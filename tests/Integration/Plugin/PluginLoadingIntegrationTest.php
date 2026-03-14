<?php

declare(strict_types=1);

namespace App\Tests\Integration\Plugin;

use App\Plugin\PluginDefinition;
use App\Plugin\PluginLoader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class PluginLoadingIntegrationTest extends TestCase
{
    private string $tempDir;

    private Filesystem $filesystem;

    public function testLoadsPluginFromProjectDirectory(): void
    {
        $pluginDir = $this->tempDir.'/project/.dde/plugins';
        mkdir($pluginDir, 0o755, true);

        file_put_contents($pluginDir.'/deploy.sh', <<<'SH'
            #!/bin/bash
            # @command project:deploy
            # @description Deploy the project to production
            set -e
            echo "deploying..."
            SH);

        $loader = new PluginLoader();
        $plugins = $loader->loadPlugins($this->tempDir.'/project');

        $this->assertCount(1, $plugins);
        $this->assertInstanceOf(PluginDefinition::class, $plugins[0]);
        $this->assertSame('project:deploy', $plugins[0]->command);
        $this->assertSame('Deploy the project to production', $plugins[0]->description);
        $this->assertSame($pluginDir.'/deploy.sh', $plugins[0]->scriptPath);
    }

    public function testIgnoresInvalidPluginFiles(): void
    {
        $pluginDir = $this->tempDir.'/project/.dde/plugins';
        mkdir($pluginDir, 0o755, true);

        // Shell file without @command annotation
        file_put_contents($pluginDir.'/no-annotation.sh', <<<'SH'
            #!/bin/bash
            echo "I have no annotations"
            SH);

        // Shell file with only @description but no @command
        file_put_contents($pluginDir.'/desc-only.sh', <<<'SH'
            #!/bin/bash
            # @description This has no command annotation
            echo "incomplete"
            SH);

        // Non-.sh file (should be ignored by Finder)
        file_put_contents($pluginDir.'/readme.txt', <<<'TXT'
            # @command should:not-load
            # @description This is not a shell script
            TXT);

        $loader = new PluginLoader();
        $plugins = $loader->loadPlugins($this->tempDir.'/project');

        $this->assertCount(0, $plugins);
    }

    public function testLoadMultiplePlugins(): void
    {
        $pluginDir = $this->tempDir.'/project/.dde/plugins';
        mkdir($pluginDir, 0o755, true);

        file_put_contents($pluginDir.'/alpha.sh', <<<'SH'
            #!/bin/bash
            # @command alpha:run
            # @description Run alpha task
            SH);

        file_put_contents($pluginDir.'/beta.sh', <<<'SH'
            #!/bin/bash
            # @command beta:run
            # @description Run beta task
            SH);

        file_put_contents($pluginDir.'/gamma.sh', <<<'SH'
            #!/bin/bash
            # @command gamma:run
            # @description Run gamma task
            SH);

        $loader = new PluginLoader();
        $plugins = $loader->loadPlugins($this->tempDir.'/project');

        $this->assertCount(3, $plugins);

        $commands = array_map(static fn (PluginDefinition $p): string => $p->command, $plugins);
        sort($commands);

        $this->assertSame(['alpha:run', 'beta:run', 'gamma:run'], $commands);
    }

    public function testEmptyPluginDirectoryReturnsEmpty(): void
    {
        $pluginDir = $this->tempDir.'/project/.dde/plugins';
        mkdir($pluginDir, 0o755, true);

        $loader = new PluginLoader();
        $plugins = $loader->loadPlugins($this->tempDir.'/project');

        $this->assertSame([], $plugins);
    }

    public function testMissingPluginDirectoryReturnsEmpty(): void
    {
        $loader = new PluginLoader();
        $plugins = $loader->loadPlugins($this->tempDir.'/nonexistent-project');

        $this->assertSame([], $plugins);
    }

    public function testGlobalPluginsLoadedFromConfigDir(): void
    {
        $globalPluginDir = $this->tempDir.'/config/plugins';
        mkdir($globalPluginDir, 0o755, true);

        file_put_contents($globalPluginDir.'/global-tool.sh', <<<'SH'
            #!/bin/bash
            # @command global:tool
            # @description A global tool available everywhere
            SH);

        $loader = new PluginLoader(configDir: $this->tempDir.'/config');
        $plugins = $loader->loadPlugins();

        $this->assertCount(1, $plugins);
        $this->assertSame('global:tool', $plugins[0]->command);
        $this->assertSame('A global tool available everywhere', $plugins[0]->description);
    }

    public function testProjectPluginsOverrideGlobalPlugins(): void
    {
        // Set up global plugin
        $globalPluginDir = $this->tempDir.'/config/plugins';
        mkdir($globalPluginDir, 0o755, true);

        file_put_contents($globalPluginDir.'/deploy.sh', <<<'SH'
            #!/bin/bash
            # @command deploy
            # @description Global deploy script
            SH);

        // Set up project plugin with same command name
        $projectPluginDir = $this->tempDir.'/project/.dde/plugins';
        mkdir($projectPluginDir, 0o755, true);

        file_put_contents($projectPluginDir.'/deploy.sh', <<<'SH'
            #!/bin/bash
            # @command deploy
            # @description Project-specific deploy script
            SH);

        $loader = new PluginLoader(configDir: $this->tempDir.'/config');
        $plugins = $loader->loadPlugins($this->tempDir.'/project');

        $this->assertCount(1, $plugins);
        $this->assertSame('deploy', $plugins[0]->command);
        $this->assertSame('Project-specific deploy script', $plugins[0]->description);
        $this->assertStringContains('project/.dde/plugins/deploy.sh', $plugins[0]->scriptPath);
    }

    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertTrue(
            str_contains($haystack, $needle),
            sprintf('Failed asserting that "%s" contains "%s".', $haystack, $needle),
        );
    }

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/dde_test_plugin_integration_'.bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o755, true);
        $this->filesystem = new Filesystem();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->filesystem->remove($this->tempDir);
        }
    }
}
