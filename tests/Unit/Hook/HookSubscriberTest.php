<?php

declare(strict_types=1);

namespace Tests\Unit\Hook;

use App\Config\GlobalConfig;
use App\Config\ProjectConfig;
use App\Config\ResolvedConfig;
use App\Event\ProjectDownPostEvent;
use App\Event\ProjectDownPreEvent;
use App\Event\ProjectUpPostEvent;
use App\Event\ProjectUpPreEvent;
use App\Hook\HookRunner;
use App\Hook\HookSubscriber;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class HookSubscriberTest extends TestCase
{
    private HookRunner&MockObject $hookRunner;

    private HookSubscriber $subscriber;

    private ResolvedConfig $config;

    public function testOnProjectUpPreRunsHooks(): void
    {
        $event = new ProjectUpPreEvent($this->config, '/tmp/project');

        $this->hookRunner
            ->expects($this->once())
            ->method('run')
            ->with('/tmp/project/.dde/hooks/project.up.pre', '/tmp/project');

        $this->subscriber->onProjectUpPre($event);
    }

    public function testOnProjectUpPostRunsHooks(): void
    {
        $event = new ProjectUpPostEvent($this->config, '/tmp/project');

        $this->hookRunner
            ->expects($this->once())
            ->method('run')
            ->with('/tmp/project/.dde/hooks/project.up.post', '/tmp/project');

        $this->subscriber->onProjectUpPost($event);
    }

    public function testOnProjectDownPreRunsHooks(): void
    {
        $event = new ProjectDownPreEvent($this->config, '/tmp/project');

        $this->hookRunner
            ->expects($this->once())
            ->method('run')
            ->with('/tmp/project/.dde/hooks/project.down.pre', '/tmp/project');

        $this->subscriber->onProjectDownPre($event);
    }

    public function testOnProjectDownPostRunsHooks(): void
    {
        $event = new ProjectDownPostEvent($this->config, '/tmp/project');

        $this->hookRunner
            ->expects($this->once())
            ->method('run')
            ->with('/tmp/project/.dde/hooks/project.down.post', '/tmp/project');

        $this->subscriber->onProjectDownPost($event);
    }

    public function testSkipHooksPreventsExecution(): void
    {
        $event = new ProjectUpPreEvent($this->config, '/tmp/project', true);

        $this->hookRunner
            ->expects($this->never())
            ->method('run');

        $this->subscriber->onProjectUpPre($event);
    }

    public function testSkipHooksPreventsExecutionForAllEventTypes(): void
    {
        $this->hookRunner
            ->expects($this->never())
            ->method('run');

        $this->subscriber->onProjectUpPre(new ProjectUpPreEvent($this->config, '/tmp', true));
        $this->subscriber->onProjectUpPost(new ProjectUpPostEvent($this->config, '/tmp', true));
        $this->subscriber->onProjectDownPre(new ProjectDownPreEvent($this->config, '/tmp', true));
        $this->subscriber->onProjectDownPost(new ProjectDownPostEvent($this->config, '/tmp', true));
    }

    protected function setUp(): void
    {
        $this->hookRunner = $this->createMock(HookRunner::class);
        $this->subscriber = new HookSubscriber($this->hookRunner);
        $this->config = ResolvedConfig::merge(new GlobalConfig(), new ProjectConfig(name: 'test'));
    }
}
