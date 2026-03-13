<?php

declare(strict_types=1);

namespace App\Model;

final readonly class ContainerInfo
{
    /**
     * @param array<string, string> $labels
     */
    public function __construct(
        public string $name,
        public ContainerStatus $status,
        public string $image,
        public array $labels = [],
    ) {
    }
}
