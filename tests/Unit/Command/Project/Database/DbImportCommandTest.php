<?php

declare(strict_types=1);

namespace Tests\Unit\Command\Project\Database;

use App\Command\Project\Database\DbImportCommand;
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
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

#[AllowMockObjectsWithoutExpectations]
final class DbImportCommandTest extends TestCase
{
    private string $tempDir;

    private ProjectConfigManager&Stub $configManager;

    private DatabaseManager&MockObject $databaseManager;

    private ServiceRegistry $serviceRegistry;

    private CommandTester $commandTester;

    private DbImportCommand $command;

    public function testCommandIsRegistered(): void
    {
        $this->assertSame('project:db:import', $this->command->getName());
        $this->assertSame('Import a SQL file into the database', $this->command->getDescription());
    }

    public function testErrorWhenNoProjectDirectoryFound(): void
    {
        $this->configManager
            ->method('findProjectDirectory')
            ->willReturn(null);

        $this->commandTester->execute([
            'file' => '/tmp/dump.sql',
        ], [
            'interactive' => false,
        ]);

        $this->assertNotSame(0, $this->commandTester->getStatusCode());
    }

    public function testErrorWhenNoDatabaseServiceConfigured(): void
    {
        // Use a non-db service so isDatabaseService() returns false
        $projectConfig = new ProjectConfig(
            name: 'test-project',
            services: [
                new ServiceDefinition(name: 'valkey', version: '9'),
            ],
        );
        $resolvedConfig = ResolvedConfig::merge(new GlobalConfig(), $projectConfig);

        $this->configManager->method('findProjectDirectory')->willReturn($this->tempDir);
        $this->configManager->method('resolveConfig')->willReturn($resolvedConfig);

        $this->commandTester->execute([
            'file' => '/tmp/dump.sql',
        ], [
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

        $this->commandTester->execute([
            'file' => '/tmp/dump.sql',
        ], [
            'interactive' => false,
        ]);

        $this->assertNotSame(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('not running', $this->commandTester->getDisplay());
    }

    public function testErrorWhenContainerIsNotRunningWithJsonOutput(): void
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
            'file' => '/tmp/dump.sql',
            '--output' => 'json',
        ], [
            'interactive' => false,
        ]);

        $this->assertNotSame(0, $this->commandTester->getStatusCode());
        $decoded = json_decode($this->commandTester->getDisplay(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('error', $decoded['status']);
    }

    public function testErrorWhenSpecifiedServiceNotFoundInConfig(): void
    {
        $projectConfig = new ProjectConfig(
            name: 'test-project',
            services: [
                new ServiceDefinition(name: 'mariadb', version: '11.8'),
            ],
        );
        $resolvedConfig = ResolvedConfig::merge(new GlobalConfig(), $projectConfig);

        $this->configManager->method('findProjectDirectory')->willReturn($this->tempDir);
        $this->configManager->method('resolveConfig')->willReturn($resolvedConfig);

        $this->commandTester->execute([
            'file' => '/tmp/dump.sql',
            '--service' => 'postgres',
        ], [
            'interactive' => false,
        ]);

        $this->assertNotSame(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('postgres', $this->commandTester->getDisplay());
    }

    public function testImportSuccessfully(): void
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

        $sqlFile = $this->tempDir.'/import.sql';
        file_put_contents($sqlFile, '-- SQL dump content');

        $this->commandTester->execute([
            'file' => $sqlFile,
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
    }

    public function testErrorWhenImportFileDoesNotExist(): void
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

        $this->commandTester->execute([
            'file' => '/tmp/nonexistent_dde_test_file_that_does_not_exist.sql',
        ], [
            'interactive' => false,
        ]);

        $this->assertNotSame(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('nonexistent_dde_test_file_that_does_not_exist.sql', $this->commandTester->getDisplay());
    }

    public function testContainerNameFallsBackToDefaultWhenEmpty(): void
    {
        // containerName is '' — the manager falls back to dde-{name}-{version}
        $serviceDefinition = new ServiceDefinition(
            name: 'mariadb',
            version: '11.8',
            containerName: '',
        );
        $projectConfig = new ProjectConfig(
            name: 'test-project',
            services: [$serviceDefinition],
        );
        $resolvedConfig = ResolvedConfig::merge(new GlobalConfig(), $projectConfig);

        $this->configManager->method('findProjectDirectory')->willReturn($this->tempDir);
        $this->configManager->method('resolveConfig')->willReturn($resolvedConfig);

        $this->databaseManager
            ->expects($this->once())
            ->method('resolveContainerName')
            ->willReturn('dde-mariadb-11.8');

        $this->databaseManager
            ->expects($this->once())
            ->method('isContainerRunning')
            ->willReturn(false);

        $this->commandTester->execute([
            'file' => '/tmp/dump.sql',
        ], [
            'interactive' => false,
        ]);

        $this->assertNotSame(0, $this->commandTester->getStatusCode());
    }

    public function testServiceSelectionByExplicitOption(): void
    {
        $mariadbService = new ServiceDefinition(
            name: 'mariadb',
            version: '11.8',
            containerName: 'dde-mariadb-11.8',
        );
        $postgresService = new ServiceDefinition(
            name: 'postgres',
            version: '18.3',
            containerName: 'dde-postgres-18.3',
        );
        $projectConfig = new ProjectConfig(
            name: 'test-project',
            services: [$mariadbService, $postgresService],
        );
        $resolvedConfig = ResolvedConfig::merge(new GlobalConfig(), $projectConfig);

        $this->configManager->method('findProjectDirectory')->willReturn($this->tempDir);
        $this->configManager->method('resolveConfig')->willReturn($resolvedConfig);

        $this->databaseManager
            ->method('resolveContainerName')
            ->willReturn('dde-postgres-18.3');

        // When explicitly specifying 'postgres', it should check the postgres container
        $this->databaseManager
            ->expects($this->once())
            ->method('isContainerRunning')
            ->willReturn(false);

        $this->commandTester->execute([
            'file' => '/tmp/dump.sql',
            '--service' => 'postgres',
        ], [
            'interactive' => false,
        ]);

        $this->assertNotSame(0, $this->commandTester->getStatusCode());
    }

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/dde_test_dbimport_'.bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o755, true);

        $this->configManager = $this->createStub(ProjectConfigManager::class);
        $this->databaseManager = $this->createMock(DatabaseManager::class);
        $this->serviceRegistry = new ServiceRegistry([], new DatabaseAdapterRegistry([new MariaDbAdapter(), new PostgresAdapter()]));

        $formatterResolver = new FormatterResolver(new TextFormatter(), new JsonFormatter());

        $this->command = new DbImportCommand(
            $this->configManager,
            $formatterResolver,
            $this->databaseManager,
            $this->serviceRegistry,
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
