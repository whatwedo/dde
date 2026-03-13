<?php

declare(strict_types=1);

namespace App\Command\System;

use App\Command\AbstractSystemCommand;
use App\Output\FormatterResolver;
use App\Service\ServiceRegistry;
use App\Service\TraefikService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'system:restart',
    description: 'Restart all global dde services',
)]
final class SystemRestartCommand extends AbstractSystemCommand
{
    public function __construct(
        private readonly ServiceRegistry $serviceRegistry,
        private readonly TraefikService $traefikService,
        FormatterResolver $formatterResolver,
    ) {
        parent::__construct($formatterResolver);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = $this->resolveFormatter($output, $input);
        $io = new SymfonyStyle($input, $output);

        $services = $this->serviceRegistry->getGlobalServices();

        // Stop in reverse order
        if ($formatter->isInteractive()) {
            $io->writeln('Stopping services...');
        }

        foreach (array_reverse($services) as $service) {
            if ($formatter->isInteractive()) {
                $io->write(sprintf('  Stopping <info>%s</info>... ', $service->getName()));
            }

            $service->stop();

            if ($formatter->isInteractive()) {
                $io->writeln('<info>done</info>');
            }
        }

        // Start in original order
        if ($formatter->isInteractive()) {
            $io->newLine();
            $io->writeln('Starting services...');
        }

        $this->traefikService->ensureNetwork();

        $results = [];

        foreach ($services as $service) {
            if ($formatter->isInteractive()) {
                $io->write(sprintf('  Starting <info>%s</info> (%s)... ', $service->getName(), $service->getContainerName()));
            }

            $service->start();

            if ($formatter->isInteractive()) {
                $io->writeln('<info>done</info>');
            }

            $results[] = [
                'name' => $service->getName(),
                'status' => 'restarted',
                'container' => $service->getContainerName(),
            ];
        }

        if (!$formatter->isInteractive()) {
            return $formatter->success([
                'services' => $results,
            ]);
        }

        $io->newLine();
        $io->success('All global services restarted.');

        return Command::SUCCESS;
    }
}
