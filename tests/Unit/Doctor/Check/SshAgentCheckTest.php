<?php

declare(strict_types=1);

namespace Tests\Unit\Doctor\Check;

use App\Config\GlobalConfig;
use App\Config\SshAgentMode;
use App\Doctor\Check\SshAgentCheck;
use App\Doctor\CheckStatus;
use App\Manager\DockerManager;
use App\Manager\GlobalConfigManager;
use App\Service\HostSshAgentResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class SshAgentCheckTest extends TestCase
{
    private string $tempDir;

    private Filesystem $filesystem;

    /**
     * @var list<resource>
     */
    private array $sockets = [];

    public function testGetName(): void
    {
        $check = $this->buildCheck(SshAgentMode::Managed, true);

        self::assertSame('SSH Agent', $check->getName());
    }

    public function testGetPriorityIsZero(): void
    {
        $check = $this->buildCheck(SshAgentMode::Managed, true);

        self::assertSame(0, $check->getPriority());
    }

    // --- Managed mode (today's behaviour, now under the managed branch) ---

    public function testManagedModeReturnsOkWhenContainerIsRunning(): void
    {
        $check = $this->buildCheck(SshAgentMode::Managed, containerRunning: true);

        $result = $check->run();

        self::assertSame(CheckStatus::OK, $result->status);
        self::assertSame('SSH agent container is running', $result->message);
        self::assertSame('', $result->fixHint);
    }

    public function testManagedModeReturnsErrorWhenContainerIsNotRunning(): void
    {
        $check = $this->buildCheck(SshAgentMode::Managed, containerRunning: false);

        $result = $check->run();

        self::assertSame(CheckStatus::ERROR, $result->status);
        self::assertSame('SSH agent container is not running.', $result->message);
        self::assertSame('Run: dde system:up', $result->fixHint);
    }

    public function testManagedModeResultNameMatchesCheckName(): void
    {
        $check = $this->buildCheck(SshAgentMode::Managed, containerRunning: true);
        $result = $check->run();

        self::assertSame($check->getName(), $result->name);
    }

    // --- Host mode, macOS ---

    public function testHostMacOsReturnsOkWhenAuthSockVisible(): void
    {
        $check = $this->buildCheck(
            SshAgentMode::Host,
            containerRunning: false,
            osFamily: 'Darwin',
            authSock: '/private/tmp/host-auth.sock',
        );

        $result = $check->run();

        self::assertSame(CheckStatus::OK, $result->status);
    }

    public function testHostMacOsReturnsErrorReportingLaunchdVisibilityWhenAuthSockMissing(): void
    {
        $check = $this->buildCheck(
            SshAgentMode::Host,
            containerRunning: false,
            osFamily: 'Darwin',
        );

        $result = $check->run();

        self::assertSame(CheckStatus::ERROR, $result->status);
        // The message states the actually-checked condition (SSH_AUTH_SOCK unset
        // in dde's environment); the launchd caveat lives in the fix hint, since
        // dde observes its own SSH_AUTH_SOCK, not what launchd/Docker Desktop see.
        self::assertStringContainsString('SSH_AUTH_SOCK', $result->message);
        self::assertStringContainsString('Docker Desktop', $result->fixHint);
        self::assertStringContainsString('launchd', $result->fixHint);
    }

    public function testHostMacOsErrorHintTellsUserHowToSelfVerify(): void
    {
        $check = $this->buildCheck(
            SshAgentMode::Host,
            containerRunning: false,
            osFamily: 'Darwin',
            authSock: '',
        );

        $result = $check->run();

        self::assertSame(CheckStatus::ERROR, $result->status);
        self::assertStringContainsString('SSH_AUTH_SOCK', $result->fixHint);
    }

    public function testHostMacOsExplicitPathIsRejectedEvenWhenSocketIsLive(): void
    {
        // A directly bind-mounted host socket cannot cross the Docker Desktop /
        // OrbStack VM boundary, so an explicit macOS source is unsupported — even
        // a live socket on the host must not report green (it would forward
        // "Connection refused" inside the container).
        $socket = $this->createUnixSocket();

        $check = $this->buildCheck(
            SshAgentMode::Host,
            containerRunning: false,
            osFamily: 'Darwin',
            authSock: '',
            source: $socket,
        );

        $result = $check->run();

        self::assertSame(CheckStatus::ERROR, $result->status);
        self::assertStringContainsString('macOS', $result->message);
        self::assertStringContainsString('docs/guides/ssh-agent.md', $result->fixHint);
        self::assertStringContainsString('env', $result->fixHint);
    }

    // --- Host mode, Linux ---

    public function testHostLinuxReturnsOkWhenResolvedSocketExistsAndIsSocket(): void
    {
        $socket = $this->createUnixSocket();

        $check = $this->buildCheck(
            SshAgentMode::Host,
            containerRunning: false,
            osFamily: 'Linux',
            authSock: $socket,
        );

        $result = $check->run();

        self::assertSame(CheckStatus::OK, $result->status);
    }

    public function testHostLinuxReturnsErrorWhenSocketMissing(): void
    {
        $check = $this->buildCheck(
            SshAgentMode::Host,
            containerRunning: false,
            osFamily: 'Linux',
            authSock: $this->tempDir.'/does-not-exist.sock',
        );

        $result = $check->run();

        self::assertSame(CheckStatus::ERROR, $result->status);
        self::assertNotSame('', $result->fixHint);
    }

    public function testHostLinuxReturnsErrorWhenAuthSockUnset(): void
    {
        $check = $this->buildCheck(
            SshAgentMode::Host,
            containerRunning: false,
            osFamily: 'Linux',
        );

        $result = $check->run();

        self::assertSame(CheckStatus::ERROR, $result->status);
        // Resolver's reason surfaces the missing prerequisite.
        self::assertStringContainsString('SSH_AUTH_SOCK', $result->message);
        self::assertNotSame('', $result->fixHint);
    }

    public function testHostLinuxReturnsErrorWhenPathExistsButIsNotASocket(): void
    {
        $regularFile = $this->tempDir.'/not-a-socket';
        $this->filesystem->touch($regularFile);

        $check = $this->buildCheck(
            SshAgentMode::Host,
            containerRunning: false,
            osFamily: 'Linux',
            authSock: $regularFile,
        );

        $result = $check->run();

        self::assertSame(CheckStatus::ERROR, $result->status);
        self::assertNotSame('', $result->fixHint);
    }

    /**
     * The managed branch must never depend on Docker even though it inspects a
     * container; the host branches probe sockets/env, not the daemon.
     */
    #[DataProvider('requiresDockerProvider')]
    public function testRequiresDockerByMode(SshAgentMode $mode, bool $expected): void
    {
        $check = $this->buildCheck($mode, containerRunning: true, osFamily: 'Linux');

        self::assertSame($expected, $check->requiresDocker());
    }

    /**
     * @return iterable<string, array{SshAgentMode, bool}>
     */
    public static function requiresDockerProvider(): iterable
    {
        yield 'managed needs the daemon' => [SshAgentMode::Managed, true];
        yield 'host does not need the daemon' => [SshAgentMode::Host, false];
    }

    private function buildCheck(
        SshAgentMode $mode,
        bool $containerRunning,
        string $osFamily = 'Linux',
        string $authSock = '',
        ?string $source = null,
    ): SshAgentCheck {
        // Default authSock to '' (not null): the resolver and the check both
        // fall back to the real process SSH_AUTH_SOCK only on null, so a null
        // default would leak the host agent of whoever runs the suite.
        $dockerManager = $this->createStub(DockerManager::class);
        $dockerManager->method('isContainerRunning')->willReturn($containerRunning);

        $globalConfigManager = $this->createStub(GlobalConfigManager::class);
        $globalConfigManager->method('load')->willReturn(new GlobalConfig(
            sshAgentMode: $mode,
            sshAgentSource: $source,
        ));

        $resolver = new HostSshAgentResolver(
            filesystem: $this->filesystem,
            osFamily: $osFamily,
            authSock: $authSock,
        );

        return new SshAgentCheck(
            dockerManager: $dockerManager,
            globalConfigManager: $globalConfigManager,
            hostSshAgentResolver: $resolver,
            osFamily: $osFamily,
            authSock: $authSock,
        );
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
        $this->tempDir = sys_get_temp_dir().'/dde-ssh-agent-check-'.bin2hex(random_bytes(6));
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
