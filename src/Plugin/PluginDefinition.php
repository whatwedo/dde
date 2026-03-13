<?php

declare(strict_types=1);

namespace App\Plugin;

final readonly class PluginDefinition
{
    public function __construct(
        public string $command,
        public string $description,
        public string $scriptPath,
        public ?string $pluginDir = null,
    ) {
    }
}
