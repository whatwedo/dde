<?php

declare(strict_types=1);

namespace App\Manager;

use App\Database\DatabaseAdapterRegistry;
use App\Model\ServiceDefinition;
use App\Service\ServiceRegistry;

readonly class DatabaseManager
{
    public function __construct(
        private DockerManager $dockerManager,
        private DatabaseAdapterRegistry $adapterRegistry,
        private ServiceRegistry $serviceRegistry,
    ) {
    }

    public function resolveContainerName(ServiceDefinition $serviceDefinition): string
    {
        if ($serviceDefinition->containerName !== '') {
            return $serviceDefinition->containerName;
        }

        return ServiceRegistry::buildContainerName($serviceDefinition->name, $serviceDefinition->version);
    }

    public function isContainerRunning(ServiceDefinition $serviceDefinition): bool
    {
        return $this->dockerManager->isContainerRunning(
            $this->resolveContainerName($serviceDefinition),
        );
    }

    /**
     * @return array<string, string>
     */
    public function getContainerEnv(ServiceDefinition $serviceDefinition): array
    {
        return $this->dockerManager->getContainerEnv(
            $this->resolveContainerName($serviceDefinition),
        );
    }

    public function execInteractiveShell(ServiceDefinition $serviceDefinition, string $database = ''): void
    {
        $adapter = $this->adapterRegistry->getAdapter($serviceDefinition->name);

        $this->dockerManager->execInteractive(
            $this->resolveContainerName($serviceDefinition),
            $adapter->getShellCommand($database),
            $this->buildExtraEnv($serviceDefinition->name),
        );
    }

    public function exportDump(ServiceDefinition $serviceDefinition, string $database): string
    {
        $adapter = $this->adapterRegistry->getAdapter($serviceDefinition->name);

        return $this->dockerManager->execCaptureWithEnv(
            $this->resolveContainerName($serviceDefinition),
            $adapter->getDumpCommand($database),
            $this->buildExtraEnv($serviceDefinition->name),
        );
    }

    public function importDump(ServiceDefinition $serviceDefinition, string $database, mixed $inputHandle): void
    {
        $adapter = $this->adapterRegistry->getAdapter($serviceDefinition->name);

        $this->dockerManager->execWithInput(
            $this->resolveContainerName($serviceDefinition),
            $adapter->getRestoreCommand($database),
            $inputHandle,
            $this->buildExtraEnv($serviceDefinition->name),
        );
    }

    public function resolveHostPort(ServiceDefinition $serviceDefinition): int
    {
        $containerName = $this->resolveContainerName($serviceDefinition);
        $ports = $this->dockerManager->getContainerPorts($containerName);
        $defaultPort = $this->serviceRegistry->getServicePort($serviceDefinition->name);
        $portKey = sprintf('%d/tcp', $defaultPort);

        if (isset($ports[$portKey]) && is_array($ports[$portKey]) && isset($ports[$portKey][0])) {
            $binding = $ports[$portKey][0];

            if (is_array($binding) && isset($binding['HostPort']) && is_string($binding['HostPort'])) {
                return (int) $binding['HostPort'];
            }
        }

        return $defaultPort;
    }

    /**
     * @return array<string, string>
     */
    private function buildExtraEnv(string $serviceName): array
    {
        $adapter = $this->adapterRegistry->getAdapter($serviceName);

        return match ($serviceName) {
            'mariadb' => [
                'MYSQL_PWD' => $adapter->getPassword(),
            ],
            'postgres' => [
                'PGPASSWORD' => $adapter->getPassword(),
            ],
            default => [],
        };
    }
}
