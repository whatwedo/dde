<?php

declare(strict_types=1);

namespace App\Manager;

use Symfony\Component\Filesystem\Filesystem;

readonly class AgentSkillManager
{
    private const string SKILL_SOURCE = 'skills/claude/dde/SKILL.md';

    /**
     * Home-relative directory that reveals an agent => home-relative skills directory it reads.
     */
    private const array TARGETS = [
        '.claude' => '.claude/skills',
        '.agents' => '.agents/skills',
    ];

    public function __construct(
        private string $projectDir,
        private Filesystem $filesystem = new Filesystem(),
    ) {}

    public function hasSkillTarget(): bool
    {
        return $this->skillTargets() !== [];
    }

    public function installSkill(): void
    {
        $skillSource = $this->projectDir.'/'.self::SKILL_SOURCE;

        if (!$this->filesystem->exists($skillSource)) {
            return;
        }

        foreach ($this->skillTargets() as $skillTarget) {
            $this->filesystem->mkdir(dirname($skillTarget));

            // copy() propagates the source permissions; inside the PHAR the skill is
            // read-only, so normalise the target to keep it overwritable on refresh.
            if ($this->filesystem->exists($skillTarget)) {
                $this->filesystem->chmod($skillTarget, 0o644);
            }

            $this->filesystem->copy($skillSource, $skillTarget, overwriteNewerFiles: true);
            $this->filesystem->chmod($skillTarget, 0o644);
        }
    }

    public function isSkillInstalled(): bool
    {
        $targets = $this->skillTargets();

        return $targets !== [] && array_all($targets, fn(string $target): bool => $this->filesystem->exists($target));
    }

    /**
     * @return list<string>
     */
    private function skillTargets(): array
    {
        $home = $this->getHomeDir();

        if ($home === null) {
            return [];
        }

        $targets = [];

        foreach (self::TARGETS as $agentDir => $skillsDir) {
            if (is_dir($home.'/'.$agentDir)) {
                $targets[] = $home.'/'.$skillsDir.'/dde/SKILL.md';
            }
        }

        return array_values(array_unique($targets));
    }

    private function getHomeDir(): ?string
    {
        $home = $_SERVER['HOME'] ?? $_ENV['HOME'] ?? null;

        return is_string($home) && $home !== '' ? $home : null;
    }
}
