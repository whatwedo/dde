<?php

declare(strict_types=1);

namespace App\Service;

use App\Database\DatabaseAdapterRegistry;
use App\Model\ServiceDefinition;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class ServiceRegistry
{
    /**
     * @var array<string, array{image: string, defaultVersion: string, knownVersions: list<string>, defaultPort: int, dataPath: string, dataMount: string, environment: array<string, string>}>
     */
    private const array SERVICE_TYPES = [
        'mariadb' => [
            'image' => 'mariadb',
            'defaultVersion' => '11.8',
            'knownVersions' => ['10.6', '10.11', '11.4', '11.8'],
            'defaultPort' => 3306,
            'dataPath' => 'mysql',
            'dataMount' => '/var/lib/mysql',
            'environment' => [
                'MARIADB_ROOT_PASSWORD' => 'root',
            ],
        ],
        'postgres' => [
            'image' => 'postgres',
            'defaultVersion' => '18.3',
            'knownVersions' => ['15', '16', '17', '18.3'],
            'defaultPort' => 5432,
            'dataPath' => 'pgdata',
            'dataMount' => '/var/lib/postgresql',
            'environment' => [
                'POSTGRES_USER' => 'postgres',
                'POSTGRES_PASSWORD' => 'postgres',
            ],
        ],
        'valkey' => [
            'image' => 'valkey/valkey',
            'defaultVersion' => '9',
            'knownVersions' => ['7.2', '8.0', '9'],
            'defaultPort' => 6379,
            'dataPath' => 'data',
            'dataMount' => '/data',
            'environment' => [],
        ],
        'mailpit' => [
            'image' => 'axllent/mailpit',
            'defaultVersion' => 'latest',
            'knownVersions' => [],
            'defaultPort' => 8025,
            'dataPath' => 'data',
            'dataMount' => '/data',
            'environment' => [],
        ],
    ];

    /**
     * @var array<AbstractSystemService>
     */
    private array $resolvedGlobalServices;

    /**
     * @param iterable<AbstractSystemService> $globalServices
     */
    public function __construct(
        #[AutowireIterator('app.global_service')]
        iterable $globalServices,
        private DatabaseAdapterRegistry $databaseAdapterRegistry,
    ) {
        $this->resolvedGlobalServices = iterator_to_array($globalServices, false);
    }

    /**
     * Returns all global services (traefik, dnsmasq, ssh-agent) started by system:up.
     *
     * @return array<AbstractSystemService>
     */
    public function getGlobalServices(): array
    {
        return $this->resolvedGlobalServices;
    }

    public static function buildContainerName(string $service, string $version): string
    {
        return sprintf('dde-%s-%s', $service, $version);
    }

    public function getServiceConfig(string $name, ?string $version = null): ServiceDefinition
    {
        $version ??= $this->getServiceVersion($name);

        return new ServiceDefinition(
            name: $name,
            version: $version,
            containerName: self::buildContainerName($name, $version),
        );
    }

    public function getServiceImage(string $name, string $version): string
    {
        if (! isset(self::SERVICE_TYPES[$name])) {
            return $name.':'.$version;
        }

        return self::SERVICE_TYPES[$name]['image'].':'.$version;
    }

    public function getServicePort(string $name): int
    {
        if (! isset(self::SERVICE_TYPES[$name])) {
            throw new \InvalidArgumentException(sprintf('Unknown service "%s".', $name));
        }

        return self::SERVICE_TYPES[$name]['defaultPort'];
    }

    /**
     * @return array<string>
     */
    public function getAllServiceTypes(): array
    {
        return array_keys(self::SERVICE_TYPES);
    }

    public function isKnownService(string $name): bool
    {
        return isset(self::SERVICE_TYPES[$name]);
    }

    public function isDatabaseService(string $name): bool
    {
        return $this->databaseAdapterRegistry->hasAdapter($name);
    }

    public function getServiceVersion(string $name): string
    {
        return self::SERVICE_TYPES[$name]['defaultVersion'] ?? 'latest';
    }

    /**
     * Curated list of versions offered by the interactive version chooser.
     * An empty list means the service has no meaningful version choice
     * (e.g., mailpit always tracks latest) and the chooser is skipped.
     *
     * @return list<string>
     */
    public function getKnownVersions(string $name): array
    {
        return self::SERVICE_TYPES[$name]['knownVersions'] ?? [];
    }

    public function supportsVersionChoice(string $name): bool
    {
        return $this->getKnownVersions($name) !== [];
    }

    /**
     * Returns default versions for all known services.
     *
     * @return array<string, string>
     */
    public function getDefaultVersions(): array
    {
        $versions = [];

        foreach (self::SERVICE_TYPES as $name => $config) {
            $versions[$name] = $config['defaultVersion'];
        }

        return $versions;
    }

    /**
     * @return array<string, string>
     */
    public function getServiceEnvironment(string $name): array
    {
        return self::SERVICE_TYPES[$name]['environment'] ?? [];
    }

    public function getContainerDataMount(string $name): string
    {
        return self::SERVICE_TYPES[$name]['dataMount'] ?? '/data';
    }
}
