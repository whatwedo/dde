<?php

declare(strict_types=1);

namespace App\Doctor\Check;

use App\Doctor\CheckInterface;
use App\Doctor\CheckResult;
use App\Doctor\CheckStatus;
use App\Util\ProcessFactory;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

final class DnsResolutionCheck implements CheckInterface
{
    public function __construct(
        private readonly ProcessFactory $processFactory,
    ) {
    }

    public function getName(): string
    {
        return 'DNS Resolution';
    }

    public function run(): CheckResult
    {
        $process = $this->processFactory->create(['dig', '+short', 'beispiel.test', '@127.0.0.1'], null, 10);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return new CheckResult(
                name: $this->getName(),
                status: CheckStatus::WARNING,
                message: 'DNS resolution timed out.',
                fixHint: 'Run: dde system:up',
            );
        }

        if ($process->isSuccessful() && trim($process->getOutput()) === '127.0.0.1') {
            return new CheckResult(
                name: $this->getName(),
                status: CheckStatus::OK,
                message: 'DNS resolution working (beispiel.test → 127.0.0.1)',
            );
        }

        return new CheckResult(
            name: $this->getName(),
            status: CheckStatus::WARNING,
            message: 'DNS resolution not working.',
            fixHint: 'Run: dde system:up',
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
