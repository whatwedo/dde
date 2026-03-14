<?php

declare(strict_types=1);

namespace Tests\Unit\Command\Project;

use App\Command\Project\ProjectDownCommand;
use App\Config\GlobalConfig;
use App\Config\ProjectConfig;
use App\Config\ResolvedConfig;
use App\Event\ProjectDownPreEvent;
use App\Exception\HookFailedException;
use App\Manager\ConfigManager;
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
final class ProjectDownCommandTest extends TestCase
{
    private string $tempDir;

    private ConfigManager&Stub $configManager;

    private ProjectLifecycleManager&MockObject $lifecycleManager;

    private CommandTester $commandTester;

    private ProjectDownCommand $command;

    public function testCommandIsRegistered(): void
    {
        $this->assertSame('project:down', $this->command->getName());
        $this->assertSame('Stop and remove the project containers', $this->command->getDescription());
    }

    public function testSuccessfulDownWithTextOutput(): void
    {
        $this->setupProjectFixture();

        $this->lifecycleManager
            ->expects($this->once())
            ->method('down')
            ->with(
                $this->anything(),
                $this->tempDir,
            );

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $display = $this->commandTester->getDisplay();
        $this->assertStringContainsString('test-project', $display);
        $this->assertStringContainsString('stopped', $display);
    }

    public function testSuccessfulDownWithJsonOutput(): void
    {
        $this->setupProjectFixture();

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
        $this->assertSame('stopped', $decoded['data']['status']);
    }

    public function testDownWithRemoveOrphansFlag(): void
    {
        $this->setupProjectFixture();

        $this->lifecycleManager
            ->expects($this->once())
            ->method('down')
            ->with(
                $this->anything(),
                $this->tempDir,
                true,
            );

        $this->commandTester->execute([
            '--remove-orphans' => true,
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
    }

    public function testDownCommandBubblesUpLifecycleException(): void
    {
        $this->setupProjectFixture();

        $this->lifecycleManager
            ->expects($this->once())
            ->method('down')
            ->willThrowException(new \RuntimeException('Container removal failed'));

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertSame(1, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Container removal failed', $this->commandTester->getDisplay());
    }

    public function testDownCommandReturnsErrorOnPreHookFailure(): void
    {
        $this->setupProjectFixture();

        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $eventDispatcher
            ->method('dispatch')
            ->willReturnCallback(function (object $event): object {
                if ($event instanceof ProjectDownPreEvent) {
                    throw new HookFailedException('/hooks/pre-down.sh', 1, 'pre-down hook failed');
                }

                return $event;
            });

        $formatterResolver = new FormatterResolver(new TextFormatter(), new JsonFormatter());

        $command = new ProjectDownCommand(
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
        $application->addCommand($command);

        $commandTester = new CommandTester($command);
        $commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertNotSame(0, $commandTester->getStatusCode());
        $this->assertStringContainsString('pre-down.sh', $commandTester->getDisplay());
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
        $this->tempDir = sys_get_temp_dir().'/dde_test_down_'.bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o755, true);

        $this->configManager = $this->createStub(ConfigManager::class);
        $this->lifecycleManager = $this->createMock(ProjectLifecycleManager::class);

        $formatterResolver = new FormatterResolver(new TextFormatter(), new JsonFormatter());

        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);

        $this->command = new ProjectDownCommand(
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
