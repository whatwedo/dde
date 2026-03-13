<?php

declare(strict_types=1);

namespace App\Doctor\Check;

use App\Doctor\CheckInterface;
use App\Doctor\CheckResult;
use App\Doctor\CheckStatus;
use App\Util\ProcessFactory;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

final class RootCaTrustedCheck implements CheckInterface
{
    public function __construct(
        private readonly ProcessFactory $processFactory,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function getName(): string
    {
        return 'Root CA Trusted';
    }

    public function run(): CheckResult
    {
        $process = $this->processFactory->create(['mkcert', '-CAROOT'], null, 10);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return new CheckResult(
                name: $this->getName(),
                status: CheckStatus::WARNING,
                message: 'Root CA check timed out.',
                fixHint: 'Run: mkcert -install',
            );
        }

        if (!$process->isSuccessful()) {
            return new CheckResult(
                name: $this->getName(),
                status: CheckStatus::WARNING,
                message: 'Could not determine mkcert CA root.',
                fixHint: 'Run: mkcert -install',
            );
        }

        $caRoot = trim($process->getOutput());

        if (!$this->filesystem->exists($caRoot.'/rootCA.pem')) {
            return new CheckResult(
                name: $this->getName(),
                status: CheckStatus::WARNING,
                message: 'Root CA not found.',
                fixHint: 'Run: mkcert -install',
            );
        }

        if (PHP_OS_FAMILY === 'Darwin') {
            $verify = $this->processFactory->create(['security', 'verify-cert', '-c', $caRoot.'/rootCA.pem'], null, 10);

            try {
                $verify->run();
            } catch (ProcessTimedOutException) {
                return new CheckResult(
                    name: $this->getName(),
                    status: CheckStatus::WARNING,
                    message: 'Root CA trust check timed out.',
                    fixHint: 'Run: mkcert -install',
                );
            }

            if (!$verify->isSuccessful()) {
                return new CheckResult(
                    name: $this->getName(),
                    status: CheckStatus::WARNING,
                    message: 'Root CA exists but is not trusted by the system.',
                    fixHint: 'Run: mkcert -install',
                );
            }
        }

        return new CheckResult(
            name: $this->getName(),
            status: CheckStatus::OK,
            message: 'Root CA is installed and trusted.',
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
