<?php

declare(strict_types=1);

namespace Tests\Unit\Manager;

use App\Manager\ServiceConfigManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

final class ServiceConfigManagerTest extends TestCase
{
    private string $tempDir;

    private Filesystem $filesystem;

    private ServiceConfigManager $manager;

    public function testEnableServiceWithoutVersion(): void
    {
        $this->createConfigFile([]);

        $this->manager->enableService('mariadb', $this->configPath());

        $data = Yaml::parseFile($this->configPath());
        $this->assertIsArray($data);
        $this->assertContains('mariadb', $data['services']);
    }

    public function testEnableServiceWithVersion(): void
    {
        $this->createConfigFile([]);

        $this->manager->enableService('mariadb', $this->configPath(), '10.6');

        $data = Yaml::parseFile($this->configPath());
        $this->assertIsArray($data);
        $this->assertContains([
            'name' => 'mariadb',
            'version' => '10.6',
        ], $data['services']);
    }

    public function testEnableServiceAddsToExistingServices(): void
    {
        $this->createConfigFile(['valkey']);

        $this->manager->enableService('mariadb', $this->configPath());

        $data = Yaml::parseFile($this->configPath());
        $this->assertIsArray($data);
        $this->assertContains('valkey', $data['services']);
        $this->assertContains('mariadb', $data['services']);
        $this->assertCount(2, $data['services']);
    }

    public function testEnableServiceCreatesServicesKeyIfMissing(): void
    {
        $this->filesystem->dumpFile($this->configPath(), Yaml::dump([
            'name' => 'test',
        ], 4, 2));

        $this->manager->enableService('valkey', $this->configPath());

        $data = Yaml::parseFile($this->configPath());
        $this->assertIsArray($data);
        $this->assertContains('valkey', $data['services']);
    }

    public function testDisableServiceRemovesSimpleEntry(): void
    {
        $this->createConfigFile(['mariadb', 'valkey']);

        $this->manager->disableService('mariadb', $this->configPath());

        $data = Yaml::parseFile($this->configPath());
        $this->assertIsArray($data);
        $this->assertNotContains('mariadb', $data['services']);
        $this->assertContains('valkey', $data['services']);
    }

    public function testDisableServiceRemovesVersionedEntry(): void
    {
        $this->createConfigFileRaw([
            'name' => 'test-project',
            'services' => [
                [
                    'name' => 'mariadb',
                    'version' => '10.6',
                ],
            ],
        ]);

        $this->manager->disableService('mariadb', $this->configPath(), '10.6');

        $data = Yaml::parseFile($this->configPath());
        $this->assertIsArray($data);
        $this->assertSame([], $data['services']);
    }

    public function testDisableServiceOnlyRemovesFirstOccurrence(): void
    {
        $this->createConfigFile(['mariadb', 'valkey', 'mariadb']);

        $this->manager->disableService('mariadb', $this->configPath());

        $data = Yaml::parseFile($this->configPath());
        $this->assertIsArray($data);
        $this->assertContains('valkey', $data['services']);
        $mariadbCount = count(array_filter($data['services'], static fn (mixed $s): bool => $s === 'mariadb'));
        $this->assertSame(1, $mariadbCount);
    }

    public function testDisableServiceWithVersionDoesNotRemoveUnversioned(): void
    {
        $this->createConfigFile(['mariadb']);

        $this->manager->disableService('mariadb', $this->configPath(), '10.6');

        $data = Yaml::parseFile($this->configPath());
        $this->assertIsArray($data);
        // Unversioned entry should remain since version filter does not match
        $this->assertContains('mariadb', $data['services']);
    }

    public function testEnableServiceIsIdempotent(): void
    {
        $this->createConfigFile(['mariadb']);
        $this->manager->enableService('mariadb', $this->configPath());
        $data = Yaml::parseFile($this->configPath());
        $this->assertIsArray($data);
        $this->assertCount(1, $data['services']);
        $this->assertContains('mariadb', $data['services']);
    }

    public function testEnableServiceWithVersionIsIdempotent(): void
    {
        $this->createConfigFileRaw([
            'name' => 'test-project',
            'services' => [
                [
                    'name' => 'mariadb',
                    'version' => '10.6',
                ],
            ],
        ]);
        $this->manager->enableService('mariadb', $this->configPath(), '10.6');
        $data = Yaml::parseFile($this->configPath());
        $this->assertIsArray($data);
        $this->assertCount(1, $data['services']);
    }

    public function testEnableServiceWithDifferentVersionAddsNewEntry(): void
    {
        $this->createConfigFile(['mariadb']);
        $this->manager->enableService('mariadb', $this->configPath(), '10.6');
        $data = Yaml::parseFile($this->configPath());
        $this->assertIsArray($data);
        $this->assertCount(2, $data['services']);
    }

    public function testDisableServiceReturnsTrueWhenServiceRemoved(): void
    {
        $this->createConfigFile(['mariadb', 'valkey']);
        $result = $this->manager->disableService('mariadb', $this->configPath());
        $this->assertTrue($result);
    }

    public function testDisableServiceReturnsFalseWhenServiceNotFound(): void
    {
        $this->createConfigFile(['valkey']);
        $result = $this->manager->disableService('mariadb', $this->configPath());
        $this->assertFalse($result);
    }

    public function testConfigFileExistsReturnsTrueForExistingFile(): void
    {
        $this->createConfigFile([]);

        $this->assertTrue($this->manager->configFileExists($this->configPath()));
    }

    public function testConfigFileExistsReturnsFalseForMissingFile(): void
    {
        $this->assertFalse($this->manager->configFileExists($this->configPath()));
    }

    private function configPath(): string
    {
        return $this->tempDir.'/.dde/config.yml';
    }

    /**
     * @param list<string> $services
     */
    private function createConfigFile(array $services): void
    {
        $this->createConfigFileRaw([
            'name' => 'test-project',
            'services' => $services,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createConfigFileRaw(array $data): void
    {
        $this->filesystem->dumpFile($this->configPath(), Yaml::dump($data, 4, 2));
    }

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/dde_test_svc_config_'.bin2hex(random_bytes(8));
        mkdir($this->tempDir.'/.dde', 0o755, true);

        $this->filesystem = new Filesystem();
        $this->manager = new ServiceConfigManager($this->filesystem);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->filesystem->remove($this->tempDir);
        }
    }
}
