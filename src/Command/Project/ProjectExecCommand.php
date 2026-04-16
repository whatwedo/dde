<?php

declare(strict_types=1);

namespace App\Command\Project;

use App\Command\AbstractProjectCommand;
use App\Manager\DockerComposeManager;
use App\Manager\ProjectConfigManager;
use App\Output\FormatterResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'project:exec',
    description: 'Execute a command in a project container',
    aliases: ['exec'],
)]
final class ProjectExecCommand extends AbstractProjectCommand
{
    public function __construct(
        ProjectConfigManager $configManager,
        private readonly DockerComposeManager $dockerComposeManager,
        FormatterResolver $formatterResolver,
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
            ->addArgument('cmd', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Command to execute')
            ->addOption('service', 's', InputOption::VALUE_REQUIRED, 'Service to exec into')
            ->addOption('root', null, InputOption::VALUE_NONE, 'Execute as root user');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = $this->resolveFormatter($output, $input);

        try {
            $projectDir = $this->getProjectDirectory();
        } catch (\RuntimeException $runtimeException) {
            return $formatter->error($runtimeException->getMessage());
        }

        $config = $this->getResolvedConfig();
        $service = $input->getOption('service');
        $service = is_string($service) ? $service : $this->getDefaultService($config, $this->dockerComposeManager->discoverServiceNames($projectDir));
        /** @var list<string> $cmd */
        $cmd = $input->getArgument('cmd');
        $isRoot = (bool) $input->getOption('root');

        $process = $this->dockerComposeManager->exec($projectDir, $service, $cmd, [
            'user' => $isRoot ? 'root' : 'dde',
            'noTty' => true,
        ]);
        $process->run(function (string $type, string $buffer) use ($output): void {
            $output->write($buffer);
        });

        return $process->getExitCode() ?? self::SUCCESS;
    }
}
