<?php

declare(strict_types=1);

namespace App\Tests\Integration\Adapter;

use App\Adapter\AdapterRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class AdapterRegistryIntegrationTest extends TestCase
{
    private string $tempDir;

    private Filesystem $filesystem;

    private string $realResourcesDir;

    public function testGetAdapterPathsReturnsBuiltinAdapters(): void
    {
        $registry = new AdapterRegistry(
            resourcesDir: $this->realResourcesDir,
            dataDir: $this->tempDir,
        );

        $paths = $registry->getAdapterPaths($this->tempDir);

        $filenames = array_map('basename', $paths);
        self::assertContains('apache.sh', $filenames);
        self::assertContains('nginx.sh', $filenames);
        self::assertContains('php-fpm.sh', $filenames);
    }

    public function testGetAdapterPathsMergesProjectAdapters(): void
    {
        $projectDir = $this->tempDir.'/project';
        $this->filesystem->mkdir($projectDir.'/.dde/adapters');
        $this->filesystem->dumpFile($projectDir.'/.dde/adapters/custom.sh', '#!/bin/sh');

        $registry = new AdapterRegistry(
            resourcesDir: $this->realResourcesDir,
            dataDir: $this->tempDir,
        );

        $paths = $registry->getAdapterPaths($projectDir);

        $filenames = array_map('basename', $paths);
        self::assertContains('apache.sh', $filenames);
        self::assertContains('nginx.sh', $filenames);
        self::assertContains('php-fpm.sh', $filenames);
        self::assertContains('custom.sh', $filenames);
    }

    public function testGetAdapterPathsReturnsOnlyBuiltinWhenNoProjectAdapters(): void
    {
        $projectDir = $this->tempDir.'/project';
        $this->filesystem->mkdir($projectDir);

        $registry = new AdapterRegistry(
            resourcesDir: $this->realResourcesDir,
            dataDir: $this->tempDir,
        );

        $paths = $registry->getAdapterPaths($projectDir);

        $filenames = array_map('basename', $paths);
        self::assertContains('apache.sh', $filenames);
        self::assertContains('nginx.sh', $filenames);
        self::assertContains('php-fpm.sh', $filenames);
        self::assertNotContains('custom.sh', $filenames);
    }

    public function testGetAdapterPathsIgnoresNonShFiles(): void
    {
        $projectDir = $this->tempDir.'/project';
        $this->filesystem->mkdir($projectDir.'/.dde/adapters');
        $this->filesystem->dumpFile($projectDir.'/.dde/adapters/custom.sh', '#!/bin/sh');
        $this->filesystem->dumpFile($projectDir.'/.dde/adapters/readme.txt', 'This is a readme.');

        $registry = new AdapterRegistry(
            resourcesDir: $this->realResourcesDir,
            dataDir: $this->tempDir,
        );

        $paths = $registry->getAdapterPaths($projectDir);

        $filenames = array_map('basename', $paths);
        self::assertContains('custom.sh', $filenames);
        self::assertNotContains('readme.txt', $filenames);
    }

    public function testProjectAdaptersAreSortedByName(): void
    {
        $projectDir = $this->tempDir.'/project';
        $this->filesystem->mkdir($projectDir.'/.dde/adapters');
        $this->filesystem->dumpFile($projectDir.'/.dde/adapters/02-second.sh', '#!/bin/sh');
        $this->filesystem->dumpFile($projectDir.'/.dde/adapters/01-first.sh', '#!/bin/sh');

        $registry = new AdapterRegistry(
            resourcesDir: $this->realResourcesDir,
            dataDir: $this->tempDir,
        );

        $paths = $registry->getAdapterPaths($projectDir);

        $projectPaths = array_filter($paths, static fn (string $p): bool => str_contains($p, '/.dde/adapters/'));
        $projectFilenames = array_values(array_map('basename', $projectPaths));

        self::assertSame('01-first.sh', $projectFilenames[0]);
        self::assertSame('02-second.sh', $projectFilenames[1]);
    }

    public function testGetBuiltinAdaptersDirPointsToResourcesAdapters(): void
    {
        $registry = new AdapterRegistry(
            resourcesDir: $this->realResourcesDir,
            dataDir: $this->tempDir,
        );

        $dir = $registry->getBuiltinAdaptersDir();

        self::assertSame($this->realResourcesDir.'/adapters', $dir);
        self::assertDirectoryExists($dir);
    }

    public function testGetEntrypointPathReturnsCorrectPath(): void
    {
        $registry = new AdapterRegistry(
            resourcesDir: $this->realResourcesDir,
            dataDir: $this->tempDir,
        );

        $path = $registry->getEntrypointPath();

        self::assertStringEndsWith('entrypoint.sh', $path);
        self::assertFileExists($path);
    }

    public function testEmptyResourcesDirReturnsEmpty(): void
    {
        $nonExistentResourcesDir = $this->tempDir.'/nonexistent-resources';

        $registry = new AdapterRegistry(
            resourcesDir: $nonExistentResourcesDir,
            dataDir: $this->tempDir,
        );

        $projectDir = $this->tempDir.'/project';
        $this->filesystem->mkdir($projectDir);

        $paths = $registry->getAdapterPaths($projectDir);

        self::assertSame([], $paths);
    }

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->tempDir = sys_get_temp_dir().'/dde_adapter_test_'.bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->tempDir);
        $this->realResourcesDir = dirname(__DIR__, 3).'/resources';
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->tempDir);
    }
}
