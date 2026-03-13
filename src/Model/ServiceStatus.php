<?php

declare(strict_types=1);

namespace App\Model;

enum ServiceStatus: string
{
    case RUNNING = 'running';
    case STOPPED = 'stopped';
}
