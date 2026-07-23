<?php

declare(strict_types=1);

namespace App\Manager;

use App\Config\Definition\GlobalConfigDefinition;
use App\Config\GlobalConfig;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

readonly class GlobalConfigManager
{
    public const string CONFIG_FILE = 'config.yml';

    public function __construct(
        private string $configDir,
        private Filesystem $filesystem = new Filesystem(),
        private Processor $processor = new Processor(),
    ) {
    }

    public function load(): GlobalConfig
    {
        $path = $this->configDir.'/'.self::CONFIG_FILE;
        $data = $this->loadYaml($path);
        $warnings = [];

        try {
            $processed = $this->processor->processConfiguration(new GlobalConfigDefinition(), [$data]);
            $sshKeysConfigured = is_array($data['ssh'] ?? null) && array_key_exists('keys', $data['ssh']);
        } catch (InvalidConfigurationException $invalidConfigurationException) {
            $warnings[] = sprintf('Invalid global config "%s": %s', $path, $invalidConfigurationException->getMessage());
            $processed = $this->processor->processConfiguration(new GlobalConfigDefinition(), [[]]);
            $sshKeysConfigured = false;
        }

        return GlobalConfig::fromProcessedConfig($processed, $warnings, $sshKeysConfigured);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \RuntimeException
     */
    private function loadYaml(string $path): array
    {
        if (!$this->filesystem->exists($path)) {
            return [];
        }

        try {
            $data = Yaml::parseFile($path, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $parseException) {
            throw new \RuntimeException(sprintf('Invalid YAML in "%s": %s', $path, $parseException->getMessage()), $parseException->getCode(), $parseException);
        }

        if (!is_array($data)) {
            return [];
        }

        return $data;
    }
}
