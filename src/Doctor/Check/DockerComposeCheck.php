<?php

declare(strict_types=1);

namespace App\Doctor\Check;

use App\Doctor\CheckInterface;
use App\Doctor\CheckResult;
use App\Doctor\CheckStatus;
use App\Util\ProcessFactory;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

final class DockerComposeCheck implements CheckInterface
{
    public function __construct(
        private readonly ProcessFactory $processFactory,
    ) {
    }

    public function getName(): string
    {
        return 'Docker Compose';
    }

    public function run(): CheckResult
    {
        $process = $this->processFactory->create(['docker', 'compose', 'version'], null, 10);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return new CheckResult(
                name: $this->getName(),
                status: CheckStatus::ERROR,
                message: 'Docker Compose check timed out.',
                fixHint: 'Upgrade to Docker Compose v2',
            );
        }

        if ($process->isSuccessful()) {
            $version = trim($process->getOutput());

            return new CheckResult(
                name: $this->getName(),
                status: CheckStatus::OK,
                message: $version,
            );
        }

        return new CheckResult(
            name: $this->getName(),
            status: CheckStatus::ERROR,
            message: 'Docker Compose not available.',
            fixHint: 'Install Docker Desktop or Docker Compose plugin',
        );
    }

    public function getPriority(): int
    {
        return 0;
    }

    public function requiresDocker(): bool
    {
        return false;
    }
}
