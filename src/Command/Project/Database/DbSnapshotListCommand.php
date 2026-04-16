<?php

declare(strict_types=1);

namespace App\Command\Project\Database;

use App\Command\AbstractDatabaseCommand;
use App\Manager\ProjectConfigManager;
use App\Output\FormatterResolver;
use App\Service\ServiceRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

#[AsCommand(
    name: 'project:db:snapshot:list',
    description: 'List database snapshots',
)]
final class DbSnapshotListCommand extends AbstractDatabaseCommand
{
    public function __construct(
        ProjectConfigManager $configManager,
        private readonly ServiceRegistry $serviceRegistry,
        private readonly Filesystem $filesystem,
        FormatterResolver $formatterResolver,
    ) {
        parent::__construct($configManager, $formatterResolver);
    }

    protected function configure(): void
    {
        $this
            ->addOption('service', 's', InputOption::VALUE_REQUIRED, 'Database service name (default: first configured DB service)');
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

        $snapshotDir = sprintf('%s/.dde/snapshots/%s', $projectDir, $serviceDefinition->name);

        if (! $this->filesystem->exists($snapshotDir)) {
            if (!$formatter->isInteractive()) {
                return $formatter->success([
                    'snapshots' => [],
                ]);
            }

            $output->writeln('No snapshots found.');

            return self::SUCCESS;
        }

        $finder = new Finder();
        $finder->files()->name('*.sql')->in($snapshotDir)->sortByModifiedTime()->reverseSorting();

        if (! $finder->hasResults()) {
            if (!$formatter->isInteractive()) {
                return $formatter->success([
                    'snapshots' => [],
                ]);
            }

            $output->writeln('No snapshots found.');

            return self::SUCCESS;
        }

        $snapshots = [];

        foreach ($finder as $file) {
            $snapshots[] = [
                'name' => $file->getFilenameWithoutExtension(),
                'file' => $file->getRealPath(),
                'size' => $file->getSize(),
                'modified' => (new \DateTimeImmutable('@'.$file->getMTime()))->format(\DateTimeInterface::ATOM),
            ];
        }

        if (!$formatter->isInteractive()) {
            return $formatter->success([
                'snapshots' => $snapshots,
            ]);
        }

        $output->writeln(sprintf('<info>%d snapshot(s) found:</info>', count($snapshots)));
        $output->writeln('');

        $output->writeln(sprintf('%-40s %10s  %s', 'Name', 'Size', 'Date'));
        $output->writeln(str_repeat('-', 70));

        foreach ($snapshots as $snapshot) {
            $output->writeln(sprintf(
                '%-40s %10d  %s',
                $snapshot['name'],
                $snapshot['size'],
                $snapshot['modified'],
            ));
        }

        return self::SUCCESS;
    }
}
