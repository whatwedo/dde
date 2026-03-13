<?php

declare(strict_types=1);

namespace App\Manager;

use Symfony\Component\Filesystem\Filesystem;

readonly class ClaudeCodeManager
{
    private const string SKILL_DIR = 'skills/claude/dde';

    private const string SKILL_FILE = 'SKILL.md';

    public function __construct(
        private string $projectDir,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function isClaudeCodeInstalled(): bool
    {
        $home = $this->getHomeDir();

        return $home !== null && is_dir($home.'/.claude');
    }

    public function installSkill(): void
    {
        $home = $this->getHomeDir();

        if ($home === null) {
            return;
        }

        $skillSource = $this->projectDir.'/'.self::SKILL_DIR.'/'.self::SKILL_FILE;
        $skillTarget = $home.'/.claude/skills/dde/'.self::SKILL_FILE;

        if (!$this->filesystem->exists($skillSource)) {
            return;
        }

        $this->filesystem->mkdir(dirname($skillTarget));
        $this->filesystem->copy($skillSource, $skillTarget, overwriteNewerFiles: true);
    }

    public function isSkillInstalled(): bool
    {
        $home = $this->getHomeDir();

        if ($home === null) {
            return false;
        }

        return $this->filesystem->exists($home.'/.claude/skills/dde/'.self::SKILL_FILE);
    }

    private function getHomeDir(): ?string
    {
        $home = $_SERVER['HOME'] ?? $_ENV['HOME'] ?? null;

        return is_string($home) && $home !== '' ? $home : null;
    }
}
