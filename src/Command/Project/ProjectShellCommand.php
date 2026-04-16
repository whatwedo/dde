<?php

declare(strict_types=1);

namespace App\Command\Project;

use App\Command\AbstractProjectCommand;
use App\Manager\DockerComposeManager;
use App\Manager\ProjectConfigManager;
use App\Manager\ProjectLifecycleManager;
use App\Output\FormatterResolver;
use App\Util\ShellDetectorUtil;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

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
        private readonly ProjectLifecycleManager $lifecycleManager,
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

        // Ensure global system services (Traefik, SSH-Agent, etc.) are running
        $this->lifecycleManager->ensureGlobalServices();

        if (!$this->isServiceRunning($projectDir, $service)) {
            $io = new SymfonyStyle($input, $output);
            $io->writeln(sprintf('Container <info>%s</info> is not running. Starting project <info>%s</info>...', $service, $config->projectName));

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

        $isRoot = (bool) $input->getOption('root');

        $shell = $this->shellDetector->detect($config, $service, $projectDir);
        $process = $this->dockerComposeManager->exec($projectDir, $service, [$shell], [
            'user' => $isRoot ? 'root' : 'dde',
            'interactive' => true,
        ]);
        $process->run();

        return $process->getExitCode() ?? self::SUCCESS;
    }

    private function isServiceRunning(string $projectDir, string $service): bool
    {
        try {
            $containers = $this->dockerComposeManager->ps($projectDir);
        } catch (\RuntimeException) {
            return false;
        }

        foreach ($containers as $container) {
            $name = $container['Service'] ?? $container['service'] ?? '';
            $state = $container['State'] ?? $container['state'] ?? '';

            if ($name === $service && $state === 'running') {
                return true;
            }
        }

        return false;
    }
}
