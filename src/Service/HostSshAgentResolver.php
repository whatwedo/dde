<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\HostSshAgentResolution;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Single source of truth for "where is the host agent socket and is it usable"
 * in `host` agent mode, shared by {@see \App\Manager\DockerComposeManager} and
 * {@see \App\Doctor\Check\SshAgentCheck}.
 *
 * `source` is deliberately minimal — null (unset) or `env` use the host
 * `SSH_AUTH_SOCK`, anything else is an explicit socket path. No hardcoded
 * per-provider paths; they drift between versions, so password-manager users
 * point `source` at the socket (see docs/guides/ssh-agent.md).
 *
 * OS family and `SSH_AUTH_SOCK` are injectable so the platform matrix is
 * testable on a single host.
 */
final readonly class HostSshAgentResolver
{
    /**
     * Docker Desktop's bridge socket. It lives inside the VM and cannot be
     * stat-ed from the host, so macOS reports it available unverified — the
     * doctor check verifies it instead.
     */
    private const string MACOS_HOST_SERVICES_SOCKET = '/run/host-services/ssh-auth.sock';

    /**
     * `source` values that ride the macOS bridge instead of a direct mount.
     */
    private const array MACOS_BRIDGE_SOURCES = [null, 'env'];

    private string $osFamily;

    private ?string $authSock;

    private ?string $homeDir;

    public function __construct(
        private Filesystem $filesystem = new Filesystem(),
        ?string $osFamily = null,
        ?string $authSock = null,
        ?string $homeDir = null,
    ) {
        $this->osFamily = $osFamily ?? PHP_OS_FAMILY;
        $this->authSock = $authSock ?? $this->readEnv('SSH_AUTH_SOCK');
        $this->homeDir = $homeDir ?? $this->readEnv('HOME');
    }

    public function resolve(?string $source = null): HostSshAgentResolution
    {
        return match ($this->osFamily) {
            'Darwin' => $this->resolveMacOs($source),
            'Linux' => $this->resolveLinux($source),
            default => new HostSshAgentResolution(
                available: false,
                mountSource: null,
                reason: sprintf('Host SSH agent forwarding is not supported on %s.', $this->osFamily),
            ),
        };
    }

    /**
     * Whether a macOS `source` rides the bridge (true) or is a direct mount.
     */
    public function usesMacOsBridge(?string $source): bool
    {
        return in_array($source, self::MACOS_BRIDGE_SOURCES, true);
    }

    private function resolveMacOs(?string $source): HostSshAgentResolution
    {
        if ($this->usesMacOsBridge($source)) {
            return $this->resolveMacOsBridge();
        }

        // An explicit socket path cannot be forwarded on macOS: a bind-mounted
        // host socket does not cross the Docker Desktop / OrbStack VM boundary.
        // Report it unavailable so bring-up mounts nothing (and the doctor names
        // the fix) instead of silently generating a dead mount.
        return new HostSshAgentResolution(
            available: false,
            mountSource: null,
            reason: sprintf(
                'An explicit source ("%s") cannot be forwarded on macOS; use the bridge (leave source unset or set it to "env").',
                $source ?? '',
            ),
        );
    }

    private function resolveMacOsBridge(): HostSshAgentResolution
    {
        return new HostSshAgentResolution(
            available: true,
            mountSource: self::MACOS_HOST_SERVICES_SOCKET,
            reason: 'Forwarding via the Docker Desktop host-services bridge; the host '
                .'SSH_AUTH_SOCK must be visible to Docker Desktop (it runs under launchd and '
                .'does not inherit the shell environment).',
        );
    }

    private function resolveLinux(?string $source): HostSshAgentResolution
    {
        return match ($source) {
            null, 'env' => $this->resolveFromEnv(),
            default => $this->resolvePath($source),
        };
    }

    private function resolveFromEnv(): HostSshAgentResolution
    {
        if ($this->authSock === null || $this->authSock === '') {
            return new HostSshAgentResolution(
                available: false,
                mountSource: null,
                reason: 'SSH_AUTH_SOCK is not set in the host environment, so no host agent '
                    .'socket could be resolved.',
            );
        }

        return $this->resolvePath($this->authSock);
    }

    private function resolvePath(string $path): HostSshAgentResolution
    {
        $path = $this->expandHome($path);

        if ($path === '' || ! $this->filesystem->exists($path)) {
            return new HostSshAgentResolution(
                available: false,
                mountSource: null,
                reason: sprintf('The resolved host agent socket "%s" does not exist.', $path),
            );
        }

        // Must be an actual socket, not a stale regular file — otherwise bring-up
        // would bind-mount a non-socket and skip the unresolved-agent warning.
        // Resolve symlinks first (a symlinked socket reports as `link`); filetype()
        // warns on unreadable paths, so suppress and treat failures as unusable.
        if (@filetype(realpath($path) ?: $path) !== 'socket') {
            return new HostSshAgentResolution(
                available: false,
                mountSource: null,
                reason: sprintf('The resolved host agent path "%s" is not a socket.', $path),
            );
        }

        return new HostSshAgentResolution(
            available: true,
            mountSource: $path,
            reason: null,
        );
    }

    /**
     * Expand a leading `~/` against the host home directory, matching how
     * `ssh.keys` paths are expanded — a config `source: ~/.1password/agent.sock`
     * would otherwise be treated as a literal path and never resolve.
     */
    private function expandHome(string $path): string
    {
        if (! str_starts_with($path, '~/')) {
            return $path;
        }

        if ($this->homeDir === null || $this->homeDir === '') {
            return $path;
        }

        return rtrim($this->homeDir, '/').substr($path, 1);
    }

    private function readEnv(string $name): ?string
    {
        $value = getenv($name);

        return $value === false ? null : $value;
    }
}
