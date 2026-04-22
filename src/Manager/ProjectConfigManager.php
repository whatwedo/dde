<?php

declare(strict_types=1);

namespace App\Manager;

use App\Config\Definition\ProjectConfigDefinition;
use App\Config\ProjectConfig;
use App\Config\ResolvedConfig;
use App\Config\WorktreeInfo;
use App\Service\ServiceRegistry;
use App\Util\IdentifierSanitizer;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Filesystem\Filesystem;
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
        private WorktreeManager $worktreeManager,
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
        return $this->worktreeManager->detect($projectDir);
    }

    public function resolveProjectHostname(string $projectName, ?WorktreeInfo $worktreeInfo): string
    {
        return $this->worktreeManager->resolveHostname($projectName, $worktreeInfo);
    }

    public function resolveDatabaseName(string $baseDatabaseName, ?WorktreeInfo $worktreeInfo, string $projectName): string
    {
        return $this->worktreeManager->resolveDatabaseName($baseDatabaseName, $worktreeInfo, $projectName);
    }

    public function sanitizeWorktreeSuffix(string $dirName, string $projectName): string
    {
        return IdentifierSanitizer::forHostname($dirName, $projectName);
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
