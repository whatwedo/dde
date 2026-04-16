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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(
    name: 'project:db:export',
    description: 'Export the database to a SQL file',
)]
final class DbExportCommand extends AbstractDatabaseCommand
{
    public function __construct(
        ProjectConfigManager $configManager,
        FormatterResolver $formatterResolver,
        private readonly DatabaseManager $databaseManager,
        private readonly ServiceRegistry $serviceRegistry,
        private readonly Filesystem $filesystem,
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
            ->addArgument('file', InputArgument::REQUIRED, 'Path on host where to save the SQL dump')
            ->addOption('service', 's', InputOption::VALUE_REQUIRED, 'Database service name (default: first configured DB service)')
            ->addOption('database', 'd', InputOption::VALUE_REQUIRED, 'Database name (default: project name)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = $this->resolveFormatter($output, $input);

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

        $filePath = $input->getArgument('file');

        if (! is_string($filePath) || $filePath === '') {
            return $formatter->error('No file path provided.');
        }

        if ($formatter->isInteractive()) {
            $output->writeln(sprintf('Exporting database from container <info>%s</info>...', $containerName));
        }

        $database = $this->resolveDatabase($input, $config, $serviceDefinition->name);

        try {
            $sqlContent = $this->databaseManager->exportDump($serviceDefinition, $database);
        } catch (\RuntimeException $runtimeException) {
            return $formatter->error(sprintf('Export failed: %s', $runtimeException->getMessage()));
        }

        $this->filesystem->dumpFile($filePath, $sqlContent);
        $bytesWritten = strlen($sqlContent);

        if (!$formatter->isInteractive()) {
            return $formatter->success([
                'status' => 'ok',
                'file' => $filePath,
                'size' => $bytesWritten,
            ]);
        }

        $output->writeln(sprintf('Export saved to <info>%s</info> (%d bytes).', $filePath, $bytesWritten));

        return self::SUCCESS;
    }
}
