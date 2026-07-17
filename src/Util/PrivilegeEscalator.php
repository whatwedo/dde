<?php

declare(strict_types=1);

namespace App\Util;

use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Optimistic-then-sudo wrapper for host-level operations that may require root
 * (e.g. writes below /etc during system:install).
 *
 * Each public method first attempts the operation as the current user. Only on
 * failure does it announce the escalation and retry exactly once through sudo
 * (with TTY forwarding, so an interactive password prompt reaches the user).
 * When dde already runs as real root, no sudo retry is attempted — sudo is often
 * absent on minimal container images — and the original failure is surfaced.
 *
 * dde must never be invoked as `sudo dde …`: that would create root-owned files
 * under $DDE_CONFIG_DIR/$DDE_DATA_DIR and break every subsequent unprivileged
 * run. bin/console rejects that invocation up-front.
 */
class PrivilegeEscalator
{
    /**
     * @param (\Closure(string): void)|null $announcer receives the escalation announcement; defaults to writing to STDERR
     * @param int|null $effectiveUserId overrides the detected effective UID (tests only)
     */
    public function __construct(
        private readonly Filesystem $filesystem = new Filesystem(),
        private readonly ProcessFactory $processFactory = new ProcessFactory(),
        private readonly ?\Closure $announcer = null,
        private readonly ?int $effectiveUserId = null,
    ) {
    }

    /**
     * @throws \RuntimeException if the mkdir fails both directly and via sudo
     */
    public function ensureDir(string $path): void
    {
        try {
            $this->filesystem->mkdir($path);

            return;
        } catch (IOExceptionInterface $ioException) {
            $this->escalate(['mkdir', '-p', $path], sprintf('Creating directory %s', $path), $ioException);
        }
    }

    /**
     * @throws \RuntimeException if the write fails both directly and via sudo
     */
    public function writeFile(string $path, string $content, string $mode = '0644'): void
    {
        try {
            $this->filesystem->dumpFile($path, $content);
            $this->filesystem->chmod($path, (int) octdec($mode));
        } catch (IOExceptionInterface $ioException) {
            $this->writeFileViaEscalation($path, $content, $mode, $ioException);
        }
    }

    /**
     * @param list<string> $command
     *
     * @throws \RuntimeException if the command fails both directly and via sudo
     */
    public function run(array $command): void
    {
        $process = $this->processFactory->create($command);
        $process->run();

        if ($process->isSuccessful()) {
            return;
        }

        $this->escalate(
            $command,
            sprintf('Running "%s"', implode(' ', $command)),
            new \RuntimeException($this->describeFailure($process)),
        );
    }

    private function writeFileViaEscalation(string $path, string $content, string $mode, IOExceptionInterface $directFailure): void
    {
        $tempFile = TempFileUtil::createTempFile('dde-escalate-');

        try {
            if (file_put_contents($tempFile, $content) === false) {
                throw new \RuntimeException(sprintf('Failed to write temporary file "%s".', $tempFile));
            }

            // `install` copies the tempfile to the target with the final mode in one
            // step and keeps the payload out of the elevated argv.
            $this->escalate(['install', '-m', $mode, $tempFile, $path], sprintf('Writing %s', $path), $directFailure);
        } finally {
            @unlink($tempFile);
        }
    }

    /**
     * @param list<string> $command
     *
     * @throws \RuntimeException if the command fails while running as root, or if the sudo escalation fails
     */
    private function escalate(array $command, string $operation, \Throwable $directFailure): void
    {
        if ($this->isRoot()) {
            throw new \RuntimeException(sprintf('%s failed while running as root: %s', $operation, $directFailure->getMessage()), 0, $directFailure);
        }

        $this->announce(sprintf('%s requires root — you may be asked for your sudo password.', $operation));

        $process = $this->processFactory->create(['sudo', ...$command]);

        if (Process::isTtySupported() && defined('STDIN') && stream_isatty(STDIN)) {
            $process->setTty(true);
        }

        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(sprintf('%s requires root, and sudo escalation failed: %s', $operation, $this->describeFailure($process)), 0, $directFailure);
        }
    }

    private function describeFailure(Process $process): string
    {
        $errorOutput = trim($process->getErrorOutput());

        return $errorOutput !== '' ? $errorOutput : sprintf('exit code %d', $process->getExitCode() ?? -1);
    }

    private function announce(string $message): void
    {
        ($this->announcer ?? static function (string $message): void {
            fwrite(STDERR, $message.PHP_EOL);
        })($message);
    }

    private function isRoot(): bool
    {
        $effectiveUserId = $this->effectiveUserId ?? (function_exists('posix_geteuid') ? posix_geteuid() : -1);

        return $effectiveUserId === 0;
    }
}
