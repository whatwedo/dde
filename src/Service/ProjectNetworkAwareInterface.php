<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Marker contract for global services that need to be attached to every
 * per-project network. `ProjectLifecycleManager` iterates `ServiceInterface`
 * implementations from the container tag and filters by `instanceof` against
 * this interface — services that do not implement it stay out of the
 * project-network plumbing entirely.
 *
 * Kept separate from `ServiceInterface` so future global services (status
 * dashboards, log forwarders) can be added without forcing them through
 * project-network reconciliation.
 */
interface ProjectNetworkAwareInterface extends ServiceInterface
{
    /**
     * DNS aliases this service exposes on the per-project network. Empty list
     * means "attach without an alias" (Traefik finds backends via labels). A
     * non-empty list maps to `docker network connect --alias` — Mailpit, for
     * instance, returns `['mail']` so existing applications keep reaching
     * `smtp://mail:1025`.
     *
     * @return list<string>
     */
    public function getProjectNetworkAliases(): array;

    /**
     * When true, `ProjectLifecycleManager::ensureProjectNetwork()` stops and
     * starts this service after attaching it to a fresh project network so
     * its in-process state (e.g. Traefik's docker provider cache) re-reads
     * the network list. Services that re-evaluate networks on every request
     * (Mailpit) don't need this.
     */
    public function requiresRestartAfterProjectNetworkAttach(): bool;
}
