<?php

declare(strict_types=1);

namespace App\Command\Project;

use App\Command\AbstractProjectCommand;
use App\Manager\DockerComposeManager;
use App\Manager\DockerManager;
use App\Manager\ProjectConfigManager;
use App\Manager\ProjectLifecycleManager;
use App\Manager\WorktreeManager;
use App\Output\FormatterResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'project:claude',
    description: 'Open Claude Code in the project\'s isolated agent container',
    aliases: ['claude'],
)]
final class ProjectClaudeCommand extends AbstractProjectCommand
{
    public function __construct(
        ProjectConfigManager $configManager,
        private readonly DockerManager $dockerManager,
        private readonly ProjectLifecycleManager $lifecycleManager,
        private readonly WorktreeManager $worktreeManager,
        FormatterResolver $formatterResolver,
    ) {
        parent::__construct($configManager, $formatterResolver);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = $this->resolveFormatter($output, $input);

        if (! $formatter->isInteractive()) {
            return $formatter->error('The "--output=json" option is not supported for interactive commands.');
        }

        try {
            $projectDir = $this->getProjectDirectory();
        } catch (\RuntimeException $runtimeException) {
            return $formatter->error($runtimeException->getMessage());
        }

        $config = $this->getResolvedConfig();

        if (! $config->claudeAgentEnabled) {
            return $formatter->error('The Claude agent container is disabled. Enable it in ~/.dde/config.yml under claude_agent.enabled.');
        }

        $this->lifecycleManager->ensureGlobalServices();

        $worktreeInfo = $this->worktreeManager->detect($projectDir);
        $containerName = DockerComposeManager::buildClaudeContainerName($config->projectName, $worktreeInfo);

        if (! $this->dockerManager->isContainerRunning($containerName)) {
            $io = new SymfonyStyle($input, $output);
            $io->writeln(sprintf('Claude agent container is not running. Starting project <info>%s</info>...', $config->projectName));

            $section = $output instanceof ConsoleOutputInterface && $output->isDecorated()
                ? $output->section()
                : null;

            try {
                $this->lifecycleManager->up($config, $projectDir, false, output: $section);
            } catch (\RuntimeException $runtimeException) {
                $section?->clear();

                return $formatter->error($runtimeException->getMessage());
            }

            $section?->clear();
        }

        $process = $this->dockerManager->createInteractiveExecProcess($containerName, ['claude'], DockerComposeManager::CLAUDE_AGENT_USER);
        $process->run();

        return $process->getExitCode() ?? self::SUCCESS;
    }
}
