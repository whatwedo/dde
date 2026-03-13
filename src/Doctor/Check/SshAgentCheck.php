<?php

declare(strict_types=1);

namespace App\Doctor\Check;

use App\Doctor\CheckInterface;
use App\Doctor\CheckResult;
use App\Doctor\CheckStatus;
use App\Manager\DockerManager;

final class SshAgentCheck implements CheckInterface
{
    public function __construct(
        private readonly DockerManager $dockerManager,
    ) {
    }

    public function getName(): string
    {
        return 'SSH Agent';
    }

    public function run(): CheckResult
    {
        if ($this->dockerManager->isContainerRunning('dde-ssh-agent')) {
            return new CheckResult(
                name: $this->getName(),
                status: CheckStatus::OK,
                message: 'SSH agent container is running',
            );
        }

        return new CheckResult(
            name: $this->getName(),
            status: CheckStatus::ERROR,
            message: 'SSH agent container is not running.',
            fixHint: 'Run: dde system:up',
        );
    }

    public function getPriority(): int
    {
        return 0;
    }

    public function requiresDocker(): bool
    {
        return true;
    }
}
