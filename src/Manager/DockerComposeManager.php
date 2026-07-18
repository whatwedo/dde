<?php

declare(strict_types=1);

namespace App\Manager;

use App\Adapter\AdapterRegistry;
use App\Config\ResolvedConfig;
use App\Config\WorktreeInfo;
use App\Model\ServiceDefinition;
use App\Model\UserContext;
use App\Util\ComposeEnvEntryParser;
use App\Util\NdJsonParser;
use App\Util\ProcessFactory;
use App\Util\TempFileUtil;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Dotenv\Exception\FormatException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

readonly class DockerComposeManager
{
    public function __construct(
        private AdapterRegistry $adapterRegistry,
        private DockerManager $dockerManager,
        private UserContext $userContext,
        private WorktreeManager $worktreeManager,
        private MkcertManager $mkcertManager,
        private Filesystem $filesystem = new Filesystem(),
        private ProcessFactory $processFactory = new ProcessFactory(),
    ) {
    }

    /**
     * @param array{composeFiles?: list<string>, build?: bool} $options
     *
     * @throws \RuntimeException
     */
    public function up(string $projectDir, array $options = [], ?OutputInterface $output = null): void
    {
        $cmd = $this->buildComposeCommand($options['composeFiles'] ?? []);
        $cmd[] = 'up';
        $cmd[] = '-d';

        if ($options['build'] ?? false) {
            $cmd[] = '--build';
        }

        $process = $this->runProcess($cmd, $projectDir, $output);

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(sprintf(
                "docker compose up failed:\n%s%s",
                $process->getErrorOutput(),
                $process->getOutput(),
            ));
        }
    }

    /**
     * @param array{composeFiles?: list<string>, removeOrphans?: bool} $options
     *
     * @throws \RuntimeException
     */
    public function down(string $projectDir, array $options = [], ?OutputInterface $output = null): void
    {
        $cmd = $this->buildComposeCommand($options['composeFiles'] ?? []);
        $cmd[] = 'down';

        if ($options['removeOrphans'] ?? false) {
            $cmd[] = '--remove-orphans';
        }

        $process = $this->runProcess($cmd, $projectDir, $output);

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(sprintf(
                "docker compose down failed:\n%s%s",
                $process->getErrorOutput(),
                $process->getOutput(),
            ));
        }
    }

    public function stop(string $projectDir, ?OutputInterface $output = null): void
    {
        $cmd = $this->buildComposeCommand([]);
        $cmd[] = 'stop';

        $process = $this->runProcess($cmd, $projectDir, $output);

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(sprintf(
                "docker compose stop failed:\n%s%s",
                $process->getErrorOutput(),
                $process->getOutput(),
            ));
        }
    }

    /**
     * @param array<string> $command
     * @param array{composeFiles?: list<string>, user?: string, noTty?: bool, interactive?: bool, env?: array<string, string>} $options
     */
    public function exec(string $projectDir, string $service, array $command, array $options = []): Process
    {
        $cmd = $this->buildComposeCommand($options['composeFiles'] ?? []);
        $cmd[] = 'exec';

        if (isset($options['user']) && $options['user'] !== '') {
            $cmd[] = '-u';
            $cmd[] = $options['user'];
        }

        if ($options['noTty'] ?? false) {
            $cmd[] = '--no-TTY';
        }

        if (isset($options['env'])) {
            foreach ($options['env'] as $key => $value) {
                $cmd[] = '-e';
                $cmd[] = sprintf('%s=%s', $key, $value);
            }
        }

        $cmd[] = $service;

        foreach ($command as $arg) {
            $cmd[] = $arg;
        }

        $process = $this->processFactory->create($cmd, $projectDir, null);

        if ($options['interactive'] ?? false) {
            $process->setTty(Process::isTtySupported());
        }

        return $process;
    }

    /**
     * @param array{composeFiles?: list<string>, follow?: bool, tail?: int|string, noFollow?: bool} $options
     */
    public function logs(string $projectDir, string $service, array $options = []): Process
    {
        $cmd = $this->buildComposeCommand($options['composeFiles'] ?? []);
        $cmd[] = 'logs';

        if ($options['follow'] ?? false) {
            $cmd[] = '--follow';
        }

        if (isset($options['tail'])) {
            $cmd[] = '--tail';
            $cmd[] = (string) $options['tail'];
        }

        if ($service !== '') {
            $cmd[] = $service;
        }

        return $this->processFactory->create($cmd, $projectDir, null);
    }

    /**
     * @param array{composeFiles?: list<string>, services?: bool, all?: bool} $options
     *
     * @return list<array<string, mixed>>
     *
     * @throws \RuntimeException
     */
    public function ps(string $projectDir, array $options = []): array
    {
        $cmd = $this->buildComposeCommand($options['composeFiles'] ?? []);
        $cmd[] = 'ps';
        $cmd[] = '--format';
        $cmd[] = 'json';

        if ($options['services'] ?? false) {
            $cmd[] = '--services';
        }

        if ($options['all'] ?? false) {
            $cmd[] = '-a';
        }

        $process = $this->runProcess($cmd, $projectDir);

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(sprintf(
                "docker compose ps failed:\n%s%s",
                $process->getErrorOutput(),
                $process->getOutput(),
            ));
        }

        return NdJsonParser::parse($process->getOutput(), 'docker compose ps');
    }

    /**
     * Returns the effective service configuration as Compose itself would
     * assemble it. With no user override the base file IS the merged view
     * and a YAML parse is sufficient; once a user override is present, we
     * shell out to `docker compose config` so Compose's own override rules
     * (`!override`, `!reset`, list-form label key-dedupe, env substitution,
     * and image/entrypoint/command resolution) are applied — the same rules
     * Compose will use at runtime when dde hands it the same `-f` chain.
     *
     * @return array<string, array<string, mixed>>
     *
     * @throws \RuntimeException
     */
    public function getMergedServices(string $projectDir, ?string $userOverrideFile = null): array
    {
        if ($userOverrideFile === null) {
            return $this->parseComposeServicesFromBaseFile($projectDir);
        }

        $composeFile = $this->findComposeFile($projectDir);

        $cmd = ['docker', 'compose', '-f', $composeFile, '-f', $userOverrideFile, 'config', '--format', 'json'];

        $process = $this->processFactory->create($cmd, $projectDir, null);
        $process->run();

        // `docker compose config` resolves env vars into the output, including
        // secrets like DB passwords or API keys. Exception messages bubble up
        // into CLI output and CI artifacts, so we never include the body here
        // — only stderr (which Compose uses for parse errors) plus a length
        // hint so callers can tell whether stdout was empty or just hidden.
        $output = $process->getOutput();
        $outputSize = strlen($output);

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(sprintf(
                "docker compose config failed:\nstderr:\n%s\n(stdout omitted, %d bytes)",
                $process->getErrorOutput(),
                $outputSize,
            ));
        }

        try {
            $data = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $jsonException) {
            throw new \RuntimeException(sprintf(
                "docker compose config returned invalid JSON: %s\nstderr:\n%s\n(stdout omitted, %d bytes)",
                $jsonException->getMessage(),
                $process->getErrorOutput(),
                $outputSize,
            ), $jsonException->getCode(), previous: $jsonException);
        }

        if (! is_array($data) || ! is_array($data['services'] ?? null)) {
            throw new \RuntimeException(sprintf(
                "docker compose config returned unexpected structure (no `services` map).\nstderr:\n%s\n(stdout omitted, %d bytes)",
                $process->getErrorOutput(),
                $outputSize,
            ));
        }

        $services = [];

        foreach ($data['services'] as $name => $config) {
            if (is_string($name) && is_array($config)) {
                $services[$name] = $config;
            }
        }

        return $services;
    }

    /**
     * Extracts unique Traefik `Host()` domains from the merged service set.
     * The merged set reflects Compose's actual label resolution including
     * `!override`/`!reset`, so callers don't have to re-implement those rules
     * to predict the effective router list.
     *
     * @param array<string, array<string, mixed>> $services
     * @param list<string>|null                   $onlyServices service names to include (null = all)
     *
     * @return list<string>
     */
    public function extractTraefikDomainsFromServices(array $services, ?array $onlyServices = null): array
    {
        $domains = [];

        foreach ($services as $serviceName => $service) {
            if ($onlyServices !== null && ! in_array($serviceName, $onlyServices, true)) {
                continue;
            }

            $labels = $this->unwrapTaggedLabels($service['labels'] ?? []);

            foreach ($labels as $key => $value) {
                $label = is_int($key) ? (string) $value : $key.'='.$value;

                if (preg_match_all('/Host\(([^)]+)\)/', $label, $hostMatches)) {
                    foreach ($hostMatches[1] as $hostContent) {
                        if (preg_match_all('/`([^`]+)`/', $hostContent, $domainMatches)) {
                            foreach ($domainMatches[1] as $domain) {
                                $domains[] = $domain;
                            }
                        }
                    }
                }
            }
        }

        return array_values(array_unique($domains));
    }

    /**
     * Returns true if any image-based service in the compose file has an image that is not yet present locally.
     * Build-only services (using `build:` without `image:`) are excluded.
     * Services with both `build:` and `image:` are also excluded (built locally, not pulled).
     *
     * @param array<string, array<string, mixed>>|null $services already-resolved service set.
     *        Pass the merged view when a user override is in play so override-only / override-changed
     *        images are checked too; otherwise the silent pre-pull skips them and `docker compose up`
     *        spam-pulls (or fails) for them later.
     */
    public function needsPull(string $projectDir, ?array $services = null): bool
    {
        $services ??= $this->parseComposeServicesFromBaseFile($projectDir);

        foreach ($services as $serviceConfig) {
            if (! is_string($serviceConfig['image'] ?? null)) {
                continue;
            }

            if (isset($serviceConfig['build'])) {
                continue;
            }

            if (! $this->dockerManager->imageExists($serviceConfig['image'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{composeFiles?: list<string>} $options
     *
     * @throws \RuntimeException
     */
    public function pull(string $projectDir, array $options = [], ?OutputInterface $output = null): void
    {
        $cmd = $this->buildComposeCommand($options['composeFiles'] ?? []);

        if ($output instanceof OutputInterface) {
            $cmd[] = '--progress';
            $cmd[] = 'plain';
        }

        $cmd[] = 'pull';

        $process = $this->runProcess($cmd, $projectDir, $output);

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(sprintf(
                "docker compose pull failed:\n%s%s",
                $process->getErrorOutput(),
                $process->getOutput(),
            ));
        }
    }

    /**
     * @param array{composeFiles?: list<string>, pull?: bool, noCache?: bool, tty?: bool} $options
     *
     * @throws \RuntimeException
     */
    public function build(string $projectDir, array $options = [], ?OutputInterface $output = null): void
    {
        $cmd = $this->buildComposeCommand($options['composeFiles'] ?? []);

        if ($output instanceof OutputInterface) {
            $cmd[] = '--progress';
            $cmd[] = 'plain';
        }

        $cmd[] = 'build';

        if ($options['pull'] ?? false) {
            $cmd[] = '--pull';
        }

        if ($options['noCache'] ?? false) {
            $cmd[] = '--no-cache';
        }

        $process = $this->runProcess($cmd, $projectDir);

        if (! $process->isSuccessful()) {
            $output?->write($process->getErrorOutput().$process->getOutput());
            throw new \RuntimeException(sprintf(
                "docker compose build failed:\n%s%s",
                $process->getErrorOutput(),
                $process->getOutput(),
            ));
        }
    }

    /**
     * @param array<string, array<string, mixed>>|null $mergedServices already-resolved
     *        service set (e.g. from a previous {@see self::getMergedServices()} call).
     *        When `null` we compute it ourselves; pass it through whenever the caller
     *        has already paid for `docker compose config` to avoid running it twice
     *        per `project:up` on stacks with user overrides.
     *
     * @throws \RuntimeException
     */
    public function generateOverride(ResolvedConfig $config, string $projectDir, ?WorktreeInfo $worktreeInfo = null, ?string $projectNetwork = null, ?string $userOverrideFile = null, ?array $mergedServices = null): string
    {
        // Source of truth is Compose's own merged view of base + user
        // override: it resolves label `!override`/`!reset`, picks the
        // effective `image`/`entrypoint`/`command`, and exposes services
        // declared only in the override — all things a hand-rolled merge in
        // PHP would have to re-implement (and historically got wrong).
        $composeServices = $mergedServices ?? $this->getMergedServices($projectDir, $userOverrideFile);

        if ($composeServices === []) {
            throw new \RuntimeException(sprintf('No services found in docker-compose.yml in "%s"', $projectDir));
        }

        $overrideServices = [];
        $entrypointPath = $this->adapterRegistry->getEntrypointPath();
        $adaptersDir = $this->adapterRegistry->getBuiltinAdaptersDir();

        // Project containers always join their per-project network — never the
        // shared `dde` network. Parallel checkouts (main + worktree) would
        // otherwise register identical service aliases on `dde` and Docker DNS
        // would round-robin between them. Callers that drove the lifecycle
        // (`ProjectLifecycleManager::up()`) compute the name themselves and
        // pass it in; standalone callers (tests, ad-hoc invocations) get it
        // derived from `$config` + `$worktreeInfo` for the same one-network
        // invariant.
        $projectNetwork ??= ProjectLifecycleManager::buildProjectNetworkName($config->projectName, $worktreeInfo);

        // `!override` so Compose replaces (not merges) the base file's
        // `services.<name>.networks` list. Without it, a hand-edited or v1
        // legacy `networks: [dde]` on a service survives the merge alongside
        // the per-project network — reintroducing the cross-checkout DNS
        // alias collision the per-project isolation is meant to prevent.
        // Project containers always join exactly one network, the per-project
        // one; extra networks have to be wired up post-`up` (hook or
        // `docker network connect`).
        $serviceNetworks = new TaggedValue('override', [
            $projectNetwork => null,
        ]);

        foreach ($composeServices as $serviceName => $serviceConfig) {
            $imageName = $this->resolveServiceImage($serviceName, $serviceConfig, $projectDir);

            $labels = [
                'dde.managed=true',
                // Traefik's docker provider defaults to `network: dde`; pin the
                // per-project network so it can resolve upstream IPs for project
                // containers that never join `dde`.
                'traefik.docker.network='.$projectNetwork,
            ];

            if ($worktreeInfo instanceof WorktreeInfo) {
                $labels = array_merge($labels, $this->overrideTraefikLabels($this->unwrapTaggedLabels($serviceConfig['labels'] ?? []), $config->projectName, $worktreeInfo));
            }

            $containerHostname = $this->resolveContainerHostname($serviceName, $serviceConfig, $config);

            // For worktrees, emit labels with !override so Docker Compose replaces
            // (not merges) the base compose labels. Otherwise the Traefik router
            // names from the base file stay on the worktree container and cause
            // both hostnames to resolve to the same container.
            $labelsValue = $worktreeInfo instanceof WorktreeInfo
                ? new TaggedValue('override', $labels)
                : $labels;

            // Worktree containers — including shell-less ones — must resolve
            // their own worktree-suffixed hostnames. `extra_hosts` is a
            // container-runtime property only (no shell needed inside the
            // image), so compute the override before the shell-less branch
            // returns. Without this, scratch/distroless worktree containers
            // would inherit the main checkout's hostnames and could not reach
            // their own worktree URLs.
            $rewrittenExtraHosts = $worktreeInfo instanceof WorktreeInfo
                ? $this->worktreeManager->rewriteExtraHosts(
                    $this->unwrapTaggedList($serviceConfig['extra_hosts'] ?? null),
                    $config->projectName,
                    $worktreeInfo,
                )
                : null;

            // Skip entrypoint override for shell-less images (scratch, single-binary)
            if (! $this->dockerManager->imageHasShell($imageName)) {
                $shellLessOverride = [
                    'labels' => $labelsValue,
                    'networks' => $serviceNetworks,
                ];

                if ($containerHostname !== null) {
                    $shellLessOverride['hostname'] = $containerHostname;
                }

                if ($rewrittenExtraHosts !== null) {
                    $shellLessOverride['extra_hosts'] = new TaggedValue('override', $rewrittenExtraHosts);
                }

                $overrideServices[$serviceName] = $shellLessOverride;

                continue;
            }

            $environment = [
                'DDE_UID' => (string) $this->userContext->uid,
                'DDE_GID' => (string) $this->userContext->gid,
                'SSH_AUTH_SOCK' => '/tmp/ssh-agent/socket',
            ];

            if ($worktreeInfo instanceof WorktreeInfo) {
                $envFileValues = $this->readEnvFileValues($serviceConfig['env_file'] ?? null, $projectDir);

                // Inline environment wins over env_file values when both
                // declare the same key — mirrors the precedence Compose
                // applies at runtime. The declaration alone is enough: a
                // bare list entry (`- FOO`) or a null map value (`FOO: ~`)
                // also suppresses any FOO from env_file, because Compose
                // would pass the host-env value (or empty) through instead.
                $inlineKeys = [];
                $inlineValues = [];

                foreach ($serviceConfig['environment'] ?? [] as $key => $value) {
                    $declaredKey = ComposeEnvEntryParser::extractKey($key, $value);

                    if ($declaredKey !== null) {
                        $inlineKeys[$declaredKey] = true;
                    }

                    $extracted = ComposeEnvEntryParser::extract($key, $value);

                    if ($extracted !== null) {
                        $inlineValues[$extracted[0]] = $extracted[1];
                    }
                }

                $combinedEnv = array_merge(
                    array_diff_key($envFileValues, $inlineKeys),
                    $inlineValues,
                );

                $envOverrides = $this->worktreeManager->computeEnvironmentOverrides(
                    $combinedEnv,
                    $config->projectName,
                    $worktreeInfo,
                    array_values(array_map(static fn (ServiceDefinition $service): string => $service->name, $config->services)),
                );

                foreach ($envOverrides as $key => $value) {
                    $environment[$key] = $value;
                }
            }

            $volumes = [
                $entrypointPath.':/dde/entrypoint.sh:ro',
                $adaptersDir.':/dde/adapters:ro',
            ];

            $projectAdaptersDir = $projectDir.'/.dde/adapters';

            if ($this->filesystem->exists($projectAdaptersDir)) {
                $volumes[] = $projectAdaptersDir.':/dde/adapters-project:ro';
            }

            $volumes[] = 'dde_ssh-agent_socket-dir:/tmp/ssh-agent:ro';

            $caRootCertPath = $this->mkcertManager->getCaRootCertPath();

            if ($caRootCertPath !== null) {
                $volumes[] = $caRootCertPath.':/dde/mkcert-rootCA.crt:ro';
            }

            // In a worktree the checked-out `.git` is a file pointing at
            // `<mainDirectory>/.git/worktrees/<name>` — an absolute host path
            // that lives outside the mounted worktree directory. Without it the
            // in-container git fails with "not a git repository". Bind-mount the
            // main repository's `.git` at the identical host path so the gitdir
            // pointer and `commondir` resolve and git works inside the container.
            // Read-write so git can refresh the worktree index; trust is granted
            // by the built-in `git` adapter (safe.directory).
            if ($worktreeInfo instanceof WorktreeInfo) {
                $mainGitDir = $worktreeInfo->mainDirectory.'/.git';

                if ($this->filesystem->exists($mainGitDir)) {
                    $volumes[] = $mainGitDir.':'.$mainGitDir;
                }
            }

            $serviceOverride = [
                'user' => '0:0',
                'entrypoint' => ['/dde/entrypoint.sh'],
                'volumes' => $volumes,
                'environment' => $environment,
                'labels' => $labelsValue,
                'networks' => $serviceNetworks,
            ];

            if ($containerHostname !== null) {
                $serviceOverride['hostname'] = $containerHostname;
            }

            // Rewrite extra_hosts only when in a worktree: container DNS for
            // `<project>.test` lives in /etc/hosts (via extra_hosts) — host-side
            // dnsmasq isn't reachable from inside the container, so without
            // this rewrite the worktree container holds the main checkout's
            // hostnames and can't reach its own worktree URLs. `!override`
            // replaces the base list on the worktree only; the main checkout
            // keeps the originals untouched.
            if ($rewrittenExtraHosts !== null) {
                $serviceOverride['extra_hosts'] = new TaggedValue('override', $rewrittenExtraHosts);
            }

            // Preserve original entrypoint + CMD when overriding entrypoint
            // Mirror Docker's behavior:
            //   - compose entrypoint overrides image ENTRYPOINT and resets CMD
            //   - compose command overrides image CMD but preserves image ENTRYPOINT
            $composeEntrypoint = $this->parseComposeStringOrList($serviceConfig['entrypoint'] ?? null);
            $composeCommand = $this->parseComposeStringOrList($serviceConfig['command'] ?? null);

            if ($composeEntrypoint !== null) {
                // Compose entrypoint overrides image entrypoint; CMD only if compose also sets it
                $resolvedEntrypoint = $composeEntrypoint;
                $resolvedCmd = $composeCommand ?? [];
            } else {
                // No compose entrypoint — use image entrypoint.
                // Values from image inspect must have $ escaped to $$ so Docker Compose
                // does not interpolate shell variables that are meant for container runtime.
                $resolvedEntrypoint = $this->escapeComposeVariables($this->getImageEntrypoint($imageName) ?? []);
                $resolvedCmd = $composeCommand ?? $this->escapeComposeVariables($this->getImageCmd($imageName) ?? []);
            }

            $command = array_merge($resolvedEntrypoint, $resolvedCmd);

            if ($command !== []) {
                $serviceOverride['command'] = $command;
            }

            // Add dev layer image override if configured for this service
            $containerConfig = $config->containers[$serviceName] ?? [];

            if (is_array($containerConfig) && isset($containerConfig['image']) && is_string($containerConfig['image'])) {
                $serviceOverride['image'] = $containerConfig['image'];
            }

            $overrideServices[$serviceName] = $serviceOverride;
        }

        $networks = [
            $projectNetwork => [
                'external' => true,
            ],
        ];

        $override = [
            'networks' => $networks,
            'volumes' => [
                'dde_ssh-agent_socket-dir' => [
                    'external' => true,
                ],
            ],
            'services' => $overrideServices,
        ];

        $yaml = Yaml::dump($override, 4, 4);

        $tempFile = TempFileUtil::createTempFile('dde-override-');

        $this->filesystem->dumpFile($tempFile, $yaml);

        // `Filesystem::dumpFile()` writes through `tempnam + chmod(0666 & ~umask) + rename`,
        // which lands at 0644 with the default umask 0022 — world-readable. The
        // overlay can carry secrets (DB passwords, API keys) inlined from
        // `env_file` values when a worktree is active, so tighten the mode to
        // 0600 explicitly. On single-user dev hosts the practical risk is low,
        // but multi-tenant build boxes and shared CI runners do not get to
        // accidentally leak the contents to other UIDs.
        $this->filesystem->chmod($tempFile, 0o600);

        return $tempFile;
    }

    /**
     * Find the compose file in a project directory.
     *
     * @throws \RuntimeException
     */
    public function findComposeFile(string $projectDir): string
    {
        return $this->findComposeFileOrNull($projectDir)
            ?? throw new \RuntimeException(sprintf('No compose file found in "%s". Expected one of: %s', $projectDir, implode(', ', ProjectConfigManager::COMPOSE_FILES)));
    }

    public function findComposeFileOrNull(string $projectDir): ?string
    {
        foreach (ProjectConfigManager::COMPOSE_FILES as $filename) {
            $path = $projectDir.'/'.$filename;

            if ($this->filesystem->exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Locate the user-supplied compose override paired with the given base file.
     * Needed because `up()` passes explicit `-f` arguments, which disables
     * Compose's default override discovery.
     */
    public function findUserOverrideFile(string $projectDir, string $composeFile): ?string
    {
        $candidates = ProjectConfigManager::COMPOSE_OVERRIDE_FILES[basename($composeFile)] ?? [];

        foreach ($candidates as $candidate) {
            $path = $projectDir.'/'.$candidate;

            if ($this->filesystem->exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Discovers service names defined in the compose file.
     *
     * @return list<string>
     */
    public function discoverServiceNames(string $projectDir): array
    {
        return array_keys($this->parseComposeServicesFromBaseFile($projectDir));
    }

    /**
     * @param list<string> $composeFiles
     *
     * @return list<string>
     */
    private function buildComposeCommand(array $composeFiles = []): array
    {
        $cmd = ['docker', 'compose'];

        foreach ($composeFiles as $file) {
            $cmd[] = '-f';
            $cmd[] = $file;
        }

        return $cmd;
    }

    /**
     * @param list<string> $command
     */
    private function runProcess(array $command, string $workingDir, ?OutputInterface $output = null): Process
    {
        $process = $this->processFactory->create($command, $workingDir, null);

        $terminal = new Terminal();
        $process->run(static function (string $type, string $buffer) use ($output, $terminal): void {
            if (!$output instanceof OutputInterface) {
                return;
            }

            $width = $terminal->getWidth();
            $stripped = preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $buffer) ?? $buffer;
            $lines = explode("\n", $stripped);
            $truncated = array_map(static fn (string $l): string => mb_substr($l, 0, $width - 1), $lines);
            $output->write(implode("\n", $truncated));
        });

        return $process;
    }

    /**
     * Discovers service names and their configurations from the compose file.
     *
     * @return array<string, array<string, mixed>>
     */
    private function parseComposeServicesFromBaseFile(string $projectDir): array
    {
        try {
            $composeFile = $this->findComposeFile($projectDir);
        } catch (\RuntimeException) {
            return [];
        }

        $data = Yaml::parseFile($composeFile, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE | Yaml::PARSE_CUSTOM_TAGS);

        if (! is_array($data) || ! is_array($data['services'] ?? null)) {
            return [];
        }

        $services = [];

        foreach ($data['services'] as $name => $config) {
            if (is_string($name) && is_array($config)) {
                $services[$name] = $config;
            }
        }

        return $services;
    }

    /**
     * Resolves the `env_file:` directive of a compose service into a flat
     * KEY => VALUE map. Supports the three forms accepted by Compose v2:
     * a single string, a list of strings, and a list of `{path, required}`
     * maps. Missing optional files are silently skipped; missing required
     * files are not enforced here because Compose itself will fail later
     * with a clearer error.
     *
     * @return array<string, string>
     */
    private function readEnvFileValues(mixed $envFile, string $projectDir): array
    {
        if ($envFile === null) {
            return [];
        }

        $entries = is_array($envFile) ? $envFile : [$envFile];
        $merged = [];

        foreach ($entries as $entry) {
            if (is_array($entry)) {
                $path = is_string($entry['path'] ?? null) ? $entry['path'] : null;
            } elseif (is_string($entry)) {
                $path = $entry;
            } else {
                $path = null;
            }

            if ($path === null) {
                continue;
            }

            $absolute = $this->filesystem->isAbsolutePath($path) ? $path : $projectDir.'/'.$path;

            if (! $this->filesystem->exists($absolute)) {
                continue;
            }

            $contents = file_get_contents($absolute);

            // Treat unreadable env files (permissions, IO error) the same as
            // missing files: skip silently. The cast-to-empty-string fallback
            // we used before would have parsed an unreadable file as empty
            // and silently disabled rewrites for that service, diverging
            // from Compose runtime behaviour.
            if ($contents === false) {
                continue;
            }

            try {
                $values = (new Dotenv())->parse($contents, $absolute);
            } catch (FormatException) {
                continue;
            }

            foreach ($values as $key => $value) {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    /**
     * Resolves the image name for a compose service (from image or build config).
     *
     * @param array<string, mixed> $serviceConfig
     */
    private function resolveServiceImage(string $serviceName, array $serviceConfig, string $projectDir): string
    {
        if (is_string($serviceConfig['image'] ?? null)) {
            return $serviceConfig['image'];
        }

        // For build-based services, the image name follows docker compose conventions
        // project-service:latest (project name derived from directory name)
        $projectDirName = strtolower(basename($projectDir));
        // Docker compose keeps hyphens but removes other special chars
        $projectDirName = (string) preg_replace('/[^a-z0-9-]/', '', $projectDirName);

        return $projectDirName.'-'.$serviceName;
    }

    /**
     * Gets the original Entrypoint from a Docker image.
     *
     * @return list<string>|null
     */
    private function getImageEntrypoint(string $image): ?array
    {
        try {
            $output = $this->dockerManager->inspectImage($image, '{{json .Config.Entrypoint}}');
        } catch (\RuntimeException) {
            return null;
        }

        if ($output === '' || $output === 'null') {
            return null;
        }

        try {
            /** @var list<string>|null $entrypoint */
            $entrypoint = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($entrypoint) ? $entrypoint : null;
    }

    /**
     * Gets the original CMD from a Docker image for entrypoint override preservation.
     *
     * @return list<string>|null
     */
    private function getImageCmd(string $image): ?array
    {
        try {
            $output = $this->dockerManager->inspectImage($image, '{{json .Config.Cmd}}');
        } catch (\RuntimeException) {
            return null;
        }

        if ($output === '' || $output === 'null') {
            return null;
        }

        try {
            /** @var list<string>|null $cmd */
            $cmd = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($cmd) ? $cmd : null;
    }

    /**
     * Parses a compose entrypoint or command value (string or list) into a list of strings.
     *
     * @return list<string>|null
     */
    private function parseComposeStringOrList(mixed $value): ?array
    {
        if (is_string($value)) {
            // Shell-form: "server /data --console-address :9001"
            $parts = preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);

            return $parts !== false && $parts !== [] ? $parts : null;
        }

        if (is_array($value) && $value !== []) {
            /** @var list<string> */
            return array_values(array_map(strval(...), $value));
        }

        return null;
    }

    /**
     * Escapes $ to $$ in values extracted from Docker image metadata so that
     * Docker Compose does not interpolate shell variables meant for container runtime.
     *
     * @param list<string> $values
     *
     * @return list<string>
     */
    private function escapeComposeVariables(array $values): array
    {
        return array_map(
            static fn (string $v): string => str_replace('$', '$$', $v),
            $values,
        );
    }

    /**
     * Resolves the container hostname for a service.
     *
     * Returns null when the compose file already declares a hostname, so the override leaves it
     * untouched. Otherwise returns a sanitized "<projectName>-<serviceName>" hostname.
     *
     * @param array<string, mixed> $serviceConfig
     */
    private function resolveContainerHostname(string $serviceName, array $serviceConfig, ResolvedConfig $config): ?string
    {
        if (is_string($serviceConfig['hostname'] ?? null) && $serviceConfig['hostname'] !== '') {
            return null;
        }

        $projectSegment = $this->sanitizeHostname($config->projectName);
        $serviceSegment = $this->sanitizeHostname($serviceName);

        if ($projectSegment === '') {
            return $serviceSegment !== '' ? $serviceSegment : null;
        }

        if ($serviceSegment === '' || $projectSegment === $serviceSegment) {
            return $projectSegment;
        }

        return $projectSegment.'-'.$serviceSegment;
    }

    /**
     * Sanitizes a string to a valid RFC 1123 hostname label (a-z, 0-9, hyphens, max 63 chars).
     */
    private function sanitizeHostname(string $value): string
    {
        $lower = strtolower($value);
        $replaced = (string) preg_replace('/[^a-z0-9-]+/', '-', $lower);
        $trimmed = trim($replaced, '-');

        if (strlen($trimmed) > 63) {
            $trimmed = trim(substr($trimmed, 0, 63), '-');
        }

        return $trimmed;
    }

    /**
     * Unwraps a `labels: !override [...]` or `labels: !reset [...]` TaggedValue
     * to its inner array so downstream label scanning can iterate it. Compose
     * itself drops the base labels on either tag; for dde's overlay purposes
     * we treat the inner list as the effective label set.
     *
     * @return array<int|string, mixed>
     */
    private function unwrapTaggedLabels(mixed $labels): array
    {
        return $this->unwrapTaggedList($labels);
    }

    /**
     * Same TaggedValue unwrap as `unwrapTaggedLabels()`, generalised for any
     * compose list value that may arrive wrapped in `!override` or `!reset`
     * after the base file is parsed with `PARSE_CUSTOM_TAGS`. Used for
     * `extra_hosts` (and any other list-shaped service field that needs the
     * same treatment) so a `!override` base entry is honoured instead of
     * being silently coerced to `[]`.
     *
     * @return array<int|string, mixed>
     */
    private function unwrapTaggedList(mixed $value): array
    {
        if ($value instanceof TaggedValue) {
            $value = $value->getValue();
        }

        return is_array($value) ? $value : [];
    }

    /**
     * Overrides Traefik labels from compose.yml for worktree usage.
     *
     * Rewrites every `Host(`<x>.<project>.test`)` value (including the bare
     * `Host(`<project>.test`)`) and the matching router/service identifier
     * segments derived from those hostnames. Hostname matching is strict:
     * only the bare project hostname or genuine subdomain-suffix hosts are
     * rewritten — unrelated hosts that merely contain the project hostname
     * as a substring (e.g. `testproject-beispiel.test` when the project is
     * `beispiel`) pass through untouched. Router/service identifier rewrites
     * are anchored at the start of the router-name segment (right after
     * `routers.` or `services.`) so a similarly named unrelated identifier
     * (`testproject-beispiel-test-…`) is not silently renamed.
     *
     * Services without Traefik labels stay unrouted in the worktree, mirroring
     * the main-checkout behaviour: dde does not invent routing for a service
     * the user never opted into. Helper containers without an exposed port
     * (e.g. `playwright`, e2e runners, background workers) would otherwise
     * get auto-generated `traefik.enable=true` + `Host(...)` labels and crash
     * Traefik with "port is missing".
     *
     * @param array<int|string, mixed> $existingLabels
     *
     * @return list<string>
     */
    private function overrideTraefikLabels(array $existingLabels, string $projectName, WorktreeInfo $worktreeInfo): array
    {
        // First pass: collect every host we will rewrite so router/service
        // identifiers derived from those hosts can be renamed in *every*
        // label, including ones that do not themselves carry a `Host()` rule
        // (e.g. `traefik.http.routers.<name>-tls.tls=true`).
        $dotFormMap = [];

        foreach ($existingLabels as $key => $value) {
            $label = is_int($key) ? (string) $value : $key.'='.$value;

            if (! str_contains($label, 'traefik.')) {
                continue;
            }

            if (preg_match_all('/Host\(`([^`]+)`\)/', $label, $matches) === false) {
                continue;
            }

            foreach ($matches[1] as $original) {
                $rewritten = $this->worktreeManager->rewriteHostname($original, $projectName, $worktreeInfo);

                if ($rewritten !== $original) {
                    $dotFormMap[str_replace('.', '-', $original)] = str_replace('.', '-', $rewritten);
                }
            }
        }

        // Second pass: emit the actual override labels. Non-Traefik labels
        // (e.g. monitoring/logging metadata defined on the service) pass
        // through verbatim — otherwise the `!override` we emit at the call
        // site would silently drop every user label that is not a Traefik
        // directive.
        $overrideLabels = [];

        foreach ($existingLabels as $key => $value) {
            $label = is_int($key) ? (string) $value : $key.'='.$value;

            if (! str_contains($label, 'traefik.')) {
                $overrideLabels[] = $label;
                continue;
            }

            $label = (string) preg_replace_callback(
                '/Host\(`([^`]+)`\)/',
                fn (array $match): string => 'Host(`'.$this->worktreeManager->rewriteHostname($match[1], $projectName, $worktreeInfo).'`)',
                $label,
            );

            // Anchor the dot-form rewrite to the start of a router-name
            // segment: the previous character must be `.` (right after
            // `routers.` or `services.`) and the next character must be `-`
            // (the service-name suffix or the `-tls` modifier). Allowing `-`
            // in the lookbehind would falsely match `beispiel-test` inside
            // `testproject-beispiel-test-app`, which is an unrelated router.
            foreach ($dotFormMap as $oldDotForm => $newDotForm) {
                $label = (string) preg_replace(
                    '/(?<=\.)'.preg_quote($oldDotForm, '/').'(?=-)/',
                    $newDotForm,
                    $label,
                );
            }

            $overrideLabels[] = $label;
        }

        return $overrideLabels;
    }
}
