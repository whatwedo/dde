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

        // 9. Docker compose up
        $composeFiles = [$composeFile, $overrideFile];

        try {
            $this->dockerComposeManager->up($projectDir, [
                'composeFiles' => $composeFiles,
                'build' => $build,
            ], $output);
        } finally {
            $this->filesystem->remove($overrideFile);
        }

        $domains = $this->collectProjectDomains($composeFile, $worktreeHostname);

        return [
            'serviceResults' => $serviceResults,
            'devLayerResult' => $devLayerResult,
            'domains' => $domains,
        ];
    }

    /**
     * Performs the full "down" sequence: compose down, then remove per-project network.
     * Services are shared infrastructure managed by system:up/system:down and must not be stopped here.
     *
     * compose down must run first so project containers are removed and automatically disconnected
     * from the per-project network before we attempt to remove it.
     */
    public function down(ResolvedConfig $config, string $projectDir, bool $removeOrphans = false): void
    {
        // 1. Compose down first — stops/removes project containers, which disconnects them
        //    from the per-project network automatically.
        $this->dockerComposeManager->down($projectDir, [
            'removeOrphans' => $removeOrphans,
        ]);

        $projectNetwork = self::buildProjectNetworkName($config->projectName);

        if (! $this->dockerManager->networkExists($projectNetwork)) {
            return;
        }

        // Main and worktree share the same project network (same project name).
        // If the compose-down above left containers from another project attached,
        // disconnecting our service containers would strip their network aliases
        // (e.g. `mariadb`) and break the still-running project. In that case we
        // leave both the service connections and the network alone — the last
        // project to go down cleans up.
        if ($this->hasForeignContainersOnNetwork($config, $projectNetwork)) {
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
     * @return list<string>
     */
    private function collectProjectDomains(string $composeFile, ?string $worktreeHostname): array
    {
        if ($worktreeHostname !== null) {
            return [$worktreeHostname];
        }

        return $this->composeParser->extractTraefikDomains($composeFile);
    }

    private function hasForeignContainersOnNetwork(ResolvedConfig $config, string $network): bool
    {
        $attached = $this->dockerManager->getConnectedContainerNames($network);
        $serviceContainers = [];

        foreach ($config->services as $service) {
            $version = $config->getServiceVersion($service->name);
            $serviceContainers[] = ServiceRegistry::buildContainerName($service->name, $version);
        }

        return array_diff($attached, $serviceContainers) !== [];
    }

    /**
     * Creates the per-project network if it does not exist, then connects all
     * configured service containers to it with their service name as alias.
     * Receives null when no services are configured, in which case it is a no-op.
     */
    private function ensureProjectNetwork(ResolvedConfig $config, ?string $projectNetwork): void
    {
        if ($projectNetwork === null) {
            return;
        }

        if (! $this->dockerManager->networkExists($projectNetwork)) {
            $this->dockerManager->createNetwork($projectNetwork);
        }

        foreach ($config->services as $service) {
            $version = $config->getServiceVersion($service->name);
            $containerName = ServiceRegistry::buildContainerName($service->name, $version);
            $this->dockerManager->connectContainerToNetwork($containerName, $projectNetwork, [$service->name]);
        }
    }
}
