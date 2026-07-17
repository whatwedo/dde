<?php

declare(strict_types=1);

namespace App\Tests\Integration\Manager;

use App\Manager\ProjectInitManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

final class ProjectInitManagerIntegrationTest extends TestCase
{
    private string $tempDir;

    private Filesystem $filesystem;

    private ProjectInitManager $manager;

    public function testCreateDirectoryStructureCreatesAllDirectories(): void
    {
        $this->manager->createDirectoryStructure(
            $this->tempDir,
            'test-project',
            ['mariadb'],
            'web',
            'bash',
            false,
        );

        self::assertDirectoryExists($this->tempDir.'/.dde');
        self::assertDirectoryExists($this->tempDir.'/.dde/data');
        self::assertDirectoryExists($this->tempDir.'/.dde/snapshots');
        self::assertDirectoryExists($this->tempDir.'/.dde/adapters');
        self::assertDirectoryExists($this->tempDir.'/.dde/plugins');
        self::assertDirectoryExists($this->tempDir.'/.dde/hooks/project.up.pre');
        self::assertDirectoryExists($this->tempDir.'/.dde/hooks/project.up.post');
        self::assertDirectoryExists($this->tempDir.'/.dde/hooks/project.down.pre');
        self::assertDirectoryExists($this->tempDir.'/.dde/hooks/project.down.post');
    }

    public function testCreateDirectoryStructureCreatesConfigYaml(): void
    {
        $this->manager->createDirectoryStructure(
            $this->tempDir,
            'my-project',
            ['mariadb', 'valkey'],
            'web',
            'zsh',
            false,
        );

        $configPath = $this->tempDir.'/.dde/config.yml';
        self::assertFileExists($configPath);

        $parsed = Yaml::parseFile($configPath);
        self::assertSame('my-project', $parsed['name']);
        self::assertSame(['mariadb', 'valkey'], $parsed['services']);
        self::assertSame('zsh', $parsed['containers']['web']['shell']);
    }

    public function testCreateDirectoryStructureCreatesGitignore(): void
    {
        $this->manager->createDirectoryStructure(
            $this->tempDir,
            'test-project',
            [],
            'web',
            null,
            false,
        );

        $gitignorePath = $this->tempDir.'/.dde/.gitignore';
        self::assertFileExists($gitignorePath);
        self::assertSame("data/*\n!data/.gitkeep\nsnapshots/*\n!snapshots/.gitkeep\n", file_get_contents($gitignorePath));
    }

    public function testDryRunDoesNotCreateAnything(): void
    {
        $result = $this->manager->createDirectoryStructure(
            $this->tempDir,
            'test-project',
            ['mariadb'],
            'web',
            'bash',
            true,
        );

        self::assertDirectoryDoesNotExist($this->tempDir.'/.dde');
        self::assertNotEmpty($result['created']);
        self::assertEmpty($result['skipped']);
    }

    public function testExistingDirectoriesAreSkipped(): void
    {
        $this->filesystem->mkdir($this->tempDir.'/.dde');
        $this->filesystem->mkdir($this->tempDir.'/.dde/data');

        $result = $this->manager->createDirectoryStructure(
            $this->tempDir,
            'test-project',
            [],
            'web',
            null,
            false,
        );

        self::assertContains('.dde/', $result['skipped']);
        self::assertContains('.dde/data/', $result['skipped']);
        self::assertNotContains('.dde/snapshots/', $result['skipped']);
        self::assertContains('.dde/snapshots/', $result['created']);
    }

    public function testExistingConfigYamlIsUpdated(): void
    {
        $this->filesystem->mkdir($this->tempDir.'/.dde');
        $this->filesystem->dumpFile($this->tempDir.'/.dde/config.yml', "name: original\n");

        $result = $this->manager->createDirectoryStructure(
            $this->tempDir,
            'new-project',
            ['mariadb'],
            'web',
            'bash',
            false,
        );

        $content = (string) file_get_contents($this->tempDir.'/.dde/config.yml');
        self::assertStringContainsString('name: new-project', $content);
        self::assertStringContainsString('mariadb', $content);
        self::assertContains('.dde/config.yml (updated)', $result['skipped']);
    }

    public function testBuildConfigYamlWithEmptyName(): void
    {
        $yaml = $this->manager->buildConfigYaml('', [], 'web', null);

        self::assertStringNotContainsString('name:', $yaml);
    }

    public function testBuildConfigYamlWithServicesAndShell(): void
    {
        $yaml = $this->manager->buildConfigYaml('my-project', ['mariadb', 'valkey'], 'web', 'zsh');

        $parsed = Yaml::parse($yaml);
        self::assertSame('my-project', $parsed['name']);
        self::assertSame(['mariadb', 'valkey'], $parsed['services']);
        self::assertSame('zsh', $parsed['containers']['web']['shell']);
    }

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->tempDir = sys_get_temp_dir().'/dde_init_integration_'.bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->tempDir);

        $this->manager = new ProjectInitManager($this->filesystem);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->tempDir);
    }
}
