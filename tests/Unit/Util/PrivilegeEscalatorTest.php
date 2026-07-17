<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use App\Util\PrivilegeEscalator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Tests\Support\Process\RecordingProcessFactory;
use Tests\Support\Process\StubProcess;

#[AllowMockObjectsWithoutExpectations]
final class PrivilegeEscalatorTest extends TestCase
{
    private string $tempDir;

    private Filesystem $filesystem;

    /**
     * @var list<string>
     */
    private array $announcements = [];

    public function testEnsureDirSucceedsWithoutEscalation(): void
    {
        $factory = new RecordingProcessFactory();
        $escalator = $this->createEscalator(new Filesystem(), $factory);

        $escalator->ensureDir($this->tempDir.'/newdir');

        $this->assertDirectoryExists($this->tempDir.'/newdir');
        $this->assertCount(0, $factory->commands);
        $this->assertSame([], $this->announcements);
    }

    public function testEnsureDirEscalatesViaSudoOnPermissionError(): void
    {
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('mkdir')->willThrowException(new IOException('Permission denied.'));
        $factory = new RecordingProcessFactory();

        $escalator = $this->createEscalator($filesystem, $factory);
        $escalator->ensureDir('/etc/resolver');

        $this->assertSame([['sudo', 'mkdir', '-p', '/etc/resolver']], $factory->commands->getArrayCopy());
        $this->assertSame(['Creating directory /etc/resolver requires root — you may be asked for your sudo password.'], $this->announcements);
    }

    public function testEnsureDirThrowsWhenSudoAlsoFails(): void
    {
        $filesystem = $this->createMock(Filesystem::class);
        $ioException = new IOException('Permission denied.');
        $filesystem->method('mkdir')->willThrowException($ioException);
        $factory = new RecordingProcessFactory(results: [
            [
                'exitCode' => 1,
                'errorOutput' => 'sudo: a password is required',
            ],
        ]);

        $escalator = $this->createEscalator($filesystem, $factory);

        try {
            $escalator->ensureDir('/etc/resolver');
            $this->fail('Expected a RuntimeException');
        } catch (\RuntimeException $runtimeException) {
            $this->assertStringContainsString('Creating directory /etc/resolver requires root', $runtimeException->getMessage());
            $this->assertStringContainsString('sudo: a password is required', $runtimeException->getMessage());
            $this->assertSame($ioException, $runtimeException->getPrevious());
        }
    }

    public function testEnsureDirDoesNotUseSudoWhenAlreadyRoot(): void
    {
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('mkdir')->willThrowException(new IOException('Read-only file system.'));
        $factory = new RecordingProcessFactory();

        $escalator = $this->createEscalator($filesystem, $factory, effectiveUserId: 0);

        try {
            $escalator->ensureDir('/etc/resolver');
            $this->fail('Expected a RuntimeException');
        } catch (\RuntimeException $runtimeException) {
            $this->assertStringContainsString('failed while running as root', $runtimeException->getMessage());
        }

        $this->assertCount(0, $factory->commands);
        $this->assertSame([], $this->announcements);
    }

    public function testWriteFileSucceedsWithoutEscalation(): void
    {
        $factory = new RecordingProcessFactory();
        $escalator = $this->createEscalator(new Filesystem(), $factory);

        $escalator->writeFile($this->tempDir.'/file.conf', "nameserver 127.0.0.1\n");

        $this->assertStringEqualsFile($this->tempDir.'/file.conf', "nameserver 127.0.0.1\n");
        $this->assertSame(0o644, fileperms($this->tempDir.'/file.conf') & 0o777);
        $this->assertCount(0, $factory->commands);
    }

    public function testWriteFileEscalatesViaTempfileAndInstall(): void
    {
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('dumpFile')->willThrowException(new IOException('Permission denied.'));
        $capturedContent = null;
        $factory = new RecordingProcessFactory(results: [
            [
                'onRun' => function (StubProcess $process) use (&$capturedContent): void {
                    $capturedContent = file_get_contents($process->commandArgs[4]);
                },
            ],
        ]);

        $escalator = $this->createEscalator($filesystem, $factory);
        $escalator->writeFile('/etc/resolver/test', "nameserver 127.0.0.1\n");

        $commands = $factory->commands->getArrayCopy();
        $this->assertCount(1, $commands);
        [$sudo, $install, $modeFlag, $mode, $tempFile, $target] = $commands[0];
        $this->assertSame(['sudo', 'install', '-m', '0644'], [$sudo, $install, $modeFlag, $mode]);
        $this->assertSame('/etc/resolver/test', $target);
        $this->assertSame("nameserver 127.0.0.1\n", $capturedContent, 'The tempfile must contain the payload while sudo install runs');
        $this->assertFileDoesNotExist($tempFile, 'The tempfile must be cleaned up');
        $this->assertSame(['Writing /etc/resolver/test requires root — you may be asked for your sudo password.'], $this->announcements);
    }

    public function testWriteFileCleansUpTempfileWhenSudoFails(): void
    {
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('dumpFile')->willThrowException(new IOException('Permission denied.'));
        $factory = new RecordingProcessFactory(results: [
            [
                'exitCode' => 1,
                'errorOutput' => 'sudo: a password is required',
            ],
        ]);

        $escalator = $this->createEscalator($filesystem, $factory);

        try {
            $escalator->writeFile('/etc/resolver/test', "nameserver 127.0.0.1\n");
            $this->fail('Expected a RuntimeException');
        } catch (\RuntimeException) {
        }

        $commands = $factory->commands->getArrayCopy();
        $this->assertCount(1, $commands);
        $this->assertFileDoesNotExist($commands[0][4]);
    }

    public function testRunSucceedsDirectlyWithoutSudo(): void
    {
        $factory = new RecordingProcessFactory();
        $escalator = $this->createEscalator(new Filesystem(), $factory);

        $escalator->run(['systemctl', 'restart', 'systemd-resolved']);

        $this->assertSame([['systemctl', 'restart', 'systemd-resolved']], $factory->commands->getArrayCopy());
        $this->assertSame([], $this->announcements);
    }

    public function testRunRetriesViaSudoWhenDirectRunFails(): void
    {
        $factory = new RecordingProcessFactory(results: [
            [
                'exitCode' => 1,
                'errorOutput' => 'Access denied',
            ],
            [
                'exitCode' => 0,
            ],
        ]);

        $escalator = $this->createEscalator(new Filesystem(), $factory);
        $escalator->run(['systemctl', 'restart', 'systemd-resolved']);

        $this->assertSame([
            ['systemctl', 'restart', 'systemd-resolved'],
            ['sudo', 'systemctl', 'restart', 'systemd-resolved'],
        ], $factory->commands->getArrayCopy());
        $this->assertSame(['Running "systemctl restart systemd-resolved" requires root — you may be asked for your sudo password.'], $this->announcements);
    }

    public function testRunThrowsWhenSudoAlsoFails(): void
    {
        $factory = new RecordingProcessFactory(results: [
            [
                'exitCode' => 1,
                'errorOutput' => 'Access denied',
            ],
            [
                'exitCode' => 1,
                'errorOutput' => 'still denied',
            ],
        ]);

        $escalator = $this->createEscalator(new Filesystem(), $factory);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Running "systemctl restart systemd-resolved" requires root.*still denied/s');

        $escalator->run(['systemctl', 'restart', 'systemd-resolved']);
    }

    private function createEscalator(
        Filesystem $filesystem,
        RecordingProcessFactory $factory,
        ?int $effectiveUserId = null,
    ): PrivilegeEscalator {
        return new PrivilegeEscalator(
            filesystem: $filesystem,
            processFactory: $factory,
            announcer: function (string $message): void {
                $this->announcements[] = $message;
            },
            effectiveUserId: $effectiveUserId ?? 501,
        );
    }

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->tempDir = sys_get_temp_dir().'/dde-escalator-test-'.bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o777, true);
        $this->announcements = [];
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->tempDir);
    }
}
