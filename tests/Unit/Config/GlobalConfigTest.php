<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Config\Definition\GlobalConfigDefinition;
use App\Config\GlobalConfig;
use PHPUnit\Framework\TestCase;

final class GlobalConfigTest extends TestCase
{
    public function testConstructionWithDefaults(): void
    {
        $config = new GlobalConfig();

        $this->assertSame('text', $config->output);
        $this->assertSame(GlobalConfigDefinition::DNS_FORWARD, $config->dnsForward);
        $this->assertSame([], $config->sshKeys);
        $this->assertSame([], $config->serviceVersions);
    }

    public function testConstructionWithCustomValues(): void
    {
        $config = new GlobalConfig(
            output: 'json',
            dnsForward: ['9.9.9.9'],
            sshKeys: ['/home/user/.ssh/id_rsa'],
            serviceVersions: [
                'php' => '8.5',
                'node' => '22',
            ],
        );

        $this->assertSame('json', $config->output);
        $this->assertSame(['9.9.9.9'], $config->dnsForward);
        $this->assertSame(['/home/user/.ssh/id_rsa'], $config->sshKeys);
        $this->assertSame([
            'php' => '8.5',
            'node' => '22',
        ], $config->serviceVersions);
    }

    public function testFromProcessedConfig(): void
    {
        $processed = [
            'output' => 'json',
            'dns' => [
                'forward' => ['1.1.1.1'],
            ],
            'ssh' => [
                'keys' => ['~/.ssh/id_rsa'],
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

        $config = GlobalConfig::fromProcessedConfig($processed, ['some warning']);

        $this->assertSame('json', $config->output);
        $this->assertSame(['1.1.1.1'], $config->dnsForward);
        $this->assertSame(['~/.ssh/id_rsa'], $config->sshKeys);
        $this->assertSame('10.6', $config->serviceVersions['mariadb']);
        $this->assertSame('6', $config->serviceVersions['valkey']);
        $this->assertSame(['some warning'], $config->warnings);
    }
}
