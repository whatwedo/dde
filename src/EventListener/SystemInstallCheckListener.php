<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Command\AbstractBaseCommand;
use App\Command\System\SystemInstallCommand;
use App\Manager\DockerManager;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: ConsoleEvents::COMMAND, priority: 50)]
final readonly class SystemInstallCheckListener
{
    public function __construct(
        private DockerManager $dockerManager,
    ) {
    }

    public function __invoke(ConsoleCommandEvent $event): void
    {
        $command = $event->getCommand();

        if (!$command instanceof AbstractBaseCommand) {
            return;
        }

        if ($command instanceof SystemInstallCommand) {
            return;
        }

        if ($this->dockerManager->networkExists('dde')) {
            return;
        }

        $io = new SymfonyStyle($event->getInput(), $event->getOutput());
        $io->error('dde is not initialized. Run "dde system:install" to set up the system.');

        $event->disableCommand();
    }
}
