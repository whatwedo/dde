<?php

declare(strict_types=1);

namespace Tests\Unit\Command\Project\Database;

use App\Command\Project\Database\DbCommand;
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
final class DbCommandTest extends TestCase
{
    private string $tempDir;

    private ConfigManager&Stub $configManager;

    private DatabaseManager&MockObject $databaseManager;

    private CommandTester $commandTester;

    private DbCommand $command;

    public function testCommandIsRegistered(): void
    {
        $this->assertSame('project:db', $this->command->getName());
        $this->assertSame('Open an interactive database shell', $this->command->getDescription());
    }

    public function testJsonOutputReturnsError(): void
    {
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

    public function testErrorWhenNoProjectDirectoryFound(): void
    {
        $this->configManager->method('findProjectDirectory')->willReturn(null);

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertNotSame(0, $this->commandTester->getStatusCode());
    }

    public function testErrorWhenNoDatabaseServiceConfigured(): void
    {
        // 'valkey' is not a database service per ServiceRegistry::isDatabaseService
        $this->setupProjectFixture(services: [new ServiceDefinition(name: 'valkey', version: '9')]);

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertNotSame(0, $this->commandTester->getStatusCode());
        $display = $this->commandTester->getDisplay();
        $this->assertStringContainsString('No database service', $display);
    }

    public function testErrorWhenContainerNotRunning(): void
    {
        $this->setupProjectFixture();

        $this->databaseManager->method('resolveContainerName')->willReturn('dde-mariadb-11.8');
        $this->databaseManager->method('isContainerRunning')->willReturn(false);

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertNotSame(0, $this->commandTester->getStatusCode());
        $display = $this->commandTester->getDisplay();
        $this->assertStringContainsString('not running', $display);
    }

    public function testSuccessfullyOpensInteractiveShell(): void
    {
        $this->setupProjectFixture();

        $this->databaseManager->method('resolveContainerName')->willReturn('dde-mariadb-11.8');
        $this->databaseManager->method('isContainerRunning')->willReturn(true);

        $this->databaseManager
            ->expects($this->once())
            ->method('execInteractiveShell');

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $display = $this->commandTester->getDisplay();
        $this->assertStringContainsString('dde-mariadb-11.8', $display);
    }

    public function testUsesExplicitServiceOptionWhenProvided(): void
    {
        $this->setupProjectFixture(services: [
            new ServiceDefinition(name: 'mariadb', version: '11.8'),
            new ServiceDefinition(name: 'postgres', version: '18.3'),
        ]);

        $this->databaseManager->method('resolveContainerName')->willReturn('dde-postgres-18.3');
        $this->databaseManager->method('isContainerRunning')->willReturn(true);
        $this->databaseManager
            ->expects($this->once())
            ->method('execInteractiveShell')
            ->with(
                $this->callback(static fn (ServiceDefinition $svc): bool => $svc->name === 'postgres'),
                $this->anything(),
            );

        $this->commandTester->execute([
            '--service' => 'postgres',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
    }

    public function testErrorWhenExplicitServiceNotFound(): void
    {
        $this->setupProjectFixture(services: [
            new ServiceDefinition(name: 'mariadb', version: '11.8'),
        ]);

        $this->commandTester->execute([
            '--service' => 'postgres',
        ], [
            'interactive' => false,
        ]);

        $this->assertNotSame(0, $this->commandTester->getStatusCode());
        $display = $this->commandTester->getDisplay();
        $this->assertStringContainsString('"postgres" not found', $display);
    }

    public function testDatabaseNameDefaultsToProjectName(): void
    {
        $this->setupProjectFixture();

        $this->databaseManager->method('resolveContainerName')->willReturn('dde-mariadb-11.8');
        $this->databaseManager->method('isContainerRunning')->willReturn(true);

        $this->databaseManager
            ->expects($this->once())
            ->method('execInteractiveShell')
            ->with($this->anything(), 'test_project');

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
    }

    public function testExplicitDatabaseOptionIsForwarded(): void
    {
        $this->setupProjectFixture();

        $this->databaseManager->method('resolveContainerName')->willReturn('dde-mariadb-11.8');
        $this->databaseManager->method('isContainerRunning')->willReturn(true);

        $this->databaseManager
            ->expects($this->once())
            ->method('execInteractiveShell')
            ->with($this->anything(), 'myapp');

        $this->commandTester->execute([
            '--database' => 'myapp',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
    }

    /**
     * @param list<ServiceDefinition>|null $services
     */
    private function setupProjectFixture(?array $services = null): void
    {
        $services ??= [new ServiceDefinition(name: 'mariadb', version: '11.8')];

        $projectConfig = new ProjectConfig(name: 'test-project', services: $services);
        $resolvedConfig = ResolvedConfig::merge(new GlobalConfig(), $projectConfig);

        $this->configManager->method('findProjectDirectory')->willReturn($this->tempDir);
        $this->configManager->method('resolveConfig')->willReturn($resolvedConfig);
    }

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/dde_test_db_'.bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o755, true);

        $this->configManager = $this->createStub(ConfigManager::class);
        $this->databaseManager = $this->createMock(DatabaseManager::class);

        $formatterResolver = new FormatterResolver(new TextFormatter(), new JsonFormatter());

        $this->command = new DbCommand(
            $this->configManager,
            $formatterResolver,
            $this->databaseManager,
            new ServiceRegistry([], new DatabaseAdapterRegistry([new MariaDbAdapter(), new PostgresAdapter()])),
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
