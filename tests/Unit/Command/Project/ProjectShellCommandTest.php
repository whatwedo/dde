<?php

declare(strict_types=1);

namespace Tests\Unit\Command\Project;

use App\Command\Project\ProjectShellCommand;
use App\Config\GlobalConfig;
use App\Config\ProjectConfig;
use App\Config\ResolvedConfig;
use App\Manager\ConfigManager;
use App\Manager\DockerComposeManager;
use App\Output\FormatterResolver;
use App\Output\JsonFormatter;
use App\Output\TextFormatter;
use App\Util\ShellDetectorUtil;
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
final class ProjectShellCommandTest extends TestCase
{
    private string $tempDir;

    private ConfigManager&Stub $configManager;

    private DockerComposeManager&MockObject $dockerComposeManager;

    private ShellDetectorUtil&Stub $shellDetector;

    private CommandTester $commandTester;

    private ProjectShellCommand $command;

    public function testCommandIsRegistered(): void
    {
        $this->assertSame('project:shell', $this->command->getName());
        $this->assertSame('Open an interactive shell in a project container', $this->command->getDescription());
    }

    public function testShellOpensDetectedShell(): void
    {
        $this->setupProjectFixture();
        $this->shellDetector->method('detect')->willReturn('zsh');

        $process = $this->createStub(Process::class);
        $process->method('getExitCode')->willReturn(0);

        $this->dockerComposeManager
            ->expects($this->once())
            ->method('exec')
            ->with(
                $this->tempDir,
                'web',
                ['zsh'],
                $this->callback(static function (array $opts): bool {
                    return $opts['user'] === 'dde' && ($opts['interactive'] ?? false) === true;
                }),
            )
            ->willReturn($process);

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
    }

    public function testShellWithRootFlag(): void
    {
        $this->setupProjectFixture();
        $this->shellDetector->method('detect')->willReturn('bash');

        $process = $this->createStub(Process::class);
        $process->method('getExitCode')->willReturn(0);

        $this->dockerComposeManager
            ->expects($this->once())
            ->method('exec')
            ->with(
                $this->tempDir,
                'web',
                ['bash'],
                $this->callback(static function (array $opts): bool {
                    return $opts['user'] === 'root';
                }),
            )
            ->willReturn($process);

        $this->commandTester->execute([
            '--root' => true,
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
    }

    public function testShellWithServiceOption(): void
    {
        $this->setupProjectFixture();
        $this->shellDetector->method('detect')->willReturn('sh');

        $process = $this->createStub(Process::class);
        $process->method('getExitCode')->willReturn(0);

        $this->dockerComposeManager
            ->expects($this->once())
            ->method('exec')
            ->with(
                $this->tempDir,
                'worker',
                ['sh'],
                $this->anything(),
            )
            ->willReturn($process);

        $this->commandTester->execute([
            '--service' => 'worker',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
    }

    public function testShellWithJsonOutputReturnsError(): void
    {
        $this->setupProjectFixture();

        $this->commandTester->execute([
            '--output' => 'json',
        ], [
            'interactive' => false,
        ]);

        $this->assertNotSame(0, $this->commandTester->getStatusCode());
        $decoded = json_decode($this->commandTester->getDisplay(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('error', $decoded['status']);
    }

    public function testShellWithCustomShellFromConfig(): void
    {
        $this->setupProjectFixture(containers: [
            'web' => [
                'shell' => 'zsh',
            ],
        ]);
        $this->shellDetector->method('detect')->willReturn('zsh');

        $process = $this->createStub(Process::class);
        $process->method('getExitCode')->willReturn(0);

        $this->dockerComposeManager
            ->expects($this->once())
            ->method('exec')
            ->with(
                $this->tempDir,
                'web',
                ['zsh'],
                $this->anything(),
            )
            ->willReturn($process);

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
    }

    public function testShellDefaultServiceFromContainerConfig(): void
    {
        $this->setupProjectFixture(containers: [
            'app' => [
                'shell' => 'bash',
            ],
            'worker' => [],
        ]);
        $this->shellDetector->method('detect')->willReturn('bash');

        $process = $this->createStub(Process::class);
        $process->method('getExitCode')->willReturn(0);

        $this->dockerComposeManager
            ->expects($this->once())
            ->method('exec')
            ->with(
                $this->tempDir,
                'app',
                ['bash'],
                $this->anything(),
            )
            ->willReturn($process);

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
    }

    public function testShellFallsBackToComposeServiceWhenNoContainersConfigured(): void
    {
        $this->setupProjectFixture();
        $this->shellDetector->method('detect')->willReturn('bash');

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
                ['bash'],
                $this->anything(),
            )
            ->willReturn($process);

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
    }

    public function testShellPropagatesNonZeroExitCode(): void
    {
        $this->setupProjectFixture();
        $this->shellDetector->method('detect')->willReturn('bash');

        $process = $this->createStub(Process::class);
        $process->method('getExitCode')->willReturn(130);

        $this->dockerComposeManager
            ->method('exec')
            ->willReturn($process);

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertSame(130, $this->commandTester->getStatusCode());
    }

    public function testErrorWhenNoProjectDirectoryFound(): void
    {
        $this->configManager
            ->method('findProjectDirectory')
            ->willReturn(null);

        $this->commandTester->execute([], [
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
        $this->tempDir = sys_get_temp_dir().'/dde_test_shell_'.bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o755, true);

        $this->configManager = $this->createStub(ConfigManager::class);
        $this->dockerComposeManager = $this->createMock(DockerComposeManager::class);
        $this->shellDetector = $this->createStub(ShellDetectorUtil::class);

        $formatterResolver = new FormatterResolver(new TextFormatter(), new JsonFormatter());

        $this->command = new ProjectShellCommand(
            $this->configManager,
            $this->dockerComposeManager,
            $formatterResolver,
            $this->shellDetector,
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
