<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use App\Util\PrivilegeEscalator;
use App\Util\ProcessFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[AllowMockObjectsWithoutExpectations]
final class PrivilegeEscalatorTest extends TestCase
{
    private Filesystem&MockObject $filesystem;

    private ProcessFactory&MockObject $processFactory;

    private PrivilegeEscalator $escalator;

    public function testEnsureDirHappyPath(): void
    {
        $this->filesystem
            ->expects($this->once())
            ->method('mkdir')
            ->with('/etc/systemd/resolved.conf.d');

        $this->processFactory
            ->expects($this->never())
            ->method('create');

        $this->escalator->ensureDir('/etc/systemd/resolved.conf.d');
    }

    public function testEnsureDirSudoFallback(): void
    {
        $this->filesystem
            ->expects($this->once())
            ->method('mkdir')
            ->with('/some/path')
            ->willThrowException(new IOException('permission denied'));

        $sudoProcess = $this->createMock(Process::class);
        $sudoProcess
            ->expects($this->once())
            ->method('mustRun');

        // Without TTY (unit test container has no TTY), setTty must NOT be invoked.
        $sudoProcess
            ->expects($this->never())
            ->method('setTty');

        $this->processFactory
            ->expects($this->once())
            ->method('create')
            ->with(['sudo', 'mkdir', '-p', '/some/path'])
            ->willReturn($sudoProcess);

        $this->escalator->ensureDir('/some/path');
    }

    public function testWriteFileHappyPath(): void
    {
        $this->filesystem
            ->expects($this->once())
            ->method('dumpFile')
            ->with('/target/path', 'content');

        $this->filesystem
            ->expects($this->once())
            ->method('chmod')
            ->with('/target/path', (int) octdec('0644'));

        $this->processFactory
            ->expects($this->never())
            ->method('create');

        $this->escalator->writeFile('/target/path', 'content');
    }

    public function testWriteFileSudoFallback(): void
    {
        $this->filesystem
            ->expects($this->once())
            ->method('dumpFile')
            ->with('/target/path', 'content')
            ->willThrowException(new IOException('permission denied'));

        $capturedTmpPath = null;

        $sudoProcess = $this->createMock(Process::class);
        $sudoProcess
            ->expects($this->once())
            ->method('mustRun');

        $this->processFactory
            ->expects($this->once())
            ->method('create')
            ->willReturnCallback(function (array $command) use (&$capturedTmpPath, $sudoProcess): Process {
                $this->assertCount(6, $command);
                $this->assertSame('sudo', $command[0]);
                $this->assertSame('install', $command[1]);
                $this->assertSame('-m', $command[2]);
                $this->assertSame('0644', $command[3]);
                $this->assertSame('/target/path', $command[5]);
                $capturedTmpPath = $command[4];
                $this->assertFileExists($capturedTmpPath);
                $this->assertSame('content', file_get_contents($capturedTmpPath));

                return $sudoProcess;
            });

        $this->escalator->writeFile('/target/path', 'content');

        // Tempfile must be cleaned up after the call returns.
        $this->assertNotNull($capturedTmpPath);
        $this->assertFileDoesNotExist($capturedTmpPath);
    }

    public function testWriteFileSudoFallbackWithCustomMode(): void
    {
        $this->filesystem
            ->expects($this->once())
            ->method('dumpFile')
            ->willThrowException(new IOException('permission denied'));

        $sudoProcess = $this->createMock(Process::class);
        $sudoProcess
            ->expects($this->once())
            ->method('mustRun');

        $this->processFactory
            ->expects($this->once())
            ->method('create')
            ->willReturnCallback(function (array $command) use ($sudoProcess): Process {
                $this->assertSame('0600', $command[3]);

                return $sudoProcess;
            });

        $this->escalator->writeFile('/target/secret', 'secret', '0600');
    }

    public function testRunHappyPath(): void
    {
        $directProcess = $this->createMock(Process::class);
        $directProcess
            ->expects($this->once())
            ->method('run');
        $directProcess
            ->expects($this->once())
            ->method('isSuccessful')
            ->willReturn(true);

        $this->processFactory
            ->expects($this->once())
            ->method('create')
            ->with(['systemctl', 'restart', 'foo'])
            ->willReturn($directProcess);

        $this->escalator->run(['systemctl', 'restart', 'foo']);
    }

    public function testRunSudoFallback(): void
    {
        $directProcess = $this->createMock(Process::class);
        $directProcess->expects($this->once())->method('run');
        // ProcessFailedException's constructor calls isSuccessful() again to verify
        // the process actually failed, so this method is invoked at least twice on
        // the non-success path.
        $directProcess->expects($this->atLeastOnce())->method('isSuccessful')->willReturn(false);
        $directProcess->method('isStarted')->willReturn(true);
        $directProcess->method('isTerminated')->willReturn(true);
        $directProcess->method('getExitCode')->willReturn(1);
        $directProcess->method('getCommandLine')->willReturn('systemctl restart foo');

        $sudoProcess = $this->createMock(Process::class);
        $sudoProcess->expects($this->once())->method('mustRun');
        $sudoProcess->expects($this->never())->method('setTty');

        $createCalls = [];
        $this->processFactory
            ->expects($this->exactly(2))
            ->method('create')
            ->willReturnCallback(function (array $command) use (&$createCalls, $directProcess, $sudoProcess): Process {
                $createCalls[] = $command;
                if (count($createCalls) === 1) {
                    $this->assertSame(['systemctl', 'restart', 'foo'], $command);

                    return $directProcess;
                }

                $this->assertSame(['sudo', 'systemctl', 'restart', 'foo'], $command);

                return $sudoProcess;
            });

        $this->escalator->run(['systemctl', 'restart', 'foo']);
    }

    public function testRunFinalFailureEnrichesMessage(): void
    {
        $directProcess = $this->createMock(Process::class);
        $directProcess->expects($this->once())->method('run');
        $directProcess->expects($this->atLeastOnce())->method('isSuccessful')->willReturn(false);
        $directProcess->method('isStarted')->willReturn(true);
        $directProcess->method('isTerminated')->willReturn(true);
        $directProcess->method('getExitCode')->willReturn(1);
        $directProcess->method('getCommandLine')->willReturn('systemctl restart foo');

        // Build a real Process for the sudo invocation that will fail when mustRun() is called.
        // We mock mustRun to throw a ProcessFailedException-like error by using a Process
        // running a command that exits non-zero.
        $sudoProcess = $this->createMock(Process::class);
        $sudoProcess
            ->expects($this->once())
            ->method('mustRun')
            ->willThrowException(new \Symfony\Component\Process\Exception\ProcessFailedException(
                $this->buildFailedRealProcess(),
            ));

        $this->processFactory
            ->expects($this->exactly(2))
            ->method('create')
            ->willReturnOnConsecutiveCalls($directProcess, $sudoProcess);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/systemctl restart foo/');
        $this->expectExceptionMessageMatches('/sudo escalation also failed/i');

        $this->escalator->run(['systemctl', 'restart', 'foo']);
    }

    public function testEnsureDirFinalFailureEnrichesMessage(): void
    {
        $this->filesystem
            ->expects($this->once())
            ->method('mkdir')
            ->willThrowException(new IOException('permission denied'));

        $sudoProcess = $this->createMock(Process::class);
        $sudoProcess
            ->expects($this->once())
            ->method('mustRun')
            ->willThrowException(new \Symfony\Component\Process\Exception\ProcessFailedException(
                $this->buildFailedRealProcess(),
            ));

        $this->processFactory
            ->expects($this->once())
            ->method('create')
            ->willReturn($sudoProcess);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/mkdir/');
        $this->expectExceptionMessageMatches('/sudo escalation also failed/i');

        $this->escalator->ensureDir('/etc/forbidden');
    }

    public function testWriteFileFinalFailureEnrichesMessageAndCleansTempfile(): void
    {
        $this->filesystem
            ->expects($this->once())
            ->method('dumpFile')
            ->willThrowException(new IOException('permission denied'));

        $sudoProcess = $this->createMock(Process::class);
        $sudoProcess
            ->expects($this->once())
            ->method('mustRun')
            ->willThrowException(new \Symfony\Component\Process\Exception\ProcessFailedException(
                $this->buildFailedRealProcess(),
            ));

        $capturedTmpPath = null;
        $this->processFactory
            ->expects($this->once())
            ->method('create')
            ->willReturnCallback(function (array $command) use (&$capturedTmpPath, $sudoProcess): Process {
                $capturedTmpPath = $command[4];

                return $sudoProcess;
            });

        try {
            $this->escalator->writeFile('/etc/forbidden/file', 'content');
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $runtimeException) {
            $this->assertMatchesRegularExpression('/install|writeFile|write file|\/etc\/forbidden\/file/i', $runtimeException->getMessage());
            $this->assertMatchesRegularExpression('/sudo escalation also failed/i', $runtimeException->getMessage());
        }

        // Even on failure, the tempfile must be cleaned up.
        $this->assertNotNull($capturedTmpPath);
        $this->assertFileDoesNotExist($capturedTmpPath);
    }

    public function testRunSudoFallbackInNonTty(): void
    {
        // Explicit no-TTY assertion: in a unit-test container, Process::isTtySupported() is false
        // (no controlling TTY on stdin), so setTty must never be invoked on the sudo subprocess.
        if (Process::isTtySupported() && \defined('STDIN') && @stream_isatty(\STDIN)) {
            $this->markTestSkipped('Test environment has a TTY; this branch covers the non-TTY path.');
        }

        $directProcess = $this->createMock(Process::class);
        $directProcess->method('run');
        $directProcess->method('isSuccessful')->willReturn(false);

        $sudoProcess = $this->createMock(Process::class);
        $sudoProcess->expects($this->never())->method('setTty');
        $sudoProcess->expects($this->once())->method('mustRun');

        $this->processFactory
            ->method('create')
            ->willReturnOnConsecutiveCalls($directProcess, $sudoProcess);

        $this->escalator->run(['true']);
    }

    public function testRunSkipsSudoWhenAlreadyRunningAsRoot(): void
    {
        // Real-root short-circuit: the direct process fails, and the escalator must NOT
        // spawn a `sudo` subprocess (sudo may be unavailable in minimal containers, and
        // would be redundant anyway). The original failure is surfaced unmodified.
        $rootEscalator = $this->makeEscalator(currentUserIsRoot: true);

        $directProcess = $this->createMock(Process::class);
        $directProcess->expects($this->once())->method('run');
        $directProcess->expects($this->atLeastOnce())->method('isSuccessful')->willReturn(false);
        // Provide a non-zero exit so ProcessFailedException construction inside run() succeeds.
        $directProcess->method('getExitCode')->willReturn(1);
        $directProcess->method('isStarted')->willReturn(true);
        $directProcess->method('isTerminated')->willReturn(true);
        $directProcess->method('getCommandLine')->willReturn('systemctl restart foo');

        $this->processFactory
            ->expects($this->once())
            ->method('create')
            ->with(['systemctl', 'restart', 'foo'])
            ->willReturn($directProcess);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/systemctl restart foo/');
        $this->expectExceptionMessageMatches('/while running as root/i');

        $rootEscalator->run(['systemctl', 'restart', 'foo']);
    }

    public function testEnsureDirSkipsSudoWhenAlreadyRunningAsRoot(): void
    {
        $rootEscalator = $this->makeEscalator(currentUserIsRoot: true);

        $this->filesystem
            ->expects($this->once())
            ->method('mkdir')
            ->with('/etc/forbidden')
            ->willThrowException(new IOException('read-only filesystem'));

        // Critical: the escalator must NOT spawn any subprocess when already root.
        $this->processFactory
            ->expects($this->never())
            ->method('create');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/mkdir/');
        $this->expectExceptionMessageMatches('/while running as root/i');
        $this->expectExceptionMessageMatches('/read-only filesystem/');

        $rootEscalator->ensureDir('/etc/forbidden');
    }

    public function testWriteFileSkipsSudoWhenAlreadyRunningAsRoot(): void
    {
        $rootEscalator = $this->makeEscalator(currentUserIsRoot: true);

        $this->filesystem
            ->expects($this->once())
            ->method('dumpFile')
            ->willThrowException(new IOException('read-only filesystem'));

        $this->processFactory
            ->expects($this->never())
            ->method('create');

        try {
            $rootEscalator->writeFile('/etc/forbidden/file', 'content');
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $runtimeException) {
            $this->assertMatchesRegularExpression('/install|\/etc\/forbidden\/file/i', $runtimeException->getMessage());
            $this->assertMatchesRegularExpression('/while running as root/i', $runtimeException->getMessage());
            $this->assertMatchesRegularExpression('/read-only filesystem/', $runtimeException->getMessage());
        }
    }

    private function buildFailedRealProcess(): Process
    {
        // Symfony's ProcessFailedException requires a Process that has been started and ended
        // unsuccessfully. Run a tiny real process that exits non-zero.
        $process = new Process(['sh', '-c', 'exit 1']);
        $process->run();

        return $process;
    }

    /**
     * Build a PrivilegeEscalator with `currentUserIsRoot()` overridden to the
     * given value. Because PrivilegeEscalator is a `readonly class`, the
     * anonymous subclass must also be `readonly`.
     */
    private function makeEscalator(bool $currentUserIsRoot): PrivilegeEscalator
    {
        $filesystem = $this->filesystem;
        $processFactory = $this->processFactory;

        if ($currentUserIsRoot) {
            return new readonly class($filesystem, $processFactory) extends PrivilegeEscalator {
                protected function currentUserIsRoot(): bool
                {
                    return true;
                }
            };
        }

        return new readonly class($filesystem, $processFactory) extends PrivilegeEscalator {
            protected function currentUserIsRoot(): bool
            {
                return false;
            }
        };
    }

    protected function setUp(): void
    {
        $this->filesystem = $this->createMock(Filesystem::class);
        $this->processFactory = $this->createMock(ProcessFactory::class);

        // Default escalator simulates a non-root user so the existing sudo-fallback
        // tests can be exercised even though the toolchain container runs as root.
        // Tests that need the already-root short-circuit build a separate instance
        // via makeEscalator(currentUserIsRoot: true).
        $this->escalator = $this->makeEscalator(currentUserIsRoot: false);
    }
}
