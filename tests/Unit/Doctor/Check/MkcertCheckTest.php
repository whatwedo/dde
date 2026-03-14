<?php

declare(strict_types=1);

namespace Tests\Unit\Doctor\Check;

use App\Doctor\Check\MkcertCheck;
use App\Doctor\CheckStatus;
use App\Util\ProcessFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class MkcertCheckTest extends TestCase
{
    public function testGetName(): void
    {
        $check = new MkcertCheck(new ProcessFactory());
        $this->assertSame('mkcert', $check->getName());
    }

    public function testMkcertInstalled(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $process->method('getOutput')->willReturn("/home/user/.local/share/mkcert\n");

        $factory = $this->createMock(ProcessFactory::class);
        $factory->method('create')->willReturn($process);

        $check = new MkcertCheck($factory);
        $result = $check->run();

        $this->assertSame(CheckStatus::OK, $result->status);
        $this->assertStringContainsString('mkcert installed', $result->message);
    }

    public function testMkcertNotInstalled(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(false);

        $factory = $this->createMock(ProcessFactory::class);
        $factory->method('create')->willReturn($process);

        $check = new MkcertCheck($factory);
        $result = $check->run();

        $this->assertSame(CheckStatus::ERROR, $result->status);
    }

    public function testMkcertCheckTimeout(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('run')->willThrowException(new ProcessTimedOutException($process, ProcessTimedOutException::TYPE_GENERAL));

        $factory = $this->createMock(ProcessFactory::class);
        $factory->method('create')->willReturn($process);

        $check = new MkcertCheck($factory);
        $result = $check->run();

        $this->assertSame(CheckStatus::ERROR, $result->status);
    }

    #[Group('e2e')]
    public function testRunReturnsCheckResult(): void
    {
        $check = new MkcertCheck(new ProcessFactory());
        $result = $check->run();
        $this->assertSame('mkcert', $result->name);
        $this->assertContains($result->status, [CheckStatus::OK, CheckStatus::ERROR]);
    }
}
