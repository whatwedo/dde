<?php

declare(strict_types=1);

namespace Tests\Unit\Command\Project\Database;

use App\Command\Project\Database\DbSnapshotCreateCommand;
use App\Config\GlobalConfig;
use App\Config\ProjectConfig;
use App\Config\ResolvedConfig;
use App\Database\DatabaseAdapterRegistry;
use App\Database\MariaDbAdapter;
use App\Database\PostgresAdapter;
use App\Manager\ConfigManager;
use App\Manager\DatabaseManager;
use App\Model\ServiceDefinition;
use App\Output\FormatterResolver;
use App\Output\JsonFormatter;
use App\Output\TextFormatter;
use App\Service\ServiceRegistry;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class DbSnapshotCreateCommandTest extends TestCase
{
    private string $tempDir;

    private ConfigManager&Stub $configManager;

    private DatabaseManager&Stub $databaseManager;

    private ServiceRegistry $serviceRegistry;

    private CommandTester $commandTester;

    private DbSnapshotCreateCommand $command;

    public function testCommandIsRegistered(): void
    {
        $this->assertSame('project:db:snapshot:create', $this->command->getName());
        $this->assertSame('Create a database snapshot', $this->command->getDescription());
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

    public function testErrorWhenNoDatabaseServiceConfigured(): void
    {
        $projectConfig = new ProjectConfig(
            name: 'test-project',
            services: [
                new ServiceDefinition(name: 'valkey', version: '9'),
            ],
        );
        $resolvedConfig = ResolvedConfig::merge(new GlobalConfig(), $projectConfig);

        $this->configManager->method('findProjectDirectory')->willReturn($this->tempDir);
        $this->configManager->method('resolveConfig')->willReturn($resolvedConfig);

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertNotSame(0, $this->commandTester->getStatusCode());
    }

    public function testErrorWhenContainerIsNotRunning(): void
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

        $this->databaseManager
            ->method('resolveContainerName')
            ->willReturn('dde-mariadb-11.8');

        $this->databaseManager
            ->method('isContainerRunning')
            ->willReturn(false);

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertNotSame(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('not running', $this->commandTester->getDisplay());
    }

    public function testErrorWhenContainerNotRunningWithJsonOutput(): void
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

        $this->databaseManager
            ->method('resolveContainerName')
            ->willReturn('dde-mariadb-11.8');

        $this->databaseManager
            ->method('isContainerRunning')
            ->willReturn(false);

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

    public function testHappyPathCreatesSnapshotFile(): void
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

        $this->databaseManager
            ->method('resolveContainerName')
            ->willReturn('dde-mariadb-11.8');

        $this->databaseManager
            ->method('isContainerRunning')
            ->willReturn(true);

        $this->databaseManager
            ->method('exportDump')
            ->willReturn('-- SQL dump content');

        $this->commandTester->execute([
            '--name' => 'my-snapshot',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());

        $expectedFile = $this->tempDir.'/.dde/snapshots/mariadb/my-snapshot.sql';
        $this->assertFileExists($expectedFile);
        $this->assertSame('-- SQL dump content', file_get_contents($expectedFile));
    }

    public function testHappyPathWithJsonOutput(): void
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

        $this->databaseManager->method('resolveContainerName')->willReturn('dde-mariadb-11.8');
        $this->databaseManager->method('isContainerRunning')->willReturn(true);
        $this->databaseManager->method('exportDump')->willReturn('-- SQL dump content');

        $this->commandTester->execute([
            '--name' => 'json-snap',
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
        $this->assertArrayHasKey('file', $decoded['data']);
        $this->assertArrayHasKey('size', $decoded['data']);
    }

    public function testDefaultSnapshotNameIsGenerated(): void
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

        $this->databaseManager->method('resolveContainerName')->willReturn('dde-mariadb-11.8');
        $this->databaseManager->method('isContainerRunning')->willReturn(true);
        $this->databaseManager->method('exportDump')->willReturn('-- SQL');

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());

        // Check a snapshot file starting with "snapshot-" was created
        $snapshotDir = $this->tempDir.'/.dde/snapshots/mariadb';
        $files = glob($snapshotDir.'/snapshot-*.sql');
        $this->assertNotEmpty($files);
    }

    public function testSnapshotNameWithPathTraversalIsRejected(): void
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
        $this->databaseManager->method('resolveContainerName')->willReturn('dde-mariadb-11.8');
        $this->databaseManager->method('isContainerRunning')->willReturn(true);

        $this->commandTester->execute([
            '--name' => '../../etc/evil',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(Command::FAILURE, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Invalid snapshot name', $this->commandTester->getDisplay());
    }

    public function testErrorWhenDumpFails(): void
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

        $this->databaseManager->method('resolveContainerName')->willReturn('dde-mariadb-11.8');
        $this->databaseManager->method('isContainerRunning')->willReturn(true);
        $this->databaseManager
            ->method('exportDump')
            ->willThrowException(new \RuntimeException('dump error'));

        $this->commandTester->execute([
            '--name' => 'fail-snap',
        ], [
            'interactive' => false,
        ]);

        $this->assertNotSame(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('dump error', $this->commandTester->getDisplay());
    }

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/dde_test_dbsnapshot_'.bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o755, true);

        $this->configManager = $this->createStub(ConfigManager::class);
        $this->databaseManager = $this->createStub(DatabaseManager::class);
        $this->serviceRegistry = new ServiceRegistry([], new DatabaseAdapterRegistry([new MariaDbAdapter(), new PostgresAdapter()]));

        $formatterResolver = new FormatterResolver(new TextFormatter(), new JsonFormatter());

        $this->command = new DbSnapshotCreateCommand(
            $this->configManager,
            $this->databaseManager,
            $this->serviceRegistry,
            new Filesystem(),
            new MockClock('2026-03-22 10:00:00'),
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
