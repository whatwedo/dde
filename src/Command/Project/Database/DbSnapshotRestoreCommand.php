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
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

#[AsCommand(
    name: 'project:db:snapshot:restore',
    description: 'Restore a database snapshot',
)]
final class DbSnapshotRestoreCommand extends AbstractDatabaseCommand
{
    public function __construct(
        ProjectConfigManager $configManager,
        private readonly DatabaseManager $databaseManager,
        private readonly ServiceRegistry $serviceRegistry,
        private readonly Filesystem $filesystem,
        FormatterResolver $formatterResolver,
    ) {
        parent::__construct($configManager, $formatterResolver);
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestArgumentValuesFor('name')) {
            try {
                $config = $this->getResolvedConfig();
                $dbService = null;

                foreach ($config->services as $svc) {
                    if ($this->serviceRegistry->isDatabaseService($svc->name)) {
                        $dbService = $svc;
                        break;
                    }
                }

                if ($dbService === null) {
                    return;
                }

                $snapshotDir = sprintf('%s/.dde/snapshots/%s', $this->getProjectDirectory(), $dbService->name);

                if (! is_dir($snapshotDir)) {
                    return;
                }

                $finder = new Finder();
                $finder->files()->name('*.sql')->in($snapshotDir);

                $names = [];

                foreach ($finder as $file) {
                    $names[] = $file->getFilenameWithoutExtension();
                }

                $suggestions->suggestValues($names);
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
            ->addArgument('name', InputArgument::OPTIONAL, 'Snapshot name to restore')
            ->addOption('service', 's', InputOption::VALUE_REQUIRED, 'Database service name (default: first configured DB service)')
            ->addOption('database', 'd', InputOption::VALUE_REQUIRED, 'Database name (default: project name)');
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

        try {
            $serviceDefinition = $this->resolveDbService($input, $config, $this->serviceRegistry);
        } catch (\RuntimeException $runtimeException) {
            return $formatter->error($runtimeException->getMessage());
        }

        $containerName = $this->databaseManager->resolveContainerName($serviceDefinition);

        if (! $this->databaseManager->isContainerRunning($serviceDefinition)) {
            return $formatter->error(sprintf('Container "%s" is not running.', $containerName));
        }

        $snapshotDir = sprintf('%s/.dde/snapshots/%s', $projectDir, $serviceDefinition->name);

        $nameArg = $input->getArgument('name');
        $snapshotName = is_string($nameArg) && $nameArg !== '' ? $nameArg : null;

        if ($snapshotName !== null && preg_match('/[^a-zA-Z0-9_\-.]/', $snapshotName) === 1) {
            return $formatter->error('Invalid snapshot name. Only alphanumeric characters, hyphens, underscores, and dots are allowed.');
        }

        if ($snapshotName !== null) {
            $filePath = $snapshotDir.'/'.$snapshotName.'.sql';

            if (! file_exists($filePath)) {
                return $formatter->error(sprintf("Snapshot '%s' not found.", $snapshotName));
            }
        } else {
            if (! $input->isInteractive()) {
                return $formatter->error('Snapshot name is required in non-interactive mode.');
            }

            if (! $this->filesystem->exists($snapshotDir)) {
                return $formatter->error('No snapshots found.');
            }

            $finder = new Finder();
            $finder->files()->name('*.sql')->in($snapshotDir)->sortByModifiedTime()->reverseSorting();

            if (! $finder->hasResults()) {
                return $formatter->error('No snapshots found.');
            }

            $names = [];

            foreach ($finder as $file) {
                $names[] = $file->getFilenameWithoutExtension();
            }

            $io = new SymfonyStyle($input, $output);
            $chosen = $io->choice('Select a snapshot to restore', $names);

            if (! is_string($chosen)) {
                return $formatter->error('No snapshot selected.');
            }

            $snapshotName = $chosen;
            $filePath = $snapshotDir.'/'.$snapshotName.'.sql';
        }

        if ($formatter->isInteractive()) {
            $output->writeln(sprintf(
                'Restoring snapshot <info>%s</info> into container <info>%s</info>...',
                $snapshotName,
                $containerName,
            ));
        }

        $fileHandle = fopen($filePath, 'r');

        if ($fileHandle === false) {
            return $formatter->error(sprintf('Failed to open snapshot file "%s".', $filePath));
        }

        $database = $this->resolveDatabase($input, $config, $serviceDefinition->name);

        try {
            $this->databaseManager->importDump($serviceDefinition, $database, $fileHandle);
        } catch (\RuntimeException $runtimeException) {
            fclose($fileHandle);

            return $formatter->error(sprintf('Restore failed: %s', $runtimeException->getMessage()));
        }

        fclose($fileHandle);

        if (!$formatter->isInteractive()) {
            return $formatter->success([
                'status' => 'ok',
                'name' => $snapshotName,
                'file' => $filePath,
                'service' => $serviceDefinition->name,
            ]);
        }

        $output->writeln(sprintf('Snapshot <info>%s</info> restored successfully.', $snapshotName));

        return self::SUCCESS;
    }
}
