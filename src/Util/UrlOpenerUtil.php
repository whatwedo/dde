<?php

declare(strict_types=1);

namespace App\Util;

final class UrlOpenerUtil
{
    public function __construct(
        private readonly ProcessFactory $processFactory = new ProcessFactory(),
    ) {
    }

    public function open(string $url): bool
    {
        $cmd = match (PHP_OS_FAMILY) {
            'Darwin' => 'open',
            'Windows' => 'start',
            default => 'xdg-open',
        };

        $process = $this->processFactory->create([$cmd, $url]);
        $process->run();

        return $process->isSuccessful();
    }
}
