<?php

declare(strict_types=1);

namespace App\Command\Project;

use App\Command\AbstractProjectCommand;
use App\Manager\DockerComposeManager;
use App\Manager\ProjectConfigManager;
use App\Output\FormatterResolver;
use App\Util\ShellDetectorUtil;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'project:shell',
    description: 'Open an interactive shell in a project container',
    aliases: ['shell'],
)]
final class ProjectShellCommand extends AbstractProjectCommand
{
    public function __construct(
        ProjectConfigManager $configManager,
        private readonly DockerComposeManager $dockerComposeManager,
        FormatterResolver $formatterResolver,
        private readonly ShellDetectorUtil $shellDetector,
    ) {
        parent::__construct($configManager, $formatterResolver);
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestOptionValuesFor('service')) {
            try {
                $config = $this->getResolvedConfig();
                $suggestions->suggestValues(array_keys($config->containers));
            } catch (\Throwable) {
                // gracefully return empty suggestions
            }
        }
    }

    protected function configure(): void
    {
        $this
            ->addOption('service', 's', InputOption::VALUE_REQUIRED, 'Service to open shell in')
            ->addOption('root', null, InputOption::VALUE_NONE, 'Open shell as root user');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = $this->resolveFormatter($output, $input);

        if (!$formatter->isInteractive()) {
            return $formatter->error('The "--output=json" option is not supported for interactive commands.');
        }

        try {
            $projectDir = $this->getProjectDirectory();
        } catch (\RuntimeException $runtimeException) {
            return $formatter->error($runtimeException->getMessage());
        }

        $config = $this->getResolvedConfig();
        $service = $input->getOption('service');
        $service = is_string($service) ? $service : $this->getDefaultService($config, $this->dockerComposeManager->discoverServiceNames($projectDir));

        $isRoot = (bool) $input->getOption('root');

        $shell = $this->shellDetector->detect($config, $service, $projectDir);
        $process = $this->dockerComposeManager->exec($projectDir, $service, [$shell], [
            'user' => $isRoot ? 'root' : 'dde',
            'interactive' => true,
        ]);
        $process->run();

        return $process->getExitCode() ?? self::SUCCESS;
    }
}
