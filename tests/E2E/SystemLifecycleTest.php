<?php

declare(strict_types=1);

namespace Tests\E2E;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[Group('e2e')]
final class SystemLifecycleTest extends TestCase
{
    use E2ETestHelper;

    public function testSystemDownStopsAllServices(): void
    {
        // Start system
        $result = $this->runConsoleJson('system:up', timeout: 180);
        $this->assertSame('ok', $result['status'], 'system:up should succeed');

        // Verify services are running
        $status = $this->runConsoleJson('system:status');
        $this->assertSame('ok', $status['status']);
        $this->assertNotEmpty($status['data']['services'], 'Services should be running after system:up');

        // Stop system
        $result = $this->runConsoleJson('system:down', timeout: 60);
        $this->assertSame('ok', $result['status'], 'system:down should succeed');

        // Verify services are stopped
        $status = $this->runConsoleJson('system:status');
        $this->assertSame('ok', $status['status']);

        $runningServices = array_filter(
            $status['data']['services'],
            static fn (array $s): bool => $s['status'] === 'running',
        );
        $this->assertEmpty($runningServices, 'No services should be running after system:down');
    }

    public function testSystemRestartBringsServicesBackUp(): void
    {
        // Start system
        $result = $this->runConsoleJson('system:up', timeout: 180);
        $this->assertSame('ok', $result['status'], 'system:up should succeed');

        // Stop system
        $result = $this->runConsoleJson('system:down', timeout: 60);
        $this->assertSame('ok', $result['status'], 'system:down should succeed');

        // Start system again (restart)
        $result = $this->runConsoleJson('system:up', timeout: 180);
        $this->assertSame('ok', $result['status'], 'system:up after down should succeed');

        // Verify services are running via status JSON
        $status = $this->runConsoleJson('system:status');
        $this->assertSame('ok', $status['status']);
        $this->assertArrayHasKey('services', $status['data']);

        $serviceNames = array_column($status['data']['services'], 'name');
        $this->assertContains('traefik', $serviceNames, 'traefik should be running after restart');

        $traefikService = array_first(array_filter(
            $status['data']['services'],
            static fn (array $s): bool => $s['name'] === 'traefik',
        ));
        $this->assertSame('running', $traefikService['status'], 'traefik should be in running state');
    }

    public function testSystemDownThenUpIsClean(): void
    {
        // Start system
        $result = $this->runConsoleJson('system:up', timeout: 180);
        $this->assertSame('ok', $result['status'], 'initial system:up should succeed');

        // Stop system
        $result = $this->runConsoleJson('system:down', timeout: 60);
        $this->assertSame('ok', $result['status'], 'system:down should succeed');

        // Start system again
        $result = $this->runConsoleJson('system:up', timeout: 180);
        $this->assertSame('ok', $result['status'], 'system:up after down should succeed');

        // Verify clean state via status
        $status = $this->runConsoleJson('system:status');
        $this->assertSame('ok', $status['status']);
        $this->assertTrue($status['data']['network'], 'dde network should exist after clean up');

        $runningServices = array_filter(
            $status['data']['services'],
            static fn (array $s): bool => $s['status'] === 'running',
        );
        $this->assertNotEmpty($runningServices, 'At least one service should be running after clean up');
    }

    public function testSystemDownIsIdempotent(): void
    {
        // Ensure nothing is running
        $this->runConsole('system:down', timeout: 60);

        // Run system:down again when nothing is running — should succeed
        $result = $this->runConsoleJson('system:down', timeout: 60);
        $this->assertSame('ok', $result['status'], 'system:down when nothing is running should succeed');

        // Run it a third time to confirm idempotency
        $result = $this->runConsoleJson('system:down', timeout: 60);
        $this->assertSame('ok', $result['status'], 'system:down should be idempotent');
    }

    protected function setUp(): void
    {
        $this->consolePath = dirname(__DIR__, 2).'/bin/console';
        $this->projectDir = sys_get_temp_dir();
        $this->tempDataDir = sys_get_temp_dir().'/dde-e2e-lifecycle-'.bin2hex(random_bytes(8));
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
