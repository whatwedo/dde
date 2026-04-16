<?php

declare(strict_types=1);

namespace App\Command\Project\Database;

use App\Command\AbstractDatabaseCommand;
use App\Manager\DatabaseManager;
use App\Manager\ProjectConfigManager;
use App\Output\FormatterResolver;
use App\Service\ServiceRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'project:db',
    description: 'Open an interactive database shell',
)]
final class DbCommand extends AbstractDatabaseCommand
{
    public function __construct(
        ProjectConfigManager $configManager,
        FormatterResolver $formatterResolver,
        private readonly DatabaseManager $databaseManager,
        private readonly ServiceRegistry $serviceRegistry,
    ) {
        parent::__construct($configManager, $formatterResolver);
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestOptionValuesFor('service')) {
            try {
                $config = $this->getResolvedConfig();
                $dbServices = [];

                foreach ($config->services as $svc) {
                    if ($this->serviceRegistry->isDatabaseService($svc->name)) {
                        $dbServices[] = $svc->name;
                    }
                }

                $suggestions->suggestValues($dbServices);
            } catch (\Throwable) {
                // gracefully return empty suggestions
            }
        }
    }

    protected function configure(): void
    {
        $this
            ->addOption('service', 's', InputOption::VALUE_REQUIRED, 'Database service name (default: first configured DB service)')
            ->addOption('database', 'd', InputOption::VALUE_REQUIRED, 'Database name (default: project name)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = $this->resolveFormatter($output, $input);

        if (!$formatter->isInteractive()) {
            return $formatter->error('Interactive shell is not supported with --output=json');
        }

        try {
            $config = $this->getResolvedConfig();
        } catch (\RuntimeException $runtimeException) {
            return $formatter->error($runtimeException->getMessage());
        }

        try {
            $serviceDefinition = $this->resolveDbService($input, $config, $this->serviceRegistry);
        } catch (\RuntimeException $runtimeException) {
            return $formatter->error($runtimeException->getMessage());
        }

        $containerName = $this->databaseManager->resolveContainerName($serviceDefinition);

        if (! $this->databaseManager->isContainerRunning($serviceDefinition)) {
            return $formatter->error(sprintf('Container "%s" is not running.', $containerName));
        }

        $database = $this->resolveDatabase($input, $config, $serviceDefinition->name);
        $output->writeln(sprintf('Opening database shell in container <info>%s</info>...', $containerName));

        $this->databaseManager->execInteractiveShell($serviceDefinition, $database);

        return self::SUCCESS;
    }
}
