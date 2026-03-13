<?php

declare(strict_types=1);

namespace App\Doctor;

final readonly class CheckResult
{
    public function __construct(
        public string $name,
        public CheckStatus $status,
        public string $message,
        public string $fixHint = '',
    ) {
    }
}
