<?php

declare(strict_types=1);

namespace Tests\Unit\Manager;

use App\Config\GlobalConfig;
use App\Config\ProjectConfig;
use App\Config\ResolvedConfig;
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
use App\Parser\DockerComposeParser;
use App\Service\AbstractSystemService;
use App\Service\ServiceRegistry;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[AllowMockObjectsWithoutExpectations]
final class ProjectLifecycleManagerTest extends TestCase
{
    public $certificateManager;

    public DockerComposeParser&Stub $composeParser;

    private DockerComposeManager&MockObject $dockerComposeManager;

    private DockerManager&MockObject $dockerManager;

    private ImageManager&MockObject $imageManager;

    private Filesystem&MockObject $filesystem;

    private Filesystem&Stub $systemFilesystem;

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

        $serviceRegistry = new ServiceRegistry([$globalService], new DatabaseAdapterRegistry([new MariaDbAdapter(), new PostgresAdapter()]));
        $systemServiceManager = new SystemServiceManager(
            $this->dockerManager,
            $serviceRegistry,
            $this->systemFilesystem,
            '/tmp/dde-data',
        );

        $worktreeManager = $this->createStub(WorktreeManager::class);
        $manager = new ProjectLifecycleManager(
            $this->dockerComposeManager,
            $systemServiceManager,
            $this->imageManager,
            $this->certificateManager,
            $serviceRegistry,
            $this->dockerManager,
            $worktreeManager,
            $this->composeParser,
            $this->filesystem,
        );

        $this->dockerComposeManager->method('findComposeFile')
            ->willReturn($projectDir.'/docker-compose.yml');
        $this->imageManager->method('ensureDevLayers')
            ->willReturn(null);
        $this->dockerComposeManager->method('generateOverride')
            ->willReturn('/tmp/override.yml');

        $manager->up($config, $projectDir, false);
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
            ->with('dde-mariadb-11.8')
            ->willReturn(true);

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
            globalConfig: new GlobalConfig(),
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

        $this->certificateManager->method('ensureForComposeFile')
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

        $this->dockerManager->expects($this->once())
            ->method('connectContainerToNetwork')
            ->with('dde-mariadb-10.6', 'dde-services-test-project', ['mariadb']);

        $this->dockerComposeManager->method('findComposeFile')
            ->willReturn($projectDir.'/docker-compose.yml');
        $this->imageManager->method('ensureDevLayers')->willReturn(null);
        $this->dockerComposeManager->method('generateOverride')->willReturn('/tmp/override.yml');

        $this->manager->up($config, $projectDir, false);
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

        $this->dockerManager->expects($this->once())
            ->method('connectContainerToNetwork')
            ->with('dde-mariadb-10.6', 'dde-services-test-project', ['mariadb']);

        $this->dockerComposeManager->method('findComposeFile')
            ->willReturn($projectDir.'/docker-compose.yml');
        $this->imageManager->method('ensureDevLayers')->willReturn(null);
        $this->dockerComposeManager->method('generateOverride')->willReturn('/tmp/override.yml');

        $this->manager->up($config, $projectDir, false);
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

        $this->dockerManager->expects($this->once())
            ->method('connectContainerToNetwork')
            ->with('dde-mariadb-10.11', 'dde-services-test-project', ['mariadb']);

        $this->dockerComposeManager->method('findComposeFile')
            ->willReturn($projectDir.'/docker-compose.yml');
        $this->imageManager->method('ensureDevLayers')->willReturn(null);
        $this->dockerComposeManager->method('generateOverride')->willReturn('/tmp/override.yml');

        $this->manager->up($config, $projectDir, false);
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

        $this->dockerManager->expects($this->once())
            ->method('connectContainerToNetwork')
            ->with('dde-mariadb-10.11', 'dde-services-test-project', ['mariadb']);

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

        $this->dockerManager->expects($this->once())
            ->method('connectContainerToNetwork')
            ->with('dde-mariadb-10.11', 'dde-services-test-project', ['mariadb']);

        $this->dockerComposeManager->method('findComposeFile')
            ->willReturn($projectDir.'/docker-compose.yml');
        $this->imageManager->method('ensureDevLayers')->willReturn(null);
        $this->dockerComposeManager->method('generateOverride')->willReturn('/tmp/override.yml');

        $this->manager->up($config, $projectDir, false);
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

        $this->dockerManager->expects($this->once())
            ->method('disconnectContainerFromNetwork')
            ->with('dde-mariadb-10.6', 'dde-services-test-project');

        $this->dockerManager->expects($this->once())
            ->method('removeNetwork')
            ->with('dde-services-test-project');

        $this->dockerComposeManager->expects($this->once())
            ->method('down');

        $this->manager->down($config, $projectDir);
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

        $serviceRegistry = new ServiceRegistry([], new DatabaseAdapterRegistry([new MariaDbAdapter(), new PostgresAdapter()]));
        $systemServiceManager = new SystemServiceManager(
            $this->dockerManager,
            $serviceRegistry,
            $this->systemFilesystem,
            '/tmp/dde-data',
        );

        $manager = new ProjectLifecycleManager(
            $this->dockerComposeManager,
            $systemServiceManager,
            $this->imageManager,
            $this->certificateManager,
            $serviceRegistry,
            $this->dockerManager,
            $worktreeManager,
            $this->composeParser,
            $this->filesystem,
        );

        $this->dockerManager->expects($this->once())
            ->method('networkExists')
            ->with('dde-services-test-project-wt')
            ->willReturn(true);

        $this->dockerManager->expects($this->once())
            ->method('disconnectContainerFromNetwork')
            ->with('dde-postgres-18', 'dde-services-test-project-wt');

        $this->dockerManager->expects($this->once())
            ->method('removeNetwork')
            ->with('dde-services-test-project-wt');

        $this->dockerComposeManager->expects($this->once())->method('down');

        $manager->down($config, $projectDir);
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
    public function testUpCallsGenerateOverrideWithNullNetworkWhenNoServices(): void
    {
        $config = $this->createConfig();
        $projectDir = '/tmp/test-project';

        $this->dockerComposeManager->method('findComposeFile')
            ->willReturn($projectDir.'/docker-compose.yml');
        $this->imageManager->method('ensureDevLayers')->willReturn(null);

        $this->dockerComposeManager->expects($this->once())
            ->method('generateOverride')
            ->with($config, $projectDir, null, null)
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

        $this->composeParser->method('extractTraefikDomains')
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

        $this->composeParser->method('extractTraefikDomains')->willReturn([]);

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

        $this->composeParser->method('extractTraefikDomains')
            ->with($projectDir.'/docker-compose.yml', ['web'])
            ->willReturn(['app.test']);

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

        $this->composeParser->method('extractTraefikDomains')
            ->with($projectDir.'/docker-compose.yml', ['worker', 'api'])
            ->willReturn(['worker.test', 'api.test']);

        $result = $this->manager->up($config, $projectDir, false);

        $this->assertSame(['worker.test', 'api.test'], $result['domains']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testUpSkipsPsInsideWorktree(): void
    {
        // Inside a worktree the hostname wins over compose labels, so the
        // extra `ps` round-trip is unnecessary and would introduce a new
        // failure mode if `ps` ever errored (e.g. JSON-parse failure).
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

        $serviceRegistry = new ServiceRegistry([], new DatabaseAdapterRegistry([new MariaDbAdapter(), new PostgresAdapter()]));
        $systemServiceManager = new SystemServiceManager(
            $this->dockerManager,
            $serviceRegistry,
            $this->systemFilesystem,
            '/tmp/dde-data',
        );

        $manager = new ProjectLifecycleManager(
            $this->dockerComposeManager,
            $systemServiceManager,
            $this->imageManager,
            $this->certificateManager,
            $serviceRegistry,
            $this->dockerManager,
            $worktreeManager,
            $this->composeParser,
            $this->filesystem,
        );

        $this->dockerComposeManager->method('findComposeFile')
            ->willReturn($projectDir.'/docker-compose.yml');
        $this->imageManager->method('ensureDevLayers')->willReturn(null);
        $this->dockerComposeManager->method('generateOverride')->willReturn('/tmp/override.yml');

        $this->dockerComposeManager->expects($this->never())->method('ps');

        $result = $manager->up($config, $projectDir, false);

        $this->assertSame(['test-project-wt.test'], $result['domains']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testUpReturnsWorktreeHostnameInsideWorktree(): void
    {
        // Regression for #118 + d3d654c: inside a worktree the compose file
        // still declares the main hostname, so surfacing compose labels would
        // leak the main URL. The lifecycle must return the worktree hostname.
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

        $serviceRegistry = new ServiceRegistry([], new DatabaseAdapterRegistry([new MariaDbAdapter(), new PostgresAdapter()]));
        $systemServiceManager = new SystemServiceManager(
            $this->dockerManager,
            $serviceRegistry,
            $this->systemFilesystem,
            '/tmp/dde-data',
        );

        $manager = new ProjectLifecycleManager(
            $this->dockerComposeManager,
            $systemServiceManager,
            $this->imageManager,
            $this->certificateManager,
            $serviceRegistry,
            $this->dockerManager,
            $worktreeManager,
            $this->composeParser,
            $this->filesystem,
        );

        $this->dockerComposeManager->method('findComposeFile')
            ->willReturn($projectDir.'/docker-compose.yml');
        $this->imageManager->method('ensureDevLayers')->willReturn(null);
        $this->dockerComposeManager->method('generateOverride')->willReturn('/tmp/override.yml');

        // The compose file still points at the MAIN hostname — even if the
        // parser were consulted it would return that, so we assert the
        // lifecycle surfaces the worktree hostname, not compose labels.
        $this->composeParser->method('extractTraefikDomains')->willReturn(['test-project.test']);

        $result = $manager->up($config, $projectDir, false);

        $this->assertSame(['test-project-wt.test'], $result['domains']);
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

        $serviceRegistry = new ServiceRegistry([], new DatabaseAdapterRegistry([new MariaDbAdapter(), new PostgresAdapter()]));
        $systemServiceManager = new SystemServiceManager(
            $this->dockerManager,
            $serviceRegistry,
            $this->systemFilesystem,
            '/tmp/dde-data',
        );

        $manager = new ProjectLifecycleManager(
            $this->dockerComposeManager,
            $systemServiceManager,
            $this->imageManager,
            $this->certificateManager,
            $serviceRegistry,
            $this->dockerManager,
            $worktreeManager,
            $this->composeParser,
            $this->filesystem,
        );

        $this->dockerManager->method('isContainerRunning')->willReturn(true);

        $this->dockerManager->expects($this->once())
            ->method('networkExists')
            ->with('dde-services-test-project-wt')
            ->willReturn(false);

        $this->dockerManager->expects($this->once())
            ->method('createNetwork')
            ->with('dde-services-test-project-wt');

        $this->dockerManager->expects($this->once())
            ->method('connectContainerToNetwork')
            ->with('dde-postgres-18', 'dde-services-test-project-wt', ['postgres']);

        $this->dockerComposeManager->method('findComposeFile')
            ->willReturn($projectDir.'/docker-compose.yml');
        $this->imageManager->method('ensureDevLayers')->willReturn(null);
        $this->dockerComposeManager->expects($this->once())
            ->method('generateOverride')
            ->with($config, $projectDir, $worktreeInfo, 'dde-services-test-project-wt')
            ->willReturn('/tmp/override.yml');

        $manager->up($config, $projectDir, false);
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

    /**
     * @param array<ServiceDefinition> $services
     */
    private function createConfig(array $services = []): ResolvedConfig
    {
        return new ResolvedConfig(
            globalConfig: new GlobalConfig(),
            projectConfig: new ProjectConfig(name: 'test-project', services: $services),
        );
    }

    protected function setUp(): void
    {
        $this->dockerComposeManager = $this->createMock(DockerComposeManager::class);
        $this->dockerManager = $this->createMock(DockerManager::class);
        $this->imageManager = $this->createMock(ImageManager::class);
        $this->filesystem = $this->createMock(Filesystem::class);
        $this->systemFilesystem = $this->createStub(Filesystem::class);

        $serviceRegistry = new ServiceRegistry([], new DatabaseAdapterRegistry([new MariaDbAdapter(), new PostgresAdapter()]));
        $systemServiceManager = new SystemServiceManager(
            $this->dockerManager,
            $serviceRegistry,
            $this->systemFilesystem,
            '/tmp/dde-data',
        );

        $this->certificateManager = $this->createStub(MkcertManager::class);
        $this->composeParser = $this->createStub(DockerComposeParser::class);

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
            $this->composeParser,
            $this->filesystem,
        );
    }
}
