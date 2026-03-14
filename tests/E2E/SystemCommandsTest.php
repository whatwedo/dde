<?php

declare(strict_types=1);

namespace Tests\E2E;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[Group('e2e')]
final class SystemCommandsTest extends TestCase
{
    use E2ETestHelper;

    public function testSystemUpAndStatus(): void
    {
        // Start system
        $result = $this->runConsoleJson('system:up', timeout: 180);
        $this->assertSame('ok', $result['status'], 'system:up should succeed');

        // Check status JSON
        $result = $this->runConsoleJson('system:status');
        $this->assertSame('ok', $result['status']);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('services', $result['data']);
        $this->assertArrayHasKey('network', $result['data']);
        $this->assertTrue($result['data']['network'], 'dde network should exist after system:up');

        // At least traefik should be running
        $serviceNames = array_column($result['data']['services'], 'name');
        $this->assertContains('traefik', $serviceNames);

        $traefikService = array_first(array_filter(
            $result['data']['services'],
            static fn (array $s): bool => $s['name'] === 'traefik',
        ));
        $this->assertSame('running', $traefikService['status']);
    }

    public function testSystemStatusTextOutput(): void
    {
        $this->runConsole('system:up', timeout: 180);
        $process = $this->runConsole('system:status');
        $this->assertTrue($process->isSuccessful());
        $this->assertStringContainsString('traefik', $process->getOutput());
    }

    public function testSystemDoctor(): void
    {
        $this->runConsole('system:up', timeout: 180);

        // JSON output — some checks may fail in dev (no dde binary, docker compose path)
        $process = $this->runConsole('system:doctor', ['--output=json']);
        $json = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($json);

        // Response may be ok or error depending on environment
        $this->assertContains($json['status'], ['ok', 'error']);

        // Extract checks from data (ok) or verify errors array (error)
        if ($json['status'] === 'ok') {
            $this->assertArrayHasKey('checks', $json['data']);
            $checks = $json['data']['checks'];
        } else {
            // When checks fail, errors array contains the failures
            $this->assertNotEmpty($json['errors']);

            return;
        }

        $this->assertNotEmpty($checks);

        foreach ($checks as $check) {
            $this->assertArrayHasKey('name', $check);
            $this->assertArrayHasKey('status', $check);
            $this->assertArrayHasKey('message', $check);
        }
    }

    public function testSystemCleanupDryRun(): void
    {
        $this->runConsole('system:up', timeout: 180);

        // Dry-run should succeed and not delete anything
        $result = $this->runConsoleJson('system:cleanup', ['--dry-run']);
        $this->assertSame('ok', $result['status']);
        $this->assertArrayHasKey('data', $result);
        // When items exist: dry_run=true + items array. When no items: just items=[]
        $this->assertArrayHasKey('items', $result['data']);
    }

    public function testSystemCleanupTextDryRun(): void
    {
        $this->runConsole('system:up', timeout: 180);
        $process = $this->runConsole('system:cleanup', ['--dry-run']);
        $this->assertTrue($process->isSuccessful());
        // Should mention "dry run" or "nothing" in output
        $output = $process->getOutput();
        $this->assertTrue(
            str_contains($output, 'Dry run') || str_contains($output, 'Nothing to clean up'),
            'Cleanup dry-run should indicate dry run or nothing to clean',
        );
    }

    public function testSystemCleanupActualRemoval(): void
    {
        $this->runConsole('system:up', timeout: 180);

        // Run actual cleanup (not dry-run) with --force to skip confirmation
        $result = $this->runConsoleJson('system:cleanup', ['--force']);
        $this->assertSame('ok', $result['status']);
        $this->assertArrayHasKey('data', $result);

        // When items were found and deleted: deleted array present
        // When nothing to clean: items array present (empty)
        $data = $result['data'];
        $this->assertTrue(
            array_key_exists('deleted', $data) || array_key_exists('items', $data),
            'Cleanup response should contain "deleted" or "items" key',
        );
    }

    public function testSystemServiceUp(): void
    {
        // Ensure system network exists
        $this->runConsoleJson('system:up', timeout: 180);

        // Start valkey via system:service:up (traefik/dnsmasq are global services, not service types)
        $result = $this->runConsoleJson('system:service:up', ['valkey']);
        $this->assertSame('ok', $result['status'], 'system:service:up valkey should succeed');
        $this->assertSame('valkey', $result['data']['service']);
        $this->assertArrayHasKey('port', $result['data']);

        // Clean up the valkey container
        (new Process(['docker', 'rm', '-f', $result['data']['container']]))->run();
    }

    protected function setUp(): void
    {
        $this->consolePath = dirname(__DIR__, 2).'/bin/console';
        $this->projectDir = sys_get_temp_dir();
        $this->tempDataDir = sys_get_temp_dir().'/dde-e2e-system-'.bin2hex(random_bytes(8));
        (new Filesystem())->mkdir($this->tempDataDir);

        // Ensure clean state
        $this->runConsole('system:down', timeout: 60);
    }

    protected function tearDown(): void
    {
        $this->runConsole('system:down', timeout: 60);
        (new Filesystem())->remove($this->tempDataDir);
    }
}
