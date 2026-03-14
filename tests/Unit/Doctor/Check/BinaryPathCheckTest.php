<?php

declare(strict_types=1);

namespace Tests\Unit\Doctor\Check;

use App\Doctor\Check\BinaryPathCheck;
use App\Doctor\CheckStatus;
use App\Util\ProcessFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class BinaryPathCheckTest extends TestCase
{
    public function testGetName(): void
    {
        $check = new BinaryPathCheck(processFactory: new ProcessFactory());
        $this->assertSame('Binary Path', $check->getName());
    }

    public function testBinaryFoundInPath(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $process->method('getOutput')->willReturn("/usr/local/bin/dde\n");

        $factory = $this->createMock(ProcessFactory::class);
        $factory->method('create')->willReturn($process);

        $check = new BinaryPathCheck($factory);
        $result = $check->run();

        $this->assertSame(CheckStatus::OK, $result->status);
    }

    public function testBinaryExistsButNotInPath(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(false);
        $process->method('getOutput')->willReturn('');

        $factory = $this->createMock(ProcessFactory::class);
        $factory->method('create')->willReturn($process);

        $tmpDir = sys_get_temp_dir().'/dde_test_'.uniqid();
        mkdir($tmpDir.'/bin', 0755, true);
        touch($tmpDir.'/bin/dde');

        try {
            $check = new BinaryPathCheck($factory, $tmpDir);
            $result = $check->run();

            $this->assertSame(CheckStatus::WARNING, $result->status);
        } finally {
            unlink($tmpDir.'/bin/dde');
            rmdir($tmpDir.'/bin');
            rmdir($tmpDir);
        }
    }

    public function testBinaryNotFound(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(false);
        $process->method('getOutput')->willReturn('');

        $factory = $this->createMock(ProcessFactory::class);
        $factory->method('create')->willReturn($process);

        $check = new BinaryPathCheck($factory, '/nonexistent/home');
        $result = $check->run();

        $this->assertSame(CheckStatus::ERROR, $result->status);
    }

    public function testBinaryPathCheckTimeout(): void
    {
        $process = $this->createMock(Process::class);
        $process->method('run')->willThrowException(new ProcessTimedOutException($process, ProcessTimedOutException::TYPE_GENERAL));

        $factory = $this->createMock(ProcessFactory::class);
        $factory->method('create')->willReturn($process);

        $check = new BinaryPathCheck($factory);
        $result = $check->run();

        $this->assertSame(CheckStatus::WARNING, $result->status);
    }

    #[Group('e2e')]
    public function testRunReturnsCheckResult(): void
    {
        $check = new BinaryPathCheck(processFactory: new ProcessFactory());
        $result = $check->run();
        $this->assertSame('Binary Path', $result->name);
        $this->assertContains($result->status, [CheckStatus::OK, CheckStatus::WARNING, CheckStatus::ERROR]);
    }
}
