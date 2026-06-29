<?php

declare(strict_types=1);

namespace App\Model;

/**
 * Result of {@see \App\Service\HostSshAgentResolver}: whether a host agent
 * socket was found and its mount source. `reason` is a human-readable note —
 * the cause when unavailable, or an informational caveat when available (the
 * macOS bridge sets it while reporting available).
 */
final readonly class HostSshAgentResolution
{
    public function __construct(
        public bool $available,
        public ?string $mountSource,
        public ?string $reason,
    ) {
    }
}
