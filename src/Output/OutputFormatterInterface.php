<?php

declare(strict_types=1);

namespace App\Output;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

interface OutputFormatterInterface
{
    /**
     * @return int Command exit code
     */
    public function success(mixed $data, string $message = ''): int;

    /**
     * @param array<string> $errors
     *
     * @return int Command exit code
     */
    public function error(string $message, array $errors = []): int;

    /**
     * @param array<string> $headers
     * @param array<array<string>> $rows
     */
    public function table(array $headers, array $rows): void;

    public function setOutput(OutputInterface $output, ?InputInterface $input = null): void;

    /**
     * Whether this formatter supports interactive terminal output (progress, tables, styled text).
     * Structured formatters (JSON, XML) return false — commands should suppress interactive output.
     */
    public function isInteractive(): bool;
}
