<?php

declare(strict_types=1);

namespace App\Util;

use App\Database\DatabaseAdapterRegistry;
use App\Service\MailpitService;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

readonly class DockerComposeModifier
{
    public function __construct(
        private DatabaseAdapterRegistry $databaseAdapterRegistry,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public function addNetwork(array &$config, string $networkName): bool
    {
        if (isset($config['networks']['default']['name']) && $config['networks']['default']['name'] === $networkName) {
            return false;
        }

        $config['networks']['default'] = [
            'name' => $networkName,
            'external' => true,
        ];

        return true;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function addTraefikLabels(array &$config, string $serviceName, string $projectName, bool $isPrimaryContainer = false): bool
    {
        if (! isset($config['services'][$serviceName]) || ! is_array($config['services'][$serviceName])) {
            return false;
        }

        $service = &$config['services'][$serviceName];

        // Determine hostnames: from VIRTUAL_HOST if present, or project name for primary container
        $virtualHost = $this->extractVirtualHost($service);

        if ($virtualHost !== null) {
            $hostnames = array_map(trim(...), explode(',', $virtualHost));
        } elseif ($isPrimaryContainer) {
            $hostnames = [$projectName.'.test'];
        } else {
            return false;
        }

        // Extract VIRTUAL_PORT if present (read-only)
        $virtualPort = $this->extractEnvironmentVariable($service, 'VIRTUAL_PORT');

        // Skip if Traefik labels already present (before any side effects)
        $existingLabels = $service['labels'] ?? [];

        if (! is_array($existingLabels)) {
            $existingLabels = [];
        }

        foreach ($existingLabels as $label) {
            if (is_string($label) && str_starts_with($label, 'traefik.enable=')) {
                return false;
            }
        }

        // Remove VIRTUAL_HOST and VIRTUAL_PORT from environment
        $this->removeEnvironmentVariable($service, 'VIRTUAL_HOST');
        $this->removeEnvironmentVariable($service, 'VIRTUAL_PORT');

        $port = $virtualPort !== null ? (int) $virtualPort : null;
        $traefikLabels = TraefikLabelGenerator::generateLabels($hostnames, $serviceName, $port);

        $service['labels'] = array_merge($existingLabels, $traefikLabels);

        return true;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function addSshAgentVolume(array &$config, string $serviceName): bool
    {
        if (! isset($config['services'][$serviceName])) {
            return false;
        }

        $service = &$config['services'][$serviceName];
        $volumeMount = 'dde_ssh-agent_socket-dir:/tmp/ssh-agent:ro';

        $existingVolumes = $service['volumes'] ?? [];

        if (! is_array($existingVolumes)) {
            $existingVolumes = [];
        }

        // Early return if canonical volume already present
        if (in_array($volumeMount, $existingVolumes, true)) {
            return false;
        }

        // Remove any existing ssh-agent socket volume (v1 or v2 format)
        $changed = false;
        $existingVolumes = array_values(array_filter($existingVolumes, static function (mixed $volume) use (&$changed): bool {
            if (is_string($volume) && str_contains($volume, 'ssh-agent') && str_contains($volume, '/tmp/ssh-agent')) {
                $changed = true;

                return false;
            }

            return true;
        }));

        $service['volumes'] = array_merge($existingVolumes, [$volumeMount]);

        // Also define the external volume at top level
        if (! isset($config['volumes']['dde_ssh-agent_socket-dir'])) {
            $config['volumes']['dde_ssh-agent_socket-dir'] = [
                'external' => true,
            ];
        }

        // Remove old top-level volume definitions (v1 format without dde_ prefix)
        foreach (array_keys($config['volumes'] ?? []) as $volumeName) {
            if (is_string($volumeName) && $volumeName !== 'dde_ssh-agent_socket-dir' && str_contains($volumeName, 'ssh-agent') && str_contains($volumeName, 'socket')) {
                unset($config['volumes'][$volumeName]);
                $changed = true;
            }
        }

        return true;
    }

    /**
     * Removes v1 build args (DDE_UID, DDE_GID) from a service's build configuration.
     *
     * @param array<string, mixed> $config
     */
    public function removeV1BuildArgs(array &$config, string $serviceName): bool
    {
        if (! isset($config['services'][$serviceName]['build']['args']) || ! is_array($config['services'][$serviceName]['build']['args'])) {
            return false;
        }

        $args = &$config['services'][$serviceName]['build']['args'];
        $changed = false;

        foreach (['DDE_UID', 'DDE_GID'] as $key) {
            if (array_key_exists($key, $args)) {
                unset($args[$key]);
                $changed = true;
            }
        }

        // Clean up empty args section
        if ($args === []) {
            unset($config['services'][$serviceName]['build']['args']);

            // Clean up build section if only context/target remain or is empty
            $build = $config['services'][$serviceName]['build'];

            if (is_array($build) && $build === []) {
                unset($config['services'][$serviceName]['build']);
            }
        }

        return $changed;
    }

    /**
     * Detects auxiliary services (mariadb, postgres, mailpit) and adds
     * corresponding environment variables (DATABASE_URL, MAILER_DSN) to the
     * given target service.
     *
     * @param array<string, mixed> $config      Full compose config (passed by reference)
     * @param string               $serviceName Target service to add env vars to (e.g. "web")
     * @param string               $projectName Project name used for database name
     * @param string|null          $projectDir  Project directory to check .env files in
     *
     * @return list<string> List of human-readable changes applied
     */
    public function addServiceEnvironment(array &$config, string $serviceName, string $projectName, ?string $projectDir = null): array
    {
        if (! isset($config['services'][$serviceName]) || ! is_array($config['services'][$serviceName])) {
            return [];
        }

        $service = &$config['services'][$serviceName];
        $changes = [];
        $dbName = str_replace('-', '_', $projectName);

        /** @var array<string, mixed> $services */
        $services = $config['services'];
        $serviceNames = array_keys($services);

        // Detect database services
        foreach ($serviceNames as $composeSvcName) {
            if (!$this->databaseAdapterRegistry->hasAdapter($composeSvcName)) {
                continue;
            }

            $adapter = $this->databaseAdapterRegistry->getAdapter($composeSvcName);
            $url = $adapter->getDsn(host: $composeSvcName, database: $dbName);

            if ($this->setEnvironmentVariable($service, 'DATABASE_URL', $url, $projectDir)) {
                $changes[] = sprintf('Added DATABASE_URL for %s to service "%s"', $composeSvcName, $serviceName);
            }

            break;
        }

        // Detect mailpit
        if (in_array('mailpit', $serviceNames, true) && $this->setEnvironmentVariable($service, 'MAILER_DSN', MailpitService::MAILER_DSN, $projectDir)) {
            $changes[] = sprintf('Added MAILER_DSN for mailpit to service "%s"', $serviceName);
        }

        return $changes;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function write(string $path, array $config): void
    {
        // Normalize service key order so `environment:` always follows `image:`
        // or `build:` — the spot where readers expect container configuration
        // to start. This only reorders keys, never changes values.
        if (isset($config['services']) && is_array($config['services'])) {
            foreach ($config['services'] as $serviceName => $serviceConfig) {
                if (is_array($serviceConfig)) {
                    $config['services'][$serviceName] = $this->reorderServiceKeys($serviceConfig);
                }
            }
        }

        $yaml = Yaml::dump($config, 10, 4, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);

        // Ensure all label list items are single-quoted
        $yaml = $this->quoteLabels($yaml);

        $this->filesystem->dumpFile($path, $yaml);
    }

    /**
     * Moves the `environment:` entry so it appears directly after `image:` or
     * `build:`. If the service has neither, the environment entry is appended
     * at the end. Returns the service as-is when there is no `environment:`.
     *
     * @param array<string, mixed> $service
     *
     * @return array<string, mixed>
     */
    public function reorderServiceKeys(array $service): array
    {
        if (! array_key_exists('environment', $service)) {
            return $service;
        }

        $environment = $service['environment'];
        unset($service['environment']);

        $reordered = [];
        $inserted = false;

        foreach ($service as $key => $value) {
            $reordered[$key] = $value;

            if (! $inserted && ($key === 'image' || $key === 'build')) {
                $reordered['environment'] = $environment;
                $inserted = true;
            }
        }

        if (! $inserted) {
            $reordered['environment'] = $environment;
        }

        return $reordered;
    }

    /**
     * @param array<string, mixed> $service
     */
    public function extractEnvironmentVariable(array $service, string $varName): ?string
    {
        if (! isset($service['environment']) || ! is_array($service['environment'])) {
            return null;
        }

        foreach ($service['environment'] as $key => $value) {
            if (is_string($key) && $key === $varName && is_string($value)) {
                return $value;
            }

            if (is_string($value) && str_starts_with($value, $varName.'=')) {
                return substr($value, strlen($varName.'='));
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $service
     */
    public function extractVirtualHost(array $service): ?string
    {
        return $this->extractEnvironmentVariable($service, 'VIRTUAL_HOST');
    }

    /**
     * Sets an environment variable on a service. Supports both list format
     * (["KEY=value"]) and map format ({KEY: value}). Skips if the variable
     * already exists in the service environment or in the project's .env / .env.dev files.
     *
     * @param array<string, mixed> $service
     * @param string|null          $projectDir Project directory to check .env files in
     *
     * @return bool true if the variable was added, false if it already existed
     */
    public function setEnvironmentVariable(array &$service, string $varName, string $value, ?string $projectDir = null): bool
    {
        // Check if already set in service environment
        if ($this->extractEnvironmentVariable($service, $varName) !== null) {
            return false;
        }

        // Check if defined in .env or .env.dev files
        if ($projectDir !== null && $this->isVariableDefinedInEnvFiles($projectDir, $varName)) {
            return false;
        }

        if (! isset($service['environment']) || ! is_array($service['environment'])) {
            $service['environment'] = [];
        }

        $isList = $service['environment'] === [] || array_is_list($service['environment']);

        if ($isList) {
            $service['environment'][] = sprintf('%s=%s', $varName, $value);
        } else {
            $service['environment'][$varName] = $value;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $service
     */
    public function removeEnvironmentVariable(array &$service, string $varName): void
    {
        if (! isset($service['environment']) || ! is_array($service['environment'])) {
            return;
        }

        $isList = array_is_list($service['environment']);
        $filtered = [];

        foreach ($service['environment'] as $key => $value) {
            if (is_string($key) && $key === $varName) {
                continue;
            }

            if (is_string($value) && str_starts_with($value, $varName.'=')) {
                continue;
            }

            if ($isList) {
                $filtered[] = $value;
            } else {
                $filtered[$key] = $value;
            }
        }

        if ($filtered === []) {
            unset($service['environment']);
        } else {
            $service['environment'] = $filtered;
        }
    }

    /**
     * Removes the container_name property from a service.
     * Fixed container names prevent running multiple instances (e.g. worktrees).
     *
     * @param array<string, mixed> $config
     */
    public function removeContainerName(array &$config, string $serviceName): bool
    {
        if (! isset($config['services'][$serviceName]['container_name'])) {
            return false;
        }

        unset($config['services'][$serviceName]['container_name']);

        return true;
    }

    /**
     * Removes the "default: name: dde" network boilerplate from the compose config.
     * This was added by dde v1 but is now injected by the dde overlay.
     * Returns true if something was removed.
     *
     * @param array<string, mixed> $config
     */
    public function removeDdeNetworkBoilerplate(array &$config): bool
    {
        if (
            isset($config['networks']['default']['name'])
            && $config['networks']['default']['name'] === 'dde'
        ) {
            unset($config['networks']['default']);

            if ($config['networks'] === []) {
                unset($config['networks']);
            }

            return true;
        }

        return false;
    }

    /**
     * Removes the dde_ssh-agent_socket-dir volume mount from a service,
     * the SSH_AUTH_SOCK environment variable, and the top-level volume definition.
     * This was added by dde v1 but is now injected by the dde overlay.
     * Returns true if something was removed.
     *
     * @param array<string, mixed> $config
     */
    public function removeSshAgentBoilerplate(array &$config, string $serviceName): bool
    {
        if (! isset($config['services'][$serviceName]) || ! is_array($config['services'][$serviceName])) {
            return false;
        }

        $service = &$config['services'][$serviceName];
        $changed = false;

        // Remove volume mount containing 'ssh-agent'
        if (isset($service['volumes']) && is_array($service['volumes'])) {
            $filtered = array_values(array_filter(
                $service['volumes'],
                static fn (mixed $v): bool => ! (is_string($v) && str_contains($v, 'ssh-agent')),
            ));

            if (count($filtered) !== count($service['volumes'])) {
                if ($filtered === []) {
                    unset($service['volumes']);
                } else {
                    $service['volumes'] = $filtered;
                }

                $changed = true;
            }
        }

        // Remove SSH_AUTH_SOCK environment variable
        if ($this->extractEnvironmentVariable($service, 'SSH_AUTH_SOCK') !== null) {
            $this->removeEnvironmentVariable($service, 'SSH_AUTH_SOCK');
            $changed = true;
        }

        // Remove top-level ssh-agent volume definitions (both dde_ prefixed and legacy v1 names)
        foreach (array_keys($config['volumes'] ?? []) as $volumeName) {
            if (is_string($volumeName) && str_contains($volumeName, 'ssh-agent')) {
                unset($config['volumes'][$volumeName]);
                $changed = true;
            }
        }

        if (isset($config['volumes']) && $config['volumes'] === []) {
            unset($config['volumes']);
        }

        return $changed;
    }

    /**
     * Returns true if the service has an SSH-Agent volume mount that is not yet
     * in the canonical dde_ssh-agent_socket-dir format.
     *
     * Kept as a public utility for external callers that need to probe for old-format volumes.
     * In the main adaptCompose() flow, removeSshAgentBoilerplate() is used directly instead.
     *
     * @param array<string, mixed> $config
     */
    public function serviceHasOldSshAgentVolume(array $config, string $serviceName): bool
    {
        $volumes = $config['services'][$serviceName]['volumes'] ?? [];

        if (! is_array($volumes)) {
            return false;
        }

        foreach ($volumes as $volume) {
            if (
                is_string($volume)
                && str_contains($volume, 'ssh-agent')
                && str_contains($volume, '/tmp/ssh-agent')
                && ! str_starts_with($volume, 'dde_ssh-agent_socket-dir')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks whether a variable is defined (uncommented) in .env or .env.dev
     * files in the given project directory.
     */
    private function isVariableDefinedInEnvFiles(string $projectDir, string $varName): bool
    {
        foreach (['.env', '.env.dev'] as $filename) {
            $path = $projectDir.'/'.$filename;

            if (! $this->filesystem->exists($path)) {
                continue;
            }

            try {
                $content = $this->filesystem->readFile($path);
            } catch (\Throwable) {
                continue;
            }

            foreach (explode("\n", $content) as $line) {
                $trimmed = ltrim($line);

                // Skip empty lines and comments
                if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                    continue;
                }

                // Match VAR_NAME= at the start of the line (with optional export prefix)
                if (preg_match('/^(?:export\s+)?'.preg_quote($varName, '/').'=/', $trimmed)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Post-processes YAML output to wrap all unquoted label list items in
     * single quotes. Matches lines under a "labels:" key that are list items
     * (starting with "- ") and not already quoted.
     */
    private function quoteLabels(string $yaml): string
    {
        $lines = explode("\n", $yaml);
        $inLabels = false;
        $labelIndent = 0;

        foreach ($lines as $i => $line) {
            // Detect "labels:" key
            if (preg_match('/^(\s*)labels:\s*$/', $line, $m)) {
                $inLabels = true;
                $labelIndent = strlen($m[1]);

                continue;
            }

            if ($inLabels) {
                // Check if we're still inside the labels block
                if ($line === '' || preg_match('/^(\s+)/', $line, $m)) {
                    $currentIndent = $line === '' ? $labelIndent + 1 : strlen($m[1]);

                    if ($currentIndent <= $labelIndent && $line !== '') {
                        $inLabels = false;

                        continue;
                    }

                    // Match unquoted list items (not already wrapped in quotes)
                    if (preg_match('/^(\s+- )([^\'"].*)$/', $line, $m)) {
                        $lines[$i] = $m[1]."'".$m[2]."'";
                    }
                } else {
                    $inLabels = false;
                }
            }
        }

        return implode("\n", $lines);
    }
}
