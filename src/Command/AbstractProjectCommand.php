<?php

declare(strict_types=1);

namespace App\Command;

use App\Config\ProjectConfig;
use App\Config\ResolvedConfig;
use App\Manager\ConfigManager;
use App\Output\FormatterResolver;

abstract class AbstractProjectCommand extends AbstractBaseCommand
{
    private ?string $projectDirectory = null;

    private ?ProjectConfig $projectConfig = null;

    private ?ResolvedConfig $resolvedConfig = null;

    public function __construct(
        protected readonly ConfigManager $configManager,
        FormatterResolver $formatterResolver,
    ) {
        parent::__construct($formatterResolver);
    }

    /**
     * @throws \RuntimeException
     */
    protected function getProjectDirectory(): string
    {
        if ($this->projectDirectory === null) {
            $directory = $this->configManager->findProjectDirectory();

            if ($directory === null) {
                throw new \RuntimeException('No project directory found. Are you inside a dde project?');
            }

            $this->projectDirectory = $directory;
        }

        return $this->projectDirectory;
    }

    protected function getResolvedConfig(): ResolvedConfig
    {
        if (!$this->resolvedConfig instanceof ResolvedConfig) {
            $this->resolvedConfig = $this->configManager->resolveConfig($this->getProjectDirectory());
        }

        return $this->resolvedConfig;
    }

    protected function getProjectConfig(): ProjectConfig
    {
        if (!$this->projectConfig instanceof ProjectConfig) {
            $this->projectConfig = $this->configManager->loadProjectConfig($this->getProjectDirectory());
        }

        return $this->projectConfig;
    }

    /**
     * @param list<string> $composeServiceNames Fallback service names from compose file
     */
    protected function getDefaultService(ResolvedConfig $config, array $composeServiceNames = []): string
    {
        $containerNames = array_keys($config->containers);

        if ($containerNames !== []) {
            return $containerNames[0];
        }

        if ($composeServiceNames !== []) {
            return $composeServiceNames[0];
        }

        return 'web';
    }
}
