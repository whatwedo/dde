<?php

declare(strict_types=1);

namespace App\Command\Project;

use App\Command\AbstractProjectCommand;
use App\Event\ProjectDownPostEvent;
use App\Event\ProjectDownPreEvent;
use App\Event\ProjectUpPostEvent;
use App\Event\ProjectUpPreEvent;
use App\Exception\HookFailedException;
use App\Manager\ProjectConfigManager;
use App\Manager\ProjectLifecycleManager;
use App\Output\FormatterResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[AsCommand(
    name: 'project:restart',
    description: 'Restart the project containers',
)]
final class ProjectRestartCommand extends AbstractProjectCommand
{
    public function __construct(
        ProjectConfigManager $configManager,
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
            ->addOption('build', null, InputOption::VALUE_NONE, 'Force rebuild images');
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

        if ($formatter->isInteractive()) {
            $io->writeln(sprintf('Restarting project <info>%s</info>...', $config->projectName));
        }

        // Down pre-hooks
        try {
            $this->eventDispatcher->dispatch(new ProjectDownPreEvent($config, $projectDir, $skipHooks));
        } catch (HookFailedException $hookFailedException) {
            return $formatter->error($hookFailedException->getMessage());
        }

        // Lifecycle: down (without volumes/orphans removal)
        $this->lifecycleManager->down($config, $projectDir);

        // Down post-hooks
        try {
            $this->eventDispatcher->dispatch(new ProjectDownPostEvent($config, $projectDir, $skipHooks));
        } catch (HookFailedException $hookFailedException) {
            if ($formatter->isInteractive()) {
                $io->warning($hookFailedException->getMessage());
            }
        }

        // Up pre-hooks
        try {
            $this->eventDispatcher->dispatch(new ProjectUpPreEvent($config, $projectDir, $skipHooks));
        } catch (HookFailedException $hookFailedException) {
            return $formatter->error($hookFailedException->getMessage());
        }

        // Lifecycle: up
        $section = $formatter->isInteractive() && $output instanceof ConsoleOutputInterface && $output->isDecorated()
            ? $output->section()
            : null;
        $result = $this->lifecycleManager->up(
            $config,
            $projectDir,
            (bool) $input->getOption('build'),
            output: $section,
        );
        $section?->clear();

        // Up post-hooks
        try {
            $this->eventDispatcher->dispatch(new ProjectUpPostEvent($config, $projectDir, $skipHooks));
        } catch (HookFailedException $hookFailedException) {
            if ($formatter->isInteractive()) {
                $io->warning($hookFailedException->getMessage());
            }
        }

        if (!$formatter->isInteractive()) {
            return $formatter->success([
                'project' => $config->projectName,
                'status' => 'restarted',
                'services' => $result['serviceResults'],
            ]);
        }

        $io->newLine();
        $io->success(sprintf('Project %s restarted.', $config->projectName));

        return self::SUCCESS;
    }
}
