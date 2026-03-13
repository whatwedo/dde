<?php

declare(strict_types=1);

namespace App\Adapter;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

/**
 * Registry of Docker entrypoint adapters.
 *
 * Adapters are shell scripts that customize the container entrypoint for
 * specific base images (e.g. nginx, php-fpm, apache). They are responsible
 * for creating the dde user, setting up permissions, and launching the
 * original entrypoint with the correct UID/GID.
 *
 * Builtin adapters ship with dde in resources/adapters/. Projects can
 * provide custom adapters in .dde/adapters/ which take precedence.
 */
final readonly class AdapterRegistry
{
    public function __construct(
        private string $resourcesDir,
        private string $dataDir,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * Returns paths to all adapter scripts (built-in + project-specific).
     *
     * @return list<string>
     */
    public function getAdapterPaths(string $projectDir): array
    {
        $paths = $this->getBuiltinAdapterFiles();
        $projectAdaptersDir = $projectDir.'/.dde/adapters';

        if ($this->filesystem->exists($projectAdaptersDir)) {
            $finder = Finder::create()
                ->in($projectAdaptersDir)
                ->files()
                ->name('*.sh')
                ->sortByName();

            foreach ($finder as $file) {
                $paths[] = $file->getPathname();
            }
        }

        return $paths;
    }

    /**
     * Returns the path to the built-in adapters directory (Docker-mountable).
     */
    public function getBuiltinAdaptersDir(): string
    {
        return $this->resolveDir().'/adapters';
    }

    /**
     * Returns the path to the entrypoint script (Docker-mountable).
     */
    public function getEntrypointPath(): string
    {
        return $this->resolveDir().'/entrypoint.sh';
    }

    /**
     * @return list<string>
     */
    private function getBuiltinAdapterFiles(): array
    {
        $dir = $this->getBuiltinAdaptersDir();

        if (! $this->filesystem->exists($dir)) {
            return [];
        }

        $finder = Finder::create()
            ->in($dir)
            ->files()
            ->name('*.sh')
            ->sortByName();

        $files = [];

        foreach ($finder as $file) {
            $files[] = $file->getPathname();
        }

        return $files;
    }

    /**
     * Returns a Docker-mountable directory path containing the resources.
     * In PHAR context, extracts resources to the data directory.
     */
    private function resolveDir(): string
    {
        if (! str_starts_with($this->resourcesDir, 'phar://')) {
            return $this->resourcesDir;
        }

        $extractedDir = $this->dataDir.'/resources';

        $this->extractResources($extractedDir);

        return $extractedDir;
    }

    private function extractResources(string $targetDir): void
    {
        $entrypointTarget = $targetDir.'/entrypoint.sh';
        $adaptersTarget = $targetDir.'/adapters';

        // Extract entrypoint
        $entrypointSource = $this->resourcesDir.'/entrypoint.sh';

        if ($this->filesystem->exists($entrypointSource)) {
            $content = $this->filesystem->readFile($entrypointSource);
            $this->filesystem->dumpFile($entrypointTarget, $content);
            $this->filesystem->chmod($entrypointTarget, 0o755);
        }

        // Extract adapters
        $adaptersSource = $this->resourcesDir.'/adapters';

        if ($this->filesystem->exists($adaptersSource)) {
            $this->filesystem->mkdir($adaptersTarget);

            $finder = Finder::create()
                ->in($adaptersSource)
                ->files()
                ->name('*.sh')
                ->sortByName();

            foreach ($finder as $file) {
                $target = $adaptersTarget.'/'.$file->getFilename();
                $this->filesystem->dumpFile($target, $file->getContents());
                $this->filesystem->chmod($target, 0o755);
            }
        }
    }
}
