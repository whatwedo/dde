<?php

declare(strict_types=1);

namespace App\Manager;

use App\Model\ContainerConfig;
use App\Model\ServiceStartStatus;
use App\Service\ServiceRegistry;
use Symfony\Component\Filesystem\Filesystem;

final readonly class SystemServiceManager
{
    public function __construct(
        private DockerManager $dockerManager,
        private ServiceRegistry $serviceRegistry,
        private Filesystem $filesystem,
        private string $dataDir,
    ) {
    }

    public function getContainerName(string $service, string $version): string
    {
        return ServiceRegistry::buildContainerName($service, $version);
    }

    public function startService(string $service, string $version, bool $isDefault): ServiceStartStatus
    {
        $containerName = $this->getContainerName($service, $version);

        if ($this->dockerManager->isContainerRunning($containerName)) {
            return ServiceStartStatus::ALREADY_RUNNING;
        }

        $config = $this->getContainerConfig($service, $version, $isDefault);
        $this->ensureDataDir($service, $version);
        $this->dockerManager->run($config);

        return ServiceStartStatus::STARTED;
    }

    public function stopService(string $service, string $version): void
    {
        $containerName = $this->getContainerName($service, $version);

        if (! $this->dockerManager->isContainerRunning($containerName)) {
            return;
        }

        $this->dockerManager->stop($containerName);
        $this->dockerManager->remove($containerName);
    }

    public function isServiceRunning(string $service, string $version): bool
    {
        return $this->dockerManager->isContainerRunning($this->getContainerName($service, $version));
    }

    public function getContainerConfig(string $service, string $version, bool $isDefault): ContainerConfig
    {
        $containerName = $this->getContainerName($service, $version);
        $image = $this->getServiceImage($service, $version);
        $port = $this->allocatePort($service, $version, $isDefault);
        $dataPath = $this->getServiceDataPath($service, $version);

        return new ContainerConfig(
            image: $image,
            containerName: $containerName,
            ports: [$port],
            volumes: [
                $dataPath => $this->serviceRegistry->getContainerDataMount($service),
            ],
            environment: $this->serviceRegistry->getServiceEnvironment($service),
            labels: [
                'dde.managed' => 'true',
                'dde.service' => $service,
                'dde.version' => $version,
            ],
            restartPolicy: 'unless-stopped',
        );
    }

    public function allocatePort(string $service, string $version, bool $isDefault): string
    {
        if ($isDefault && $this->serviceRegistry->isKnownService($service)) {
            $port = $this->serviceRegistry->getServicePort($service);

            return sprintf('127.0.0.1:%d:%d', $port, $port);
        }

        $registry = $this->loadPortRegistry();
        $key = $service.'-'.$version;

        if (isset($registry[$key])) {
            $hostPort = $registry[$key];
        } else {
            $hostPort = $this->findNextAvailablePort($registry);
            $registry[$key] = $hostPort;
            $this->savePortRegistry($registry);
        }

        $containerPort = $this->serviceRegistry->isKnownService($service)
            ? $this->serviceRegistry->getServicePort($service)
            : 0;

        return sprintf('127.0.0.1:%d:%d', $hostPort, $containerPort);
    }

    /**
     * @return array<string, int>
     */
    public function loadPortRegistry(): array
    {
        $path = $this->dataDir.'/ports.json';

        if (! $this->filesystem->exists($path)) {
            return [];
        }

        $content = $this->filesystem->readFile($path);

        if ($content === '') {
            return [];
        }

        try {
            /** @var array<string, int> $data */
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return $data;
    }

    /**
     * @param array<string, int> $registry
     */
    public function savePortRegistry(array $registry): void
    {
        $path = $this->dataDir.'/ports.json';
        $this->filesystem->mkdir(dirname($path));
        $this->filesystem->dumpFile(
            $path,
            json_encode($registry, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n",
        );
    }

    private function getServiceImage(string $service, string $version): string
    {
        return $this->serviceRegistry->getServiceImage($service, $version);
    }

    private function getServiceDataPath(string $service, string $version): string
    {
        return $this->dataDir.'/services/'.$service.'-'.$version;
    }

    private function ensureDataDir(string $service, string $version): void
    {
        $this->filesystem->mkdir($this->getServiceDataPath($service, $version));
    }

    /**
     * @param array<string, int> $registry
     *
     * @throws \RuntimeException
     */
    private function findNextAvailablePort(array $registry): int
    {
        $usedPorts = array_values($registry);
        $port = 10000;
        $maxPort = 65535;

        while (in_array($port, $usedPorts, true)) {
            $port++;

            if ($port > $maxPort) {
                throw new \RuntimeException('No available port found in range 10000-65535. Clean up unused services with system:cleanup.');
            }
        }

        return $port;
    }
}
