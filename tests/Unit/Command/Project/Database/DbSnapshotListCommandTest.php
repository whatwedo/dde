<?php

declare(strict_types=1);

namespace Tests\Unit\Command\Project\Database;

use App\Command\Project\Database\DbSnapshotListCommand;
use App\Config\GlobalConfig;
use App\Config\ProjectConfig;
use App\Config\ResolvedConfig;
use App\Database\DatabaseAdapterRegistry;
use App\Database\MariaDbAdapter;
use App\Database\PostgresAdapter;
use App\Manager\ProjectConfigManager;
use App\Model\ServiceDefinition;
use App\Output\FormatterResolver;
use App\Output\JsonFormatter;
use App\Output\TextFormatter;
use App\Service\ServiceRegistry;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class DbSnapshotListCommandTest extends TestCase
{
    private string $tempDir;

    private ProjectConfigManager&Stub $configManager;

    private CommandTester $commandTester;

    private DbSnapshotListCommand $command;

    public function testCommandIsRegistered(): void
    {
        $this->assertSame('project:db:snapshot:list', $this->command->getName());
        $this->assertSame('List database snapshots', $this->command->getDescription());
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

    public function testEmptyWhenSnapshotDirDoesNotExist(): void
    {
        $this->setupMariadbConfig();

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('No snapshots found', $this->commandTester->getDisplay());
    }

    public function testEmptyJsonWhenSnapshotDirDoesNotExist(): void
    {
        $this->setupMariadbConfig();

        $this->commandTester->execute([
            '--output' => 'json',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $decoded = json_decode($this->commandTester->getDisplay(), true);
        $this->assertIsArray($decoded);
        $this->assertSame([], $decoded['data']['snapshots']);
    }

    public function testEmptyWhenSnapshotDirExistsButIsEmpty(): void
    {
        $this->setupMariadbConfig();
        $snapshotDir = $this->tempDir.'/.dde/snapshots/mariadb';
        mkdir($snapshotDir, 0o755, true);

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('No snapshots found', $this->commandTester->getDisplay());
    }

    public function testListsSnapshotsWithTextOutput(): void
    {
        $this->setupMariadbConfig();
        $snapshotDir = $this->tempDir.'/.dde/snapshots/mariadb';
        mkdir($snapshotDir, 0o755, true);

        file_put_contents($snapshotDir.'/snap-a.sql', '-- snap a');
        sleep(1);
        file_put_contents($snapshotDir.'/snap-b.sql', '-- snap b');

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('snap-a', $output);
        $this->assertStringContainsString('snap-b', $output);
    }

    public function testListsSnapshotsWithJsonOutput(): void
    {
        $this->setupMariadbConfig();
        $snapshotDir = $this->tempDir.'/.dde/snapshots/mariadb';
        mkdir($snapshotDir, 0o755, true);

        file_put_contents($snapshotDir.'/snap-c.sql', '-- snap c content');

        $this->commandTester->execute([
            '--output' => 'json',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $decoded = json_decode($this->commandTester->getDisplay(), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('snapshots', $decoded['data']);
        $this->assertCount(1, $decoded['data']['snapshots']);
        $this->assertSame('snap-c', $decoded['data']['snapshots'][0]['name']);
        $this->assertArrayHasKey('file', $decoded['data']['snapshots'][0]);
        $this->assertArrayHasKey('size', $decoded['data']['snapshots'][0]);
        $this->assertArrayHasKey('modified', $decoded['data']['snapshots'][0]);
    }

    public function testSnapshotsAreSortedNewestFirst(): void
    {
        $this->setupMariadbConfig();
        $snapshotDir = $this->tempDir.'/.dde/snapshots/mariadb';
        mkdir($snapshotDir, 0o755, true);

        file_put_contents($snapshotDir.'/snap-old.sql', '-- old');
        sleep(1);
        file_put_contents($snapshotDir.'/snap-new.sql', '-- new');

        $this->commandTester->execute([
            '--output' => 'json',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $decoded = json_decode($this->commandTester->getDisplay(), true);
        $this->assertIsArray($decoded);
        $this->assertCount(2, $decoded['data']['snapshots']);
        $this->assertSame('snap-new', $decoded['data']['snapshots'][0]['name']);
        $this->assertSame('snap-old', $decoded['data']['snapshots'][1]['name']);
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
        $this->tempDir = sys_get_temp_dir().'/dde_test_snaplist_'.bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o755, true);

        $this->configManager = $this->createStub(ProjectConfigManager::class);
        $serviceRegistry = new ServiceRegistry([], new DatabaseAdapterRegistry([new MariaDbAdapter(), new PostgresAdapter()]));

        $formatterResolver = new FormatterResolver(new TextFormatter(), new JsonFormatter());

        $this->command = new DbSnapshotListCommand(
            $this->configManager,
            $serviceRegistry,
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
