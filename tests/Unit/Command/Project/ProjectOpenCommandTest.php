<?php

declare(strict_types=1);

namespace Tests\Unit\Command\Project;

use App\Command\Project\ProjectOpenCommand;
use App\Config\GlobalConfig;
use App\Config\ProjectConfig;
use App\Config\ResolvedConfig;
use App\Config\WorktreeInfo;
use App\Manager\ProjectConfigManager;
use App\Manager\WorktreeManager;
use App\Output\FormatterResolver;
use App\Output\JsonFormatter;
use App\Output\TextFormatter;
use App\Util\UrlOpenerUtil;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class ProjectOpenCommandTest extends TestCase
{
    private ProjectConfigManager&Stub $configManager;

    private CommandTester $commandTester;

    private ProjectOpenCommand $command;

    private WorktreeManager&Stub $worktreeManager;

    public function testCommandIsRegistered(): void
    {
        $this->assertSame('project:open', $this->command->getName());
        $this->assertSame('Open the project in the default browser', $this->command->getDescription());
        $this->assertContains('open', $this->command->getAliases());
    }

    public function testUrlForMainCheckout(): void
    {
        $this->setupProjectFixture();

        $this->worktreeManager
            ->method('detect')
            ->willReturn(null);

        $this->worktreeManager
            ->method('resolveHostname')
            ->willReturn('test-project.test');

        $this->commandTester->execute([
            '--url-only' => true,
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $this->assertSame('https://test-project.test', trim($this->commandTester->getDisplay()));
    }

    public function testUrlForWorktree(): void
    {
        $this->setupProjectFixture();

        $this->worktreeManager
            ->method('detect')
            ->willReturn(new WorktreeInfo(
                mainDirectory: '/tmp/main',
                worktreeDirectory: '/tmp/wt-feature-x',
                branch: 'feature/x',
                suffix: 'wt-feature-x',
            ));

        $this->worktreeManager
            ->method('resolveHostname')
            ->willReturn('test-project-feature-x.test');

        $this->commandTester->execute([
            '--url-only' => true,
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $this->assertSame('https://test-project-feature-x.test', trim($this->commandTester->getDisplay()));
    }

    public function testJsonOutputContainsUrl(): void
    {
        $this->setupProjectFixture();

        $this->worktreeManager
            ->method('detect')
            ->willReturn(null);

        $this->worktreeManager
            ->method('resolveHostname')
            ->willReturn('test-project.test');

        $this->commandTester->execute([
            '--output' => 'json',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $decoded = json_decode($this->commandTester->getDisplay(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('ok', $decoded['status']);
        $this->assertSame('https://test-project.test', $decoded['data']['url']);
    }

    public function testJsonOutputWithWorktree(): void
    {
        $this->setupProjectFixture();

        $this->worktreeManager
            ->method('detect')
            ->willReturn(new WorktreeInfo(
                mainDirectory: '/tmp/main',
                worktreeDirectory: '/tmp/wt-bugfix',
                branch: 'bugfix/login',
                suffix: 'wt-bugfix',
            ));

        $this->worktreeManager
            ->method('resolveHostname')
            ->willReturn('test-project-bugfix.test');

        $this->commandTester->execute([
            '--output' => 'json',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $decoded = json_decode($this->commandTester->getDisplay(), true);
        $this->assertSame('https://test-project-bugfix.test', $decoded['data']['url']);
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
            ->willReturn('/tmp/test-project');

        $this->configManager
            ->method('resolveConfig')
            ->willReturn($resolvedConfig);
    }

    protected function setUp(): void
    {
        $this->configManager = $this->createStub(ProjectConfigManager::class);
        $this->worktreeManager = $this->createStub(WorktreeManager::class);

        $processFactory = $this->createMock(\App\Util\ProcessFactory::class);
        $processFactory->method('create')
            ->willReturnCallback(static function (): Process {
                $process = new Process(['true']);
                $process->run();

                return $process;
            });

        $formatterResolver = new FormatterResolver(new TextFormatter(), new JsonFormatter());

        $dockerComposeManager = $this->createStub(\App\Manager\DockerComposeManager::class);
        $dockerComposeManager->method('findComposeFileOrNull')->willReturn(null);

        $mkcertManager = $this->createStub(\App\Manager\MkcertManager::class);
        $mkcertManager->method('extractDomainsFromComposeFile')->willReturn([]);

        $this->command = new ProjectOpenCommand(
            $this->configManager,
            $formatterResolver,
            $dockerComposeManager,
            $mkcertManager,
            $this->worktreeManager,
            new UrlOpenerUtil($processFactory),
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
}
