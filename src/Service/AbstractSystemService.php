<?php

declare(strict_types=1);

namespace App\Service;

use App\Manager\DockerManager;
use App\Model\ContainerConfig;
use App\Model\ServiceStatus;

abstract class AbstractSystemService implements ServiceInterface
{
    public function __construct(
        protected readonly DockerManager $dockerManager,
    ) {
    }

    abstract public function getName(): string;

    abstract public function getContainerName(): string;

    abstract public function getImageName(): string;

    abstract public function getContainerConfig(): ContainerConfig;

    public function start(): void
    {
        if (! $this->isRunning()) {
            $containerName = $this->getContainerName();

            if ($this->dockerManager->containerExists($containerName)) {
                $this->dockerManager->start($containerName);
            } else {
                $this->dockerManager->run($this->getContainerConfig());
            }
        }

        $this->reconcileProjectNetworkAttachments();
    }

    public function stop(): void
    {
        if (! $this->isRunning()) {
            return;
        }

        $this->dockerManager->stop($this->getContainerName());
    }

    public function remove(): void
    {
        $containerName = $this->getContainerName();

        if (! $this->dockerManager->containerExists($containerName)) {
            return;
        }

        if ($this->isRunning()) {
            $this->dockerManager->stop($containerName);
        }

        $this->dockerManager->remove($containerName);
    }

    public function build(bool $pull = false): void
    {
        // Hook for services with a local image build; upstream-image services inherit this no-op.
    }

    public function status(): ServiceStatus
    {
        if ($this->isRunning()) {
            return ServiceStatus::RUNNING;
        }

        return ServiceStatus::STOPPED;
    }

    public function isRunning(): bool
    {
        return $this->dockerManager->isContainerRunning($this->getContainerName());
    }

    /**
     * Network aliases this service should expose on every per-project network.
     * Return `null` to opt out of project-network attachment entirely (the
     * default). Return `[]` to attach without an alias (Traefik, which finds
     * backends via labels). Return a non-empty list to attach with aliases —
     * Mailpit, for instance, returns `['mail']` so existing applications can
     * keep reaching it as `smtp://mail:1025` from inside the project network.
     *
     * @return list<string>|null
     */
    public function getProjectNetworkAliases(): ?array
    {
        return null;
    }

    /**
     * When true, `ProjectLifecycleManager::ensureProjectNetwork()` stops and
     * starts this service after attaching it to a fresh project network so
     * its in-process state (e.g. Traefik's docker provider cache) re-reads
     * the network list. Services that re-evaluate networks on every request
     * (Mailpit) don't need this.
     */
    public function requiresRestartAfterProjectNetworkAttach(): bool
    {
        return false;
    }

    /**
     * Re-attaches this service to every existing per-project network that still
     * has project containers connected. Docker does not preserve runtime
     * `network connect` attachments across a container re-create (`system:update`,
     * `system:down` + `system:up`), so without this, projects that were running
     * before the restart would become unreachable from their dependencies.
     *
     * No-op for services that opt out via `getProjectNetworkAliases() === null`.
     */
    protected function reconcileProjectNetworkAttachments(): void
    {
        $aliases = $this->getProjectNetworkAliases();

        if ($aliases === null) {
            return;
        }

        $networks = $this->dockerManager->listNetworksWithPrefix('dde-services-');
        $container = $this->getContainerName();
        $attached = false;

        foreach ($networks as $network) {
            $connected = $this->dockerManager->getConnectedContainerNames($network);

            if (in_array($container, $connected, true)) {
                continue;
            }

            $hasProjectContainers = array_filter(
                $connected,
                static fn (string $name): bool => $name !== $container,
            ) !== [];

            if (! $hasProjectContainers) {
                continue;
            }

            $this->dockerManager->connectContainerToNetwork($container, $network, $aliases);
            $attached = true;
        }

        if ($attached && $this->requiresRestartAfterProjectNetworkAttach()) {
            $this->dockerManager->stop($container);
            $this->dockerManager->start($container);
        }
    }

    /**
     * @return array<string, string>
     */
    protected function getDefaultLabels(): array
    {
        return [
            'dde.managed' => 'true',
            'dde.service' => $this->getName(),
            'com.docker.compose.project' => 'dde',
        ];
    }
}
