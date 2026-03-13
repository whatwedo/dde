<?php

declare(strict_types=1);

namespace App\Event;

use App\Config\ResolvedConfig;
use Symfony\Contracts\EventDispatcher\Event;

abstract class AbstractProjectEvent extends Event
{
    public function __construct(
        public readonly ResolvedConfig $config,
        public readonly string $projectDir,
        public readonly bool $skipHooks = false,
    ) {
    }
}
