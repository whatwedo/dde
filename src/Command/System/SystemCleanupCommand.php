<?php

declare(strict_types=1);

namespace App\Command\System;

use App\Command\AbstractSystemCommand;
use App\Manager\CleanupManager;
use App\Output\FormatterResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'system:cleanup',
    description: 'Clean up dde-managed Docker resources',
)]
final class SystemCleanupCommand extends AbstractSystemCommand
{
    public function __construct(
        private readonly CleanupManager $cleanupManager,
        FormatterResolver $formatterResolver,
    ) {
        parent::__construct($formatterResolver);
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only list items, do not delete')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Delete without confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = $this->resolveFormatter($output, $input);
        $isDryRun = (bool) $input->getOption('dry-run');
        $isForce = (bool) $input->getOption('force');
        $io = new SymfonyStyle($input, $output);

        $items = $this->cleanupManager->collectCleanupItems();

        if ($items === []) {
            if (!$formatter->isInteractive()) {
                return $formatter->success([
                    'items' => [],
                ]);
            }

            $io->success('Nothing to clean up.');

            return Command::SUCCESS;
        }

        if (!$formatter->isInteractive() && $isDryRun) {
            return $formatter->success([
                'dry_run' => true,
                'items' => $items,
            ]);
        }

        if ($formatter->isInteractive()) {
            $io->writeln(sprintf('Found <comment>%d</comment> item(s) to clean up:', count($items)));
            $io->newLine();

            foreach ($items as $item) {
                $io->writeln(sprintf('  [%s] %s', $item['type'], $item['name']));
            }

            $io->newLine();
        }

        if ($isDryRun) {
            $io->note('Dry run — nothing was deleted.');

            return Command::SUCCESS;
        }

        if (! $isForce && $input->isInteractive() && ! $io->confirm(sprintf('Delete %d item(s)?', count($items)), false)) {
            return Command::SUCCESS;
        }

        $deleted = [];

        foreach ($items as $item) {
            try {
                $this->cleanupManager->deleteItem($item);

                $deleted[] = $item;

                if ($formatter->isInteractive()) {
                    $io->writeln(sprintf('  Deleted [%s] %s', $item['type'], $item['name']));
                }
            } catch (\Throwable $e) {
                if ($formatter->isInteractive()) {
                    $io->writeln(sprintf('  <error>Failed to delete [%s] %s: %s</error>', $item['type'], $item['name'], $e->getMessage()));
                }
            }
        }

        if (!$formatter->isInteractive()) {
            return $formatter->success([
                'status' => 'ok',
                'deleted' => $deleted,
            ]);
        }

        $io->newLine();
        $io->success(sprintf('Cleaned up %d item(s).', count($deleted)));

        return Command::SUCCESS;
    }
}
