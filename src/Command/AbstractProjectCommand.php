<?php

declare(strict_types=1);

namespace App\Command;

use App\Config\ProjectConfig;
use App\Config\ResolvedConfig;
use App\Event\AbstractProjectEvent;
use App\Manager\ProjectConfigManager;
use App\Output\FormatterResolver;
use App\Output\OutputFormatterInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

abstract class AbstractProjectCommand extends AbstractBaseCommand
{
    private ?string $projectDirectory = null;

    private ?ProjectConfig $projectConfig = null;

    private ?ResolvedConfig $resolvedConfig = null;

    public function __construct(
        protected readonly ProjectConfigManager $configManager,
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
                throw new \RuntimeException('No dde project found here. Run "dde project:init" to initialize the current directory.');
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

    /**
     * Shows warnings collected while dispatching a lifecycle event, e.g. the stderr of a successful hook.
     */
    protected function renderHookWarnings(SymfonyStyle $io, OutputFormatterInterface $formatter, AbstractProjectEvent $event): void
    {
        if (!$formatter->isInteractive()) {
            return;
        }

        foreach ($event->warnings as $warning) {
            $io->warning($warning);
        }
    }
}
