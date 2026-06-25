<?php

declare(strict_types=1);

namespace Tests\Unit\Doctor\Check;

use App\Doctor\Check\RootCaTrustedCheck;
use App\Doctor\CheckStatus;
use App\Util\ProcessFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class RootCaTrustedCheckTest extends TestCase
{
    public function testGetName(): void
    {
        $check = new RootCaTrustedCheck(new ProcessFactory());
        $this->assertSame('Root CA Trusted', $check->getName());
    }

    public function testRootCaFoundAndTrustedOnLinux(): void
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            $this->markTestSkipped('Linux-only path; skipped on macOS.');
        }

        $caRootProcess = $this->createStub(Process::class);
        $caRootProcess->method('isSuccessful')->willReturn(true);
        $caRootProcess->method('getOutput')->willReturn("/home/user/.local/share/mkcert\n");

        $factory = $this->createStub(ProcessFactory::class);
        $factory->method('create')->willReturn($caRootProcess);

        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('exists')->willReturnCallback(
            static fn (string $path): bool => $path === '/home/user/.local/share/mkcert/rootCA.pem',
        );

        $check = new RootCaTrustedCheck($factory, $filesystem);
        $result = $check->run();

        $this->assertSame(CheckStatus::OK, $result->status);
        $this->assertSame('Root CA is installed and trusted.', $result->message);
    }

    public function testRootCaFoundAndTrustedOnMacOs(): void
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            $this->markTestSkipped('macOS-only path.');
        }

        $caRootProcess = $this->createStub(Process::class);
        $caRootProcess->method('isSuccessful')->willReturn(true);
        $caRootProcess->method('getOutput')->willReturn("/Users/user/Library/Application Support/mkcert\n");

        $verifyProcess = $this->createStub(Process::class);
        $verifyProcess->method('isSuccessful')->willReturn(true);

        $factory = $this->createStub(ProcessFactory::class);
        $factory->method('create')
            ->willReturnCallback(function (array $cmd) use ($caRootProcess, $verifyProcess): Process {
                if ($cmd[0] === 'security') {
                    return $verifyProcess;
                }

                return $caRootProcess;
            });

        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('exists')->willReturn(true);

        $check = new RootCaTrustedCheck($factory, $filesystem);
        $result = $check->run();

        $this->assertSame(CheckStatus::OK, $result->status);
        $this->assertSame('Root CA is installed and trusted.', $result->message);
    }

    public function testRootCaNotTrustedOnMacOs(): void
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            $this->markTestSkipped('macOS-only path.');
        }

        $caRootProcess = $this->createStub(Process::class);
        $caRootProcess->method('isSuccessful')->willReturn(true);
        $caRootProcess->method('getOutput')->willReturn("/Users/user/Library/Application Support/mkcert\n");

        $verifyProcess = $this->createStub(Process::class);
        $verifyProcess->method('isSuccessful')->willReturn(false);

        $factory = $this->createStub(ProcessFactory::class);
        $factory->method('create')
            ->willReturnCallback(function (array $cmd) use ($caRootProcess, $verifyProcess): Process {
                if ($cmd[0] === 'security') {
                    return $verifyProcess;
                }

                return $caRootProcess;
            });

        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('exists')->willReturn(true);

        $check = new RootCaTrustedCheck($factory, $filesystem);
        $result = $check->run();

        $this->assertSame(CheckStatus::WARNING, $result->status);
        $this->assertSame('Root CA exists but is not trusted by the system.', $result->message);
    }

    public function testTrustCheckTimeoutOnMacOs(): void
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            $this->markTestSkipped('macOS-only path.');
        }

        $caRootProcess = $this->createStub(Process::class);
        $caRootProcess->method('isSuccessful')->willReturn(true);
        $caRootProcess->method('getOutput')->willReturn("/Users/user/Library/Application Support/mkcert\n");

        $verifyProcess = $this->createStub(Process::class);
        $verifyProcess->method('run')->willThrowException(new ProcessTimedOutException($verifyProcess, ProcessTimedOutException::TYPE_GENERAL));

        $factory = $this->createStub(ProcessFactory::class);
        $factory->method('create')
            ->willReturnCallback(function (array $cmd) use ($caRootProcess, $verifyProcess): Process {
                if ($cmd[0] === 'security') {
                    return $verifyProcess;
                }

                return $caRootProcess;
            });

        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('exists')->willReturn(true);

        $check = new RootCaTrustedCheck($factory, $filesystem);
        $result = $check->run();

        $this->assertSame(CheckStatus::WARNING, $result->status);
        $this->assertSame('Root CA trust check timed out.', $result->message);
    }

    public function testRootCaNotFound(): void
    {
        $process = $this->createStub(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $process->method('getOutput')->willReturn("/home/user/.local/share/mkcert\n");

        $factory = $this->createStub(ProcessFactory::class);
        $factory->method('create')->willReturn($process);

        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('exists')->willReturn(false);

        $check = new RootCaTrustedCheck($factory, $filesystem);
        $result = $check->run();

        $this->assertSame(CheckStatus::WARNING, $result->status);
        $this->assertSame('Root CA not found.', $result->message);
    }

    public function testMkcertCommandFails(): void
    {
        $process = $this->createStub(Process::class);
        $process->method('isSuccessful')->willReturn(false);

        $factory = $this->createStub(ProcessFactory::class);
        $factory->method('create')->willReturn($process);

        $check = new RootCaTrustedCheck($factory);
        $result = $check->run();

        $this->assertSame(CheckStatus::WARNING, $result->status);
        $this->assertSame('Could not determine mkcert CA root.', $result->message);
    }

    public function testRootCaCheckTimeout(): void
    {
        $process = $this->createStub(Process::class);
        $process->method('run')->willThrowException(new ProcessTimedOutException($process, ProcessTimedOutException::TYPE_GENERAL));

        $factory = $this->createStub(ProcessFactory::class);
        $factory->method('create')->willReturn($process);

        $check = new RootCaTrustedCheck($factory);
        $result = $check->run();

        $this->assertSame(CheckStatus::WARNING, $result->status);
        $this->assertSame('Root CA check timed out.', $result->message);
    }

    #[Group('e2e')]
    public function testRunReturnsCheckResult(): void
    {
        $check = new RootCaTrustedCheck(new ProcessFactory());
        $result = $check->run();
        $this->assertSame('Root CA Trusted', $result->name);
        $this->assertContains($result->status, [CheckStatus::OK, CheckStatus::WARNING]);
    }
}
