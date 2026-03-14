<?php

declare(strict_types=1);

namespace Tests\E2E;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[Group('e2e')]
final class PostgresDatabaseCommandsTest extends TestCase
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

        // Drop the table via psql in postgres container
        $this->psql('DROP TABLE e2e_test');

        // Import
        $result = $this->runConsoleJson('project:db:import', [$exportFile]);
        $this->assertSame('ok', $result['status'], 'db:import should succeed');

        // Verify data is back
        $output = $this->psql('SELECT COUNT(*) FROM e2e_test');
        $this->assertStringContainsString('2', $output);
    }

    public function testSnapshotCreateListRestore(): void
    {
        // Create snapshot
        $result = $this->runConsoleJson('project:db:snapshot:create', ['--name=pg-snap']);
        $this->assertSame('ok', $result['status'], 'db:snapshot should succeed');
        $this->assertSame('pg-snap', $result['data']['name']);
        $this->assertGreaterThan(0, $result['data']['size']);

        // List snapshots
        $result = $this->runConsoleJson('project:db:snapshot:list');
        $this->assertSame('ok', $result['status']);
        $this->assertNotEmpty($result['data']['snapshots']);
        $snapshotNames = array_column($result['data']['snapshots'], 'name');
        $this->assertContains('pg-snap', $snapshotNames);

        // Drop table, then restore
        $this->psql('DROP TABLE e2e_test');

        $result = $this->runConsoleJson('project:db:snapshot:restore', ['pg-snap']);
        $this->assertSame('ok', $result['status'], 'snapshot:restore should succeed');

        // Verify data is back
        $output = $this->psql('SELECT COUNT(*) FROM e2e_test');
        $this->assertStringContainsString('2', $output);
    }

    public function testSnapshotListEmptyJson(): void
    {
        $this->filesystem->remove($this->projectDir.'/.dde/snapshots');

        $result = $this->runConsoleJson('project:db:snapshot:list');
        $this->assertSame('ok', $result['status']);
        $this->assertSame([], $result['data']['snapshots']);
    }

    private function psql(string $sql): string
    {
        $process = new Process([
            'docker', 'exec', 'dde-postgres-18.3',
            'psql', '-U', 'postgres', '-d', 'e2e_pg_test', '-t', '-c', $sql,
        ]);
        $process->setTimeout(10);
        $process->run();

        return $process->getOutput();
    }

    private function waitForPostgres(int $maxAttempts = 60): void
    {
        for ($i = 0; $i < $maxAttempts; $i++) {
            $process = new Process(['docker', 'exec', 'dde-postgres-18.3', 'pg_isready', '-U', 'postgres']);
            $process->setTimeout(5);
            $process->run();

            if ($process->isSuccessful()) {
                return;
            }

            usleep(1_000_000);
        }

        $this->fail('PostgreSQL did not become ready within '.$maxAttempts.' seconds');
    }

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->bootProject('e2e-pg-test', 'postgres');
        $this->waitForPostgres();
        usleep(2_000_000); // Allow Postgres to fully initialize after accepting connections

        // Seed via psql directly in postgres container (web container lacks pdo_pgsql)
        (new Process([
            'docker', 'exec', 'dde-postgres-18.3',
            'psql', '-U', 'postgres', '-c', 'DROP DATABASE IF EXISTS e2e_pg_test',
        ]))->setTimeout(10)->mustRun();

        (new Process([
            'docker', 'exec', 'dde-postgres-18.3',
            'psql', '-U', 'postgres', '-c', 'CREATE DATABASE e2e_pg_test',
        ]))->setTimeout(10)->mustRun();

        (new Process([
            'docker', 'exec', 'dde-postgres-18.3',
            'psql', '-U', 'postgres', '-d', 'e2e_pg_test', '-c',
            "CREATE TABLE IF NOT EXISTS e2e_test (id INT PRIMARY KEY, name VARCHAR(50)); INSERT INTO e2e_test VALUES (1, 'hello'), (2, 'world') ON CONFLICT DO NOTHING;",
        ]))->setTimeout(10)->mustRun();
    }

    protected function tearDown(): void
    {
        $this->tearDownE2EProject();
    }
}
