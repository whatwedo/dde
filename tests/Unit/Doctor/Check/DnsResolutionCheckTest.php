<?php

declare(strict_types=1);

namespace Tests\Unit\Doctor\Check;

use App\Doctor\Check\DnsResolutionCheck;
use App\Doctor\CheckStatus;
use App\Util\ProcessFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

#[AllowMockObjectsWithoutExpectations]
final class DnsResolutionCheckTest extends TestCase
{
    public function testGetName(): void
    {
        $check = new DnsResolutionCheck(new ProcessFactory());
        $this->assertSame('DNS Resolution', $check->getName());
    }

    public function testSuccessfulDnsResolution(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $process->method('getOutput')->willReturn("127.0.0.1\n");

        $factory = $this->createMock(ProcessFactory::class);
        $factory->method('create')->willReturn($process);

        $check = new DnsResolutionCheck($factory);
        $result = $check->run();

        $this->assertSame(CheckStatus::OK, $result->status);
        $this->assertSame('DNS Resolution', $result->name);
    }

    public function testFailedDnsResolution(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(false);
        $process->method('getOutput')->willReturn('');

        $factory = $this->createMock(ProcessFactory::class);
        $factory->method('create')->willReturn($process);

        $check = new DnsResolutionCheck($factory);
        $result = $check->run();

        $this->assertSame(CheckStatus::WARNING, $result->status);
    }

    public function testDnsResolutionWrongOutput(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $process->method('getOutput')->willReturn("192.168.1.1\n");

        $factory = $this->createMock(ProcessFactory::class);
        $factory->method('create')->willReturn($process);

        $check = new DnsResolutionCheck($factory);
        $result = $check->run();

        $this->assertSame(CheckStatus::WARNING, $result->status);
    }

    public function testDnsResolutionTimeout(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('run')->willThrowException(new ProcessTimedOutException($process, ProcessTimedOutException::TYPE_GENERAL));

        $factory = $this->createMock(ProcessFactory::class);
        $factory->method('create')->willReturn($process);

        $check = new DnsResolutionCheck($factory);
        $result = $check->run();

        $this->assertSame(CheckStatus::WARNING, $result->status);
    }

    #[Group('e2e')]
    public function testRunReturnsCheckResult(): void
    {
        $check = new DnsResolutionCheck(new ProcessFactory());
        $result = $check->run();
        $this->assertSame('DNS Resolution', $result->name);
        $this->assertContains($result->status, [CheckStatus::OK, CheckStatus::WARNING]);
    }
}
