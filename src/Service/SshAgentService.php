<?php

declare(strict_types=1);

namespace App\Service;

use App\Manager\DockerManager;
use App\Manager\GlobalConfigManager;
use App\Model\ContainerConfig;
use App\Model\UserContext;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

final class SshAgentService extends AbstractSystemService
{
    public function __construct(
        DockerManager $dockerManager,
        private readonly Filesystem $filesystem,
        private readonly ImageBuilder $imageBuilder,
        private readonly UserContext $userContext,
        private readonly GlobalConfigManager $globalConfigManager,
        private readonly string $projectDir,
        private readonly string $userHomeDir,
        private readonly string $dataDir,
    ) {
        parent::__construct($dockerManager);
    }

    public function getName(): string
    {
        return 'ssh-agent';
    }

    public function getContainerName(): string
    {
        return 'dde-ssh-agent';
    }

    public function getImageName(): string
    {
        return 'dde-ssh-agent:local';
    }

    public function getContainerConfig(): ContainerConfig
    {
        $keys = $this->getConfiguredKeys();

        $volumes = [
            'dde_ssh-agent_socket-dir' => '/tmp/ssh-agent',
        ];

        foreach ($keys as $key) {
            $volumes[$key] = '/home/dde/.ssh/'.basename($key).':ro';
        }

        return new ContainerConfig(
            image: $this->getImageName(),
            containerName: $this->getContainerName(),
            volumes: $volumes,
            environment: [
                'DDE_UID' => (string) $this->userContext->uid,
                'DDE_GID' => (string) $this->userContext->gid,
            ],
            labels: $this->getDefaultLabels(),
        );
    }

    public function start(): void
    {
        $this->build();

        parent::start();
    }

    public function build(bool $pull = false): void
    {
        $this->buildImage($pull);
    }

    /**
     * Add SSH keys to the running agent interactively (prompts for passphrases).
     * Call this after start() to load keys into the agent.
     */
    public function addKeys(): void
    {
        if (!$this->isRunning()) {
            return;
        }

        $keys = $this->getConfiguredKeys();

        foreach ($keys as $keyPath) {
            $containerKeyPath = '/home/dde/.ssh/'.basename($keyPath);
            $this->dockerManager->execInteractive(
                $this->getContainerName(),
                ['ssh-add', $containerKeyPath],
                [
                    'SSH_AUTH_SOCK' => '/tmp/ssh-agent/socket',
                ],
            );
        }
    }

    /**
     * Check how many keys are currently loaded in the agent.
     */
    public function getLoadedKeyCount(): int
    {
        if (!$this->isRunning()) {
            return 0;
        }

        try {
            $output = $this->dockerManager->execCapture(
                $this->getContainerName(),
                ['ssh-add', '-l'],
            );

            if (str_contains($output, 'no identities')) {
                return 0;
            }

            return count(array_filter(explode("\n", trim($output))));
        } catch (\RuntimeException) {
            return 0;
        }
    }

    public function buildImage(bool $pull = false): void
    {
        $resourceDir = $this->projectDir.'/resources/docker/ssh-agent';
        $dockerfilePath = $resourceDir.'/Dockerfile';
        $runShPath = $resourceDir.'/run.sh';

        if (!$this->filesystem->exists($dockerfilePath)) {
            throw new \RuntimeException(sprintf('Dockerfile not found at "%s"', $dockerfilePath));
        }

        $files = [
            'Dockerfile' => $this->filesystem->readFile($dockerfilePath),
        ];

        if ($this->filesystem->exists($runShPath)) {
            $files['run.sh'] = $this->filesystem->readFile($runShPath);
        }

        $this->imageBuilder->buildIfChanged(
            $this->getImageName(),
            $this->dataDir.'/ssh-agent/.build-hash',
            $files,
            'dde-ssh-agent-',
            $pull,
        );
    }

    /**
     * @return array<string>
     */
    public function getConfiguredKeys(): array
    {
        $sshKeys = $this->globalConfigManager->load()->sshKeys;

        if ($sshKeys === null) {
            return $this->detectSshKeys();
        }

        return array_map(
            fn (string $key): string => str_starts_with($key, '~/') ? $this->userHomeDir.substr($key, 1) : $key,
            $sshKeys,
        );
    }

    /**
     * @return array<string>
     */
    public function detectSshKeys(): array
    {
        $sshDir = $this->userHomeDir.'/.ssh';

        if (! $this->filesystem->exists($sshDir)) {
            return [];
        }

        $keys = [];

        $finder = Finder::create()
            ->in($sshDir)
            ->files()
            ->depth('== 0')
            ->notName('*.pub')
            ->notName('known_hosts')
            ->notName('known_hosts.old')
            ->notName('config')
            ->notName('authorized_keys');

        foreach ($finder as $file) {
            $filePath = $file->getPathname();
            $content = $this->filesystem->readFile($filePath);

            if (str_contains($content, 'PRIVATE KEY')) {
                $keys[] = $filePath;
            }
        }

        return $keys;
    }
}
