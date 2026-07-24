<?php

declare(strict_types=1);

namespace Tests\Unit\Manager;

use App\Config\GlobalConfig;
use App\Config\ProjectConfig;
use App\Config\ResolvedConfig;
use App\Config\SshAgentMode;
use App\Database\DatabaseAdapterRegistry;
use App\Database\MariaDbAdapter;
use App\Database\PostgresAdapter;
use App\Manager\DockerComposeManager;
use App\Manager\DockerManager;
use App\Manager\ImageManager;
use App\Manager\MkcertManager;
use App\Manager\ProjectLifecycleManager;
use App\Manager\SystemServiceManager;
use App\Manager\WorktreeManager;
use App\Model\ContainerConfig;
use App\Model\ServiceDefinition;
use App\Service\AbstractSystemService;
use App\Service\HostSshAgentResolver;
use App\Service\ProjectNetworkAwareInterface;
use App\Service\ServiceRegistry;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Filesystem\Filesystem;

#[AllowMockObjectsWithoutExpectations]
final class ProjectLifecycleManagerTest extends TestCase
{
    public $certificateManager;

    private DockerComposeManager&MockObject $dockerComposeManager;

    private DockerManager&MockObject $dockerManager;

    private ImageManager&MockObject $imageManager;

    private Filesystem&MockObject $filesystem;

    private Filesystem&Stub $systemFilesystem;

    private ProjectNetworkAwareSystemServiceTestDouble&Stub $traefikStub;

    private HostSshAgentResolver $hostSshAgentResolver;

    private string $presentSocketPath;

    /**
     * @var resource|false
     */
    private $presentSocket;

    private ProjectLifecycleManager $manager;

    #[AllowMockObjectsWithoutExpectations]
    public function testUpEnsuresServicesAndCallsComposeUp(): void
    {
        $config = $this->createConfig([
            new ServiceDefinition(name: 'mariadb', version: '11.8'),
        ]);
        $projectDir = '/tmp/test-project';

        $this->dockerManager->method('isContainerRunning')
            ->willReturn(false);

        $this->dockerManager->method('getContainersByLabel')
            ->willReturn([]);

        $this->dockerManager->expects($this->once())
            ->method('run');

        $this->systemFilesystem->method('mkdir');

        $this->dockerComposeManager->expects($this->once())
            ->method('findComposeFile')
            ->with($projectDir)
            ->willReturn($projectDir.'/docker-compose.yml');

        $this->imageManager->expects($this->once())
            ->method('ensureDevLayers')
            ->willReturn(null);

        $this->dockerComposeManager->expects($this->once())
            ->method('build')
            ->with($projectDir, [], null);

        $this->dockerComposeManager->expects($this->once())
            ->method('generateOverride')
            ->with($config, $projectDir, null, 'dde-services-test-project')
            ->willReturn('/tmp/override.yml');

        $this->dockerComposeManager->expects($this->once())
            ->method('up')
            ->with(
                $projectDir,
                $this->callback(function (array $options): bool {
                    return isset($options['composeFiles'])
                        && $options['build'] === false;
                }),
                null,
            );

        $this->filesystem->expects($this->once())
            ->method('remove')
            ->with('/tmp/override.yml');

        $result = $this->manager->up($config, $projectDir, false);

        $this->assertSame('mariadb', $result['serviceResults'][0]['name']);
        $this->assertSame('started', $result['serviceResults'][0]['status']);
        $this->assertNull($result['devLayerResult']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testUpStartsGlobalServicesAutomatically(): void
    {
        $config = $this->createConfig();
        $projectDir = '/tmp/test-project';

        $globalService = $this->createMock(AbstractSystemService::class);
        $globalService->expects($this->once())
            ->method('start');

        $manager = $this->makeManager([$globalService]);

        $this->dockerComposeManager->method('findComposeFile')
            ->willReturn($projectDir.'/docker-compose.yml');
        $this->imageManager->method('ensureDevLayers')
            ->willReturn(null);
        $this->dockerComposeManager->method('generateOverride')
            ->willReturn('/tmp/override.yml');

        $manager->up($config, $projectDir, false);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testUpWarnsAndContinuesWhenHostAgentIsUnresolvedInHostMode(): void
    {
        // R4.1/R4.3: on a Linux host in `host` mode with no host agent, the
        // bring-up must surface a specific warning naming the missing
        // prerequisite *before* the later git/SSH failure surface, and still
        // bring the project up rather than aborting.
        $resolver = new HostSshAgentResolver(
            osFamily: 'Linux',
            authSock: '',
        );

        $config = $this->createHostModeConfig();
        $projectDir = '/tmp/test-project';
        $output = $this->arrangeHostModeUp($projectDir);

        $manager = $this->makeManager(hostSshAgentResolver: $resolver);

        // The bring-up proceeds to compose up — i.e. it is NOT aborted (R4.2).
        $this->dockerComposeManager->expects($this->once())->method('up');

        $result = $manager->up($config, $projectDir, false, $output);

        // Returned (not written to the transient, decoration-gated progress
        // section) so the command can surface it on non-decorated output too.
        $warning = $result['sshForwardingWarning'];
        self::assertNotNull($warning);
        self::assertStringContainsStringIgnoringCase('SSH', $warning);
        self::assertStringContainsStringIgnoringCase('forwarding', $warning);
        // The specific missing prerequisite from the resolution reason.
        self::assertStringContainsString('SSH_AUTH_SOCK', $warning);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testUpDoesNotWarnWhenHostAgentIsAvailableInHostMode(): void
    {
        // Resolver reports an available socket → no warning fires.
        $resolver = new HostSshAgentResolver(
            osFamily: 'Linux',
            authSock: $this->presentSocketPath,
        );

        $config = $this->createHostModeConfig();
        $projectDir = '/tmp/test-project';
        $output = $this->arrangeHostModeUp($projectDir);

        $manager = $this->makeManager(hostSshAgentResolver: $resolver);

        $result = $manager->up($config, $projectDir, false, $output);

        self::assertNull($result['sshForwardingWarning']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testUpDoesNotWarnInManagedMode(): void
    {
        // Managed mode never consults the host resolver, so even a resolver that
        // would report unavailable must not produce a warning on the up path.
        $resolver = new HostSshAgentResolver(
            osFamily: 'Linux',
            authSock: '',
        );

        $config = $this->createConfig();
        $projectDir = '/tmp/test-project';
        $output = $this->arrangeHostModeUp($projectDir);

        $manager = $this->makeManager(hostSshAgentResolver: $resolver);

        $result = $manager->up($config, $projectDir, false, $output);

        self::assertNull($result['sshForwardingWarning']);
    }

    public function testDownCallsComposeDown(): void
    {
        $config = $this->createConfig([
            new ServiceDefinition(name: 'mariadb', version: '10.11'),
        ]);
        $projectDir = '/tmp/test-project';

        $this->dockerComposeManager->expects($this->once())
            ->method('down')
            ->with($projectDir, [
                'removeOrphans' => true,
            ]);

        $this->dockerManager->expects($this->never())
            ->method('stop');

        $this->manager->down($config, $projectDir, true);
    }

    public function testDownDoesNotStopServices(): void
    {
        $config = $this->createConfig([
            new ServiceDefinition(name: 'mariadb', version: '11.8'),
        ]);
        $projectDir = '/tmp/test-project';

        $this->dockerComposeManager->expects($this->once())
            ->method('down')
            ->with($projectDir, [
                'removeOrphans' => false,
            ]);

        $this->dockerManager->expects($this->never())
            ->method('stop');

        $this->manager->down($config, $projectDir);
    }

    public function testEnsureServicesReportsAlreadyRunning(): void
    {
        $config = $this->createConfig([
            new ServiceDefinition(name: 'mariadb', version: '11.8'),
        ]);

        $this->dockerManager->method('isContainerRunning')
            ->willReturnMap([['dde-mariadb-11.8', true]]);

        $this->dockerManager->expects($this->never())
            ->method('run');

        $result = $this->manager->ensureServices($config);

        $this->assertSame('already_running', $result[0]['status']);
    }

    public function testEnsureServicesReportsStarted(): void
    {
        $config = $this->createConfig([
            new ServiceDefinition(name: 'mariadb', version: '11.8'),
        ]);

        $this->dockerManager->method('isContainerRunning')
            ->willReturn(false);

        $this->systemFilesystem->method('mkdir');

        $result = $this->manager->ensureServices($config);

        $this->assertSame('started', $result[0]['status']);
    }

    public function testEnsureServicesPassesNonDefaultFlagForNonDefaultVersion(): void
    {
        // mariadb default is 11.8; requesting 10.6 must receive isDefault=false
        // so it gets a dynamic host port and not port 3306.
        $config = new ResolvedConfig(
            globalConfig: new GlobalConfig(sshAgentMode: SshAgentMode::Managed),
            projectConfig: new ProjectConfig(name: 'test-project', services: [
                new ServiceDefinition(name: 'mariadb', version: '10.6'),
            ]),
            serviceVersions: [
                'mariadb' => '11.8',
            ],
        );

        $this->dockerManager->method('isContainerRunning')->willReturn(false);
        $this->systemFilesystem->method('mkdir');

        $this->dockerManager->expects($this->once())
            ->method('run')
            ->with($this->callback(function (ContainerConfig $containerConfig): bool {
                // Port must be dynamic (>= 10000), never 3306
                return str_contains($containerConfig->ports[0] ?? '', ':10000:')
                    || (! str_contains($containerConfig->ports[0] ?? '', ':3306:'));
            }));

        $result = $this->manager->ensureServices($config);

        $this->assertSame('started', $result[0]['status']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testUpThrowsWhenCertificateGenerationFails(): void
    {
        $config = $this->createConfig();
        $projectDir = '/tmp/test-project';

        $this->dockerComposeManager->method('findComposeFile')
            ->willReturn($projectDir.'/docker-compose.yml');

        $this->certificateManager->method('ensureForDomains')
            ->willThrowException(new \RuntimeException('mkcert not found'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('mkcert not found');

        $this->manager->up($config, $projectDir, false);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testUpThrowsWhenDevLayerBuildFails(): void
    {
        $config = $this->createConfig();
        $projectDir = '/tmp/test-project';

        $this->dockerComposeManager->method('findComposeFile')
            ->willReturn($projectDir.'/docker-compose.yml');

        $this->imageManager->method('ensureDevLayers')
            ->willThrowException(new \RuntimeException('Docker build failed'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Docker build failed');

        $this->manager->up($config, $projectDir, false);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testUpCleansUpOverrideFileEvenOnComposeUpFailure(): void
    {
        $config = $this->createConfig();
        $projectDir = '/tmp/test-project';

        $this->dockerComposeManager->method('findComposeFile')
            ->willReturn($projectDir.'/docker-compose.yml');

        $this->imageManager->method('ensureDevLayers')
            ->willReturn(null);

        $this->dockerComposeManager->method('generateOverride')
            ->willReturn('/tmp/override.yml');

        $this->dockerComposeManager->method('up')
            ->willThrowException(new \RuntimeException('compose up failed'));

        $this->filesystem->expects($this->once())
            ->method('remove')
            ->with('/tmp/override.yml');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('compose up failed');

        $this->manager->up($config, $projectDir, false);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testUpIncludesUserComposeOverrideBetweenBaseAndDdeOverride(): void
    {
        // Regression: `docker compose -f base -f dde-override` skips Compose's
        // default `docker-compose.override.yml` auto-discovery, so the user
        // override has to be passed explicitly between base and dde override.
        $config = $this->createConfig();
        $projectDir = '/tmp/test-project';
        $composeFile = $projectDir.'/docker-compose.yml';
        $userOverride = $projectDir.'/docker-compose.override.yml';
        $ddeOverride = '/tmp/dde-override.yml';

        $this->dockerComposeManager->method('findComposeFile')
            ->willReturn($composeFile);

        $this->dockerComposeManager->method('findUserOverrideFile')
            ->willReturnMap([[$projectDir, $composeFile, $userOverride]]);

        $this->imageManager->method('ensureDevLayers')
            ->willReturn(null);

        $this->dockerComposeManager->method('generateOverride')
            ->willReturn($ddeOverride);

        $this->dockerComposeManager->expects($this->once())
            ->method('up')
            ->with(
                $projectDir,
                $this->callback(static function (array $options) use ($composeFile, $userOverride, $ddeOverride): bool {
                    return $options['composeFiles'] === [$composeFile, $userOverride, $ddeOverride];
                }),
                null,
            );

        $this->manager->up($config, $projectDir, false);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testUpOmitsUserOverrideWhenAbsent(): void
    {
        $config = $this->createConfig();
        $projectDir = '/tmp/test-project';
        $composeFile = $projectDir.'/docker-compose.yml';
        $ddeOverride = '/tmp/dde-override.yml';

        $this->dockerComposeManager->method('findComposeFile')
            ->willReturn($composeFile);

        $this->dockerComposeManager->method('findUserOverrideFile')
            ->willReturn(null);

        $this->imageManager->method('ensureDevLayers')
            ->willReturn(null);

        $this->dockerComposeManager->method('generateOverride')
            ->willReturn($ddeOverride);

        $this->dockerComposeManager->expects($this->once())
            ->method('up')
            ->with(
                $projectDir,
                $this->callback(static function (array $options) use ($composeFile, $ddeOverride): bool {
                    return $options['composeFiles'] === [$composeFile, $ddeOverride];
                }),
                null,
            );

        $this->manager->up($config, $projectDir, false);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testUpForwardsBuildFlagToDockerCompose(): void
    {
        $config = $this->createConfig();
        $projectDir = '/tmp/test-project';

        $this->dockerComposeManager->method('findComposeFile')
            ->willReturn($projectDir.'/docker-compose.yml');

        $this->imageManager->method('ensureDevLayers')
            ->willReturn(null);

        $this->dockerComposeManager->method('generateOverride')
            ->willReturn('/tmp/override.yml');

        $this->dockerComposeManager->expects($this->once())
            ->method('up')
            ->with(
                $projectDir,
                $this->callback(function (array $options): bool {
                    return $options['build'] === true;
                }),
                null,
            );

        $this->manager->up($config, $projectDir, true);
    }

    public function testUpCreatesPerProjectNetworkAndConnectsServices(): void
    {
        $config = $this->createConfig([
            new ServiceDefinition(name: 'mariadb', version: '10.6'),
        ]);
        $projectDir = '/tmp/test-project';

        $this->dockerManager->method('isContainerRunning')->willReturn(false);
        $this->systemFilesystem->method('mkdir');

        $this->dockerManager->expects($this->once())
            ->method('networkExists')
            ->with('dde-services-test-project')
            ->willReturn(false);

        $this->dockerManager->expects($this->once())
            ->method('createNetwork')
            ->with('dde-services-test-project');

        $calls = [];
        $this->captureConnectContainerToNetworkCalls(2, $calls);

        $this->dockerComposeManager->method('findComposeFile')
            ->willReturn($projectDir.'/docker-compose.yml');
        $this->imageManager->method('ensureDevLayers')->willReturn(null);
        $this->dockerComposeManager->method('generateOverride')->willReturn('/tmp/override.yml');

        $this->manager->up($config, $projectDir, false);

        self::assertContains(['dde-mariadb-10.6', 'dde-services-test-project', ['mariadb']], $calls);
        self::assertContains(['dde-traefik', 'dde-services-test-project', []], $calls);
    }

    public function testUpSkipsNetworkCreationWhenAlreadyExists(): void
    {
        $config = $this->createConfig([
            new ServiceDefinition(name: 'mariadb', version: '10.6'),
        ]);
        $projectDir = '/tmp/test-project';

        $this->dockerManager->method('isContainerRunning')->willReturn(true);
        $this->dockerManager->expects($this->once())
            ->method('networkExists')
            ->with('dde-services-test-project')
            ->willReturn(true);

        $this->dockerManager->expects($this->never())
            ->method('createNetwork');

        $calls = [];
        $this->captureConnectContainerToNetworkCalls(2, $calls);

        $this->dockerComposeManager->method('findComposeFile')
            ->willReturn($projectDir.'/docker-compose.yml');
        $this->imageManager->method('ensureDevLayers')->willReturn(null);
        $this->dockerComposeManager->method('generateOverride')->willReturn('/tmp/override.yml');

        $this->manager->up($config, $projectDir, false);

        self::assertContains(['dde-mariadb-10.6', 'dde-services-test-project', ['mariadb']], $calls);
        self::assertContains(['dde-traefik', 'dde-services-test-project', []], $calls);
    }

    public function testUpDisconnectsStaleServiceContainerOfDifferentVersion(): void
    {
        // Regression: when a project switches MariaDB version (e.g. from 11.8
        // default to 10.11), the previously attached `dde-mariadb-11.8` stays
        // connected to the project network with alias `mariadb`. Docker DNS
        // then round-robins between the stale and the desired container,
        // randomly routing app traffic to the wrong database. ensureProjectNetwork
        // must detach any same-service-type container of a different version
        // before attaching the configured one.
        $config = $this->createConfig([
            new ServiceDefinition(name: 'mariadb', version: '10.11'),
        ]);
        $projectDir = '/tmp/test-project';

        $this->dockerManager->method('isContainerRunning')->willReturn(true);

        $this->dockerManager->expects($this->once())
            ->method('networkExists')
            ->with('dde-services-test-project')
            ->willReturn(true);

        $this->dockerManager->expects($this->once())
            ->method('getConnectedContainerNames')
            ->with('dde-services-test-project')
            ->willReturn(['dde-mariadb-11.8', 'dde-postgres-18', 'test-project-web-1']);

        // Stale mariadb of the wrong version must be detached. The unrelated
        // postgres container and the app container must be left alone.
        $this->dockerManager->expects($this->once())
            ->method('disconnectContainerFromNetwork')
            ->with('dde-mariadb-11.8', 'dde-services-test-project');

        $calls = [];
        $this->captureConnectContainerToNetworkCalls(2, $calls);

        $this->dockerComposeManager->method('findComposeFile')
            ->willReturn($projectDir.'/docker-compose.yml');
        $this->imageManager->method('ensureDevLayers')->willReturn(null);
        $this->dockerComposeManager->method('generateOverride')->willReturn('/tmp/override.yml');

        $this->manager->up($config, $projectDir, false);

        self::assertContains(['dde-mariadb-10.11', 'dde-services-test-project', ['mariadb']], $calls);
        self::assertContains(['dde-traefik', 'dde-services-test-project', []], $calls);
    }

    public function testUpKeepsServiceContainerOfMatchingVersionAttached(): void
    {
        // When the configured service container is already attached at the
        // correct version, ensureProjectNetwork must not disconnect it. The
        // subsequent connect call is a no-op (DockerManager swallows the
        // "already exists in network" error) but the disconnect path must
        // not run for the matching version.
        $config = $this->createConfig([
            new ServiceDefinition(name: 'mariadb', version: '10.11'),
        ]);
        $projectDir = '/tmp/test-project';

        $this->dockerManager->method('isContainerRunning')->willReturn(true);

        $this->dockerManager->expects($this->once())
            ->method('networkExists')
            ->with('dde-services-test-project')
            ->willReturn(true);

        $this->dockerManager->expects($this->once())
            ->method('getConnectedContainerNames')
            ->with('dde-services-test-project')
            ->willReturn(['dde-mariadb-10.11']);

        $this->dockerManager->expects($this->never())
            ->method('disconnectContainerFromNetwork');

        $calls = [];
        $this->captureConnectContainerToNetworkCalls(2, $calls);

        $this->dockerComposeManager->method('findComposeFile')
            ->willReturn($projectDir.'/docker-compose.yml');
        $this->imageManager->method('ensureDevLayers')->willReturn(null);
        $this->dockerComposeManager->method('generateOverride')->willReturn('/tmp/override.yml');

        $this->manager->up($config, $projectDir, false);

        self::assertContains(['dde-mariadb-10.11', 'dde-services-test-project', ['mariadb']], $calls);
        self::assertContains(['dde-traefik', 'dde-services-test-project', []], $calls);
    }

    public function testUpRestartsTraefikWhenAttachingToProjectNetworkForTheFirstTime(): void
    {
        $config = $this->createConfig([
            new ServiceDefinition(name: 'mariadb', version: '10.11'),
        ]);
        $projectDir = '/tmp/test-project';

        $this->dockerManager->method('isContainerRunning')->willReturn(true);

        $this->dockerManager->expects($this->once())
            ->method('networkExists')
            ->with('dde-services-test-project')
            ->willReturn(true);

        $this->dockerManager->expects($this->once())
            ->method('getConnectedContainerNames')
            ->with('dde-services-test-project')
            ->willReturn(['dde-mariadb-10.11']);

        $this->dockerManager->expects($this->once())
            ->method('stop')
            ->with('dde-traefik');

        $this->dockerManager->expects($this->once())
            ->method('start')
            ->with('dde-traefik');

        $calls = [];
        $this->captureConnectContainerToNetworkCalls(2, $calls);

        $this->dockerComposeManager->method('findComposeFile')
            ->willReturn($projectDir.'/docker-compose.yml');
        $this->imageManager->method('ensureDevLayers')->willReturn(null);
        $this->dockerComposeManager->method('generateOverride')->willReturn('/tmp/override.yml');

        $this->manager->up($config, $projectDir, false);
    }

    public function testUpAttachesGlobalServicesWithAliasesToProjectNetwork(): void
    {
        // Global services that opt in via ProjectNetworkAwareInterface (Mailpit's
        // `mail` alias being the canonical case) must join the per-project
        // network too, otherwise `smtp://mail:1025` from inside the project
        // network would not resolve.
        $mailpitStub = $this->createStub(ProjectNetworkAwareSystemServiceTestDouble::class);
        $mailpitStub->method('getContainerName')->willReturn('dde-mailpit');
        $mailpitStub->method('getProjectNetworkAliases')->willReturn(['mail']);
        $mailpitStub->method('requiresRestartAfterProjectNetworkAttach')->willReturn(false);

        $manager = $this->makeManager([$this->traefikStub, $mailpitStub]);

        $config = $this->createConfig([
            new ServiceDefinition(name: 'mariadb', version: '10.11'),
        ]);
        $projectDir = '/tmp/test-project';

        $this->dockerManager->method('isContainerRunning')->willReturn(true);
        $this->dockerManager->method('networkExists')->willReturn(true);
        // Connected: only the project's own mariadb is already there;
        // traefik + mailpit have never been attached, so both must be
        // connected and the assertion below pins all three connects.
        $this->dockerManager->method('getConnectedContainerNames')->willReturn(['dde-mariadb-10.11']);

        $calls = [];
        $this->captureConnectContainerToNetworkCalls(3, $calls);

        $this->dockerComposeManager->method('findComposeFile')
            ->willReturn($projectDir.'/docker-compose.yml');
        $this->imageManager->method('ensureDevLayers')->willReturn(null);
        $this->dockerComposeManager->method('generateOverride')->willReturn('/tmp/override.yml');

        $manager->up($config, $projectDir, false);

        self::assertCount(3, $calls);
        self::assertSame([
            ['dde-mariadb-10.11', 'dde-services-test-project', ['mariadb']],
            ['dde-traefik', 'dde-services-test-project', []],
            ['dde-mailpit', 'dde-services-test-project', ['mail']],
        ], $calls);
    }

    public function testDownDisconnectsGlobalServicesWithAliases(): void
    {
        $mailpitStub = $this->createStub(ProjectNetworkAwareSystemServiceTestDouble::class);
        $mailpitStub->method('getContainerName')->willReturn('dde-mailpit');
        $mailpitStub->method('getProjectNetworkAliases')->willReturn(['mail']);

        $manager = $this->makeManager([$this->traefikStub, $mailpitStub]);

        $config = $this->createConfig([
            new ServiceDefinition(name: 'mariadb', version: '10.6'),
        ]);
        $projectDir = '/tmp/test-project';

        $this->dockerManager->method('networkExists')->willReturn(true);

        $calls = [];
        $this->captureDisconnectContainerFromNetworkCalls(3, $calls);

        $this->dockerManager->expects($this->once())
            ->method('removeNetwork')
            ->with('dde-services-test-project');

        $manager->down($config, $projectDir);

        self::assertContains(['dde-mariadb-10.6', 'dde-services-test-project'], $calls);
        self::assertContains(['dde-traefik', 'dde-services-test-project'], $calls);
        self::assertContains(['dde-mailpit', 'dde-services-test-project'], $calls);
    }

    public function testUpKeepsTraefikRunningWhenAlreadyAttachedToProjectNetwork(): void
    {
        $config = $this->createConfig([
            new ServiceDefinition(name: 'mariadb', version: '10.11'),
        ]);
        $projectDir = '/tmp/test-project';

        $this->dockerManager->method('isContainerRunning')->willReturn(true);

        $this->dockerManager->expects($this->once())
            ->method('networkExists')
            ->with('dde-services-test-project')
            ->willReturn(true);

        $this->dockerManager->expects($this->once())
            ->method('getConnectedContainerNames')
            ->with('dde-services-test-project')
            ->willReturn(['dde-mariadb-10.11', 'dde-traefik']);

        $this->dockerManager->expects($this->never())->method('stop');
        $this->dockerManager->expects($this->never())->method('start');

        // Only mariadb is re-attached (its alias is the canonical service
        // name and connecting again is idempotent in Docker). Traefik is
        // already connected and the gate skips the redundant connect — the
        // restart sequence is therefore also never triggered.
        $calls = [];
        $this->captureConnectContainerToNetworkCalls(1, $calls);

        $this->dockerComposeManager->method('findComposeFile')
            ->willReturn($projectDir.'/docker-compose.yml');
        $this->imageManager->method('ensureDevLayers')->willReturn(null);
        $this->dockerComposeManager->method('generateOverride')->willReturn('/tmp/override.yml');

        $this->manager->up($config, $projectDir, false);
    }

    public function testUpSkipsStaleScanWhenNetworkIsFreshlyCreated(): void
    {
        // A freshly created network has no containers attached, so probing it
        // for stale containers would only waste a `docker network inspect`
        // call. Verify ensureProjectNetwork skips getConnectedContainerNames
        // when the network had to be created.
        $config = $this->createConfig([
            new ServiceDefinition(name: 'mariadb', version: '10.11'),
        ]);
        $projectDir = '/tmp/test-project';

        $this->dockerManager->method('isContainerRunning')->willReturn(true);

        $this->dockerManager->expects($this->once())
            ->method('networkExists')
            ->with('dde-services-test-project')
            ->willReturn(false);

        $this->dockerManager->expects($this->once())
            ->method('createNetwork')
            ->with('dde-services-test-project');

        $this->dockerManager->expects($this->never())
            ->method('getConnectedContainerNames');

        $this->dockerManager->expects($this->never())
            ->method('disconnectContainerFromNetwork');

        $calls = [];
        $this->captureConnectContainerToNetworkCalls(2, $calls);

        $this->dockerComposeManager->method('findComposeFile')
            ->willReturn($projectDir.'/docker-compose.yml');
        $this->imageManager->method('ensureDevLayers')->willReturn(null);
        $this->dockerComposeManager->method('generateOverride')->willReturn('/tmp/override.yml');

        $this->manager->up($config, $projectDir, false);

        self::assertContains(['dde-mariadb-10.11', 'dde-services-test-project', ['mariadb']], $calls);
        self::assertContains(['dde-traefik', 'dde-services-test-project', []], $calls);
    }

    public function testDownDisconnectsServicesAndRemovesNetwork(): void
    {
        $config = $this->createConfig([
            new ServiceDefinition(name: 'mariadb', version: '10.6'),
        ]);
        $projectDir = '/tmp/test-project';

        $this->dockerManager->expects($this->once())
            ->method('networkExists')
            ->with('dde-services-test-project')
            ->willReturn(true);

        $calls = [];
        $this->captureDisconnectContainerFromNetworkCalls(2, $calls);

        $this->dockerManager->expects($this->once())
            ->method('removeNetwork')
            ->with('dde-services-test-project');

        $this->dockerComposeManager->expects($this->once())
            ->method('down');

        $this->manager->down($config, $projectDir);

        self::assertContains(['dde-mariadb-10.6', 'dde-services-test-project'], $calls);
        self::assertContains(['dde-traefik', 'dde-services-test-project'], $calls);
    }

    public function testDownSkipsAllCleanupWhenNetworkMissing(): void
    {
        $config = $this->createConfig([
            new ServiceDefinition(name: 'mariadb', version: '10.6'),
        ]);
        $projectDir = '/tmp/test-project';

        $this->dockerManager->expects($this->once())
            ->method('networkExists')
            ->with('dde-services-test-project')
            ->willReturn(false);

        $this->dockerManager->expects($this->never())->method('disconnectContainerFromNetwork');
        $this->dockerManager->expects($this->never())->method('removeNetwork');

        $this->dockerComposeManager->method('down');

        $this->manager->down($config, $projectDir);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testDownInWorktreeRemovesOnlyWorktreeNetwork(): void
    {
        $config = $this->createConfig([
            new ServiceDefinition(name: 'postgres', version: '18'),
        ]);
        $projectDir = '/tmp/test-project-wt';

        $worktreeManager = $this->createMock(WorktreeManager::class);
        $worktreeInfo = new \App\Config\WorktreeInfo(
            mainDirectory: '/tmp/test-project',
            worktreeDirectory: $projectDir,
            branch: 'feature/pg18',
            suffix: 'test-project-wt',
        );
        $worktreeManager->method('detect')->willReturn($worktreeInfo);

        $manager = $this->makeManager(worktreeManager: $worktreeManager);

        $this->dockerManager->expects($this->once())
            ->method('networkExists')
            ->with('dde-services-test-project-wt')
            ->willReturn(true);

        $calls = [];
        $this->captureDisconnectContainerFromNetworkCalls(2, $calls);

        $this->dockerManager->expects($this->once())
            ->method('removeNetwork')
            ->with('dde-services-test-project-wt');

        $this->dockerComposeManager->expects($this->once())->method('down');

        $manager->down($config, $projectDir);

        self::assertContains(['dde-postgres-18', 'dde-services-test-project-wt'], $calls);
        self::assertContains(['dde-traefik', 'dde-services-test-project-wt'], $calls);
    }

    /**
     * Verifies generateOverride receives the per-project network name as 4th argument
     * when services are configured, and null when no services are configured.
     */
    #[AllowMockObjectsWithoutExpectations]
    public function testUpCallsGenerateOverrideWithNetworkWhenServicesConfigured(): void
    {
        $config = $this->createConfig([new ServiceDefinition(name: 'mariadb', version: '10.6')]);
        $projectDir = '/tmp/test-project';

        $this->dockerManager->method('isContainerRunning')->willReturn(true);
        $this->dockerManager->method('networkExists')->willReturn(true);
        $this->dockerComposeManager->method('findComposeFile')
            ->willReturn($projectDir.'/docker-compose.yml');
        $this->imageManager->method('ensureDevLayers')->willReturn(null);

        $this->dockerComposeManager->expects($this->once())
            ->method('generateOverride')
            ->with(
                $config,
                $projectDir,
                null,
                'dde-services-test-project',
            )
            ->willReturn('/tmp/override.yml');

        $this->manager->up($config, $projectDir, false);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testUpCallsGenerateOverrideWithPerProjectNetworkEvenWithoutServices(): void
    {
        // Service-less projects still get a per-project network — the old
        // "fall back to the shared `dde` network" branch was removed so the
        // overlay always carries a single, predictable network name.
        $config = $this->createConfig();
        $projectDir = '/tmp/test-project';

        $this->dockerComposeManager->method('findComposeFile')
            ->willReturn($projectDir.'/docker-compose.yml');
        $this->imageManager->method('ensureDevLayers')->willReturn(null);

        $this->dockerComposeManager->expects($this->once())
            ->method('generateOverride')
            ->with($config, $projectDir, null, 'dde-services-test-project')
            ->willReturn('/tmp/override.yml');

        $this->manager->up($config, $projectDir, false);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testUpReturnsDomainsFromComposeFileOutsideWorktree(): void
    {
        $config = $this->createConfig();
        $projectDir = '/tmp/test-project';

        $this->dockerComposeManager->method('findComposeFile')
            ->willReturn($projectDir.'/docker-compose.yml');
        $this->imageManager->method('ensureDevLayers')->willReturn(null);
        $this->dockerComposeManager->method('generateOverride')->willReturn('/tmp/override.yml');
        $this->dockerComposeManager->method('ps')
            ->willReturn([
                [
                    'Service' => 'web',
                ],
                [
                    'Service' => 'api',
                ],
            ]);

        $this->dockerComposeManager->method('extractTraefikDomainsFromServices')
            ->willReturn(['app.test', 'api.app.test']);

        $result = $this->manager->up($config, $projectDir, false);

        $this->assertSame(['app.test', 'api.app.test'], $result['domains']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testUpReturnsEmptyDomainsWhenNoTraefikLabels(): void
    {
        $config = $this->createConfig();
        $projectDir = '/tmp/test-project';

        $this->dockerComposeManager->method('findComposeFile')
            ->willReturn($projectDir.'/docker-compose.yml');
        $this->imageManager->method('ensureDevLayers')->willReturn(null);
        $this->dockerComposeManager->method('generateOverride')->willReturn('/tmp/override.yml');
        $this->dockerComposeManager->method('ps')->willReturn([]);

        $this->dockerComposeManager->method('extractTraefikDomainsFromServices')->willReturn([]);

        $result = $this->manager->up($config, $projectDir, false);

        $this->assertSame([], $result['domains']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testUpOnlyReturnsDomainsBelongingToRunningServices(): void
    {
        $config = $this->createConfig();
        $projectDir = '/tmp/test-project';

        $this->dockerComposeManager->method('findComposeFile')
            ->willReturn($projectDir.'/docker-compose.yml');
        $this->imageManager->method('ensureDevLayers')->willReturn(null);
        $this->dockerComposeManager->method('generateOverride')->willReturn('/tmp/override.yml');

        // Only 'web' is running — 'api' is excluded by a profile
        $this->dockerComposeManager->method('ps')
            ->willReturn([
                [
                    'Service' => 'web',
                ],
            ]);

        $this->dockerComposeManager->method('extractTraefikDomainsFromServices')
            ->willReturnCallback(static function (array $services, ?array $onlyServices = null): array {
                return $onlyServices === ['web'] ? ['app.test'] : [];
            });

        $result = $this->manager->up($config, $projectDir, false);

        $this->assertSame(['app.test'], $result['domains']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testUpHandlesMalformedPsOutputGracefully(): void
    {
        $config = $this->createConfig();
        $projectDir = '/tmp/test-project';

        $this->dockerComposeManager->method('findComposeFile')
            ->willReturn($projectDir.'/docker-compose.yml');
        $this->imageManager->method('ensureDevLayers')->willReturn(null);
        $this->dockerComposeManager->method('generateOverride')->willReturn('/tmp/override.yml');

        // Simulate malformed / mixed ps output:
        //  - missing Service key (ignored)
        //  - non-string Service value (ignored)
        //  - empty-string service (ignored)
        //  - lowercase key variant (accepted — docker compose emits this in newer versions)
        //  - canonical camel-case key (accepted)
        $this->dockerComposeManager->method('ps')
            ->willReturn([
                [
                    'Name' => 'project-web-1',
                ],
                [
                    'Service' => 123,
                ],
                [
                    'Service' => '',
                ],
                [
                    'service' => 'worker',
                ],
                [
                    'Service' => 'api',
                ],
            ]);

        $this->dockerComposeManager->method('extractTraefikDomainsFromServices')
            ->willReturnCallback(static function (array $services, ?array $onlyServices = null): array {
                return $onlyServices === ['worker', 'api'] ? ['worker.test', 'api.test'] : [];
            });

        $result = $this->manager->up($config, $projectDir, false);

        $this->assertSame(['worker.test', 'api.test'], $result['domains']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testUpToleratesPsFailureInsideWorktree(): void
    {
        // A `ps` JSON-parse failure must never tear down `up()`; the
        // lifecycle falls back to "all declared Traefik hosts" (no service
        // filter) so the user still sees the worktree URL list afterwards.
        $config = $this->createConfig();
        $projectDir = '/tmp/test-project-wt';

        $worktreeManager = $this->createMock(WorktreeManager::class);
        $worktreeInfo = new \App\Config\WorktreeInfo(
            mainDirectory: '/tmp/test-project',
            worktreeDirectory: $projectDir,
            branch: 'feature/x',
            suffix: 'test-project-wt',
        );
        $worktreeManager->method('detect')->willReturn($worktreeInfo);
        $worktreeManager->method('resolveHostname')->willReturn('test-project-wt.test');
        $worktreeManager->method('rewriteHostname')->willReturnCallback(
            static fn (string $hostname): string => match ($hostname) {
                'test-project.test' => 'test-project-wt.test',
                default => $hostname,
            },
        );

        $manager = $this->makeManager(worktreeManager: $worktreeManager);

        $this->dockerComposeManager->method('findComposeFile')
            ->willReturn($projectDir.'/docker-compose.yml');
        $this->imageManager->method('ensureDevLayers')->willReturn(null);
        $this->dockerComposeManager->method('generateOverride')->willReturn('/tmp/override.yml');
        $this->dockerComposeManager->method('ps')->willThrowException(new \RuntimeException('ps json parse failed'));
        $this->dockerComposeManager->method('extractTraefikDomainsFromServices')->willReturn(['test-project.test']);

        $result = $manager->up($config, $projectDir, false);

        $this->assertSame(['test-project-wt.test'], $result['domains']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testUpReturnsWorktreeHostnameInsideWorktree(): void
    {
        // Regression for #118 + d3d654c: inside a worktree the compose file
        // still declares the main hostname, so surfacing compose labels
        // verbatim would leak the main URL. The lifecycle must rewrite each
        // Traefik-declared host through `WorktreeManager::rewriteHostname`
        // before returning.
        $config = $this->createConfig();
        $projectDir = '/tmp/test-project-wt';

        $worktreeManager = $this->createMock(WorktreeManager::class);
        $worktreeInfo = new \App\Config\WorktreeInfo(
            mainDirectory: '/tmp/test-project',
            worktreeDirectory: $projectDir,
            branch: 'feature/x',
            suffix: 'test-project-wt',
        );
        $worktreeManager->method('detect')->willReturn($worktreeInfo);
        $worktreeManager->method('resolveHostname')->willReturn('test-project-wt.test');
        $worktreeManager->method('rewriteHostname')->willReturnCallback(
            static fn (string $hostname): string => match ($hostname) {
                'test-project.test' => 'test-project-wt.test',
                default => $hostname,
            },
        );

        $manager = $this->makeManager(worktreeManager: $worktreeManager);

        $this->dockerComposeManager->method('findComposeFile')
            ->willReturn($projectDir.'/docker-compose.yml');
        $this->imageManager->method('ensureDevLayers')->willReturn(null);
        $this->dockerComposeManager->method('generateOverride')->willReturn('/tmp/override.yml');
        $this->dockerComposeManager->method('extractTraefikDomainsFromServices')->willReturn(['test-project.test']);

        $result = $manager->up($config, $projectDir, false);

        $this->assertSame(['test-project-wt.test'], $result['domains']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testUpReturnsEverySubdomainRewrittenInsideWorktree(): void
    {
        // Regression: `dde update` inside a worktree used to print only the
        // bare worktree hostname even when the compose file declared
        // subdomain Host() rules. Every Traefik-declared host must be
        // surfaced, each one rewritten to its worktree variant — anything
        // less hides URLs the worktree container actually serves.
        $config = $this->createConfig();
        $projectDir = '/tmp/test-project-wt';

        $worktreeManager = $this->createMock(WorktreeManager::class);
        $worktreeInfo = new \App\Config\WorktreeInfo(
            mainDirectory: '/tmp/test-project',
            worktreeDirectory: $projectDir,
            branch: 'feature/x',
            suffix: 'test-project-wt',
        );
        $worktreeManager->method('detect')->willReturn($worktreeInfo);
        $worktreeManager->method('resolveHostname')->willReturn('test-project-wt.test');
        $worktreeManager->method('rewriteHostname')->willReturnCallback(
            static fn (string $hostname): string => match ($hostname) {
                'test-project.test' => 'test-project-wt.test',
                'preview.test-project.test' => 'preview.test-project-wt.test',
                'playwright.test-project.test' => 'playwright.test-project-wt.test',
                default => $hostname,
            },
        );

        $manager = $this->makeManager(worktreeManager: $worktreeManager);

        $this->dockerComposeManager->method('findComposeFile')
            ->willReturn($projectDir.'/docker-compose.yml');
        $this->imageManager->method('ensureDevLayers')->willReturn(null);
        $this->dockerComposeManager->method('generateOverride')->willReturn('/tmp/override.yml');
        $this->dockerComposeManager->method('extractTraefikDomainsFromServices')->willReturn([
            'test-project.test',
            'preview.test-project.test',
            'playwright.test-project.test',
        ]);

        $result = $manager->up($config, $projectDir, false);

        $this->assertSame([
            'test-project-wt.test',
            'preview.test-project-wt.test',
            'playwright.test-project-wt.test',
        ], $result['domains']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testUpCreatesWorktreeSpecificNetworkAndConnectsServices(): void
    {
        $config = $this->createConfig([
            new ServiceDefinition(name: 'postgres', version: '18'),
        ]);
        $projectDir = '/tmp/test-project-wt';

        $worktreeManager = $this->createMock(WorktreeManager::class);
        $worktreeInfo = new \App\Config\WorktreeInfo(
            mainDirectory: '/tmp/test-project',
            worktreeDirectory: $projectDir,
            branch: 'feature/pg18',
            suffix: 'test-project-wt',
        );
        $worktreeManager->method('detect')->willReturn($worktreeInfo);
        $worktreeManager->method('resolveHostname')->willReturn('test-project-wt.test');

        $manager = $this->makeManager(worktreeManager: $worktreeManager);

        $this->dockerManager->method('isContainerRunning')->willReturn(true);

        $this->dockerManager->expects($this->once())
            ->method('networkExists')
            ->with('dde-services-test-project-wt')
            ->willReturn(false);

        $this->dockerManager->expects($this->once())
            ->method('createNetwork')
            ->with('dde-services-test-project-wt');

        $calls = [];
        $this->captureConnectContainerToNetworkCalls(2, $calls);

        $this->dockerComposeManager->method('findComposeFile')
            ->willReturn($projectDir.'/docker-compose.yml');
        $this->imageManager->method('ensureDevLayers')->willReturn(null);
        $this->dockerComposeManager->expects($this->once())
            ->method('generateOverride')
            ->with($config, $projectDir, $worktreeInfo, 'dde-services-test-project-wt')
            ->willReturn('/tmp/override.yml');

        $manager->up($config, $projectDir, false);

        self::assertContains(['dde-postgres-18', 'dde-services-test-project-wt', ['postgres']], $calls);
        self::assertContains(['dde-traefik', 'dde-services-test-project-wt', []], $calls);
    }

    public function testBuildProjectNetworkNameAppendsSanitisedWorktreeSuffix(): void
    {
        $worktreeInfo = new \App\Config\WorktreeInfo(
            mainDirectory: '/tmp/test-project',
            worktreeDirectory: '/tmp/test-project-wt-feature',
            branch: 'feature/x',
            suffix: 'test-project-wt-feature',
        );

        $this->assertSame(
            'dde-services-test-project-wt-feature',
            ProjectLifecycleManager::buildProjectNetworkName('test-project', $worktreeInfo),
        );
    }

    public function testBuildProjectNetworkNameWithoutWorktreeKeepsOldName(): void
    {
        $this->assertSame(
            'dde-services-test-project',
            ProjectLifecycleManager::buildProjectNetworkName('test-project'),
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testUpGeneratesCertificateForAllRewrittenWorktreeDomains(): void
    {
        $config = ResolvedConfig::merge(new GlobalConfig(sshAgentMode: SshAgentMode::Managed), new ProjectConfig(name: 'beispiel'));
        $projectDir = '/tmp/test-project-beispiel-wt';

        $worktreeInfo = new \App\Config\WorktreeInfo(
            mainDirectory: '/main',
            worktreeDirectory: $projectDir,
            branch: 'feature/x',
            suffix: 'feature-x',
        );

        $worktreeManager = $this->createMock(WorktreeManager::class);
        $worktreeManager->method('detect')->willReturn($worktreeInfo);
        $worktreeManager->method('resolveHostname')->willReturn('feature-x.beispiel.test');
        $worktreeManager
            ->method('rewriteHostname')
            ->willReturnCallback(static function (string $hostname, string $projectName, \App\Config\WorktreeInfo $worktreeInfo): string {
                return match ($hostname) {
                    'beispiel.test' => 'feature-x.beispiel.test',
                    'preview.beispiel.test' => 'preview.feature-x.beispiel.test',
                    default => $hostname,
                };
            });

        $mkcertManager = $this->createMock(MkcertManager::class);
        $mkcertCalls = [];
        $mkcertManager
            ->method('ensureForDomains')
            ->willReturnCallback(static function (string $certName, array $domains) use (&$mkcertCalls): void {
                $mkcertCalls[] = [$certName, $domains];
            });

        // Compose file declares the bare project host and a subdomain.
        $this->dockerComposeManager->method('extractTraefikDomainsFromServices')
            ->willReturn(['beispiel.test', 'preview.beispiel.test']);

        $manager = $this->makeManager(
            worktreeManager: $worktreeManager,
            mkcertManager: $mkcertManager,
        );

        $this->dockerComposeManager->method('findComposeFile')
            ->willReturn($projectDir.'/docker-compose.yml');
        $this->imageManager->method('ensureDevLayers')->willReturn(null);
        $this->dockerComposeManager->method('generateOverride')->willReturn('/tmp/override.yml');

        $manager->up($config, $projectDir, build: false);

        $worktreeCall = null;

        foreach ($mkcertCalls as $call) {
            if ($call[0] === 'beispiel-feature-x') {
                $worktreeCall = $call;
                break;
            }
        }

        self::assertNotNull($worktreeCall, 'mkcert was not called for the worktree certificate');
        self::assertContains('feature-x.beispiel.test', $worktreeCall[1]);
        self::assertContains('preview.feature-x.beispiel.test', $worktreeCall[1]);
    }

    public function testUpExcludesUnrelatedExternalDomainsFromWorktreeCertificate(): void
    {
        // The compose file may declare Traefik routes for external hosts
        // (e.g. a partner API exposed via the project's Traefik). Those
        // hosts pass through `rewriteHostname()` unchanged because they are
        // not subdomains of the project. mkcert must not be asked to sign
        // them — generating a local trusted cert for a domain the project
        // does not own is at best confusing, at worst a security smell.
        $config = ResolvedConfig::merge(new GlobalConfig(sshAgentMode: SshAgentMode::Managed), new ProjectConfig(name: 'beispiel'));
        $projectDir = '/tmp/test-project-beispiel-wt';

        $worktreeInfo = new \App\Config\WorktreeInfo(
            mainDirectory: '/main',
            worktreeDirectory: $projectDir,
            branch: 'feature/x',
            suffix: 'feature-x',
        );

        $worktreeManager = $this->createMock(WorktreeManager::class);
        $worktreeManager->method('detect')->willReturn($worktreeInfo);
        $worktreeManager->method('resolveHostname')->willReturn('feature-x.beispiel.test');
        $worktreeManager
            ->method('rewriteHostname')
            ->willReturnCallback(static function (string $hostname, string $projectName, \App\Config\WorktreeInfo $worktreeInfo): string {
                return match ($hostname) {
                    'beispiel.test' => 'feature-x.beispiel.test',
                    default => $hostname,
                };
            });

        $mkcertManager = $this->createMock(MkcertManager::class);
        $mkcertCalls = [];
        $mkcertManager
            ->method('ensureForDomains')
            ->willReturnCallback(static function (string $certName, array $domains) use (&$mkcertCalls): void {
                $mkcertCalls[] = [$certName, $domains];
            });

        $this->dockerComposeManager->method('extractTraefikDomainsFromServices')
            ->willReturn(['beispiel.test', 'partner.example.com']);

        $manager = $this->makeManager(
            worktreeManager: $worktreeManager,
            mkcertManager: $mkcertManager,
        );

        $this->dockerComposeManager->method('findComposeFile')
            ->willReturn($projectDir.'/docker-compose.yml');
        $this->imageManager->method('ensureDevLayers')->willReturn(null);
        $this->dockerComposeManager->method('generateOverride')->willReturn('/tmp/override.yml');

        $manager->up($config, $projectDir, build: false);

        $worktreeCall = null;

        foreach ($mkcertCalls as $call) {
            if ($call[0] === 'beispiel-feature-x') {
                $worktreeCall = $call;
                break;
            }
        }

        self::assertNotNull($worktreeCall);
        self::assertContains('feature-x.beispiel.test', $worktreeCall[1]);
        self::assertNotContains('partner.example.com', $worktreeCall[1]);
    }

    /**
     * @param array<ServiceDefinition> $services
     */
    private function createConfig(array $services = []): ResolvedConfig
    {
        return new ResolvedConfig(
            globalConfig: new GlobalConfig(sshAgentMode: SshAgentMode::Managed),
            projectConfig: new ProjectConfig(name: 'test-project', services: $services),
        );
    }

    private function createHostModeConfig(): ResolvedConfig
    {
        return new ResolvedConfig(
            globalConfig: new GlobalConfig(sshAgentMode: SshAgentMode::Host),
            projectConfig: new ProjectConfig(name: 'test-project'),
        );
    }

    /**
     * Wires the collaborators every host-mode warn test shares: a compose file,
     * a no-op dev layer, and a stubbed override so `up()` runs end-to-end without
     * Docker. Returns the captured output buffer so the test can assert on the
     * emitted (or absent) warning.
     */
    private function arrangeHostModeUp(string $projectDir): BufferedOutput
    {
        $this->dockerComposeManager->method('findComposeFile')
            ->willReturn($projectDir.'/docker-compose.yml');
        $this->imageManager->method('ensureDevLayers')->willReturn(null);
        $this->dockerComposeManager->method('generateOverride')->willReturn('/tmp/override.yml');

        return new BufferedOutput();
    }

    /**
     * Builds a ProjectLifecycleManager with optional overrides for the
     * collaborators that tests most commonly customise. Defaults come from
     * setUp() so every test only spells out what differs from the baseline.
     *
     * @param iterable<AbstractSystemService>|null $globalServices defaults to [traefikStub]
     */
    private function makeManager(
        ?iterable $globalServices = null,
        ?WorktreeManager $worktreeManager = null,
        ?MkcertManager $mkcertManager = null,
        ?HostSshAgentResolver $hostSshAgentResolver = null,
    ): ProjectLifecycleManager {
        $globalServices ??= [$this->traefikStub];
        $serviceRegistry = new ServiceRegistry(
            $globalServices,
            new DatabaseAdapterRegistry([new MariaDbAdapter(), new PostgresAdapter()]),
        );
        $systemServiceManager = new SystemServiceManager(
            $this->dockerManager,
            $serviceRegistry,
            $this->systemFilesystem,
            '/tmp/dde-data',
        );

        return new ProjectLifecycleManager(
            $this->dockerComposeManager,
            $systemServiceManager,
            $this->imageManager,
            $mkcertManager ?? $this->certificateManager,
            $serviceRegistry,
            $this->dockerManager,
            $worktreeManager ?? $this->createStub(WorktreeManager::class),
            $hostSshAgentResolver ?? $this->hostSshAgentResolver,
            $this->filesystem,
        );
    }

    /**
     * @param list<array{0: string, 1: string, 2: array<int, string>}> $calls
     */
    private function captureConnectContainerToNetworkCalls(int $expectedCount, array &$calls): void
    {
        $this->dockerManager->expects($this->exactly($expectedCount))
            ->method('connectContainerToNetwork')
            ->willReturnCallback(static function (string $container, string $network, array $aliases = []) use (&$calls): void {
                $calls[] = [$container, $network, $aliases];
            });
    }

    /**
     * @param list<array{0: string, 1: string}> $calls
     */
    private function captureDisconnectContainerFromNetworkCalls(int $expectedCount, array &$calls): void
    {
        $this->dockerManager->expects($this->exactly($expectedCount))
            ->method('disconnectContainerFromNetwork')
            ->willReturnCallback(static function (string $container, string $network) use (&$calls): void {
                $calls[] = [$container, $network];
            });
    }

    protected function setUp(): void
    {
        $this->dockerComposeManager = $this->createMock(DockerComposeManager::class);
        $this->dockerManager = $this->createMock(DockerManager::class);
        $this->imageManager = $this->createMock(ImageManager::class);
        $this->filesystem = $this->createMock(Filesystem::class);
        $this->systemFilesystem = $this->createStub(Filesystem::class);

        $this->traefikStub = $this->createStub(ProjectNetworkAwareSystemServiceTestDouble::class);
        $this->traefikStub->method('getContainerName')->willReturn('dde-traefik');
        $this->traefikStub->method('getProjectNetworkAliases')->willReturn([]);
        $this->traefikStub->method('requiresRestartAfterProjectNetworkAttach')->willReturn(true);

        $serviceRegistry = new ServiceRegistry([$this->traefikStub], new DatabaseAdapterRegistry([new MariaDbAdapter(), new PostgresAdapter()]));
        $systemServiceManager = new SystemServiceManager(
            $this->dockerManager,
            $serviceRegistry,
            $this->systemFilesystem,
            '/tmp/dde-data',
        );

        $this->certificateManager = $this->createStub(MkcertManager::class);

        // A real unix socket stands in for a present agent socket: the resolver
        // verifies the path is an actual socket, not merely that it exists.
        $this->presentSocketPath = sys_get_temp_dir().'/dde-ssh-present-'.bin2hex(random_bytes(6)).'.sock';
        $this->presentSocket = stream_socket_server('unix://'.$this->presentSocketPath, $errno, $errstr);
        if ($this->presentSocket === false) {
            self::markTestSkipped(sprintf('Could not create unix socket: %s (%d)', $errstr, $errno));
        }

        // The default resolver mimics a Linux host with a present agent socket
        // so the managed-mode tests never trip the host-mode warn path. Host-mode
        // tests build their own resolver with controlled OS / SSH_AUTH_SOCK seams.
        $this->hostSshAgentResolver = new HostSshAgentResolver(
            osFamily: 'Linux',
            authSock: $this->presentSocketPath,
        );

        // networkExists returns false by default (PHPUnit mock default for bool return),
        // so removeNetwork is never called unless a test explicitly stubs networkExists to true.

        $worktreeManager = $this->createStub(WorktreeManager::class);
        $this->manager = new ProjectLifecycleManager(
            $this->dockerComposeManager,
            $systemServiceManager,
            $this->imageManager,
            $this->certificateManager,
            $serviceRegistry,
            $this->dockerManager,
            $worktreeManager,
            $this->hostSshAgentResolver,
            $this->filesystem,
        );
    }

    protected function tearDown(): void
    {
        if (is_resource($this->presentSocket)) {
            fclose($this->presentSocket);
        }

        if (file_exists($this->presentSocketPath)) {
            unlink($this->presentSocketPath);
        }
    }
}

/**
 * Test double that combines `AbstractSystemService` with the
 * `ProjectNetworkAwareInterface` marker so `createStub()` produces a stub
 * passing both the abstract-base contract used internally and the
 * `instanceof` filter applied by `ProjectLifecycleManager`.
 */
abstract class ProjectNetworkAwareSystemServiceTestDouble extends AbstractSystemService implements ProjectNetworkAwareInterface
{
}
