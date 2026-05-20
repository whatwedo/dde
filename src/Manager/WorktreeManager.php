<?php

declare(strict_types=1);

namespace App\Manager;

use App\Config\WorktreeInfo;
use App\Util\ComposeEnvEntryParser;
use App\Util\IdentifierSanitizer;
use App\Util\ProcessFactory;

readonly class WorktreeManager
{
    /**
     * Maps DB-URL schemes to the dde database service name backing them. Mirrors
     * the scheme list used by `ProjectInitAdaptationManager::proposeDatabaseUrlRule()`
     * — both must move in lockstep when new DB service types are added.
     */
    private const DATABASE_URL_SCHEMES = [
        'mysql' => 'mariadb',
        'mariadb' => 'mariadb',
        'postgres' => 'postgres',
        'postgresql' => 'postgres',
        'pgsql' => 'postgres',
    ];

    public function __construct(
        private ProcessFactory $processFactory,
    ) {
    }

    /**
     * Detects which git worktree the user is physically in.
     *
     * `$projectDir` is where dde found `.dde/config.yml` (via walk-up from CWD)
     * — used here only as the working directory for `git worktree list`.
     * `$cwd` is the directory we should match against the worktree list to
     * decide which worktree we are in. They are NOT the same when the
     * worktree shares its parent's `.dde/` (e.g. a worktree nested inside
     * the main checkout under `.claude/worktrees/<name>` or any worktree
     * whose branch hasn't committed `.dde/` yet) — walk-up resolves to the
     * main and `$projectDir` lies about which worktree we are in. Defaults
     * to `getcwd()` so production callers do not need to think about it.
     */
    public function detect(string $projectDir, ?string $cwd = null): ?WorktreeInfo
    {
        if ($cwd === null) {
            $resolved = getcwd();
            $cwd = $resolved !== false ? $resolved : null;
        }

        if ($cwd === null) {
            return null;
        }

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

        $realCwd = realpath($cwd);

        if ($realCwd === false) {
            return null;
        }

        // Longest-prefix match handles worktrees nested inside another
        // worktree's directory: the inner one wins because its real path is
        // a longer prefix of CWD than the outer one.
        $currentWorktree = $this->findWorktreeContaining($realCwd, $worktrees);

        if ($currentWorktree === null) {
            return null;
        }

        $mainWorktree = $worktrees[0];

        if ($this->pathsEqual($currentWorktree['path'], $mainWorktree['path'])) {
            return null;
        }

        return new WorktreeInfo(
            mainDirectory: $mainWorktree['path'],
            worktreeDirectory: $currentWorktree['path'],
            branch: $currentWorktree['branch'],
            suffix: basename($currentWorktree['path']),
        );
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
     * Rewrites a hostname from the main checkout to its worktree variant.
     * Replaces the substring `<projectName>.test` with `<worktreeProjectName>.test`,
     * which preserves any subdomain prefix (e.g. `preview.beispiel.test` ->
     * `preview.beispiel-feature-x.test`). Bare project hostnames are rewritten too.
     * Unrelated hosts (no `.<project>.test` suffix and not equal to `<project>.test`)
     * pass through unchanged so external integrations declared on the same compose
     * service are never mangled.
     */
    public function rewriteHostname(string $hostname, string $projectName, WorktreeInfo $worktreeInfo): string
    {
        $projectHostname = $projectName.'.test';
        $worktreeHostname = $this->resolveHostname($projectName, $worktreeInfo);

        if ($hostname === $projectHostname) {
            return $worktreeHostname;
        }

        if (str_ends_with($hostname, '.'.$projectHostname)) {
            return str_replace($projectHostname, $worktreeHostname, $hostname);
        }

        return $hostname;
    }

    /**
     * @param array<int|string, mixed> $existingEnv
     * @param list<string>             $configuredServiceNames service names from `ProjectConfig::$services` —
     *                                                         only env values whose URL scheme corresponds to
     *                                                         a configured DB service get their DB name rewritten
     *
     * @return array<string, string>
     */
    public function computeEnvironmentOverrides(
        array $existingEnv,
        string $projectName,
        WorktreeInfo $worktreeInfo,
        array $configuredServiceNames,
    ): array {
        $projectHostname = $projectName.'.test';
        $worktreeHostname = $this->resolveHostname($projectName, $worktreeInfo);
        $dbSuffix = IdentifierSanitizer::forDatabaseSuffix($worktreeInfo->suffix, $projectName);

        $overrides = [];

        foreach ($existingEnv as $key => $value) {
            $extracted = ComposeEnvEntryParser::extract($key, $value);

            if ($extracted === null) {
                continue;
            }

            [$envKey, $original] = $extracted;
            $new = $original;

            // 1. Hostname rewrite
            if (str_contains($new, $projectHostname)) {
                $new = str_replace($projectHostname, $worktreeHostname, $new);
            }

            // 2. DB URL path segment rewrite (scheme-driven, not key-driven, so
            //    projects with multiple DB connections — e.g. `DATABASE_URL` +
            //    `GUACAMOLE_DATABASE_URL` — all get rewritten consistently)
            if ($this->shouldRewriteAsDatabaseUrl($new, $configuredServiceNames)) {
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

    /**
     * Returns the worktree entry whose real path is the longest prefix of
     * `$realPath`. Falls back to a string comparison when an entry's real
     * path can't be resolved (matches the same `realpath`-with-string-fallback
     * policy used by `pathsEqual`). Returns `null` when no entry contains the
     * path at all.
     *
     * @param list<array{path: string, branch: string}> $worktrees
     *
     * @return array{path: string, branch: string}|null
     */
    private function findWorktreeContaining(string $realPath, array $worktrees): ?array
    {
        $best = null;
        $bestLength = -1;

        foreach ($worktrees as $worktree) {
            $resolved = realpath($worktree['path']);
            $candidate = $resolved !== false ? $resolved : rtrim($worktree['path'], '/');

            if ($candidate === '') {
                continue;
            }

            if ($realPath !== $candidate && ! str_starts_with($realPath, $candidate.'/')) {
                continue;
            }

            $length = strlen($candidate);

            if ($length > $bestLength) {
                $best = [
                    'path' => $candidate,
                    'branch' => $worktree['branch'],
                ];
                $bestLength = $length;
            }
        }

        return $best;
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
     * @param list<string> $configuredServiceNames
     */
    private function shouldRewriteAsDatabaseUrl(string $value, array $configuredServiceNames): bool
    {
        if (preg_match('~^([a-z][a-z0-9+.-]*)://~i', $value, $m) !== 1) {
            return false;
        }

        $scheme = strtolower($m[1]);
        $serviceType = self::DATABASE_URL_SCHEMES[$scheme] ?? null;

        if ($serviceType === null) {
            return false;
        }

        return in_array($serviceType, $configuredServiceNames, true);
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
