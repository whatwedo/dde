<?php

declare(strict_types=1);

namespace Tests\Unit\Manager;

use App\Manager\ProjectInitManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ProjectInitManagerTest extends TestCase
{
    private string $tempDir;

    private Filesystem $filesystem;

    private ProjectInitManager $service;

    public function testCreateDirectoryStructureCreatesAllDirs(): void
    {
        $result = $this->service->createDirectoryStructure(
            $this->tempDir,
            'test-project',
            ['mariadb'],
            'web',
            'bash',
            false,
        );

        $this->assertNotEmpty($result['created']);
        $this->assertDirectoryExists($this->tempDir.'/.dde');
        $this->assertDirectoryExists($this->tempDir.'/.dde/hooks/project.up.pre');
        $this->assertDirectoryExists($this->tempDir.'/.dde/hooks/project.up.post');
        $this->assertDirectoryExists($this->tempDir.'/.dde/hooks/project.down.pre');
        $this->assertDirectoryExists($this->tempDir.'/.dde/hooks/project.down.post');
        $this->assertDirectoryExists($this->tempDir.'/.dde/data');
        $this->assertDirectoryExists($this->tempDir.'/.dde/plugins');
        $this->assertFileExists($this->tempDir.'/.dde/.gitignore');
        $this->assertFileExists($this->tempDir.'/.dde/config.yml');
    }

    public function testCreateDirectoryStructureCreatesGitkeepInTrackedDirs(): void
    {
        $this->service->createDirectoryStructure(
            $this->tempDir,
            'test-project',
            [],
            'web',
            null,
            false,
        );

        $this->assertFileExists($this->tempDir.'/.dde/adapters/.gitkeep');
        $this->assertFileExists($this->tempDir.'/.dde/plugins/.gitkeep');
        $this->assertFileExists($this->tempDir.'/.dde/hooks/project.up.pre/.gitkeep');
        $this->assertFileExists($this->tempDir.'/.dde/hooks/project.up.post/.gitkeep');
        $this->assertFileExists($this->tempDir.'/.dde/hooks/project.down.pre/.gitkeep');
        $this->assertFileExists($this->tempDir.'/.dde/hooks/project.down.post/.gitkeep');

        // data/ and snapshots/ have .gitkeep for worktree support
        $this->assertFileExists($this->tempDir.'/.dde/data/.gitkeep');
        $this->assertFileExists($this->tempDir.'/.dde/snapshots/.gitkeep');
    }

    public function testCreateDirectoryStructureDryRunDoesNotCreateGitkeep(): void
    {
        $this->service->createDirectoryStructure($this->tempDir, 'test', [], 'web', null, true);

        $this->assertFileDoesNotExist($this->tempDir.'/.dde/adapters/.gitkeep');
    }

    public function testCreateDirectoryStructureSkipsExistingDirs(): void
    {
        // First run
        $this->service->createDirectoryStructure($this->tempDir, 'test', [], 'web', null, false);

        // Second run
        $result = $this->service->createDirectoryStructure($this->tempDir, 'test', [], 'web', null, false);

        $this->assertNotEmpty($result['skipped']);
        $this->assertSame([], $result['created']);
    }

    public function testCreateDirectoryStructureDryRunDoesNotCreate(): void
    {
        $result = $this->service->createDirectoryStructure($this->tempDir, 'test', [], 'web', null, true);

        $this->assertNotEmpty($result['created']);
        $this->assertDirectoryDoesNotExist($this->tempDir.'/.dde');
    }

    public function testBuildConfigYamlContainsName(): void
    {
        $yaml = $this->service->buildConfigYaml('my-project', ['mariadb'], 'web', 'zsh');

        $this->assertStringContainsString('name: my-project', $yaml);
        $this->assertStringContainsString('mariadb', $yaml);
        $this->assertStringContainsString('shell: zsh', $yaml);
    }

    public function testBuildConfigYamlOmitsEmptyValues(): void
    {
        $yaml = $this->service->buildConfigYaml('', [], 'web', null);

        $this->assertStringNotContainsString('name:', $yaml);
        $this->assertStringNotContainsString('services:', $yaml);
        $this->assertStringNotContainsString('shell:', $yaml);
    }

    public function testBuildConfigYamlEmitsBareStringsForDefaults(): void
    {
        $yaml = $this->service->buildConfigYaml('my-project', ['mariadb', 'valkey'], 'web', null);

        $this->assertStringContainsString('- mariadb', $yaml);
        $this->assertStringContainsString('- valkey', $yaml);
        $this->assertStringNotContainsString('version:', $yaml);
    }

    public function testBuildConfigYamlEmitsMappingForPinnedServices(): void
    {
        $yaml = $this->service->buildConfigYaml(
            'my-project',
            [
                [
                    'name' => 'mariadb',
                    'version' => '11.4',
                ],
                'valkey',
                [
                    'name' => 'postgres',
                    'version' => '16',
                ],
            ],
            'web',
            null,
        );

        $this->assertStringContainsString('name: mariadb', $yaml);
        $this->assertStringContainsString("version: '11.4'", $yaml);
        $this->assertStringContainsString('name: postgres', $yaml);
        $this->assertStringContainsString("version: '16'", $yaml);
        // valkey stays as a bare string
        $this->assertStringContainsString('- valkey', $yaml);
    }

    public function testCreateDirectoryStructureRemovesDdeFromGitignore(): void
    {
        $gitignorePath = $this->tempDir.'/.gitignore';
        file_put_contents($gitignorePath, "vendor/\n.dde\n.env.local\n");

        $this->service->createDirectoryStructure($this->tempDir, 'test', [], 'web', null, false);

        $content = (string) file_get_contents($gitignorePath);
        $this->assertStringNotContainsString('.dde', $content);
        $this->assertStringContainsString('vendor/', $content);
        $this->assertStringContainsString('.env.local', $content);
    }

    public function testCreateDirectoryStructureRemovesDdeSlashFromGitignore(): void
    {
        $gitignorePath = $this->tempDir.'/.gitignore';
        file_put_contents($gitignorePath, "vendor/\n.dde/\n.env.local\n");

        $this->service->createDirectoryStructure($this->tempDir, 'test', [], 'web', null, false);

        $content = (string) file_get_contents($gitignorePath);
        $this->assertStringNotContainsString('.dde', $content);
        $this->assertStringContainsString('vendor/', $content);
    }

    public function testCreateDirectoryStructureLeavesGitignoreWithoutDdeUntouched(): void
    {
        $gitignorePath = $this->tempDir.'/.gitignore';
        $original = "vendor/\n.env.local\n";
        file_put_contents($gitignorePath, $original);

        $this->service->createDirectoryStructure($this->tempDir, 'test', [], 'web', null, false);

        $this->assertSame($original, (string) file_get_contents($gitignorePath));
    }

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/dde_test_init_'.bin2hex(random_bytes(8));
        $this->filesystem = new Filesystem();
        $this->filesystem->mkdir($this->tempDir);

        $this->service = new ProjectInitManager($this->filesystem);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->tempDir);
    }
}
