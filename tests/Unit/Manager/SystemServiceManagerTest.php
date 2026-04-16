<?php

declare(strict_types=1);

namespace Tests\Unit\Manager;

use App\Database\DatabaseAdapterRegistry;
use App\Manager\DockerManager;
use App\Manager\SystemServiceManager;
use App\Model\ContainerConfig;
use App\Model\ServiceStartStatus;
use App\Service\ServiceRegistry;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[AllowMockObjectsWithoutExpectations]
final class SystemServiceManagerTest extends TestCase
{
    private DockerManager&MockObject $dockerManager;

    private string $tempDir;

    private SystemServiceManager $manager;

    #[AllowMockObjectsWithoutExpectations]
    public function testGetContainerName(): void
    {
        $this->assertSame('dde-mariadb-11.8', $this->manager->getContainerName('mariadb', '11.8'));
        $this->assertSame('dde-mariadb-10.6', $this->manager->getContainerName('mariadb', '10.6'));
        $this->assertSame('dde-valkey-9', $this->manager->getContainerName('valkey', '9'));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetContainerConfigDefaultVersion(): void
    {
        $config = $this->manager->getContainerConfig('mariadb', '11.8', true);

        $this->assertInstanceOf(ContainerConfig::class, $config);
        $this->assertSame('mariadb:11.8', $config->image);
        $this->assertSame('dde-mariadb-11.8', $config->containerName);
        $this->assertSame(['127.0.0.1:3306:3306'], $config->ports);
        $this->assertSame([], $config->networkAliases);
        $this->assertSame('true', $config->labels['dde.managed']);
        $this->assertSame('mariadb', $config->labels['dde.service']);
        $this->assertSame('11.8', $config->labels['dde.version']);
        $this->assertSame('unless-stopped', $config->restartPolicy);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetContainerConfigNonDefaultVersion(): void
    {
        $config = $this->manager->getContainerConfig('mariadb', '10.6', false);

        $this->assertSame('mariadb:10.6', $config->image);
        $this->assertSame('dde-mariadb-10.6', $config->containerName);
        $this->assertSame([], $config->networkAliases);
        // Non-default gets dynamic port
        $this->assertNotEmpty($config->ports);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetContainerConfigPostgres(): void
    {
        $config = $this->manager->getContainerConfig('postgres', '18.3', true);

        $this->assertSame('postgres:18.3', $config->image);
        $this->assertSame(['127.0.0.1:5432:5432'], $config->ports);
        $this->assertSame([], $config->networkAliases);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetContainerConfigValkey(): void
    {
        $config = $this->manager->getContainerConfig('valkey', '9', true);

        $this->assertSame('valkey/valkey:9', $config->image);
        $this->assertSame(['127.0.0.1:6379:6379'], $config->ports);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAllocatePortDefaultUsesStandardPort(): void
    {
        $port = $this->manager->allocatePort('mariadb', '11.8', true);

        $this->assertSame('127.0.0.1:3306:3306', $port);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAllocatePortNonDefaultUsesDynamicPort(): void
    {
        $port = $this->manager->allocatePort('mariadb', '10.6', false);

        $this->assertSame('127.0.0.1:10000:3306', $port);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAllocatePortNonDefaultPersistsToRegistry(): void
    {
        $this->manager->allocatePort('mariadb', '10.6', false);

        $registry = $this->manager->loadPortRegistry();
        $this->assertArrayHasKey('mariadb-10.6', $registry);
        $this->assertSame(10000, $registry['mariadb-10.6']);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAllocatePortNonDefaultReusesExistingPort(): void
    {
        $this->manager->savePortRegistry([
            'mariadb-10.6' => 12345,
        ]);

        $port = $this->manager->allocatePort('mariadb', '10.6', false);

        $this->assertSame('127.0.0.1:12345:3306', $port);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAllocatePortAvoidsDuplicates(): void
    {
        $this->manager->savePortRegistry([
            'postgres-15' => 10000,
        ]);

        $port = $this->manager->allocatePort('mariadb', '10.6', false);

        $this->assertSame('127.0.0.1:10001:3306', $port);
    }

    public function testStartServiceCreatesDataDirAndCallsRun(): void
    {
        $this->dockerManager
            ->expects($this->once())
            ->method('isContainerRunning')
            ->with('dde-mariadb-11.8')
            ->willReturn(false);

        $this->dockerManager
            ->expects($this->once())
            ->method('run')
            ->with($this->isInstanceOf(ContainerConfig::class));

        $result = $this->manager->startService('mariadb', '11.8', true);

        $this->assertSame(ServiceStartStatus::STARTED, $result);
        $this->assertDirectoryExists($this->tempDir.'/services/mariadb-11.8');
    }

    public function testStartServiceSkipsWhenAlreadyRunning(): void
    {
        $this->dockerManager
            ->expects($this->once())
            ->method('isContainerRunning')
            ->with('dde-mariadb-11.8')
            ->willReturn(true);

        $this->dockerManager
            ->expects($this->never())
            ->method('run');

        $result = $this->manager->startService('mariadb', '11.8', true);

        $this->assertSame(ServiceStartStatus::ALREADY_RUNNING, $result);
    }

    public function testStartServiceNonDefaultAlwaysGetsDynamicPort(): void
    {
        // isDefault=false is always passed for non-default versions by the caller;
        // port allocation must be dynamic regardless of what other containers are running.
        $this->dockerManager
            ->method('isContainerRunning')
            ->with('dde-mariadb-10.6')
            ->willReturn(false);

        $capturedConfig = null;
        $this->dockerManager
            ->expects($this->once())
            ->method('run')
            ->with($this->callback(static function (ContainerConfig $config) use (&$capturedConfig): bool {
                $capturedConfig = $config;

                return true;
            }));

        $result = $this->manager->startService('mariadb', '10.6', false);

        $this->assertSame(ServiceStartStatus::STARTED, $result);
        $this->assertNotNull($capturedConfig);
        $this->assertNotSame('127.0.0.1:3306:3306', $capturedConfig->ports[0]);
        $this->assertSame([], $capturedConfig->networkAliases);
    }

    public function testStartServiceDefaultAlwaysGetsStandardPort(): void
    {
        // isDefault=true means this is the configured default version; it must
        // always receive the standard port (3306) even when another version is running.
        $this->dockerManager
            ->method('isContainerRunning')
            ->with('dde-mariadb-11.8')
            ->willReturn(false);

        $capturedConfig = null;
        $this->dockerManager
            ->expects($this->once())
            ->method('run')
            ->with($this->callback(static function (ContainerConfig $config) use (&$capturedConfig): bool {
                $capturedConfig = $config;

                return true;
            }));

        $result = $this->manager->startService('mariadb', '11.8', true);

        $this->assertSame(ServiceStartStatus::STARTED, $result);
        $this->assertNotNull($capturedConfig);
        $this->assertSame('127.0.0.1:3306:3306', $capturedConfig->ports[0]);
        $this->assertSame([], $capturedConfig->networkAliases);
    }

    public function testStopServiceCallsStopAndRemove(): void
    {
        $this->dockerManager
            ->expects($this->once())
            ->method('isContainerRunning')
            ->with('dde-mariadb-11.8')
            ->willReturn(true);

        $this->dockerManager
            ->expects($this->once())
            ->method('stop')
            ->with('dde-mariadb-11.8');

        $this->dockerManager
            ->expects($this->once())
            ->method('remove')
            ->with('dde-mariadb-11.8');

        $this->manager->stopService('mariadb', '11.8');
    }

    public function testStopServiceSkipsWhenNotRunning(): void
    {
        $this->dockerManager
            ->expects($this->once())
            ->method('isContainerRunning')
            ->with('dde-mariadb-11.8')
            ->willReturn(false);

        $this->dockerManager
            ->expects($this->never())
            ->method('stop');

        $this->manager->stopService('mariadb', '11.8');
    }

    public function testIsServiceRunningDelegates(): void
    {
        $this->dockerManager
            ->expects($this->once())
            ->method('isContainerRunning')
            ->with('dde-valkey-9')
            ->willReturn(true);

        $this->assertTrue($this->manager->isServiceRunning('valkey', '9'));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testLoadPortRegistryReturnsEmptyWhenFileDoesNotExist(): void
    {
        $this->assertSame([], $this->manager->loadPortRegistry());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testSaveAndLoadPortRegistry(): void
    {
        $registry = [
            'mariadb-10.6' => 10000,
            'postgres-15' => 10001,
        ];
        $this->manager->savePortRegistry($registry);

        $loaded = $this->manager->loadPortRegistry();

        $this->assertSame($registry, $loaded);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testLoadPortRegistryHandlesInvalidJson(): void
    {
        $path = $this->tempDir.'/ports.json';
        (new Filesystem())->dumpFile($path, 'not-json');

        $this->assertSame([], $this->manager->loadPortRegistry());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testAllocatePortRespectsUpperBound(): void
    {
        $registry = [];
        for ($i = 10000; $i < 10100; $i++) {
            $registry['svc-'.$i] = $i;
        }

        $this->manager->savePortRegistry($registry);

        $port = $this->manager->allocatePort('mariadb', '99', false);
        $this->assertStringContainsString(':10100:', $port);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testFindNextAvailablePortThrowsAtUpperBound(): void
    {
        $registry = [];
        for ($i = 10000; $i <= 65535; $i++) {
            $registry['svc-'.$i] = $i;
        }

        $this->manager->savePortRegistry($registry);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no available port/i');

        $this->manager->allocatePort('new-service', '1', false);
    }

    protected function setUp(): void
    {
        $this->dockerManager = $this->createMock(DockerManager::class);
        $this->tempDir = sys_get_temp_dir().'/dde-test-ssm-'.bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o777, true);

        $this->manager = new SystemServiceManager(
            dockerManager: $this->dockerManager,
            serviceRegistry: new ServiceRegistry([], new DatabaseAdapterRegistry([])),
            filesystem: new Filesystem(),
            dataDir: $this->tempDir,
        );
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tempDir);
    }
}
