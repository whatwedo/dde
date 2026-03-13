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

#[AsCommand(
    name: 'system:status',
    description: 'Show status of global dde services',
)]
final class SystemStatusCommand extends AbstractSystemCommand
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

        $networkExists = $this->dockerManager->networkExists('dde');
        $services = [];
        $rows = [];

        foreach ($this->serviceRegistry->getGlobalServices() as $service) {
            $running = $service->isRunning();
            $status = $running ? 'running' : 'stopped';
            $containerName = $service->getContainerName();

            $services[] = [
                'name' => $service->getName(),
                'status' => $status,
                'container' => $containerName,
            ];

            $rows[] = [
                $service->getName(),
                $running ? '<info>'.$status.'</info>' : '<fg=red>'.$status.'</>',
                $containerName,
            ];
        }

        if (!$formatter->isInteractive()) {
            return $formatter->success([
                'services' => $services,
                'network' => $networkExists,
            ]);
        }

        $formatter->table(
            ['Name', 'Status', 'Container'],
            $rows,
        );

        return Command::SUCCESS;
    }
}
