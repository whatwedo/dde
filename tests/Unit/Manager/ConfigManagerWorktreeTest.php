<?php

declare(strict_types=1);

namespace Tests\Unit\Manager;

use App\Config\WorktreeInfo;
use App\Database\DatabaseAdapterRegistry;
use App\Manager\ConfigManager;
use App\Service\ServiceRegistry;
use App\Util\ProcessFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ConfigManagerWorktreeTest extends TestCase
{
    private string|false $originalCwd;

    /**
     * @var list<string>
     */
    private array $cleanupDirs = [];

    // --- sanitizeWorktreeSuffix tests ---

    public function testSanitizeSubdomainStripsProjectPrefix(): void
    {
        $manager = $this->createManager();

        $this->assertSame('feature-x', $manager->sanitizeWorktreeSuffix('beispiel-feature-x', 'beispiel'));
    }

    public function testSanitizeSubdomainLowercases(): void
    {
        $manager = $this->createManager();

        $this->assertSame('proj-123', $manager->sanitizeWorktreeSuffix('beispiel-PROJ-123', 'beispiel'));
    }

    public function testSanitizeSubdomainReplacesSpecialChars(): void
    {
        $manager = $this->createManager();

        $this->assertSame('special-chars', $manager->sanitizeWorktreeSuffix('beispiel-special_chars!@#', 'beispiel'));
    }

    public function testSanitizeSubdomainEmptySuffixFallback(): void
    {
        $manager = $this->createManager();

        $this->assertSame('worktree', $manager->sanitizeWorktreeSuffix('beispiel-', 'beispiel'));
    }

    public function testSanitizeSubdomainDirNameSameAsProject(): void
    {
        $manager = $this->createManager();

        $this->assertSame('worktree', $manager->sanitizeWorktreeSuffix('beispiel', 'beispiel'));
    }

    public function testSanitizeSubdomainWithoutProjectPrefix(): void
    {
        $manager = $this->createManager();

        $this->assertSame('other-dir', $manager->sanitizeWorktreeSuffix('other-dir', 'beispiel'));
    }

    public function testSanitizeSubdomainTruncatesLongNames(): void
    {
        $manager = $this->createManager();

        $longSuffix = str_repeat('a', 100);
        $result = $manager->sanitizeWorktreeSuffix('beispiel-'.$longSuffix, 'beispiel');

        $this->assertLessThanOrEqual(63, strlen($result));
        $this->assertStringStartsNotWith('-', $result);
    }

    public function testSanitizeSubdomainCaseInsensitivePrefixRemoval(): void
    {
        $manager = $this->createManager();

        $this->assertSame('feature', $manager->sanitizeWorktreeSuffix('Beispiel-feature', 'beispiel'));
    }

    public function testSanitizeSubdomainCollapsesConsecutiveHyphens(): void
    {
        $manager = $this->createManager();

        $this->assertSame('foo-bar', $manager->sanitizeWorktreeSuffix('beispiel-foo---bar', 'beispiel'));
    }

    public function testSanitizeSubdomainTrimsTrailingHyphensAfterTruncation(): void
    {
        $manager = $this->createManager();

        // Create a string that ends with hyphens after truncation at 63 chars
        $suffix = str_repeat('a', 60).'---bbb';
        $result = $manager->sanitizeWorktreeSuffix($suffix, 'nonmatch');

        $this->assertLessThanOrEqual(63, strlen($result));
        $this->assertFalse(str_ends_with($result, '-'));
    }

    public function testSanitizeSubdomainHandlesUnicode(): void
    {
        $manager = $this->createManager();

        $this->assertSame('uberarbeitung', $manager->sanitizeWorktreeSuffix('beispiel-überarbeitung', 'beispiel'));
    }

    public function testSanitizeSubdomainPureSpecialCharsFallback(): void
    {
        $manager = $this->createManager();

        $this->assertSame('worktree', $manager->sanitizeWorktreeSuffix('beispiel-!!!', 'beispiel'));
    }

    // --- resolveProjectHostname tests ---

    public function testResolveProjectHostnameMainCheckout(): void
    {
        $manager = $this->createManager();

        $this->assertSame('beispiel.test', $manager->resolveProjectHostname('beispiel', null));
    }

    public function testResolveProjectHostnameWorktree(): void
    {
        $manager = $this->createManager();
        $worktreeInfo = new \App\Config\WorktreeInfo(
            mainDirectory: '/main',
            worktreeDirectory: '/wt',
            branch: 'feature-x',
            suffix: 'beispiel-feature-x',
        );

        $this->assertSame('beispiel-feature-x.test', $manager->resolveProjectHostname('beispiel', $worktreeInfo));
    }

    // --- detectWorktree tests ---

    #[Group('e2e')]
    public function testDetectWorktreeReturnsNullForMainCheckout(): void
    {
        $mainDir = $this->createGitRepo();

        $manager = $this->createManager();
        $result = $manager->detectWorktree($mainDir);

        $this->assertNull($result);
    }

    #[Group('e2e')]
    public function testDetectWorktreeReturnsInfoForWorktree(): void
    {
        $mainDir = $this->createGitRepo();
        $worktreeDir = $this->createWorktree($mainDir, 'feature-branch');

        $manager = $this->createManager();
        $result = $manager->detectWorktree($worktreeDir);

        $this->assertInstanceOf(WorktreeInfo::class, $result);
        $this->assertSame(realpath($mainDir), $result->mainDirectory);
        $this->assertSame(realpath($worktreeDir), $result->worktreeDirectory);
        $this->assertSame('feature-branch', $result->branch);
        $this->assertSame(basename($worktreeDir), $result->suffix);
    }

    #[Group('e2e')]
    public function testDetectWorktreeReturnsNullForNonGitDirectory(): void
    {
        $tmpDir = $this->createTempDir();

        $manager = $this->createManager();
        $result = $manager->detectWorktree($tmpDir);

        $this->assertNull($result);
    }

    // --- helper methods ---

    private function createManager(): ConfigManager
    {
        return new ConfigManager(configDir: '/tmp/nonexistent-dde-config', serviceRegistry: new ServiceRegistry([], new DatabaseAdapterRegistry([])), processFactory: new ProcessFactory());
    }

    private function createTempDir(): string
    {
        $baseDir = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
        $dir = $baseDir.'/dde_wt_test_'.bin2hex(random_bytes(8));
        mkdir($dir, 0o755, true);
        $this->cleanupDirs[] = $dir;

        return $dir;
    }

    private function createGitRepo(): string
    {
        $dir = $this->createTempDir();

        $this->runProcess(['git', 'init'], $dir);
        $this->runProcess(['git', 'config', 'user.email', 'test@example.com'], $dir);
        $this->runProcess(['git', 'config', 'user.name', 'Test'], $dir);

        file_put_contents($dir.'/README.md', 'test');
        $this->runProcess(['git', 'add', '.'], $dir);
        $this->runProcess(['git', 'commit', '-m', 'initial commit', '--no-gpg-sign'], $dir);

        return $dir;
    }

    private function createWorktree(string $mainDir, string $branchName): string
    {
        $baseDir = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
        $worktreeDir = $baseDir.'/dde_wt_test_'.bin2hex(random_bytes(8));
        $this->cleanupDirs[] = $worktreeDir;

        $this->runProcess(['git', 'worktree', 'add', '-b', $branchName, $worktreeDir], $mainDir);

        return $worktreeDir;
    }

    /**
     * @param list<string> $command
     */
    private function runProcess(array $command, string $cwd): void
    {
        $process = new Process($command, $cwd);
        $process->mustRun();
    }

    private function removeDirectory(string $dir): void
    {
        // First, remove any git worktrees properly before deleting
        $gitDir = $dir.'/.git';
        if (is_file($gitDir)) {
            // This is a worktree - find the main repo and remove the worktree reference
            $content = file_get_contents($gitDir);
            if ($content !== false && preg_match('/gitdir: (.+)/', $content, $matches)) {
                $worktreeGitDir = dirname(trim($matches[1]));
                $mainGitDir = dirname($worktreeGitDir);
                if (is_dir($mainGitDir)) {
                    $process = new Process(['git', 'worktree', 'remove', '--force', $dir], dirname($mainGitDir));
                    $process->run();

                    return;
                }
            }
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }

        @rmdir($dir);
    }

    protected function setUp(): void
    {
        $cwd = getcwd();
        $this->originalCwd = $cwd;
    }

    protected function tearDown(): void
    {
        if ($this->originalCwd !== false) {
            chdir($this->originalCwd);
        }

        // Clean up temp directories in reverse order
        foreach (array_reverse($this->cleanupDirs) as $dir) {
            if (is_dir($dir)) {
                $this->removeDirectory($dir);
            }
        }

        $this->cleanupDirs = [];
    }
}
