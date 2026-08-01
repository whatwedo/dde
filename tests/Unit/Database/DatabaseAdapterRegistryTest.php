<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Database\DatabaseAdapterRegistry;
use App\Database\MariaDbAdapter;
use App\Database\PostgresAdapter;
use PHPUnit\Framework\TestCase;

final class DatabaseAdapterRegistryTest extends TestCase
{
    private DatabaseAdapterRegistry $registry;

    public function testGetAdapterForMariaDb(): void
    {
        $adapter = $this->registry->getAdapter('mariadb');

        $this->assertInstanceOf(MariaDbAdapter::class, $adapter);
        $this->assertSame('mariadb', $adapter->getServiceName());
    }

    public function testGetAdapterForPostgres(): void
    {
        $adapter = $this->registry->getAdapter('postgres');

        $this->assertInstanceOf(PostgresAdapter::class, $adapter);
        $this->assertSame('postgres', $adapter->getServiceName());
    }

    public function testGetAdapterResolvesAlias(): void
    {
        $adapter = $this->registry->getAdapter('mysql');

        $this->assertInstanceOf(MariaDbAdapter::class, $adapter);
    }

    public function testGetAdapterThrowsForUnknownService(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('No database adapter found for service "mongodb"');

        $this->registry->getAdapter('mongodb');
    }

    public function testGetAdapterThrowsForEmptyRegistry(): void
    {
        $registry = new DatabaseAdapterRegistry([]);

        $this->expectException(\InvalidArgumentException::class);

        $registry->getAdapter('mariadb');
    }

    protected function setUp(): void
    {
        $this->registry = new DatabaseAdapterRegistry([
            new MariaDbAdapter(),
            new PostgresAdapter(),
        ]);
    }
}
