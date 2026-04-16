<?php

declare(strict_types=1);

namespace Tests\Unit\Command\Project;

use App\Command\Project\ProjectStatusCommand;
use App\Config\GlobalConfig;
use App\Config\ProjectConfig;
use App\Config\ResolvedConfig;
use App\Manager\DockerComposeManager;
use App\Manager\DockerManager;
use App\Manager\ProjectConfigManager;
use App\Output\FormatterResolver;
use App\Output\JsonFormatter;
use App\Output\TextFormatter;
use App\Util\ProcessFactory;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class ProjectStatusCommandTest extends TestCase
{
    private string $tempDir;

    private ProjectConfigManager&Stub $configManager;

    private DockerComposeManager&Stub $dockerComposeManager;

    private CommandTester $commandTester;

    private ProjectStatusCommand $command;

    public function testCommandIsRegistered(): void
    {
        $this->assertSame('project:status', $this->command->getName());
        $this->assertSame('Show project status', $this->command->getDescription());
    }

    public function testStatusWithAllRunningContainers(): void
    {
        $this->setupProjectFixture();

        $this->dockerComposeManager
            ->method('ps')
            ->willReturn([
                [
                    'Service' => 'web',
                    'State' => 'running',
                    'Health' => 'healthy',
                    'Publishers' => [
                        [
                            'TargetPort' => 443,
                            'Protocol' => 'tcp',
                        ],
                    ],
                ],
                [
                    'Service' => 'worker',
                    'State' => 'running',
                    'Health' => '',
                    'Ports' => '',
                ],
            ]);

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $display = $this->commandTester->getDisplay();
        $this->assertStringContainsString('test-project', $display);
        $this->assertStringContainsString('running', $display);
    }

    public function testStatusWithJsonOutputAllRunning(): void
    {
        $this->setupProjectFixture();

        $this->dockerComposeManager
            ->method('ps')
            ->willReturn([
                [
                    'Service' => 'web',
                    'State' => 'running',
                    'Health' => 'healthy',
                    'Publishers' => [
                        [
                            'TargetPort' => 443,
                            'Protocol' => 'tcp',
                        ],
                    ],
                ],
            ]);

        $this->commandTester->execute([
            '--output' => 'json',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $decoded = json_decode($this->commandTester->getDisplay(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('ok', $decoded['status']);
        $this->assertSame('test-project', $decoded['data']['project']);
        $this->assertSame('running', $decoded['data']['status']);
        $this->assertCount(1, $decoded['data']['containers']);
        $this->assertSame('web', $decoded['data']['containers'][0]['name']);
        $this->assertSame('running', $decoded['data']['containers'][0]['status']);
        $this->assertSame('healthy', $decoded['data']['containers'][0]['health']);
        $this->assertContains('443/tcp', $decoded['data']['containers'][0]['ports']);
    }

    public function testStatusWithPartialContainers(): void
    {
        $this->setupProjectFixture();

        $this->dockerComposeManager
            ->method('ps')
            ->willReturn([
                [
                    'Service' => 'web',
                    'State' => 'running',
                    'Health' => '',
                    'Ports' => '',
                ],
                [
                    'Service' => 'worker',
                    'State' => 'exited',
                    'Health' => '',
                    'Ports' => '',
                ],
            ]);

        $this->commandTester->execute([
            '--output' => 'json',
        ], [
            'interactive' => false,
        ]);

        $decoded = json_decode($this->commandTester->getDisplay(), true);
        $this->assertSame('partial', $decoded['data']['status']);
    }

    public function testStatusWithNoContainers(): void
    {
        $this->setupProjectFixture();

        $this->dockerComposeManager
            ->method('ps')
            ->willReturn([]);

        $this->commandTester->execute([
            '--output' => 'json',
        ], [
            'interactive' => false,
        ]);

        $decoded = json_decode($this->commandTester->getDisplay(), true);
        $this->assertSame('stopped', $decoded['data']['status']);
        $this->assertSame([], $decoded['data']['containers']);
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
        $this->tempDir = sys_get_temp_dir().'/dde_test_status_'.bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o755, true);

        $this->configManager = $this->createStub(ProjectConfigManager::class);
        $this->dockerComposeManager = $this->createStub(DockerComposeManager::class);

        $formatterResolver = new FormatterResolver(new TextFormatter(), new JsonFormatter());

        $this->command = new ProjectStatusCommand(
            $this->configManager,
            $this->dockerComposeManager,
            $formatterResolver,
            new DockerManager(new ProcessFactory()),
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
