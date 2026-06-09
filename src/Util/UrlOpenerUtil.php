<?php

declare(strict_types=1);

namespace App\Util;

final class UrlOpenerUtil
{
    public function __construct(
        private readonly ProcessFactory $processFactory = new ProcessFactory(),
    ) {
    }

    /**
     * Opens the given URL. When $browser is a non-empty executable (path or
     * name accepting a URL argument, e.g. `/usr/bin/firefox`) it is used
     * instead of the platform default opener. The opener stays neutral: only
     * callers that explicitly want a browser pass one — `project:db:open`, for
     * instance, must keep routing DSNs through the OS default handler.
     */
    public function open(string $url, ?string $browser = null): bool
    {
        $command = $browser !== null && $browser !== '' ? $browser : $this->defaultCommand();

        $process = $this->processFactory->create([$command, $url]);
        $process->run();

        return $process->isSuccessful();
    }

    private function defaultCommand(): string
    {
        return match (PHP_OS_FAMILY) {
            'Darwin' => 'open',
            'Windows' => 'start',
            default => 'xdg-open',
        };
    }
}
