<?php

declare(strict_types=1);

namespace Tests\E2E;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[Group('e2e')]
final class DatabaseCommandsTest extends TestCase
{
    use E2ETestHelper;

    private Filesystem $filesystem;

    public function testDbExportAndImport(): void
    {
        $exportFile = $this->projectDir.'/dump.sql';

        // Export
        $result = $this->runConsoleJson('project:db:export', [$exportFile]);
        $this->assertSame('ok', $result['status'], 'db:export should succeed');
        $this->assertFileExists($exportFile);
        $this->assertGreaterThan(0, $result['data']['size']);

        $dumpContent = file_get_contents($exportFile);
        $this->assertStringContainsString('e2e_test', $dumpContent);

        // Drop the table, then import
        $this->runConsole('project:exec', [
            '-s', 'web', '--', 'php', '-r',
            "\$pdo = new PDO('mysql:host=mariadb;port=3306;dbname=e2e_db_test', 'root', 'root'); \$pdo->exec('DROP TABLE e2e_test'); echo 'DROP_OK';",
        ]);

        // Import
        $result = $this->runConsoleJson('project:db:import', [$exportFile]);
        $this->assertSame('ok', $result['status'], 'db:import should succeed');

        // Verify data is back
        $process = $this->runConsole('project:exec', [
            '-s', 'web', '--', 'php', '-r',
            "\$pdo = new PDO('mysql:host=mariadb;port=3306;dbname=e2e_db_test', 'root', 'root'); \$r = \$pdo->query('SELECT COUNT(*) FROM e2e_test'); echo 'COUNT=' . \$r->fetchColumn();",
        ]);
        $this->assertStringContainsString('COUNT=2', $process->getOutput());
    }

    public function testSnapshotCreateListRestore(): void
    {
        // Create snapshot
        $result = $this->runConsoleJson('project:db:snapshot:create', ['--name=e2e-snap']);
        $this->assertSame('ok', $result['status'], 'db:snapshot should succeed');
        $this->assertSame('e2e-snap', $result['data']['name']);
        $this->assertGreaterThan(0, $result['data']['size']);

        // List snapshots
        $result = $this->runConsoleJson('project:db:snapshot:list');
        $this->assertSame('ok', $result['status']);
        $this->assertNotEmpty($result['data']['snapshots']);
        $snapshotNames = array_column($result['data']['snapshots'], 'name');
        $this->assertContains('e2e-snap', $snapshotNames);

        // Drop table, then restore
        $this->runConsole('project:exec', [
            '-s', 'web', '--', 'php', '-r',
            "\$pdo = new PDO('mysql:host=mariadb;port=3306;dbname=e2e_db_test', 'root', 'root'); \$pdo->exec('DROP TABLE e2e_test'); echo 'DROP_OK';",
        ]);

        $result = $this->runConsoleJson('project:db:snapshot:restore', ['e2e-snap']);
        $this->assertSame('ok', $result['status'], 'snapshot:restore should succeed');

        // Verify data is back
        $process = $this->runConsole('project:exec', [
            '-s', 'web', '--', 'php', '-r',
            "\$pdo = new PDO('mysql:host=mariadb;port=3306;dbname=e2e_db_test', 'root', 'root'); \$r = \$pdo->query('SELECT COUNT(*) FROM e2e_test'); echo 'COUNT=' . \$r->fetchColumn();",
        ]);
        $this->assertStringContainsString('COUNT=2', $process->getOutput());
    }

    public function testSnapshotListEmptyJson(): void
    {
        // Before any snapshots, list should return empty array
        $this->filesystem->remove($this->projectDir.'/.dde/snapshots');

        $result = $this->runConsoleJson('project:db:snapshot:list');
        $this->assertSame('ok', $result['status']);
        $this->assertSame([], $result['data']['snapshots']);
    }

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->bootProject('e2e-db-test', 'mariadb');
        $this->waitForMariaDb();

        // Create a test database matching the sanitized project name and seed data.
        // dde sanitizes 'e2e-db-test' to 'e2e_db_test' for the default database name.
        $seedProcess = $this->runConsole('project:exec', [
            '-s', 'web', '--', 'php', '-r',
            implode(' ', [
                "\$pdo = new PDO('mysql:host=mariadb;port=3306', 'root', 'root');",
                "\$pdo->exec('CREATE DATABASE IF NOT EXISTS e2e_db_test');",
                "\$pdo->exec('USE e2e_db_test');",
                "\$pdo->exec('CREATE TABLE IF NOT EXISTS e2e_test (id INT PRIMARY KEY, name VARCHAR(50))');",
                "\$pdo->exec(\"INSERT IGNORE INTO e2e_test VALUES (1, 'hello'), (2, 'world')\");",
                "echo 'SEED_OK';",
            ]),
        ]);
        $this->assertStringContainsString('SEED_OK', $seedProcess->getOutput(), 'Database seeding should succeed');
    }

    protected function tearDown(): void
    {
        $this->tearDownE2EProject();
    }
}
