<?php

declare(strict_types=1);

namespace Tests\Unit\Command\Project;

use App\Command\Project\ProjectClaudeCommand;
use App\Config\GlobalConfig;
use App\Config\ProjectConfig;
use App\Config\ResolvedConfig;
use App\Manager\DockerManager;
use App\Manager\ProjectConfigManager;
use App\Manager\WorktreeManager;
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
final class ProjectClaudeCommandTest extends TestCase
{
    private string $tempDir;

    private ProjectConfigManager&Stub $configManager;

    private DockerManager&MockObject $dockerManager;

    private WorktreeManager&Stub $worktreeManager;

    private CommandTester $commandTester;

    private ProjectClaudeCommand $command;

    public function testCommandIsRegistered(): void
    {
        $this->assertSame('project:claude', $this->command->getName());
        $this->assertStringContainsString('Claude', $this->command->getDescription());
    }

    public function testErrorWhenNoProjectDirectoryFound(): void
    {
        $this->configManager
            ->method('findProjectDirectory')
            ->willReturn(null);

        $this->commandTester->execute([], ['interactive' => false]);

        $this->assertNotSame(0, $this->commandTester->getStatusCode());
    }

    public function testErrorWhenClaudeAgentDisabled(): void
    {
        $this->setupProjectFixture(claudeAgentEnabled: false);

        $this->commandTester->execute([], ['interactive' => false]);

        $this->assertNotSame(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('disabled', $this->commandTester->getDisplay());
    }

    public function testRunsEphemeralContainerWithCorrectUser(): void
    {
        $this->setupProjectFixture();

        $this->dockerManager
            ->method('networkExists')
            ->willReturn(false);

        $process = $this->createStub(Process::class);
        $process->method('getExitCode')->willReturn(0);

        $this->dockerManager
            ->expects($this->once())
            ->method('createInteractiveRunProcess')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                null,
                'developer',
                ['claude'],
            )
            ->willReturn($process);

        $this->commandTester->execute([], ['interactive' => false]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
    }

    public function testPassesProjectNetworkWhenAvailable(): void
    {
        $this->setupProjectFixture();

        $this->dockerManager
            ->method('networkExists')
            ->willReturn(true);

        $process = $this->createStub(Process::class);
        $process->method('getExitCode')->willReturn(0);

        $this->dockerManager
            ->expects($this->once())
            ->method('createInteractiveRunProcess')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                'dde-services-myproject',
                $this->anything(),
                $this->anything(),
            )
            ->willReturn($process);

        $this->commandTester->execute([], ['interactive' => false]);
    }

    public function testPassesNullNetworkWhenProjectNotRunning(): void
    {
        $this->setupProjectFixture();

        $this->dockerManager
            ->method('networkExists')
            ->willReturn(false);

        $process = $this->createStub(Process::class);
        $process->method('getExitCode')->willReturn(0);

        $this->dockerManager
            ->expects($this->once())
            ->method('createInteractiveRunProcess')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->anything(),
                null,
                $this->anything(),
                $this->anything(),
            )
            ->willReturn($process);

        $this->commandTester->execute([], ['interactive' => false]);
    }

    public function testPropagatesNonZeroExitCode(): void
    {
        $this->setupProjectFixture();

        $this->dockerManager
            ->method('networkExists')
            ->willReturn(false);

        $process = $this->createStub(Process::class);
        $process->method('getExitCode')->willReturn(130);

        $this->dockerManager
            ->method('createInteractiveRunProcess')
            ->willReturn($process);

        $this->commandTester->execute([], ['interactive' => false]);

        $this->assertSame(130, $this->commandTester->getStatusCode());
    }

    private function setupProjectFixture(bool $claudeAgentEnabled = true): void
    {
        $projectConfig = new ProjectConfig(name: 'myproject');
        $resolvedConfig = ResolvedConfig::merge(
            new GlobalConfig(claudeAgentEnabled: $claudeAgentEnabled),
            $projectConfig,
        );

        $this->configManager
            ->method('findProjectDirectory')
            ->willReturn($this->tempDir);

        $this->configManager
            ->method('resolveConfig')
            ->willReturn($resolvedConfig);
    }

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/dde_test_claude_'.bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o755, true);

        $this->configManager = $this->createStub(ProjectConfigManager::class);
        $this->dockerManager = $this->createMock(DockerManager::class);
        $this->worktreeManager = $this->createStub(WorktreeManager::class);

        $formatterResolver = new FormatterResolver(new TextFormatter(), new JsonFormatter());

        $this->command = new ProjectClaudeCommand(
            $this->configManager,
            $this->dockerManager,
            $this->worktreeManager,
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
