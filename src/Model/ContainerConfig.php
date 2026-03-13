<?php

declare(strict_types=1);

namespace App\Model;

final readonly class ContainerConfig
{
    /**
     * @param array<int, string> $ports
     * @param array<string, string> $volumes
     * @param array<string, string> $environment
     * @param array<string, string> $labels
     * @param array<string> $networkAliases
     */
    public function __construct(
        public string $image,
        public string $containerName,
        public array $ports = [],
        public array $volumes = [],
        public array $environment = [],
        public array $labels = [],
        public array $networkAliases = [],
        public string $restartPolicy = 'unless-stopped',
    ) {
    }
}
