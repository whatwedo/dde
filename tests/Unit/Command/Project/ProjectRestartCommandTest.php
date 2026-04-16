<?php

declare(strict_types=1);

namespace Tests\Unit\Command\Project;

use App\Command\Project\ProjectRestartCommand;
use App\Config\GlobalConfig;
use App\Config\ProjectConfig;
use App\Config\ResolvedConfig;
use App\Manager\ProjectConfigManager;
use App\Manager\ProjectLifecycleManager;
use App\Model\ServiceDefinition;
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
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[AllowMockObjectsWithoutExpectations]
final class ProjectRestartCommandTest extends TestCase
{
    private string $tempDir;

    private ProjectConfigManager&Stub $configManager;

    private ProjectLifecycleManager&MockObject $lifecycleManager;

    private CommandTester $commandTester;

    private ProjectRestartCommand $command;

    public function testCommandIsRegistered(): void
    {
        $this->assertSame('project:restart', $this->command->getName());
        $this->assertSame('Restart the project containers', $this->command->getDescription());
    }

    public function testSuccessfulRestartWithTextOutput(): void
    {
        $this->setupProjectFixture();

        $this->lifecycleManager
            ->expects($this->once())
            ->method('down')
            ->with($this->anything(), $this->tempDir);

        $this->lifecycleManager
            ->expects($this->once())
            ->method('up')
            ->willReturn([
                'serviceResults' => [
                    [
                        'name' => 'mariadb',
                        'version' => '11.8',
                        'status' => 'running',
                    ],
                ],
                'devLayerResult' => null,
            ]);

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $display = $this->commandTester->getDisplay();
        $this->assertStringContainsString('test-project', $display);
        $this->assertStringContainsString('restarted', $display);
    }

    public function testSuccessfulRestartWithJsonOutput(): void
    {
        $this->setupProjectFixture();

        $this->lifecycleManager
            ->method('up')
            ->willReturn([
                'serviceResults' => [
                    [
                        'name' => 'mariadb',
                        'version' => '11.8',
                        'status' => 'running',
                    ],
                ],
                'devLayerResult' => null,
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
        $this->assertSame('restarted', $decoded['data']['status']);
        $this->assertNotEmpty($decoded['data']['services']);
    }

    public function testRestartWithBuildFlag(): void
    {
        $this->setupProjectFixture(services: []);

        $this->lifecycleManager
            ->expects($this->once())
            ->method('up')
            ->with(
                $this->anything(),
                $this->tempDir,
                true,
            )
            ->willReturn([
                'serviceResults' => [],
                'devLayerResult' => null,
            ]);

        $this->commandTester->execute([
            '--build' => true,
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

    /**
     * @param array<ServiceDefinition>|null $services
     */
    private function setupProjectFixture(?array $services = null): void
    {
        file_put_contents($this->tempDir.'/docker-compose.yml', "services:\n  web:\n    image: nginx\n");

        $services ??= [new ServiceDefinition(name: 'mariadb', version: 'latest')];

        $projectConfig = new ProjectConfig(
            name: 'test-project',
            services: $services,
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
        $this->tempDir = sys_get_temp_dir().'/dde_test_restart_'.bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o755, true);

        $this->configManager = $this->createStub(ProjectConfigManager::class);
        $this->lifecycleManager = $this->createMock(ProjectLifecycleManager::class);

        $formatterResolver = new FormatterResolver(new TextFormatter(), new JsonFormatter());

        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);

        $this->command = new ProjectRestartCommand(
            $this->configManager,
            $this->lifecycleManager,
            $formatterResolver,
            $eventDispatcher,
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
