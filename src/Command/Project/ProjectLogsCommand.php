<?php

declare(strict_types=1);

namespace App\Command\Project;

use App\Command\AbstractProjectCommand;
use App\Manager\ConfigManager;
use App\Manager\DockerComposeManager;
use App\Output\FormatterResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'project:logs',
    description: 'Show project container logs',
    aliases: ['logs'],
)]
final class ProjectLogsCommand extends AbstractProjectCommand
{
    public function __construct(
        ConfigManager $configManager,
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
            ->addOption('service', 's', InputOption::VALUE_REQUIRED, 'Service to show logs for')
            ->addOption('follow', 'f', InputOption::VALUE_NONE, 'Follow log output')
            ->addOption('no-follow', null, InputOption::VALUE_NONE, 'Do not follow log output')
            ->addOption('tail', null, InputOption::VALUE_REQUIRED, 'Number of lines from end', 'all');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = $this->resolveFormatter($output, $input);

        try {
            $projectDir = $this->getProjectDirectory();
        } catch (\RuntimeException $runtimeException) {
            return $formatter->error($runtimeException->getMessage());
        }

        $service = $input->getOption('service');
        $follow = (bool) $input->getOption('follow');
        $noFollow = (bool) $input->getOption('no-follow');
        $tail = $input->getOption('tail');

        if ($noFollow) {
            $follow = false;
        }

        if (!$formatter->isInteractive() && $follow) {
            return $formatter->error('The "--output=json" option is not supported for interactive/streaming commands.');
        }

        $logOptions = [
            'follow' => $follow,
        ];

        if (is_string($tail) && $tail !== 'all') {
            $logOptions['tail'] = $tail;
        }

        $process = $this->dockerComposeManager->logs($projectDir, is_string($service) ? $service : '', $logOptions);

        $process->run(function (string $type, string $buffer) use ($output): void {
            $output->write($buffer);
        });

        return $process->getExitCode() ?? self::SUCCESS;
    }
}
