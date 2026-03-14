<?php

declare(strict_types=1);

namespace Tests\Unit\Event;

use App\Config\GlobalConfig;
use App\Config\ProjectConfig;
use App\Config\ResolvedConfig;
use App\Event\AbstractProjectEvent;
use App\Event\ProjectDownPostEvent;
use App\Event\ProjectDownPreEvent;
use App\Event\ProjectUpPostEvent;
use App\Event\ProjectUpPreEvent;
use PHPUnit\Framework\TestCase;

final class ProjectEventTest extends TestCase
{
    private ResolvedConfig $config;

    public function testUpPreEventCarriesConfigAndProjectDir(): void
    {
        $event = new ProjectUpPreEvent($this->config, '/tmp/project');

        $this->assertSame($this->config, $event->config);
        $this->assertSame('/tmp/project', $event->projectDir);
        $this->assertFalse($event->skipHooks);
    }

    public function testUpPostEventCarriesConfigAndProjectDir(): void
    {
        $event = new ProjectUpPostEvent($this->config, '/tmp/project');

        $this->assertSame($this->config, $event->config);
        $this->assertSame('/tmp/project', $event->projectDir);
        $this->assertFalse($event->skipHooks);
    }

    public function testDownPreEventCarriesConfigAndProjectDir(): void
    {
        $event = new ProjectDownPreEvent($this->config, '/tmp/project');

        $this->assertSame($this->config, $event->config);
        $this->assertSame('/tmp/project', $event->projectDir);
        $this->assertFalse($event->skipHooks);
    }

    public function testDownPostEventCarriesConfigAndProjectDir(): void
    {
        $event = new ProjectDownPostEvent($this->config, '/tmp/project');

        $this->assertSame($this->config, $event->config);
        $this->assertSame('/tmp/project', $event->projectDir);
        $this->assertFalse($event->skipHooks);
    }

    public function testSkipHooksDefaultsToFalse(): void
    {
        $event = new ProjectUpPreEvent($this->config, '/tmp/project');

        $this->assertFalse($event->skipHooks);
    }

    public function testSkipHooksCanBeSetToTrue(): void
    {
        $event = new ProjectUpPreEvent($this->config, '/tmp/project', true);

        $this->assertTrue($event->skipHooks);
    }

    public function testAllEventsExtendAbstractProjectEvent(): void
    {
        $this->assertInstanceOf(AbstractProjectEvent::class, new ProjectUpPreEvent($this->config, '/tmp'));
        $this->assertInstanceOf(AbstractProjectEvent::class, new ProjectUpPostEvent($this->config, '/tmp'));
        $this->assertInstanceOf(AbstractProjectEvent::class, new ProjectDownPreEvent($this->config, '/tmp'));
        $this->assertInstanceOf(AbstractProjectEvent::class, new ProjectDownPostEvent($this->config, '/tmp'));
    }

    protected function setUp(): void
    {
        $this->config = ResolvedConfig::merge(new GlobalConfig(), new ProjectConfig(name: 'test'));
    }
}
