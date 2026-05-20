<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use Symfony\Component\HttpKernel\KernelInterface;

final class ApplicationTest extends TestCase
{
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

    private function createKernelMock(): KernelInterface
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willThrowException(new ServiceNotFoundException('not available'));

        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('getBundles')->willReturn([]);
        $kernel->method('getEnvironment')->willReturn('test');
        $kernel->method('getContainer')->willReturn($container);

        return $kernel;
    }
}
