<?php

declare(strict_types=1);

namespace App\Command\Project\Database;

use App\Command\AbstractDatabaseCommand;
use App\Manager\ConfigManager;
use App\Manager\DatabaseManager;
use App\Output\FormatterResolver;
use App\Service\ServiceRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

#[AsCommand(
    name: 'project:db:import',
    description: 'Import a SQL file into the database',
)]
final class DbImportCommand extends AbstractDatabaseCommand
{
    public function __construct(
        ConfigManager $configManager,
        FormatterResolver $formatterResolver,
        private readonly DatabaseManager $databaseManager,
        private readonly ServiceRegistry $serviceRegistry,
    ) {
        parent::__construct($configManager, $formatterResolver);
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestArgumentValuesFor('file')) {
            try {
                $cwd = getcwd();

                if ($cwd === false) {
                    return;
                }

                $finder = new Finder();
                $finder->files()->name('*.sql')->in($cwd)->depth('< 3');

                $files = [];

                foreach ($finder as $file) {
                    $files[] = $file->getRelativePathname();
                }

                $suggestions->suggestValues($files);
            } catch (\Throwable) {
                // gracefully return empty suggestions
            }
        }

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
            ->addArgument('file', InputArgument::REQUIRED, 'Path on host to the SQL file to import')
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

        if (! file_exists($filePath)) {
            return $formatter->error(sprintf('File "%s" not found.', $filePath));
        }

        $fileHandle = fopen($filePath, 'r');

        if ($fileHandle === false) {
            return $formatter->error(sprintf('Failed to open file "%s".', $filePath));
        }

        if ($formatter->isInteractive()) {
            $output->writeln(sprintf('Importing <info>%s</info> into container <info>%s</info>...', $filePath, $containerName));
        }

        $database = $this->resolveDatabase($input, $config, $serviceDefinition->name);

        try {
            $this->databaseManager->importDump($serviceDefinition, $database, $fileHandle);
        } catch (\RuntimeException $runtimeException) {
            fclose($fileHandle);

            return $formatter->error(sprintf('Import failed: %s', $runtimeException->getMessage()));
        }

        fclose($fileHandle);

        if (!$formatter->isInteractive()) {
            return $formatter->success([
                'status' => 'ok',
                'file' => $filePath,
            ]);
        }

        $output->writeln('Import completed successfully.');

        return self::SUCCESS;
    }
}
