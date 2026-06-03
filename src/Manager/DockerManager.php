<?php

declare(strict_types=1);

namespace App\Manager;

use App\Model\ContainerConfig;
use App\Model\ContainerInfo;
use App\Model\ContainerStatus;
use App\Util\NdJsonParser;
use App\Util\ProcessFactory;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

readonly class DockerManager
{
    public function __construct(
        private ProcessFactory $processFactory,
    ) {
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \RuntimeException
     */
    public function inspect(string $containerId): array
    {
        $process = $this->processFactory->create(['docker', 'inspect', $containerId]);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(sprintf('Failed to inspect container "%s": %s', $containerId, $process->getErrorOutput()));
        }

        try {
            /** @var array<int, array<string, mixed>> $data */
            $data = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $jsonException) {
            throw new \RuntimeException(sprintf('Failed to parse inspect output for container "%s": %s', $containerId, $jsonException->getMessage()), $jsonException->getCode(), $jsonException);
        }

        if (! isset($data[0])) {
            throw new \RuntimeException(sprintf('Unexpected inspect output for container "%s"', $containerId));
        }

        return $data[0];
    }

    public function isContainerRunning(string $containerName): bool
    {
        $process = $this->processFactory->create(['docker', 'inspect', '--format', '{{.State.Running}}', $containerName]);
        $process->run();

        if (! $process->isSuccessful()) {
            return false;
        }

        return trim($process->getOutput()) === 'true';
    }

    public function containerExists(string $containerName): bool
    {
        $process = $this->processFactory->create([
            'docker', 'ps', '-a',
            '--filter', sprintf('name=^%s$', $containerName),
            '--format', '{{.Names}}',
        ]);
        $process->run();

        if (! $process->isSuccessful()) {
            return false;
        }

        return trim($process->getOutput()) === $containerName;
    }

    public function start(string $containerName): void
    {
        $process = $this->processFactory->create(['docker', 'start', $containerName]);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    /**
     * @return array<ContainerInfo>
     *
     * @throws \RuntimeException
     */
    public function getContainersByLabel(string $label, ?string $value = null): array
    {
        $filter = $value !== null ? sprintf('label=%s=%s', $label, $value) : sprintf('label=%s', $label);
        $process = $this->processFactory->create(['docker', 'ps', '-a', '--filter', $filter, '--format', 'json']);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(sprintf('Failed to list containers by label "%s=%s": %s', $label, $value, $process->getErrorOutput()));
        }

        $parsed = NdJsonParser::parse($process->getOutput(), 'container');
        $containers = [];

        foreach ($parsed as $data) {
            $labels = [];

            if (is_string($data['Labels'] ?? null)) {
                foreach (explode(',', $data['Labels']) as $labelPair) {
                    $parts = explode('=', $labelPair, 2);

                    if (count($parts) === 2) {
                        $labels[$parts[0]] = $parts[1];
                    }
                }
            }

            $containers[] = new ContainerInfo(
                name: is_string($data['Names'] ?? null) ? $data['Names'] : '',
                status: ContainerStatus::fromDockerStatus(is_string($data['Status'] ?? null) ? $data['Status'] : ''),
                image: is_string($data['Image'] ?? null) ? $data['Image'] : '',
                labels: $labels,
            );
        }

        return $containers;
    }

    public function networkExists(string $name): bool
    {
        $process = $this->processFactory->create(['docker', 'network', 'inspect', $name]);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Returns true if the network exists and has one or more connected containers.
     * Used by lifecycle teardown to skip network removal when another project
     * (e.g. main while tearing down a worktree) still has containers attached.
     */
    public function networkHasActiveContainers(string $name): bool
    {
        $process = $this->processFactory->create([
            'docker', 'network', 'inspect', '--format', '{{len .Containers}}', $name,
        ]);
        $process->run();

        if (! $process->isSuccessful()) {
            return false;
        }

        return (int) trim($process->getOutput()) > 0;
    }

    /**
     * Returns the container names currently attached to the given network.
     * Empty list when the network does not exist. Used by lifecycle teardown
     * to tell "last project on the network" from "other projects still using it".
     *
     * @return list<string>
     */
    public function getConnectedContainerNames(string $network): array
    {
        $process = $this->processFactory->create([
            'docker',
            'network',
            'inspect',
            '--format',
            "{{range .Containers}}{{.Name}}\n{{end}}",
            $network,
        ]);
        $process->run();

        if (! $process->isSuccessful()) {
            return [];
        }

        return array_values(array_filter(
            explode("\n", trim($process->getOutput())),
            static fn (string $name): bool => $name !== '',
        ));
    }

    /**
     * Returns the network names whose name matches the given prefix.
     *
     * @return list<string>
     */
    public function listNetworksWithPrefix(string $prefix): array
    {
        $process = $this->processFactory->create([
            'docker',
            'network',
            'ls',
            '--filter',
            'name='.$prefix,
            '--format',
            '{{.Name}}',
        ]);
        $process->run();

        if (! $process->isSuccessful()) {
            return [];
        }

        return array_values(array_filter(
            explode("\n", trim($process->getOutput())),
            static fn (string $name): bool => $name !== '' && str_starts_with($name, $prefix),
        ));
    }

    public function createNetwork(string $name): void
    {
        $process = $this->processFactory->create(['docker', 'network', 'create', $name]);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    public function removeNetwork(string $name): void
    {
        $process = $this->processFactory->create(['docker', 'network', 'rm', $name]);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    /**
     * Connects a container to a Docker network, optionally with aliases.
     * Silently ignores "already connected" errors so re-up is idempotent.
     *
     * @param list<string> $aliases
     *
     * @throws ProcessFailedException
     */
    public function connectContainerToNetwork(string $containerName, string $networkName, array $aliases = []): void
    {
        $command = ['docker', 'network', 'connect'];

        foreach ($aliases as $alias) {
            $command[] = '--alias';
            $command[] = $alias;
        }

        $command[] = $networkName;
        $command[] = $containerName;

        $process = $this->processFactory->create($command);
        $process->run();

        if (! $process->isSuccessful()) {
            // Ignore "already exists in network" — container was already connected (idempotent re-up)
            if (str_contains($process->getErrorOutput(), 'already exists in network')) {
                return;
            }

            throw new ProcessFailedException($process);
        }
    }

    /**
     * Disconnects a container from a Docker network.
     * Silently ignores failures (container may already be stopped/removed).
     */
    public function disconnectContainerFromNetwork(string $containerName, string $networkName): void
    {
        $process = $this->processFactory->create(['docker', 'network', 'disconnect', $networkName, $containerName]);
        $process->run();
        // Failures are intentionally ignored — the container may already be stopped.
    }

    public function run(ContainerConfig $config): void
    {
        $command = ['docker', 'run', '-d'];

        $command[] = '--name';
        $command[] = $config->containerName;

        $command[] = '--restart';
        $command[] = $config->restartPolicy;

        $command[] = '--network';
        $command[] = 'dde';

        foreach ($config->ports as $port) {
            $command[] = '-p';
            $command[] = $port;
        }

        foreach ($config->volumes as $hostPath => $containerPath) {
            $command[] = '-v';
            $command[] = sprintf('%s:%s', $hostPath, $containerPath);
        }

        foreach ($config->environment as $key => $envValue) {
            $command[] = '-e';
            $command[] = sprintf('%s=%s', $key, $envValue);
        }

        foreach ($config->labels as $key => $labelValue) {
            $command[] = '-l';
            $command[] = sprintf('%s=%s', $key, $labelValue);
        }

        foreach ($config->networkAliases as $alias) {
            $command[] = '--network-alias';
            $command[] = $alias;
        }

        $command[] = $config->image;

        $process = $this->processFactory->create($command);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    public function stop(string $containerName): void
    {
        $process = $this->processFactory->create(['docker', 'stop', $containerName]);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    public function remove(string $containerName): void
    {
        $process = $this->processFactory->create(['docker', 'rm', '-f', $containerName]);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    /**
     * Returns the IP address of a container on a specific Docker network, or null if unavailable.
     */
    public function getContainerNetworkIp(string $containerName, string $network = 'dde'): ?string
    {
        $process = $this->processFactory->create([
            'docker', 'inspect',
            '--format', sprintf('{{(index .NetworkSettings.Networks "%s").IPAddress}}', $network),
            $containerName,
        ]);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        $ip = trim($process->getOutput());

        return $ip !== '' ? $ip : null;
    }

    public function getContainerUptime(string $containerName): ?string
    {
        $process = $this->processFactory->create(['docker', 'inspect', '--format', '{{.State.StartedAt}}', $containerName]);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        $startedAt = trim($process->getOutput());

        if ($startedAt === '' || $startedAt === '0001-01-01T00:00:00Z') {
            return null;
        }

        return $startedAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContainerPorts(string $containerName): array
    {
        $process = $this->processFactory->create(['docker', 'inspect', '--format', '{{json .NetworkSettings.Ports}}', $containerName]);
        $process->run();

        if (! $process->isSuccessful()) {
            return [];
        }

        $output = trim($process->getOutput());

        if ($output === '' || $output === 'null') {
            return [];
        }

        try {
            /** @var array<string, mixed> $ports */
            $ports = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return $ports;
    }

    public function buildImage(string $contextDir, string $tag, ?OutputInterface $output = null, bool $pull = false): void
    {
        $command = ['docker', 'build', '--progress', 'plain'];

        if ($pull) {
            $command[] = '--pull';
        }

        $command[] = '-t';
        $command[] = $tag;
        $command[] = $contextDir;

        $process = $this->processFactory->create($command, $contextDir, null);

        $outputBuffer = '';
        $process->run(static function (string $_type, string $buffer) use (&$outputBuffer): void {
            $outputBuffer .= $buffer;
        });

        if (! $process->isSuccessful()) {
            $output?->write($outputBuffer);
            throw new ProcessFailedException($process);
        }
    }

    public function imageExists(string $tag): bool
    {
        $process = $this->processFactory->create(['docker', 'image', 'inspect', $tag]);
        $process->run();

        return $process->isSuccessful();
    }

    public function removeImage(string $tag): void
    {
        $process = $this->processFactory->create(['docker', 'rmi', '-f', $tag]);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(sprintf('Failed to remove image "%s": %s', $tag, $process->getErrorOutput()));
        }
    }

    /**
     * @param list<string> $command
     */
    public function runEphemeral(string $image, array $command): Process
    {
        $cmd = ['docker', 'run', '--rm', '--entrypoint', '', $image, ...$command];
        $process = $this->processFactory->create($cmd, null, null);
        $process->run();

        return $process;
    }

    /**
     * Checks whether an image has a usable shell (/bin/sh).
     *
     * Scratch-based and single-binary images have no userland tools, so
     * the dde entrypoint script cannot run inside them. We probe by
     * attempting to run /bin/sh inside the image — this is the only
     * reliable method since some minimal images ship a PATH that
     * references /bin even though /bin/sh does not exist.
     */
    public function imageHasShell(string $image): bool
    {
        $process = $this->processFactory->create(
            ['docker', 'run', '--rm', '--entrypoint', '/bin/sh', $image, '-c', 'exit 0'],
        );
        $process->setTimeout(30);
        $process->run();

        return $process->isSuccessful();
    }

    public function inspectImage(string $image, string $format): string
    {
        $process = $this->processFactory->create(['docker', 'image', 'inspect', '--format', $format, $image], null, null);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(sprintf('Failed to inspect image "%s": %s', $image, $process->getErrorOutput()));
        }

        return trim($process->getOutput());
    }

    /**
     * @param list<string> $command
     *
     * @throws \RuntimeException
     */
    public function execCapture(string $containerName, array $command): string
    {
        $cmd = ['docker', 'exec', $containerName, ...$command];
        $process = $this->processFactory->create($cmd, null, 300);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(sprintf('Failed to exec in container "%s": %s', $containerName, $process->getErrorOutput()));
        }

        return $process->getOutput();
    }

    /**
     * @return array<string, string>
     *
     * @throws \RuntimeException
     */
    public function getContainerEnv(string $containerName): array
    {
        $process = $this->processFactory->create(['docker', 'inspect', '--format', '{{json .Config.Env}}', $containerName]);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(sprintf('Failed to get env for container "%s": %s', $containerName, $process->getErrorOutput()));
        }

        $output = trim($process->getOutput());

        if ($output === '' || $output === 'null') {
            return [];
        }

        try {
            /** @var list<string> $envList */
            $envList = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $jsonException) {
            throw new \RuntimeException(sprintf('Failed to parse env for container "%s": %s', $containerName, $jsonException->getMessage()), $jsonException->getCode(), $jsonException);
        }

        $env = [];

        foreach ($envList as $item) {
            $parts = explode('=', $item, 2);

            if (count($parts) === 2) {
                $env[$parts[0]] = $parts[1];
            }
        }

        return $env;
    }

    /**
     * Returns a ready-to-run interactive `docker run --rm -it` Process without executing it.
     * The container is automatically removed when the process exits.
     *
     * @param list<string>           $volumes      each element in "host:container" format
     * @param array<string, string>  $environment
     * @param list<string>           $cmd          command to run inside the container
     */
    public function createInteractiveRunProcess(
        string $image,
        array $volumes = [],
        array $environment = [],
        ?string $network = null,
        ?string $user = null,
        array $cmd = [],
    ): Process {
        $command = ['docker', 'run', '--rm', '-it'];

        if ($user !== null) {
            $command[] = '-u';
            $command[] = $user;
        }

        foreach ($volumes as $volume) {
            $command[] = '-v';
            $command[] = $volume;
        }

        foreach ($environment as $key => $value) {
            $command[] = '-e';
            $command[] = sprintf('%s=%s', $key, $value);
        }

        if ($network !== null) {
            $command[] = '--network';
            $command[] = $network;
        }

        $command[] = $image;

        foreach ($cmd as $part) {
            $command[] = $part;
        }

        $process = $this->processFactory->create($command, null, null);

        if (Process::isTtySupported()) {
            $process->setTty(true);
        }

        return $process;
    }

    /**
     * Returns a ready-to-run interactive `docker exec -it` Process without executing it.
     * Callers receive the exit code via `$process->getExitCode()` after `$process->run()`.
     *
     * @param list<string> $command
     */
    public function createInteractiveExecProcess(string $containerName, array $command, ?string $user = null): Process
    {
        $cmd = ['docker', 'exec'];

        if ($user !== null) {
            $cmd[] = '-u';
            $cmd[] = $user;
        }

        $cmd[] = '-it';
        $cmd[] = $containerName;

        foreach ($command as $part) {
            $cmd[] = $part;
        }

        $process = $this->processFactory->create($cmd, null, null);

        if (Process::isTtySupported()) {
            $process->setTty(true);
        }

        return $process;
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $env
     */
    public function execInteractive(string $containerName, array $command, array $env = []): void
    {
        $cmd = ['docker', 'exec', '-it'];

        foreach ($env as $key => $value) {
            $cmd[] = '-e';
            $cmd[] = sprintf('%s=%s', $key, $value);
        }

        $cmd[] = $containerName;

        foreach ($command as $part) {
            $cmd[] = $part;
        }

        $process = $this->processFactory->create($cmd, null, null);

        if (Process::isTtySupported()) {
            $process->setTty(true);
        }

        $process->mustRun();
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $env
     *
     * @throws \RuntimeException
     */
    public function execCaptureWithEnv(string $containerName, array $command, array $env): string
    {
        $cmd = ['docker', 'exec'];

        foreach ($env as $key => $value) {
            $cmd[] = '-e';
            $cmd[] = sprintf('%s=%s', $key, $value);
        }

        $cmd[] = $containerName;

        foreach ($command as $part) {
            $cmd[] = $part;
        }

        $process = $this->processFactory->create($cmd, null, 300);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(sprintf('Failed to exec in container "%s": %s', $containerName, $process->getErrorOutput()));
        }

        return $process->getOutput();
    }

    /**
     * @param array<string, string> $filters
     *
     * @return array<array{Name: string, Labels: string}>
     *
     * @throws \RuntimeException
     */
    public function listVolumes(array $filters = []): array
    {
        $cmd = ['docker', 'volume', 'ls', '--format', 'json'];

        foreach ($filters as $key => $filterValue) {
            $cmd[] = '--filter';
            $cmd[] = sprintf('%s=%s', $key, $filterValue);
        }

        $process = $this->processFactory->create($cmd);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(sprintf('Failed to list volumes: %s', $process->getErrorOutput()));
        }

        $parsed = NdJsonParser::parse($process->getOutput(), 'volume');
        $volumes = [];

        foreach ($parsed as $data) {
            $volumes[] = [
                'Name' => is_string($data['Name'] ?? null) ? $data['Name'] : '',
                'Labels' => is_string($data['Labels'] ?? null) ? $data['Labels'] : '',
            ];
        }

        return $volumes;
    }

    /**
     * @param array<string, string> $filters
     *
     * @return array<array{ID: string, Repository: string, Tag: string, Size: string}>
     *
     * @throws \RuntimeException
     */
    public function listImages(array $filters = []): array
    {
        $cmd = ['docker', 'image', 'ls', '--format', 'json'];

        foreach ($filters as $key => $filterValue) {
            $cmd[] = '--filter';
            $cmd[] = sprintf('%s=%s', $key, $filterValue);
        }

        $process = $this->processFactory->create($cmd);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(sprintf('Failed to list images: %s', $process->getErrorOutput()));
        }

        $parsed = NdJsonParser::parse($process->getOutput(), 'image');
        $images = [];

        foreach ($parsed as $data) {
            $images[] = [
                'ID' => is_string($data['ID'] ?? null) ? $data['ID'] : '',
                'Repository' => is_string($data['Repository'] ?? null) ? $data['Repository'] : '',
                'Tag' => is_string($data['Tag'] ?? null) ? $data['Tag'] : '',
                'Size' => is_string($data['Size'] ?? null) ? $data['Size'] : '',
            ];
        }

        return $images;
    }

    public function removeVolume(string $name): void
    {
        $process = $this->processFactory->create(['docker', 'volume', 'rm', $name]);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(sprintf('Failed to remove volume "%s": %s', $name, $process->getErrorOutput()));
        }
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $env
     * @param resource|string $input
     *
     * @throws \RuntimeException
     */
    public function execWithInput(string $containerName, array $command, mixed $input, array $env = []): void
    {
        $cmd = ['docker', 'exec', '-i'];

        foreach ($env as $key => $value) {
            $cmd[] = '-e';
            $cmd[] = sprintf('%s=%s', $key, $value);
        }

        $cmd[] = $containerName;

        foreach ($command as $part) {
            $cmd[] = $part;
        }

        $process = $this->processFactory->create($cmd, null, 300);
        $process->setInput($input);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(sprintf('Failed to exec in container "%s": %s', $containerName, $process->getErrorOutput()));
        }
    }

    /**
     * @param list<array<string, mixed>> $containers
     */
    public function determineOverallStatus(array $containers): string
    {
        if ($containers === []) {
            return 'stopped';
        }

        $runningCount = 0;

        foreach ($containers as $container) {
            $state = $container['State'] ?? $container['state'] ?? $container['Status'] ?? '';

            if ($state === 'running') {
                $runningCount++;
            }
        }

        if ($runningCount === count($containers)) {
            return 'running';
        }

        if ($runningCount > 0) {
            return 'partial';
        }

        return 'stopped';
    }

    /**
     * @param array<string, mixed> $container
     *
     * @return list<string>
     */
    public function extractPorts(array $container): array
    {
        $ports = $container['Ports'] ?? $container['ports'] ?? $container['Publishers'] ?? [];

        if (is_string($ports)) {
            return $ports !== '' ? [$ports] : [];
        }

        if (! is_array($ports)) {
            return [];
        }

        $formatted = [];

        foreach ($ports as $port) {
            if (is_array($port)) {
                $target = $port['TargetPort'] ?? $port['target'] ?? 0;
                $protocol = $port['Protocol'] ?? $port['protocol'] ?? 'tcp';

                if ((int) $target > 0) {
                    $formatted[] = sprintf('%s/%s', $target, $protocol);
                }
            } elseif (is_string($port)) {
                $formatted[] = $port;
            }
        }

        return $formatted;
    }
}
