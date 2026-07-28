<?php

declare(strict_types=1);

namespace App\Util;

use Symfony\Component\Process\Process;

final class TtyUtil
{
    /**
     * TTY mode wires a child's stdin, stdout and stderr to `/dev/tty`, so a
     * redirected stream would be lost. Stdout is covered by
     * {@see Process::isTtySupported()}.
     */
    public static function hasTerminal(): bool
    {
        return Process::isTtySupported() && stream_isatty(STDIN) && stream_isatty(STDERR);
    }
}
