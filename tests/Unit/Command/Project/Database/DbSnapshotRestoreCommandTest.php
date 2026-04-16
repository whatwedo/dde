<?php

declare(strict_types=1);

namespace Tests\Unit\Command\Project\Database;

use App\Command\Project\Database\DbSnapshotRestoreCommand;
use App\Config\GlobalConfig;
use App\Config\ProjectConfig;
use App\Config\ResolvedConfig;
use App\Database\DatabaseAdapterRegistry;
use App\Database\MariaDbAdapter;
use App\Database\PostgresAdapter;
use App\Manager\DatabaseManager;
use App\Manager\ProjectConfigManager;
use App\Model\ServiceDefinition;
use App\Output\FormatterResolver;
use App\Output\JsonFormatter;
use App\Output\TextFormatter;
use App\Service\ServiceRegistry;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

#[AllowMockObjectsWithoutExpectations]
final class DbSnapshotRestoreCommandTest extends TestCase
{
    private string $tempDir;

    private ProjectConfigManager&Stub $configManager;

    private DatabaseManager&MockObject $databaseManager;

    private ServiceRegistry $serviceRegistry;

    private CommandTester $commandTester;

    private DbSnapshotRestoreCommand $command;

    public function testCommandIsRegistered(): void
    {
        $this->assertSame('project:db:snapshot:restore', $this->command->getName());
        $this->assertSame('Restore a database snapshot', $this->command->getDescription());
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

    public function testErrorWhenContainerIsNotRunning(): void
    {
        $this->setupMariadbConfig();

        $this->databaseManager
            ->method('resolveContainerName')
            ->willReturn('dde-mariadb-11.8');

        $this->databaseManager
            ->method('isContainerRunning')
            ->willReturn(false);

        $this->commandTester->execute([
            'name' => 'my-snapshot',
        ], [
            'interactive' => false,
        ]);

        $this->assertNotSame(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('not running', $this->commandTester->getDisplay());
    }

    public function testErrorWhenNamedSnapshotDoesNotExist(): void
    {
        $this->setupMariadbConfig();

        $this->databaseManager->method('resolveContainerName')->willReturn('dde-mariadb-11.8');
        $this->databaseManager->method('isContainerRunning')->willReturn(true);

        $this->commandTester->execute([
            'name' => 'nonexistent-snap',
        ], [
            'interactive' => false,
        ]);

        $this->assertNotSame(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('nonexistent-snap', $this->commandTester->getDisplay());
    }

    public function testErrorInNonInteractiveModeWithoutName(): void
    {
        $this->setupMariadbConfig();

        $this->databaseManager->method('resolveContainerName')->willReturn('dde-mariadb-11.8');
        $this->databaseManager->method('isContainerRunning')->willReturn(true);

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertNotSame(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('non-interactive', $this->commandTester->getDisplay());
    }

    public function testHappyPathRestoresSnapshotByName(): void
    {
        $this->setupMariadbConfig();

        $snapshotDir = $this->tempDir.'/.dde/snapshots/mariadb';
        mkdir($snapshotDir, 0o755, true);
        file_put_contents($snapshotDir.'/my-snap.sql', '-- sql content');

        $this->databaseManager->method('resolveContainerName')->willReturn('dde-mariadb-11.8');
        $this->databaseManager->method('isContainerRunning')->willReturn(true);
        $this->databaseManager
            ->expects($this->once())
            ->method('importDump');

        $this->commandTester->execute([
            'name' => 'my-snap',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('my-snap', $this->commandTester->getDisplay());
    }

    public function testHappyPathWithJsonOutput(): void
    {
        $this->setupMariadbConfig();

        $snapshotDir = $this->tempDir.'/.dde/snapshots/mariadb';
        mkdir($snapshotDir, 0o755, true);
        file_put_contents($snapshotDir.'/json-snap.sql', '-- sql content');

        $this->databaseManager->method('resolveContainerName')->willReturn('dde-mariadb-11.8');
        $this->databaseManager->method('isContainerRunning')->willReturn(true);
        $this->databaseManager->method('importDump');

        $this->commandTester->execute([
            'name' => 'json-snap',
            '--output' => 'json',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $decoded = json_decode($this->commandTester->getDisplay(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('ok', $decoded['status']);
        $this->assertSame('json-snap', $decoded['data']['name']);
        $this->assertSame('mariadb', $decoded['data']['service']);
    }

    public function testSnapshotNameWithPathTraversalIsRejected(): void
    {
        $this->setupMariadbConfig();

        $this->databaseManager->method('resolveContainerName')->willReturn('dde-mariadb-11.8');
        $this->databaseManager->method('isContainerRunning')->willReturn(true);

        $this->commandTester->execute([
            'name' => '../../etc/evil',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(Command::FAILURE, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Invalid snapshot name', $this->commandTester->getDisplay());
    }

    public function testErrorWhenRestoreFails(): void
    {
        $this->setupMariadbConfig();

        $snapshotDir = $this->tempDir.'/.dde/snapshots/mariadb';
        mkdir($snapshotDir, 0o755, true);
        file_put_contents($snapshotDir.'/fail-snap.sql', '-- sql content');

        $this->databaseManager->method('resolveContainerName')->willReturn('dde-mariadb-11.8');
        $this->databaseManager->method('isContainerRunning')->willReturn(true);
        $this->databaseManager
            ->method('importDump')
            ->willThrowException(new \RuntimeException('restore error'));

        $this->commandTester->execute([
            'name' => 'fail-snap',
        ], [
            'interactive' => false,
        ]);

        $this->assertNotSame(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('restore error', $this->commandTester->getDisplay());
    }

    public function testErrorWhenNoSnapshotsExistInNonInteractiveWithoutName(): void
    {
        $this->setupMariadbConfig();

        $this->databaseManager->method('resolveContainerName')->willReturn('dde-mariadb-11.8');
        $this->databaseManager->method('isContainerRunning')->willReturn(true);

        // No snapshot directory created, no name argument
        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertNotSame(0, $this->commandTester->getStatusCode());
    }

    private function setupMariadbConfig(): void
    {
        $serviceDefinition = new ServiceDefinition(
            name: 'mariadb',
            version: '11.8',
            containerName: 'dde-mariadb-11.8',
        );
        $projectConfig = new ProjectConfig(
            name: 'test-project',
            services: [$serviceDefinition],
        );
        $resolvedConfig = ResolvedConfig::merge(new GlobalConfig(), $projectConfig);

        $this->configManager->method('findProjectDirectory')->willReturn($this->tempDir);
        $this->configManager->method('resolveConfig')->willReturn($resolvedConfig);
    }

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/dde_test_snaprestore_'.bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o755, true);

        $this->configManager = $this->createStub(ProjectConfigManager::class);
        $this->databaseManager = $this->createMock(DatabaseManager::class);
        $this->serviceRegistry = new ServiceRegistry([], new DatabaseAdapterRegistry([new MariaDbAdapter(), new PostgresAdapter()]));

        $formatterResolver = new FormatterResolver(new TextFormatter(), new JsonFormatter());

        $this->command = new DbSnapshotRestoreCommand(
            $this->configManager,
            $this->databaseManager,
            $this->serviceRegistry,
            new Filesystem(),
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
