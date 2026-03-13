<?php

declare(strict_types=1);

namespace App\Manager;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

final readonly class ServiceConfigManager
{
    public function __construct(
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function enableService(string $serviceName, string $configPath, ?string $version = null): void
    {
        $data = $this->loadConfigData($configPath);

        if ($this->hasService($data['services'], $serviceName, $version)) {
            return;
        }

        $data['services'][] = $this->buildServiceEntry($serviceName, $version);

        $this->filesystem->dumpFile($configPath, Yaml::dump($data, 4, 2));
    }

    public function disableService(string $serviceName, string $configPath, ?string $version = null): bool
    {
        $data = $this->loadConfigData($configPath);
        $originalCount = count($data['services']);
        $data['services'] = $this->removeServiceEntry($data['services'], $serviceName, $version);

        if (count($data['services']) === $originalCount) {
            return false;
        }

        $this->filesystem->dumpFile($configPath, Yaml::dump($data, 4, 2));

        return true;
    }

    public function configFileExists(string $configPath): bool
    {
        return $this->filesystem->exists($configPath);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadConfigData(string $configPath): array
    {
        $data = Yaml::parseFile($configPath, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);

        if (! is_array($data)) {
            $data = [];
        }

        if (! isset($data['services']) || ! is_array($data['services'])) {
            $data['services'] = [];
        }

        return $data;
    }

    /**
     * @return string|array{name: string, version: string}
     */
    private function buildServiceEntry(string $name, ?string $version): string|array
    {
        if ($version === null) {
            return $name;
        }

        return [
            'name' => $name,
            'version' => $version,
        ];
    }

    /**
     * @param array<mixed> $services
     */
    private function hasService(array $services, string $name, ?string $version): bool
    {
        foreach ($services as $entry) {
            if ($version === null && is_string($entry) && $entry === $name) {
                return true;
            }

            if (is_array($entry) && ($entry['name'] ?? null) === $name && ($version === null || ($entry['version'] ?? null) === $version)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $services
     *
     * @return array<mixed>
     */
    private function removeServiceEntry(array $services, string $name, ?string $version): array
    {
        $removed = false;
        $result = [];

        foreach ($services as $entry) {
            if ($removed) {
                $result[] = $entry;
                continue;
            }

            if (is_string($entry) && $entry === $name && $version === null) {
                $removed = true;
                continue;
            }

            if (is_array($entry) && ($entry['name'] ?? null) === $name && ($version === null || ($entry['version'] ?? null) === $version)) {
                $removed = true;
                continue;
            }

            $result[] = $entry;
        }

        return $result;
    }
}
