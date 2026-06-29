<?php

declare(strict_types=1);

namespace App\Config;

enum SshAgentMode: string
{
    case Managed = 'managed';
    case Host = 'host';
}
