<?php

declare(strict_types=1);

namespace App\Command\Project\Database;

use App\Command\AbstractDatabaseCommand;
use App\Manager\ConfigManager;
use App\Manager\DatabaseManager;
use App\Output\FormatterResolver;
use App\Service\ServiceRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(
    name: 'project:db:snapshot:create',
    description: 'Create a database snapshot',
)]
final class DbSnapshotCreateCommand extends AbstractDatabaseCommand
{
    public function __construct(
        ConfigManager $configManager,
        private readonly DatabaseManager $databaseManager,
        private readonly ServiceRegistry $serviceRegistry,
        private readonly Filesystem $filesystem,
        private readonly ClockInterface $clock,
        FormatterResolver $formatterResolver,
    ) {
        parent::__construct($configManager, $formatterResolver);
    }

    protected function configure(): void
    {
        $this
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Snapshot name (default: snapshot-{YYYYMMDD-HHiiss})')
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

        $nameOption = $input->getOption('name');
        $snapshotName = is_string($nameOption) && $nameOption !== ''
            ? $nameOption
            : 'snapshot-'.$this->clock->now()->format('Ymd-His');

        if (preg_match('/[^a-zA-Z0-9_\-.]/', $snapshotName) === 1) {
            return $formatter->error('Invalid snapshot name. Only alphanumeric characters, hyphens, underscores, and dots are allowed.');
        }

        $snapshotDir = sprintf('%s/.dde/snapshots/%s', $projectDir, $serviceDefinition->name);
        $this->filesystem->mkdir($snapshotDir);

        $filePath = $snapshotDir.'/'.$snapshotName.'.sql';

        if ($formatter->isInteractive()) {
            $output->writeln(sprintf('Creating snapshot from container <info>%s</info>...', $containerName));
        }

        $database = $this->resolveDatabase($input, $config, $serviceDefinition->name);

        try {
            $sqlContent = $this->databaseManager->exportDump($serviceDefinition, $database);
        } catch (\RuntimeException $runtimeException) {
            return $formatter->error(sprintf('Snapshot failed: %s', $runtimeException->getMessage()));
        }

        $this->filesystem->dumpFile($filePath, $sqlContent);
        $bytesWritten = strlen($sqlContent);

        if (!$formatter->isInteractive()) {
            return $formatter->success([
                'status' => 'ok',
                'name' => $snapshotName,
                'file' => $filePath,
                'size' => $bytesWritten,
                'service' => $serviceDefinition->name,
            ]);
        }

        $output->writeln(sprintf(
            'Snapshot <info>%s</info> created at <info>%s</info> (%d bytes).',
            $snapshotName,
            $filePath,
            $bytesWritten,
        ));

        return self::SUCCESS;
    }
}
