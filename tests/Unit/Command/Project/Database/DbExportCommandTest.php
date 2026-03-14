<?php

declare(strict_types=1);

namespace Tests\Unit\Command\Project\Database;

use App\Command\Project\Database\DbExportCommand;
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
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

#[AllowMockObjectsWithoutExpectations]
final class DbExportCommandTest extends TestCase
{
    private string $tempDir;

    private ConfigManager&Stub $configManager;

    private DatabaseManager&MockObject $databaseManager;

    private ServiceRegistry $serviceRegistry;

    private CommandTester $commandTester;

    private DbExportCommand $command;

    public function testCommandIsRegistered(): void
    {
        $this->assertSame('project:db:export', $this->command->getName());
        $this->assertSame('Export the database to a SQL file', $this->command->getDescription());
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

    public function testExportSuccessfully(): void
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

        $outputFile = $this->tempDir.'/dump.sql';

        $this->commandTester->execute([
            'file' => $outputFile,
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $this->assertFileExists($outputFile);
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

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/dde_test_dbexport_'.bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o755, true);

        $this->configManager = $this->createStub(ConfigManager::class);
        $this->databaseManager = $this->createMock(DatabaseManager::class);
        $this->serviceRegistry = new ServiceRegistry([], new DatabaseAdapterRegistry([new MariaDbAdapter(), new PostgresAdapter()]));

        $formatterResolver = new FormatterResolver(new TextFormatter(), new JsonFormatter());

        $this->command = new DbExportCommand(
            $this->configManager,
            $formatterResolver,
            $this->databaseManager,
            $this->serviceRegistry,
            new Filesystem(),
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
