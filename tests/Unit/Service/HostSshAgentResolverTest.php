<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Model\HostSshAgentResolution;
use App\Service\HostSshAgentResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class HostSshAgentResolverTest extends TestCase
{
    private const string MACOS_SOCKET = '/run/host-services/ssh-auth.sock';

    private string $tempDir;

    private Filesystem $filesystem;

    /**
     * @var list<resource>
     */
    private array $sockets = [];

    public function testMacOsAlwaysReportsHostServicesSocketAvailable(): void
    {
        $resolver = new HostSshAgentResolver(
            filesystem: $this->filesystem,
            osFamily: 'Darwin',
        );

        $resolution = $resolver->resolve();

        self::assertTrue($resolution->available);
        self::assertSame(self::MACOS_SOCKET, $resolution->mountSource);
        self::assertNotNull($resolution->reason);
        self::assertStringContainsString('launchd', $resolution->reason);
    }

    /**
     * The default sources (unset or `env`) ride the Docker Desktop bridge,
     * which forwards whatever the host SSH_AUTH_SOCK points at.
     */
    #[DataProvider('macOsBridgeSourceProvider')]
    public function testMacOsBridgeBackedSourcesUseHostServicesSocket(?string $source): void
    {
        $resolver = new HostSshAgentResolver(
            filesystem: $this->filesystem,
            osFamily: 'Darwin',
            authSock: '/some/other/sock',
        );

        $resolution = $resolver->resolve($source);

        self::assertTrue($resolution->available);
        self::assertSame(self::MACOS_SOCKET, $resolution->mountSource);
    }

    /**
     * @return iterable<string, array{0: ?string}>
     */
    public static function macOsBridgeSourceProvider(): iterable
    {
        yield 'null (unset)' => [null];
        yield 'env' => ['env'];
    }

    /**
     * On macOS an explicit source is rejected: a bind-mounted host socket cannot
     * cross the Docker Desktop / OrbStack VM boundary, so it is unavailable even
     * when the socket is live on the host — only the bridge works.
     */
    public function testMacOsExplicitPathIsUnavailableEvenWhenSocketIsLive(): void
    {
        $socket = $this->createUnixSocket();

        $resolver = new HostSshAgentResolver(
            filesystem: $this->filesystem,
            osFamily: 'Darwin',
        );

        $resolution = $resolver->resolve($socket);

        self::assertFalse($resolution->available);
        self::assertNull($resolution->mountSource);
        self::assertNotNull($resolution->reason);
        self::assertStringContainsString('macOS', $resolution->reason);
    }

    #[DataProvider('envBackedSourceProvider')]
    public function testLinuxEnvBackedSourcesUseSshAuthSockWhenPresent(?string $source): void
    {
        $socket = $this->createUnixSocket();

        $resolver = new HostSshAgentResolver(
            filesystem: $this->filesystem,
            osFamily: 'Linux',
            authSock: $socket,
        );

        $resolution = $resolver->resolve($source);

        self::assertTrue($resolution->available);
        self::assertSame($socket, $resolution->mountSource);
        self::assertNull($resolution->reason);
    }

    public function testLinuxPathUnavailableWhenNotASocket(): void
    {
        // A regular file at the resolved path is not a usable agent socket; the
        // resolver must reject it so bring-up does not mount a non-socket.
        $regularFile = $this->tempDir.'/not-a-socket';
        $this->filesystem->touch($regularFile);

        $resolver = new HostSshAgentResolver(
            filesystem: $this->filesystem,
            osFamily: 'Linux',
            authSock: $regularFile,
        );

        $resolution = $resolver->resolve('env');

        self::assertFalse($resolution->available);
        self::assertNull($resolution->mountSource);
        self::assertStringContainsString('not a socket', (string) $resolution->reason);
    }

    /**
     * @return iterable<string, array{0: ?string}>
     */
    public static function envBackedSourceProvider(): iterable
    {
        yield 'null (unset)' => [null];
        yield 'env' => ['env'];
    }

    #[DataProvider('envBackedSourceProvider')]
    public function testLinuxEnvBackedSourcesUnavailableWhenSshAuthSockUnset(?string $source): void
    {
        // Empty string, not null, is the "unset" seam: the constructor falls
        // back to the real process SSH_AUTH_SOCK only when authSock is null, so
        // passing null here would leak the host agent of whoever runs the suite.
        $resolver = new HostSshAgentResolver(
            filesystem: $this->filesystem,
            osFamily: 'Linux',
            authSock: '',
        );

        $resolution = $resolver->resolve($source);

        self::assertFalse($resolution->available);
        self::assertNull($resolution->mountSource);
        self::assertNotNull($resolution->reason);
        self::assertStringContainsString('SSH_AUTH_SOCK', $resolution->reason);
    }

    #[DataProvider('envBackedSourceProvider')]
    public function testLinuxEnvBackedSourcesUnavailableWhenSocketMissing(?string $source): void
    {
        $missing = $this->tempDir.'/missing.sock';

        $resolver = new HostSshAgentResolver(
            filesystem: $this->filesystem,
            osFamily: 'Linux',
            authSock: $missing,
        );

        $resolution = $resolver->resolve($source);

        self::assertFalse($resolution->available);
        self::assertNull($resolution->mountSource);
        self::assertNotNull($resolution->reason);
        self::assertStringContainsString('does not exist', $resolution->reason);
    }

    public function testLinuxExplicitPathUsedVerbatimWhenPresent(): void
    {
        $socket = $this->createUnixSocket($this->tempDir.'/custom-agent.sock');

        $resolver = new HostSshAgentResolver(
            filesystem: $this->filesystem,
            osFamily: 'Linux',
            authSock: '/should/be/ignored',
        );

        $resolution = $resolver->resolve($socket);

        self::assertTrue($resolution->available);
        self::assertSame($socket, $resolution->mountSource);
    }

    public function testLinuxExpandsLeadingTildeInExplicitSource(): void
    {
        // `source: ~/agent.sock` must expand against HOME (as `ssh.keys` do);
        // a literal `~` would never resolve.
        $socket = $this->createUnixSocket($this->tempDir.'/agent.sock');

        $resolver = new HostSshAgentResolver(
            filesystem: $this->filesystem,
            osFamily: 'Linux',
            authSock: '',
            homeDir: $this->tempDir,
        );

        $resolution = $resolver->resolve('~/agent.sock');

        self::assertTrue($resolution->available);
        self::assertSame($socket, $resolution->mountSource);
    }

    public function testLinuxResolvesSymlinkToSocket(): void
    {
        // SSH_AUTH_SOCK is often a symlink to the real socket; filetype() reports
        // `link`, so the resolver must follow it before the socket-type check.
        $socket = $this->createUnixSocket($this->tempDir.'/real-agent.sock');
        $link = $this->tempDir.'/agent-link.sock';
        symlink($socket, $link);

        $resolver = new HostSshAgentResolver(
            filesystem: $this->filesystem,
            osFamily: 'Linux',
            authSock: $link,
        );

        $resolution = $resolver->resolve('env');

        self::assertTrue($resolution->available);
        self::assertSame($link, $resolution->mountSource);
    }

    public function testLinuxExplicitPathUnavailableWhenMissing(): void
    {
        $resolver = new HostSshAgentResolver(
            filesystem: $this->filesystem,
            osFamily: 'Linux',
            authSock: '',
        );

        $resolution = $resolver->resolve($this->tempDir.'/nope.sock');

        self::assertFalse($resolution->available);
        self::assertNull($resolution->mountSource);
        self::assertNotNull($resolution->reason);
    }

    public function testUnsupportedOsReportsUnavailableNamingTheOs(): void
    {
        $resolver = new HostSshAgentResolver(
            filesystem: $this->filesystem,
            osFamily: 'Windows',
            authSock: '',
        );

        $resolution = $resolver->resolve();

        self::assertFalse($resolution->available);
        self::assertNull($resolution->mountSource);
        self::assertNotNull($resolution->reason);
        self::assertStringContainsString('Windows', $resolution->reason);
    }

    public function testReturnsResolutionDto(): void
    {
        $resolver = new HostSshAgentResolver(
            filesystem: $this->filesystem,
            osFamily: 'Darwin',
        );

        self::assertInstanceOf(HostSshAgentResolution::class, $resolver->resolve());
    }

    private function createUnixSocket(?string $path = null): string
    {
        $path ??= $this->tempDir.'/agent.sock';
        $server = stream_socket_server('unix://'.$path, $errno, $errstr);
        if ($server === false) {
            self::markTestSkipped(sprintf('Could not create unix socket: %s (%d)', $errstr, $errno));
        }

        // Keep the resource alive for the duration of the test.
        $this->sockets[] = $server;

        return $path;
    }

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->tempDir = sys_get_temp_dir().'/dde-host-agent-'.bin2hex(random_bytes(6));
        $this->filesystem->mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            fclose($socket);
        }

        $this->filesystem->remove($this->tempDir);
    }
}
