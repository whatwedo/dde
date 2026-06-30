<?php

declare(strict_types=1);

namespace Tests\E2E;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * E2E coverage for the system-install privilege-escalation contract.
 *
 * Spec: .kiro/specs/system-install-privilege-escalation
 *
 * Validated requirements:
 *  - R1.2: Linux/systemd-resolved happy-path without sudo prefix
 *  - R1.3: Linux/NetworkManager happy-path without sudo prefix
 *  - R1.4: non-interactive CI happy-path (passwordless sudo, no TTY)
 *  - R3.1: `sudo dde …` invocations are rejected pre-kernel
 *  - R4.1: files under $DDE_DATA_DIR are owned by the invoking user
 *  - R4.3: subsequent commands (e.g. `project:up`) can write under $DDE_DATA_DIR
 *
 * macOS `/etc/resolver/test` is handled by `DnsmasqService::configureDnsMacOs()` (bare
 * Filesystem, no escalation) and is out of scope for this escalation suite.
 *
 * Each test owns its setUp/tearDown: an isolated `DDE_CONFIG_DIR`/`DDE_DATA_DIR` tree
 * under `sys_get_temp_dir()`, plus best-effort cleanup of any `/etc/**` artefacts the
 * test may have produced. Cleanup uses `try/finally` and never fails the test.
 */
#[Group('e2e')]
final class SystemInstallTest extends TestCase
{
    use E2ETestHelper;

    private const REJECT_MESSAGE = 'dde must not be run with sudo. It escalates internally where required.';

    private const SYSTEMD_RESOLVED_CONF = '/etc/systemd/resolved.conf.d/dde-test.conf';

    private const SYSTEMD_RESOLVED_CONTENT = "[Resolve]\nDNS=127.0.0.1\nDomains=~test\n";

    private const NETWORK_MANAGER_CONF = '/etc/NetworkManager/dnsmasq.d/dde-test.conf';

    private const NETWORK_MANAGER_CONTENT = "server=/test/127.0.0.1\n";

    /**
     * @var list<string> resolver-config files we need to remove on tearDown
     */
    private array $createdResolverFiles = [];

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

    /**
     * R1.2 / R1.3 / R1.4: `dde system:install` runs to a successful DNS step on Linux
     * (systemd-resolved or NetworkManager) without a `sudo` prefix when passwordless
     * sudo is configured. The test asserts the resolver config file was written with
     * the exact content `DnsmasqService::configureDns*()` produces.
     *
     * Skipped on macOS (R1.1 is a manual release-time check) and when neither
     * systemd-resolved nor NetworkManager is active (e.g. inside the toolchain
     * container, where `system:install` cannot reach the live host).
     */
    public function testLinuxHappyPathConfiguresDns(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('R1.1 (macOS /etc/resolver) is verified manually before release.');
        }

        if (!$this->hasPasswordlessSudo()) {
            self::markTestSkipped('Linux happy-path requires passwordless sudo (`sudo -n true`).');
        }

        $resolver = $this->detectActiveResolver();
        if ($resolver === null) {
            self::markTestSkipped('Linux happy-path requires systemd-resolved or NetworkManager to be active.');
        }

        [$configFile, $expectedContent] = $resolver;
        $this->createdResolverFiles[] = $configFile;

        $process = $this->runConsole('system:install', timeout: 180);

        // The DNS step is what this test cares about; other steps (mkcert/Traefik/Docker)
        // may legitimately fail when their prerequisites are missing — we still assert
        // the resolver config was written, which proves the optimistic-then-sudo
        // escalation path executed end-to-end.
        $this->assertFileExists(
            $configFile,
            sprintf(
                "Expected resolver file %s to be created by system:install.\nSTDOUT: %s\nSTDERR: %s",
                $configFile,
                $process->getOutput(),
                $process->getErrorOutput(),
            ),
        );

        // Reading the file may itself need sudo if it was written root-owned.
        $actualContent = $this->readPossiblyRootOwned($configFile);
        $this->assertSame(
            $expectedContent,
            $actualContent,
            'Resolver config content does not match the byte-exact contract from DnsmasqService.',
        );
    }

    /**
     * R3.1: invoking dde with `SUDO_USER` set (i.e. via `sudo dde …`) must exit 1 and
     * print the reject message on stderr before any kernel boot.
     *
     * Reproducing this from a non-root user without a real `sudo` invocation is not
     * possible (the guard's predicate requires `posix_geteuid() === 0`). We exercise
     * the path by spawning the console under EUID 0 with `SUDO_USER` injected — the
     * same shape `sudo` would produce. When the test runs as a non-root user we skip,
     * documenting that the canonical coverage lives in the unit-tier
     * `tests/Unit/BinConsoleGuardTest.php` (which is unaffected by Docker availability).
     */
    public function testRejectsSudoInvocation(): void
    {
        if (!\function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped(
                'R3.1 reject path requires EUID 0 (run inside the toolchain container or as root). '
                .'Unit-tier coverage in tests/Unit/BinConsoleGuardTest.php applies regardless.',
            );
        }

        $consolePath = \dirname(__DIR__, 2).'/bin/console';
        $process = new Process([PHP_BINARY, $consolePath, 'list'], \dirname(__DIR__, 2), [
            'SUDO_USER' => 'alice',
        ]);
        $process->setTimeout(30.0);
        $process->run();

        self::assertSame(
            1,
            $process->getExitCode(),
            sprintf(
                "Expected exit 1 when EUID is 0 and SUDO_USER is set.\nSTDOUT: %s\nSTDERR: %s",
                $process->getOutput(),
                $process->getErrorOutput(),
            ),
        );
        self::assertStringContainsString(
            self::REJECT_MESSAGE,
            $process->getErrorOutput(),
            'Reject message wording must match the design verbatim.',
        );
        self::assertSame(
            '',
            $process->getOutput(),
            'Reject path must short-circuit before any framework output reaches stdout.',
        );
    }

    /**
     * R4.1: every file `system:install` writes under `$DDE_DATA_DIR` must be owned by
     * the invoking user (UID/GID), never by root. Even if downstream steps fail
     * (Docker / mkcert unavailable in this environment), `ensureConfig()` runs first
     * and is the canonical R4.1 producer. We assert ownership of its output file.
     *
     * Skipped on macOS where `posix_*` ownership semantics still apply but the spec
     * defers macOS host coverage to manual release verification.
     */
    public function testDataDirOwnershipAfterInstall(): void
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            self::markTestSkipped('macOS R4.1 verification is part of the manual release checklist.');
        }

        if (!\function_exists('posix_getuid') || !\function_exists('posix_getgid')) {
            self::markTestSkipped('R4.1 ownership check requires the posix extension.');
        }

        $this->runConsole('system:install', timeout: 180);

        $configFile = $this->tempDataDir.'/dnsmasq/dnsmasq.conf';
        $this->assertFileExists(
            $configFile,
            sprintf('Expected ensureConfig() to create %s as the first dnsmasq step.', $configFile),
        );

        clearstatcache(true, $configFile);
        $owner = fileowner($configFile);
        $group = filegroup($configFile);
        $expectedUid = posix_getuid();
        $expectedGid = posix_getgid();

        self::assertNotFalse($owner, 'fileowner() failed for the dnsmasq config.');
        self::assertNotFalse($group, 'filegroup() failed for the dnsmasq config.');

        self::assertSame(
            $expectedUid,
            $owner,
            sprintf(
                'R4.1: %s must be owned by the invoking user (UID %d), got UID %d. Privileged escalation must not poison $DDE_DATA_DIR.',
                $configFile,
                $expectedUid,
                $owner,
            ),
        );
        self::assertSame(
            $expectedGid,
            $group,
            sprintf(
                'R4.1: %s must be owned by the invoking group (GID %d), got GID %d.',
                $configFile,
                $expectedGid,
                $group,
            ),
        );
    }

    /**
     * R4.3: after `system:install` completes, a follow-up command (e.g. `project:up`)
     * running as the same unprivileged user must be able to write under `$DDE_DATA_DIR`
     * without a permission conflict. We simulate the follow-up by writing a touch-file
     * into `$DDE_DATA_DIR/dnsmasq/` as the current user. PR #102 Run 25138709404 is
     * the regression this guards.
     */
    public function testProjectUpFollowupWritability(): void
    {
        $this->runConsole('system:install', timeout: 180);

        $dnsmasqDir = $this->tempDataDir.'/dnsmasq';
        $this->assertDirectoryExists(
            $dnsmasqDir,
            'Expected ensureConfig() to create the dnsmasq directory under $DDE_DATA_DIR.',
        );

        $touchFile = $dnsmasqDir.'/.dde-e2e-followup-'.bin2hex(random_bytes(4));
        $written = @file_put_contents($touchFile, 'follow-up writability probe');

        try {
            self::assertNotFalse(
                $written,
                sprintf(
                    'R4.3: writing %s as the current user must succeed. A failure here means $DDE_DATA_DIR was poisoned with root-owned paths.',
                    $touchFile,
                ),
            );
        } finally {
            @unlink($touchFile);
        }
    }

    private function hasPasswordlessSudo(): bool
    {
        $process = new Process(['sudo', '-n', 'true']);
        $process->setTimeout(5.0);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * @return array{0: string, 1: string}|null tuple of (config-file-path, expected-content), or null when no resolver is active
     */
    private function detectActiveResolver(): ?array
    {
        if ($this->isSystemdActive('systemd-resolved')) {
            return [self::SYSTEMD_RESOLVED_CONF, self::SYSTEMD_RESOLVED_CONTENT];
        }

        if ($this->isSystemdActive('NetworkManager')) {
            return [self::NETWORK_MANAGER_CONF, self::NETWORK_MANAGER_CONTENT];
        }

        return null;
    }

    private function isSystemdActive(string $unit): bool
    {
        $process = new Process(['systemctl', 'is-active', $unit]);
        $process->setTimeout(5.0);
        $process->run();

        return $process->isSuccessful() && trim($process->getOutput()) === 'active';
    }

    private function readPossiblyRootOwned(string $path): string
    {
        $direct = @file_get_contents($path);
        if ($direct !== false) {
            return $direct;
        }

        $process = new Process(['sudo', '-n', 'cat', $path]);
        $process->setTimeout(5.0);
        $process->run();

        return $process->isSuccessful() ? $process->getOutput() : '';
    }

    private function bestEffortRemoveResolverFile(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        if (@unlink($path)) {
            return;
        }

        $process = new Process(['sudo', '-n', 'rm', '-f', $path]);
        $process->setTimeout(10.0);

        try {
            $process->run();
        } catch (\Throwable $throwable) {
            error_log(sprintf('SystemInstallTest: failed to remove %s: %s', $path, $throwable->getMessage()));
        }

        if (file_exists($path)) {
            error_log(sprintf('SystemInstallTest: %s still exists after cleanup attempt.', $path));
        }
    }

    protected function setUp(): void
    {
        $this->consolePath = \dirname(__DIR__, 2).'/bin/console';
        $this->projectDir = sys_get_temp_dir();
        $this->tempDataDir = sys_get_temp_dir().'/dde-e2e-install-'.bin2hex(random_bytes(8));
        (new Filesystem())->mkdir($this->tempDataDir);

        $this->runConsole('system:down', timeout: 60);
    }

    protected function tearDown(): void
    {
        try {
            $this->runConsole('system:down', timeout: 60);
        } catch (\Throwable $throwable) {
            error_log('SystemInstallTest tearDown system:down failed: '.$throwable->getMessage());
        }

        foreach ($this->createdResolverFiles as $file) {
            $this->bestEffortRemoveResolverFile($file);
        }

        try {
            (new Filesystem())->remove($this->tempDataDir);
        } catch (\Throwable $throwable) {
            error_log('SystemInstallTest tearDown tempDir cleanup failed: '.$throwable->getMessage());
        }
    }
}
