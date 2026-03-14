<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use App\Util\TempFileUtil;
use PHPUnit\Framework\TestCase;

final class TempFileUtilTest extends TestCase
{
    public function testCreateTempFileReturnsExistingFilePath(): void
    {
        $path = TempFileUtil::createTempFile('dde-test-');

        try {
            $this->assertFileExists($path);
        } finally {
            unlink($path);
        }
    }

    public function testCreateTempFilePrefixIsUsed(): void
    {
        $path = TempFileUtil::createTempFile('dde-prefix-');

        try {
            $this->assertStringContainsString('dde-prefix-', basename($path));
        } finally {
            unlink($path);
        }
    }

    public function testCreateTempFileReturnsUniquePathsOnEachCall(): void
    {
        $path1 = TempFileUtil::createTempFile('dde-test-');
        $path2 = TempFileUtil::createTempFile('dde-test-');

        try {
            $this->assertNotSame($path1, $path2);
        } finally {
            unlink($path1);
            unlink($path2);
        }
    }

    public function testCreateTempDirReturnsExistingDirectoryPath(): void
    {
        $dir = TempFileUtil::createTempDir('dde-testdir-');

        try {
            $this->assertDirectoryExists($dir);
        } finally {
            rmdir($dir);
        }
    }

    public function testCreateTempDirPrefixIsUsed(): void
    {
        $dir = TempFileUtil::createTempDir('dde-prefix-');

        try {
            $this->assertStringContainsString('dde-prefix-', basename($dir));
        } finally {
            rmdir($dir);
        }
    }

    public function testCreateTempDirReturnsUniquePathsOnEachCall(): void
    {
        $dir1 = TempFileUtil::createTempDir('dde-test-');
        $dir2 = TempFileUtil::createTempDir('dde-test-');

        try {
            $this->assertNotSame($dir1, $dir2);
        } finally {
            rmdir($dir1);
            rmdir($dir2);
        }
    }
}
