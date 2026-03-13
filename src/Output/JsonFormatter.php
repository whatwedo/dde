<?php

declare(strict_types=1);

namespace App\Output;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class JsonFormatter implements OutputFormatterInterface
{
    private ?OutputInterface $output = null;

    public function success(mixed $data, string $message = ''): int
    {
        $this->write([
            'status' => 'ok',
            'message' => $message,
            'data' => $data,
            'errors' => [],
        ]);

        return Command::SUCCESS;
    }

    public function error(string $message, array $errors = []): int
    {
        $this->write([
            'status' => 'error',
            'message' => $message,
            'data' => null,
            'errors' => $errors,
        ]);

        return Command::FAILURE;
    }

    /**
     * @param mixed[][] $rows
     */
    public function table(array $headers, array $rows): void
    {
        $this->write([
            'status' => 'ok',
            'message' => '',
            'data' => array_map(
                fn (array $row): array => array_combine($headers, $row),
                $rows,
            ),
            'errors' => [],
        ]);
    }

    public function setOutput(OutputInterface $output, ?InputInterface $input = null): void
    {
        $this->output = $output;
    }

    public function isInteractive(): bool
    {
        return false;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function write(array $data): void
    {
        $this->getOutput()->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * @throws \RuntimeException
     */
    private function getOutput(): OutputInterface
    {
        if (!$this->output instanceof OutputInterface) {
            throw new \RuntimeException('Output not initialized. Call setOutput() first.');
        }

        return $this->output;
    }
}
