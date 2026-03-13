<?php

declare(strict_types=1);

namespace App\Plugin;

use App\Manager\ConfigManager;
use App\Util\ProcessFactory;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;
use Symfony\Component\Console\Exception\CommandNotFoundException;

final class PluginCommandLoader implements CommandLoaderInterface
{
    /**
     * @var array<string, PluginDefinition>|null
     */
    private ?array $plugins = null;

    public function __construct(
        private readonly PluginLoader $pluginLoader,
        private readonly ConfigManager $configManager,
        private readonly ProcessFactory $processFactory = new ProcessFactory(),
    ) {
    }

    public function get(string $name): PluginProxyCommand
    {
        $plugins = $this->resolvePlugins();

        if (! isset($plugins[$name])) {
            throw new CommandNotFoundException(sprintf('Plugin command "%s" not found.', $name));
        }

        return new PluginProxyCommand($plugins[$name], $this->processFactory);
    }

    public function has(string $name): bool
    {
        return isset($this->resolvePlugins()[$name]);
    }

    /**
     * @return list<string>
     */
    public function getNames(): array
    {
        return array_keys($this->resolvePlugins());
    }

    /**
     * @return array<string, PluginDefinition>
     */
    private function resolvePlugins(): array
    {
        if ($this->plugins !== null) {
            return $this->plugins;
        }

        $this->plugins = [];
        $projectDir = $this->configManager->findProjectDirectory();
        $definitions = $this->pluginLoader->loadPlugins($projectDir);

        foreach ($definitions as $definition) {
            $commandName = 'project:exec:'.$definition->command;
            $this->plugins[$commandName] = $definition;
        }

        return $this->plugins;
    }
}
