<?php

declare(strict_types=1);

namespace App\Database;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class DatabaseAdapterRegistry
{
    /**
     * @param iterable<DatabaseAdapterInterface> $adapters
     */
    public function __construct(
        #[AutowireIterator('dde.database_adapter')]
        private readonly iterable $adapters = [],
    ) {
    }

    public function getAdapter(string $serviceName): DatabaseAdapterInterface
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->supports($serviceName)) {
                return $adapter;
            }
        }

        throw new \InvalidArgumentException(sprintf('No database adapter found for service "%s"', $serviceName));
    }

    public function hasAdapter(string $serviceName): bool
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->supports($serviceName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Maps a DB URL scheme (e.g. `mysql`, `postgresql`) to the compose service
     * type backing it (`mariadb`, `postgres`). Both `WorktreeManager` and
     * `ProjectInitAdaptationManager` need this; centralising it on the registry
     * means each adapter owns its own scheme list and a fourth DB adapter only
     * has to be wired through once.
     */
    public function getServiceTypeForUrlScheme(string $scheme): ?string
    {
        $normalised = strtolower($scheme);

        foreach ($this->adapters as $adapter) {
            if (in_array($normalised, $adapter->getUrlSchemes(), true)) {
                return $adapter->getServiceName();
            }
        }

        return null;
    }
}
