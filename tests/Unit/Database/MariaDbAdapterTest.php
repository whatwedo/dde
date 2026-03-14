<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Database\MariaDbAdapter;
use PHPUnit\Framework\TestCase;

final class MariaDbAdapterTest extends TestCase
{
    private MariaDbAdapter $adapter;

    public function testGetServiceName(): void
    {
        $this->assertSame('mariadb', $this->adapter->getServiceName());
    }

    public function testGetUsername(): void
    {
        $this->assertSame('root', $this->adapter->getUsername());
    }

    public function testGetPassword(): void
    {
        $this->assertSame('root', $this->adapter->getPassword());
    }

    public function testGetDefaultPort(): void
    {
        $this->assertSame(3306, $this->adapter->getDefaultPort());
    }

    public function testGetDumpCommand(): void
    {
        $this->assertSame([
            'mariadb-dump',
            '-u',
            'root',
            'mydb',
        ], $this->adapter->getDumpCommand('mydb'));
    }

    public function testGetRestoreCommand(): void
    {
        $this->assertSame([
            'mariadb',
            '-u',
            'root',
            'mydb',
        ], $this->adapter->getRestoreCommand('mydb'));
    }

    public function testGetShellCommandWithDatabase(): void
    {
        $this->assertSame([
            'mariadb',
            '-u',
            'root',
            'mydb',
        ], $this->adapter->getShellCommand('mydb'));
    }

    public function testGetShellCommandWithoutDatabase(): void
    {
        $this->assertSame([
            'mariadb',
            '-u',
            'root',
        ], $this->adapter->getShellCommand());
    }

    public function testGetDsn(): void
    {
        $this->assertSame('mysql://root:root@127.0.0.1:3306/mydb', $this->adapter->getDsn('127.0.0.1', 0, 'mydb'));
    }

    public function testGetDsnWithCustomPort(): void
    {
        $this->assertSame('mysql://root:root@127.0.0.1:13306/mydb', $this->adapter->getDsn('127.0.0.1', 13306, 'mydb'));
    }

    public function testGetDsnDefaultsToDefaultPort(): void
    {
        $url = $this->adapter->getDsn('127.0.0.1', 0, 'mydb');

        $this->assertStringContainsString(':3306/', $url);
    }

    public function testGetDsnWithoutDatabase(): void
    {
        $this->assertSame('mysql://root:root@127.0.0.1:3306', $this->adapter->getDsn('127.0.0.1', 0));
    }

    protected function setUp(): void
    {
        $this->adapter = new MariaDbAdapter();
    }
}
