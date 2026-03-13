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
}
