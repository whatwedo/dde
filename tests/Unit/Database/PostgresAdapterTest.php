<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Database\PostgresAdapter;
use PHPUnit\Framework\TestCase;

final class PostgresAdapterTest extends TestCase
{
    private PostgresAdapter $adapter;

    public function testGetServiceName(): void
    {
        $this->assertSame('postgres', $this->adapter->getServiceName());
    }

    public function testGetUsername(): void
    {
        $this->assertSame('postgres', $this->adapter->getUsername());
    }

    public function testGetPassword(): void
    {
        $this->assertSame('postgres', $this->adapter->getPassword());
    }

    public function testGetDefaultPort(): void
    {
        $this->assertSame(5432, $this->adapter->getDefaultPort());
    }

    public function testGetDumpCommand(): void
    {
        $this->assertSame([
            'pg_dump',
            '-U',
            'postgres',
            'mydb',
        ], $this->adapter->getDumpCommand('mydb'));
    }

    public function testGetRestoreCommand(): void
    {
        $this->assertSame([
            'psql',
            '-U',
            'postgres',
            'mydb',
        ], $this->adapter->getRestoreCommand('mydb'));
    }

    public function testGetShellCommand(): void
    {
        $this->assertSame([
            'psql',
            '-U',
            'postgres',
            'mydb',
        ], $this->adapter->getShellCommand('mydb'));
    }

    public function testGetShellCommandWithoutDatabase(): void
    {
        $this->assertSame([
            'psql',
            '-U',
            'postgres',
        ], $this->adapter->getShellCommand());
    }

    public function testGetDsn(): void
    {
        $this->assertSame('postgresql://postgres:postgres@127.0.0.1:5432/mydb', $this->adapter->getDsn('127.0.0.1', 0, 'mydb'));
    }

    public function testGetDsnWithCustomPort(): void
    {
        $this->assertSame('postgresql://postgres:postgres@127.0.0.1:15432/mydb', $this->adapter->getDsn('127.0.0.1', 15432, 'mydb'));
    }

    public function testGetDsnDefaultsToDefaultPort(): void
    {
        $url = $this->adapter->getDsn('127.0.0.1', 0, 'mydb');

        $this->assertStringContainsString(':5432/', $url);
    }

    public function testGetDsnWithoutDatabase(): void
    {
        $this->assertSame('postgresql://postgres:postgres@127.0.0.1:5432', $this->adapter->getDsn('127.0.0.1', 0));
    }

    protected function setUp(): void
    {
        $this->adapter = new PostgresAdapter();
    }
}
