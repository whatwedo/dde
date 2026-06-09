<?php

declare(strict_types=1);

namespace Tests\Unit\Manager;

use App\Database\DatabaseAdapterInterface;
use App\Database\DatabaseAdapterRegistry;
use App\Database\MariaDbAdapter;
use App\Database\PostgresAdapter;
use App\Manager\DatabaseManager;
use App\Manager\DockerManager;
use App\Model\ServiceDefinition;
use App\Service\ServiceRegistry;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class DatabaseManagerTest extends TestCase
{
    private DockerManager&MockObject $dockerManager;

    private DatabaseAdapterRegistry $adapterRegistry;

    private ServiceRegistry $serviceRegistry;

    private DatabaseManager $databaseManager;

    #[AllowMockObjectsWithoutExpectations]
    public function testResolveContainerNameUsesExplicitName(): void
    {
        $service = new ServiceDefinition(name: 'mariadb', version: '11.8', containerName: 'custom-container');

        $this->assertSame('custom-container', $this->databaseManager->resolveContainerName($service));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testResolveContainerNameFallsBackToDefault(): void
    {
        $service = new ServiceDefinition(name: 'mariadb', version: '11.8', containerName: '');

        $this->assertSame('dde-mariadb-11.8', $this->databaseManager->resolveContainerName($service));
    }

    public function testIsContainerRunningDelegatesToDocker(): void
    {
        $service = new ServiceDefinition(name: 'mariadb', version: '11.8', containerName: 'dde-mariadb-11.8');

        $this->dockerManager
            ->expects($this->once())
            ->method('isContainerRunning')
            ->with('dde-mariadb-11.8')
            ->willReturn(true);

        $this->assertTrue($this->databaseManager->isContainerRunning($service));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testIsContainerNotRunning(): void
    {
        $service = new ServiceDefinition(name: 'mariadb', version: '11.8', containerName: 'dde-mariadb-11.8');

        $this->dockerManager
            ->method('isContainerRunning')
            ->with('dde-mariadb-11.8')
            ->willReturn(false);

        $this->assertFalse($this->databaseManager->isContainerRunning($service));
    }

    public function testGetContainerEnvDelegatesToDocker(): void
    {
        $service = new ServiceDefinition(name: 'mariadb', version: '11.8', containerName: 'dde-mariadb-11.8');
        $expectedEnv = [
            'MYSQL_ROOT_PASSWORD' => 'secret',
            'MYSQL_DATABASE' => 'testdb',
        ];

        $this->dockerManager
            ->expects($this->once())
            ->method('getContainerEnv')
            ->with('dde-mariadb-11.8')
            ->willReturn($expectedEnv);

        $this->assertSame($expectedEnv, $this->databaseManager->getContainerEnv($service));
    }

    public function testExportDumpToFileStreamsThroughDockerAndReturnsByteCount(): void
    {
        $service = new ServiceDefinition(name: 'mariadb', version: '11.8', containerName: 'dde-mariadb-11.8');

        $this->dockerManager
            ->expects($this->once())
            ->method('execCaptureToFileWithEnv')
            ->with(
                'dde-mariadb-11.8',
                ['mysqldump', '-u', 'root', 'testdb'],
                [
                    'MYSQL_PWD' => 'root',
                ],
                '/tmp/snapshot.sql',
            )
            ->willReturn(123);

        $result = $this->databaseManager->exportDumpToFile($service, 'testdb', '/tmp/snapshot.sql');
        $this->assertSame(123, $result);
    }

    public function testImportDumpCallsExecWithInput(): void
    {
        $service = new ServiceDefinition(name: 'mariadb', version: '11.8', containerName: 'dde-mariadb-11.8');

        $handle = fopen('php://memory', 'r');
        self::assertIsResource($handle);

        $this->dockerManager
            ->expects($this->once())
            ->method('execWithInput');

        $this->databaseManager->importDump($service, 'testdb', $handle);
        fclose($handle);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testResolveHostPortReturnsDefaultWhenNoPortMapping(): void
    {
        $service = new ServiceDefinition(name: 'mariadb', version: '11.8', containerName: 'dde-mariadb-11.8');

        $this->dockerManager
            ->method('getContainerPorts')
            ->with('dde-mariadb-11.8')
            ->willReturn([]);

        $this->assertSame(3306, $this->databaseManager->resolveHostPort($service));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testResolveHostPortReturnsMappedPort(): void
    {
        $service = new ServiceDefinition(name: 'mariadb', version: '11.8', containerName: 'dde-mariadb-11.8');

        $this->dockerManager
            ->method('getContainerPorts')
            ->with('dde-mariadb-11.8')
            ->willReturn([
                '3306/tcp' => [
                    [
                        'HostIp' => '0.0.0.0',
                        'HostPort' => '33060',
                    ],
                ],
            ]);

        $this->assertSame(33060, $this->databaseManager->resolveHostPort($service));
    }

    public function testExportDumpToFileThrowsWhenDockerFails(): void
    {
        $service = new ServiceDefinition(name: 'mariadb', version: '11.8', containerName: 'dde-mariadb-11.8');

        $this->dockerManager
            ->expects($this->once())
            ->method('execCaptureToFileWithEnv')
            ->willThrowException(new \RuntimeException('Container is not running'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Container is not running');

        $this->databaseManager->exportDumpToFile($service, 'testdb', '/tmp/snapshot.sql');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testResolveHostPortReturnsDefaultWhenBindingMalformed(): void
    {
        $service = new ServiceDefinition(name: 'mariadb', version: '11.8', containerName: 'dde-mariadb-11.8');

        $this->dockerManager
            ->method('getContainerPorts')
            ->with('dde-mariadb-11.8')
            ->willReturn([
                '3306/tcp' => [
                    [
                        'HostIp' => '0.0.0.0',
                    ],
                ],
            ]);

        $this->assertSame(3306, $this->databaseManager->resolveHostPort($service));
    }

    protected function setUp(): void
    {
        $this->dockerManager = $this->createMock(DockerManager::class);

        $adapter = $this->createStub(DatabaseAdapterInterface::class);
        $adapter->method('getServiceName')->willReturn('mariadb');
        $adapter->method('supports')->willReturnCallback(
            static fn (string $name): bool => $name === 'mariadb',
        );
        $adapter->method('getUsername')->willReturn('root');
        $adapter->method('getPassword')->willReturn('root');
        $adapter->method('getDefaultPort')->willReturn(3306);
        $adapter->method('getDumpCommand')->willReturn(['mysqldump', '-u', 'root', 'testdb']);
        $adapter->method('getRestoreCommand')->willReturn(['mysql', '-u', 'root', 'testdb']);
        $adapter->method('getShellCommand')->willReturn(['mysql', '-u', 'root', 'testdb']);

        $this->adapterRegistry = new DatabaseAdapterRegistry([$adapter]);
        $this->serviceRegistry = new ServiceRegistry([], new DatabaseAdapterRegistry([new MariaDbAdapter(), new PostgresAdapter()]));

        $this->databaseManager = new DatabaseManager(
            $this->dockerManager,
            $this->adapterRegistry,
            $this->serviceRegistry,
        );
    }
}
