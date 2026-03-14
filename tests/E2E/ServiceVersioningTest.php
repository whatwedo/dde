<?php

declare(strict_types=1);

namespace Tests\E2E;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[Group('e2e')]
final class ServiceVersioningTest extends TestCase
{
    use E2ETestHelper;

    /**
     * @var list<string>
     */
    private array $containersToCleanup = [];

    public function testServiceUpWithDefaultVersion(): void
    {
        $result = $this->runConsoleJson('system:service:up', ['valkey'], timeout: 180);
        $this->assertSame('ok', $result['status'], 'system:service:up valkey should succeed');
        $this->assertSame('valkey', $result['data']['service']);
        $this->assertArrayHasKey('port', $result['data']);
        $this->assertArrayHasKey('container', $result['data']);

        $containerName = $result['data']['container'];
        $this->containersToCleanup[] = $containerName;

        $process = new Process(['docker', 'inspect', '--format', '{{.State.Running}}', $containerName]);
        $process->run();
        $this->assertSame('true', trim($process->getOutput()), 'Valkey container should be running');
    }

    public function testServiceUpWithSpecificVersion(): void
    {
        $result = $this->runConsoleJson('system:service:up', ['valkey', '--service-version=8'], timeout: 180);
        $this->assertSame('ok', $result['status'], 'system:service:up valkey --service-version=8 should succeed');
        $this->assertSame('valkey', $result['data']['service']);
        $this->assertSame('8', $result['data']['version']);

        $containerName = $result['data']['container'];
        $this->containersToCleanup[] = $containerName;

        $process = new Process(['docker', 'inspect', '--format', '{{.Config.Image}}', $containerName]);
        $process->run();
        $this->assertStringContainsString('valkey/valkey:8', trim($process->getOutput()), 'Image should use valkey version 8');
    }

    public function testServiceUpMariadbWithVersion(): void
    {
        $result = $this->runConsoleJson('system:service:up', ['mariadb', '--service-version=11.4'], timeout: 180);
        $this->assertSame('ok', $result['status'], 'system:service:up mariadb --service-version=11.4 should succeed');
        $this->assertSame('mariadb', $result['data']['service']);
        $this->assertSame('11.4', $result['data']['version']);
        $this->assertArrayHasKey('port', $result['data']);

        $containerName = $result['data']['container'];
        $this->containersToCleanup[] = $containerName;

        $process = new Process(['docker', 'inspect', '--format', '{{.Config.Image}}', $containerName]);
        $process->run();
        $this->assertStringContainsString('mariadb:11.4', trim($process->getOutput()));
    }

    public function testServiceUpIsIdempotent(): void
    {
        $result1 = $this->runConsoleJson('system:service:up', ['valkey'], timeout: 180);
        $this->assertSame('ok', $result1['status']);
        $this->containersToCleanup[] = $result1['data']['container'];

        // Start again — should succeed (idempotent)
        $result2 = $this->runConsoleJson('system:service:up', ['valkey'], timeout: 180);
        $this->assertSame('ok', $result2['status']);

        // Same container
        $this->assertSame($result1['data']['container'], $result2['data']['container']);
    }

    protected function setUp(): void
    {
        $this->consolePath = dirname(__DIR__, 2).'/bin/console';
        $this->projectDir = sys_get_temp_dir();
        $this->tempDataDir = sys_get_temp_dir().'/dde-e2e-svc-'.bin2hex(random_bytes(8));
        (new Filesystem())->mkdir($this->tempDataDir);
        $this->containersToCleanup = [];

        // Ensure system network exists
        $this->runConsoleJson('system:up', timeout: 180);
    }

    protected function tearDown(): void
    {
        // Clean up containers created during this test
        foreach ($this->containersToCleanup as $container) {
            (new Process(['docker', 'rm', '-f', $container]))->run();
        }

        $this->runConsole('system:down', timeout: 60);
        (new Filesystem())->remove($this->tempDataDir);
    }
}
