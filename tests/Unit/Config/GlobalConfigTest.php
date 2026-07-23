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
        $this->assertNull($config->sshKeys);
        $this->assertSame([], $config->serviceVersions);
        $this->assertNull($config->defaultBrowser);
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
            'default_browser' => '/usr/bin/firefox',
        ];

        $config = GlobalConfig::fromProcessedConfig($processed, ['some warning'], sshKeysConfigured: true);

        $this->assertSame('json', $config->output);
        $this->assertSame(['1.1.1.1'], $config->dnsForward);
        $this->assertSame(['~/.ssh/id_rsa'], $config->sshKeys);
        $this->assertSame('10.6', $config->serviceVersions['mariadb']);
        $this->assertSame('6', $config->serviceVersions['valkey']);
        $this->assertSame(['some warning'], $config->warnings);
        $this->assertSame('/usr/bin/firefox', $config->defaultBrowser);
    }

    public function testFromProcessedConfigWithoutExplicitSshKeysYieldsNull(): void
    {
        $processed = [
            'output' => 'text',
            'dns' => [
                'forward' => GlobalConfigDefinition::DNS_FORWARD,
            ],
            'ssh' => [
                'keys' => GlobalConfigDefinition::SSH_KEYS,
            ],
            'services' => [],
            'default_browser' => null,
        ];

        $config = GlobalConfig::fromProcessedConfig($processed);

        $this->assertNull($config->sshKeys);
    }
}
