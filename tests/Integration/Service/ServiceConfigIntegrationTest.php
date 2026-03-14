<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Manager\ServiceConfigManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

final class ServiceConfigIntegrationTest extends TestCase
{
    private string $tempDir;

    private Filesystem $filesystem;

    private ServiceConfigManager $manager;

    public function testEnableServiceCreatesConfigEntry(): void
    {
        $this->createConfigFile([
            'name' => 'my-project',
            'services' => [],
        ]);

        $this->manager->enableService('mariadb', $this->configPath());

        $data = Yaml::parseFile($this->configPath());
        $this->assertIsArray($data);
        $this->assertArrayHasKey('services', $data);
        $this->assertContains('mariadb', $data['services']);
        $this->assertCount(1, $data['services']);
        // Verify other keys are preserved
        $this->assertSame('my-project', $data['name']);
    }

    public function testDisableServiceRemovesConfigEntry(): void
    {
        $this->createConfigFile([
            'name' => 'my-project',
            'services' => ['mariadb', 'valkey'],
        ]);

        $result = $this->manager->disableService('mariadb', $this->configPath());

        $this->assertTrue($result);
        $data = Yaml::parseFile($this->configPath());
        $this->assertIsArray($data);
        $this->assertNotContains('mariadb', $data['services']);
        $this->assertContains('valkey', $data['services']);
        $this->assertCount(1, $data['services']);
    }

    public function testEnableMultipleServicesPreservesExisting(): void
    {
        $this->createConfigFile([
            'name' => 'my-project',
            'services' => [],
        ]);

        $this->manager->enableService('mariadb', $this->configPath());
        $this->manager->enableService('valkey', $this->configPath());

        $data = Yaml::parseFile($this->configPath());
        $this->assertIsArray($data);
        $this->assertCount(2, $data['services']);
        $this->assertContains('mariadb', $data['services']);
        $this->assertContains('valkey', $data['services']);
        // Verify ordering: mariadb first, valkey second
        $this->assertSame('mariadb', $data['services'][0]);
        $this->assertSame('valkey', $data['services'][1]);
    }

    public function testEnableWithVersionCreatesCorrectConfig(): void
    {
        $this->createConfigFile([
            'name' => 'my-project',
            'services' => [],
        ]);

        $this->manager->enableService('mariadb', $this->configPath(), '10.11');

        $data = Yaml::parseFile($this->configPath());
        $this->assertIsArray($data);
        $this->assertCount(1, $data['services']);
        $this->assertIsArray($data['services'][0]);
        $this->assertSame('mariadb', $data['services'][0]['name']);
        $this->assertSame('10.11', $data['services'][0]['version']);
    }

    public function testEnableIsIdempotent(): void
    {
        $this->createConfigFile([
            'name' => 'my-project',
            'services' => [],
        ]);

        $this->manager->enableService('mariadb', $this->configPath());
        $this->manager->enableService('mariadb', $this->configPath());

        $data = Yaml::parseFile($this->configPath());
        $this->assertIsArray($data);
        $this->assertCount(1, $data['services']);
        $this->assertContains('mariadb', $data['services']);
    }

    public function testFullLifecycleEnableDisableReEnable(): void
    {
        $this->createConfigFile([
            'name' => 'lifecycle-project',
            'services' => [],
        ]);

        // Enable two services
        $this->manager->enableService('mariadb', $this->configPath(), '10.11');
        $this->manager->enableService('valkey', $this->configPath());

        $data = Yaml::parseFile($this->configPath());
        $this->assertCount(2, $data['services']);

        // Disable mariadb
        $result = $this->manager->disableService('mariadb', $this->configPath(), '10.11');
        $this->assertTrue($result);

        $data = Yaml::parseFile($this->configPath());
        $this->assertCount(1, $data['services']);
        $this->assertContains('valkey', $data['services']);

        // Re-enable mariadb with different version
        $this->manager->enableService('mariadb', $this->configPath(), '11.2');

        $data = Yaml::parseFile($this->configPath());
        $this->assertCount(2, $data['services']);
        $this->assertContains('valkey', $data['services']);
        $this->assertSame('mariadb', $data['services'][1]['name']);
        $this->assertSame('11.2', $data['services'][1]['version']);
    }

    public function testDisableNonExistentServiceReturnsFalse(): void
    {
        $this->createConfigFile([
            'name' => 'my-project',
            'services' => ['valkey'],
        ]);

        $result = $this->manager->disableService('mariadb', $this->configPath());

        $this->assertFalse($result);

        // Verify file unchanged
        $data = Yaml::parseFile($this->configPath());
        $this->assertCount(1, $data['services']);
        $this->assertContains('valkey', $data['services']);
    }

    public function testConfigFileExistsWithRealFile(): void
    {
        $this->assertFalse($this->manager->configFileExists($this->configPath()));

        $this->createConfigFile([
            'name' => 'test',
            'services' => [],
        ]);

        $this->assertTrue($this->manager->configFileExists($this->configPath()));
    }

    private function configPath(): string
    {
        return $this->tempDir.'/.dde/config.yml';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createConfigFile(array $data): void
    {
        $this->filesystem->dumpFile($this->configPath(), Yaml::dump($data, 4, 2));
    }

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->tempDir = sys_get_temp_dir().'/dde_svc_integration_'.bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->tempDir.'/.dde', 0o755);

        $this->manager = new ServiceConfigManager($this->filesystem);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->filesystem->remove($this->tempDir);
        }
    }
}
