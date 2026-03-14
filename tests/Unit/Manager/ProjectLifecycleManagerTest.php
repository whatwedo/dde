<?php

declare(strict_types=1);

namespace Tests\Unit\Manager;

use App\Config\GlobalConfig;
use App\Config\ProjectConfig;
use App\Config\ResolvedConfig;
use App\Database\DatabaseAdapterRegistry;
use App\Database\MariaDbAdapter;
use App\Database\PostgresAdapter;
use App\Manager\ConfigManager;
use App\Manager\DockerComposeManager;
use App\Manager\DockerManager;
use App\Manager\ImageManager;
use App\Manager\MkcertManager;
use App\Manager\ProjectLifecycleManager;
use App\Manager\SystemServiceManager;
use App\Model\ContainerInfo;
use App\Model\ContainerStatus;
use App\Model\ServiceDefinition;
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
            ->with($config, $projectDir)
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

        $configManager = $this->createStub(ConfigManager::class);
        $manager = new ProjectLifecycleManager(
            $configManager,
            $this->dockerComposeManager,
            $systemServiceManager,
            $this->imageManager,
            $this->certificateManager,
            $serviceRegistry,
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

    #[AllowMockObjectsWithoutExpectations]
    public function testRestartCallsDownThenUp(): void
    {
        $config = $this->createConfig();
        $projectDir = '/tmp/test-project';

        $this->dockerComposeManager->expects($this->once())
            ->method('down')
            ->with($projectDir, [
                'removeOrphans' => false,
            ]);

        $this->dockerComposeManager->expects($this->once())
            ->method('findComposeFile')
            ->willReturn($projectDir.'/docker-compose.yml');

        $this->imageManager->expects($this->once())
            ->method('ensureDevLayers')
            ->willReturn(null);

        $this->dockerComposeManager->expects($this->once())
            ->method('build');

        $this->dockerComposeManager->expects($this->once())
            ->method('generateOverride')
            ->willReturn('/tmp/override.yml');

        $this->dockerComposeManager->expects($this->once())
            ->method('up');

        $this->filesystem->expects($this->once())
            ->method('remove');

        $result = $this->manager->restart($config, $projectDir);

        $this->assertArrayHasKey('serviceResults', $result);
        $this->assertArrayHasKey('devLayerResult', $result);
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

        $this->dockerManager->method('getContainersByLabel')
            ->willReturn([]);

        $this->systemFilesystem->method('mkdir');

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

    public function testEnsureServicesSkipsWhenServiceAlreadyRunningUnderDifferentName(): void
    {
        $config = $this->createConfig([
            new ServiceDefinition(name: 'mariadb', version: '11.8'),
        ]);

        $this->dockerManager->method('isContainerRunning')
            ->willReturn(false);

        $this->dockerManager->method('getContainersByLabel')
            ->willReturn([
                new ContainerInfo(name: 'dde-mariadb-10', status: ContainerStatus::RUNNING, image: 'mariadb:10'),
            ]);

        $result = $this->manager->ensureServices($config);

        $this->assertIsArray($result);
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

        $configManager = $this->createStub(ConfigManager::class);
        $this->manager = new ProjectLifecycleManager(
            $configManager,
            $this->dockerComposeManager,
            $systemServiceManager,
            $this->imageManager,
            $this->certificateManager,
            $serviceRegistry,
            $this->filesystem,
        );
    }
}
