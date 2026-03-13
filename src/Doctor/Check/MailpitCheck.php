<?php

declare(strict_types=1);

namespace App\Doctor\Check;

use App\Doctor\CheckInterface;
use App\Doctor\CheckResult;
use App\Doctor\CheckStatus;
use App\Manager\DockerManager;

final class MailpitCheck implements CheckInterface
{
    public function __construct(
        private readonly DockerManager $dockerManager,
    ) {
    }

    public function getName(): string
    {
        return 'Mailpit';
    }

    public function run(): CheckResult
    {
        if ($this->dockerManager->isContainerRunning('dde-mailpit')) {
            return new CheckResult(
                name: $this->getName(),
                status: CheckStatus::OK,
                message: 'Mailpit container is running',
            );
        }

        return new CheckResult(
            name: $this->getName(),
            status: CheckStatus::WARNING,
            message: 'Mailpit container is not running.',
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
