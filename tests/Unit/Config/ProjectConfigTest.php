<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Config\ProjectConfig;
use App\Model\ServiceDefinition;
use PHPUnit\Framework\TestCase;

final class ProjectConfigTest extends TestCase
{
    public function testConstructionWithDefaults(): void
    {
        $config = new ProjectConfig();

        $this->assertSame('', $config->name);
        $this->assertSame([], $config->services);
        $this->assertSame([], $config->containers);
    }

    public function testConstructionWithCustomValues(): void
    {
        $service = new ServiceDefinition(name: 'mariadb', version: '10.6');

        $config = new ProjectConfig(
            name: 'my-project',
            services: [$service],
            containers: [
                'web' => [
                    'ports' => [80],
                ],
            ],
        );

        $this->assertSame('my-project', $config->name);
        $this->assertCount(1, $config->services);
        $this->assertSame('mariadb', $config->services[0]->name);
        $this->assertSame('10.6', $config->services[0]->version);
        $this->assertSame([
            'web' => [
                'ports' => [80],
            ],
        ], $config->containers);
    }

    public function testFromProcessedConfig(): void
    {
        $processed = [
            'name' => 'my-app',
            'services' => [
                [
                    'name' => 'mariadb',
                    'version' => '10.6',
                ],
                [
                    'name' => 'valkey',
                    'version' => 'latest',
                ],
            ],
            'containers' => [
                'web' => [
                    'shell' => 'zsh',
                    'image' => 'nginx',
                ],
            ],
        ];

        $config = ProjectConfig::fromProcessedConfig($processed);

        $this->assertSame('my-app', $config->name);
        $this->assertCount(2, $config->services);
        $this->assertSame('mariadb', $config->services[0]->name);
        $this->assertSame('10.6', $config->services[0]->version);
        $this->assertSame('valkey', $config->services[1]->name);
        $this->assertSame('latest', $config->services[1]->version);
        $this->assertArrayHasKey('web', $config->containers);
        $this->assertSame('zsh', $config->containers['web']['shell']);
    }
}
