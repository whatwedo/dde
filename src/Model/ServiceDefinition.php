<?php

declare(strict_types=1);

namespace App\Model;

final readonly class ServiceDefinition
{
    /**
     * @param array<int, int> $ports
     */
    public function __construct(
        public string $name,
        public string $version = 'latest',
        public string $containerName = '',
        public array $ports = [],
    ) {
    }
}
