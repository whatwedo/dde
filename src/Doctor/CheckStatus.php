<?php

declare(strict_types=1);

namespace App\Doctor;

enum CheckStatus: string
{
    case OK = 'ok';
    case WARNING = 'warning';
    case ERROR = 'error';
    case SKIPPED = 'skipped';
}
