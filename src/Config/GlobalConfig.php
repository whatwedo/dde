<?php

declare(strict_types=1);

namespace App\Config;

use App\Config\Definition\GlobalConfigDefinition;

final readonly class GlobalConfig
{
    /**
     * @param array<string> $dnsForward
     * @param array<string> $sshKeys
     * @param array<string, string> $serviceVersions
     * @param list<string> $warnings
     */
    public function __construct(
        public string $output = GlobalConfigDefinition::OUTPUT,
        public array $dnsForward = GlobalConfigDefinition::DNS_FORWARD,
        public array $sshKeys = GlobalConfigDefinition::SSH_KEYS,
        public array $serviceVersions = [],
        public array $warnings = [],
        public ?string $defaultBrowser = null,
    ) {
    }

    /**
     * @param array<string, mixed> $processed Output from Processor::processConfiguration()
     * @param list<string> $warnings
     */
    public static function fromProcessedConfig(array $processed, array $warnings = []): self
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
            sshKeys: $processed['ssh']['keys'],
            serviceVersions: $serviceVersions,
            warnings: $warnings,
            defaultBrowser: $processed['default_browser'],
        );
    }
}
