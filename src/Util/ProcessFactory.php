<?php

declare(strict_types=1);

namespace App\Util;

use Symfony\Component\Process\Process;

readonly class ProcessFactory
{
    /**
     * @param list<string> $command
     */
    public function create(array $command, ?string $cwd = null, int|float|null $timeout = 60): Process
    {
        $process = new Process($command, $cwd);
        $process->setTimeout($timeout);

        return $process;
    }
}
