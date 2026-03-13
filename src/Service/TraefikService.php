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

    /**
     * @param list<string> $hostnames
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException
     */
    public function generateLabels(array $hostnames, string $serviceName, ?int $port = null): array
    {
        if ($hostnames === []) {
            throw new \InvalidArgumentException('At least one hostname is required');
        }

        $primaryHostname = $hostnames[0];
        $routerName = $this->generateRouterName($primaryHostname, $serviceName);
        $hostRule = $this->generateHostRule($hostnames);

        $labels = [
            'traefik.enable=true',
            sprintf('traefik.http.routers.%s.rule=%s', $routerName, $hostRule),
        ];

        if ($port !== null) {
            $labels[] = sprintf('traefik.http.services.%s.loadbalancer.server.port=%d', $routerName, $port);
        }

        $labels[] = sprintf('traefik.http.routers.%s-tls.rule=%s', $routerName, $hostRule);
        $labels[] = sprintf('traefik.http.routers.%s-tls.tls=true', $routerName);

        return $labels;
    }

    public function generateRouterName(string $hostname, string $serviceName): string
    {
        return str_replace('.', '-', $hostname).'-'.$serviceName;
    }

    /**
     * @param list<string> $hostnames
     */
    private function generateHostRule(array $hostnames): string
    {
        $parts = array_map(
            static fn (string $h): string => sprintf('Host(`%s`)', $h),
            $hostnames,
        );

        return implode(' || ', $parts);
    }

    private function ensureCertsDir(): void
    {
        $this->filesystem->mkdir($this->dataDir.'/certs');
    }
}
