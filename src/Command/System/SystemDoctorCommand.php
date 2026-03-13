<?php

declare(strict_types=1);

namespace App\Command\System;

use App\Command\AbstractSystemCommand;
use App\Doctor\Check\DockerAvailableCheck;
use App\Doctor\CheckInterface;
use App\Doctor\CheckResult;
use App\Doctor\CheckStatus;
use App\Output\FormatterResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

#[AsCommand(
    name: 'system:doctor',
    description: 'Check the health of the dde system',
)]
final class SystemDoctorCommand extends AbstractSystemCommand
{
    /**
     * @param iterable<CheckInterface> $checks
     */
    public function __construct(
        #[AutowireIterator('dde.doctor_check')]
        private readonly iterable $checks,
        FormatterResolver $formatterResolver,
    ) {
        parent::__construct($formatterResolver);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = $this->resolveFormatter($output, $input);
        $io = new SymfonyStyle($input, $output);

        $results = [];
        $hasError = false;
        $hasWarning = false;

        $checks = [...$this->checks];
        usort($checks, static fn (CheckInterface $a, CheckInterface $b): int => $b->getPriority() <=> $a->getPriority());

        $dockerAvailable = true;

        foreach ($checks as $check) {
            if (!$dockerAvailable && $check->requiresDocker()) {
                $result = new CheckResult(
                    name: $check->getName(),
                    status: CheckStatus::SKIPPED,
                    message: 'Skipped (Docker not available)',
                );
                $results[] = $result;

                if ($formatter->isInteractive()) {
                    $io->writeln(sprintf('⏭️ %s: %s', $result->name, $result->message));
                }

                continue;
            }

            $result = $check->run();
            $results[] = $result;

            if ($result->status === CheckStatus::ERROR) {
                $hasError = true;
            }

            if ($result->status === CheckStatus::WARNING) {
                $hasWarning = true;
            }

            if ($check instanceof DockerAvailableCheck && $result->status !== CheckStatus::OK) {
                $dockerAvailable = false;
            }

            if ($formatter->isInteractive()) {
                $icon = match ($result->status) {
                    CheckStatus::OK => '✅',
                    CheckStatus::WARNING => '⚠️',
                    CheckStatus::ERROR => '❌',
                    CheckStatus::SKIPPED => '⏭️',
                };

                $line = sprintf('%s %s: %s', $icon, $result->name, $result->message);
                $io->writeln($line);

                if ($result->fixHint !== '' && $result->status !== CheckStatus::OK) {
                    $io->writeln(sprintf('   <comment>Fix: %s</comment>', $result->fixHint));
                }
            }
        }

        if (!$formatter->isInteractive()) {
            $data = array_map(
                static fn (CheckResult $r): array => [
                    'name' => $r->name,
                    'status' => $r->status->value,
                    'message' => $r->message,
                    'fix_hint' => $r->fixHint,
                ],
                $results,
            );

            if ($hasError) {
                $errors = array_map(
                    static fn (array $check): string => sprintf('%s: %s', $check['name'], $check['message']),
                    array_filter($data, static fn (array $check): bool => $check['status'] === CheckStatus::ERROR->value),
                );

                return $formatter->error('Some checks failed.', array_values($errors));
            }

            return $formatter->success([
                'checks' => $data,
            ]);
        }

        $io->newLine();

        if ($hasError) {
            $io->error('Some checks failed. Please fix the issues above.');

            return Command::FAILURE;
        }

        if ($hasWarning) {
            $io->warning('All checks passed with warnings.');

            return Command::SUCCESS;
        }

        $io->success('All checks passed.');

        return Command::SUCCESS;
    }
}
