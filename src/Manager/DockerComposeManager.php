<?php

declare(strict_types=1);

namespace App\Manager;

use App\Adapter\AdapterRegistry;
use App\Config\ResolvedConfig;
use App\Config\WorktreeInfo;
use App\Model\UserContext;
use App\Service\TraefikService;
use App\Util\NdJsonParser;
use App\Util\ProcessFactory;
use App\Util\TempFileUtil;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

readonly class DockerComposeManager
{
    public function __construct(
        private AdapterRegistry $adapterRegistry,
        private ConfigManager $configManager,
        private DockerManager $dockerManager,
        private TraefikService $traefikService,
        private UserContext $userContext,
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

    public function generateOverride(ResolvedConfig $config, string $projectDir, ?WorktreeInfo $worktreeInfo = null): string
    {
        $composeServices = $this->discoverComposeServicesWithConfig($projectDir);

        if ($composeServices === []) {
            throw new \RuntimeException(sprintf('No services found in docker-compose.yml in "%s"', $projectDir));
        }

        $overrideServices = [];
        $entrypointPath = $this->adapterRegistry->getEntrypointPath();
        $adaptersDir = $this->adapterRegistry->getBuiltinAdaptersDir();

        foreach ($composeServices as $serviceName => $serviceConfig) {
            $imageName = $this->resolveServiceImage($serviceName, $serviceConfig, $projectDir);

            $labels = ['dde.managed=true'];
            $worktreeHostname = null;
            $projectHostname = $config->projectName.'.test';

            if ($worktreeInfo instanceof WorktreeInfo) {
                $worktreeHostname = $this->configManager->resolveProjectHostname($config->projectName, $worktreeInfo);
                $labels = array_merge($labels, $this->overrideTraefikLabels($serviceConfig['labels'] ?? [], $projectHostname, $worktreeHostname, $serviceName));
            }

            // Skip entrypoint override for shell-less images (scratch, single-binary)
            if (! $this->dockerManager->imageHasShell($imageName)) {
                $overrideServices[$serviceName] = [
                    'labels' => $labels,
                ];

                continue;
            }

            $environment = [
                'DDE_UID' => (string) $this->userContext->uid,
                'DDE_GID' => (string) $this->userContext->gid,
            ];

            if ($worktreeHostname !== null) {
                $envOverrides = $this->overrideEnvironmentHostnames($serviceConfig['environment'] ?? [], $projectHostname, $worktreeHostname);

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

            $serviceOverride = [
                'entrypoint' => ['/dde/entrypoint.sh'],
                'volumes' => $volumes,
                'environment' => $environment,
                'labels' => $labels,
            ];

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

        $override = [
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
            ?? throw new \RuntimeException(sprintf('No compose file found in "%s". Expected one of: %s', $projectDir, implode(', ', ConfigManager::COMPOSE_FILES)));
    }

    public function findComposeFileOrNull(string $projectDir): ?string
    {
        foreach (ConfigManager::COMPOSE_FILES as $filename) {
            $path = $projectDir.'/'.$filename;

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
        return array_keys($this->discoverComposeServicesWithConfig($projectDir));
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
    private function discoverComposeServicesWithConfig(string $projectDir): array
    {
        try {
            $composeFile = $this->findComposeFile($projectDir);
        } catch (\RuntimeException) {
            return [];
        }

        $data = Yaml::parseFile($composeFile, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);

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
     * Overrides Traefik labels from compose.yml for worktree usage.
     *
     * Replaces the Host() value in existing router rules with the worktree hostname,
     * keeping the original router names so the override file overwrites (not duplicates) them.
     *
     * @param array<int|string, mixed> $existingLabels
     *
     * @return list<string>
     */
    private function overrideTraefikLabels(array $existingLabels, string $projectHostname, string $worktreeHostname, string $serviceName): array
    {
        $overrideLabels = [];
        $hasTraefikLabels = false;

        foreach ($existingLabels as $key => $value) {
            $label = is_int($key) ? (string) $value : $key.'='.$value;

            if (! str_contains($label, 'traefik.')) {
                continue;
            }

            $hasTraefikLabels = true;

            // Replace Host(`project.test`) with Host(`worktree.project.test`)
            $overrideLabels[] = (string) preg_replace(
                '/Host\(`'.preg_quote($projectHostname, '/').'`\)/',
                sprintf('Host(`%s`)', $worktreeHostname),
                $label,
            );
        }

        // Fallback: generate new labels if compose.yml has none
        if (! $hasTraefikLabels) {
            return $this->traefikService->generateLabels([$worktreeHostname], $serviceName);
        }

        return $overrideLabels;
    }

    /**
     * Scans environment variables for the project hostname and returns overrides
     * with the worktree hostname substituted.
     *
     * Handles both map format (KEY: value) and list format (- KEY=value).
     *
     * @param array<int|string, mixed> $existingEnv
     *
     * @return array<string, string>
     */
    private function overrideEnvironmentHostnames(array $existingEnv, string $projectHostname, string $worktreeHostname): array
    {
        $overrides = [];

        foreach ($existingEnv as $key => $value) {
            if (is_int($key)) {
                // List format: "KEY=value"
                $envString = (string) $value;

                if (! str_contains($envString, $projectHostname)) {
                    continue;
                }

                $eqPos = strpos($envString, '=');

                if ($eqPos === false) {
                    continue;
                }

                $envKey = substr($envString, 0, $eqPos);
                $envValue = substr($envString, $eqPos + 1);
                $overrides[$envKey] = str_replace($projectHostname, $worktreeHostname, $envValue);
            } else {
                // Map format: KEY: value
                $envValue = (string) $value;

                if (! str_contains($envValue, $projectHostname)) {
                    continue;
                }

                $overrides[$key] = str_replace($projectHostname, $worktreeHostname, $envValue);
            }
        }

        return $overrides;
    }
}
