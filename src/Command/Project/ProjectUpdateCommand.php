<?php

declare(strict_types=1);

namespace App\Command\Project;

use App\Command\AbstractProjectCommand;
use App\Event\ProjectDownPostEvent;
use App\Event\ProjectDownPreEvent;
use App\Event\ProjectUpPostEvent;
use App\Event\ProjectUpPreEvent;
use App\Exception\HookFailedException;
use App\Manager\DockerComposeManager;
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
    name: 'project:update',
    description: 'Pull latest images, rebuild and restart containers',
    aliases: ['update'],
)]
final class ProjectUpdateCommand extends AbstractProjectCommand
{
    public function __construct(
        ProjectConfigManager $configManager,
        private readonly DockerComposeManager $dockerComposeManager,
        private readonly ProjectLifecycleManager $lifecycleManager,
        private readonly EventDispatcherInterface $eventDispatcher,
        FormatterResolver $formatterResolver,
    ) {
        parent::__construct($configManager, $formatterResolver);
    }

    protected function configure(): void
    {
        $this->addOption('skip-hooks', null, InputOption::VALUE_NONE, 'Skip hook execution');
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
            $io->writeln(sprintf('Updating project <info>%s</info>...', $config->projectName));
        }

        // 1. Pre-down hooks
        try {
            $this->eventDispatcher->dispatch(new ProjectDownPreEvent($config, $projectDir, $skipHooks));
        } catch (HookFailedException $hookFailedException) {
            return $formatter->error($hookFailedException->getMessage());
        }

        // 2. Stop and remove containers + volumes
        if ($formatter->isInteractive()) {
            $io->write('  Stopping containers... ');
        }

        $this->lifecycleManager->down($config, $projectDir, removeOrphans: true);

        if ($formatter->isInteractive()) {
            $io->writeln('<info>done</info>');
        }

        // 3. Post-down hooks
        try {
            $this->eventDispatcher->dispatch(new ProjectDownPostEvent($config, $projectDir, $skipHooks));
        } catch (HookFailedException $hookFailedException) {
            if ($formatter->isInteractive()) {
                $io->warning($hookFailedException->getMessage());
            }
        }

        // 4. Pull latest images
        if ($formatter->isInteractive()) {
            $io->writeln('  Pulling latest images...');
        }

        $pullSection = $formatter->isInteractive() && $output instanceof ConsoleOutputInterface && $output->isDecorated()
            ? $output->section()
            : null;
        $this->dockerComposeManager->pull($projectDir, [], $pullSection);
        $pullSection?->clear();

        // 5. Pre-up hooks
        try {
            $this->eventDispatcher->dispatch(new ProjectUpPreEvent($config, $projectDir, $skipHooks));
        } catch (HookFailedException $hookFailedException) {
            return $formatter->error($hookFailedException->getMessage());
        }

        // 6. Start everything (services, certs, build, up)
        if ($formatter->isInteractive()) {
            $io->writeln('  Starting containers...');
        }

        $upSection = $formatter->isInteractive() && $output instanceof ConsoleOutputInterface && $output->isDecorated()
            ? $output->section()
            : null;
        $result = $this->lifecycleManager->up(
            $config,
            $projectDir,
            build: true,
            output: $upSection,
        );
        $upSection?->clear();

        // 7. Post-up hooks
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
                'status' => 'updated',
                'services' => $result['serviceResults'],
                'domains' => $result['domains'],
                'sshForwardingWarning' => $result['sshForwardingWarning'],
            ]);
        }

        if ($result['sshForwardingWarning'] !== null) {
            $io->warning($result['sshForwardingWarning']);
        }

        $io->newLine();
        $io->success(sprintf('Project %s updated.', $config->projectName));

        $this->renderDomains($io, $result['domains']);

        return self::SUCCESS;
    }

    /**
     * @param list<string> $domains
     */
    private function renderDomains(SymfonyStyle $io, array $domains): void
    {
        if ($domains === []) {
            return;
        }

        $io->writeln('Available at:');

        foreach ($domains as $domain) {
            $io->writeln(sprintf('  <info>https://%s</info>', $domain));
        }

        $io->newLine();
    }
}
