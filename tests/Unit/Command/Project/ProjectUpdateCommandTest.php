<?php

declare(strict_types=1);

namespace Tests\Unit\Command\Project;

use App\Command\Project\ProjectUpdateCommand;
use App\Config\GlobalConfig;
use App\Config\ProjectConfig;
use App\Config\ResolvedConfig;
use App\Manager\ConfigManager;
use App\Manager\DockerComposeManager;
use App\Manager\ProjectLifecycleManager;
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
final class ProjectUpdateCommandTest extends TestCase
{
    private string $tempDir;

    private ConfigManager&MockObject $configManager;

    private DockerComposeManager&MockObject $dockerComposeManager;

    private ProjectLifecycleManager&MockObject $lifecycleManager;

    private EventDispatcherInterface&Stub $eventDispatcher;

    private CommandTester $commandTester;

    private ProjectUpdateCommand $command;

    public function testCommandIsRegistered(): void
    {
        $this->assertSame('project:update', $this->command->getName());
    }

    public function testSuccessfulUpdateWithTextOutput(): void
    {
        $this->setupProjectFixture();

        $this->lifecycleManager
            ->expects($this->once())
            ->method('down')
            ->with(
                $this->isInstanceOf(ResolvedConfig::class),
                $this->tempDir,
                true,
            );

        $this->dockerComposeManager
            ->expects($this->once())
            ->method('pull')
            ->with($this->tempDir);

        $this->lifecycleManager
            ->expects($this->once())
            ->method('up')
            ->with(
                $this->isInstanceOf(ResolvedConfig::class),
                $this->tempDir,
                true,
            )
            ->willReturn([
                'serviceResults' => [],
                'devLayerResult' => null,
            ]);

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $display = $this->commandTester->getDisplay();
        $this->assertStringContainsString('test-project', $display);
        $this->assertStringContainsString('updated', $display);
    }

    public function testSuccessfulUpdateWithJsonOutput(): void
    {
        $this->setupProjectFixture();

        $this->lifecycleManager
            ->method('down');

        $this->dockerComposeManager
            ->method('pull');

        $this->lifecycleManager
            ->method('up')
            ->willReturn([
                'serviceResults' => [],
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
        $this->assertSame('updated', $decoded['data']['status']);
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

    public function testUpdateCommandBubblesUpPullFailure(): void
    {
        $this->setupProjectFixture();

        $this->lifecycleManager
            ->method('down');

        $this->eventDispatcher
            ->method('dispatch')
            ->willReturnArgument(0);

        $this->dockerComposeManager
            ->method('pull')
            ->willThrowException(new \RuntimeException('Network unreachable'));

        $this->expectException(\RuntimeException::class);

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);
    }

    public function testUpdateCommandCallsDownPullUpInOrder(): void
    {
        $this->setupProjectFixture();

        $callOrder = [];

        $this->lifecycleManager
            ->method('down')
            ->willReturnCallback(function () use (&$callOrder): void {
                $callOrder[] = 'down';
            });

        $this->dockerComposeManager
            ->method('pull')
            ->willReturnCallback(function () use (&$callOrder): void {
                $callOrder[] = 'pull';
            });

        $this->lifecycleManager
            ->method('up')
            ->willReturnCallback(function () use (&$callOrder): array {
                $callOrder[] = 'up';

                return [
                    'serviceResults' => [],
                    'devLayerResult' => null,
                ];
            });

        $this->eventDispatcher
            ->method('dispatch')
            ->willReturnArgument(0);

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertSame(['down', 'pull', 'up'], $callOrder);
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
            ->with($this->tempDir)
            ->willReturn($resolvedConfig);
    }

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/dde_test_update_'.bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o755, true);

        $this->configManager = $this->createMock(ConfigManager::class);
        $this->dockerComposeManager = $this->createMock(DockerComposeManager::class);
        $this->lifecycleManager = $this->createMock(ProjectLifecycleManager::class);
        $this->eventDispatcher = $this->createStub(EventDispatcherInterface::class);

        $formatterResolver = new FormatterResolver(new TextFormatter(), new JsonFormatter());

        $this->command = new ProjectUpdateCommand(
            $this->configManager,
            $this->dockerComposeManager,
            $this->lifecycleManager,
            $this->eventDispatcher,
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
