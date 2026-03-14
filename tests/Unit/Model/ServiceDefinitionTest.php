<?php

declare(strict_types=1);

namespace Tests\Unit\Model;

use App\Model\ServiceDefinition;
use PHPUnit\Framework\TestCase;

final class ServiceDefinitionTest extends TestCase
{
    public function testConstructionWithDefaults(): void
    {
        $service = new ServiceDefinition(name: 'mysql');

        $this->assertSame('mysql', $service->name);
        $this->assertSame('latest', $service->version);
        $this->assertSame('', $service->containerName);
        $this->assertSame([], $service->ports);
    }

    public function testConstructionWithAllFields(): void
    {
        $service = new ServiceDefinition(
            name: 'valkey',
            version: '7.2',
            containerName: 'my-valkey',
            ports: [6379],
        );

        $this->assertSame('valkey', $service->name);
        $this->assertSame('7.2', $service->version);
        $this->assertSame('my-valkey', $service->containerName);
        $this->assertSame([6379], $service->ports);
    }
}
