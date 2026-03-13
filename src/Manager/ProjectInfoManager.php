<?php

declare(strict_types=1);

namespace App\Manager;

use App\Config\ResolvedConfig;
use App\Service\ServiceRegistry;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

readonly class ProjectInfoManager
{
    private const string CONFIG_DIR = '.dde';

    private const string DEFAULT_SHELL = 'bash';

    public function __construct(
        private ServiceRegistry $serviceRegistry,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * @return list<array{name: string, version: string, host: string, port: int, type: string}>
     */
    public function buildServiceData(ResolvedConfig $config): array
    {
        $services = [];

        foreach ($config->services as $service) {
            $version = $config->getServiceVersion($service->name);
            $port = $this->serviceRegistry->isKnownService($service->name)
                ? $this->serviceRegistry->getServicePort($service->name)
                : 0;

            $services[] = [
                'name' => $service->name,
                'version' => $version,
                'host' => $service->name,
                'port' => $port,
                'type' => $service->name,
            ];
        }

        return $services;
    }

    /**
     * @param list<array<string, mixed>> $liveContainers
     *
     * @return list<array{name: string, shell: string, status: string}>
     */
    public function buildContainerData(ResolvedConfig $config, array $liveContainers): array
    {
        $containers = [];

        // Index live containers by service name
        $liveByService = [];

        foreach ($liveContainers as $lc) {
            $serviceName = $lc['Service'] ?? $lc['service'] ?? '';

            if ($serviceName !== '') {
                $liveByService[$serviceName] = $lc;
            }
        }

        // Build from config containers, merging live data
        foreach ($config->containers as $name => $containerConfig) {
            $shell = self::DEFAULT_SHELL;

            if (is_array($containerConfig) && is_string($containerConfig['shell'] ?? null)) {
                $shell = $containerConfig['shell'];
            }

            $status = 'stopped';

            if (isset($liveByService[$name])) {
                $status = $liveByService[$name]['State'] ?? $liveByService[$name]['state'] ?? 'unknown';
            }

            $containers[] = [
                'name' => $name,
                'shell' => $shell,
                'status' => $status,
            ];
        }

        // Add live containers not in config
        foreach ($liveContainers as $lc) {
            $serviceName = $lc['Service'] ?? $lc['service'] ?? '';

            if ($serviceName !== '' && ! array_key_exists((string) $serviceName, $config->containers)) {
                $containers[] = [
                    'name' => $serviceName,
                    'shell' => self::DEFAULT_SHELL,
                    'status' => $lc['State'] ?? $lc['state'] ?? 'unknown',
                ];
            }
        }

        return $containers;
    }

    /**
     * @return array<string, int>
     */
    public function countHooks(string $projectDir): array
    {
        $hookDirs = [
            'up.pre' => $projectDir.'/'.self::CONFIG_DIR.'/hooks/project.up.pre',
            'up.post' => $projectDir.'/'.self::CONFIG_DIR.'/hooks/project.up.post',
            'down.pre' => $projectDir.'/'.self::CONFIG_DIR.'/hooks/project.down.pre',
            'down.post' => $projectDir.'/'.self::CONFIG_DIR.'/hooks/project.down.post',
        ];

        $counts = [];

        foreach ($hookDirs as $key => $dir) {
            $counts[$key] = 0;

            if ($this->filesystem->exists($dir)) {
                $finder = Finder::create()
                    ->in($dir)
                    ->files()
                    ->depth('== 0');

                $counts[$key] = $finder->count();
            }
        }

        return $counts;
    }

    /**
     * @return list<string>
     */
    public function scanPlugins(string $projectDir): array
    {
        $pluginDir = $projectDir.'/'.self::CONFIG_DIR.'/plugins';
        $plugins = [];

        if (! $this->filesystem->exists($pluginDir)) {
            return [];
        }

        $finder = Finder::create()
            ->in($pluginDir)
            ->files()
            ->name('*.sh')
            ->sortByName();

        foreach ($finder as $file) {
            $content = $this->filesystem->readFile($file->getPathname());

            if (preg_match('/@command\s+(.+)/', $content, $matches)) {
                $plugins[] = trim($matches[1]);
            }
        }

        return $plugins;
    }
}
