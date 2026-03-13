<?php

declare(strict_types=1);

namespace App\Hook;

use App\Event\AbstractProjectEvent;
use App\Event\ProjectDownPostEvent;
use App\Event\ProjectDownPreEvent;
use App\Event\ProjectUpPostEvent;
use App\Event\ProjectUpPreEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final readonly class HookSubscriber
{
    public function __construct(
        private HookRunner $hookRunner,
    ) {
    }

    #[AsEventListener(event: ProjectUpPreEvent::class)]
    public function onProjectUpPre(ProjectUpPreEvent $event): void
    {
        $this->runHooks($event, 'project.up.pre');
    }

    #[AsEventListener(event: ProjectUpPostEvent::class)]
    public function onProjectUpPost(ProjectUpPostEvent $event): void
    {
        $this->runHooks($event, 'project.up.post');
    }

    #[AsEventListener(event: ProjectDownPreEvent::class)]
    public function onProjectDownPre(ProjectDownPreEvent $event): void
    {
        $this->runHooks($event, 'project.down.pre');
    }

    #[AsEventListener(event: ProjectDownPostEvent::class)]
    public function onProjectDownPost(ProjectDownPostEvent $event): void
    {
        $this->runHooks($event, 'project.down.post');
    }

    private function runHooks(AbstractProjectEvent $event, string $hookName): void
    {
        if ($event->skipHooks) {
            return;
        }

        $hookDir = $event->projectDir.'/.dde/hooks/'.$hookName;
        $this->hookRunner->run($hookDir, $event->projectDir);
    }
}
