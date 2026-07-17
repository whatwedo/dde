<?php

declare(strict_types=1);

namespace Tests\Support\Process;

use Symfony\Component\Process\Process;

/**
 * A Process that never actually runs: it reports a preconfigured exit code and
 * error output. `$onRun` fires when run() is called, so tests can observe state
 * that only exists at execution time (e.g. the escalator's tempfile).
 */
final class StubProcess extends Process
{
    /**
     * @param list<string> $commandArgs
     * @param (\Closure(self): void)|null $onRun
     */
    public function __construct(
        public readonly array $commandArgs,
        private readonly int $exitCode = 0,
        private readonly string $stubErrorOutput = '',
        private readonly ?\Closure $onRun = null,
    ) {
        parent::__construct($commandArgs);
    }

    /**
     * @param array<string, mixed> $env
     */
    public function run(?callable $callback = null, array $env = []): int
    {
        if ($this->onRun instanceof \Closure) {
            ($this->onRun)($this);
        }

        return $this->exitCode;
    }

    public function isSuccessful(): bool
    {
        return $this->exitCode === 0;
    }

    public function getExitCode(): int
    {
        return $this->exitCode;
    }

    public function getErrorOutput(): string
    {
        return $this->stubErrorOutput;
    }
}
