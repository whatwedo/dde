<?php

declare(strict_types=1);

namespace App\Model;

enum ContainerStatus: string
{
    public static function fromDockerStatus(string $status): self
    {
        $lower = strtolower($status);

        return match (true) {
            str_contains($lower, 'up'), str_starts_with($lower, 'running') => self::RUNNING,
            str_contains($lower, 'exited'), str_contains($lower, 'exit') => self::EXITED,
            str_contains($lower, 'paused') => self::PAUSED,
            str_contains($lower, 'restarting') => self::RESTARTING,
            str_contains($lower, 'created') => self::CREATED,
            str_contains($lower, 'dead') => self::DEAD,
            default => self::UNKNOWN,
        };
    }

    case RUNNING = 'running';
    case EXITED = 'exited';
    case PAUSED = 'paused';
    case RESTARTING = 'restarting';
    case CREATED = 'created';
    case DEAD = 'dead';
    case UNKNOWN = 'unknown';
}
