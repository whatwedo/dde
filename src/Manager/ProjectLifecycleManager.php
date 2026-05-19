<?php

declare(strict_types=1);

namespace App\Manager;

use App\Config\ResolvedConfig;
use App\Config\WorktreeInfo;
use App\Service\ServiceRegistry;
use App\Util\IdentifierSanitizer;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

readonly class ProjectLifecycleManager
{
    public function __construct(
        private DockerComposeManager $dockerComposeManager,
        private SystemServiceManager $systemServiceManager,
        private ImageManager $imageManager,
        private MkcertManager $mkcertManager,
        private ServiceRegistry $serviceRegistry,
        private DockerManager $dockerManager,
        private WorktreeManager $worktreeManager,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * @return array{serviceResults: list<array{name: string, version: string, status: string}>, devLayerResult: array{serviceName: string, imageTag: string}|null, domains: list<string>}
     */
    public function up(ResolvedConfig $config, string $projectDir, bool $build, ?OutputInterface $output = null): array
    {
        // 0. Ensure global system services are running (auto system:up)
        $this->ensureGlobalServices();

        // 1. Detect worktree up-front — the per-project network name depends on it.
        $worktreeInfo = $this->worktreeManager->detect($projectDir);

        // 2. Ensure declared services are running
        $serviceResults = $this->ensureServices($config);

        // 3. Ensure per-project network exists and connect services to it.
        //    Worktrees get their own network (`dde-services-<project>-<suffix>`) so
        //    they can bind the canonical service alias (e.g. `postgres`) to a
        //    different version than the main checkout without DNS collisions.
        $projectNetwork = $config->services !== []
            ? self::buildProjectNetworkName($config->projectName, $worktreeInfo)
            : null;
        $this->ensureProjectNetwork($config, $projectNetwork);

        // 4. Discover the user override up-front and snapshot the effective
        //    Compose service set (base + override merged) once. Every later
        //    step that reads service config — TLS, dev layer, domain output —
        //    works off this view, so a Traefik router declared only in
        //    `docker-compose.override.yml` is covered without re-implementing
        //    Compose's merge rules in PHP.
        $composeFile = $this->dockerComposeManager->findComposeFile($projectDir);
        $userOverride = $this->dockerComposeManager->findUserOverrideFile($projectDir, $composeFile);
        $mergedServices = $this->dockerComposeManager->getMergedServices($projectDir, $userOverride);
        $projectName = $config->projectName;

        $this->mkcertManager->ensureForDomains(
            $projectName,
            $this->dockerComposeManager->extractTraefikDomainsFromServices($mergedServices),
        );

        // 4b. Ensure TLS certificates for worktree domains
        $worktreeHostname = null;

        if ($worktreeInfo instanceof WorktreeInfo) {
            $worktreeHostname = $this->worktreeManager->resolveHostname($projectName, $worktreeInfo);
            $suffix = IdentifierSanitizer::forHostname($worktreeInfo->suffix, $projectName);

            $mainDomains = $this->dockerComposeManager->extractTraefikDomainsFromServices($mergedServices);
            $worktreeDomains = [];

            // Only include domains the rewrite actually changed. Compose
            // services that legitimately point at unrelated external hosts
            // (e.g. `Host(`partner.example.com`)`) are passed through
            // unchanged by `rewriteHostname()` — they must NOT end up in
            // the worktree-specific mkcert SAN list, otherwise we generate
            // a local trusted cert for hosts the project does not own.
            foreach ($mainDomains as $domain) {
                $rewritten = $this->worktreeManager->rewriteHostname($domain, $projectName, $worktreeInfo);

                if ($rewritten !== $domain) {
                    $worktreeDomains[] = $rewritten;
                }
            }

            // Always include the bare worktree hostname so the cert covers it even
            // when the compose file declares no Traefik labels at all (the override
            // generator falls back to generated labels in that case).
            $worktreeDomains[] = $worktreeHostname;
            $worktreeDomains = array_values(array_unique($worktreeDomains));

            $this->mkcertManager->ensureForDomains($projectName.'-'.$suffix, $worktreeDomains);
        }

        // 5. Image layer check — build dev layer for project containers.
        //    Reuse the merged service view from step 4 so a base file with
        //    valid Compose custom tags (`!override`, `!reset`) doesn't fail a
        //    second, narrower YAML parse here.
        $devLayerResult = $this->imageManager->ensureDevLayers($config, $mergedServices, $output);

        // 6. Pre-build compose images
        $this->dockerComposeManager->build($projectDir, [], $output);

        // 7. Pull missing images silently — avoids flooding the GUI with pull/extract spam
        if ($this->dockerComposeManager->needsPull($projectDir)) {
            $this->dockerComposeManager->pull($projectDir);
        }

        // 8. Generate the dde overlay from the merged service view discovered
        //    in step 4 so override-only services and override-modified fields
        //    (image, entrypoint, command, labels) get the same treatment as
        //    base services.
        $overrideFile = $this->dockerComposeManager->generateOverride($config, $projectDir, $worktreeInfo, $projectNetwork, $userOverride);

        // 9. Docker compose up, then query running services while the override
        //    file still exists — `finally` deletes it afterwards. A `ps`
        //    JSON-parse failure must not tear down `up()`, so fall back to
        //    `null` (no service filter — include every Traefik-declared
        //    host) when the lookup fails.
        $composeFiles = $this->buildComposeFileChain($composeFile, $userOverride, $overrideFile);
        $runningServices = null;

        try {
            $this->dockerComposeManager->up($projectDir, [
                'composeFiles' => $composeFiles,
                'build' => $build,
            ], $output);

            try {
                $runningServices = $this->getRunningServiceNames($projectDir, $composeFiles);
            } catch (\RuntimeException) {
                $runningServices = null;
            }
        } finally {
            $this->filesystem->remove($overrideFile);
        }

        $domains = $this->collectProjectDomains($mergedServices, $config->projectName, $worktreeInfo, $worktreeHostname, $runningServices);

        return [
            'serviceResults' => $serviceResults,
            'devLayerResult' => $devLayerResult,
            'domains' => $domains,
        ];
    }

    /**
     * Performs the full "down" sequence: compose down, then remove the per-project network.
     * Services are shared infrastructure managed by system:up/system:down and must not be stopped here.
     *
     * compose down must run first so project containers are removed and automatically disconnected
     * from the per-project network before we attempt to remove it.
     *
     * In a worktree, the per-project network is worktree-scoped
     * (`dde-services-<project>-<suffix>`), so teardown does not affect the main
     * checkout or any sibling worktree.
     */
    public function down(ResolvedConfig $config, string $projectDir, bool $removeOrphans = false): void
    {
        // 1. Compose down first — stops/removes project containers, which disconnects them
        //    from the per-project network automatically.
        $this->dockerComposeManager->down($projectDir, [
            'removeOrphans' => $removeOrphans,
        ]);

        $worktreeInfo = $this->worktreeManager->detect($projectDir);
        $projectNetwork = self::buildProjectNetworkName($config->projectName, $worktreeInfo);

        if (! $this->dockerManager->networkExists($projectNetwork)) {
            return;
        }

        // 2. Disconnect service containers from the per-project network
        foreach ($config->services as $service) {
            $version = $config->getServiceVersion($service->name);
            $containerName = ServiceRegistry::buildContainerName($service->name, $version);
            $this->dockerManager->disconnectContainerFromNetwork($containerName, $projectNetwork);
        }

        // 3. Disconnect global services attached in ensureProjectNetwork.
        foreach ($this->serviceRegistry->getGlobalServices() as $globalService) {
            if (! $globalService->attachesToProjectNetwork()) {
                continue;
            }

            $this->dockerManager->disconnectContainerFromNetwork(
                $globalService->getContainerName(),
                $projectNetwork,
            );
        }

        // 4. Remove the now-empty per-project network
        $this->dockerManager->removeNetwork($projectNetwork);
    }

    /**
     * Ensures all declared services are running.
     *
     * @return list<array{name: string, version: string, status: string}>
     */
    public function ensureServices(ResolvedConfig $config): array
    {
        $serviceResults = [];

        foreach ($config->services as $service) {
            $version = $config->getServiceVersion($service->name);

            $status = $this->systemServiceManager->startService($service->name, $version, $config->isDefaultVersion($service->name, $version));

            $serviceResults[] = [
                'name' => $service->name,
                'version' => $version,
                'status' => $status->value,
            ];
        }

        return $serviceResults;
    }

    public static function buildProjectNetworkName(string $projectName, ?WorktreeInfo $worktreeInfo = null): string
    {
        $base = 'dde-services-'.$projectName;

        if (! $worktreeInfo instanceof WorktreeInfo) {
            return $base;
        }

        $suffix = IdentifierSanitizer::forHostname($worktreeInfo->suffix, $projectName);

        return $base.'-'.$suffix;
    }

    /**
     * Ensures all global system services (Traefik, SSH-Agent, etc.) are running.
     * Automatically starts them if they are not — equivalent to system:up.
     */
    public function ensureGlobalServices(): void
    {
        foreach ($this->serviceRegistry->getGlobalServices() as $service) {
            $service->start();
        }
    }

    /**
     * Assembles the `docker compose -f <base> [-f <user override>] -f <dde overlay>`
     * argument chain in the order Compose applies overlays: base first,
     * user override in the middle (so it merges on top of base while still
     * losing to dde-controlled fields), dde runtime overlay last.
     *
     * @return list<string>
     */
    private function buildComposeFileChain(string $composeFile, ?string $userOverride, string $ddeOverride): array
    {
        return $userOverride !== null
            ? [$composeFile, $userOverride, $ddeOverride]
            : [$composeFile, $ddeOverride];
    }

    /**
     * Collects the project's reachable domains.
     *
     * Mirrors the worktree-first policy of `project:open` (see commit d3d654c):
     * the compose file always declares the *main* project hostnames — only the
     * generated override rewrites them — so inside a worktree we feed every
     * Traefik-declared host through `WorktreeManager::rewriteHostname` to
     * surface its worktree variant. Bare project host plus every subdomain
     * get listed, mirroring what the override file actually wires up.
     *
     * Outside a worktree the Traefik labels are returned as-is so
     * user-customised domains win.
     *
     * Only domains belonging to actually running services are returned so
     * that inactive profiles do not produce misleading URLs. `null` for
     * `$runningServices` means "include every declared Traefik host" — used
     * as the safety fallback when `docker compose ps` could not be parsed.
     *
     * @param array<string, array<string, mixed>> $mergedServices Compose-merged service set (base + user override)
     * @param list<string>|null                   $runningServices
     *
     * @return list<string>
     */
    private function collectProjectDomains(
        array $mergedServices,
        string $projectName,
        ?WorktreeInfo $worktreeInfo,
        ?string $worktreeHostname,
        ?array $runningServices,
    ): array {
        $domains = $this->dockerComposeManager->extractTraefikDomainsFromServices($mergedServices, $runningServices);

        if (! $worktreeInfo instanceof WorktreeInfo) {
            return $domains;
        }

        $rewritten = [];

        foreach ($domains as $domain) {
            $rewritten[] = $this->worktreeManager->rewriteHostname($domain, $projectName, $worktreeInfo);
        }

        // Compose file with no Traefik labels at all: the override generator
        // falls back to auto-generated labels for the bare worktree host, so
        // mirror that here.
        if ($rewritten === [] && $worktreeHostname !== null) {
            $rewritten[] = $worktreeHostname;
        }

        return array_values(array_unique($rewritten));
    }

    /**
     * Returns the service names of currently running containers for the project.
     *
     * `docker compose ps --format json` varies across versions: older releases
     * emit camel-case keys (`Service`), newer ones lower-case (`service`).
     * ProjectInfoManager / ProjectStatusCommand / ProjectShellCommand already
     * accept both spellings — match that convention so this method does not
     * silently return an empty list against a lowercase-key environment.
     *
     * @param list<string> $composeFiles
     *
     * @return list<string>
     */
    private function getRunningServiceNames(string $projectDir, array $composeFiles): array
    {
        $containers = $this->dockerComposeManager->ps($projectDir, [
            'composeFiles' => $composeFiles,
        ]);

        $services = [];

        foreach ($containers as $container) {
            $service = $container['Service'] ?? $container['service'] ?? null;

            if (is_string($service) && $service !== '') {
                $services[] = $service;
            }
        }

        return array_values(array_unique($services));
    }

    /**
     * Creates the per-project network if it does not exist, then connects all
     * configured service containers to it with their service name as alias.
     * Receives null when no services are configured, in which case it is a no-op.
     *
     * Before connecting, any previously attached `dde-<service>-*` container
     * whose version differs from the configured one is detached. Without this
     * step a project that switches a service version (e.g. mariadb 11.8 → 10.11)
     * would keep the old container connected under the canonical alias as
     * well, and Docker DNS would round-robin between both — randomly routing
     * traffic to the wrong database.
     */
    private function ensureProjectNetwork(ResolvedConfig $config, ?string $projectNetwork): void
    {
        if ($projectNetwork === null) {
            return;
        }

        $networkExisted = $this->dockerManager->networkExists($projectNetwork);

        if (! $networkExisted) {
            $this->dockerManager->createNetwork($projectNetwork);
        }

        $desiredContainers = [];

        foreach ($config->services as $service) {
            $version = $config->getServiceVersion($service->name);
            $desiredContainers[$service->name] = ServiceRegistry::buildContainerName($service->name, $version);
        }

        $connectedContainers = $networkExisted
            ? $this->dockerManager->getConnectedContainerNames($projectNetwork)
            : [];

        if ($networkExisted) {
            $this->detachStaleServiceContainers($projectNetwork, $desiredContainers, $connectedContainers);
        }

        foreach ($desiredContainers as $serviceName => $containerName) {
            $this->dockerManager->connectContainerToNetwork($containerName, $projectNetwork, [$serviceName]);
        }

        // Attach global services that need in-network reachability (Traefik
        // for routing, Mailpit for its `mail` alias). Opt in via
        // AbstractSystemService::attachesToProjectNetwork().
        foreach ($this->serviceRegistry->getGlobalServices() as $globalService) {
            if (! $globalService->attachesToProjectNetwork()) {
                continue;
            }

            $container = $globalService->getContainerName();
            $alreadyConnected = in_array($container, $connectedContainers, true);

            $this->dockerManager->connectContainerToNetwork($container, $projectNetwork, $globalService->getProjectNetworkAliases());

            // Some services (Traefik) cache their network list at process
            // startup; a runtime `docker network connect` is not picked up
            // until the container is restarted.
            if (! $alreadyConnected && $globalService->requiresRestartAfterProjectNetworkAttach()) {
                $this->dockerManager->stop($container);
                $this->dockerManager->start($container);
            }
        }
    }

    /**
     * Disconnects any `dde-<service>-*` container attached to the network whose
     * version does not match the configured one. Containers belonging to other
     * service types and non-dde containers (e.g. project app containers) are
     * left untouched.
     *
     * @param array<string, string> $desiredContainers service name => desired container name
     * @param list<string>           $connectedContainers network's currently attached containers
     */
    private function detachStaleServiceContainers(string $projectNetwork, array $desiredContainers, array $connectedContainers): void
    {
        foreach ($desiredContainers as $serviceName => $desired) {
            $prefix = sprintf('dde-%s-', $serviceName);

            foreach ($connectedContainers as $name) {
                if ($name !== $desired && str_starts_with($name, $prefix)) {
                    $this->dockerManager->disconnectContainerFromNetwork($name, $projectNetwork);
                }
            }
        }
    }
}
