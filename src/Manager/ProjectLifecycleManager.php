<?php

declare(strict_types=1);

namespace App\Manager;

use App\Config\ResolvedConfig;
use App\Config\WorktreeInfo;
use App\Service\ServiceRegistry;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

readonly class ProjectLifecycleManager
{
    public function __construct(
        private ConfigManager $configManager,
        private DockerComposeManager $dockerComposeManager,
        private SystemServiceManager $systemServiceManager,
        private ImageManager $imageManager,
        private MkcertManager $mkcertManager,
        private ServiceRegistry $serviceRegistry,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * Performs the full "up" sequence: ensure services, build dev layers,
     * compose build, generate override, compose up, cleanup override.
     *
     * @return array{serviceResults: list<array{name: string, version: string, status: string}>, devLayerResult: array{serviceName: string, imageTag: string}|null}
     */
    public function up(ResolvedConfig $config, string $projectDir, bool $build, ?OutputInterface $output = null): array
    {
        // 0. Ensure global system services are running (auto system:up)
        $this->ensureGlobalServices();

        // 1. Ensure declared services are running
        $serviceResults = $this->ensureServices($config);

        // 2. Ensure TLS certificates for project domains
        $composeFile = $this->dockerComposeManager->findComposeFile($projectDir);
        $projectName = $config->projectName;
        $this->mkcertManager->ensureForComposeFile($projectName, $composeFile);

        // 2b. Ensure TLS certificates for worktree domains
        $worktreeInfo = $this->configManager->detectWorktree($projectDir);

        if ($worktreeInfo instanceof WorktreeInfo) {
            $worktreeHostname = $this->configManager->resolveProjectHostname($projectName, $worktreeInfo);
            $suffix = $this->configManager->sanitizeWorktreeSuffix($worktreeInfo->suffix, $projectName);
            $this->mkcertManager->ensureForDomains($projectName.'-'.$suffix, [$worktreeHostname]);
        }

        // 3. Image layer check — build dev layer for project containers
        $devLayerResult = $this->imageManager->ensureDevLayers($config, $composeFile, $output);

        // 4. Pre-build compose images
        $this->dockerComposeManager->build($projectDir, [], $output);

        // 5. Pull missing images silently — avoids flooding the GUI with pull/extract spam
        if ($this->dockerComposeManager->needsPull($projectDir)) {
            $this->dockerComposeManager->pull($projectDir);
        }

        // 6. Generate override (pass worktreeInfo to avoid duplicate detection)
        $overrideFile = $this->dockerComposeManager->generateOverride($config, $projectDir, $worktreeInfo);

        // 7. Docker compose up
        $composeFiles = [$composeFile, $overrideFile];

        try {
            $this->dockerComposeManager->up($projectDir, [
                'composeFiles' => $composeFiles,
                'build' => $build,
            ], $output);
        } finally {
            $this->filesystem->remove($overrideFile);
        }

        return [
            'serviceResults' => $serviceResults,
            'devLayerResult' => $devLayerResult,
        ];
    }

    /**
     * Performs the full "down" sequence: compose down only.
     * Services are shared infrastructure managed by system:up/system:down and must not be stopped here.
     */
    public function down(ResolvedConfig $config, string $projectDir, bool $removeOrphans = false): void
    {
        $this->dockerComposeManager->down($projectDir, [
            'removeOrphans' => $removeOrphans,
        ]);
    }

    /**
     * Performs a full restart: down then up.
     *
     * @return array{serviceResults: list<array{name: string, version: string, status: string}>, devLayerResult: array{serviceName: string, imageTag: string}|null}
     */
    public function restart(ResolvedConfig $config, string $projectDir, bool $build = false, ?OutputInterface $output = null): array
    {
        $this->down($config, $projectDir);

        return $this->up($config, $projectDir, $build, output: $output);
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
            $isDefault = $config->isDefaultVersion($service->name, $version);

            $status = $this->systemServiceManager->startService($service->name, $version, $isDefault);

            $serviceResults[] = [
                'name' => $service->name,
                'version' => $version,
                'status' => $status->value,
            ];
        }

        return $serviceResults;
    }

    /**
     * Ensures all global system services (Traefik, SSH-Agent, etc.) are running.
     * Automatically starts them if they are not — equivalent to system:up.
     */
    private function ensureGlobalServices(): void
    {
        foreach ($this->serviceRegistry->getGlobalServices() as $service) {
            $service->start();
        }
    }
}
