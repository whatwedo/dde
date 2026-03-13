<?php

declare(strict_types=1);

namespace App\Manager;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

final readonly class ProjectInitManager
{
    private const string DDE_DIR = '.dde';

    private const array HOOK_DIRS = [
        'hooks/project.up.pre',
        'hooks/project.up.post',
        'hooks/project.down.pre',
        'hooks/project.down.post',
    ];

    private const array EXTRA_DIRS = [
        'data',
        'snapshots',
        'adapters',
        'plugins',
    ];

    private const array GITKEEP_DIRS = [
        'adapters',
        'plugins',
        'data',
        'snapshots',
        'hooks/project.up.pre',
        'hooks/project.up.post',
        'hooks/project.down.pre',
        'hooks/project.down.post',
    ];

    public function __construct(
        private Filesystem $filesystem,
    ) {
    }

    /**
     * @param list<string> $services
     *
     * @return array{created: list<string>, skipped: list<string>}
     */
    public function createDirectoryStructure(
        string $projectDir,
        string $name,
        array $services,
        string $container,
        ?string $shell,
        bool $isDryRun,
    ): array {
        $ddeDir = $projectDir.'/'.self::DDE_DIR;
        $created = [];
        $skipped = [];

        $directories = array_merge(
            [self::DDE_DIR],
            array_map(fn (string $dir): string => self::DDE_DIR.'/'.$dir, self::EXTRA_DIRS),
            array_map(fn (string $dir): string => self::DDE_DIR.'/'.$dir, self::HOOK_DIRS),
        );

        foreach ($directories as $dir) {
            $fullPath = $projectDir.'/'.$dir;

            if ($this->filesystem->exists($fullPath)) {
                $skipped[] = $dir.'/';

                continue;
            }

            if (! $isDryRun) {
                $this->filesystem->mkdir($fullPath);
            }

            $created[] = $dir.'/';
        }

        // Create .gitkeep in tracked directories (needed for git worktree support)
        foreach (self::GITKEEP_DIRS as $dir) {
            $gitkeepPath = $ddeDir.'/'.$dir.'/.gitkeep';

            if ($this->filesystem->exists($gitkeepPath)) {
                continue;
            }

            if (! $isDryRun) {
                $this->filesystem->dumpFile($gitkeepPath, '');
            }
        }

        // Create .gitignore
        $gitignorePath = $ddeDir.'/.gitignore';
        $gitignoreRelative = self::DDE_DIR.'/.gitignore';

        if ($this->filesystem->exists($gitignorePath)) {
            $skipped[] = $gitignoreRelative;
        } else {
            if (! $isDryRun) {
                $this->filesystem->dumpFile($gitignorePath, "data/\n!data/.gitkeep\nsnapshots/\n!snapshots/.gitkeep\n");
            }

            $created[] = $gitignoreRelative;
        }

        // Create or update config.yml
        $configPath = $ddeDir.'/config.yml';
        $configRelative = self::DDE_DIR.'/config.yml';
        $configData = $this->buildConfigYaml($name, $services, $container, $shell);
        $configExists = $this->filesystem->exists($configPath);

        if (! $isDryRun) {
            $this->filesystem->dumpFile($configPath, $configData);
        }

        if ($configExists) {
            $skipped[] = $configRelative.' (updated)';
        } else {
            $created[] = $configRelative;
        }

        // Remove .dde from project .gitignore (needed for worktree support)
        $this->removeDdeFromGitignore($projectDir);

        return [
            'created' => $created,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param list<string> $services
     */
    public function buildConfigYaml(string $name, array $services, string $container, ?string $shell): string
    {
        $config = [];

        if ($name !== '') {
            $config['name'] = $name;
        }

        if ($services !== []) {
            $config['services'] = $services;
        }

        $containerConfig = [];

        if ($shell !== null) {
            $containerConfig['shell'] = $shell;
        }

        $config['containers'] = [
            $container => $containerConfig,
        ];

        $yaml = Yaml::dump($config, 4, 4);

        // Clean up empty inline maps: "key: {  }" → "key: ~"
        return (string) preg_replace('/: \{ {2}\}/', ': ~', $yaml);
    }

    /**
     * Removes .dde entries from the project's root .gitignore so that
     * .dde/config.yml is tracked in git (required for worktree support).
     */
    private function removeDdeFromGitignore(string $projectDir): void
    {
        $gitignorePath = $projectDir.'/.gitignore';

        if (! $this->filesystem->exists($gitignorePath)) {
            return;
        }

        $content = file_get_contents($gitignorePath);

        if ($content === false) {
            return;
        }

        $lines = explode("\n", $content);
        $filtered = array_filter($lines, fn (string $line): bool => ! in_array(trim($line), ['.dde', '.dde/', '/.dde', '/.dde/'], true));

        $newContent = implode("\n", $filtered);

        if ($newContent !== $content) {
            $this->filesystem->dumpFile($gitignorePath, $newContent);
        }
    }
}
