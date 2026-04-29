<?php

declare(strict_types=1);

namespace App\Service;

use App\Manager\DockerManager;
use App\Model\ContainerConfig;
use App\Model\ServiceStatus;

abstract class AbstractSystemService implements ServiceInterface
{
    public function __construct(
        protected readonly DockerManager $dockerManager,
    ) {
    }

    abstract public function getName(): string;

    abstract public function getContainerName(): string;

    abstract public function getImageName(): string;

    abstract public function getContainerConfig(): ContainerConfig;

    public function start(): void
    {
        if ($this->isRunning()) {
            return;
        }

        $containerName = $this->getContainerName();

        if ($this->dockerManager->containerExists($containerName)) {
            $this->dockerManager->start($containerName);

            return;
        }

        $this->dockerManager->run($this->getContainerConfig());
    }

    public function stop(): void
    {
        if (! $this->isRunning()) {
            return;
        }

        $this->dockerManager->stop($this->getContainerName());
    }

    public function remove(): void
    {
        $containerName = $this->getContainerName();

        if (! $this->dockerManager->containerExists($containerName)) {
            return;
        }

        if ($this->isRunning()) {
            $this->dockerManager->stop($containerName);
        }

        $this->dockerManager->remove($containerName);
    }

    public function build(bool $pull = false): void
    {
        // Hook for services with a local image build; upstream-image services inherit this no-op.
    }

    public function status(): ServiceStatus
    {
        if ($this->isRunning()) {
            return ServiceStatus::RUNNING;
        }

        return ServiceStatus::STOPPED;
    }

    public function isRunning(): bool
    {
        return $this->dockerManager->isContainerRunning($this->getContainerName());
    }

    /**
     * @return array<string, string>
     */
    protected function getDefaultLabels(): array
    {
        return [
            'dde.managed' => 'true',
            'dde.service' => $this->getName(),
            'com.docker.compose.project' => 'dde',
        ];
    }
}
