<?php

declare(strict_types=1);

namespace App\Event;

use App\Config\ResolvedConfig;
use Symfony\Contracts\EventDispatcher\Event;

abstract class AbstractProjectEvent extends Event
{
    /**
     * Warnings collected by listeners (e.g. hook stderr), rendered by the dispatching command.
     *
     * @var list<string>
     */
    public array $warnings = [];

    public function __construct(
        public readonly ResolvedConfig $config,
        public readonly string $projectDir,
        public readonly bool $skipHooks = false,
    ) {
    }
}
