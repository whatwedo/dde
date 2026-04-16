<?php

declare(strict_types=1);

namespace Tests\Unit\Command\Project;

use App\Command\Project\ProjectLogsCommand;
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
final class ProjectLogsCommandTest extends TestCase
{
    private string $tempDir;

    private ProjectConfigManager&Stub $configManager;

    private DockerComposeManager&MockObject $dockerComposeManager;

    private CommandTester $commandTester;

    private ProjectLogsCommand $command;

    public function testCommandIsRegistered(): void
    {
        $this->assertSame('project:logs', $this->command->getName());
        $this->assertSame('Show project container logs', $this->command->getDescription());
    }

    public function testBasicLogsCall(): void
    {
        $this->setupProjectFixture();

        $process = $this->createMock(Process::class);
        $process->method('getExitCode')->willReturn(0);
        $process->expects($this->once())->method('run');

        $this->dockerComposeManager
            ->expects($this->once())
            ->method('logs')
            ->with(
                $this->tempDir,
                '',
                $this->callback(static function (array $options): bool {
                    return $options['follow'] === false
                        && ! array_key_exists('tail', $options);
                }),
            )
            ->willReturn($process);

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
    }

    public function testLogsWithServiceOption(): void
    {
        $this->setupProjectFixture();

        $process = $this->createStub(Process::class);
        $process->method('getExitCode')->willReturn(0);

        $this->dockerComposeManager
            ->expects($this->once())
            ->method('logs')
            ->with(
                $this->tempDir,
                'web',
                $this->anything(),
            )
            ->willReturn($process);

        $this->commandTester->execute([
            '--service' => 'web',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
    }

    public function testLogsWithTailOption(): void
    {
        $this->setupProjectFixture();

        $process = $this->createStub(Process::class);
        $process->method('getExitCode')->willReturn(0);

        $this->dockerComposeManager
            ->expects($this->once())
            ->method('logs')
            ->with(
                $this->tempDir,
                '',
                $this->callback(static function (array $options): bool {
                    return $options['tail'] === '100';
                }),
            )
            ->willReturn($process);

        $this->commandTester->execute([
            '--tail' => '100',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
    }

    public function testLogsFollowWithJsonOutputReturnsError(): void
    {
        $this->setupProjectFixture();

        $this->commandTester->execute([
            '--follow' => true,
            '--output' => 'json',
        ], [
            'interactive' => false,
        ]);

        $this->assertNotSame(0, $this->commandTester->getStatusCode());
        $decoded = json_decode($this->commandTester->getDisplay(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('error', $decoded['status']);
    }

    public function testLogsNoFollowOverridesFollow(): void
    {
        $this->setupProjectFixture();

        $process = $this->createStub(Process::class);
        $process->method('getExitCode')->willReturn(0);

        $this->dockerComposeManager
            ->expects($this->once())
            ->method('logs')
            ->with(
                $this->tempDir,
                '',
                $this->callback(static function (array $options): bool {
                    return $options['follow'] === false;
                }),
            )
            ->willReturn($process);

        $this->commandTester->execute([
            '--follow' => true,
            '--no-follow' => true,
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

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertNotSame(0, $this->commandTester->getStatusCode());
    }

    private function setupProjectFixture(): void
    {
        $projectConfig = new ProjectConfig(
            name: 'test-project',
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
        $this->tempDir = sys_get_temp_dir().'/dde_test_logs_'.bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o755, true);

        $this->configManager = $this->createStub(ProjectConfigManager::class);
        $this->dockerComposeManager = $this->createMock(DockerComposeManager::class);

        $formatterResolver = new FormatterResolver(new TextFormatter(), new JsonFormatter());

        $this->command = new ProjectLogsCommand(
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
