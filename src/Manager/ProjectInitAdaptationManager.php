<?php

declare(strict_types=1);

namespace App\Manager;

use App\Parser\DockerComposeParser;
use App\Parser\DockerfileParser;
use App\Util\DiffUtil;
use App\Util\DockerComposeModifier;
use Symfony\Component\Filesystem\Filesystem;

readonly class ProjectInitAdaptationManager
{
    public function __construct(
        private DockerComposeManager $dockerComposeManager,
        private DockerComposeParser $dockerComposeParser,
        private DockerComposeModifier $dockerComposeModifier,
        private DockerfileParser $dockerfileParser,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function detectFirstService(?string $composePath): ?string
    {
        if ($composePath === null) {
            return null;
        }

        try {
            $config = $this->dockerComposeParser->parse($composePath);
        } catch (\RuntimeException) {
            return null;
        }

        if (! isset($config['services']) || ! is_array($config['services'])) {
            return null;
        }

        $serviceNames = array_keys($config['services']);
        $first = reset($serviceNames);

        return is_string($first) ? $first : null;
    }

    /**
     * Finds the compose file and delegates to adaptCompose and adaptDockerfile.
     *
     * @return array{compose: ?array{changes: list<string>, diff: string, config: array<string, mixed>, composePath: string}, dockerfile: ?array{changes: list<string>, diff: string, lines: list<string>, dockerfilePath: string}}
     */
    public function adaptDocker(string $projectDir, string $name, string $container): array
    {
        $result = [
            'compose' => null,
            'dockerfile' => null,
        ];

        $composePath = $this->dockerComposeManager->findComposeFileOrNull($projectDir);

        if ($composePath !== null) {
            $result['compose'] = $this->adaptCompose($composePath, $name, $container);
            // Find Dockerfiles with build targets from compose services
            try {
                $config = $this->dockerComposeParser->parse($composePath);
            } catch (\RuntimeException) {
                return $result;
            }

            // Collect all (dockerfile → targets) from compose build configs
            /** @var array<string, list<string>> $dockerfileTargets */
            $dockerfileTargets = [];
            if (isset($config['services']) && is_array($config['services'])) {
                foreach ($config['services'] as $svc) {
                    if (!is_array($svc)) {
                        continue;
                    }

                    $build = $svc['build'] ?? null;

                    if (!is_array($build)) {
                        continue;
                    }

                    $target = $build['target'] ?? null;

                    if (!is_string($target) || $target === '') {
                        continue;
                    }

                    $context = is_string($build['context'] ?? null) ? $build['context'] : '.';
                    $dockerfile = is_string($build['dockerfile'] ?? null) ? $build['dockerfile'] : 'Dockerfile';
                    $dockerfilePath = $projectDir.'/'.ltrim($context.'/'.$dockerfile, '/');

                    if ($this->filesystem->exists($dockerfilePath)) {
                        $dockerfileTargets[$dockerfilePath][] = $target;
                    }
                }
            }

            if ($dockerfileTargets !== []) {
                // Adapt each Dockerfile with all its targets
                foreach ($dockerfileTargets as $dockerfilePath => $targets) {
                    $result['dockerfile'] = $this->adaptDockerfileMultiStage($dockerfilePath, $targets);
                }
            } elseif ($this->filesystem->exists($projectDir.'/Dockerfile')) {
                // Fallback: adapt root Dockerfile without target constraint
                $result['dockerfile'] = $this->adaptDockerfile($projectDir.'/Dockerfile');
            }
        } elseif ($this->filesystem->exists($projectDir.'/Dockerfile')) {
            // No compose file — try root Dockerfile
            $result['dockerfile'] = $this->adaptDockerfile($projectDir.'/Dockerfile');
        }

        return $result;
    }

    /**
     * Adapts MAILER_DSN when mailpit is a configured dde service.
     * Checks docker-compose environment first, then falls back to .env files.
     *
     * @param list<string> $ddeServiceNames Service names from .dde/config.yml
     *
     * @return string|null Human-readable change description, or null if no change
     */
    public function adaptMailerDsn(string $projectDir, string $container, array $ddeServiceNames): ?string
    {
        if (!in_array('mailpit', $ddeServiceNames, true)) {
            return null;
        }

        $mailerDsn = 'smtp://mailpit:1025';
        $composePath = $this->dockerComposeManager->findComposeFileOrNull($projectDir);

        // 1. Check docker-compose environment
        if ($composePath !== null) {
            $config = $this->dockerComposeParser->parse($composePath);
            $service = $config['services'][$container] ?? null;

            if (is_array($service)) {
                $existing = $this->dockerComposeModifier->extractEnvironmentVariable($service, 'MAILER_DSN');

                if ($existing !== null) {
                    if ($existing === $mailerDsn) {
                        return null;
                    }

                    $this->dockerComposeModifier->removeEnvironmentVariable($service, 'MAILER_DSN');
                    $this->dockerComposeModifier->setEnvironmentVariable($service, 'MAILER_DSN', $mailerDsn);
                    $config['services'][$container] = $service;
                    $this->dockerComposeModifier->write($composePath, $config);

                    return sprintf('Updated MAILER_DSN in %s (service "%s")', basename($composePath), $container);
                }
            }
        }

        // 2. Fall back to .env files
        foreach (['.env', '.env.local', '.env.dev'] as $envFile) {
            $envPath = $projectDir.'/'.$envFile;

            if (!$this->filesystem->exists($envPath)) {
                continue;
            }

            $content = $this->filesystem->readFile($envPath);
            $lines = explode("\n", $content);
            $changed = false;

            foreach ($lines as &$line) {
                $trimmed = ltrim($line);

                if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                    continue;
                }

                if (preg_match('/^(?:export\s+)?MAILER_DSN=(.*)$/', $trimmed, $matches)) {
                    if ($matches[1] !== $mailerDsn) {
                        $line = 'MAILER_DSN='.$mailerDsn;
                        $changed = true;
                    }

                    break;
                }
            }

            unset($line);

            if ($changed) {
                $this->filesystem->dumpFile($envPath, implode("\n", $lines));

                return sprintf('Updated MAILER_DSN in %s', $envFile);
            }
        }

        return null;
    }

    /**
     * Analyzes a docker-compose file and returns proposed changes without writing.
     *
     * @return array{changes: list<string>, diff: string, config: array<string, mixed>, composePath: string}|null null if parsing fails
     */
    public function adaptCompose(string $composePath, string $name, string $container): ?array
    {
        try {
            $config = $this->dockerComposeParser->parse($composePath);
        } catch (\RuntimeException) {
            return null;
        }

        $original = $config;
        $changes = [];

        if ($this->dockerComposeModifier->addNetwork($config, 'dde')) {
            $changes[] = 'Added external network "dde"';
        }

        if (isset($config['services']) && is_array($config['services'])) {
            foreach (array_keys($config['services']) as $serviceName) {
                if (is_string($serviceName) && $this->dockerComposeModifier->addTraefikLabels($config, $serviceName, $name, $serviceName === $container)) {
                    $changes[] = sprintf('Added Traefik labels to service "%s"', $serviceName);
                }
            }
        }

        if (isset($config['services'][$container]) && $this->dockerComposeModifier->addSshAgentVolume($config, $container)) {
            $changes[] = sprintf('Added SSH-Agent volume to service "%s"', $container);
        }

        if (isset($config['services']) && is_array($config['services'])) {
            foreach (array_keys($config['services']) as $svcName) {
                if (! is_string($svcName) || $svcName === $container) {
                    continue;
                }

                if ($this->dockerComposeModifier->serviceHasOldSshAgentVolume($config, $svcName) && $this->dockerComposeModifier->addSshAgentVolume($config, $svcName)) {
                    $changes[] = sprintf('Migrated SSH-Agent volume in service "%s"', $svcName);
                }
            }
        }

        if (isset($config['services']) && is_array($config['services'])) {
            foreach (array_keys($config['services']) as $svcName) {
                if (is_string($svcName) && $this->dockerComposeModifier->removeV1BuildArgs($config, $svcName)) {
                    $changes[] = sprintf('Removed v1 build args (DDE_UID, DDE_GID) from service "%s"', $svcName);
                }
            }
        }

        // Remove container_name — fixed names prevent worktree support
        if (isset($config['services']) && is_array($config['services'])) {
            foreach (array_keys($config['services']) as $svcName) {
                if (is_string($svcName) && $this->dockerComposeModifier->removeContainerName($config, $svcName)) {
                    $changes[] = sprintf('Removed container_name from service "%s"', $svcName);
                }
            }
        }

        // Remove legacy DDE_CONTAINER_SHELL environment variable
        if (isset($config['services']) && is_array($config['services'])) {
            foreach (array_keys($config['services']) as $svcName) {
                if (!is_string($svcName)) {
                    continue;
                }

                $svc = $config['services'][$svcName];

                if (is_array($svc) && $this->dockerComposeModifier->extractEnvironmentVariable($svc, 'DDE_CONTAINER_SHELL') !== null) {
                    $this->dockerComposeModifier->removeEnvironmentVariable($svc, 'DDE_CONTAINER_SHELL');
                    $config['services'][$svcName] = $svc;
                    $changes[] = sprintf('Removed legacy DDE_CONTAINER_SHELL from service "%s"', $svcName);
                }
            }
        }

        if (isset($config['services'][$container])) {
            $envChanges = $this->dockerComposeModifier->addServiceEnvironment($config, $container, $name, \dirname($composePath));
            $changes = array_merge($changes, $envChanges);
        }

        if ($changes === []) {
            return [
                'changes' => [],
                'diff' => '',
                'config' => $config,
                'composePath' => $composePath,
            ];
        }

        $diff = $this->dockerComposeParser->generateDiff($original, $config);

        return [
            'changes' => $changes,
            'diff' => $diff,
            'config' => $config,
            'composePath' => $composePath,
        ];
    }

    /**
     * Analyzes a Dockerfile across multiple stages and returns proposed changes.
     *
     * @param list<string> $stages
     *
     * @return array{changes: list<string>, diff: string, lines: list<string>, dockerfilePath: string}|null
     */
    public function adaptDockerfileMultiStage(string $dockerfilePath, array $stages): ?array
    {
        try {
            $lines = $this->dockerfileParser->parse($dockerfilePath);
        } catch (\RuntimeException) {
            return null;
        }

        // Find boilerplate in specified stages + any other stage via generic scan
        $allBoilerplate = $this->dockerfileParser->findV1Boilerplate($lines);

        foreach ($stages as $stage) {
            $found = $this->dockerfileParser->findV1BoilerplateInStage($lines, $stage);
            $allBoilerplate = [...$allBoilerplate, ...$found];
        }

        $allBoilerplate = array_values(array_unique($allBoilerplate));
        sort($allBoilerplate);

        if ($allBoilerplate === []) {
            return [
                'changes' => [],
                'diff' => '',
                'lines' => $lines,
                'dockerfilePath' => $dockerfilePath,
            ];
        }

        $cleaned = $this->dockerfileParser->removeLines($lines, $allBoilerplate);
        $changes = [sprintf('Remove %d v1 boilerplate lines from %d stage(s)', count($allBoilerplate), count($stages))];
        $diff = $this->generateDockerfileDiff($lines, $cleaned);

        return [
            'changes' => $changes,
            'diff' => $diff,
            'lines' => $cleaned,
            'dockerfilePath' => $dockerfilePath,
        ];
    }

    /**
     * Analyzes a Dockerfile and returns proposed changes without writing.
     *
     * @return array{changes: list<string>, diff: string, lines: list<string>, dockerfilePath: string}|null null if parsing fails
     */
    public function adaptDockerfile(string $dockerfilePath, ?string $targetStage = null): ?array
    {
        try {
            $lines = $this->dockerfileParser->parse($dockerfilePath);
        } catch (\RuntimeException) {
            return null;
        }

        $boilerplate = $targetStage !== null
            ? $this->dockerfileParser->findV1BoilerplateInStage($lines, $targetStage)
            : $this->dockerfileParser->findV1Boilerplate($lines);

        if ($boilerplate === []) {
            return [
                'changes' => [],
                'diff' => '',
                'lines' => $lines,
                'dockerfilePath' => $dockerfilePath,
            ];
        }

        $cleaned = $this->dockerfileParser->removeLines($lines, $boilerplate);
        $changes = [sprintf('Remove %d v1 boilerplate lines', count($boilerplate))];
        $diff = $this->generateDockerfileDiff($lines, $cleaned);

        return [
            'changes' => $changes,
            'diff' => $diff,
            'lines' => $cleaned,
            'dockerfilePath' => $dockerfilePath,
        ];
    }

    /**
     * Writes adapted docker-compose config to disk.
     *
     * @param array<string, mixed> $config
     */
    public function writeCompose(string $composePath, array $config): void
    {
        $this->dockerComposeModifier->write($composePath, $config);
    }

    /**
     * Writes adapted Dockerfile lines to disk.
     *
     * @param list<string> $lines
     */
    public function writeDockerfile(string $dockerfilePath, array $lines): void
    {
        $this->dockerfileParser->write($dockerfilePath, $lines);
    }

    /**
     * @param list<string> $original
     * @param list<string> $cleaned
     */
    private function generateDockerfileDiff(array $original, array $cleaned): string
    {
        if ($original === $cleaned) {
            return '';
        }

        return DiffUtil::generateTextDiff($original, $cleaned);
    }
}
