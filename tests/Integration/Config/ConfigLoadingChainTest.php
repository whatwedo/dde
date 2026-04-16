<?php

declare(strict_types=1);

namespace App\Tests\Integration\Config;

use App\Config\Definition\GlobalConfigDefinition;
use App\Config\GlobalConfig;
use App\Config\ProjectConfig;
use App\Config\ResolvedConfig;
use App\Database\DatabaseAdapterRegistry;
use App\Manager\GlobalConfigManager;
use App\Manager\ProjectConfigManager;
use App\Model\ServiceDefinition;
use App\Service\ServiceRegistry;
use App\Util\ProcessFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

final class ConfigLoadingChainTest extends TestCase
{
    private string $tempDir;

    private Filesystem $filesystem;

    public function testGlobalConfigLoadsFromYamlFile(): void
    {
        $globalDir = $this->tempDir.'/global';
        $this->filesystem->mkdir($globalDir);

        $config = [
            'output' => 'json',
            'dns' => [
                'forward' => ['1.1.1.1', '8.8.8.8'],
            ],
            'ssh' => [
                'keys' => ['~/.ssh/id_ed25519'],
            ],
            'services' => [
                'mariadb' => [
                    'version' => '10',
                ],
                'valkey' => [
                    'version' => '6',
                ],
            ],
        ];

        file_put_contents($globalDir.'/config.yml', Yaml::dump($config, 4));

        $manager = $this->createGlobalConfigManager($globalDir);
        $globalConfig = $manager->load();

        self::assertInstanceOf(GlobalConfig::class, $globalConfig);
        self::assertSame('json', $globalConfig->output);
        self::assertSame(['1.1.1.1', '8.8.8.8'], $globalConfig->dnsForward);
        self::assertSame(['~/.ssh/id_ed25519'], $globalConfig->sshKeys);
        self::assertSame('10', $globalConfig->serviceVersions['mariadb']);
        self::assertSame('6', $globalConfig->serviceVersions['valkey']);
        self::assertSame([], $globalConfig->warnings);
    }

    public function testProjectConfigLoadsFromYamlFile(): void
    {
        $projectDir = $this->tempDir.'/project';
        $this->filesystem->mkdir($projectDir.'/.dde');

        $config = [
            'name' => 'my-project',
            'services' => [
                [
                    'name' => 'mariadb',
                    'version' => '10.6',
                ],
                [
                    'name' => 'valkey',
                ],
            ],
            'containers' => [
                'web' => [
                    'hosts' => ['my-project.test'],
                ],
            ],
        ];

        file_put_contents($projectDir.'/.dde/config.yml', Yaml::dump($config, 4));

        $manager = $this->createProjectConfigManager($this->tempDir.'/global-unused');
        $projectConfig = $manager->loadProjectConfig($projectDir);

        self::assertInstanceOf(ProjectConfig::class, $projectConfig);
        self::assertSame('my-project', $projectConfig->name);
        self::assertCount(2, $projectConfig->services);

        self::assertInstanceOf(ServiceDefinition::class, $projectConfig->services[0]);
        self::assertSame('mariadb', $projectConfig->services[0]->name);
        self::assertSame('10.6', $projectConfig->services[0]->version);

        self::assertSame('valkey', $projectConfig->services[1]->name);
        self::assertSame('latest', $projectConfig->services[1]->version);

        self::assertArrayHasKey('web', $projectConfig->containers);
        self::assertSame([
            'hosts' => ['my-project.test'],
        ], $projectConfig->containers['web']);
    }

    public function testResolveConfigMergesGlobalAndProject(): void
    {
        // Set up global config
        $globalDir = $this->tempDir.'/global';
        $this->filesystem->mkdir($globalDir);

        $globalYaml = [
            'output' => 'json',
            'dns' => [
                'forward' => ['1.1.1.1'],
            ],
            'services' => [
                'mariadb' => [
                    'version' => '10',
                ],
            ],
        ];

        file_put_contents($globalDir.'/config.yml', Yaml::dump($globalYaml, 4));

        // Set up project config
        $projectDir = $this->tempDir.'/project';
        $this->filesystem->mkdir($projectDir.'/.dde');

        $projectYaml = [
            'name' => 'test-project',
            'services' => [
                [
                    'name' => 'mariadb',
                    'version' => '11.2',
                ],
                [
                    'name' => 'valkey',
                ],
            ],
            'containers' => [
                'app' => [
                    'hosts' => ['test-project.test'],
                ],
            ],
        ];

        file_put_contents($projectDir.'/.dde/config.yml', Yaml::dump($projectYaml, 4));

        $serviceRegistry = new ServiceRegistry([], new DatabaseAdapterRegistry([]));
        $manager = $this->createProjectConfigManager($globalDir, $serviceRegistry);
        $resolved = $manager->resolveConfig($projectDir);

        self::assertInstanceOf(ResolvedConfig::class, $resolved);

        // Global values flow through
        self::assertSame('json', $resolved->output);
        self::assertSame(['1.1.1.1'], $resolved->dnsForward);

        // Project values flow through
        self::assertSame('test-project', $resolved->projectName);
        self::assertCount(2, $resolved->services);
        self::assertArrayHasKey('app', $resolved->containers);

        // Service versions: defaults merged with global overrides
        // Global set mariadb=10, so serviceVersions has that
        self::assertSame('10', $resolved->serviceVersions['mariadb']);

        // Default versions from ServiceRegistry are present for non-overridden services
        self::assertSame('18.3', $resolved->serviceVersions['postgres']);
        self::assertSame('9', $resolved->serviceVersions['valkey']);

        // Project explicit version wins for getServiceVersion()
        self::assertSame('11.2', $resolved->getServiceVersion('mariadb'));

        // Valkey has no project version override (version=latest), so falls back to resolved serviceVersions
        self::assertSame('9', $resolved->getServiceVersion('valkey'));

        // Original configs are accessible
        self::assertInstanceOf(GlobalConfig::class, $resolved->globalConfig);
        self::assertInstanceOf(ProjectConfig::class, $resolved->projectConfig);
    }

    public function testMissingGlobalConfigUsesDefaults(): void
    {
        $nonExistentDir = $this->tempDir.'/does-not-exist';

        $manager = $this->createGlobalConfigManager($nonExistentDir);
        $globalConfig = $manager->load();

        self::assertInstanceOf(GlobalConfig::class, $globalConfig);
        self::assertSame(GlobalConfigDefinition::OUTPUT, $globalConfig->output);
        self::assertSame(GlobalConfigDefinition::DNS_FORWARD, $globalConfig->dnsForward);
        self::assertSame(GlobalConfigDefinition::SSH_KEYS, $globalConfig->sshKeys);
        self::assertSame([], $globalConfig->serviceVersions);
        self::assertSame([], $globalConfig->warnings);
    }

    public function testMissingProjectConfigUsesDefaults(): void
    {
        $projectDir = $this->tempDir.'/empty-project';
        $this->filesystem->mkdir($projectDir);

        $manager = $this->createProjectConfigManager($this->tempDir.'/global-unused');
        $projectConfig = $manager->loadProjectConfig($projectDir);

        self::assertInstanceOf(ProjectConfig::class, $projectConfig);
        self::assertSame('', $projectConfig->name);
        self::assertSame([], $projectConfig->services);
        self::assertSame([], $projectConfig->containers);
    }

    private function createGlobalConfigManager(string $configDir): GlobalConfigManager
    {
        return new GlobalConfigManager(
            configDir: $configDir,
        );
    }

    private function createProjectConfigManager(string $configDir, ?ServiceRegistry $serviceRegistry = null): ProjectConfigManager
    {
        return new ProjectConfigManager(
            globalConfigManager: new GlobalConfigManager(configDir: $configDir),
            serviceRegistry: $serviceRegistry ?? new ServiceRegistry([], new DatabaseAdapterRegistry([])),
            processFactory: new ProcessFactory(),
        );
    }

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->tempDir = sys_get_temp_dir().'/dde_config_test_'.bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->tempDir);
    }
}
