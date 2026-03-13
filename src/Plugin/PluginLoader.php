<?php

declare(strict_types=1);

namespace App\Plugin;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

readonly class PluginLoader
{
    public function __construct(
        private string $configDir = '',
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * Scan plugin directories and return parsed plugin definitions.
     * Project plugins override global plugins with the same @command name.
     *
     * @return list<PluginDefinition>
     */
    public function loadPlugins(?string $projectDir = null): array
    {
        $plugins = [];

        $globalDir = $this->getGlobalPluginsDir();

        if ($globalDir !== null) {
            $plugins = $this->scanDirectory($globalDir);
        }

        if ($projectDir !== null) {
            $projectPlugins = $this->scanDirectory($projectDir.'/.dde/plugins');
            $plugins = $this->mergePlugins($plugins, $projectPlugins);
        }

        return array_values($plugins);
    }

    /**
     * @return array<string, PluginDefinition>
     */
    private function scanDirectory(string $dir): array
    {
        if (!$this->filesystem->exists($dir)) {
            return [];
        }

        $finder = Finder::create()
            ->in($dir)
            ->files()
            ->name('*.sh')
            ->sortByName();

        if (!$finder->hasResults()) {
            return [];
        }

        $realDir = realpath($dir) ?: $dir;
        $plugins = [];

        foreach ($finder as $file) {
            $definition = $this->parseAnnotations($file->getPathname(), $realDir);

            if ($definition instanceof PluginDefinition) {
                $plugins[$definition->command] = $definition;
            }
        }

        return $plugins;
    }

    private function parseAnnotations(string $filePath, ?string $pluginDir = null): ?PluginDefinition
    {
        $content = $this->filesystem->readFile($filePath);

        $command = null;
        $description = '';

        if (preg_match_all('/^#\s*@(\w+)\s+(.+)$/m', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key = $match[1];
                $value = trim($match[2]);

                match ($key) {
                    'command' => $command = $value,
                    'description' => $description = $value,
                    default => null,
                };
            }
        }

        if ($command === null || $command === '') {
            return null;
        }

        // Validate command name: only alphanumeric, hyphens, underscores, colons
        if (!preg_match('/^[a-zA-Z0-9_:-]+$/', $command)) {
            return null;
        }

        return new PluginDefinition(
            command: $command,
            description: $description,
            scriptPath: $filePath,
            pluginDir: $pluginDir,
        );
    }

    /**
     * Merge project plugins into global plugins, overriding by command name.
     *
     * @param array<string, PluginDefinition> $global
     * @param array<string, PluginDefinition> $project
     *
     * @return array<string, PluginDefinition>
     */
    private function mergePlugins(array $global, array $project): array
    {
        return array_merge($global, $project);
    }

    private function getGlobalPluginsDir(): ?string
    {
        if ($this->configDir === '') {
            return null;
        }

        $dir = $this->configDir.'/plugins';

        if (!$this->filesystem->exists($dir)) {
            return null;
        }

        return $dir;
    }
}
