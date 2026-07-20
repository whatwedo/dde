<?php

declare(strict_types=1);

namespace App\Service;

use App\Manager\DockerManager;
use App\Model\ContainerConfig;
use Symfony\Component\Filesystem\Filesystem;

final class TraefikService extends AbstractSystemService implements ProjectNetworkAwareInterface
{
    public const string DASHBOARD_HOSTNAME = 'traefik.test';

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
            labels: $this->getDefaultLabels() + $this->getDashboardLabels(),
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

    /**
     * @return list<string>
     */
    public function getProjectNetworkAliases(): array
    {
        return [];
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
                http:
                  redirections:
                    entryPoint:
                      to: websecure
                      scheme: https
                      permanent: false
              websecure:
                address: ":443"
            providersThrottleDuration: 0s
            api:
              dashboard: true
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
     * Routes the built-in Traefik dashboard (api@internal) to https://traefik.test
     * so configuration errors of any project router become visible at a glance.
     *
     * TLS is served by the dedicated system certificate (`_system`), which
     * covers the single-label system hosts — browsers reject the wildcard
     * *.test default certificate for them because a wildcard directly on a
     * TLD counts as covering a public suffix. The docker provider reads these
     * labels off the Traefik container itself, hence the explicit
     * traefik.enable=true.
     *
     * @return array<string, string>
     */
    private function getDashboardLabels(): array
    {
        return [
            'traefik.enable' => 'true',
            'traefik.http.routers.dashboard.rule' => sprintf('Host(`%s`)', self::DASHBOARD_HOSTNAME),
            'traefik.http.routers.dashboard.service' => 'api@internal',
            'traefik.http.routers.dashboard.entrypoints' => 'websecure',
            'traefik.http.routers.dashboard.tls' => 'true',
        ];
    }

    private function ensureCertsDir(): void
    {
        $this->filesystem->mkdir($this->dataDir.'/certs');
    }
}
