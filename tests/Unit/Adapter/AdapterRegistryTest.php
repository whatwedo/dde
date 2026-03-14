<?php

declare(strict_types=1);

namespace Tests\Unit\Adapter;

use App\Adapter\AdapterRegistry;
use PHPUnit\Framework\TestCase;

final class AdapterRegistryTest extends TestCase
{
    private string $tempDir;

    public function testGetBuiltinAdaptersDirReturnsAdaptersSubdirectory(): void
    {
        $registry = new AdapterRegistry($this->tempDir.'/resources', $this->tempDir.'/data');

        $this->assertSame($this->tempDir.'/resources/adapters', $registry->getBuiltinAdaptersDir());
    }

    public function testGetEntrypointPathReturnsEntrypointScript(): void
    {
        $registry = new AdapterRegistry($this->tempDir.'/resources', $this->tempDir.'/data');

        $this->assertSame($this->tempDir.'/resources/entrypoint.sh', $registry->getEntrypointPath());
    }

    public function testGetAdapterPathsReturnsBuiltinAdapters(): void
    {
        $adaptersDir = $this->tempDir.'/resources/adapters';
        mkdir($adaptersDir, 0o755, true);
        file_put_contents($adaptersDir.'/nginx.sh', '#!/bin/sh');
        file_put_contents($adaptersDir.'/php-fpm.sh', '#!/bin/sh');

        $registry = new AdapterRegistry($this->tempDir.'/resources', $this->tempDir.'/data');

        $paths = $registry->getAdapterPaths($this->tempDir.'/project');

        $this->assertCount(2, $paths);
        $this->assertContains($adaptersDir.'/nginx.sh', $paths);
        $this->assertContains($adaptersDir.'/php-fpm.sh', $paths);
    }

    public function testGetAdapterPathsIncludesProjectAdapters(): void
    {
        $adaptersDir = $this->tempDir.'/resources/adapters';
        mkdir($adaptersDir, 0o755, true);
        file_put_contents($adaptersDir.'/nginx.sh', '#!/bin/sh');

        $projectDir = $this->tempDir.'/project';
        $projectAdaptersDir = $projectDir.'/.dde/adapters';
        mkdir($projectAdaptersDir, 0o755, true);
        file_put_contents($projectAdaptersDir.'/custom.sh', '#!/bin/sh');

        $registry = new AdapterRegistry($this->tempDir.'/resources', $this->tempDir.'/data');

        $paths = $registry->getAdapterPaths($projectDir);

        $this->assertCount(2, $paths);
        $this->assertContains($adaptersDir.'/nginx.sh', $paths);
        $this->assertContains($projectAdaptersDir.'/custom.sh', $paths);
    }

    public function testGetAdapterPathsReturnsEmptyWhenNoAdaptersDir(): void
    {
        $registry = new AdapterRegistry($this->tempDir.'/resources', $this->tempDir.'/data');

        $paths = $registry->getAdapterPaths($this->tempDir.'/project');

        $this->assertSame([], $paths);
    }

    public function testGetAdapterPathsReturnsSortedBuiltinPaths(): void
    {
        $adaptersDir = $this->tempDir.'/resources/adapters';
        mkdir($adaptersDir, 0o755, true);
        file_put_contents($adaptersDir.'/zz-last.sh', '#!/bin/sh');
        file_put_contents($adaptersDir.'/aa-first.sh', '#!/bin/sh');

        $registry = new AdapterRegistry($this->tempDir.'/resources', $this->tempDir.'/data');

        $paths = $registry->getAdapterPaths($this->tempDir.'/project');

        $this->assertSame([
            $adaptersDir.'/aa-first.sh',
            $adaptersDir.'/zz-last.sh',
        ], $paths);
    }

    public function testGetAdapterPathsIgnoresNonShFiles(): void
    {
        $adaptersDir = $this->tempDir.'/resources/adapters';
        mkdir($adaptersDir, 0o755, true);
        file_put_contents($adaptersDir.'/nginx.sh', '#!/bin/sh');
        file_put_contents($adaptersDir.'/readme.txt', 'not a script');
        file_put_contents($adaptersDir.'/.gitkeep', '');

        $registry = new AdapterRegistry($this->tempDir.'/resources', $this->tempDir.'/data');

        $paths = $registry->getAdapterPaths($this->tempDir.'/project');

        $this->assertCount(1, $paths);
        $this->assertContains($adaptersDir.'/nginx.sh', $paths);
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = scandir($dir);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir.'/'.$item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/dde-adapter-test-'.bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }
}
