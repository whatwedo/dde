<?php

declare(strict_types=1);

namespace App\Doctor\Check;

use App\Config\SshAgentMode;
use App\Doctor\CheckInterface;
use App\Doctor\CheckResult;
use App\Doctor\CheckStatus;
use App\Manager\DockerManager;
use App\Manager\GlobalConfigManager;
use App\Service\HostSshAgentResolver;

/**
 * Verifies the SSH-agent prerequisite per mode so misconfiguration surfaces here
 * rather than as an opaque in-container SSH failure later:
 *
 * - `managed`: the `dde-ssh-agent` container must be running.
 * - `host` + macOS bridge: the bridge socket lives in the Docker Desktop VM and
 *   can't be stat-ed, so the only host-visible signal is whether `SSH_AUTH_SOCK`
 *   is set — Docker Desktop runs under launchd and doesn't inherit the shell
 *   environment, the common silent failure.
 * - `host` + Linux: verifies the resolved path is a live socket.
 * - `host` + macOS + explicit path: unsupported (the bridge is the only macOS
 *   route), so it is reported as an error rather than probed.
 *
 * OS family and `SSH_AUTH_SOCK` are injectable so the matrix is testable on one
 * host.
 */
final class SshAgentCheck implements CheckInterface
{
    private readonly string $osFamily;

    private readonly ?string $authSock;

    public function __construct(
        private readonly DockerManager $dockerManager,
        private readonly GlobalConfigManager $globalConfigManager,
        private readonly HostSshAgentResolver $hostSshAgentResolver,
        ?string $osFamily = null,
        ?string $authSock = null,
    ) {
        $this->osFamily = $osFamily ?? PHP_OS_FAMILY;
        $this->authSock = $authSock ?? $this->readEnv('SSH_AUTH_SOCK');
    }

    public function getName(): string
    {
        return 'SSH Agent';
    }

    public function run(): CheckResult
    {
        return match ($this->globalConfigManager->load()->sshAgentMode) {
            SshAgentMode::Managed => $this->checkManaged(),
            SshAgentMode::Host => $this->checkHost(),
        };
    }

    public function getPriority(): int
    {
        return 0;
    }

    public function requiresDocker(): bool
    {
        // Only the managed branch inspects a container; host branches probe a
        // socket and must run even with the daemon down.
        return $this->globalConfigManager->load()->sshAgentMode === SshAgentMode::Managed;
    }

    private function checkManaged(): CheckResult
    {
        if ($this->dockerManager->isContainerRunning('dde-ssh-agent')) {
            return new CheckResult(
                name: $this->getName(),
                status: CheckStatus::OK,
                message: 'SSH agent container is running',
            );
        }

        return new CheckResult(
            name: $this->getName(),
            status: CheckStatus::ERROR,
            message: 'SSH agent container is not running.',
            fixHint: 'Run: dde system:up',
        );
    }

    private function checkHost(): CheckResult
    {
        return match ($this->osFamily) {
            'Darwin' => $this->checkHostMacOs(),
            'Linux' => $this->checkHostLinux(),
            default => new CheckResult(
                name: $this->getName(),
                status: CheckStatus::ERROR,
                message: sprintf('Host SSH agent forwarding is not supported on %s.', $this->osFamily),
                fixHint: 'Use the managed agent mode (ssh.agent.mode: managed) on this platform.',
            ),
        };
    }

    private function checkHostMacOs(): CheckResult
    {
        // macOS forwarding always rides the Docker Desktop / OrbStack bridge; a
        // directly bind-mounted host socket cannot cross the VM boundary. Reject
        // an explicit source outright — otherwise the socket's mere presence on
        // the host reports green while forwarding fails with "Connection refused"
        // inside the container.
        $source = $this->globalConfigManager->load()->sshAgentSource;
        if (! $this->hostSshAgentResolver->usesMacOsBridge($source)) {
            return new CheckResult(
                name: $this->getName(),
                status: CheckStatus::ERROR,
                message: sprintf(
                    'Host SSH agent forwarding is enabled with an explicit source "%s", but explicit '
                    .'socket paths are not supported on macOS: a bind-mounted host socket cannot cross '
                    .'the Docker Desktop / OrbStack VM boundary, so forwarding fails inside the container.',
                    $source ?? '',
                ),
                fixHint: 'On macOS use the bridge: set ssh.agent.source to env (or leave it unset). '
                    .'See docs/guides/ssh-agent.md.',
            );
        }

        // dde can only observe its own SSH_AUTH_SOCK, not what the Docker Desktop
        // runtime (launchd) sees. Unset here means dde has no agent to forward at
        // all; a set value is necessary but not sufficient (launchd may still not
        // see it — the macOS caveat), so this stays best-effort.
        if ($this->authSock === null || $this->authSock === '') {
            return new CheckResult(
                name: $this->getName(),
                status: CheckStatus::ERROR,
                message: 'Host SSH agent forwarding is enabled, but SSH_AUTH_SOCK is not set in '
                    ."dde's environment, so there is no host agent to forward.",
                fixHint: 'Start your SSH agent (e.g. 1Password, Bitwarden, or `ssh-agent`) and ensure '
                    .'SSH_AUTH_SOCK points at its socket. On macOS also make sure the Docker Desktop '
                    .'runtime sees it — it runs under launchd and does not inherit your shell '
                    .'environment; check `launchctl getenv SSH_AUTH_SOCK` and, if empty, run '
                    .'`launchctl setenv SSH_AUTH_SOCK "$SSH_AUTH_SOCK"`, then restart Docker Desktop.',
            );
        }

        return new CheckResult(
            name: $this->getName(),
            status: CheckStatus::OK,
            message: 'Host SSH agent forwarding is enabled; SSH_AUTH_SOCK is set and forwarded '
                .'through the Docker Desktop host-services bridge.',
        );
    }

    /**
     * Surface the resolver's verdict for a directly bind-mounted host agent
     * socket (Linux); the resolver validates existence and socket-ness.
     */
    private function checkHostLinux(): CheckResult
    {
        $resolution = $this->hostSshAgentResolver->resolve($this->globalConfigManager->load()->sshAgentSource);

        if (! $resolution->available || $resolution->mountSource === null) {
            return new CheckResult(
                name: $this->getName(),
                status: CheckStatus::ERROR,
                message: 'Host SSH agent forwarding is enabled, but no usable host agent socket '
                    .'could be resolved'.($resolution->reason !== null ? ': '.$resolution->reason : '.'),
                fixHint: 'Start your SSH agent (e.g. 1Password, Bitwarden, or `ssh-agent`) and '
                    .'ensure SSH_AUTH_SOCK points at its socket.',
            );
        }

        return new CheckResult(
            name: $this->getName(),
            status: CheckStatus::OK,
            message: sprintf(
                'Host SSH agent forwarding is enabled; the host agent socket "%s" is present.',
                $resolution->mountSource,
            ),
        );
    }

    private function readEnv(string $name): ?string
    {
        $value = getenv($name);

        return $value === false ? null : $value;
    }
}
