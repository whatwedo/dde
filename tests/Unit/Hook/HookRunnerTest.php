<?php

declare(strict_types=1);

namespace Tests\Unit\Hook;

use App\Exception\HookFailedException;
use App\Hook\HookRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class HookRunnerTest extends TestCase
{
    private string $tempDir;

    private string $hookDir;

    private HookRunner $hookRunner;

    public function testRunReturnsEarlyWhenDirectoryDoesNotExist(): void
    {
        $warnings = $this->hookRunner->run('/nonexistent/directory', $this->tempDir);

        $this->assertSame([], $warnings);
    }

    public function testRunReturnsEarlyWhenNoScriptsFound(): void
    {
        mkdir($this->hookDir, 0o755, true);

        $warnings = $this->hookRunner->run($this->hookDir, $this->tempDir);

        $this->assertSame([], $warnings);
    }

    public function testRunExecutesScriptsAlphabetically(): void
    {
        mkdir($this->hookDir, 0o755, true);

        $logFile = $this->tempDir.'/execution.log';

        // Create scripts in reverse alphabetical order
        $this->createScript($this->hookDir.'/02-second.sh', '#!/bin/bash'."\n".'echo "second" >> '.$logFile);
        $this->createScript($this->hookDir.'/01-first.sh', '#!/bin/bash'."\n".'echo "first" >> '.$logFile);
        $this->createScript($this->hookDir.'/03-third.sh', '#!/bin/bash'."\n".'echo "third" >> '.$logFile);

        $this->hookRunner->run($this->hookDir, $this->tempDir);

        $log = file_get_contents($logFile);
        $this->assertSame("first\nsecond\nthird\n", $log);
    }

    public function testRunSkipsNonExecutableScripts(): void
    {
        mkdir($this->hookDir, 0o755, true);

        $logFile = $this->tempDir.'/execution.log';

        $this->createScript($this->hookDir.'/01-runs.sh', '#!/bin/bash'."\n".'echo "ran" >> '.$logFile);

        // Create a non-executable script — should be skipped, NOT auto-fixed
        file_put_contents($this->hookDir.'/02-skipped.sh', '#!/bin/bash'."\n".'echo "skipped" >> '.$logFile);
        chmod($this->hookDir.'/02-skipped.sh', 0o644);

        $warnings = $this->hookRunner->run($this->hookDir, $this->tempDir);

        // Only the executable script ran
        $log = file_get_contents($logFile);
        $this->assertSame("ran\n", $log);

        // A warning was returned
        $this->assertCount(1, $warnings);
    }

    public function testRunSkipsNonExecutableScriptsAndReturnsWarnings(): void
    {
        mkdir($this->hookDir, 0o755, true);

        file_put_contents($this->hookDir.'/01-skipped.sh', '#!/bin/bash'."\n".'echo "skipped"');
        chmod($this->hookDir.'/01-skipped.sh', 0o644);

        $warnings = $this->hookRunner->run($this->hookDir, $this->tempDir);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('01-skipped.sh', $warnings[0]);
        $this->assertStringContainsString('not executable', $warnings[0]);
    }

    public function testRunReturnsEmptyWarningsOnSuccess(): void
    {
        mkdir($this->hookDir, 0o755, true);

        $this->createScript($this->hookDir.'/01-ok.sh', '#!/bin/bash'."\n".'exit 0');

        $warnings = $this->hookRunner->run($this->hookDir, $this->tempDir);

        $this->assertSame([], $warnings);
    }

    public function testRunThrowsHookFailedExceptionOnNonZeroExit(): void
    {
        mkdir($this->hookDir, 0o755, true);

        $this->createScript($this->hookDir.'/01-fail.sh', '#!/bin/bash'."\n".'exit 42');

        $this->expectException(HookFailedException::class);
        $this->expectExceptionMessageMatches('/01-fail\.sh/');

        $this->hookRunner->run($this->hookDir, $this->tempDir);
    }

    public function testRunUsesProjectDirAsWorkingDirectory(): void
    {
        mkdir($this->hookDir, 0o755, true);

        $this->createScript($this->hookDir.'/01-pwd.sh', '#!/bin/bash'."\n".'pwd > '.$this->tempDir.'/pwd.log');

        $this->hookRunner->run($this->hookDir, $this->tempDir);

        $pwd = trim((string) file_get_contents($this->tempDir.'/pwd.log'));
        // On macOS, /var is a symlink to /private/var, so realpath both sides
        $this->assertSame(realpath($this->tempDir), realpath($pwd));
    }

    public function testRunSkipsSymlinkOutsideHookDirectory(): void
    {
        mkdir($this->hookDir, 0o755, true);

        // Create a script outside the hook directory
        $outsideScript = $this->tempDir.'/outside.sh';
        file_put_contents($outsideScript, '#!/bin/bash'."\n".'exit 0');
        chmod($outsideScript, 0o755);

        // Create a symlink inside the hook dir pointing outside
        symlink($outsideScript, $this->hookDir.'/01-traversal.sh');

        $warnings = $this->hookRunner->run($this->hookDir, $this->tempDir);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('01-traversal.sh', $warnings[0]);
        $this->assertStringContainsString('resolves outside hook directory', $warnings[0]);
    }

    public function testRunIgnoresNonShFiles(): void
    {
        mkdir($this->hookDir, 0o755, true);

        $logFile = $this->tempDir.'/execution.log';

        $this->createScript($this->hookDir.'/01-runs.sh', '#!/bin/bash'."\n".'echo "ran" >> '.$logFile);

        // Create a non-.sh file that is executable
        $this->createScript($this->hookDir.'/02-ignored.txt', '#!/bin/bash'."\n".'echo "ignored" >> '.$logFile);

        $this->hookRunner->run($this->hookDir, $this->tempDir);

        $log = file_get_contents($logFile);
        $this->assertSame("ran\n", $log);
    }

    private function createScript(string $path, string $content): void
    {
        file_put_contents($path, $content);
        chmod($path, 0o755);
    }

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/dde_test_hookrunner_'.bin2hex(random_bytes(8));
        $this->hookDir = $this->tempDir.'/hooks';
        mkdir($this->tempDir, 0o755, true);

        $this->hookRunner = new HookRunner();
    }

    protected function tearDown(): void
    {
        $filesystem = new Filesystem();

        if (is_dir($this->tempDir)) {
            $filesystem->remove($this->tempDir);
        }
    }
}
