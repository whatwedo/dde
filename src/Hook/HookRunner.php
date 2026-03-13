<?php

declare(strict_types=1);

namespace App\Hook;

use App\Exception\HookFailedException;
use App\Util\ProcessFactory;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

readonly class HookRunner
{
    public function __construct(
        private Filesystem $filesystem = new Filesystem(),
        private ProcessFactory $processFactory = new ProcessFactory(),
    ) {
    }

    /**
     * Execute all scripts in the given hook directory.
     * Scripts are executed alphabetically.
     * Non-executable scripts are skipped and a warning is returned.
     * Exit code != 0 throws HookFailedException.
     *
     * @return string[] list of warning messages
     *
     * @throws HookFailedException
     */
    public function run(string $hookDir, string $projectDir): array
    {
        if (!$this->filesystem->exists($hookDir)) {
            return [];
        }

        $finder = Finder::create()
            ->in($hookDir)
            ->files()
            ->name('*.sh')
            ->sortByName();

        if (!$finder->hasResults()) {
            return [];
        }

        $realHookDir = realpath($hookDir);
        if ($realHookDir === false) {
            return [];
        }

        $warnings = [];

        foreach ($finder as $file) {
            $script = $file->getPathname();

            $realScript = realpath($script);
            if ($realScript === false || !str_starts_with($realScript, $realHookDir)) {
                $warnings[] = sprintf('Hook script "%s" resolves outside hook directory, skipped.', $script);
                continue;
            }

            if (!is_executable($script)) {
                $warnings[] = sprintf('Hook script "%s" is not executable and was skipped. Run: chmod +x %s', $script, $script);
                continue;
            }

            $process = $this->processFactory->create([$script], $projectDir, 300);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new HookFailedException($script, $process->getExitCode() ?? 1, $process->getErrorOutput());
            }
        }

        return $warnings;
    }
}
