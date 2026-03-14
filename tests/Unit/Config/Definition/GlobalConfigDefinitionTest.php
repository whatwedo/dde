<?php

declare(strict_types=1);

namespace Tests\Unit\Config\Definition;

use App\Config\Definition\GlobalConfigDefinition;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class GlobalConfigDefinitionTest extends TestCase
{
    private Processor $processor;

    private GlobalConfigDefinition $definition;

    public function testEmptyConfigReturnsDefaults(): void
    {
        $result = $this->processor->processConfiguration($this->definition, []);

        $this->assertSame(GlobalConfigDefinition::OUTPUT, $result['output']);
        $this->assertSame(GlobalConfigDefinition::DNS_FORWARD, $result['dns']['forward']);
        $this->assertSame(GlobalConfigDefinition::SSH_KEYS, $result['ssh']['keys']);
        $this->assertSame([], $result['services']);
    }

    public function testFullConfig(): void
    {
        $input = [
            'output' => 'json',
            'dns' => [
                'forward' => ['1.1.1.1', '8.8.8.8'],
            ],
            'ssh' => [
                'keys' => ['~/.ssh/id_ed25519', '~/.ssh/id_rsa'],
            ],
            'services' => [
                'mariadb' => [
                    'version' => '10.6',
                ],
                'valkey' => [
                    'version' => '6',
                ],
            ],
        ];

        $result = $this->processor->processConfiguration($this->definition, [$input]);

        $this->assertSame('json', $result['output']);
        $this->assertSame(['1.1.1.1', '8.8.8.8'], $result['dns']['forward']);
        $this->assertSame(['~/.ssh/id_ed25519', '~/.ssh/id_rsa'], $result['ssh']['keys']);
        $this->assertSame('10.6', $result['services']['mariadb']['version']);
        $this->assertSame('6', $result['services']['valkey']['version']);
    }

    public function testInvalidOutputThrows(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->processor->processConfiguration($this->definition, [
            [
                'output' => 'xml',
            ],
        ]);
    }

    protected function setUp(): void
    {
        $this->processor = new Processor();
        $this->definition = new GlobalConfigDefinition();
    }
}
