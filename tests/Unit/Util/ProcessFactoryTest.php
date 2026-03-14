<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use App\Util\ProcessFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ProcessFactoryTest extends TestCase
{
    private ProcessFactory $factory;

    public function testCreateReturnsProcess(): void
    {
        $process = $this->factory->create(['echo', 'hello']);

        $this->assertInstanceOf(Process::class, $process);
    }

    public function testCreateWithWorkingDirectory(): void
    {
        $cwd = sys_get_temp_dir();
        $process = $this->factory->create(['ls'], $cwd);

        $this->assertSame($cwd, $process->getWorkingDirectory());
    }

    public function testCreateWithTimeout(): void
    {
        $process = $this->factory->create(['echo', 'hello'], null, 120);

        $this->assertSame(120.0, $process->getTimeout());
    }

    public function testCreateWithNullTimeout(): void
    {
        $process = $this->factory->create(['echo', 'hello'], null, null);

        $this->assertNull($process->getTimeout());
    }

    protected function setUp(): void
    {
        $this->factory = new ProcessFactory();
    }
}
