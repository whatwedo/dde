<?php

declare(strict_types=1);

namespace Tests\Unit\Command\Project;

use App\Command\Project\ProjectUpCommand;
use App\Config\GlobalConfig;
use App\Config\ProjectConfig;
use App\Config\ResolvedConfig;
use App\Event\ProjectUpPreEvent;
use App\Exception\HookFailedException;
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
final class ProjectUpCommandTest extends TestCase
{
    private string $tempDir;

    private ProjectConfigManager&Stub $configManager;

    private ProjectLifecycleManager&MockObject $lifecycleManager;

    private CommandTester $commandTester;

    private ProjectUpCommand $command;

    public function testCommandIsRegistered(): void
    {
        $this->assertSame('project:up', $this->command->getName());
        $this->assertSame('Start the project containers', $this->command->getDescription());
    }

    public function testSuccessfulUpWithTextOutput(): void
    {
        $this->setupProjectFixture();

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
                'domains' => [],
            ]);

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $display = $this->commandTester->getDisplay();
        $this->assertStringContainsString('test-project', $display);
        $this->assertStringContainsString('started', $display);
    }

    public function testSuccessfulUpWithJsonOutput(): void
    {
        $this->setupProjectFixture();

        $this->lifecycleManager
            ->method('up')
            ->willReturn([
                'serviceResults' => [
                    [
                        'name' => 'mariadb',
                        'version' => '11.8',
                        'status' => 'started',
                    ],
                ],
                'devLayerResult' => null,
                'domains' => [],
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
        $this->assertSame('started', $decoded['data']['status']);
        $this->assertNotEmpty($decoded['data']['services']);
        $this->assertCount(1, $decoded['data']['services']);
        $this->assertSame('mariadb', $decoded['data']['services'][0]['name']);
        $this->assertSame('started', $decoded['data']['services'][0]['status']);
    }

    public function testSuccessfulUpDisplaysDomains(): void
    {
        $this->setupProjectFixture();

        $this->lifecycleManager
            ->method('up')
            ->willReturn([
                'serviceResults' => [],
                'devLayerResult' => null,
                'domains' => ['app.test', 'api.app.test'],
            ]);

        $this->commandTester->execute([]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $display = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Available at:', $display);
        $this->assertStringContainsString('https://app.test', $display);
        $this->assertStringContainsString('https://api.app.test', $display);
    }

    public function testSuccessfulUpOmitsDomainSectionWhenNoDomains(): void
    {
        $this->setupProjectFixture();

        $this->lifecycleManager
            ->method('up')
            ->willReturn([
                'serviceResults' => [],
                'devLayerResult' => null,
                'domains' => [],
            ]);

        $this->commandTester->execute([]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $this->assertStringNotContainsString('Available at:', $this->commandTester->getDisplay());
    }

    public function testSuccessfulUpWithJsonOutputIncludesDomains(): void
    {
        $this->setupProjectFixture();

        $this->lifecycleManager
            ->method('up')
            ->willReturn([
                'serviceResults' => [],
                'devLayerResult' => null,
                'domains' => ['app.test'],
            ]);

        $this->commandTester->execute([
            '--output' => 'json',
        ], [
            'interactive' => false,
        ]);

        $decoded = json_decode($this->commandTester->getDisplay(), true);
        $this->assertIsArray($decoded);
        $this->assertSame(['app.test'], $decoded['data']['domains']);
    }

    public function testUpWithBuildFlag(): void
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
                'domains' => [],
            ]);

        $this->commandTester->execute([
            '--build' => true,
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
    }

    public function testUpCommandBubblesUpLifecycleException(): void
    {
        $this->setupProjectFixture();

        $this->lifecycleManager
            ->expects($this->once())
            ->method('up')
            ->willThrowException(new \RuntimeException('Docker daemon not running'));

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertSame(1, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Docker daemon not running', $this->commandTester->getDisplay());
    }

    public function testUpCommandReturnsErrorOnPreHookFailure(): void
    {
        $this->setupProjectFixture();

        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $eventDispatcher
            ->method('dispatch')
            ->willReturnCallback(function (object $event): object {
                if ($event instanceof ProjectUpPreEvent) {
                    throw new HookFailedException('/hooks/pre-up.sh', 1, 'pre-up hook failed');
                }

                return $event;
            });

        $configManager = $this->configManager;
        $lifecycleManager = $this->lifecycleManager;
        $formatterResolver = new FormatterResolver(new TextFormatter(), new JsonFormatter());

        $command = new ProjectUpCommand(
            $configManager,
            $lifecycleManager,
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
        $application->addCommand($command);

        $commandTester = new CommandTester($command);
        $commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertNotSame(0, $commandTester->getStatusCode());
        $this->assertStringContainsString('pre-up.sh', $commandTester->getDisplay());
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
        $this->tempDir = sys_get_temp_dir().'/dde_test_up_'.bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o755, true);

        $this->configManager = $this->createStub(ProjectConfigManager::class);
        $this->lifecycleManager = $this->createMock(ProjectLifecycleManager::class);

        $formatterResolver = new FormatterResolver(new TextFormatter(), new JsonFormatter());

        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);

        $this->command = new ProjectUpCommand(
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
