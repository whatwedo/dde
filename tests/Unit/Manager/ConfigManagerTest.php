<?php

declare(strict_types=1);

namespace Tests\Unit\Manager;

use App\Config\Definition\GlobalConfigDefinition;
use App\Config\GlobalConfig;
use App\Config\ProjectConfig;
use App\Config\ResolvedConfig;
use App\Database\DatabaseAdapterRegistry;
use App\Manager\ConfigManager;
use App\Service\ServiceRegistry;
use App\Util\ProcessFactory;
use PHPUnit\Framework\TestCase;

final class ConfigManagerTest extends TestCase
{
    private string|false $originalCwd;

    /**
     * @var list<string>
     */
    private array $tempFiles = [];

    /**
     * @var list<string>
     */
    private array $tempDirs = [];

    public function testLoadGlobalConfigReturnsDefaultsWhenNoFile(): void
    {
        $fakeConfigDir = $this->createTempDir();

        $manager = new ConfigManager(configDir: $fakeConfigDir, serviceRegistry: new ServiceRegistry([], new DatabaseAdapterRegistry([])), processFactory: new ProcessFactory());
        $config = $manager->loadGlobalConfig();

        $this->assertInstanceOf(GlobalConfig::class, $config);
        $this->assertSame('text', $config->output);
        $this->assertSame(GlobalConfigDefinition::DNS_FORWARD, $config->dnsForward);
    }

    public function testLoadGlobalConfigParsesExistingFile(): void
    {
        $fakeConfigDir = $this->createTempDir();

        $configPath = $fakeConfigDir.'/'.'config.yml';
        $yaml = <<<'YAML'
            output: json
            YAML;
        file_put_contents($configPath, $yaml);
        $this->tempFiles[] = $configPath;

        $manager = new ConfigManager(configDir: $fakeConfigDir, serviceRegistry: new ServiceRegistry([], new DatabaseAdapterRegistry([])), processFactory: new ProcessFactory());
        $config = $manager->loadGlobalConfig();

        $this->assertSame('json', $config->output);
    }

    public function testLoadProjectConfigParsesFile(): void
    {
        $projectDir = $this->createTempDir();
        $configDir = $projectDir.'/'.'.dde';
        mkdir($configDir, 0o755, true);
        $this->tempDirs[] = $configDir;

        $configPath = $configDir.'/'.'config.yml';
        $yaml = <<<'YAML'
            name: my-project
            services:
                - mariadb
            YAML;
        file_put_contents($configPath, $yaml);
        $this->tempFiles[] = $configPath;

        $manager = new ConfigManager(configDir: '/tmp/nonexistent-dde-config', serviceRegistry: new ServiceRegistry([], new DatabaseAdapterRegistry([])), processFactory: new ProcessFactory());
        $config = $manager->loadProjectConfig($projectDir);

        $this->assertSame('my-project', $config->name);
        $this->assertCount(1, $config->services);
        $this->assertSame('mariadb', $config->services[0]->name);
    }

    public function testLoadProjectConfigReturnsDefaultWhenMissing(): void
    {
        $manager = new ConfigManager(configDir: '/tmp/nonexistent-dde-config', serviceRegistry: new ServiceRegistry([], new DatabaseAdapterRegistry([])), processFactory: new ProcessFactory());
        $config = $manager->loadProjectConfig('/nonexistent/project/dir');

        $this->assertSame('', $config->name);
        $this->assertSame([], $config->services);
        $this->assertSame([], $config->containers);
    }

    public function testResolveConfigMergesGlobalAndProject(): void
    {
        // set up global config
        $fakeConfigDir = $this->createTempDir();

        $globalConfigPath = $fakeConfigDir.'/'.'config.yml';
        file_put_contents($globalConfigPath, "output: json\n");
        $this->tempFiles[] = $globalConfigPath;

        // set up project config
        $projectDir = $this->createTempDir();
        $projectConfigDir = $projectDir.'/'.'.dde';
        mkdir($projectConfigDir, 0o755, true);
        $this->tempDirs[] = $projectConfigDir;

        $projectConfigPath = $projectConfigDir.'/'.'config.yml';
        file_put_contents($projectConfigPath, "name: test-app\n");
        $this->tempFiles[] = $projectConfigPath;

        $manager = new ConfigManager(configDir: $fakeConfigDir, serviceRegistry: new ServiceRegistry([], new DatabaseAdapterRegistry([])), processFactory: new ProcessFactory());
        $resolved = $manager->resolveConfig($projectDir);

        $this->assertInstanceOf(ResolvedConfig::class, $resolved);
        $this->assertSame('json', $resolved->output);
        $this->assertSame('test-app', $resolved->projectName);
    }

    public function testFindProjectDirectoryDoesNotMatchDockerCompose(): void
    {
        $projectDir = $this->createTempDir();
        $subDir = $projectDir.'/src/deep';
        mkdir($subDir, 0o755, true);
        $this->tempDirs[] = $projectDir.'/src/deep';
        $this->tempDirs[] = $projectDir.'/src';

        $composePath = $projectDir.'/docker-compose.yml';
        file_put_contents($composePath, 'version: "3"');
        $this->tempFiles[] = $composePath;

        chdir($subDir);

        $manager = new ConfigManager(configDir: '/tmp/nonexistent-dde-config', serviceRegistry: new ServiceRegistry([], new DatabaseAdapterRegistry([])), processFactory: new ProcessFactory());
        $result = $manager->findProjectDirectory();

        $this->assertNull($result);
    }

    public function testFindDockerProjectDirectoryFindsDockerCompose(): void
    {
        $projectDir = $this->createTempDir();
        $subDir = $projectDir.'/src/deep';
        mkdir($subDir, 0o755, true);
        $this->tempDirs[] = $projectDir.'/src/deep';
        $this->tempDirs[] = $projectDir.'/src';

        $composePath = $projectDir.'/docker-compose.yml';
        file_put_contents($composePath, 'version: "3"');
        $this->tempFiles[] = $composePath;

        chdir($subDir);

        $manager = new ConfigManager(configDir: '/tmp/nonexistent-dde-config', serviceRegistry: new ServiceRegistry([], new DatabaseAdapterRegistry([])), processFactory: new ProcessFactory());
        $result = $manager->findDockerProjectDirectory();

        $this->assertSame($projectDir, $result);
    }

    public function testFindDockerProjectDirectoryReturnsNullWhenNotFound(): void
    {
        $emptyDir = $this->createTempDir();
        chdir($emptyDir);

        $manager = new ConfigManager(configDir: '/tmp/nonexistent-dde-config', serviceRegistry: new ServiceRegistry([], new DatabaseAdapterRegistry([])), processFactory: new ProcessFactory());
        $result = $manager->findDockerProjectDirectory();

        $this->assertNull($result);
    }

    public function testFindProjectDirectoryFindsDdeConfig(): void
    {
        $projectDir = $this->createTempDir();
        $ddeDir = $projectDir.'/.dde';
        mkdir($ddeDir, 0o755, true);
        $this->tempDirs[] = $ddeDir;

        $configPath = $ddeDir.'/config.yml';
        file_put_contents($configPath, 'name: test');
        $this->tempFiles[] = $configPath;

        chdir($projectDir);

        $manager = new ConfigManager(configDir: '/tmp/nonexistent-dde-config', serviceRegistry: new ServiceRegistry([], new DatabaseAdapterRegistry([])), processFactory: new ProcessFactory());
        $result = $manager->findProjectDirectory();

        $this->assertSame($projectDir, $result);
    }

    public function testFindProjectDirectoryReturnsNullWhenNotFound(): void
    {
        $emptyDir = $this->createTempDir();
        chdir($emptyDir);

        $manager = new ConfigManager(configDir: '/tmp/nonexistent-dde-config', serviceRegistry: new ServiceRegistry([], new DatabaseAdapterRegistry([])), processFactory: new ProcessFactory());
        $result = $manager->findProjectDirectory();

        $this->assertNull($result);
    }

    public function testLoadGlobalConfigUsesInjectedConfigDir(): void
    {
        $fakeConfigDir = $this->createTempDir();

        $manager = new ConfigManager(configDir: $fakeConfigDir, serviceRegistry: new ServiceRegistry([], new DatabaseAdapterRegistry([])), processFactory: new ProcessFactory());
        $config = $manager->loadGlobalConfig();

        $this->assertInstanceOf(GlobalConfig::class, $config);
    }

    public function testLoadGlobalConfigWithInvalidValuesCollectsWarnings(): void
    {
        $fakeConfigDir = $this->createTempDir();

        $configPath = $fakeConfigDir.'/'.'config.yml';
        $yaml = <<<'YAML'
            output: invalid_output_format
            YAML;
        file_put_contents($configPath, $yaml);
        $this->tempFiles[] = $configPath;

        $manager = new ConfigManager(configDir: $fakeConfigDir, serviceRegistry: new ServiceRegistry([], new DatabaseAdapterRegistry([])), processFactory: new ProcessFactory());
        $config = $manager->loadGlobalConfig();

        // Should NOT throw, should return defaults with warning
        $this->assertSame('text', $config->output);
        $this->assertNotEmpty($config->warnings);
        $this->assertStringContainsString('Invalid global config', $config->warnings[0]);
    }

    public function testLoadGlobalConfigWithInvalidYamlThrowsRuntimeException(): void
    {
        $fakeConfigDir = $this->createTempDir();

        $configPath = $fakeConfigDir.'/'.'config.yml';
        file_put_contents($configPath, "output: !!php/object 'O:8:\"stdClass\":0:{}'");
        $this->tempFiles[] = $configPath;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Invalid YAML/');

        $manager = new ConfigManager(configDir: $fakeConfigDir, serviceRegistry: new ServiceRegistry([], new DatabaseAdapterRegistry([])), processFactory: new ProcessFactory());
        $manager->loadGlobalConfig();
    }

    public function testLoadProjectConfigWithMalformedYamlThrowsRuntimeException(): void
    {
        $projectDir = $this->createTempDir();
        $configDir = $projectDir.'/.dde';
        mkdir($configDir, 0o755, true);
        $this->tempDirs[] = $configDir;

        $configPath = $configDir.'/config.yml';
        file_put_contents($configPath, 'invalid: yaml: content: [broken');
        $this->tempFiles[] = $configPath;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Invalid YAML/');

        $manager = new ConfigManager(configDir: '/tmp/nonexistent-dde-config', serviceRegistry: new ServiceRegistry([], new DatabaseAdapterRegistry([])), processFactory: new ProcessFactory());
        $manager->loadProjectConfig($projectDir);
    }

    public function testLoadProjectConfigWithEmptyFileReturnsDefaults(): void
    {
        $projectDir = $this->createTempDir();
        $configDir = $projectDir.'/.dde';
        mkdir($configDir, 0o755, true);
        $this->tempDirs[] = $configDir;

        $configPath = $configDir.'/config.yml';
        file_put_contents($configPath, '');
        $this->tempFiles[] = $configPath;

        $manager = new ConfigManager(configDir: '/tmp/nonexistent-dde-config', serviceRegistry: new ServiceRegistry([], new DatabaseAdapterRegistry([])), processFactory: new ProcessFactory());
        $config = $manager->loadProjectConfig($projectDir);

        $this->assertInstanceOf(ProjectConfig::class, $config);
        $this->assertSame('', $config->name);
        $this->assertSame([], $config->services);
        $this->assertSame([], $config->containers);
    }

    public function testLoadProjectConfigWithUnknownKeysThrowsException(): void
    {
        $projectDir = $this->createTempDir();
        $configDir = $projectDir.'/.dde';
        mkdir($configDir, 0o755, true);
        $this->tempDirs[] = $configDir;

        $configPath = $configDir.'/config.yml';
        file_put_contents($configPath, "unknown_key: value\n");
        $this->tempFiles[] = $configPath;

        // Symfony's config Processor throws InvalidConfigurationException for unrecognized keys
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        $manager = new ConfigManager(configDir: '/tmp/nonexistent-dde-config', serviceRegistry: new ServiceRegistry([], new DatabaseAdapterRegistry([])), processFactory: new ProcessFactory());
        $manager->loadProjectConfig($projectDir);
    }

    private function createTempDir(): string
    {
        $baseDir = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
        $dir = $baseDir.'/dde_test_'.bin2hex(random_bytes(8));
        mkdir($dir, 0o755, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    protected function setUp(): void
    {
        $cwd = getcwd();
        $this->originalCwd = $cwd;
    }

    protected function tearDown(): void
    {
        if ($this->originalCwd !== false) {
            chdir($this->originalCwd);
        }

        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        // remove dirs in reverse order (deepest first)
        foreach (array_reverse($this->tempDirs) as $dir) {
            if (is_dir($dir)) {
                @rmdir($dir);
            }
        }

        $this->tempFiles = [];
        $this->tempDirs = [];
    }
}
