<?php

declare(strict_types=1);

namespace App\Service;

use App\Manager\DockerManager;
use App\Util\TempFileUtil;
use Symfony\Component\Filesystem\Filesystem;

readonly class ImageBuilder
{
    public function __construct(
        private DockerManager $dockerManager,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * Build a Docker image if the source files have changed since last build.
     *
     * When $pull is true the hash short-circuit is bypassed so the underlying
     * docker build can refresh the base image via --pull.
     *
     * @param array<string, string> $files Map of filename => content to include in build context
     */
    public function buildIfChanged(string $imageName, string $hashFile, array $files, string $tempPrefix, bool $pull = false): void
    {
        $currentHash = hash('xxh128', implode('', $files));

        if (! $pull && $this->filesystem->exists($hashFile) && $this->dockerManager->imageExists($imageName)) {
            $storedHash = trim($this->filesystem->readFile($hashFile));

            if ($storedHash === $currentHash) {
                return;
            }
        }

        $tempDir = TempFileUtil::createTempDir($tempPrefix);

        try {
            foreach ($files as $filename => $content) {
                $this->filesystem->dumpFile($tempDir.'/'.$filename, $content);

                if (str_ends_with($filename, '.sh')) {
                    $this->filesystem->chmod($tempDir.'/'.$filename, 0o755);
                }
            }

            $this->dockerManager->buildImage($tempDir, $imageName, null, $pull);
        } finally {
            $this->filesystem->remove($tempDir);
        }

        $this->filesystem->mkdir(\dirname($hashFile));
        $this->filesystem->dumpFile($hashFile, $currentHash);
    }
}
