<?php

declare(strict_types=1);

namespace App\Config;

use App\Model\ServiceDefinition;

final class ResolvedConfig
{
    /**
     * @var array<string>
     */
    public array $dnsForward { get => $this->globalConfig->dnsForward; }

    /**
     * @var array<string>|null null = not configured (auto-detect), empty array = explicitly no keys
     */
    public ?array $sshKeys { get => $this->globalConfig->sshKeys; }

    public SshAgentMode $sshAgentMode { get => $this->globalConfig->sshAgentMode; }

    public ?string $sshAgentSource { get => $this->globalConfig->sshAgentSource; }

    /**
     * @var array<ServiceDefinition>
     */
    public array $services { get => $this->projectConfig->services; }

    /**
     * @var array<string, mixed>
     */
    public array $containers { get => $this->projectConfig->containers; }

    /**
     * @var list<string>
     */
    public array $warnings { get => $this->globalConfig->warnings; }

    public string $output { get => $this->globalConfig->output; }

    public ?string $defaultBrowser { get => $this->globalConfig->defaultBrowser; }

    public string $projectName { get => $this->projectConfig->name; }

    /**
     * @param array<string, string> $serviceVersions
     */
    public function __construct(
        public readonly GlobalConfig $globalConfig,
        public readonly ProjectConfig $projectConfig,
        public readonly array $serviceVersions = [],
    ) {
    }

    /**
     * Returns the resolved version for a given service name.
     *
     * Override chain: project explicit version > global/resolved serviceVersions > hardcoded defaults.
     */
    public function getServiceVersion(string $serviceName): string
    {
        // 1. Project explicit version
        foreach ($this->services as $service) {
            if ($service->name === $serviceName && $service->version !== 'latest') {
                return $service->version;
            }
        }

        // 2. Global/resolved service versions (already merged defaults < global)
        if (isset($this->serviceVersions[$serviceName])) {
            return $this->serviceVersions[$serviceName];
        }

        // 3. Fallback
        return 'latest';
    }

    /**
     * Checks if the given version matches the global default (ignoring project overrides).
     */
    public function isDefaultVersion(string $serviceName, string $version): bool
    {
        return ($this->serviceVersions[$serviceName] ?? 'latest') === $version;
    }

    /**
     * @param array<string, string> $defaultServiceVersions
     */
    public static function merge(GlobalConfig $global, ProjectConfig $project, array $defaultServiceVersions = []): self
    {
        return new self(
            globalConfig: $global,
            projectConfig: $project,
            serviceVersions: array_merge($defaultServiceVersions, $global->serviceVersions),
        );
    }
}
