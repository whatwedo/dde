<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Subprocess test for the pre-kernel root-guard in `bin/console`.
 *
 * The guard rejects `sudo dde …` invocations (EUID 0 with `SUDO_USER` set) before any
 * Symfony bootstrap runs, while allowing both real-root sessions (containers, provisioning)
 * and unprivileged users to pass through.
 *
 * Spec: requirements.md R3.1, R3.2, R3.3 + design.md "Root-Guard in bin/console".
 */
#[CoversNothing]
final class BinConsoleGuardTest extends TestCase
{
    private const REJECT_MESSAGE = 'dde must not be run with sudo. It escalates internally where required.';

    /**
     * R3.2: an effective-root session WITHOUT `SUDO_USER` (container / provisioning context)
     * must pass the guard and run the command normally.
     */
    public function testRealRootSessionWithoutSudoUserAllowed(): void
    {
        $process = $this->spawnConsole(['list'], envOverrides: [
            'SUDO_USER' => false,
        ]);
        $process->run();

        self::assertSame(
            0,
            $process->getExitCode(),
            'Expected exit 0 when SUDO_USER is unset (real-root or unprivileged), got stderr: '.$process->getErrorOutput(),
        );
        self::assertStringNotContainsString(self::REJECT_MESSAGE, $process->getErrorOutput());
    }

    /**
     * R3.1: EUID 0 + `SUDO_USER` set must trigger the guard (exit 1 + stderr message).
     *
     * The toolchain container runs as root by default, so setting `SUDO_USER=alice` reproduces
     * exactly what `sudo dde …` would look like to the guard.
     */
    public function testSudoUserSetWithRootEuidRejects(): void
    {
        if (!$this->isRoot()) {
            self::markTestSkipped('Requires EUID 0 to exercise the reject path; run inside the toolchain container.');
        }

        $process = $this->spawnConsole(['list'], envOverrides: [
            'SUDO_USER' => 'alice',
        ]);
        $process->run();

        self::assertSame(
            1,
            $process->getExitCode(),
            'Expected exit 1 when running as root with SUDO_USER set, got stdout: '.$process->getOutput(),
        );
        self::assertStringContainsString(self::REJECT_MESSAGE, $process->getErrorOutput());
    }

    /**
     * Verifies the exact reject wording (R3.1 acceptance bullet, design "Wording").
     */
    public function testRejectMessageContent(): void
    {
        if (!$this->isRoot()) {
            self::markTestSkipped('Requires EUID 0 to exercise the reject path.');
        }

        $process = $this->spawnConsole(['list'], envOverrides: [
            'SUDO_USER' => 'alice',
        ]);
        $process->run();

        // Verbatim wording from design.md > Components and Interfaces > bin/console Root-Guard.
        self::assertStringContainsString(
            'dde must not be run with sudo. It escalates internally where required.',
            $process->getErrorOutput(),
        );
    }

    /**
     * R3.1 ordering bullet: the guard must fire BEFORE the PHAR-detection block mutates
     * `$_SERVER`/`$_ENV`. We assert that the rejected run produces no Symfony Dotenv noise
     * and no stdout — only the bare reject line on stderr.
     */
    public function testGuardDoesNotMutateEnvBeforeReject(): void
    {
        if (!$this->isRoot()) {
            self::markTestSkipped('Requires EUID 0 to exercise the reject path.');
        }

        $process = $this->spawnConsole(['list'], envOverrides: [
            'SUDO_USER' => 'alice',
        ]);
        $process->run();

        self::assertSame(1, $process->getExitCode());
        self::assertSame('', $process->getOutput(), 'Reject path must not produce stdout (kernel must not boot).');

        $stderr = $process->getErrorOutput();
        self::assertStringContainsString(self::REJECT_MESSAGE, $stderr);
        // No Symfony / Dotenv warnings should leak — the guard precedes any framework code.
        self::assertStringNotContainsString('Dotenv', $stderr);
        self::assertStringNotContainsString('LogicException', $stderr);
        self::assertStringNotContainsString('Symfony\\', $stderr);
    }

    /**
     * R3.3: an unprivileged user (EUID != 0) must always pass the guard, even with
     * `SUDO_USER` set (which can never legitimately occur, but the predicate must short-
     * circuit on EUID anyway). When the test runs as non-root we exercise the live path;
     * when it runs as root we skip — R3.3 then has no live coverage and the predicate's
     * `posix_geteuid() === 0` short-circuit is evident from inspection of `bin/console`.
     */
    public function testUnprivilegedUserCanRunCommand(): void
    {
        if ($this->isRoot()) {
            self::markTestSkipped('R3.3 requires a non-root EUID; running as root.');
        }

        $process = $this->spawnConsole(['list'], envOverrides: [
            'SUDO_USER' => 'alice',
        ]);
        $process->run();

        self::assertSame(
            0,
            $process->getExitCode(),
            'Unprivileged user must pass the guard regardless of SUDO_USER, got stderr: '.$process->getErrorOutput(),
        );
        self::assertStringNotContainsString(self::REJECT_MESSAGE, $process->getErrorOutput());
    }

    private function isRoot(): bool
    {
        return function_exists('posix_geteuid') && posix_geteuid() === 0;
    }

    /**
     * @param list<string>           $args         arguments passed to bin/console
     * @param array<string, string|false> $envOverrides env values to set, or `false` to unset
     */
    private function spawnConsole(array $args, array $envOverrides = []): Process
    {
        $projectRoot = dirname(__DIR__, 2);
        $command = [PHP_BINARY, $projectRoot.'/bin/console', ...$args];

        $env = [];
        // Inherit a minimal sane env (PATH/HOME) and let Process inherit the rest by default.
        // We pass overrides on top; `false` means "explicitly unset".
        foreach ($envOverrides as $key => $value) {
            $env[$key] = $value;
        }

        $process = new Process($command, $projectRoot, $env);
        $process->setTimeout(30.0);

        return $process;
    }
}
