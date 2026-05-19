<?php

declare(strict_types=1);

namespace App\Service;

use App\Manager\DockerManager;
use App\Model\ContainerConfig;
use Symfony\Component\Filesystem\Filesystem;

final class TraefikService extends AbstractSystemService
{
    public function __construct(
        DockerManager $dockerManager,
        private readonly Filesystem $filesystem,
        private readonly string $dataDir,
    ) {
        parent::__construct($dockerManager);
    }

    public function getName(): string
    {
        return 'traefik';
    }

    public function getContainerName(): string
    {
        return 'dde-traefik';
    }

    public function getImageName(): string
    {
        return 'traefik:v3';
    }

    public function getContainerConfig(): ContainerConfig
    {
        return new ContainerConfig(
            image: $this->getImageName(),
            containerName: $this->getContainerName(),
            ports: ['127.0.0.1:80:80', '127.0.0.1:443:443'],
            volumes: [
                '/var/run/docker.sock' => '/var/run/docker.sock:ro',
                $this->dataDir.'/traefik' => '/etc/traefik',
                $this->dataDir.'/certs' => '/certs:ro',
            ],
            labels: $this->getDefaultLabels(),
            restartPolicy: 'unless-stopped',
        );
    }

    public function start(): void
    {
        $this->ensureNetwork();
        $this->ensureStaticConfig();
        $this->ensureDynamicConfigDir();
        $this->ensureCertsDir();

        parent::start();
    }

    public function attachesToProjectNetwork(): bool
    {
        return true;
    }

    public function requiresRestartAfterProjectNetworkAttach(): bool
    {
        return true;
    }

    public function ensureNetwork(): void
    {
        if (! $this->dockerManager->networkExists('dde')) {
            $this->dockerManager->createNetwork('dde');
        }
    }

    public function ensureStaticConfig(): void
    {
        $configPath = $this->dataDir.'/traefik/traefik.yml';
        $content = <<<'YAML'
            entryPoints:
              web:
                address: ":80"
              websecure:
                address: ":443"
            providersThrottleDuration: 0s
            providers:
              docker:
                exposedByDefault: false
                network: dde
              file:
                directory: /etc/traefik/dynamic
                watch: true
            YAML;

        $this->filesystem->mkdir(dirname($configPath));
        $this->filesystem->dumpFile($configPath, $content."\n");
    }

    public function ensureDynamicConfigDir(): void
    {
        $this->filesystem->mkdir($this->dataDir.'/traefik/dynamic');
    }

    private function ensureCertsDir(): void
    {
        $this->filesystem->mkdir($this->dataDir.'/certs');
    }
}
