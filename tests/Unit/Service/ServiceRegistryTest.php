<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Database\DatabaseAdapterRegistry;
use App\Database\MariaDbAdapter;
use App\Database\PostgresAdapter;
use App\Manager\DockerManager;
use App\Model\ServiceDefinition;
use App\Service\AbstractSystemService;
use App\Service\ServiceRegistry;
use App\Service\TraefikService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ServiceRegistryTest extends TestCase
{
    private ServiceRegistry $registry;

    public function testGetGlobalServicesReturnsIteratedServices(): void
    {
        $services = $this->registry->getGlobalServices();

        $this->assertCount(1, $services);
        $this->assertInstanceOf(AbstractSystemService::class, $services[0]);
    }

    public function testGetGlobalServicesCachesResult(): void
    {
        $first = $this->registry->getGlobalServices();
        $second = $this->registry->getGlobalServices();

        $this->assertSame($first, $second);
    }

    public function testGetServiceConfigReturnsDefinition(): void
    {
        $config = $this->registry->getServiceConfig('mariadb', '11.8');

        $this->assertInstanceOf(ServiceDefinition::class, $config);
        $this->assertSame('mariadb', $config->name);
        $this->assertSame('11.8', $config->version);
        $this->assertSame('dde-mariadb-11.8', $config->containerName);
    }

    public function testGetServiceConfigUsesDefaultVersionWhenNull(): void
    {
        $config = $this->registry->getServiceConfig('mariadb');

        $this->assertSame('11.8', $config->version);
    }

    public function testGetServiceImage(): void
    {
        $this->assertSame('mariadb:11.8', $this->registry->getServiceImage('mariadb', '11.8'));
        $this->assertSame('postgres:18.3', $this->registry->getServiceImage('postgres', '18.3'));
        $this->assertSame('valkey/valkey:9', $this->registry->getServiceImage('valkey', '9'));
        $this->assertSame('axllent/mailpit:latest', $this->registry->getServiceImage('mailpit', 'latest'));
    }

    public function testGetServiceImageUnknownService(): void
    {
        $this->assertSame('custom:1.0', $this->registry->getServiceImage('custom', '1.0'));
    }

    public function testGetServicePort(): void
    {
        $this->assertSame(3306, $this->registry->getServicePort('mariadb'));
        $this->assertSame(5432, $this->registry->getServicePort('postgres'));
        $this->assertSame(6379, $this->registry->getServicePort('valkey'));
        $this->assertSame(8025, $this->registry->getServicePort('mailpit'));
    }

    public function testGetServicePortThrowsForUnknownService(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown service "unknown".');

        $this->registry->getServicePort('unknown');
    }

    public function testGetAllServiceTypes(): void
    {
        $types = $this->registry->getAllServiceTypes();

        $this->assertContains('mariadb', $types);
        $this->assertContains('postgres', $types);
        $this->assertContains('valkey', $types);
        $this->assertContains('mailpit', $types);
        $this->assertCount(4, $types);
    }

    public function testIsKnownService(): void
    {
        $this->assertTrue($this->registry->isKnownService('mariadb'));
        $this->assertTrue($this->registry->isKnownService('postgres'));
        $this->assertTrue($this->registry->isKnownService('valkey'));
        $this->assertTrue($this->registry->isKnownService('mailpit'));
        $this->assertFalse($this->registry->isKnownService('unknown'));
    }

    public function testGetServiceVersion(): void
    {
        $this->assertSame('11.8', $this->registry->getServiceVersion('mariadb'));
        $this->assertSame('18.3', $this->registry->getServiceVersion('postgres'));
        $this->assertSame('9', $this->registry->getServiceVersion('valkey'));
        $this->assertSame('latest', $this->registry->getServiceVersion('mailpit'));
        $this->assertSame('latest', $this->registry->getServiceVersion('unknown'));
    }

    public function testGetKnownVersionsIncludesDefault(): void
    {
        $this->assertContains('11.8', $this->registry->getKnownVersions('mariadb'));
        $this->assertContains('18.3', $this->registry->getKnownVersions('postgres'));
        $this->assertContains('9', $this->registry->getKnownVersions('valkey'));
    }

    public function testGetKnownVersionsEmptyForServicesWithoutVersionChoice(): void
    {
        $this->assertSame([], $this->registry->getKnownVersions('mailpit'));
        $this->assertSame([], $this->registry->getKnownVersions('unknown'));
    }

    public function testSupportsVersionChoice(): void
    {
        $this->assertTrue($this->registry->supportsVersionChoice('mariadb'));
        $this->assertTrue($this->registry->supportsVersionChoice('postgres'));
        $this->assertTrue($this->registry->supportsVersionChoice('valkey'));
        $this->assertFalse($this->registry->supportsVersionChoice('mailpit'));
        $this->assertFalse($this->registry->supportsVersionChoice('unknown'));
    }

    public function testIsDatabaseServiceReturnsTrueForDatabaseServices(): void
    {
        $this->assertTrue($this->registry->isDatabaseService('mariadb'));
        $this->assertTrue($this->registry->isDatabaseService('postgres'));
    }

    public function testIsDatabaseServiceReturnsFalseForNonDatabaseServices(): void
    {
        $this->assertFalse($this->registry->isDatabaseService('valkey'));
        $this->assertFalse($this->registry->isDatabaseService('mailpit'));
        $this->assertFalse($this->registry->isDatabaseService('unknown'));
    }

    public function testGetServiceEnvironmentForMariadb(): void
    {
        $registry = new ServiceRegistry([], $this->createDatabaseAdapterRegistry());
        $env = $registry->getServiceEnvironment('mariadb');
        $this->assertSame('root', $env['MARIADB_ROOT_PASSWORD']);
    }

    public function testGetServiceEnvironmentForPostgres(): void
    {
        $registry = new ServiceRegistry([], $this->createDatabaseAdapterRegistry());
        $env = $registry->getServiceEnvironment('postgres');
        $this->assertSame('postgres', $env['POSTGRES_USER']);
        $this->assertSame('postgres', $env['POSTGRES_PASSWORD']);
    }

    public function testGetServiceEnvironmentForUnknownReturnsEmpty(): void
    {
        $registry = new ServiceRegistry([], $this->createDatabaseAdapterRegistry());
        $this->assertSame([], $registry->getServiceEnvironment('valkey'));
    }

    public function testGetContainerDataMount(): void
    {
        $registry = new ServiceRegistry([], $this->createDatabaseAdapterRegistry());
        $this->assertSame('/var/lib/mysql', $registry->getContainerDataMount('mariadb'));
        $this->assertSame('/var/lib/postgresql/data', $registry->getContainerDataMount('postgres'));
        $this->assertSame('/data', $registry->getContainerDataMount('valkey'));
    }

    public function testGetServiceCredentialsForMariadb(): void
    {
        $creds = $this->registry->getServiceCredentials('mariadb');

        $this->assertSame([
            'user' => 'root',
            'password' => 'root',
        ], $creds);
    }

    public function testGetServiceCredentialsForPostgres(): void
    {
        $creds = $this->registry->getServiceCredentials('postgres');

        $this->assertSame([
            'user' => 'postgres',
            'password' => 'postgres',
        ], $creds);
    }

    public function testGetServiceCredentialsForValkeyReturnsNull(): void
    {
        $this->assertNull($this->registry->getServiceCredentials('valkey'));
    }

    public function testGetServiceCredentialsForMailpitReturnsNull(): void
    {
        $this->assertNull($this->registry->getServiceCredentials('mailpit'));
    }

    public function testGetServiceCredentialsForUnknownReturnsNull(): void
    {
        $this->assertNull($this->registry->getServiceCredentials('unknown'));
    }

    private function createDatabaseAdapterRegistry(): DatabaseAdapterRegistry
    {
        return new DatabaseAdapterRegistry([new MariaDbAdapter(), new PostgresAdapter()]);
    }

    protected function setUp(): void
    {
        $dockerManager = $this->createStub(DockerManager::class);
        $traefikService = new TraefikService(
            dockerManager: $dockerManager,
            filesystem: new Filesystem(),
            dataDir: sys_get_temp_dir(),
        );

        $this->registry = new ServiceRegistry([$traefikService], $this->createDatabaseAdapterRegistry());
    }
}
