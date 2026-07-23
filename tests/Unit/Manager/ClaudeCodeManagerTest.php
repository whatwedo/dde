<?php

declare(strict_types=1);

namespace Tests\Unit\Manager;

use App\Manager\ClaudeCodeManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ClaudeCodeManagerTest extends TestCase
{
    private string $tempDir;

    private string $projectDir;

    private string $homeDir;

    private Filesystem $filesystem;

    private mixed $originalHome = null;

    public function testInstallSkillCopiesSkillIntoClaudeSkillsDirectory(): void
    {
        $this->createSkillSource('# dde skill');

        $manager = new ClaudeCodeManager($this->projectDir);
        $manager->installSkill();

        $target = $this->homeDir.'/.claude/skills/dde/SKILL.md';
        self::assertFileExists($target);
        self::assertSame('# dde skill', file_get_contents($target));
    }

    public function testInstallSkillOverwritesReadOnlyTarget(): void
    {
        $this->createSkillSource('# updated skill');

        $target = $this->homeDir.'/.claude/skills/dde/SKILL.md';
        $this->filesystem->dumpFile($target, '# stale skill');
        $this->filesystem->chmod($target, 0o444);

        $manager = new ClaudeCodeManager($this->projectDir);
        $manager->installSkill();

        self::assertSame('# updated skill', file_get_contents($target));
    }

    public function testInstallSkillLeavesTargetWritableWhenSourceIsReadOnly(): void
    {
        $this->createSkillSource('# dde skill');
        $this->filesystem->chmod($this->projectDir.'/skills/claude/dde/SKILL.md', 0o444);

        $manager = new ClaudeCodeManager($this->projectDir);
        $manager->installSkill();

        self::assertTrue(is_writable($this->homeDir.'/.claude/skills/dde/SKILL.md'));
    }

    private function createSkillSource(string $content): void
    {
        $this->filesystem->dumpFile($this->projectDir.'/skills/claude/dde/SKILL.md', $content);
    }

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->tempDir = sys_get_temp_dir().'/dde-test-claude-'.bin2hex(random_bytes(8));
        $this->projectDir = $this->tempDir.'/project';
        $this->homeDir = $this->tempDir.'/home';
        $this->filesystem->mkdir([$this->projectDir, $this->homeDir.'/.claude']);

        $this->originalHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $this->homeDir;
    }

    protected function tearDown(): void
    {
        if ($this->originalHome === null) {
            unset($_SERVER['HOME']);
        } else {
            $_SERVER['HOME'] = $this->originalHome;
        }

        if (is_dir($this->tempDir)) {
            $this->filesystem->chmod($this->tempDir, 0o755, recursive: true);
            $this->filesystem->remove($this->tempDir);
        }
    }
}
