<?php

declare(strict_types=1);

namespace App\Tests\Integration\Hook;

use App\Exception\HookFailedException;
use App\Hook\HookRunner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class HookRunnerIntegrationTest extends TestCase
{
    private string $tempDir;

    private Filesystem $filesystem;

    private HookRunner $runner;

    public function testRunExecutesScriptsAlphabetically(): void
    {
        $hookDir = $this->tempDir.'/hooks';
        $this->filesystem->mkdir($hookDir);

        $outputFile = $this->tempDir.'/order.txt';

        $secondScript = $hookDir.'/02-second.sh';
        $this->filesystem->dumpFile($secondScript, "#!/bin/bash\necho second >> {$outputFile}\n");
        chmod($secondScript, 0755);

        $firstScript = $hookDir.'/01-first.sh';
        $this->filesystem->dumpFile($firstScript, "#!/bin/bash\necho first >> {$outputFile}\n");
        chmod($firstScript, 0755);

        $warnings = $this->runner->run($hookDir, $this->tempDir);

        self::assertSame([], $warnings);
        self::assertFileExists($outputFile);

        $lines = array_filter(explode("\n", trim((string) file_get_contents($outputFile))));
        $lines = array_values($lines);

        self::assertSame('first', $lines[0]);
        self::assertSame('second', $lines[1]);
    }

    public function testRunPassesProjectDirAsWorkingDirectory(): void
    {
        $hookDir = $this->tempDir.'/hooks';
        $projectDir = $this->tempDir.'/project';
        $this->filesystem->mkdir($hookDir);
        $this->filesystem->mkdir($projectDir);

        $outputFile = $this->tempDir.'/cwd.txt';
        $script = $hookDir.'/check-cwd.sh';
        $this->filesystem->dumpFile($script, "#!/bin/bash\npwd > {$outputFile}\n");
        chmod($script, 0755);

        $warnings = $this->runner->run($hookDir, $projectDir);

        self::assertSame([], $warnings);
        self::assertFileExists($outputFile);

        $actualCwd = trim((string) file_get_contents($outputFile));
        // Resolve symlinks for comparison (macOS /tmp is a symlink to /private/tmp)
        self::assertSame(realpath($projectDir), realpath($actualCwd));
    }

    public function testRunReturnsWarningForNonExecutableScript(): void
    {
        $hookDir = $this->tempDir.'/hooks';
        $this->filesystem->mkdir($hookDir);

        $script = $hookDir.'/not-executable.sh';
        $this->filesystem->dumpFile($script, "#!/bin/bash\necho ran\n");
        // Intentionally not setting executable bit

        $warnings = $this->runner->run($hookDir, $this->tempDir);

        self::assertCount(1, $warnings);
        self::assertStringContainsString($script, $warnings[0]);
    }

    public function testRunThrowsHookFailedExceptionOnNonZeroExit(): void
    {
        $hookDir = $this->tempDir.'/hooks';
        $this->filesystem->mkdir($hookDir);

        $script = $hookDir.'/failing.sh';
        $this->filesystem->dumpFile($script, "#!/bin/bash\nexit 1\n");
        chmod($script, 0755);

        $this->expectException(HookFailedException::class);

        try {
            $this->runner->run($hookDir, $this->tempDir);
        } catch (HookFailedException $hookFailedException) {
            self::assertSame($script, $hookFailedException->script);
            throw $hookFailedException;
        }
    }

    public function testRunReturnsEmptyForNonExistentDirectory(): void
    {
        $nonExistentDir = $this->tempDir.'/does-not-exist';

        $result = $this->runner->run($nonExistentDir, $this->tempDir);

        self::assertSame([], $result);
    }

    public function testRunReturnsEmptyForEmptyDirectory(): void
    {
        $hookDir = $this->tempDir.'/hooks';
        $this->filesystem->mkdir($hookDir);

        $result = $this->runner->run($hookDir, $this->tempDir);

        self::assertSame([], $result);
    }

    public function testRunIgnoresNonShFiles(): void
    {
        $hookDir = $this->tempDir.'/hooks';
        $this->filesystem->mkdir($hookDir);

        $outputFile = $this->tempDir.'/ran.txt';

        $txtScript = $hookDir.'/hook.txt';
        $this->filesystem->dumpFile($txtScript, "#!/bin/bash\necho ran-txt > {$outputFile}\n");
        chmod($txtScript, 0755);

        $shScript = $hookDir.'/hook.sh';
        $this->filesystem->dumpFile($shScript, "#!/bin/bash\necho ran-sh > {$outputFile}\n");
        chmod($shScript, 0755);

        $warnings = $this->runner->run($hookDir, $this->tempDir);

        self::assertSame([], $warnings);
        self::assertFileExists($outputFile);
        self::assertSame('ran-sh', trim((string) file_get_contents($outputFile)));
    }

    public function testRunMixesExecutableAndNonExecutableScripts(): void
    {
        $hookDir = $this->tempDir.'/hooks';
        $this->filesystem->mkdir($hookDir);

        $outputFile = $this->tempDir.'/ran.txt';

        $firstScript = $hookDir.'/01-ok.sh';
        $this->filesystem->dumpFile($firstScript, "#!/bin/bash\necho 01 >> {$outputFile}\n");
        chmod($firstScript, 0755);

        $skipScript = $hookDir.'/02-skip.sh';
        $this->filesystem->dumpFile($skipScript, "#!/bin/bash\necho 02 >> {$outputFile}\n");
        // Intentionally not setting executable bit

        $thirdScript = $hookDir.'/03-ok.sh';
        $this->filesystem->dumpFile($thirdScript, "#!/bin/bash\necho 03 >> {$outputFile}\n");
        chmod($thirdScript, 0755);

        $warnings = $this->runner->run($hookDir, $this->tempDir);

        self::assertCount(1, $warnings);
        self::assertStringContainsString($skipScript, $warnings[0]);

        self::assertFileExists($outputFile);
        $lines = array_filter(explode("\n", trim((string) file_get_contents($outputFile))));
        $lines = array_values($lines);

        self::assertSame('01', $lines[0]);
        self::assertSame('03', $lines[1]);
    }

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->tempDir = sys_get_temp_dir().'/dde_hook_test_'.bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->tempDir);
        $this->runner = new HookRunner();
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->tempDir);
    }
}
