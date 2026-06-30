<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Manager\DockerManager;
use App\Model\ContainerConfig;
use App\Service\DnsmasqService;
use App\Service\ImageBuilder;
use App\Util\PrivilegeEscalator;
use App\Util\ProcessFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[AllowMockObjectsWithoutExpectations]
final class DnsmasqServiceTest extends TestCase
{
    private DockerManager&MockObject $dockerManager;

    private PrivilegeEscalator&MockObject $escalator;

    private string $tempDir;

    private string $projectDir;

    private DnsmasqService $service;

    private Filesystem $filesystem;

    public function testGetName(): void
    {
        $this->assertSame('dnsmasq', $this->service->getName());
    }

    public function testGetContainerName(): void
    {
        $this->assertSame('dde-dnsmasq', $this->service->getContainerName());
    }

    public function testGetImageName(): void
    {
        $this->assertSame('dde-dnsmasq:local', $this->service->getImageName());
    }

    public function testGetContainerConfigReturnsCorrectConfig(): void
    {
        $config = $this->service->getContainerConfig();

        $this->assertInstanceOf(ContainerConfig::class, $config);
        $this->assertSame('dde-dnsmasq:local', $config->image);
        $this->assertSame('dde-dnsmasq', $config->containerName);
        $this->assertSame(['127.0.0.1:53:53/udp', '127.0.0.1:53:53/tcp'], $config->ports);
        $this->assertArrayHasKey('dde.managed', $config->labels);
        $this->assertSame('true', $config->labels['dde.managed']);
    }

    public function testGetContainerConfigIncludesDnsmasqConfVolume(): void
    {
        $config = $this->service->getContainerConfig();

        $this->assertArrayHasKey($this->tempDir.'/dnsmasq/dnsmasq.conf', $config->volumes);
        $this->assertSame('/etc/dnsmasq.conf:ro', $config->volumes[$this->tempDir.'/dnsmasq/dnsmasq.conf']);
    }

    public function testEnsureConfigCreatesDefaultConfig(): void
    {
        // R4.1 Negative regression: ensureConfig() must NOT route through escalator.
        $this->escalator->expects($this->never())->method($this->anything());

        $this->service->ensureConfig();

        $configPath = $this->tempDir.'/dnsmasq/dnsmasq.conf';
        $this->assertFileExists($configPath);

        $content = file_get_contents($configPath);
        $this->assertIsString($content);
        $this->assertStringContainsString('address=/test/127.0.0.1', $content);
        $this->assertStringContainsString('server=9.9.9.9', $content);
        $this->assertStringContainsString('server=149.112.112.112', $content);
        $this->assertStringContainsString('no-dhcp-interface=', $content);
        $this->assertStringContainsString('log-queries', $content);
        $this->assertStringContainsString('log-facility=-', $content);
    }

    public function testEnsureConfigWithCustomForwardDns(): void
    {
        // R4.1 Negative regression: ensureConfig() must NOT route through escalator.
        $this->escalator->expects($this->never())->method($this->anything());

        $this->service->ensureConfig(['9.9.9.9']);

        $configPath = $this->tempDir.'/dnsmasq/dnsmasq.conf';
        $content = file_get_contents($configPath);
        $this->assertIsString($content);
        $this->assertStringContainsString('server=9.9.9.9', $content);
        $this->assertStringNotContainsString('server=149.112.112.112', $content);
    }

    public function testEnsureConfigDoesNotRouteThroughEscalator(): void
    {
        // R4.1 explicit standalone test: even when the data-dir doesn't exist yet,
        // ensureConfig() routes only through Filesystem, never through the escalator.
        $this->escalator->expects($this->never())->method($this->anything());

        $this->service->ensureConfig();

        $this->assertFileExists($this->tempDir.'/dnsmasq/dnsmasq.conf');
    }

    public function testBuildImageSkipsWhenHashMatches(): void
    {
        $dockerfileContent = file_get_contents($this->projectDir.'/resources/docker/dnsmasq/Dockerfile');
        $this->assertIsString($dockerfileContent);
        $hash = hash('xxh128', $dockerfileContent);

        $hashFile = $this->tempDir.'/dnsmasq/.build-hash';
        $this->filesystem->mkdir(dirname($hashFile));
        $this->filesystem->dumpFile($hashFile, $hash);

        $this->dockerManager
            ->expects($this->once())
            ->method('imageExists')
            ->with('dde-dnsmasq:local')
            ->willReturn(true);

        $this->dockerManager
            ->expects($this->never())
            ->method('buildImage');

        $this->service->buildImage();
    }

    public function testBuildImageBuildsWhenNoHashFile(): void
    {
        $this->dockerManager
            ->expects($this->once())
            ->method('buildImage')
            ->with($this->stringStartsWith(sys_get_temp_dir().'/dde-dnsmasq-'), 'dde-dnsmasq:local', null, false);

        $this->service->buildImage();

        $hashFile = $this->tempDir.'/dnsmasq/.build-hash';
        $this->assertFileExists($hashFile);
    }

    public function testBuildImageRebuildsWhenHashDiffers(): void
    {
        $hashFile = $this->tempDir.'/dnsmasq/.build-hash';
        $this->filesystem->mkdir(dirname($hashFile));
        $this->filesystem->dumpFile($hashFile, 'old-hash');

        $this->dockerManager
            ->method('imageExists')
            ->willReturn(true);

        $this->dockerManager
            ->expects($this->once())
            ->method('buildImage')
            ->with($this->stringStartsWith(sys_get_temp_dir().'/dde-dnsmasq-'), 'dde-dnsmasq:local', null, false);

        $this->service->buildImage();
    }

    public function testBuildImageRebuildsWhenImageDoesNotExist(): void
    {
        $dockerfileContent = file_get_contents($this->projectDir.'/resources/docker/dnsmasq/Dockerfile');
        $this->assertIsString($dockerfileContent);
        $hash = hash('xxh128', $dockerfileContent);

        $hashFile = $this->tempDir.'/dnsmasq/.build-hash';
        $this->filesystem->mkdir(dirname($hashFile));
        $this->filesystem->dumpFile($hashFile, $hash);

        $this->dockerManager
            ->expects($this->once())
            ->method('imageExists')
            ->with('dde-dnsmasq:local')
            ->willReturn(false);

        $this->dockerManager
            ->expects($this->once())
            ->method('buildImage')
            ->with($this->stringStartsWith(sys_get_temp_dir().'/dde-dnsmasq-'), 'dde-dnsmasq:local', null, false);

        $this->service->buildImage();
    }

    public function testBuildWithPullPassesPullFlagToImageBuilder(): void
    {
        $this->dockerManager
            ->expects($this->once())
            ->method('buildImage')
            ->with($this->anything(), 'dde-dnsmasq:local', null, true);

        $this->service->build(true);
    }

    public function testBuildImageThrowsWhenDockerfileNotFound(): void
    {
        // Remove the Dockerfile
        $this->filesystem->remove($this->projectDir.'/resources/docker/dnsmasq/Dockerfile');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Dockerfile not found');

        $this->service->buildImage();
    }

    public function testGetResolverContent(): void
    {
        $this->assertSame("nameserver 127.0.0.1\n", $this->service->getResolverContent());
    }

    public function testStartCallsEnsureConfigAndBuildImage(): void
    {
        $this->dockerManager
            ->method('isContainerRunning')
            ->willReturnMap([['dde-dnsmasq', false]]);

        $this->dockerManager
            ->method('containerExists')
            ->willReturnMap([['dde-dnsmasq', false]]);

        $this->dockerManager
            ->expects($this->once())
            ->method('buildImage')
            ->with($this->stringStartsWith(sys_get_temp_dir().'/dde-dnsmasq-'), 'dde-dnsmasq:local', null, false);

        $this->dockerManager
            ->expects($this->once())
            ->method('run')
            ->with($this->isInstanceOf(ContainerConfig::class));

        $this->service->start();

        $this->assertFileExists($this->tempDir.'/dnsmasq/dnsmasq.conf');
    }

    public function testStartSkipsWhenAlreadyRunning(): void
    {
        $this->dockerManager
            ->method('isContainerRunning')
            ->willReturnMap([['dde-dnsmasq', true]]);

        $this->dockerManager
            ->expects($this->once())
            ->method('buildImage');

        $this->dockerManager
            ->expects($this->never())
            ->method('run');

        $this->service->start();
    }

    public function testStopOnlyCallsDockerManagerStopWhenRunning(): void
    {
        $this->dockerManager
            ->method('isContainerRunning')
            ->willReturnMap([['dde-dnsmasq', true]]);

        $this->dockerManager
            ->expects($this->once())
            ->method('stop')
            ->with('dde-dnsmasq');

        $this->dockerManager
            ->expects($this->never())
            ->method('remove');

        $this->service->stop();
    }

    public function testRemoveStopsAndRemovesWhenRunning(): void
    {
        $this->dockerManager
            ->method('containerExists')
            ->willReturnMap([['dde-dnsmasq', true]]);

        $this->dockerManager
            ->method('isContainerRunning')
            ->willReturnMap([['dde-dnsmasq', true]]);

        $this->dockerManager
            ->expects($this->once())
            ->method('stop')
            ->with('dde-dnsmasq');

        $this->dockerManager
            ->expects($this->once())
            ->method('remove')
            ->with('dde-dnsmasq');

        $this->service->remove();
    }

    public function testIsRunningDelegatesToDockerManager(): void
    {
        $this->dockerManager
            ->expects($this->once())
            ->method('isContainerRunning')
            ->with('dde-dnsmasq')
            ->willReturn(true);

        $this->assertTrue($this->service->isRunning());
    }

    public function testConfigureDnsSystemdResolvedRoutesThroughEscalator(): void
    {
        // R1.2: systemd-resolved /etc/** writes go through PrivilegeEscalator.
        $configDir = '/etc/systemd/resolved.conf.d';
        $configFile = $configDir.'/dde-test.conf';
        $expectedContent = "[Resolve]\nDNS=127.0.0.1\nDomains=~test\n";

        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('exists')->willReturn(false);

        $service = $this->buildService($filesystem);

        $this->escalator->expects($this->once())
            ->method('ensureDir')
            ->with($configDir);
        $this->escalator->expects($this->once())
            ->method('writeFile')
            ->with($configFile, $expectedContent);
        $this->escalator->expects($this->once())
            ->method('run')
            ->with(['systemctl', 'restart', 'systemd-resolved']);

        $this->invokePrivate($service, 'configureDnsSystemdResolved');
    }

    public function testConfigureDnsSystemdResolvedShortCircuitsWhenContentMatches(): void
    {
        // R2.1: existence short-circuit avoids unnecessary escalation.
        $expectedContent = "[Resolve]\nDNS=127.0.0.1\nDomains=~test\n";

        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('exists')->willReturn(true);
        $filesystem->method('readFile')->willReturn($expectedContent);

        $service = $this->buildService($filesystem);

        $this->escalator->expects($this->never())->method($this->anything());

        $this->invokePrivate($service, 'configureDnsSystemdResolved');
    }

    public function testConfigureDnsNetworkManagerRoutesThroughEscalator(): void
    {
        // R1.3: NetworkManager /etc/** writes go through PrivilegeEscalator.
        $configDir = '/etc/NetworkManager/dnsmasq.d';
        $configFile = $configDir.'/dde-test.conf';
        $expectedContent = "server=/test/127.0.0.1\n";

        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('exists')->willReturn(false);

        $service = $this->buildService($filesystem);

        $this->escalator->expects($this->once())
            ->method('ensureDir')
            ->with($configDir);
        $this->escalator->expects($this->once())
            ->method('writeFile')
            ->with($configFile, $expectedContent);
        $this->escalator->expects($this->once())
            ->method('run')
            ->with(['systemctl', 'restart', 'NetworkManager']);

        $this->invokePrivate($service, 'configureDnsNetworkManager');
    }

    public function testConfigureDnsNetworkManagerShortCircuitsWhenContentMatches(): void
    {
        $expectedContent = "server=/test/127.0.0.1\n";

        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('exists')->willReturn(true);
        $filesystem->method('readFile')->willReturn($expectedContent);

        $service = $this->buildService($filesystem);

        $this->escalator->expects($this->never())->method($this->anything());

        $this->invokePrivate($service, 'configureDnsNetworkManager');
    }

    /**
     * Build a service instance with a custom (mocked) Filesystem so the
     * existence short-circuit can be exercised without touching the real /etc.
     */
    private function buildService(Filesystem $filesystem): DnsmasqService
    {
        return new DnsmasqService(
            dockerManager: $this->dockerManager,
            filesystem: $filesystem,
            imageBuilder: new ImageBuilder($this->dockerManager, $this->filesystem),
            escalator: $this->escalator,
            projectDir: $this->projectDir,
            dataDir: $this->tempDir,
            processFactory: new ProcessFactory(),
        );
    }

    private function invokePrivate(DnsmasqService $service, string $method): void
    {
        $reflection = new \ReflectionMethod($service, $method);
        $reflection->invoke($service);
    }

    protected function setUp(): void
    {
        $this->dockerManager = $this->createMock(DockerManager::class);
        $this->escalator = $this->createMock(PrivilegeEscalator::class);
        $this->filesystem = new Filesystem();
        $this->tempDir = sys_get_temp_dir().'/dde-test-'.bin2hex(random_bytes(8));
        $this->projectDir = $this->tempDir.'/project';
        mkdir($this->tempDir, 0o777, true);
        mkdir($this->projectDir.'/resources/docker/dnsmasq', 0o777, true);

        // Copy Dockerfile to temp project dir
        copy(
            dirname(__DIR__, 3).'/resources/docker/dnsmasq/Dockerfile',
            $this->projectDir.'/resources/docker/dnsmasq/Dockerfile',
        );

        $this->service = new DnsmasqService(
            dockerManager: $this->dockerManager,
            filesystem: $this->filesystem,
            imageBuilder: new ImageBuilder($this->dockerManager, $this->filesystem),
            escalator: $this->escalator,
            projectDir: $this->projectDir,
            dataDir: $this->tempDir,
        );
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->tempDir);
    }
}
