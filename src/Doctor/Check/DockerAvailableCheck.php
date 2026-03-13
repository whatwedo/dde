<?php

declare(strict_types=1);

namespace App\Doctor\Check;

use App\Doctor\CheckInterface;
use App\Doctor\CheckResult;
use App\Doctor\CheckStatus;
use App\Util\ProcessFactory;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

final class DockerAvailableCheck implements CheckInterface
{
    public function __construct(
        private readonly ProcessFactory $processFactory,
    ) {
    }

    public function getName(): string
    {
        return 'Docker Available';
    }

    public function run(): CheckResult
    {
        $process = $this->processFactory->create(['docker', 'info'], null, 10);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return new CheckResult(
                name: $this->getName(),
                status: CheckStatus::ERROR,
                message: 'Docker check timed out.',
                fixHint: 'Start Docker Desktop',
            );
        }

        if ($process->isSuccessful()) {
            return new CheckResult(
                name: $this->getName(),
                status: CheckStatus::OK,
                message: 'Docker is running',
            );
        }

        return new CheckResult(
            name: $this->getName(),
            status: CheckStatus::ERROR,
            message: 'Docker is not running. Start Docker Desktop or the Docker daemon.',
            fixHint: 'Start Docker Desktop',
        );
    }

    public function getPriority(): int
    {
        return 100;
    }

    public function requiresDocker(): bool
    {
        return false;
    }
}
