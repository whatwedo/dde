<?php

declare(strict_types=1);

namespace App\Command\System;

use App\Command\AbstractSystemCommand;
use App\Manager\SystemLifecycleManager;
use App\Model\SystemLifecycleProgress;
use App\Output\FormatterResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'system:down',
    description: 'Stop and remove all dde containers',
)]
final class SystemDownCommand extends AbstractSystemCommand
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

        $result = $this->manager->down(
            $formatter->isInteractive()
                ? function (SystemLifecycleProgress $event, string $name, ?string $container) use ($io): void {
                    match ($event) {
                        SystemLifecycleProgress::Removing => $io->write(sprintf(
                            '  Removing <info>%s</info>%s... ',
                            $name,
                            $container !== null && $container !== $name ? sprintf(' (%s)', $container) : '',
                        )),
                        SystemLifecycleProgress::Removed => $io->writeln('<info>done</info>'),
                        default => null,
                    };
                }
            : null,
        );

        if (! $formatter->isInteractive()) {
            return $formatter->success($result);
        }

        $io->newLine();
        $io->success('All dde containers removed.');

        return Command::SUCCESS;
    }
}
