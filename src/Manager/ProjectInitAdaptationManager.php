<?php

declare(strict_types=1);

namespace App\Manager;

use App\Parser\DockerComposeParser;
use App\Parser\DockerfileParser;
use App\Service\ServiceRegistry;
use App\Util\DiffUtil;
use App\Util\DockerComposeModifier;
use App\Util\IdentifierSanitizer;
use Symfony\Component\Filesystem\Filesystem;

readonly class ProjectInitAdaptationManager
{
    public function __construct(
        private DockerComposeManager $dockerComposeManager,
        private DockerComposeParser $dockerComposeParser,
        private DockerComposeModifier $dockerComposeModifier,
        private DockerfileParser $dockerfileParser,
        private ServiceRegistry $serviceRegistry,
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
     * Applies forced env transformations (APP_ENV, MAILER_DSN) immediately and
     * returns prompted proposals (DATABASE_URL) for user confirmation.
     *
     * @param list<string>                       $ddeServiceNames
     * @param list<\App\Model\ServiceDefinition> $services
     *
     * @return array{appliedChanges: list<string>, proposals: list<EnvMigrationProposal>}
     */
    public function proposeEnvMigrations(
        string $projectDir,
        string $projectName,
        string $container,
        array $ddeServiceNames,
        array $services,
    ): array {
        $composePath = $this->dockerComposeManager->findComposeFileOrNull($projectDir);
        $emptyResult = [
            'appliedChanges' => [],
            'proposals' => [],
        ];

        if ($composePath === null) {
            return $emptyResult;
        }

        try {
            $config = $this->dockerComposeParser->parse($composePath);
        } catch (\RuntimeException) {
            return $emptyResult;
        }

        if (! isset($config['services'][$container]) || ! is_array($config['services'][$container])) {
            return $emptyResult;
        }

        $appliedChanges = [];

        // Forced: APP_ENV
        $appEnvChange = $this->applyAppEnvRule($config, $container);
        if ($appEnvChange !== null) {
            $appliedChanges[] = $appEnvChange;
        }

        // Forced: MAILER_DSN
        $mailerChange = $this->applyMailerDsnRuleToConfig($config, $container, $projectDir, $ddeServiceNames);
        if ($mailerChange !== null) {
            $appliedChanges[] = $mailerChange;
        }

        if ($appliedChanges !== []) {
            $this->dockerComposeModifier->write($composePath, $config);
        }

        // Prompted: DATABASE_URL
        $proposal = $this->proposeDatabaseUrlRule($config, $container, $projectDir, $projectName, $services);
        $proposals = $proposal instanceof EnvMigrationProposal ? [$proposal] : [];

        return [
            'appliedChanges' => $appliedChanges,
            'proposals' => $proposals,
        ];
    }

    /**
     * @param list<EnvMigrationProposal> $accepted
     */
    public function applyEnvMigrations(string $projectDir, string $container, array $accepted): void
    {
        if ($accepted === []) {
            return;
        }

        $composePath = $this->dockerComposeManager->findComposeFileOrNull($projectDir);

        if ($composePath === null) {
            return;
        }

        try {
            $config = $this->dockerComposeParser->parse($composePath);
        } catch (\RuntimeException) {
            return;
        }

        if (! isset($config['services'][$container]) || ! is_array($config['services'][$container])) {
            return;
        }

        foreach ($accepted as $proposal) {
            // Write .env value
            $this->writeEnvVariable($projectDir, $proposal->envFile, $proposal->variable, $proposal->envTargetValue);

            // Write compose value
            $service = $config['services'][$container];
            $this->dockerComposeModifier->removeEnvironmentVariable($service, $proposal->variable);
            $this->dockerComposeModifier->setEnvironmentVariable($service, $proposal->variable, $proposal->composeValue);
            $config['services'][$container] = $service;
        }

        $this->dockerComposeModifier->write($composePath, $config);
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

        // Remove dde network boilerplate (now injected by the dde overlay)
        if ($this->dockerComposeModifier->removeDdeNetworkBoilerplate($config)) {
            $changes[] = 'Removed external "dde" network (now injected by dde overlay)';
        }

        if (isset($config['services']) && is_array($config['services'])) {
            foreach (array_keys($config['services']) as $serviceName) {
                if (is_string($serviceName) && $this->dockerComposeModifier->addTraefikLabels($config, $serviceName, $name, $serviceName === $container)) {
                    $changes[] = sprintf('Added Traefik labels to service "%s"', $serviceName);
                }
            }
        }

        // Remove SSH-Agent boilerplate from primary container (now injected by dde overlay)
        if (isset($config['services'][$container]) && $this->dockerComposeModifier->removeSshAgentBoilerplate($config, $container)) {
            $changes[] = sprintf('Removed SSH-Agent volume from service "%s" (now injected by dde overlay)', $container);
        }

        // Remove SSH-Agent boilerplate from all other services (now injected by dde overlay)
        if (isset($config['services']) && is_array($config['services'])) {
            foreach (array_keys($config['services']) as $svcName) {
                if (! is_string($svcName) || $svcName === $container) {
                    continue;
                }

                if ($this->dockerComposeModifier->removeSshAgentBoilerplate($config, $svcName)) {
                    $changes[] = sprintf('Removed SSH-Agent volume from service "%s" (now injected by dde overlay)', $svcName);
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

        // Remove OPEN_URL environment variable from all services (dde v1 remnant, no longer used)
        if (isset($config['services']) && is_array($config['services'])) {
            foreach (array_keys($config['services']) as $svcName) {
                if (! is_string($svcName)) {
                    continue;
                }

                $svc = $config['services'][$svcName] ?? [];

                if (is_array($svc) && $this->dockerComposeModifier->extractEnvironmentVariable($svc, 'OPEN_URL') !== null) {
                    $this->dockerComposeModifier->removeEnvironmentVariable($svc, 'OPEN_URL');
                    $config['services'][$svcName] = $svc;
                    $changes[] = sprintf('Removed legacy OPEN_URL from service "%s"', $svcName);
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
     * @param array<string, mixed> $config
     */
    private function applyAppEnvRule(array &$config, string $container): ?string
    {
        if (! isset($config['services'][$container]) || ! is_array($config['services'][$container])) {
            return null;
        }

        $service = $config['services'][$container];

        if ($this->dockerComposeModifier->extractEnvironmentVariable($service, 'APP_ENV') === 'dev') {
            return null;
        }

        $this->dockerComposeModifier->removeEnvironmentVariable($service, 'APP_ENV');
        $this->dockerComposeModifier->setEnvironmentVariable($service, 'APP_ENV', 'dev');
        $config['services'][$container] = $service;

        return 'Applied APP_ENV=dev to compose';
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string>         $ddeServiceNames
     */
    private function applyMailerDsnRuleToConfig(array &$config, string $container, string $projectDir, array $ddeServiceNames): ?string
    {
        if (! in_array('mailpit', $ddeServiceNames, true)) {
            return null;
        }

        if (! isset($config['services'][$container]) || ! is_array($config['services'][$container])) {
            return null;
        }

        $mailerDsn = 'smtp://mailpit:1025';
        $envDsn = 'null://null';
        $changed = false;

        // compose
        $service = $config['services'][$container];

        if ($this->dockerComposeModifier->extractEnvironmentVariable($service, 'MAILER_DSN') !== $mailerDsn) {
            $this->dockerComposeModifier->removeEnvironmentVariable($service, 'MAILER_DSN');
            $this->dockerComposeModifier->setEnvironmentVariable($service, 'MAILER_DSN', $mailerDsn);
            $config['services'][$container] = $service;
            $changed = true;
        }

        // .env walk
        foreach (['.env', '.env.local', '.env.dev'] as $envFile) {
            $path = $projectDir.'/'.$envFile;

            if (! $this->filesystem->exists($path)) {
                continue;
            }

            $current = $this->readEnvVariable($projectDir, $envFile, 'MAILER_DSN');

            if ($current === null) {
                continue;
            }

            if ($current !== $envDsn) {
                $this->writeEnvVariable($projectDir, $envFile, 'MAILER_DSN', $envDsn);
            }

            break;
        }

        return $changed ? sprintf('Applied MAILER_DSN=%s to compose and %s to .env', $mailerDsn, $envDsn) : null;
    }

    /**
     * @param array<string, mixed>               $config
     * @param list<\App\Model\ServiceDefinition> $services
     */
    private function proposeDatabaseUrlRule(array $config, string $container, string $projectDir, string $projectName, array $services): ?EnvMigrationProposal
    {
        if (! isset($config['services'][$container]) || ! is_array($config['services'][$container])) {
            return null;
        }

        // Check compose already has DATABASE_URL
        if ($this->dockerComposeModifier->extractEnvironmentVariable($config['services'][$container], 'DATABASE_URL') !== null) {
            return null;
        }

        // Read .env
        $envFile = null;
        $originalValue = '';

        foreach (['.env', '.env.local', '.env.dev'] as $candidate) {
            $val = $this->readEnvVariable($projectDir, $candidate, 'DATABASE_URL');

            if ($val !== null) {
                $envFile = $candidate;
                $originalValue = $val;
                break;
            }
        }

        if ($envFile === null) {
            return null;
        }

        $parsed = $this->parseDatabaseUrl($originalValue);

        if ($parsed === null) {
            return null;
        }

        // Scheme → service type
        $serviceType = match (strtolower($parsed['scheme'])) {
            'mysql', 'mariadb' => 'mariadb',
            'postgres', 'postgresql', 'pgsql' => 'postgres',
            default => null,
        };

        if ($serviceType === null) {
            return null;
        }

        $matchingService = null;

        foreach ($services as $s) {
            if ($s->name === $serviceType) {
                $matchingService = $s;
                break;
            }
        }

        if ($matchingService === null) {
            return null;
        }

        $creds = $this->serviceRegistry->getServiceCredentials($serviceType);

        if ($creds === null) {
            return null;
        }

        $sanitizedDb = IdentifierSanitizer::forDatabase($projectName);
        $originalDb = $parsed['dbname'] !== '' ? $parsed['dbname'] : $sanitizedDb;

        // Build .env target
        $port = $parsed['port'] !== '' ? ':'.$parsed['port'] : '';
        $query = $parsed['query'] !== '' ? '?'.$parsed['query'] : '';
        $envTarget = sprintf('%s://app:changeme@127.0.0.1%s/%s%s', $parsed['scheme'], $port, $originalDb, $query);

        // Build compose value
        $serverVersion = $this->formatServerVersion($serviceType, $matchingService->version);
        $filteredQuery = $this->filterQueryParam($parsed['query'], 'serverVersion');
        $queryPart = '?serverVersion='.$serverVersion.($filteredQuery !== '' ? '&'.$filteredQuery : '');
        $composeValue = sprintf(
            '%s://%s:%s@%s/%s%s',
            $parsed['scheme'],
            $creds['user'],
            $creds['password'],
            $serviceType,
            $sanitizedDb,
            $queryPart,
        );

        return new EnvMigrationProposal(
            variable: 'DATABASE_URL',
            envFile: $envFile,
            originalValue: $originalValue,
            envTargetValue: $envTarget,
            composeValue: $composeValue,
            description: sprintf('Migrate DATABASE_URL for %s', $serviceType),
        );
    }

    /**
     * @return array{scheme: string, user: string, pass: string, host: string, port: string, dbname: string, query: string}|null
     */
    private function parseDatabaseUrl(string $url): ?array
    {
        $pattern = '#^(?<scheme>[a-z][a-z0-9+.\-]*)://(?:(?<user>[^:@/]*)(?::(?<pass>[^@/]*))?@)?(?<host>[^:/?\\#]+)(?::(?<port>\d+))?(?:/(?<dbname>[^?\\#]*))?(?:\?(?<query>[^\\#]*))?#i';

        if (preg_match($pattern, $url, $m) !== 1) {
            return null;
        }

        return [
            'scheme' => $m['scheme'],
            'user' => $m['user'],
            'pass' => $m['pass'],
            'host' => $m['host'],
            'port' => $m['port'] ?? '',
            'dbname' => $m['dbname'] ?? '',
            'query' => $m['query'] ?? '',
        ];
    }

    private function formatServerVersion(string $serviceType, string $version): string
    {
        if ($serviceType === 'mariadb') {
            $suffix = substr_count($version, '.') >= 2 ? '-MariaDB' : '.0-MariaDB';

            return $version.$suffix;
        }

        return $version;
    }

    private function filterQueryParam(string $query, string $removeKey): string
    {
        if ($query === '') {
            return '';
        }

        $parts = explode('&', $query);
        $filtered = [];

        foreach ($parts as $p) {
            if ($p === '') {
                continue;
            }

            $eq = strpos($p, '=');
            $k = $eq === false ? $p : substr($p, 0, $eq);

            if ($k !== $removeKey) {
                $filtered[] = $p;
            }
        }

        return implode('&', $filtered);
    }

    private function readEnvVariable(string $projectDir, string $envFile, string $varName): ?string
    {
        $path = $projectDir.'/'.$envFile;

        if (! $this->filesystem->exists($path)) {
            return null;
        }

        $content = $this->filesystem->readFile($path);

        foreach (explode("\n", $content) as $line) {
            $trimmed = ltrim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (preg_match('/^(?:export\s+)?'.preg_quote($varName, '/').'=(.*)$/', $trimmed, $m)) {
                return $m[1];
            }
        }

        return null;
    }

    private function writeEnvVariable(string $projectDir, string $envFile, string $varName, string $value): void
    {
        $path = $projectDir.'/'.$envFile;

        if (! $this->filesystem->exists($path)) {
            return;
        }

        $content = $this->filesystem->readFile($path);
        $lines = explode("\n", $content);
        $written = false;

        foreach ($lines as &$line) {
            $trimmed = ltrim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (preg_match('/^(?:export\s+)?'.preg_quote($varName, '/').'=/', $trimmed)) {
                $leadingWhitespace = substr($line, 0, strlen($line) - strlen($trimmed));
                $exportPrefix = str_starts_with($trimmed, 'export ') ? 'export ' : '';
                $hasCr = str_ends_with($line, "\r");
                $line = $leadingWhitespace.$exportPrefix.$varName.'='.$value.($hasCr ? "\r" : '');
                $written = true;
                break;
            }
        }

        unset($line);

        if ($written) {
            $this->filesystem->dumpFile($path, implode("\n", $lines));
        }
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
