<?php

declare(strict_types=1);

namespace App\Command\System;

use App\Command\AbstractSystemCommand;
use App\Manager\DockerManager;
use App\Output\FormatterResolver;
use App\Service\ServiceRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'system:down',
    description: 'Stop all global dde services',
)]
final class SystemDownCommand extends AbstractSystemCommand
{
    public function __construct(
        private readonly ServiceRegistry $serviceRegistry,
        private readonly DockerManager $dockerManager,
        FormatterResolver $formatterResolver,
    ) {
        parent::__construct($formatterResolver);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = $this->resolveFormatter($output, $input);
        $io = new SymfonyStyle($input, $output);

        $services = $this->serviceRegistry->getGlobalServices();
        $services = array_reverse($services);

        $results = [];

        foreach ($services as $service) {
            $wasRunning = $service->isRunning();

            if ($formatter->isInteractive()) {
                $io->write(sprintf('  Stopping <info>%s</info> (%s)... ', $service->getName(), $service->getContainerName()));
            }

            $service->remove();

            $status = $wasRunning ? 'stopped' : 'already_stopped';

            if ($formatter->isInteractive()) {
                $io->writeln($wasRunning ? '<info>done</info>' : '<comment>not running</comment>');
            }

            $results[] = [
                'name' => $service->getName(),
                'status' => $status,
                'container' => $service->getContainerName(),
            ];
        }

        // Stop versioned service containers (mariadb, postgres, valkey, etc.)
        $serviceContainers = $this->dockerManager->getContainersByLabel('dde.service');

        foreach ($serviceContainers as $container) {
            if ($formatter->isInteractive()) {
                $io->write(sprintf('  Stopping <info>%s</info>... ', $container->name));
            }

            $this->dockerManager->stop($container->name);
            $this->dockerManager->remove($container->name);

            if ($formatter->isInteractive()) {
                $io->writeln('<info>done</info>');
            }

            $results[] = [
                'name' => $container->name,
                'status' => 'stopped',
                'container' => $container->name,
            ];
        }

        if (!$formatter->isInteractive()) {
            return $formatter->success([
                'services' => $results,
            ]);
        }

        $io->newLine();
        $io->success('All dde services stopped.');

        return Command::SUCCESS;
    }
}
