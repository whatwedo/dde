<?php

declare(strict_types=1);

namespace App\Model;

enum ServiceStartStatus: string
{
    case STARTED = 'started';
    case ALREADY_RUNNING = 'already_running';
}
