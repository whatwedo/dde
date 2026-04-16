<?php

declare(strict_types=1);

namespace Tests\Unit\Manager;

use App\Config\Definition\GlobalConfigDefinition;
use App\Config\GlobalConfig;
use App\Manager\GlobalConfigManager;
use PHPUnit\Framework\TestCase;

final class GlobalConfigManagerTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $tempFiles = [];

    /**
     * @var list<string>
     */
    private array $tempDirs = [];

    public function testLoadReturnsDefaultsWhenNoFile(): void
    {
        $configDir = $this->createTempDir();
        $manager = new GlobalConfigManager(configDir: $configDir);
        $config = $manager->load();

        $this->assertInstanceOf(GlobalConfig::class, $config);
        $this->assertSame('text', $config->output);
        $this->assertSame(GlobalConfigDefinition::DNS_FORWARD, $config->dnsForward);
        $this->assertSame([], $config->sshKeys);
        $this->assertSame([], $config->warnings);
    }

    public function testLoadParsesExistingFile(): void
    {
        $configDir = $this->createTempDir();
        $path = $configDir.'/config.yml';
        file_put_contents($path, "output: json\n");
        $this->tempFiles[] = $path;

        $manager = new GlobalConfigManager(configDir: $configDir);
        $config = $manager->load();

        $this->assertSame('json', $config->output);
    }

    public function testLoadParsesSshKeys(): void
    {
        $configDir = $this->createTempDir();
        $path = $configDir.'/config.yml';
        file_put_contents($path, "ssh:\n  keys:\n    - ~/.ssh/id_ed25519\n");
        $this->tempFiles[] = $path;

        $manager = new GlobalConfigManager(configDir: $configDir);
        $config = $manager->load();

        $this->assertSame(['~/.ssh/id_ed25519'], $config->sshKeys);
    }

    public function testLoadParsesDnsForward(): void
    {
        $configDir = $this->createTempDir();
        $path = $configDir.'/config.yml';
        file_put_contents($path, "dns:\n  forward:\n    - 1.1.1.1\n    - 8.8.8.8\n");
        $this->tempFiles[] = $path;

        $manager = new GlobalConfigManager(configDir: $configDir);
        $config = $manager->load();

        $this->assertSame(['1.1.1.1', '8.8.8.8'], $config->dnsForward);
    }

    public function testLoadParsesServiceVersions(): void
    {
        $configDir = $this->createTempDir();
        $path = $configDir.'/config.yml';
        file_put_contents($path, "services:\n  mariadb:\n    version: \"10.9\"\n");
        $this->tempFiles[] = $path;

        $manager = new GlobalConfigManager(configDir: $configDir);
        $config = $manager->load();

        $this->assertSame([
            'mariadb' => '10.9',
        ], $config->serviceVersions);
    }

    public function testLoadReturnsDefaultsOnInvalidConfig(): void
    {
        $configDir = $this->createTempDir();
        $path = $configDir.'/config.yml';
        file_put_contents($path, "output: invalid_format\n");
        $this->tempFiles[] = $path;

        $manager = new GlobalConfigManager(configDir: $configDir);
        $config = $manager->load();

        $this->assertSame('text', $config->output);
        $this->assertNotEmpty($config->warnings);
        $this->assertStringContainsString('Invalid global config', $config->warnings[0]);
    }

    public function testLoadThrowsOnInvalidYaml(): void
    {
        $configDir = $this->createTempDir();
        $path = $configDir.'/config.yml';
        file_put_contents($path, "key: [\n");
        $this->tempFiles[] = $path;

        $manager = new GlobalConfigManager(configDir: $configDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid YAML');
        $manager->load();
    }

    private function createTempDir(): string
    {
        $dir = sys_get_temp_dir().'/dde_global_config_test_'.bin2hex(random_bytes(8));
        mkdir($dir, 0o755, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        foreach (array_reverse($this->tempDirs) as $dir) {
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
    }
}
