<?php

declare(strict_types=1);

namespace App\Command\Project;

use App\Command\AbstractProjectCommand;
use App\Manager\DockerComposeManager;
use App\Manager\ProjectConfigManager;
use App\Output\FormatterResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'project:stop',
    description: 'Stop the project containers without removing them',
    aliases: ['stop'],
)]
final class ProjectStopCommand extends AbstractProjectCommand
{
    public function __construct(
        ProjectConfigManager $configManager,
        private readonly DockerComposeManager $dockerComposeManager,
        FormatterResolver $formatterResolver,
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

        if ($formatter->isInteractive()) {
            $io->writeln(sprintf('Stopping project <info>%s</info>...', $config->projectName));
        }

        $this->dockerComposeManager->stop($projectDir);

        if (!$formatter->isInteractive()) {
            return $formatter->success([
                'project' => $config->projectName,
                'status' => 'stopped',
            ]);
        }

        $io->newLine();
        $io->success(sprintf('Project %s stopped. Containers are preserved — use project:up to restart.', $config->projectName));

        return self::SUCCESS;
    }
}
