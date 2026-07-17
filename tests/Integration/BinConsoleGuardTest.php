<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Subprocess tests for the pre-kernel root guard in bin/console.
 *
 * The reject case requires EUID 0, so it only runs where the suite itself runs
 * as root (e.g. the CI container); the unprivileged cases only run locally.
 * Between CI and a local run, all four paths are covered.
 */
final class BinConsoleGuardTest extends TestCase
{
    private const REJECT_MESSAGE = 'dde must not be run with sudo. It escalates internally where required.';

    public function testRootWithSudoUserIsRejected(): void
    {
        if (posix_geteuid() !== 0) {
            $this->markTestSkipped('Requires running the test suite as root (e.g. CI container).');
        }

        $process = $this->runConsole([
            'SUDO_USER' => 'someuser',
        ]);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString(self::REJECT_MESSAGE, $process->getErrorOutput());
    }

    public function testRootWithoutSudoUserPassesThrough(): void
    {
        if (posix_geteuid() !== 0) {
            $this->markTestSkipped('Requires running the test suite as root (e.g. CI container).');
        }

        $process = $this->runConsole([
            'SUDO_USER' => false,
        ]);

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
    }

    public function testUnprivilegedInvocationPassesThrough(): void
    {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('Requires an unprivileged user.');
        }

        $process = $this->runConsole([
            'SUDO_USER' => false,
        ]);

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
    }

    public function testUnprivilegedInvocationWithStaleSudoUserPassesThrough(): void
    {
        // SUDO_USER alone (without EUID 0) must not trigger the guard — it can
        // linger in shells spawned from an earlier sudo session.
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('Requires an unprivileged user.');
        }

        $process = $this->runConsole([
            'SUDO_USER' => 'someuser',
        ]);

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
    }

    /**
     * @param array<string, string|false> $env values of false remove the variable
     */
    private function runConsole(array $env): Process
    {
        $process = new Process(
            [PHP_BINARY, dirname(__DIR__, 2).'/bin/console', '--version'],
            dirname(__DIR__, 2),
            $env,
        );
        $process->setTimeout(60);
        $process->run();

        return $process;
    }
}
