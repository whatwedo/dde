<?php

declare(strict_types=1);

namespace Tests\Unit\Command\Project\Database;

use App\Command\Project\Database\DbOpenCommand;
use App\Config\GlobalConfig;
use App\Config\ProjectConfig;
use App\Config\ResolvedConfig;
use App\Database\DatabaseAdapterInterface;
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
use App\Util\UrlOpenerUtil;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class DbOpenCommandTest extends TestCase
{
    private string $tempDir;

    private ProjectConfigManager&Stub $configManager;

    private DatabaseManager&Stub $databaseManager;

    private DatabaseAdapterInterface&Stub $adapter;

    private CommandTester $commandTester;

    private DbOpenCommand $command;

    public function testCommandIsRegistered(): void
    {
        $this->assertSame('project:db:open', $this->command->getName());
        $this->assertSame('Open the database in an external client', $this->command->getDescription());
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

    public function testErrorWhenAdapterNotFound(): void
    {
        // Use 'postgres' service but registry only has adapter for 'mariadb'
        $this->setupProjectFixture(services: [new ServiceDefinition(name: 'postgres', version: '18.3')]);

        $this->databaseManager->method('resolveContainerName')->willReturn('dde-postgres-18.3');
        $this->databaseManager->method('isContainerRunning')->willReturn(true);

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertNotSame(0, $this->commandTester->getStatusCode());
        $display = $this->commandTester->getDisplay();
        $this->assertStringContainsString('No database adapter', $display);
    }

    public function testJsonOutputReturnsUrl(): void
    {
        $this->setupProjectFixture();
        $this->databaseManager->method('resolveContainerName')->willReturn('dde-mariadb-11.8');
        $this->databaseManager->method('isContainerRunning')->willReturn(true);
        $this->databaseManager->method('resolveHostPort')->willReturn(3306);

        $this->adapter->method('getDsn')->willReturn('mysql://root:root@127.0.0.1:3306/test_project');

        $this->commandTester->execute([
            '--output' => 'json',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $decoded = json_decode($this->commandTester->getDisplay(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('ok', $decoded['status']);
        $this->assertArrayHasKey('url', $decoded['data']);
        $this->assertStringContainsString('mysql://', $decoded['data']['url']);
    }

    public function testTextOutputPrintsUrlAndSucceeds(): void
    {
        $this->setupProjectFixture();
        $this->databaseManager->method('resolveContainerName')->willReturn('dde-mariadb-11.8');
        $this->databaseManager->method('isContainerRunning')->willReturn(true);
        $this->databaseManager->method('resolveHostPort')->willReturn(3306);

        $this->adapter->method('getDsn')->willReturn('mysql://root:root@127.0.0.1:3306/test_project');

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $display = $this->commandTester->getDisplay();
        $this->assertStringContainsString('mysql://', $display);
    }

    public function testHostPortIsPassedToDsn(): void
    {
        $this->setupProjectFixture();
        $this->databaseManager->method('resolveContainerName')->willReturn('dde-mariadb-11.8');
        $this->databaseManager->method('isContainerRunning')->willReturn(true);
        $this->databaseManager->method('resolveHostPort')->willReturn(33060);

        $capturedPort = null;
        $this->adapter->method('getDsn')
            ->willReturnCallback(function (string $database, int $port, string $host) use (&$capturedPort): string {
                $capturedPort = $port;

                return 'mysql://root:root@127.0.0.1:33060/test_project';
            });

        $this->commandTester->execute([
            '--output' => 'json',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(33060, $capturedPort);
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
        $this->tempDir = sys_get_temp_dir().'/dde_test_dbopen_'.bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o755, true);

        $this->configManager = $this->createStub(ProjectConfigManager::class);
        $this->databaseManager = $this->createStub(DatabaseManager::class);

        // Adapter stub for 'mariadb' — injected into real DatabaseAdapterRegistry
        $this->adapter = $this->createStub(DatabaseAdapterInterface::class);
        $this->adapter->method('getServiceName')->willReturn('mariadb');
        $this->adapter->method('supports')->willReturnCallback(
            static fn (string $name): bool => $name === 'mariadb',
        );

        $formatterResolver = new FormatterResolver(new TextFormatter(), new JsonFormatter());

        $processFactory = $this->createMock(\App\Util\ProcessFactory::class);
        $processFactory->method('create')
            ->willReturnCallback(static function (): Process {
                $process = new Process(['true']);
                $process->run();

                return $process;
            });
        $urlOpener = new UrlOpenerUtil($processFactory);

        $this->command = new DbOpenCommand(
            $this->configManager,
            $formatterResolver,
            $this->databaseManager,
            new DatabaseAdapterRegistry([$this->adapter]),
            new ServiceRegistry([], new DatabaseAdapterRegistry([new MariaDbAdapter(), new PostgresAdapter()])),
            $urlOpener,
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
