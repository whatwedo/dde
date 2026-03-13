<?php

declare(strict_types=1);

namespace App\Output;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class TextFormatter implements OutputFormatterInterface
{
    private ?SymfonyStyle $io = null;

    public function success(mixed $data, string $message = ''): int
    {
        $this->getIo()->success($message !== '' ? $message : 'OK');

        return Command::SUCCESS;
    }

    public function error(string $message, array $errors = []): int
    {
        $this->getIo()->error($message);

        foreach ($errors as $error) {
            $this->getIo()->writeln(sprintf('  - %s', $error));
        }

        return Command::FAILURE;
    }

    public function table(array $headers, array $rows): void
    {
        $this->getIo()->table($headers, $rows);
    }

    public function setOutput(OutputInterface $output, ?InputInterface $input = null): void
    {
        $this->io = new SymfonyStyle($input ?? new ArrayInput([]), $output);
    }

    public function isInteractive(): bool
    {
        return true;
    }

    /**
     * @throws \RuntimeException
     */
    private function getIo(): SymfonyStyle
    {
        if (!$this->io instanceof SymfonyStyle) {
            throw new \RuntimeException('Output not initialized. Call setOutput() first.');
        }

        return $this->io;
    }
}
