<?php

declare(strict_types=1);

namespace App\Util;


final class TempFileUtil
{
    private function __construct()
    {
    }

    public static function createTempFile(string $prefix): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), $prefix);

        if ($tempFile === false) {
            throw new \RuntimeException('Failed to create temporary file');
        }

        return $tempFile;
    }

    public static function createTempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir().'/'.$prefix.bin2hex(random_bytes(8));
        mkdir($dir, 0o777, true);

        if (!is_dir($dir)) {
            throw new \RuntimeException(sprintf('Failed to create temporary directory "%s"', $dir));
        }

        return $dir;
    }
}
