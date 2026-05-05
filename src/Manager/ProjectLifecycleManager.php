<?php

declare(strict_types=1);

namespace App\Manager;

use App\Config\ResolvedConfig;
use App\Config\WorktreeInfo;
use App\Parser\DockerComposeParser;
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
        private DockerComposeParser $composeParser = new DockerComposeParser(),
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

        // 4. Ensure TLS certificates for project domains
        $composeFile = $this->dockerComposeManager->findComposeFile($projectDir);
        $projectName = $config->projectName;
        $this->mkcertManager->ensureForComposeFile($projectName, $composeFile);

        // 4b. Ensure TLS certificates for worktree domains
        $worktreeHostname = null;

        if ($worktreeInfo instanceof WorktreeInfo) {
            $worktreeHostname = $this->worktreeManager->resolveHostname($projectName, $worktreeInfo);
            $suffix = IdentifierSanitizer::forHostname($worktreeInfo->suffix, $projectName);
            $this->mkcertManager->ensureForDomains($projectName.'-'.$suffix, [$worktreeHostname]);
        }

        // 5. Image layer check — build dev layer for project containers
        $devLayerResult = $this->imageManager->ensureDevLayers($config, $composeFile, $output);

        // 6. Pre-build compose images
        $this->dockerComposeManager->build($projectDir, [], $output);

        // 7. Pull missing images silently — avoids flooding the GUI with pull/extract spam
        if ($this->dockerComposeManager->needsPull($projectDir)) {
            $this->dockerComposeManager->pull($projectDir);
        }

        // 8. Generate override (inject worktree info + per-project network)
        $overrideFile = $this->dockerComposeManager->generateOverride($config, $projectDir, $worktreeInfo, $projectNetwork);

        // 9. Docker compose up, then query running services while the override
        //    file still exists — `finally` deletes it afterwards. Inside a
        //    worktree the hostname wins over compose labels anyway, so skip
        //    the extra `ps` round-trip and avoid introducing a new failure
        //    mode where a JSON-parse error in `ps` would tear down `up()`.
        $composeFiles = [$composeFile, $overrideFile];
        $runningServices = [];

        try {
            $this->dockerComposeManager->up($projectDir, [
                'composeFiles' => $composeFiles,
                'build' => $build,
            ], $output);

            if ($worktreeHostname === null) {
                $runningServices = $this->getRunningServiceNames($projectDir, $composeFiles);
            }
        } finally {
            $this->filesystem->remove($overrideFile);
        }

        $domains = $this->collectProjectDomains($composeFile, $worktreeHostname, $runningServices);

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

        // 3. Remove the now-empty per-project network
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
     * Collects the project's reachable domains.
     *
     * Mirrors the worktree-first policy of `project:open` (see commit d3d654c):
     * inside a worktree the compose file still declares the main project
     * hostname — only the generated override rewrites it — so we surface the
     * resolved worktree hostname. Outside a worktree we fall back to the
     * Traefik `Host()` labels in the compose file so user-customised domains
     * win.
     *
     * Only domains belonging to actually running services are returned so that
     * inactive profiles do not produce misleading URLs.
     *
     * @param list<string> $runningServices
     *
     * @return list<string>
     */
    private function collectProjectDomains(string $composeFile, ?string $worktreeHostname, array $runningServices): array
    {
        if ($worktreeHostname !== null) {
            return [$worktreeHostname];
        }

        return $this->composeParser->extractTraefikDomains($composeFile, $runningServices);
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

        if ($networkExisted) {
            $this->detachStaleServiceContainers($projectNetwork, $desiredContainers);
        }

        foreach ($desiredContainers as $serviceName => $containerName) {
            $this->dockerManager->connectContainerToNetwork($containerName, $projectNetwork, [$serviceName]);
        }
    }

    /**
     * Disconnects any `dde-<service>-*` container attached to the network whose
     * version does not match the configured one. Containers belonging to other
     * service types and non-dde containers (e.g. project app containers) are
     * left untouched.
     *
     * @param array<string, string> $desiredContainers service name => desired container name
     */
    private function detachStaleServiceContainers(string $projectNetwork, array $desiredContainers): void
    {
        $connected = $this->dockerManager->getConnectedContainerNames($projectNetwork);

        foreach ($desiredContainers as $serviceName => $desired) {
            $prefix = sprintf('dde-%s-', $serviceName);

            foreach ($connected as $name) {
                if ($name !== $desired && str_starts_with($name, $prefix)) {
                    $this->dockerManager->disconnectContainerFromNetwork($name, $projectNetwork);
                }
            }
        }
    }
}
