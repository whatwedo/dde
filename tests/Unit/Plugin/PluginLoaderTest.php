<?php

declare(strict_types=1);

namespace Tests\Unit\Plugin;

use App\Plugin\PluginDefinition;
use App\Plugin\PluginLoader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class PluginLoaderTest extends TestCase
{
    private string $tempDir;

    private PluginLoader $loader;

    public function testLoadPluginsFromProjectDirectory(): void
    {
        $pluginDir = $this->tempDir.'/project/.dde/plugins';
        mkdir($pluginDir, 0o755, true);

        file_put_contents($pluginDir.'/hash-pw.sh', <<<'SH'
            #!/bin/bash
            # @command web:hash-pw
            # @description Generate password hash
            echo "hashing"
            SH);

        $plugins = $this->loader->loadPlugins($this->tempDir.'/project');

        $this->assertCount(1, $plugins);
        $this->assertSame('web:hash-pw', $plugins[0]->command);
        $this->assertSame('Generate password hash', $plugins[0]->description);
        $this->assertStringEndsWith('hash-pw.sh', $plugins[0]->scriptPath);
    }

    public function testLoadPluginsSkipsFilesWithoutCommandAnnotation(): void
    {
        $pluginDir = $this->tempDir.'/project/.dde/plugins';
        mkdir($pluginDir, 0o755, true);

        file_put_contents($pluginDir.'/no-annotation.sh', <<<'SH'
            #!/bin/bash
            echo "no annotation"
            SH);

        $plugins = $this->loader->loadPlugins($this->tempDir.'/project');

        $this->assertCount(0, $plugins);
    }

    public function testLoadPluginsReturnsEmptyForMissingDirectory(): void
    {
        $plugins = $this->loader->loadPlugins($this->tempDir.'/nonexistent');

        $this->assertSame([], $plugins);
    }

    public function testLoadPluginsReturnsEmptyForNullProjectDir(): void
    {
        $plugins = $this->loader->loadPlugins();

        $this->assertSame([], $plugins);
    }

    public function testLoadPluginsReturnsEmptyForEmptyDirectory(): void
    {
        $pluginDir = $this->tempDir.'/project/.dde/plugins';
        mkdir($pluginDir, 0o755, true);

        $plugins = $this->loader->loadPlugins($this->tempDir.'/project');

        $this->assertSame([], $plugins);
    }

    public function testLoadPluginsMultiplePlugins(): void
    {
        $pluginDir = $this->tempDir.'/project/.dde/plugins';
        mkdir($pluginDir, 0o755, true);

        file_put_contents($pluginDir.'/alpha.sh', <<<'SH'
            #!/bin/bash
            # @command alpha:cmd
            # @description Alpha command
            SH);

        file_put_contents($pluginDir.'/beta.sh', <<<'SH'
            #!/bin/bash
            # @command beta:cmd
            # @description Beta command
            SH);

        $plugins = $this->loader->loadPlugins($this->tempDir.'/project');

        $this->assertCount(2, $plugins);

        $commands = array_map(static fn (PluginDefinition $p): string => $p->command, $plugins);
        $this->assertContains('alpha:cmd', $commands);
        $this->assertContains('beta:cmd', $commands);
    }

    public function testProjectPluginsOverrideGlobal(): void
    {
        // Simulate global plugins by creating a custom PluginLoader subclass
        // Instead, we test the merge logic via project directory having two dirs
        $globalDir = $this->tempDir.'/global/.dde/plugins';
        $projectDir = $this->tempDir.'/project/.dde/plugins';
        mkdir($globalDir, 0o755, true);
        mkdir($projectDir, 0o755, true);

        file_put_contents($globalDir.'/deploy.sh', <<<'SH'
            #!/bin/bash
            # @command deploy
            # @description Global deploy
            SH);

        file_put_contents($projectDir.'/deploy.sh', <<<'SH'
            #!/bin/bash
            # @command deploy
            # @description Project deploy
            SH);

        // Load only the project directory (global is HOME-based, tested separately)
        // To test merge, we use reflection to call private methods
        $reflection = new \ReflectionClass($this->loader);
        $scanMethod = $reflection->getMethod('scanDirectory');
        $mergeMethod = $reflection->getMethod('mergePlugins');

        $globalPlugins = $scanMethod->invoke($this->loader, $globalDir);
        $projectPlugins = $scanMethod->invoke($this->loader, $projectDir);
        $merged = $mergeMethod->invoke($this->loader, $globalPlugins, $projectPlugins);

        $this->assertCount(1, $merged);
        $this->assertSame('Project deploy', $merged['deploy']->description);
    }

    public function testParseAnnotationsWithDescriptionOnly(): void
    {
        $pluginDir = $this->tempDir.'/project/.dde/plugins';
        mkdir($pluginDir, 0o755, true);

        file_put_contents($pluginDir.'/minimal.sh', <<<'SH'
            #!/bin/bash
            # @command minimal:cmd
            SH);

        $plugins = $this->loader->loadPlugins($this->tempDir.'/project');

        $this->assertCount(1, $plugins);
        $this->assertSame('minimal:cmd', $plugins[0]->command);
        $this->assertSame('', $plugins[0]->description);
    }

    public function testPluginWithWhitespaceOnlyCommandIsSkipped(): void
    {
        $pluginDir = $this->tempDir.'/project/.dde/plugins';
        mkdir($pluginDir, 0o755, true);

        // The regex requires at least one non-whitespace character after @command,
        // so a whitespace-only value would not match the annotation pattern.
        // We test with a value that trims to empty by using a tab character.
        file_put_contents($pluginDir.'/empty-cmd.sh', "#!/bin/bash\n# @command \t \necho test\n");

        $plugins = $this->loader->loadPlugins($this->tempDir.'/project');

        $this->assertCount(0, $plugins);
    }

    public function testPluginWithPathTraversalCommandIsSkipped(): void
    {
        $pluginDir = $this->tempDir.'/project/.dde/plugins';
        mkdir($pluginDir, 0o755, true);

        file_put_contents($pluginDir.'/evil.sh', <<<'SH'
            #!/bin/bash
            # @command ../../evil
            # @description Evil command
            echo "evil"
            SH);

        $plugins = $this->loader->loadPlugins($this->tempDir.'/project');

        $this->assertCount(0, $plugins);
    }

    public function testPluginWithValidHyphenatedCommandIsLoaded(): void
    {
        $pluginDir = $this->tempDir.'/project/.dde/plugins';
        mkdir($pluginDir, 0o755, true);

        file_put_contents($pluginDir.'/valid.sh', <<<'SH'
            #!/bin/bash
            # @command valid-name
            # @description A valid command
            echo "valid"
            SH);

        $plugins = $this->loader->loadPlugins($this->tempDir.'/project');

        $this->assertCount(1, $plugins);
        $this->assertSame('valid-name', $plugins[0]->command);
    }

    public function testNonShFilesAreIgnored(): void
    {
        $pluginDir = $this->tempDir.'/project/.dde/plugins';
        mkdir($pluginDir, 0o755, true);

        file_put_contents($pluginDir.'/plugin.txt', <<<'SH'
            #!/bin/bash
            # @command should:ignore
            # @description Should be ignored
            SH);

        $plugins = $this->loader->loadPlugins($this->tempDir.'/project');

        $this->assertSame([], $plugins);
    }

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/dde_test_pluginloader_'.bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o755, true);

        $this->loader = new PluginLoader();
    }

    protected function tearDown(): void
    {
        $filesystem = new Filesystem();

        if (is_dir($this->tempDir)) {
            $filesystem->remove($this->tempDir);
        }
    }
}
