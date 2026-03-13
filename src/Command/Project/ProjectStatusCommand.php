<?php

declare(strict_types=1);

namespace App\Command\Project;

use App\Command\AbstractProjectCommand;
use App\Manager\ConfigManager;
use App\Manager\DockerComposeManager;
use App\Manager\DockerManager;
use App\Output\FormatterResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'project:status',
    description: 'Show project status',
)]
final class ProjectStatusCommand extends AbstractProjectCommand
{
    public function __construct(
        ConfigManager $configManager,
        private readonly DockerComposeManager $dockerComposeManager,
        FormatterResolver $formatterResolver,
        private readonly DockerManager $dockerManager,
    ) {
        parent::__construct($configManager, $formatterResolver);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = $this->resolveFormatter($output, $input);
        $io = new SymfonyStyle($input, $output);

        try {
            $projectDir = $this->getProjectDirectory();
        } catch (\RuntimeException $runtimeException) {
            return $formatter->error($runtimeException->getMessage());
        }

        $config = $this->getResolvedConfig();
        $containers = $this->dockerComposeManager->ps($projectDir);

        $overallStatus = $this->dockerManager->determineOverallStatus($containers);

        $containerData = [];

        foreach ($containers as $container) {
            $containerData[] = [
                'name' => $container['Service'] ?? $container['service'] ?? $container['Name'] ?? '',
                'status' => $container['State'] ?? $container['state'] ?? $container['Status'] ?? 'unknown',
                'health' => $container['Health'] ?? $container['health'] ?? '',
                'ports' => $this->dockerManager->extractPorts($container),
            ];
        }

        if (!$formatter->isInteractive()) {
            return $formatter->success([
                'project' => $config->projectName,
                'status' => $overallStatus,
                'containers' => $containerData,
            ]);
        }

        $io->writeln(sprintf('Project <info>%s</info> — %s', $config->projectName, $overallStatus));
        $io->newLine();

        if ($containerData === []) {
            $io->writeln('  No containers running.');

            return self::SUCCESS;
        }

        $headers = ['Service', 'Status', 'Health', 'Ports'];
        $rows = [];

        foreach ($containerData as $c) {
            $rows[] = [
                $c['name'],
                $c['status'],
                $c['health'] !== '' ? $c['health'] : '-',
                implode(', ', $c['ports']),
            ];
        }

        $formatter->table($headers, $rows);

        return self::SUCCESS;
    }
}
