<?php

declare(strict_types=1);

namespace App\Config;

use App\Config\Definition\GlobalConfigDefinition;

final readonly class GlobalConfig
{
    /**
     * @param array<string> $dnsForward
     * @param array<string>|null $sshKeys null = not configured (auto-detect), empty array = explicitly no keys
     * @param array<string, string> $serviceVersions
     * @param list<string> $warnings
     */
    public function __construct(
        public string $output = GlobalConfigDefinition::OUTPUT,
        public array $dnsForward = GlobalConfigDefinition::DNS_FORWARD,
        public ?array $sshKeys = null,
        public array $serviceVersions = [],
        public array $warnings = [],
        public ?string $defaultBrowser = null,
    ) {
    }

    /**
     * @param array<string, mixed> $processed Output from Processor::processConfiguration()
     * @param list<string> $warnings
     * @param bool $sshKeysConfigured whether ssh.keys was explicitly present in the raw config (the processor cannot distinguish an explicit empty list from the default)
     */
    public static function fromProcessedConfig(array $processed, array $warnings = [], bool $sshKeysConfigured = false): self
    {
        $serviceVersions = [];
        foreach ($processed['services'] as $name => $config) {
            if (is_string($name) && is_array($config) && isset($config['version'])) {
                $serviceVersions[$name] = (string) $config['version'];
            }
        }

        return new self(
            output: $processed['output'],
            dnsForward: $processed['dns']['forward'],
            sshKeys: $sshKeysConfigured ? $processed['ssh']['keys'] : null,
            serviceVersions: $serviceVersions,
            warnings: $warnings,
            defaultBrowser: $processed['default_browser'],
        );
    }
}
