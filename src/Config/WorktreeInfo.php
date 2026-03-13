<?php

declare(strict_types=1);

namespace App\Config;

final readonly class WorktreeInfo
{
    public function __construct(
        public string $mainDirectory,
        public string $worktreeDirectory,
        public string $branch,
        public string $suffix,
    ) {
    }
}
