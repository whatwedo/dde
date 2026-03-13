<?php

declare(strict_types=1);

namespace App\Doctor\Check;

use App\Doctor\CheckInterface;
use App\Doctor\CheckResult;
use App\Doctor\CheckStatus;
use App\Manager\DockerManager;

final class NetworkCheck implements CheckInterface
{
    public function __construct(
        private readonly DockerManager $dockerManager,
    ) {
    }

    public function getName(): string
    {
        return 'Docker Network';
    }

    public function run(): CheckResult
    {
        if ($this->dockerManager->networkExists('dde')) {
            return new CheckResult(
                name: $this->getName(),
                status: CheckStatus::OK,
                message: "Docker network 'dde' exists",
            );
        }

        return new CheckResult(
            name: $this->getName(),
            status: CheckStatus::ERROR,
            message: "Docker network 'dde' not found.",
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
