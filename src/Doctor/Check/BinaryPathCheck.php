<?php

declare(strict_types=1);

namespace App\Doctor\Check;

use App\Doctor\CheckInterface;
use App\Doctor\CheckResult;
use App\Doctor\CheckStatus;
use App\Util\ProcessFactory;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

final class BinaryPathCheck implements CheckInterface
{
    public function __construct(
        private readonly ProcessFactory $processFactory,
        private readonly ?string $homeDir = null,
    ) {
    }

    public function getName(): string
    {
        return 'Binary Path';
    }

    public function run(): CheckResult
    {
        $process = $this->processFactory->create(['which', 'dde'], null, 10);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return new CheckResult(
                name: $this->getName(),
                status: CheckStatus::WARNING,
                message: 'Binary path check timed out.',
            );
        }

        $inPath = $process->isSuccessful() && trim($process->getOutput()) !== '';

        $homeDir = $this->homeDir ?? getenv('HOME');
        $binaryExists = is_string($homeDir) && $homeDir !== '' && is_file($homeDir.'/bin/dde');

        if ($inPath) {
            return new CheckResult(
                name: $this->getName(),
                status: CheckStatus::OK,
                message: 'dde binary found in PATH',
            );
        }

        if ($binaryExists) {
            return new CheckResult(
                name: $this->getName(),
                status: CheckStatus::WARNING,
                message: 'dde binary exists but not in PATH. Add ~/bin to your PATH.',
                fixHint: 'Add ~/bin to your PATH',
            );
        }

        return new CheckResult(
            name: $this->getName(),
            status: CheckStatus::ERROR,
            message: 'dde binary not found. Run the installer.',
            fixHint: 'Run the installer.',
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
