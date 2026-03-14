<?php

declare(strict_types=1);

namespace Tests\Unit\Model;

use App\Model\ContainerConfig;
use PHPUnit\Framework\TestCase;

final class ContainerConfigTest extends TestCase
{
    public function testConstructionWithDefaults(): void
    {
        $config = new ContainerConfig(
            image: 'nginx:latest',
            containerName: 'web',
        );

        $this->assertSame('nginx:latest', $config->image);
        $this->assertSame('web', $config->containerName);
        $this->assertSame([], $config->ports);
        $this->assertSame([], $config->volumes);
        $this->assertSame([], $config->environment);
        $this->assertSame([], $config->labels);
        $this->assertSame([], $config->networkAliases);
        $this->assertSame('unless-stopped', $config->restartPolicy);
    }

    public function testConstructionWithAllProperties(): void
    {
        $config = new ContainerConfig(
            image: 'mariadb:11.8',
            containerName: 'db',
            ports: ['3306:3306'],
            volumes: [
                '/data' => '/var/lib/mysql',
            ],
            environment: [
                'MYSQL_ROOT_PASSWORD' => 'secret',
            ],
            labels: [
                'traefik.enable' => 'true',
            ],
            networkAliases: ['database', 'db.local'],
            restartPolicy: 'always',
        );

        $this->assertSame('mariadb:11.8', $config->image);
        $this->assertSame('db', $config->containerName);
        $this->assertSame(['3306:3306'], $config->ports);
        $this->assertSame([
            '/data' => '/var/lib/mysql',
        ], $config->volumes);
        $this->assertSame([
            'MYSQL_ROOT_PASSWORD' => 'secret',
        ], $config->environment);
        $this->assertSame([
            'traefik.enable' => 'true',
        ], $config->labels);
        $this->assertSame(['database', 'db.local'], $config->networkAliases);
        $this->assertSame('always', $config->restartPolicy);
    }
}
