<?php

declare(strict_types=1);

namespace App\Config;

use App\Model\ServiceDefinition;

final readonly class ProjectConfig
{
    /**
     * @param array<ServiceDefinition> $services
     * @param array<string, mixed> $containers
     */
    public function __construct(
        public string $name = '',
        public array $services = [],
        public array $containers = [],
    ) {
    }

    /**
     * @param array<string, mixed> $processed Output from Processor::processConfiguration()
     */
    public static function fromProcessedConfig(array $processed): self
    {
        $services = [];
        foreach ($processed['services'] as $entry) {
            if (is_array($entry) && is_string($entry['name'] ?? null)) {
                $services[] = new ServiceDefinition(
                    name: $entry['name'],
                    version: is_string($entry['version'] ?? null) ? $entry['version'] : 'latest',
                );
            }
        }

        return new self(
            name: $processed['name'],
            services: $services,
            containers: $processed['containers'],
        );
    }
}
