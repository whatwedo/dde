<?php

declare(strict_types=1);

namespace Tests\E2E;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[Group('e2e')]
final class SystemInstallTest extends TestCase
{
    use E2ETestHelper;

    public function testSystemInstallSucceeds(): void
    {
        $process = $this->runConsole('system:install', timeout: 180);

        $this->assertTrue(
            $process->isSuccessful(),
            sprintf("system:install failed:\nSTDOUT: %s\nSTDERR: %s", $process->getOutput(), $process->getErrorOutput()),
        );
    }

    public function testSystemInstallIsIdempotent(): void
    {
        $first = $this->runConsole('system:install', timeout: 180);
        $this->assertTrue(
            $first->isSuccessful(),
            sprintf("First system:install failed:\nSTDOUT: %s\nSTDERR: %s", $first->getOutput(), $first->getErrorOutput()),
        );

        $second = $this->runConsole('system:install', timeout: 180);
        $this->assertTrue(
            $second->isSuccessful(),
            sprintf("Second system:install failed:\nSTDOUT: %s\nSTDERR: %s", $second->getOutput(), $second->getErrorOutput()),
        );
    }

    public function testSystemInstallConfiguresServicesVisibleInStatus(): void
    {
        $process = $this->runConsole('system:install', timeout: 180);
        $this->assertTrue(
            $process->isSuccessful(),
            sprintf("system:install failed:\nSTDOUT: %s\nSTDERR: %s", $process->getOutput(), $process->getErrorOutput()),
        );

        $result = $this->runConsoleJson('system:status');
        $this->assertSame('ok', $result['status']);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('services', $result['data']);
        $this->assertNotEmpty($result['data']['services'], 'Services should be non-empty after system:install');
    }

    public function testSudoDdeInvocationIsRejected(): void
    {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('Already root — the sudo signature cannot be reproduced.');
        }

        $probe = new Process(['sudo', '-n', 'true']);
        $probe->run();

        if (! $probe->isSuccessful()) {
            $this->markTestSkipped('Requires passwordless sudo.');
        }

        $process = new Process(['sudo', '-n', PHP_BINARY, $this->consolePath, '--version']);
        $process->setTimeout(60);
        $process->run();

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('dde must not be run with sudo', $process->getErrorOutput());
    }

    public function testSystemInstallKeepsDataDirOwnedByInvokingUser(): void
    {
        $process = $this->runConsole('system:install', timeout: 180);
        $this->assertTrue(
            $process->isSuccessful(),
            sprintf("system:install failed:\nSTDOUT: %s\nSTDERR: %s", $process->getOutput(), $process->getErrorOutput()),
        );

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempDataDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            $this->assertSame(
                posix_geteuid(),
                fileowner((string) $file),
                sprintf('"%s" must be owned by the invoking user, never root', (string) $file),
            );
        }
    }

    protected function setUp(): void
    {
        $this->consolePath = dirname(__DIR__, 2).'/bin/console';
        $this->projectDir = sys_get_temp_dir();
        $this->tempDataDir = sys_get_temp_dir().'/dde-e2e-install-'.bin2hex(random_bytes(8));
        (new Filesystem())->mkdir($this->tempDataDir);

        $this->runConsole('system:down', timeout: 60);
    }

    protected function tearDown(): void
    {
        $this->runConsole('system:down', timeout: 60);
        (new Filesystem())->remove($this->tempDataDir);
    }
}
