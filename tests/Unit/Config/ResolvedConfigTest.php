<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Config\Definition\GlobalConfigDefinition;
use App\Config\GlobalConfig;
use App\Config\ProjectConfig;
use App\Config\ResolvedConfig;
use App\Model\ServiceDefinition;
use PHPUnit\Framework\TestCase;

final class ResolvedConfigTest extends TestCase
{
    public function testConstruction(): void
    {
        $globalConfig = new GlobalConfig();
        $projectConfig = new ProjectConfig(name: 'test-project');

        $resolved = new ResolvedConfig(
            globalConfig: $globalConfig,
            projectConfig: $projectConfig,
        );

        $this->assertSame($globalConfig, $resolved->globalConfig);
        $this->assertSame($projectConfig, $resolved->projectConfig);
        $this->assertSame('text', $resolved->globalConfig->output);
    }

    public function testConstructionWithCustomValues(): void
    {
        $globalConfig = new GlobalConfig(output: 'json');
        $projectConfig = new ProjectConfig(name: 'custom');

        $resolved = new ResolvedConfig(
            globalConfig: $globalConfig,
            projectConfig: $projectConfig,
        );

        $this->assertSame('json', $resolved->globalConfig->output);
    }

    public function testMergeWithDefaults(): void
    {
        $global = new GlobalConfig();
        $project = new ProjectConfig();

        $resolved = ResolvedConfig::merge($global, $project);

        $this->assertSame(GlobalConfigDefinition::OUTPUT, $resolved->output);
        $this->assertSame(GlobalConfigDefinition::DNS_FORWARD, $resolved->dnsForward);
        $this->assertNull($resolved->sshKeys);
        $this->assertSame([], $resolved->serviceVersions);
        $this->assertSame('', $resolved->projectName);
        $this->assertSame([], $resolved->services);
        $this->assertSame([], $resolved->containers);
        $this->assertNull($resolved->defaultBrowser);
    }

    public function testDefaultBrowserDelegatesToGlobalConfig(): void
    {
        $resolved = ResolvedConfig::merge(
            new GlobalConfig(defaultBrowser: '/usr/bin/firefox'),
            new ProjectConfig(name: 'test-project'),
        );

        $this->assertSame('/usr/bin/firefox', $resolved->defaultBrowser);
    }

    public function testMergeGlobalOverridesDefaults(): void
    {
        $global = new GlobalConfig(
            output: 'json',
            dnsForward: ['9.9.9.9'],
            sshKeys: ['/home/user/.ssh/id_ed25519'],
        );
        $project = new ProjectConfig();

        $resolved = ResolvedConfig::merge($global, $project);

        $this->assertSame('json', $resolved->output);
        $this->assertSame(['9.9.9.9'], $resolved->dnsForward);
        $this->assertSame(['/home/user/.ssh/id_ed25519'], $resolved->sshKeys);
    }

    public function testMergeServiceVersionsMerged(): void
    {
        $global = new GlobalConfig(
            serviceVersions: [
                'mariadb' => '10.6',
                'valkey' => '6',
            ],
        );
        $project = new ProjectConfig();

        $defaultVersions = [
            'mariadb' => '11.8',
            'postgres' => '18.3',
            'valkey' => '9',
            'mailpit' => 'latest',
        ];

        $resolved = ResolvedConfig::merge($global, $project, $defaultVersions);

        // global overrides defaults
        $this->assertSame('10.6', $resolved->serviceVersions['mariadb']);
        $this->assertSame('6', $resolved->serviceVersions['valkey']);
        // defaults preserved for non-overridden
        $this->assertSame('18.3', $resolved->serviceVersions['postgres']);
        $this->assertSame('latest', $resolved->serviceVersions['mailpit']);
    }

    public function testMergePreservesProjectServices(): void
    {
        $global = new GlobalConfig();
        $services = [
            new ServiceDefinition(name: 'mariadb', version: '11.8'),
            new ServiceDefinition(name: 'valkey'),
        ];
        $project = new ProjectConfig(name: 'my-app', services: $services);

        $resolved = ResolvedConfig::merge($global, $project);

        $this->assertSame($services, $resolved->services);
        $this->assertSame('my-app', $resolved->projectName);
    }

    public function testMergePreservesProjectContainers(): void
    {
        $global = new GlobalConfig();
        $containers = [
            'web' => [
                'image' => 'nginx',
                'ports' => [8080],
            ],
            'worker' => [
                'image' => 'php:8.5-cli',
            ],
        ];
        $project = new ProjectConfig(name: 'my-app', containers: $containers);

        $resolved = ResolvedConfig::merge($global, $project);

        $this->assertSame($containers, $resolved->containers);
    }

    public function testGetServiceVersionFallsBackToDefaults(): void
    {
        $defaultVersions = [
            'mariadb' => '11.8',
            'postgres' => '18.3',
            'valkey' => '9',
            'mailpit' => 'latest',
        ];

        $resolved = ResolvedConfig::merge(new GlobalConfig(), new ProjectConfig(), $defaultVersions);

        $this->assertSame('11.8', $resolved->getServiceVersion('mariadb'));
        $this->assertSame('18.3', $resolved->getServiceVersion('postgres'));
        $this->assertSame('9', $resolved->getServiceVersion('valkey'));
        $this->assertSame('latest', $resolved->getServiceVersion('mailpit'));
    }

    public function testGetServiceVersionUsesGlobalOverride(): void
    {
        $defaultVersions = [
            'mariadb' => '11.8',
            'postgres' => '18.3',
        ];

        $global = new GlobalConfig(serviceVersions: [
            'mariadb' => '10.6',
        ]);
        $resolved = ResolvedConfig::merge($global, new ProjectConfig(), $defaultVersions);

        $this->assertSame('10.6', $resolved->getServiceVersion('mariadb'));
        // Non-overridden still uses defaults
        $this->assertSame('18.3', $resolved->getServiceVersion('postgres'));
    }

    public function testGetServiceVersionProjectExplicitOverridesGlobal(): void
    {
        $global = new GlobalConfig(serviceVersions: [
            'mariadb' => '10.6',
        ]);
        $project = new ProjectConfig(
            name: 'my-app',
            services: [new ServiceDefinition(name: 'mariadb', version: '10.3')],
        );

        $resolved = ResolvedConfig::merge($global, $project, [
            'mariadb' => '11.8',
        ]);

        $this->assertSame('10.3', $resolved->getServiceVersion('mariadb'));
    }

    public function testGetServiceVersionProjectLatestFallsToGlobal(): void
    {
        $global = new GlobalConfig(serviceVersions: [
            'mariadb' => '10.6',
        ]);
        $project = new ProjectConfig(
            name: 'my-app',
            services: [new ServiceDefinition(name: 'mariadb', version: 'latest')],
        );

        $resolved = ResolvedConfig::merge($global, $project, [
            'mariadb' => '11.8',
        ]);

        // version=latest means "use default", falls through to global
        $this->assertSame('10.6', $resolved->getServiceVersion('mariadb'));
    }

    public function testGetServiceVersionUnknownServiceReturnsLatest(): void
    {
        $resolved = ResolvedConfig::merge(new GlobalConfig(), new ProjectConfig());

        $this->assertSame('latest', $resolved->getServiceVersion('unknown-service'));
    }

    public function testIsDefaultVersionMatchesGlobalDefault(): void
    {
        $global = new GlobalConfig(serviceVersions: [
            'mariadb' => '10.6',
        ]);
        $resolved = ResolvedConfig::merge($global, new ProjectConfig(), [
            'mariadb' => '11.8',
        ]);

        $this->assertTrue($resolved->isDefaultVersion('mariadb', '10.6'));
        $this->assertFalse($resolved->isDefaultVersion('mariadb', '11.8'));
    }

    public function testIsDefaultVersionUsesRegistryDefaultsWhenNoOverride(): void
    {
        $resolved = ResolvedConfig::merge(new GlobalConfig(), new ProjectConfig(), [
            'valkey' => '9',
        ]);

        $this->assertTrue($resolved->isDefaultVersion('valkey', '9'));
        $this->assertFalse($resolved->isDefaultVersion('valkey', '6'));
    }

    public function testIsDefaultVersionIgnoresProjectOverrides(): void
    {
        $global = new GlobalConfig();
        $project = new ProjectConfig(
            name: 'my-app',
            services: [new ServiceDefinition(name: 'mariadb', version: '10.3')],
        );

        $resolved = ResolvedConfig::merge($global, $project, [
            'mariadb' => '11.8',
        ]);

        // isDefaultVersion checks global default, not project override
        $this->assertTrue($resolved->isDefaultVersion('mariadb', '11.8'));
        $this->assertFalse($resolved->isDefaultVersion('mariadb', '10.3'));
    }
}
