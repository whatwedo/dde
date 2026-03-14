<?php

declare(strict_types=1);

namespace Tests\Unit\Doctor\Check;

use App\Doctor\Check\DockerAvailableCheck;
use App\Doctor\CheckStatus;
use App\Util\ProcessFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class DockerAvailableCheckTest extends TestCase
{
    public function testGetName(): void
    {
        $check = new DockerAvailableCheck(new ProcessFactory());
        $this->assertSame('Docker Available', $check->getName());
    }

    public function testDockerRunning(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);

        $factory = $this->createMock(ProcessFactory::class);
        $factory->method('create')->willReturn($process);

        $check = new DockerAvailableCheck($factory);
        $result = $check->run();

        $this->assertSame(CheckStatus::OK, $result->status);
        $this->assertSame('Docker Available', $result->name);
    }

    public function testDockerNotRunning(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(false);

        $factory = $this->createMock(ProcessFactory::class);
        $factory->method('create')->willReturn($process);

        $check = new DockerAvailableCheck($factory);
        $result = $check->run();

        $this->assertSame(CheckStatus::ERROR, $result->status);
    }

    public function testDockerCheckTimeout(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('run')->willThrowException(new ProcessTimedOutException($process, ProcessTimedOutException::TYPE_GENERAL));

        $factory = $this->createMock(ProcessFactory::class);
        $factory->method('create')->willReturn($process);

        $check = new DockerAvailableCheck($factory);
        $result = $check->run();

        $this->assertSame(CheckStatus::ERROR, $result->status);
    }

    #[Group('e2e')]
    public function testRunReturnsCheckResult(): void
    {
        $check = new DockerAvailableCheck(new ProcessFactory());
        $result = $check->run();
        $this->assertSame('Docker Available', $result->name);
        $this->assertContains($result->status, [CheckStatus::OK, CheckStatus::ERROR]);
    }
}
