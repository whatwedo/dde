<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Manager\DockerManager;
use App\Model\ContainerConfig;
use App\Model\ContainerInfo;
use App\Model\ContainerStatus;
use App\Service\TraefikService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[AllowMockObjectsWithoutExpectations]
final class TraefikServiceTest extends TestCase
{
    private DockerManager&MockObject $dockerManager;

    private string $tempDir;

    private TraefikService $service;

    public function testGetName(): void
    {
        $this->assertSame('traefik', $this->service->getName());
    }

    public function testGetContainerName(): void
    {
        $this->assertSame('dde-traefik', $this->service->getContainerName());
    }

    public function testGetImageName(): void
    {
        $this->assertSame('traefik:v3', $this->service->getImageName());
    }

    public function testGetContainerConfigReturnsCorrectConfig(): void
    {
        $config = $this->service->getContainerConfig();

        $this->assertInstanceOf(ContainerConfig::class, $config);
        $this->assertSame('traefik:v3', $config->image);
        $this->assertSame('dde-traefik', $config->containerName);
        $this->assertSame(['127.0.0.1:80:80', '127.0.0.1:443:443'], $config->ports);
        $this->assertSame('unless-stopped', $config->restartPolicy);
        $this->assertArrayHasKey('dde.managed', $config->labels);
        $this->assertSame('true', $config->labels['dde.managed']);
    }

    public function testPortsAreBoundToLocalhost(): void
    {
        $config = $this->service->getContainerConfig();
        foreach ($config->ports as $port) {
            $this->assertStringStartsWith('127.0.0.1:', $port);
        }
    }

    public function testGetContainerConfigIncludesDockerSocket(): void
    {
        $config = $this->service->getContainerConfig();

        $this->assertArrayHasKey('/var/run/docker.sock', $config->volumes);
        $this->assertSame('/var/run/docker.sock:ro', $config->volumes['/var/run/docker.sock']);
    }

    public function testGetContainerConfigIncludesTraefikVolume(): void
    {
        $config = $this->service->getContainerConfig();

        $this->assertArrayHasKey($this->tempDir.'/traefik', $config->volumes);
        $this->assertSame('/etc/traefik', $config->volumes[$this->tempDir.'/traefik']);
    }

    public function testGetContainerConfigIncludesCertsVolume(): void
    {
        $config = $this->service->getContainerConfig();

        $this->assertArrayHasKey($this->tempDir.'/certs', $config->volumes);
        $this->assertSame('/certs:ro', $config->volumes[$this->tempDir.'/certs']);
    }

    public function testEnsureStaticConfigCreatesFile(): void
    {
        $this->service->ensureStaticConfig();

        $configPath = $this->tempDir.'/traefik/traefik.yml';
        $this->assertFileExists($configPath);

        $content = file_get_contents($configPath);
        $this->assertIsString($content);
        $this->assertStringContainsString('entryPoints:', $content);
        $this->assertStringContainsString('address: ":80"', $content);
        $this->assertStringContainsString('address: ":443"', $content);
        $this->assertStringContainsString('exposedByDefault: false', $content);
        $this->assertStringContainsString('network: dde', $content);
        $this->assertStringContainsString('directory: /etc/traefik/dynamic', $content);
        $this->assertStringContainsString('watch: true', $content);
    }

    public function testEnsureDynamicConfigDirCreatesDirectory(): void
    {
        $this->service->ensureDynamicConfigDir();

        $this->assertDirectoryExists($this->tempDir.'/traefik/dynamic');
    }

    public function testEnsureNetworkCreatesNetworkWhenNotExists(): void
    {
        $this->dockerManager
            ->expects($this->once())
            ->method('networkExists')
            ->with('dde')
            ->willReturn(false);

        $this->dockerManager
            ->expects($this->once())
            ->method('createNetwork')
            ->with('dde');

        $this->service->ensureNetwork();
    }

    public function testEnsureNetworkSkipsWhenAlreadyExists(): void
    {
        $this->dockerManager
            ->expects($this->once())
            ->method('networkExists')
            ->with('dde')
            ->willReturn(true);

        $this->dockerManager
            ->expects($this->never())
            ->method('createNetwork');

        $this->service->ensureNetwork();
    }

    public function testStartCallsEnsureMethodsAndDelegates(): void
    {
        $this->dockerManager
            ->method('isContainerRunning')
            ->with('dde-traefik')
            ->willReturn(false);

        $this->dockerManager
            ->method('containerExists')
            ->with('dde-traefik')
            ->willReturn(false);

        $this->dockerManager
            ->method('networkExists')
            ->with('dde')
            ->willReturn(true);

        $this->dockerManager
            ->expects($this->once())
            ->method('run')
            ->with($this->isInstanceOf(ContainerConfig::class));

        $this->service->start();

        $this->assertFileExists($this->tempDir.'/traefik/traefik.yml');
        $this->assertDirectoryExists($this->tempDir.'/traefik/dynamic');
        $this->assertDirectoryExists($this->tempDir.'/certs');
    }

    public function testStartReactivatesExistingStoppedContainer(): void
    {
        $this->dockerManager
            ->method('isContainerRunning')
            ->with('dde-traefik')
            ->willReturn(false);

        $this->dockerManager
            ->method('containerExists')
            ->with('dde-traefik')
            ->willReturn(true);

        $this->dockerManager
            ->method('networkExists')
            ->with('dde')
            ->willReturn(true);

        $this->dockerManager
            ->expects($this->once())
            ->method('start')
            ->with('dde-traefik');

        $this->dockerManager
            ->expects($this->never())
            ->method('run');

        $this->service->start();
    }

    public function testStartReconnectsTraefikToExistingProjectNetworksAfterRecreate(): void
    {
        $this->dockerManager
            ->method('isContainerRunning')
            ->with('dde-traefik')
            ->willReturn(false);

        $this->dockerManager
            ->method('containerExists')
            ->with('dde-traefik')
            ->willReturn(true);

        $this->dockerManager
            ->method('networkExists')
            ->with('dde')
            ->willReturn(true);

        $this->dockerManager
            ->method('getContainersByLabel')
            ->with('dde.service')
            ->willReturn([
                new ContainerInfo('dde-traefik', ContainerStatus::RUNNING, 'traefik:v3'),
                new ContainerInfo('dde-mariadb-11.8', ContainerStatus::RUNNING, 'mariadb:11.8'),
            ]);

        $this->dockerManager
            ->method('listNetworksWithPrefix')
            ->with('dde-services-')
            ->willReturn(['dde-services-alpha', 'dde-services-beta', 'dde-services-empty']);

        $this->dockerManager
            ->method('getConnectedContainerNames')
            ->willReturnMap([
                ['dde-services-alpha', ['alpha-web-1', 'dde-mariadb-11.8']],
                ['dde-services-beta', ['beta-web-1']],
                ['dde-services-empty', []],
            ]);

        $connectCalls = [];
        $this->dockerManager
            ->expects($this->exactly(2))
            ->method('connectContainerToNetwork')
            ->willReturnCallback(static function (string $container, string $network) use (&$connectCalls): void {
                $connectCalls[] = [$container, $network];
            });

        $this->dockerManager
            ->expects($this->exactly(2))
            ->method('start')
            ->with('dde-traefik');

        $this->dockerManager
            ->expects($this->once())
            ->method('stop')
            ->with('dde-traefik');

        $this->service->start();

        self::assertContains(['dde-traefik', 'dde-services-alpha'], $connectCalls);
        self::assertContains(['dde-traefik', 'dde-services-beta'], $connectCalls);
    }

    public function testStartSkipsReconciliationWhenTraefikAlreadyAttached(): void
    {
        $this->dockerManager
            ->method('isContainerRunning')
            ->with('dde-traefik')
            ->willReturn(false);

        $this->dockerManager
            ->method('containerExists')
            ->with('dde-traefik')
            ->willReturn(true);

        $this->dockerManager
            ->method('networkExists')
            ->with('dde')
            ->willReturn(true);

        $this->dockerManager
            ->method('getContainersByLabel')
            ->with('dde.service')
            ->willReturn([
                new ContainerInfo('dde-traefik', ContainerStatus::RUNNING, 'traefik:v3'),
            ]);

        $this->dockerManager
            ->method('listNetworksWithPrefix')
            ->with('dde-services-')
            ->willReturn(['dde-services-alpha']);

        $this->dockerManager
            ->method('getConnectedContainerNames')
            ->with('dde-services-alpha')
            ->willReturn(['alpha-web-1', 'dde-traefik']);

        $this->dockerManager
            ->expects($this->never())
            ->method('connectContainerToNetwork');

        $this->dockerManager
            ->expects($this->never())
            ->method('stop');

        $this->service->start();
    }

    public function testStartSkipsReconciliationWhenOnlyDdeManagedContainersAttached(): void
    {
        // A stale network left with only `dde-mariadb-*` or other dde-managed
        // services must not pull Traefik back in. Without the filter, two
        // global services on the same stale network would keep reconciling
        // each other in.
        $this->dockerManager
            ->method('isContainerRunning')
            ->with('dde-traefik')
            ->willReturn(false);

        $this->dockerManager
            ->method('containerExists')
            ->with('dde-traefik')
            ->willReturn(true);

        $this->dockerManager
            ->method('networkExists')
            ->with('dde')
            ->willReturn(true);

        $this->dockerManager
            ->method('getContainersByLabel')
            ->with('dde.service')
            ->willReturn([
                new ContainerInfo('dde-traefik', ContainerStatus::RUNNING, 'traefik:v3'),
                new ContainerInfo('dde-mailpit', ContainerStatus::RUNNING, 'axllent/mailpit:latest'),
                new ContainerInfo('dde-mariadb-11.8', ContainerStatus::RUNNING, 'mariadb:11.8'),
            ]);

        $this->dockerManager
            ->method('listNetworksWithPrefix')
            ->with('dde-services-')
            ->willReturn(['dde-services-stale']);

        $this->dockerManager
            ->method('getConnectedContainerNames')
            ->with('dde-services-stale')
            ->willReturn(['dde-mariadb-11.8', 'dde-mailpit']);

        $this->dockerManager
            ->expects($this->never())
            ->method('connectContainerToNetwork');

        $this->service->start();
    }

    public function testStartReconnectsTraefikToNetworkOfProjectStartingWithDdePrefix(): void
    {
        // Regression: a project whose Compose project name starts with `dde-`
        // (e.g. directory `dde-shop`) ends up with containers like
        // `dde-shop-web-1`. A naive `str_starts_with($name, 'dde-')` filter
        // would treat that as a dde-managed container and the network as
        // empty, leaving the project unreachable after a Traefik recreate.
        $this->dockerManager
            ->method('isContainerRunning')
            ->with('dde-traefik')
            ->willReturn(false);

        $this->dockerManager
            ->method('containerExists')
            ->with('dde-traefik')
            ->willReturn(true);

        $this->dockerManager
            ->method('networkExists')
            ->with('dde')
            ->willReturn(true);

        $this->dockerManager
            ->method('getContainersByLabel')
            ->with('dde.service')
            ->willReturn([
                new ContainerInfo('dde-traefik', ContainerStatus::RUNNING, 'traefik:v3'),
            ]);

        $this->dockerManager
            ->method('listNetworksWithPrefix')
            ->with('dde-services-')
            ->willReturn(['dde-services-dde-shop']);

        $this->dockerManager
            ->method('getConnectedContainerNames')
            ->with('dde-services-dde-shop')
            ->willReturn(['dde-shop-web-1']);

        $this->dockerManager
            ->expects($this->once())
            ->method('connectContainerToNetwork')
            ->with('dde-traefik', 'dde-services-dde-shop');

        $this->service->start();
    }

    public function testStartFallsBackSafelyWhenContainerLabelLookupThrows(): void
    {
        // Regression: when `getContainersByLabel('dde.service')` throws
        // (rare, but the call sits on a defensive catch), we used to
        // silently treat the lookup as empty — then every connected
        // container in every `dde-services-*` network counted as a project
        // container, so Traefik attached itself to truly stale networks too.
        // The catch is still there (we don't want a broken label query to
        // tear down `start()`), but the now-tested behaviour is: degrade to
        // "no project containers detected" for that scan, so reconciliation
        // is a no-op instead of a flood.
        $this->dockerManager
            ->method('isContainerRunning')
            ->with('dde-traefik')
            ->willReturn(false);

        $this->dockerManager
            ->method('containerExists')
            ->with('dde-traefik')
            ->willReturn(true);

        $this->dockerManager
            ->method('networkExists')
            ->with('dde')
            ->willReturn(true);

        $this->dockerManager
            ->method('getContainersByLabel')
            ->with('dde.service')
            ->willThrowException(new \RuntimeException('docker socket unreachable'));

        $this->dockerManager
            ->method('listNetworksWithPrefix')
            ->with('dde-services-')
            ->willReturn(['dde-services-stale', 'dde-services-mariadb-only']);

        // Without the regression's safe fallback, both `dde-mailpit` and
        // `dde-mariadb-11.8` would count as project containers and Traefik
        // would attach to both networks. The new behaviour: no connect, no
        // restart cascade.
        $this->dockerManager
            ->method('getConnectedContainerNames')
            ->willReturnMap([
                ['dde-services-stale', ['dde-mailpit']],
                ['dde-services-mariadb-only', ['dde-mariadb-11.8']],
            ]);

        $this->dockerManager
            ->expects($this->never())
            ->method('connectContainerToNetwork');

        $this->dockerManager
            ->expects($this->never())
            ->method('stop');

        $this->service->start();
    }

    public function testStartSkipsReconciliationForEmptyProjectNetworks(): void
    {
        $this->dockerManager
            ->method('isContainerRunning')
            ->with('dde-traefik')
            ->willReturn(false);

        $this->dockerManager
            ->method('containerExists')
            ->with('dde-traefik')
            ->willReturn(true);

        $this->dockerManager
            ->method('networkExists')
            ->with('dde')
            ->willReturn(true);

        $this->dockerManager
            ->method('getContainersByLabel')
            ->with('dde.service')
            ->willReturn([
                new ContainerInfo('dde-traefik', ContainerStatus::RUNNING, 'traefik:v3'),
            ]);

        $this->dockerManager
            ->method('listNetworksWithPrefix')
            ->with('dde-services-')
            ->willReturn(['dde-services-stale']);

        $this->dockerManager
            ->method('getConnectedContainerNames')
            ->with('dde-services-stale')
            ->willReturn([]);

        $this->dockerManager
            ->expects($this->never())
            ->method('connectContainerToNetwork');

        $this->service->start();
    }

    public function testStartSkipsWhenAlreadyRunning(): void
    {
        $this->dockerManager
            ->method('isContainerRunning')
            ->with('dde-traefik')
            ->willReturn(true);

        $this->dockerManager
            ->method('networkExists')
            ->with('dde')
            ->willReturn(true);

        $this->dockerManager
            ->expects($this->never())
            ->method('run');

        $this->service->start();
    }

    public function testStopOnlyCallsDockerManagerStopWhenRunning(): void
    {
        $this->dockerManager
            ->method('isContainerRunning')
            ->with('dde-traefik')
            ->willReturn(true);

        $this->dockerManager
            ->expects($this->once())
            ->method('stop')
            ->with('dde-traefik');

        $this->dockerManager
            ->expects($this->never())
            ->method('remove');

        $this->service->stop();
    }

    public function testRemoveStopsAndRemovesWhenRunning(): void
    {
        $this->dockerManager
            ->method('containerExists')
            ->with('dde-traefik')
            ->willReturn(true);

        $this->dockerManager
            ->method('isContainerRunning')
            ->with('dde-traefik')
            ->willReturn(true);

        $this->dockerManager
            ->expects($this->once())
            ->method('stop')
            ->with('dde-traefik');

        $this->dockerManager
            ->expects($this->once())
            ->method('remove')
            ->with('dde-traefik');

        $this->service->remove();
    }

    public function testStopSkipsWhenNotRunning(): void
    {
        $this->dockerManager
            ->method('isContainerRunning')
            ->with('dde-traefik')
            ->willReturn(false);

        $this->dockerManager
            ->expects($this->never())
            ->method('stop');

        $this->dockerManager
            ->expects($this->never())
            ->method('remove');

        $this->service->stop();
    }

    public function testIsRunningDelegatesToDockerManager(): void
    {
        $this->dockerManager
            ->expects($this->once())
            ->method('isContainerRunning')
            ->with('dde-traefik')
            ->willReturn(true);

        $this->assertTrue($this->service->isRunning());
    }

    public function testGenerateRouterNameSanitizesDots(): void
    {
        $this->assertSame('beispiel-test-web', $this->service->generateRouterName('beispiel.test', 'web'));
    }

    public function testGenerateRouterNameWithWorktreeHostname(): void
    {
        $this->assertSame(
            'beispiel-feature-x-test-web',
            $this->service->generateRouterName('beispiel-feature-x.test', 'web'),
        );
    }

    public function testGenerateRouterNameWithDifferentServices(): void
    {
        $this->assertSame('my-app-test-web', $this->service->generateRouterName('my-app.test', 'web'));
        $this->assertSame('my-app-test-api', $this->service->generateRouterName('my-app.test', 'api'));
        $this->assertSame('my-app-test-worker', $this->service->generateRouterName('my-app.test', 'worker'));
    }

    public function testGenerateLabelsSingleHostWithoutPort(): void
    {
        $labels = $this->service->generateLabels(['beispiel.test'], 'web');

        $this->assertCount(4, $labels);
        $this->assertSame('traefik.enable=true', $labels[0]);
        $this->assertSame('traefik.http.routers.beispiel-test-web.rule=Host(`beispiel.test`)', $labels[1]);
        $this->assertSame('traefik.http.routers.beispiel-test-web-tls.rule=Host(`beispiel.test`)', $labels[2]);
        $this->assertSame('traefik.http.routers.beispiel-test-web-tls.tls=true', $labels[3]);
    }

    public function testGenerateLabelsWithPort(): void
    {
        $labels = $this->service->generateLabels(['beispiel.test'], 'web', 8080);

        $this->assertCount(5, $labels);
        $this->assertSame('traefik.http.routers.beispiel-test-web.rule=Host(`beispiel.test`)', $labels[1]);
        $this->assertSame('traefik.http.services.beispiel-test-web.loadbalancer.server.port=8080', $labels[2]);
        $this->assertSame('traefik.http.routers.beispiel-test-web-tls.rule=Host(`beispiel.test`)', $labels[3]);
        $this->assertSame('traefik.http.routers.beispiel-test-web-tls.tls=true', $labels[4]);
    }

    public function testGenerateLabelsMultipleHostnames(): void
    {
        $labels = $this->service->generateLabels(['beispiel.test', 'www.beispiel.test'], 'web');

        $this->assertCount(4, $labels);
        $this->assertSame('traefik.http.routers.beispiel-test-web.rule=Host(`beispiel.test`) || Host(`www.beispiel.test`)', $labels[1]);
        $this->assertSame('traefik.http.routers.beispiel-test-web-tls.rule=Host(`beispiel.test`) || Host(`www.beispiel.test`)', $labels[2]);
    }

    public function testGenerateLabelsMultipleHostnamesWithPort(): void
    {
        $labels = $this->service->generateLabels(['app.test', 'www.app.test'], 'web', 3000);

        $this->assertCount(5, $labels);
        $this->assertSame('traefik.http.routers.app-test-web.rule=Host(`app.test`) || Host(`www.app.test`)', $labels[1]);
        $this->assertSame('traefik.http.services.app-test-web.loadbalancer.server.port=3000', $labels[2]);
        $this->assertSame('traefik.http.routers.app-test-web-tls.rule=Host(`app.test`) || Host(`www.app.test`)', $labels[3]);
    }

    public function testGenerateLabelsRouterNameUsesFirstHostname(): void
    {
        $labels = $this->service->generateLabels(['primary.test', 'alias.test'], 'web');

        // Router name based on first hostname
        $this->assertStringContainsString('primary-test-web', $labels[1]);
    }

    public function testGenerateLabelsForWorktreeHostname(): void
    {
        $labels = $this->service->generateLabels(['beispiel-feature-x.test'], 'web');

        $this->assertCount(4, $labels);
        $this->assertSame('traefik.http.routers.beispiel-feature-x-test-web.rule=Host(`beispiel-feature-x.test`)', $labels[1]);
        $this->assertSame('traefik.http.routers.beispiel-feature-x-test-web-tls.rule=Host(`beispiel-feature-x.test`)', $labels[2]);
    }

    public function testGenerateLabelsThrowsOnEmptyHostnames(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one hostname is required');

        $this->service->generateLabels([], 'web');
    }

    public function testGenerateLabelsForMultipleServicesUniqueRouterNames(): void
    {
        $webLabels = $this->service->generateLabels(['beispiel.test'], 'web');
        $apiLabels = $this->service->generateLabels(['beispiel.test'], 'api');

        $this->assertStringContainsString('beispiel-test-web', $webLabels[1]);
        $this->assertStringContainsString('beispiel-test-api', $apiLabels[1]);

        $this->assertStringContainsString('Host(`beispiel.test`)', $webLabels[1]);
        $this->assertStringContainsString('Host(`beispiel.test`)', $apiLabels[1]);
    }

    protected function setUp(): void
    {
        $this->dockerManager = $this->createMock(DockerManager::class);
        $this->tempDir = sys_get_temp_dir().'/dde-test-'.bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o777, true);

        $this->service = new TraefikService(
            dockerManager: $this->dockerManager,
            filesystem: new Filesystem(),
            dataDir: $this->tempDir,
        );
    }

    protected function tearDown(): void
    {
        $filesystem = new Filesystem();
        $filesystem->remove($this->tempDir);
    }
}
