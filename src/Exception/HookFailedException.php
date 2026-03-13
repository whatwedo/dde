<?php

declare(strict_types=1);

namespace App\Exception;


final class HookFailedException extends \RuntimeException
{
    public function __construct(
        public readonly string $script,
        int $exitCode,
        string $errorOutput,
    ) {
        parent::__construct(sprintf(
            'Hook "%s" failed with exit code %d: %s',
            basename($script),
            $exitCode,
            $errorOutput,
        ));
    }
}
