<?php

declare(strict_types=1);

namespace Tests\Support\Process;

use App\Util\ProcessFactory;

/**
 * Records every created command and returns StubProcesses with preconfigured
 * results, in FIFO order. When the result queue is empty, a succeeding stub
 * is returned.
 */
final readonly class RecordingProcessFactory extends ProcessFactory
{
    /**
     * @var \ArrayObject<int, array{exitCode?: int, errorOutput?: string, onRun?: \Closure(StubProcess): void}>
     */
    public \ArrayObject $results;

    /**
     * @param \ArrayObject<int, list<string>> $commands
     * @param list<array{exitCode?: int, errorOutput?: string, onRun?: \Closure(StubProcess): void}> $results
     */
    public function __construct(
        public \ArrayObject $commands = new \ArrayObject(),
        array $results = [],
    ) {
        $this->results = new \ArrayObject($results);
    }

    public function create(array $command, ?string $cwd = null, int|float|null $timeout = 60): StubProcess
    {
        $this->commands->append($command);

        $results = $this->results->getArrayCopy();
        $result = array_shift($results) ?? [];
        $this->results->exchangeArray($results);

        return new StubProcess(
            $command,
            $result['exitCode'] ?? 0,
            $result['errorOutput'] ?? '',
            $result['onRun'] ?? null,
        );
    }
}
