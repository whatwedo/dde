<?php

declare(strict_types=1);

namespace Tests\Unit\Plugin;

use App\Manager\ProjectConfigManager;
use App\Plugin\PluginCommandLoader;
use App\Plugin\PluginDefinition;
use App\Plugin\PluginLoader;
use App\Plugin\PluginProxyCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Exception\CommandNotFoundException;

final class PluginCommandLoaderTest extends TestCase
{
    public function testGetNamesReturnsCommandNamesWithProjectPrefix(): void
    {
        $loader = $this->createLoader([
            new PluginDefinition(command: 'web:hello', description: 'Say hello', scriptPath: '/tmp/hello.sh'),
            new PluginDefinition(command: 'db:dump', description: 'Dump database', scriptPath: '/tmp/dump.sh'),
        ]);

        $names = $loader->getNames();

        $this->assertSame(['project:web:hello', 'project:db:dump'], $names);
    }

    public function testHasReturnsTrueForExistingCommand(): void
    {
        $loader = $this->createLoader([
            new PluginDefinition(command: 'web:hello', description: 'Say hello', scriptPath: '/tmp/hello.sh'),
        ]);

        $this->assertTrue($loader->has('project:web:hello'));
    }

    public function testHasReturnsFalseForUnknownCommand(): void
    {
        $loader = $this->createLoader([]);

        $this->assertFalse($loader->has('project:unknown'));
    }

    public function testGetReturnsPluginProxyCommand(): void
    {
        $loader = $this->createLoader([
            new PluginDefinition(command: 'web:hello', description: 'Say hello', scriptPath: '/tmp/hello.sh'),
        ]);

        $command = $loader->get('project:web:hello');

        $this->assertInstanceOf(PluginProxyCommand::class, $command);
        $this->assertSame('project:web:hello', $command->getName());
    }

    public function testGetThrowsCommandNotFoundExceptionForUnknownCommand(): void
    {
        $loader = $this->createLoader([]);

        $this->expectException(CommandNotFoundException::class);

        $loader->get('project:unknown');
    }

    public function testGetNamesReturnsEmptyWhenNoPlugins(): void
    {
        $loader = $this->createLoader([]);

        $this->assertSame([], $loader->getNames());
    }

    /**
     * @param list<PluginDefinition> $definitions
     */
    private function createLoader(array $definitions): PluginCommandLoader
    {
        $pluginLoader = $this->createStub(PluginLoader::class);
        $pluginLoader->method('loadPlugins')->willReturn($definitions);

        $configManager = $this->createStub(ProjectConfigManager::class);
        $configManager->method('findProjectDirectory')->willReturn('/tmp/project');

        return new PluginCommandLoader($pluginLoader, $configManager);
    }
}
