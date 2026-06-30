<?php

declare(strict_types=1);

namespace App\Util;

use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Optimistic-then-sudo wrapper for filesystem and subprocess operations that may
 * require elevated privileges. Each public method first attempts the operation as
 * the current user; on a failure it retries exactly once via `sudo` — unless the
 * current user is already real root, in which case sudo would be redundant (and
 * may not even be installed on minimal container images), so the original
 * failure is surfaced unmodified.
 *
 * On final failure the underlying exception is wrapped in a {@see \RuntimeException}
 * whose message names the failed command and either the direct failure (already-root
 * path) or both the direct and sudo failures (non-root path). The wording does NOT
 * claim that the operation "requires root privileges" because the optimistic attempt
 * may have failed for an unrelated reason (read-only filesystem, missing service
 * unit, invalid argument, etc.).
 */
readonly class PrivilegeEscalator
{
    public function __construct(
        private Filesystem $filesystem,
        private ProcessFactory $processFactory,
    ) {
    }

    /**
     * @throws \RuntimeException if both the optimistic mkdir and the sudo retry fail,
     *                           or if the optimistic mkdir fails while already running as root
     */
    public function ensureDir(string $path): void
    {
        try {
            $this->filesystem->mkdir($path);

            return;
        } catch (IOExceptionInterface $ioException) {
            // Fall through to sudo retry (or direct rethrow when already root).
        }

        $this->runWithSudoOrSurfaceFailure(['mkdir', '-p', $path], $ioException);
    }

    /**
     * @throws \RuntimeException if both the optimistic write and the sudo install retry fail,
     *                           or if the optimistic write fails while already running as root
     */
    public function writeFile(string $path, string $content, string $mode = '0644'): void
    {
        try {
            $this->filesystem->dumpFile($path, $content);
            $this->filesystem->chmod($path, (int) octdec($mode));

            return;
        } catch (IOExceptionInterface $ioException) {
            // Fall through to sudo retry via tempfile + install.
        }

        $tmp = TempFileUtil::createTempFile('dde-escalator-');

        try {
            if (file_put_contents($tmp, $content) === false) {
                throw new \RuntimeException(
                    sprintf('Failed to write tempfile "%s" for sudo install fallback.', $tmp),
                );
            }

            $this->runWithSudoOrSurfaceFailure(
                ['install', '-m', $mode, $tmp, $path],
                $ioException,
            );
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * @param list<string> $command
     *
     * @throws \RuntimeException if both the direct run and the sudo retry fail,
     *                           or if the direct run fails while already running as root
     */
    public function run(array $command): void
    {
        $direct = $this->processFactory->create($command);
        $direct->run();

        if ($direct->isSuccessful()) {
            return;
        }

        $this->runWithSudoOrSurfaceFailure($command, new ProcessFailedException($direct));
    }

    /**
     * Test seam: subclasses (typically anonymous in tests) can override this to
     * exercise both the already-root short-circuit and the non-root sudo branch
     * without depending on the host process's actual EUID.
     */
    protected function currentUserIsRoot(): bool
    {
        return function_exists('posix_geteuid') && posix_geteuid() === 0;
    }

    /**
     * Retries the operation via `sudo` unless the process is already running as
     * real root, in which case sudo would be either redundant or unavailable.
     *
     * @param list<string> $sudoCommand the command to retry under sudo (no leading "sudo")
     * @param \Throwable   $directFailure the optimistic-attempt failure being recovered
     *
     * @throws \RuntimeException always — wraps either the direct failure (when already root)
     *                           or the sudo failure (when sudo retry also failed)
     */
    private function runWithSudoOrSurfaceFailure(array $sudoCommand, \Throwable $directFailure): void
    {
        if ($this->currentUserIsRoot()) {
            throw new \RuntimeException(
                sprintf(
                    '"%s" failed while running as root: %s',
                    implode(' ', $sudoCommand),
                    $directFailure->getMessage(),
                ),
                0,
                $directFailure,
            );
        }

        try {
            $this->runWithSudo($sudoCommand);
        } catch (ProcessFailedException $processFailedException) {
            throw new \RuntimeException(
                sprintf(
                    '"%s" failed; sudo escalation also failed: %s',
                    implode(' ', $sudoCommand),
                    $processFailedException->getMessage(),
                ),
                0,
                $processFailedException,
            );
        }
    }

    /**
     * @param list<string> $command
     *
     * @throws ProcessFailedException if the sudo subprocess exits non-zero
     */
    private function runWithSudo(array $command): void
    {
        $sudo = $this->processFactory->create(['sudo', ...$command]);

        if (Process::isTtySupported() && \defined('STDIN') && @stream_isatty(\STDIN)) {
            $sudo->setTty(true);
        }

        $sudo->mustRun();
    }
}
