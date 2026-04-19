<?php

declare(strict_types=1);

namespace App\Manager;

use App\Config\WorktreeInfo;
use App\Util\IdentifierSanitizer;
use App\Util\ProcessFactory;

readonly class WorktreeManager
{
    public function __construct(
        private ProcessFactory $processFactory,
    ) {
    }

    public function detect(string $projectDir): ?WorktreeInfo
    {
        $process = $this->processFactory->create(['git', 'worktree', 'list', '--porcelain'], $projectDir, 10);

        try {
            $process->run();
        } catch (\Throwable) {
            return null;
        }

        if (! $process->isSuccessful()) {
            return null;
        }

        $output = $process->getOutput();

        if ($output === '') {
            return null;
        }

        $worktrees = $this->parseWorktreeOutput($output);

        if (count($worktrees) < 2) {
            return null;
        }

        $realProjectDir = realpath($projectDir);

        if ($realProjectDir === false) {
            return null;
        }

        $mainWorktree = $worktrees[0];

        if ($this->pathsEqual($realProjectDir, $mainWorktree['path'])) {
            return null;
        }

        foreach ($worktrees as $worktree) {
            if ($this->pathsEqual($realProjectDir, $worktree['path'])) {
                return new WorktreeInfo(
                    mainDirectory: $mainWorktree['path'],
                    worktreeDirectory: $realProjectDir,
                    branch: $worktree['branch'],
                    suffix: basename($realProjectDir),
                );
            }
        }

        return null;
    }

    public function resolveHostname(string $projectName, ?WorktreeInfo $worktreeInfo): string
    {
        if (! $worktreeInfo instanceof WorktreeInfo) {
            return $projectName.'.test';
        }

        $suffix = IdentifierSanitizer::forHostname($worktreeInfo->suffix, $projectName);

        return $projectName.'-'.$suffix.'.test';
    }

    public function resolveDatabaseName(
        string $baseDatabaseName,
        ?WorktreeInfo $worktreeInfo,
        string $projectName,
    ): string {
        if (! $worktreeInfo instanceof WorktreeInfo) {
            return $baseDatabaseName;
        }

        $suffix = IdentifierSanitizer::forDatabaseSuffix($worktreeInfo->suffix, $projectName);
        $combined = $baseDatabaseName.'_'.$suffix;

        if (strlen($combined) > 63) {
            $combined = rtrim(substr($combined, 0, 63), '_');
        }

        return $combined;
    }

    /**
     * @param array<int|string, mixed> $existingEnv
     *
     * @return array<string, string>
     */
    public function computeEnvironmentOverrides(
        array $existingEnv,
        string $projectName,
        WorktreeInfo $worktreeInfo,
    ): array {
        $projectHostname = $projectName.'.test';
        $worktreeHostname = $this->resolveHostname($projectName, $worktreeInfo);
        $dbSuffix = IdentifierSanitizer::forDatabaseSuffix($worktreeInfo->suffix, $projectName);

        $overrides = [];

        foreach ($existingEnv as $key => $value) {
            $extracted = $this->extractKeyValue($key, $value);

            if ($extracted === null) {
                continue;
            }

            [$envKey, $original] = $extracted;
            $new = $original;

            // 1. Hostname rewrite
            if (str_contains($new, $projectHostname)) {
                $new = str_replace($projectHostname, $worktreeHostname, $new);
            }

            // 2. DATABASE_URL path segment rewrite
            if ($envKey === 'DATABASE_URL') {
                $new = $this->rewriteDatabaseUrl($new, $dbSuffix);
            }

            if ($new !== $original) {
                $overrides[$envKey] = $new;
            }
        }

        return $overrides;
    }

    /**
     * @return list<array{path: string, branch: string}>
     */
    private function parseWorktreeOutput(string $output): array
    {
        $worktrees = [];
        $blocks = preg_split('/\n\n+/', trim($output));

        if ($blocks === false) {
            return [];
        }

        foreach ($blocks as $block) {
            $path = null;
            $branch = '';

            foreach (explode("\n", $block) as $line) {
                if (str_starts_with($line, 'worktree ')) {
                    $path = substr($line, 9);
                } elseif (str_starts_with($line, 'branch ')) {
                    $branchRef = substr($line, 7);
                    $branch = str_starts_with($branchRef, 'refs/heads/') ? substr($branchRef, 11) : $branchRef;
                }
            }

            if ($path !== null) {
                $worktrees[] = [
                    'path' => $path,
                    'branch' => $branch,
                ];
            }
        }

        return $worktrees;
    }

    private function pathsEqual(string $a, string $b): bool
    {
        $realA = realpath($a);
        $realB = realpath($b);

        if ($realA === false || $realB === false) {
            return rtrim($a, '/') === rtrim($b, '/');
        }

        return $realA === $realB;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function extractKeyValue(int|string $key, mixed $value): ?array
    {
        if (is_int($key)) {
            if (! is_string($value)) {
                return null;
            }

            $eq = strpos($value, '=');

            if ($eq === false) {
                return null;
            }

            return [substr($value, 0, $eq), substr($value, $eq + 1)];
        }

        if (! is_string($value)) {
            return null;
        }

        return [$key, $value];
    }

    private function rewriteDatabaseUrl(string $value, string $dbSuffix): string
    {
        $pattern = '~^([a-z][a-z0-9+.-]*://[^/?#]+/)([^?#]*)(.*)$~i';

        if (preg_match($pattern, $value, $m) !== 1 || $m[2] === '') {
            return $value;
        }

        $newDbName = $m[2].'_'.$dbSuffix;

        if (strlen($newDbName) > 63) {
            $newDbName = rtrim(substr($newDbName, 0, 63), '_');
        }

        return $m[1].$newDbName.$m[3];
    }
}
