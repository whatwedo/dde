<?php

declare(strict_types=1);

namespace App\Manager;

final readonly class EnvMigrationProposal
{
    public function __construct(
        public string $variable,
        public string $envFile,
        public string $originalValue,
        public string $envTargetValue,
        public string $composeValue,
        public string $description,
    ) {
    }
}
