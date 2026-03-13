<?php

declare(strict_types=1);

namespace App\Command\Project;

use App\Command\AbstractProjectCommand;
use App\Event\ProjectDownPostEvent;
use App\Event\ProjectDownPreEvent;
use App\Exception\HookFailedException;
use App\Manager\ConfigManager;
use App\Manager\ProjectLifecycleManager;
use App\Output\FormatterResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[AsCommand(
    name: 'project:down',
    description: 'Stop and remove the project containers',
    aliases: ['down'],
)]
final class ProjectDownCommand extends AbstractProjectCommand
{
    public function __construct(
        ConfigManager $configManager,
        private readonly ProjectLifecycleManager $lifecycleManager,
        FormatterResolver $formatterResolver,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
        parent::__construct($configManager, $formatterResolver);
    }

    protected function configure(): void
    {
        $this
            ->addOption('skip-hooks', null, InputOption::VALUE_NONE, 'Skip hook execution')
            ->addOption('remove-orphans', null, InputOption::VALUE_NONE, 'Remove orphan containers');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = $this->resolveFormatter($output, $input);
        $io = new SymfonyStyle($input, $output);

        try {
            $projectDir = $this->getProjectDirectory();
        } catch (\RuntimeException $runtimeException) {
            return $formatter->error($runtimeException->getMessage());
        }

        $config = $this->getResolvedConfig();
        $skipHooks = (bool) $input->getOption('skip-hooks');

        // Pre-hooks
        try {
            $this->eventDispatcher->dispatch(new ProjectDownPreEvent($config, $projectDir, $skipHooks));
        } catch (HookFailedException $hookFailedException) {
            return $formatter->error($hookFailedException->getMessage());
        }

        if ($formatter->isInteractive()) {
            $io->writeln(sprintf('Stopping project <info>%s</info>...', $config->projectName));
        }

        // Lifecycle: down
        try {
            $this->lifecycleManager->down(
                $config,
                $projectDir,
                removeOrphans: (bool) $input->getOption('remove-orphans'),
            );
        } catch (\RuntimeException $runtimeException) {
            return $formatter->error($runtimeException->getMessage());
        }

        // Post-hooks
        try {
            $this->eventDispatcher->dispatch(new ProjectDownPostEvent($config, $projectDir, $skipHooks));
        } catch (HookFailedException $hookFailedException) {
            if ($formatter->isInteractive()) {
                $io->warning($hookFailedException->getMessage());
            }
        }

        if (!$formatter->isInteractive()) {
            return $formatter->success([
                'project' => $config->projectName,
                'status' => 'stopped',
            ]);
        }

        $io->newLine();
        $io->success(sprintf('Project %s stopped and removed.', $config->projectName));

        return self::SUCCESS;
    }
}
