<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

final class ApplicationTest extends TestCase
{
    private string $originalCwd;

    private string $tempDir;

    public function testNameAndVersion(): void
    {
        $application = new Application($this->createKernelMock());

        $this->assertSame('dde', $application->getName());
        // Tests always run against an unbuilt checkout — the build pipeline only
        // substitutes APP_VERSION before `box compile`, after the test suite.
        // The constructor must therefore report the `dev` fallback.
        $this->assertSame('dev', $application->getVersion());
    }

    public function testAllFiltersUnwantedCommands(): void
    {
        $application = new Application($this->createKernelMock());
        $application->setAutoExit(false);

        $commands = $application->all();

        foreach (array_keys($commands) as $name) {
            $allowed = in_array($name, ['about', 'completion', 'help', 'list'], true)
                || str_starts_with($name, 'project:')
                || str_starts_with($name, 'system:');

            $this->assertTrue($allowed, sprintf('Command "%s" should be filtered out', $name));
        }
    }

    public function testAllowedPrefixesAreKept(): void
    {
        $application = new Application($this->createKernelMock());
        $application->setAutoExit(false);

        $commands = $application->all();

        $this->assertArrayHasKey('help', $commands);
        $this->assertArrayHasKey('list', $commands);
    }

    public function testDefinitionContainsProjectDirOption(): void
    {
        $application = new Application($this->createKernelMock());

        $option = $application->getDefinition()->getOption('project-dir');

        $this->assertSame('C', $option->getShortcut());
        $this->assertTrue($option->isValueRequired());
    }

    public function testResolveProjectDirectoryReturnsNullWhenOptionAbsent(): void
    {
        $this->assertNull(Application::resolveProjectDirectory(new ArgvInput(['bin/console', 'list'])));
    }

    public function testResolveProjectDirectoryResolvesLongOption(): void
    {
        $input = new ArgvInput(['bin/console', '--project-dir='.$this->tempDir, 'list']);

        $this->assertSame(realpath($this->tempDir), Application::resolveProjectDirectory($input));
    }

    public function testResolveProjectDirectoryResolvesShortOption(): void
    {
        $input = new ArgvInput(['bin/console', '-C', $this->tempDir, 'list']);

        $this->assertSame(realpath($this->tempDir), Application::resolveProjectDirectory($input));
    }

    public function testResolveProjectDirectoryResolvesRelativePath(): void
    {
        chdir(dirname($this->tempDir));
        $input = new ArgvInput(['bin/console', '-C', basename($this->tempDir), 'list']);

        $this->assertSame(realpath($this->tempDir), Application::resolveProjectDirectory($input));
    }

    public function testResolveProjectDirectoryIgnoresTokensAfterDoubleDash(): void
    {
        $input = new ArgvInput(['bin/console', 'project:exec', '--', '-C', '/nonexistent']);

        $this->assertNull(Application::resolveProjectDirectory($input));
    }

    public function testResolveProjectDirectoryThrowsForMissingDirectory(): void
    {
        $input = new ArgvInput(['bin/console', '-C', $this->tempDir.'/missing', 'list']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('is not a directory');

        Application::resolveProjectDirectory($input);
    }

    public function testResolveProjectDirectoryThrowsForFile(): void
    {
        $file = $this->tempDir.'/file.txt';
        file_put_contents($file, 'content');
        $input = new ArgvInput(['bin/console', '-C', $file, 'list']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('is not a directory');

        Application::resolveProjectDirectory($input);
    }

    public function testDoRunChangesWorkingDirectory(): void
    {
        $application = new Application($this->createKernelMock());
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);

        $exitCode = $application->run(
            new ArgvInput(['bin/console', '-C', $this->tempDir, 'list']),
            new NullOutput(),
        );

        $this->assertSame(0, $exitCode);
        $this->assertSame(realpath($this->tempDir), getcwd());
    }

    public function testDoRunKeepsWorkingDirectoryWithoutOption(): void
    {
        $application = new Application($this->createKernelMock());
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);

        $application->run(new ArgvInput(['bin/console', 'list']), new NullOutput());

        $this->assertSame($this->originalCwd, getcwd());
    }

    private function createKernelMock(): KernelInterface
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturnCallback(static function (string $id): EventDispatcher {
            if ($id === 'event_dispatcher') {
                return new EventDispatcher();
            }

            throw new ServiceNotFoundException($id);
        });

        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('getBundles')->willReturn([]);
        $kernel->method('getEnvironment')->willReturn('test');
        $kernel->method('getContainer')->willReturn($container);

        return $kernel;
    }

    protected function setUp(): void
    {
        $cwd = getcwd();
        $this->assertNotFalse($cwd);
        $this->originalCwd = $cwd;

        $this->tempDir = sys_get_temp_dir().'/dde-application-test-'.bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);
        (new Filesystem())->remove($this->tempDir);
    }
}
