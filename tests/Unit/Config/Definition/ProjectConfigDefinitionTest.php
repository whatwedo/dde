<?php

declare(strict_types=1);

namespace Tests\Unit\Config\Definition;

use App\Config\Definition\ProjectConfigDefinition;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class ProjectConfigDefinitionTest extends TestCase
{
    private Processor $processor;

    private ProjectConfigDefinition $definition;

    public function testEmptyConfigReturnsDefaults(): void
    {
        $result = $this->processor->processConfiguration($this->definition, []);

        $this->assertSame('', $result['name']);
        $this->assertSame([], $result['services']);
        $this->assertSame([], $result['containers']);
    }

    public function testFullConfig(): void
    {
        $input = [
            'name' => 'my-project',
            'services' => [
                [
                    'name' => 'mariadb',
                    'version' => '10.11',
                ],
                [
                    'name' => 'valkey',
                    'version' => '9',
                ],
            ],
            'containers' => [
                'web' => [
                    'shell' => 'zsh',
                    'image' => 'nginx',
                    'default_database_name' => 'my_db',
                ],
            ],
        ];

        $result = $this->processor->processConfiguration($this->definition, [$input]);

        $this->assertSame('my-project', $result['name']);
        $this->assertCount(2, $result['services']);
        $this->assertSame('mariadb', $result['services'][0]['name']);
        $this->assertSame('10.11', $result['services'][0]['version']);
        $this->assertSame('valkey', $result['services'][1]['name']);
        $this->assertSame('9', $result['services'][1]['version']);
        $this->assertArrayHasKey('web', $result['containers']);
        $this->assertSame('zsh', $result['containers']['web']['shell']);
    }

    public function testStringServicesAreNormalized(): void
    {
        $result = $this->processor->processConfiguration($this->definition, [
            [
                'services' => ['mariadb', 'valkey'],
            ],
        ]);

        $this->assertCount(2, $result['services']);
        $this->assertSame('mariadb', $result['services'][0]['name']);
        $this->assertSame('latest', $result['services'][0]['version']);
        $this->assertSame('valkey', $result['services'][1]['name']);
        $this->assertSame('latest', $result['services'][1]['version']);
    }

    public function testMixedServicesFormat(): void
    {
        $result = $this->processor->processConfiguration($this->definition, [
            [
                'services' => [
                    'valkey',
                    [
                        'name' => 'mariadb',
                        'version' => '11.8',
                    ],
                    'mailpit',
                ],
            ],
        ]);

        $this->assertCount(3, $result['services']);
        $this->assertSame('valkey', $result['services'][0]['name']);
        $this->assertSame('latest', $result['services'][0]['version']);
        $this->assertSame('mariadb', $result['services'][1]['name']);
        $this->assertSame('11.8', $result['services'][1]['version']);
        $this->assertSame('mailpit', $result['services'][2]['name']);
        $this->assertSame('latest', $result['services'][2]['version']);
    }

    public function testInvalidServiceNameThrows(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->processor->processConfiguration($this->definition, [
            [
                'services' => ['mongodb'],
            ],
        ]);
    }

    public function testContainersMustBeArrays(): void
    {
        $result = $this->processor->processConfiguration($this->definition, [
            [
                'containers' => [
                    'web' => [
                        'shell' => 'zsh',
                        'ports' => [8080, 8443],
                    ],
                ],
            ],
        ]);

        $this->assertArrayHasKey('web', $result['containers']);
        $this->assertIsArray($result['containers']['web']);
        $this->assertSame('zsh', $result['containers']['web']['shell']);
        $this->assertSame([8080, 8443], $result['containers']['web']['ports']);
    }

    public function testContainerKeysPreserveHyphens(): void
    {
        $result = $this->processor->processConfiguration($this->definition, [
            [
                'containers' => [
                    'my-service' => [
                        'shell' => 'bash',
                    ],
                ],
            ],
        ]);

        $this->assertArrayHasKey('my-service', $result['containers']);
        $this->assertArrayNotHasKey('my_service', $result['containers']);
    }

    protected function setUp(): void
    {
        $this->processor = new Processor();
        $this->definition = new ProjectConfigDefinition();
    }
}
