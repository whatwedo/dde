<?php

declare(strict_types=1);

namespace App\Util;

use App\Config\ResolvedConfig;
use App\Manager\DockerComposeManager;

class ShellDetectorUtil
{
    private const array SHELL_CANDIDATES = ['zsh', 'bash', 'sh'];

    /**
     * @var array<string, string>
     */
    private array $cache = [];

    public function __construct(
        private readonly DockerComposeManager $dockerComposeManager,
    ) {
    }

    /**
     * Detect the best available shell for a service.
     *
     * Respects an explicit `shell` key in containers config, otherwise probes
     * the running container for available shells. Results are cached per
     * project+service within the same process to avoid redundant exec calls.
     */
    public function detect(ResolvedConfig $config, string $service, string $projectDir): string
    {
        $containerConfig = $config->containers[$service] ?? [];

        if (is_array($containerConfig) && isset($containerConfig['shell']) && is_string($containerConfig['shell'])) {
            return $containerConfig['shell'];
        }

        $cacheKey = $projectDir.':'.$service;

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        return $this->cache[$cacheKey] = $this->detectFromContainer($projectDir, $service);
    }

    private function detectFromContainer(string $projectDir, string $service): string
    {
        foreach (self::SHELL_CANDIDATES as $shell) {
            $process = $this->dockerComposeManager->exec($projectDir, $service, ['which', $shell], [
                'user' => 'root',
            ]);
            $process->run();

            if ($process->isSuccessful()) {
                return $shell;
            }
        }

        return 'sh';
    }
}
