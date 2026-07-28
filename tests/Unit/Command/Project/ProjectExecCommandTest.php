<?php

declare(strict_types=1);

namespace Tests\Unit\Command\Project;

use App\Command\Project\ProjectExecCommand;
use App\Config\GlobalConfig;
use App\Config\ProjectConfig;
use App\Config\ResolvedConfig;
use App\Manager\DockerComposeManager;
use App\Manager\ProjectConfigManager;
use App\Output\FormatterResolver;
use App\Output\JsonFormatter;
use App\Output\TextFormatter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[AllowMockObjectsWithoutExpectations]
final class ProjectExecCommandTest extends TestCase
{
    private string $tempDir;

    private ProjectConfigManager&Stub $configManager;

    private DockerComposeManager&MockObject $dockerComposeManager;

    private CommandTester $commandTester;

    private ProjectExecCommand $command;

    public function testCommandIsRegistered(): void
    {
        $this->assertSame('project:exec', $this->command->getName());
        $this->assertSame('Execute a command in a project container', $this->command->getDescription());
    }

    /**
     * Regression guard for #271: without a pty in the container, Ctrl-C only
     * kills the local client and leaves long-running processes (dev servers,
     * watchers, workers) alive.
     */
    public function testExecRequestsAnInteractiveProcess(): void
    {
        $this->setupProjectFixture();

        $process = $this->createMock(Process::class);
        $process->method('getExitCode')->willReturn(0);
        $process->expects($this->once())->method('run');

        $this->dockerComposeManager
            ->expects($this->once())
            ->method('exec')
            ->with(
                $this->tempDir,
                'web',
                ['ls', '-la'],
                $this->callback(static function (array $options): bool {
                    return $options['user'] === 'dde'
                        && ($options['interactive'] ?? false) === true;
                }),
            )
            ->willReturn($process);

        $this->commandTester->execute([
            'cmd' => ['ls', '-la'],
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
    }

    public function testExecWithRootFlag(): void
    {
        $this->setupProjectFixture();

        $process = $this->createStub(Process::class);
        $process->method('getExitCode')->willReturn(0);

        $this->dockerComposeManager
            ->expects($this->once())
            ->method('exec')
            ->with(
                $this->tempDir,
                'web',
                ['whoami'],
                $this->callback(static function (array $options): bool {
                    return $options['user'] === 'root';
                }),
            )
            ->willReturn($process);

        $this->commandTester->execute([
            'cmd' => ['whoami'],
            '--root' => true,
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
    }

    public function testExecWithServiceOption(): void
    {
        $this->setupProjectFixture();

        $process = $this->createStub(Process::class);
        $process->method('getExitCode')->willReturn(0);

        $this->dockerComposeManager
            ->expects($this->once())
            ->method('exec')
            ->with(
                $this->tempDir,
                'worker',
                ['php', '-v'],
                $this->anything(),
            )
            ->willReturn($process);

        $this->commandTester->execute([
            'cmd' => ['php', '-v'],
            '--service' => 'worker',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
    }

    public function testExecDefaultServiceFromContainerConfig(): void
    {
        $this->setupProjectFixture(containers: [
            'app' => [
                'shell' => 'bash',
            ],
            'worker' => [],
        ]);

        $process = $this->createStub(Process::class);
        $process->method('getExitCode')->willReturn(0);

        $this->dockerComposeManager
            ->expects($this->once())
            ->method('exec')
            ->with(
                $this->tempDir,
                'app',
                ['ls'],
                $this->anything(),
            )
            ->willReturn($process);

        $this->commandTester->execute([
            'cmd' => ['ls'],
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
    }

    public function testExecFallsBackToComposeServiceWhenNoContainersConfigured(): void
    {
        $this->setupProjectFixture();

        $this->dockerComposeManager
            ->method('discoverServiceNames')
            ->willReturn(['my-app', 'worker']);

        $process = $this->createStub(Process::class);
        $process->method('getExitCode')->willReturn(0);

        $this->dockerComposeManager
            ->expects($this->once())
            ->method('exec')
            ->with(
                $this->tempDir,
                'my-app',
                ['ls'],
                $this->anything(),
            )
            ->willReturn($process);

        $this->commandTester->execute([
            'cmd' => ['ls'],
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
    }

    public function testErrorWhenNoProjectDirectoryFound(): void
    {
        $this->configManager
            ->method('findProjectDirectory')
            ->willReturn(null);

        $this->commandTester->execute([
            'cmd' => ['ls'],
        ], [
            'interactive' => false,
        ]);

        $this->assertNotSame(0, $this->commandTester->getStatusCode());
    }

    /**
     * @param array<string, mixed> $containers
     */
    private function setupProjectFixture(array $containers = []): void
    {
        $projectConfig = new ProjectConfig(
            name: 'test-project',
            containers: $containers,
        );

        $resolvedConfig = ResolvedConfig::merge(new GlobalConfig(), $projectConfig);

        $this->configManager
            ->method('findProjectDirectory')
            ->willReturn($this->tempDir);

        $this->configManager
            ->method('resolveConfig')
            ->willReturn($resolvedConfig);
    }

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/dde_test_exec_'.bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o755, true);

        $this->configManager = $this->createStub(ProjectConfigManager::class);
        $this->dockerComposeManager = $this->createMock(DockerComposeManager::class);

        $formatterResolver = new FormatterResolver(new TextFormatter(), new JsonFormatter());

        $this->command = new ProjectExecCommand(
            $this->configManager,
            $this->dockerComposeManager,
            $formatterResolver,
        );

        $application = new Application();
        $application->getDefinition()->addOption(new InputOption(
            'output',
            'o',
            InputOption::VALUE_REQUIRED,
            'Output format',
            'text',
        ));
        $application->addCommand($this->command);

        $this->commandTester = new CommandTester($this->command);
    }

    protected function tearDown(): void
    {
        $filesystem = new Filesystem();

        if (is_dir($this->tempDir)) {
            $filesystem->remove($this->tempDir);
        }
    }
}
