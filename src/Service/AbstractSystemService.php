<?php

declare(strict_types=1);

namespace App\Service;

use App\Manager\DockerManager;
use App\Model\ContainerConfig;
use App\Model\ContainerInfo;
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
     * Whether this service should attach to every per-project network.
     * Default is opt-out. Overriders that return `true` are iterated by
     * `ProjectLifecycleManager::ensureProjectNetwork()` and re-attached on
     * `start()` via `reconcileProjectNetworkAttachments()`.
     */
    public function attachesToProjectNetwork(): bool
    {
        return false;
    }

    /**
     * DNS aliases this service exposes on the per-project network when
     * `attachesToProjectNetwork()` is true. Empty list means "attach without
     * an alias" (Traefik finds backends via labels). A non-empty list maps to
     * `docker network connect --alias` — Mailpit, for instance, returns
     * `['mail']` so existing applications keep reaching `smtp://mail:1025`.
     *
     * @return list<string>
     */
    public function getProjectNetworkAliases(): array
    {
        return [];
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

    /**
     * Re-attaches this service to every existing per-project network that still
     * has project containers connected. Called from `start()` on every
     * invocation; the dominant case is the cheap no-op path (no opt-in or no
     * networks to scan). Necessary because Docker does not preserve runtime
     * `network connect` attachments across a container re-create
     * (`system:update`, `system:down` + `system:up`) — without this, projects
     * that were running before the restart would become unreachable from
     * their dependencies until the next `project:up`.
     */
    private function reconcileProjectNetworkAttachments(): void
    {
        if (! $this->attachesToProjectNetwork()) {
            return;
        }

        $aliases = $this->getProjectNetworkAliases();
        $networks = $this->dockerManager->listNetworksWithPrefix('dde-services-');
        $container = $this->getContainerName();

        // Identify dde-managed containers so they don't count as "project
        // containers" on a stale network. Both global services and versioned
        // services tag themselves with `com.docker.compose.project=dde`;
        // user project containers carry their own Compose project name there
        // even when the directory happens to start with `dde-` (e.g.
        // `dde-shop-web-1`). A name-prefix check (`dde-…`) would misclassify
        // those.
        try {
            $ddeManagedContainers = $this->dockerManager->getContainersByLabel('com.docker.compose.project', 'dde');
        } catch (\RuntimeException) {
            $ddeManagedContainers = [];
        }

        $ddeManagedNames = array_flip(array_map(
            static fn (ContainerInfo $info): string => $info->name,
            $ddeManagedContainers,
        ));
        $attached = false;

        foreach ($networks as $network) {
            $connected = $this->dockerManager->getConnectedContainerNames($network);

            if (in_array($container, $connected, true)) {
                continue;
            }

            // Only project containers count: dde-managed containers (Traefik,
            // Mailpit, versioned `dde-mariadb-*` services …) leftover on a
            // stale network would otherwise reconcile each other indefinitely
            // and keep an empty network alive.
            $hasProjectContainers = false;

            foreach ($connected as $name) {
                if (! isset($ddeManagedNames[$name])) {
                    $hasProjectContainers = true;
                    break;
                }
            }

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
}
