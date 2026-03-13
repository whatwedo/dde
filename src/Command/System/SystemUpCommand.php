<?php

declare(strict_types=1);

namespace App\Command\System;

use App\Command\AbstractSystemCommand;
use App\Model\ServiceStartStatus;
use App\Output\FormatterResolver;
use App\Service\ServiceRegistry;
use App\Service\SshAgentService;
use App\Service\TraefikService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'system:up',
    description: 'Start all global dde services',
)]
final class SystemUpCommand extends AbstractSystemCommand
{
    public function __construct(
        private readonly ServiceRegistry $serviceRegistry,
        private readonly TraefikService $traefikService,
        private readonly SshAgentService $sshAgentService,
        FormatterResolver $formatterResolver,
    ) {
        parent::__construct($formatterResolver);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = $this->resolveFormatter($output, $input);
        $io = new SymfonyStyle($input, $output);

        if ($formatter->isInteractive()) {
            $io->writeln('Ensuring dde network...');
        }

        $this->traefikService->ensureNetwork();

        $results = [];

        foreach ($this->serviceRegistry->getGlobalServices() as $service) {
            $wasRunning = $service->isRunning();

            if ($formatter->isInteractive()) {
                $io->write(sprintf('  Starting <info>%s</info> (%s)... ', $service->getName(), $service->getContainerName()));
            }

            $service->start();

            $status = $wasRunning ? ServiceStartStatus::ALREADY_RUNNING : ServiceStartStatus::STARTED;

            if ($formatter->isInteractive()) {
                $io->writeln($wasRunning ? '<comment>already running</comment>' : '<info>done</info>');
            }

            $results[] = [
                'name' => $service->getName(),
                'status' => $status->value,
                'container' => $service->getContainerName(),
            ];
        }

        // Add SSH keys interactively after all services are started (requires TTY for passphrase prompts)
        if ($input->isInteractive() && Process::isTtySupported() && $this->sshAgentService->isRunning() && $this->sshAgentService->getLoadedKeyCount() === 0) {
            $keys = $this->sshAgentService->getConfiguredKeys();

            if ($keys !== []) {
                $io->newLine();
                $io->writeln(sprintf('  Adding <info>%d</info> SSH key(s) to agent...', count($keys)));
                $this->sshAgentService->addKeys();
                $io->writeln(sprintf('  <info>%d</info> key(s) loaded.', $this->sshAgentService->getLoadedKeyCount()));
            }
        }

        if (!$formatter->isInteractive()) {
            return $formatter->success([
                'services' => $results,
            ]);
        }

        $io->newLine();
        $io->success('All global services started.');

        return Command::SUCCESS;
    }
}
