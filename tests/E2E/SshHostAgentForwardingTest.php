<?php

declare(strict_types=1);

namespace Tests\E2E;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * End-to-end coverage for the `host` SSH-agent mode (task 4.1).
 *
 * These tests spawn the real `bin/console` CLI against a real Docker daemon, so
 * they are tagged `#[Group('e2e')]` and excluded from the CI run
 * (`make test` / `phpunit --exclude-group=e2e`). They are run by hand on a real
 * host — see the "Pre-rollout gates (manual)" section of
 * `.kiro/specs/ssh-host-agent/tasks.md`. In particular:
 *
 *   - {@see testHostModeWithWorkingAgentForwardsIdentities} additionally
 *     requires a real host SSH agent (a live `SSH_AUTH_SOCK` listing at least
 *     one identity). On a host without one it `markTestSkipped`s rather than
 *     failing spuriously — the empirical "1Password agent reachable from inside
 *     a container" gate is a manual rollout check, not an automated assertion.
 *
 *   - {@see testHostModeWithoutAgentBringsUpWithWarningAndNoForwarding} exercises
 *     the warn-and-continue path, which is Linux-effective: on macOS the resolver
 *     reports the Docker Desktop host-services bridge as available without
 *     host-side verification (the source lives inside the VM), so the bring-up
 *     warning never fires and pre-flight detection is delegated to the doctor
 *     check (R4.4). The test therefore `markTestSkipped`s on macOS.
 *
 * Requirements: 2.1, 4.1, 4.2, 4.3.
 */
#[Group('e2e')]
final class SshHostAgentForwardingTest extends TestCase
{
    use E2ETestHelper;

    /**
     * The constant in-container socket contract, identical across both agent
     * modes (R2.4) — a project container cannot observe which mode is active.
     */
    private const string CONTAINER_SOCKET_PATH = '/tmp/ssh-agent/socket';

    /**
     * Scenario 1 — host mode + a working host agent → the container sees the
     * forwarded identities.
     *
     * Needs a real host SSH agent at run time, so it skips cleanly when none is
     * available rather than failing on machines without one.
     *
     * Requirements: 2.1.
     */
    public function testHostModeWithWorkingAgentForwardsIdentities(): void
    {
        $hostAuthSock = getenv('SSH_AUTH_SOCK');

        if ($hostAuthSock === false || $hostAuthSock === '' || ! file_exists($hostAuthSock)) {
            $this->markTestSkipped('No host SSH agent (SSH_AUTH_SOCK) available — host-mode forwarding cannot be exercised.');
        }

        // The host agent must actually carry at least one identity, otherwise
        // `ssh-add -l` inside the container would be indistinguishable from a
        // missing agent. A host with an empty agent is not a valid fixture for
        // this scenario.
        $hostList = $this->runHostSshAdd($hostAuthSock);

        if (! $hostList->isSuccessful()) {
            $this->markTestSkipped('Host SSH agent holds no identities (ssh-add -l on the host failed) — cannot assert forwarding.');
        }

        $this->writeGlobalSshAgentMode('host');
        $this->bootProject(name: 'e2e-ssh-host', services: '');

        // The forwarding socket is bind-mounted at the constant container path;
        // SSH_AUTH_SOCK must point at it (R2.1).
        $envProcess = $this->runConsole('project:exec', ['--service=web', '--', 'printenv', 'SSH_AUTH_SOCK']);
        $this->assertTrue(
            $envProcess->isSuccessful(),
            sprintf("printenv SSH_AUTH_SOCK in container failed:\nSTDOUT: %s\nSTDERR: %s", $envProcess->getOutput(), $envProcess->getErrorOutput()),
        );
        $this->assertSame(self::CONTAINER_SOCKET_PATH, trim($envProcess->getOutput()), 'Container SSH_AUTH_SOCK must point at the constant forwarding socket path');

        // The container can reach the forwarded agent and list identities.
        $listProcess = $this->runConsole('project:exec', ['--service=web', '--', 'ssh-add', '-l']);
        $this->assertTrue(
            $listProcess->isSuccessful(),
            sprintf(
                "ssh-add -l inside the container failed — the host agent was not forwarded:\nSTDOUT: %s\nSTDERR: %s",
                $listProcess->getOutput(),
                $listProcess->getErrorOutput(),
            ),
        );
        $this->assertStringNotContainsString(
            'The agent has no identities',
            $listProcess->getOutput(),
            'The forwarded agent should expose the host identities inside the container',
        );

        // The container runs `project:exec` as the unprivileged dde user, not
        // root. On Docker Desktop the bind-mounted socket lands as root:root, so
        // without the entrypoint chowning it to the dde user this would fail
        // with "Permission denied". Assert that specific regression explicitly.
        $combined = $listProcess->getOutput().$listProcess->getErrorOutput();
        $this->assertStringNotContainsString(
            'Permission denied',
            $combined,
            'The dde user must be able to read the forwarded socket — the entrypoint chowns it (Docker Desktop mounts it root-owned)',
        );
    }

    /**
     * Scenario 2 — host mode + no resolvable host agent → bring-up succeeds, a
     * specific warning is emitted, and the container has no SSH forwarding.
     *
     * Linux-effective: on macOS the resolver always reports available, so this
     * path cannot be exercised (R4.4 delegates macOS pre-flight to the doctor).
     *
     * Requirements: 4.1, 4.2, 4.3.
     */
    public function testHostModeWithoutAgentBringsUpWithWarningAndNoForwarding(): void
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            $this->markTestSkipped('On macOS the host-services bridge is reported available without host-side verification; the warn-and-continue path is Linux-effective (R4.4).');
        }

        // Point the host agent source at a path that is guaranteed not to exist,
        // so resolution is deterministically unavailable regardless of any real
        // agent on the host running the suite.
        $missingSocket = sys_get_temp_dir().'/dde-e2e-missing-agent-'.bin2hex(random_bytes(8)).'.sock';
        $this->writeGlobalSshAgentMode('host', $missingSocket);

        $this->initE2EProject();
        $this->runConsole('system:down', timeout: 60);
        $this->cleanupLeftoverContainers();

        $initResult = $this->runConsoleJson('project:init', [
            '--name=e2e-ssh-host-noagent',
            '--services=',
            '--shell=bash',
            '--force',
        ]);
        $this->assertSame('ok', $initResult['status'], 'project:init should succeed');

        $systemResult = $this->runConsoleJson('system:up', timeout: 180);
        $this->assertSame('ok', $systemResult['status'], 'system:up should succeed');

        // Run project:up in text mode so the human-facing warning surfaces in
        // the command output (the warning is written via OutputInterface).
        $upProcess = $this->runConsole('project:up', timeout: 180);

        // R4.1 — bring-up succeeds despite the missing host agent.
        $this->assertTrue(
            $upProcess->isSuccessful(),
            sprintf(
                "project:up should succeed even when the host agent is unresolvable (R4.1):\nSTDOUT: %s\nSTDERR: %s",
                $upProcess->getOutput(),
                $upProcess->getErrorOutput(),
            ),
        );

        // R4.3 — a specific warning tells the developer SSH forwarding is off.
        $combinedOutput = $upProcess->getOutput().$upProcess->getErrorOutput();
        $this->assertStringContainsString(
            'SSH agent forwarding is disabled',
            $combinedOutput,
            'A specific warning must name that SSH forwarding is disabled (R4.3)',
        );

        // R4.2 — the container starts without SSH forwarding: SSH_AUTH_SOCK is
        // not injected at all (printenv exits non-zero for an unset variable).
        $envProcess = $this->runConsole('project:exec', ['--service=web', '--', 'printenv', 'SSH_AUTH_SOCK']);
        $this->assertFalse(
            $envProcess->isSuccessful(),
            sprintf('SSH_AUTH_SOCK must not be set in the container when forwarding is unavailable (R4.2); got: %s', $envProcess->getOutput()),
        );
        $this->assertSame('', trim($envProcess->getOutput()), 'No SSH_AUTH_SOCK value should be forwarded into the container (R4.2)');
    }

    /**
     * Writes a minimal global `~/.dde/config.yml` (inside the isolated
     * DDE_CONFIG_DIR the E2E helper points the CLI at) selecting the SSH agent
     * mode and, optionally, an explicit agent source path.
     */
    private function writeGlobalSshAgentMode(string $mode, ?string $source = null): void
    {
        $lines = [
            'ssh:',
            '  agent:',
            '    mode: '.$mode,
        ];

        if ($source !== null) {
            $lines[] = '    source: '.$source;
        }

        $configDir = $this->tempDataDir.'/config';
        (new Filesystem())->dumpFile($configDir.'/config.yml', implode("\n", $lines)."\n");
    }

    /**
     * Runs `ssh-add -l` directly on the host (not in a container) against the
     * host agent, to confirm the fixture host carries at least one identity.
     */
    private function runHostSshAdd(string $authSock): Process
    {
        $process = new Process(['ssh-add', '-l']);
        $process->setEnv([
            'SSH_AUTH_SOCK' => $authSock,
        ]);
        $process->setTimeout(10);
        $process->run();

        return $process;
    }

    protected function setUp(): void
    {
        $this->initE2EProject();

        $this->runConsole('system:down', timeout: 60);
        $this->cleanupLeftoverContainers();
    }

    protected function tearDown(): void
    {
        $this->tearDownE2EProject();
    }
}
