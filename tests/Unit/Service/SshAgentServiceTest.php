<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Config\GlobalConfig;
use App\Manager\DockerManager;
use App\Manager\GlobalConfigManager;
use App\Model\ContainerConfig;
use App\Model\UserContext;
use App\Service\ImageBuilder;
use App\Service\SshAgentService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[AllowMockObjectsWithoutExpectations]
final class SshAgentServiceTest extends TestCase
{
    private DockerManager&MockObject $dockerManager;

    private GlobalConfigManager&MockObject $globalConfigManager;

    private string $tempDir;

    private string $projectDir;

    private Filesystem $filesystem;

    public function testGetName(): void
    {
        $service = $this->createService();

        $this->assertSame('ssh-agent', $service->getName());
    }

    public function testGetContainerName(): void
    {
        $service = $this->createService();

        $this->assertSame('dde-ssh-agent', $service->getContainerName());
    }

    public function testGetImageName(): void
    {
        $service = $this->createService();

        $this->assertSame('dde-ssh-agent:local', $service->getImageName());
    }

    public function testGetContainerConfigReturnsCorrectConfig(): void
    {
        $service = $this->createService();
        $config = $service->getContainerConfig();

        $this->assertInstanceOf(ContainerConfig::class, $config);
        $this->assertSame('dde-ssh-agent:local', $config->image);
        $this->assertSame('dde-ssh-agent', $config->containerName);
        $this->assertArrayHasKey('dde.managed', $config->labels);
        $this->assertSame('true', $config->labels['dde.managed']);
    }

    public function testGetContainerConfigHasSocketVolume(): void
    {
        $service = $this->createService();
        $config = $service->getContainerConfig();

        $this->assertArrayHasKey('dde_ssh-agent_socket-dir', $config->volumes);
        $this->assertSame('/tmp/ssh-agent', $config->volumes['dde_ssh-agent_socket-dir']);
    }

    public function testGetContainerConfigHasEnvironmentVariables(): void
    {
        $service = $this->createService();
        $config = $service->getContainerConfig();

        $this->assertArrayHasKey('DDE_UID', $config->environment);
        $this->assertArrayHasKey('DDE_GID', $config->environment);
        $this->assertSame((string) posix_getuid(), $config->environment['DDE_UID']);
        $this->assertSame((string) posix_getgid(), $config->environment['DDE_GID']);
    }

    public function testGetContainerConfigMountsConfiguredKeys(): void
    {
        $keyPath = $this->tempDir.'/ssh/id_rsa';
        $this->filesystem->mkdir(dirname($keyPath));
        $this->filesystem->dumpFile($keyPath, "-----BEGIN PRIVATE KEY-----\ntest\n-----END PRIVATE KEY-----");

        $service = $this->createService(sshKeys: [$keyPath]);
        $config = $service->getContainerConfig();

        $this->assertArrayHasKey($keyPath, $config->volumes);
        $this->assertSame('/home/dde/.ssh/id_rsa:ro', $config->volumes[$keyPath]);
    }

    public function testGetContainerConfigMountsMultipleKeys(): void
    {
        $key1 = $this->tempDir.'/ssh/id_rsa';
        $key2 = $this->tempDir.'/ssh/id_ed25519';
        $this->filesystem->mkdir(dirname($key1));
        $this->filesystem->dumpFile($key1, 'key1');
        $this->filesystem->dumpFile($key2, 'key2');

        $service = $this->createService(sshKeys: [$key1, $key2]);
        $config = $service->getContainerConfig();

        $this->assertArrayHasKey($key1, $config->volumes);
        $this->assertArrayHasKey($key2, $config->volumes);
        $this->assertSame('/home/dde/.ssh/id_rsa:ro', $config->volumes[$key1]);
        $this->assertSame('/home/dde/.ssh/id_ed25519:ro', $config->volumes[$key2]);
    }

    public function testGetConfiguredKeysReturnsExplicitKeys(): void
    {
        $service = $this->createService(sshKeys: ['/path/to/key']);

        $this->assertSame(['/path/to/key'], $service->getConfiguredKeys());
    }

    public function testGetConfiguredKeysExpandsTilde(): void
    {
        $fakeHome = $this->tempDir.'/home';
        $service = $this->createService(sshKeys: ['~/.ssh/id_ed25519'], userHomeDir: $fakeHome);

        $this->assertSame([$fakeHome.'/.ssh/id_ed25519'], $service->getConfiguredKeys());
    }

    public function testGetConfiguredKeysFallsBackToDetection(): void
    {
        $service = $this->createService();

        // With no configured keys, it falls back to auto-detection
        // Result depends on host ~/.ssh — just verify it returns an array
        $this->assertIsArray($service->getConfiguredKeys());
    }

    public function testDetectSshKeysFindsPrivateKeys(): void
    {
        $fakeHome = $this->tempDir.'/fakehome';
        $sshDir = $fakeHome.'/.ssh';
        $this->filesystem->mkdir($sshDir);
        $this->filesystem->dumpFile($sshDir.'/id_rsa', "-----BEGIN RSA PRIVATE KEY-----\ntest\n-----END RSA PRIVATE KEY-----");
        $this->filesystem->dumpFile($sshDir.'/id_rsa.pub', 'ssh-rsa AAAA...');
        $this->filesystem->dumpFile($sshDir.'/id_ed25519', "-----BEGIN OPENSSH PRIVATE KEY-----\ntest\n-----END OPENSSH PRIVATE KEY-----");
        $this->filesystem->dumpFile($sshDir.'/known_hosts', 'github.com ...');
        $this->filesystem->dumpFile($sshDir.'/config', 'Host *');

        $service = $this->createService(userHomeDir: $fakeHome);
        $keys = $service->detectSshKeys();

        $this->assertContains($sshDir.'/id_rsa', $keys);
        $this->assertContains($sshDir.'/id_ed25519', $keys);
        $this->assertNotContains($sshDir.'/id_rsa.pub', $keys);
        $this->assertNotContains($sshDir.'/known_hosts', $keys);
        $this->assertNotContains($sshDir.'/config', $keys);
    }

    public function testDetectSshKeysReturnsEmptyWhenNoSshDir(): void
    {
        $service = $this->createService(userHomeDir: $this->tempDir.'/nonexistent');
        $this->assertSame([], $service->detectSshKeys());
    }

    public function testBuildImageSkipsWhenHashMatches(): void
    {
        $dockerfilePath = $this->projectDir.'/resources/docker/ssh-agent/Dockerfile';
        $runShPath = $this->projectDir.'/resources/docker/ssh-agent/run.sh';

        $dockerfileContent = file_get_contents($dockerfilePath);
        $runShContent = file_get_contents($runShPath);
        $this->assertIsString($dockerfileContent);
        $this->assertIsString($runShContent);
        $contextHash = hash('xxh128', $dockerfileContent.$runShContent);

        $hashFile = $this->tempDir.'/ssh-agent/.build-hash';
        $this->filesystem->mkdir(dirname($hashFile));
        $this->filesystem->dumpFile($hashFile, $contextHash);

        $this->dockerManager
            ->expects($this->once())
            ->method('imageExists')
            ->with('dde-ssh-agent:local')
            ->willReturn(true);

        $this->dockerManager
            ->expects($this->never())
            ->method('buildImage');

        $service = $this->createService();
        $service->buildImage();
    }

    public function testBuildImageBuildsWhenNoHashFile(): void
    {
        $this->dockerManager
            ->expects($this->once())
            ->method('buildImage')
            ->with($this->stringStartsWith(sys_get_temp_dir().'/dde-ssh-agent-'), 'dde-ssh-agent:local');

        $service = $this->createService();
        $service->buildImage();

        $hashFile = $this->tempDir.'/ssh-agent/.build-hash';
        $this->assertFileExists($hashFile);
    }

    public function testBuildImageThrowsWhenDockerfileNotFound(): void
    {
        $this->filesystem->remove($this->projectDir.'/resources/docker/ssh-agent/Dockerfile');

        $service = $this->createService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Dockerfile not found');

        $service->buildImage();
    }

    public function testStartCallsBuildImageAndRun(): void
    {
        $this->dockerManager
            ->method('isContainerRunning')
            ->with('dde-ssh-agent')
            ->willReturn(false);

        $this->dockerManager
            ->expects($this->once())
            ->method('buildImage')
            ->with($this->stringStartsWith(sys_get_temp_dir().'/dde-ssh-agent-'), 'dde-ssh-agent:local');

        $this->dockerManager
            ->expects($this->once())
            ->method('run')
            ->with($this->isInstanceOf(ContainerConfig::class));

        $service = $this->createService();
        $service->start();
    }

    public function testStartSkipsRunWhenAlreadyRunning(): void
    {
        $this->dockerManager
            ->method('isContainerRunning')
            ->with('dde-ssh-agent')
            ->willReturn(true);

        $this->dockerManager
            ->expects($this->once())
            ->method('buildImage');

        $this->dockerManager
            ->expects($this->never())
            ->method('run');

        $service = $this->createService();
        $service->start();
    }

    public function testStopCallsDockerManagerStopAndRemove(): void
    {
        $this->dockerManager
            ->method('isContainerRunning')
            ->with('dde-ssh-agent')
            ->willReturn(true);

        $this->dockerManager
            ->expects($this->once())
            ->method('stop')
            ->with('dde-ssh-agent');

        $this->dockerManager
            ->expects($this->once())
            ->method('remove')
            ->with('dde-ssh-agent');

        $service = $this->createService();
        $service->stop();
    }

    public function testIsRunningDelegatesToDockerManager(): void
    {
        $this->dockerManager
            ->expects($this->once())
            ->method('isContainerRunning')
            ->with('dde-ssh-agent')
            ->willReturn(true);

        $service = $this->createService();
        $this->assertTrue($service->isRunning());
    }

    /**
     * @param array<string> $sshKeys
     */
    private function createService(array $sshKeys = [], string $userHomeDir = '/tmp'): SshAgentService
    {
        $this->globalConfigManager->method('load')->willReturn(new GlobalConfig(sshKeys: $sshKeys));

        return new SshAgentService(
            dockerManager: $this->dockerManager,
            filesystem: $this->filesystem,
            imageBuilder: new ImageBuilder($this->dockerManager, $this->filesystem),
            userContext: new UserContext(),
            globalConfigManager: $this->globalConfigManager,
            projectDir: $this->projectDir,
            userHomeDir: $userHomeDir,
            dataDir: $this->tempDir,
        );
    }

    protected function setUp(): void
    {
        $this->dockerManager = $this->createMock(DockerManager::class);
        $this->globalConfigManager = $this->createMock(GlobalConfigManager::class);
        $this->filesystem = new Filesystem();
        $this->tempDir = sys_get_temp_dir().'/dde-test-'.bin2hex(random_bytes(8));
        $this->projectDir = $this->tempDir.'/project';
        mkdir($this->tempDir, 0o777, true);
        mkdir($this->projectDir.'/resources/docker/ssh-agent', 0o777, true);

        // Copy Dockerfile and run.sh to temp project dir
        copy(
            dirname(__DIR__, 3).'/resources/docker/ssh-agent/Dockerfile',
            $this->projectDir.'/resources/docker/ssh-agent/Dockerfile',
        );
        copy(
            dirname(__DIR__, 3).'/resources/docker/ssh-agent/run.sh',
            $this->projectDir.'/resources/docker/ssh-agent/run.sh',
        );
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->tempDir);
    }
}
