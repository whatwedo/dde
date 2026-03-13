<?php

declare(strict_types=1);

namespace App\Output;

enum OutputFormat: string
{
    case TEXT = 'text';
    case JSON = 'json';
}
