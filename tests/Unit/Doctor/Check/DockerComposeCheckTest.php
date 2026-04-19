<?php

declare(strict_types=1);

namespace Tests\Unit\Doctor\Check;

use App\Doctor\Check\DockerComposeCheck;
use App\Doctor\CheckStatus;
use App\Util\ProcessFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

#[AllowMockObjectsWithoutExpectations]
final class DockerComposeCheckTest extends TestCase
{
    public function testGetName(): void
    {
        $check = new DockerComposeCheck(new ProcessFactory());
        $this->assertSame('Docker Compose', $check->getName());
    }

    public function testDockerComposeAvailable(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $process->method('getOutput')->willReturn("Docker Compose version v2.24.0\n");

        $factory = $this->createMock(ProcessFactory::class);
        $factory->method('create')->willReturn($process);

        $check = new DockerComposeCheck($factory);
        $result = $check->run();

        $this->assertSame(CheckStatus::OK, $result->status);
        $this->assertSame('Docker Compose version v2.24.0', $result->message);
    }

    public function testDockerComposeNotAvailable(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(false);

        $factory = $this->createMock(ProcessFactory::class);
        $factory->method('create')->willReturn($process);

        $check = new DockerComposeCheck($factory);
        $result = $check->run();

        $this->assertSame(CheckStatus::ERROR, $result->status);
    }

    public function testDockerComposeCheckTimeout(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('run')->willThrowException(new ProcessTimedOutException($process, ProcessTimedOutException::TYPE_GENERAL));

        $factory = $this->createMock(ProcessFactory::class);
        $factory->method('create')->willReturn($process);

        $check = new DockerComposeCheck($factory);
        $result = $check->run();

        $this->assertSame(CheckStatus::ERROR, $result->status);
    }

    #[Group('e2e')]
    public function testRunReturnsCheckResult(): void
    {
        $check = new DockerComposeCheck(new ProcessFactory());
        $result = $check->run();
        $this->assertSame('Docker Compose', $result->name);
        $this->assertContains($result->status, [CheckStatus::OK, CheckStatus::ERROR]);
    }
}
