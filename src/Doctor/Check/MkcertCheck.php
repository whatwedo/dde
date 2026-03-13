<?php

declare(strict_types=1);

namespace App\Doctor\Check;

use App\Doctor\CheckInterface;
use App\Doctor\CheckResult;
use App\Doctor\CheckStatus;
use App\Util\ProcessFactory;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

final class MkcertCheck implements CheckInterface
{
    public function __construct(
        private readonly ProcessFactory $processFactory,
    ) {
    }

    public function getName(): string
    {
        return 'mkcert';
    }

    public function run(): CheckResult
    {
        $process = $this->processFactory->create(['mkcert', '-CAROOT'], null, 10);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return new CheckResult(
                name: $this->getName(),
                status: CheckStatus::ERROR,
                message: 'mkcert check timed out.',
                fixHint: 'brew install mkcert (macOS) or see https://github.com/FiloSottile/mkcert',
            );
        }

        if ($process->isSuccessful()) {
            $path = trim($process->getOutput());

            return new CheckResult(
                name: $this->getName(),
                status: CheckStatus::OK,
                message: sprintf('mkcert installed, CAROOT: %s', $path),
            );
        }

        return new CheckResult(
            name: $this->getName(),
            status: CheckStatus::ERROR,
            message: 'mkcert not installed.',
            fixHint: 'brew install mkcert (macOS) or see https://github.com/FiloSottile/mkcert',
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
