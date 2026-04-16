<?php

declare(strict_types=1);

namespace App\Manager;

use App\Config\Definition\ProjectConfigDefinition;
use App\Config\ProjectConfig;
use App\Config\ResolvedConfig;
use App\Config\WorktreeInfo;
use App\Service\ServiceRegistry;
use App\Util\ProcessFactory;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

readonly class ProjectConfigManager
{
    public const string CONFIG_FILE = 'config.yml';

    public const string CONFIG_DIR = '.dde';

    public const array COMPOSE_FILES = ['docker-compose.yml', 'docker-compose.yaml', 'compose.yml', 'compose.yaml'];

    private const string PROJECT_MARKER = '.dde/config.yml';

    public function __construct(
        private GlobalConfigManager $globalConfigManager,
        private ServiceRegistry $serviceRegistry,
        private ProcessFactory $processFactory,
        private Filesystem $filesystem = new Filesystem(),
        private Processor $processor = new Processor(),
    ) {
    }

    public function loadProjectConfig(string $projectDir): ProjectConfig
    {
        $path = $projectDir.'/'.self::CONFIG_DIR.'/'.self::CONFIG_FILE;
        $data = $this->loadYaml($path);

        $processed = $this->processor->processConfiguration(new ProjectConfigDefinition(), [$data]);

        return ProjectConfig::fromProcessedConfig($processed);
    }

    public function resolveConfig(string $projectDir): ResolvedConfig
    {
        $global = $this->globalConfigManager->load();
        $project = $this->loadProjectConfig($projectDir);

        return ResolvedConfig::merge($global, $project, $this->serviceRegistry->getDefaultVersions());
    }

    public function findProjectDirectory(): ?string
    {
        $directory = getcwd();

        if ($directory === false) {
            return null;
        }

        return $this->searchUpward($directory, [self::PROJECT_MARKER]);
    }

    public function findDockerProjectDirectory(): ?string
    {
        $directory = getcwd();

        if ($directory === false) {
            return null;
        }

        return $this->searchUpward($directory, self::COMPOSE_FILES);
    }

    public function detectWorktree(string $projectDir): ?WorktreeInfo
    {
        $process = $this->processFactory->create(['git', 'worktree', 'list', '--porcelain'], $projectDir, 10);

        try {
            $process->run();
        } catch (\Throwable) {
            return null;
        }

        if (!$process->isSuccessful()) {
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

        // If project dir matches the main worktree, this is not a worktree checkout
        if ($this->pathsEqual($realProjectDir, $mainWorktree['path'])) {
            return null;
        }

        // Find the matching worktree entry
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

    public function resolveProjectHostname(string $projectName, ?WorktreeInfo $worktreeInfo): string
    {
        if ($worktreeInfo instanceof WorktreeInfo) {
            $suffix = $this->sanitizeWorktreeSuffix($worktreeInfo->suffix, $projectName);

            return $projectName.'-'.$suffix.'.test';
        }

        return $projectName.'.test';
    }

    public function sanitizeWorktreeSuffix(string $dirName, string $projectName): string
    {
        $suffix = $dirName;

        // Remove project name prefix (case-insensitive)
        if (str_starts_with(strtolower($suffix), strtolower($projectName).'-')) {
            $suffix = substr($suffix, strlen($projectName) + 1);
        } elseif (strcasecmp($suffix, $projectName) === 0) {
            $suffix = '';
        }

        // Transliterate unicode to ASCII (e.g. ü → ue)
        $slugger = new AsciiSlugger();
        $suffix = (string) $slugger->slug($suffix, '-')->lower();

        // Replace any remaining invalid characters with hyphens
        $suffix = (string) preg_replace('/[^a-z0-9-]/', '-', $suffix);

        // Collapse consecutive hyphens
        $suffix = (string) preg_replace('/-{2,}/', '-', $suffix);

        // Trim leading/trailing hyphens
        $suffix = trim($suffix, '-');

        // Fallback for empty suffix
        if ($suffix === '') {
            $suffix = 'worktree';
        }

        // Truncate to 63 characters (DNS label limit)
        if (strlen($suffix) > 63) {
            $suffix = rtrim(substr($suffix, 0, 63), '-');
        }

        return $suffix;
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
                    // Strip refs/heads/ prefix
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
     * @return array<string, mixed>
     *
     * @throws \RuntimeException
     */
    private function loadYaml(string $path): array
    {
        if (!$this->filesystem->exists($path)) {
            return [];
        }

        try {
            $data = Yaml::parseFile($path, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $parseException) {
            throw new \RuntimeException(sprintf('Invalid YAML in "%s": %s', $path, $parseException->getMessage()), $parseException->getCode(), $parseException);
        }

        if (!is_array($data)) {
            return [];
        }

        return $data;
    }

    /**
     * @param list<string> $candidates
     */
    private function searchUpward(string $directory, array $candidates): ?string
    {
        while (true) {
            foreach ($candidates as $configFile) {
                if ($this->filesystem->exists($directory.'/'.$configFile)) {
                    return $directory;
                }
            }

            $parent = dirname($directory);

            if ($parent === $directory) {
                break;
            }

            $directory = $parent;
        }

        return null;
    }
}
