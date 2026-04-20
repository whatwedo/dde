<?php

declare(strict_types=1);

namespace App\Command\System;

use App\Command\AbstractSystemCommand;
use App\Manager\SystemLifecycleManager;
use App\Model\SystemLifecycleProgress;
use App\Output\FormatterResolver;
use App\Service\SshAgentService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'system:up',
    description: 'Start all global dde services',
)]
final class SystemUpCommand extends AbstractSystemCommand
{
    public function __construct(
        private readonly SystemLifecycleManager $manager,
        private readonly SshAgentService $sshAgentService,
        FormatterResolver $formatterResolver,
    ) {
        parent::__construct($formatterResolver);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = $this->resolveFormatter($output, $input);
        $io = new SymfonyStyle($input, $output);

        $result = $this->manager->up(
            $formatter->isInteractive()
                ? function (SystemLifecycleProgress $event, string $name, ?string $container) use ($io): void {
                    match ($event) {
                        SystemLifecycleProgress::Starting => $io->write(sprintf(
                            '  Starting <info>%s</info>%s... ',
                            $name,
                            $container !== null && $container !== $name ? sprintf(' (%s)', $container) : '',
                        )),
                        SystemLifecycleProgress::Started => $io->writeln('<info>done</info>'),
                        SystemLifecycleProgress::AlreadyRunning => $io->writeln('<comment>already running</comment>'),
                        default => null,
                    };
                }
            : null,
        );

        if ($input->isInteractive() && Process::isTtySupported() && $this->sshAgentService->isRunning() && $this->sshAgentService->getLoadedKeyCount() === 0) {
            $keys = $this->sshAgentService->getConfiguredKeys();

            if ($keys !== []) {
                $io->newLine();
                $io->writeln(sprintf('  Adding <info>%d</info> SSH key(s) to agent...', count($keys)));
                $this->sshAgentService->addKeys();
                $io->writeln(sprintf('  <info>%d</info> key(s) loaded.', $this->sshAgentService->getLoadedKeyCount()));
            }
        }

        if (! $formatter->isInteractive()) {
            return $formatter->success([
                'services' => $result['globalServices'],
            ]);
        }

        $io->newLine();
        $io->success('All global services started.');

        return Command::SUCCESS;
    }
}
