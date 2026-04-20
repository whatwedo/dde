<?php

declare(strict_types=1);

namespace App\Command\System;

use App\Command\AbstractSystemCommand;
use App\Manager\SystemLifecycleManager;
use App\Model\SystemLifecycleProgress;
use App\Output\FormatterResolver;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\LogicException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'system:update',
    description: 'Rebuild global service images and refresh integrations',
)]
final class SystemUpdateCommand extends AbstractSystemCommand
{
    public function __construct(
        private readonly SystemLifecycleManager $manager,
        FormatterResolver $formatterResolver,
    ) {
        parent::__construct($formatterResolver);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = $this->resolveFormatter($output, $input);
        $io = new SymfonyStyle($input, $output);

        if ($formatter->isInteractive()) {
            $io->title('dde System Update');
        }

        $application = $this->getApplication();

        if (! $application instanceof Application) {
            throw new LogicException('SystemUpdateCommand requires a Console Application to install completion and AI agent integrations.');
        }

        $result = $this->manager->update(
            $application,
            $formatter->isInteractive()
                ? function (SystemLifecycleProgress $event, string $name, ?string $container, ?string $detail) use ($io): void {
                    match ($event) {
                        SystemLifecycleProgress::Removing => $io->write(sprintf(
                            '  Removing <info>%s</info>%s... ',
                            $name,
                            $container !== null && $container !== $name ? sprintf(' (%s)', $container) : '',
                        )),
                        SystemLifecycleProgress::Removed => $io->writeln('<info>done</info>'),
                        SystemLifecycleProgress::Building => $io->write(sprintf(
                            '  Building <info>%s</info> image... ',
                            $name,
                        )),
                        SystemLifecycleProgress::Built => $io->writeln('<info>done</info>'),
                        SystemLifecycleProgress::Starting => $io->write(sprintf(
                            '  Starting <info>%s</info>%s... ',
                            $name,
                            $container !== null && $container !== $name ? sprintf(' (%s)', $container) : '',
                        )),
                        SystemLifecycleProgress::Started => $io->writeln('<info>done</info>'),
                        SystemLifecycleProgress::AlreadyRunning => $io->writeln('<comment>already running</comment>'),
                        SystemLifecycleProgress::PostInstallStarting => $io->write(sprintf(
                            '  Refreshing <info>%s</info>... ',
                            $name,
                        )),
                        SystemLifecycleProgress::PostInstallOk => $io->writeln('<info>done</info>'),
                        SystemLifecycleProgress::PostInstallFailed => $io->writeln(sprintf(
                            '<error>failed</error>%s',
                            $detail !== null ? sprintf(': %s', $detail) : '',
                        )),
                        default => null,
                    };
                }
            : null,
        );

        if (! $formatter->isInteractive()) {
            return $formatter->success($result);
        }

        if ($result['postInstallWarnings'] !== []) {
            $io->newLine();

            foreach ($result['postInstallWarnings'] as $warning) {
                $io->warning($warning);
            }
        }

        $io->newLine();
        $io->success('dde system updated.');

        return Command::SUCCESS;
    }
}
