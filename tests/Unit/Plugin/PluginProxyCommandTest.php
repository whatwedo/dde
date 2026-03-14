<?php

declare(strict_types=1);

namespace Tests\Unit\Plugin;

use App\Plugin\PluginDefinition;
use App\Plugin\PluginProxyCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class PluginProxyCommandTest extends TestCase
{
    private string $tempDir;

    private Filesystem $filesystem;

    public function testCommandNameIsPrefixedWithProject(): void
    {
        $plugin = new PluginDefinition(
            command: 'web:hash-pw',
            description: 'Generate password hash',
            scriptPath: '/path/to/hash-pw.sh',
        );

        $command = new PluginProxyCommand($plugin);

        $this->assertSame('project:exec:web:hash-pw', $command->getName());
    }

    public function testDescriptionMatchesPluginDescription(): void
    {
        $plugin = new PluginDefinition(
            command: 'deploy',
            description: 'Deploy the application',
            scriptPath: '/path/to/deploy.sh',
        );

        $command = new PluginProxyCommand($plugin);

        $this->assertSame('Deploy the application', $command->getDescription());
    }

    public function testCommandHasArgsArgument(): void
    {
        $plugin = new PluginDefinition(
            command: 'test:cmd',
            description: 'Test command',
            scriptPath: '/path/to/test.sh',
        );

        $command = new PluginProxyCommand($plugin);
        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasArgument('args'));
        $this->assertTrue($definition->getArgument('args')->isArray());
        $this->assertFalse($definition->getArgument('args')->isRequired());
    }

    public function testExecuteRunsPluginScriptAndReturnsOutput(): void
    {
        $scriptPath = $this->tempDir.'/hello.sh';
        $this->filesystem->dumpFile($scriptPath, "#!/bin/bash\necho 'PLUGIN_OK'");
        chmod($scriptPath, 0o755);

        $plugin = new PluginDefinition(
            command: 'hello',
            description: 'Say hello',
            scriptPath: $scriptPath,
        );

        $command = new PluginProxyCommand($plugin);
        $application = new Application();
        $application->addCommand($command);

        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('PLUGIN_OK', $tester->getDisplay());
    }

    public function testExecutePassesArgumentsToScript(): void
    {
        $scriptPath = $this->tempDir.'/echo-args.sh';
        $this->filesystem->dumpFile($scriptPath, "#!/bin/bash\necho \"\$@\"");
        chmod($scriptPath, 0o755);

        $plugin = new PluginDefinition(
            command: 'echo-args',
            description: 'Echo arguments',
            scriptPath: $scriptPath,
        );

        $command = new PluginProxyCommand($plugin);
        $application = new Application();
        $application->addCommand($command);

        $tester = new CommandTester($command);
        $tester->execute([
            'args' => ['foo', 'bar'],
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('foo bar', $tester->getDisplay());
    }

    public function testExecuteFailsWhenScriptIsNotExecutable(): void
    {
        $scriptPath = $this->tempDir.'/not-executable.sh';
        $this->filesystem->dumpFile($scriptPath, "#!/bin/bash\necho 'SHOULD_NOT_RUN'");
        chmod($scriptPath, 0o644);

        $plugin = new PluginDefinition(
            command: 'not-executable',
            description: 'Not executable plugin',
            scriptPath: $scriptPath,
        );

        $command = new PluginProxyCommand($plugin);
        $application = new Application();
        $application->addCommand($command);

        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('is not executable', $tester->getDisplay());
        $this->assertStringContainsString($scriptPath, $tester->getDisplay());
    }

    public function testExecuteReturnsNonZeroExitCode(): void
    {
        $scriptPath = $this->tempDir.'/fail.sh';
        $this->filesystem->dumpFile($scriptPath, "#!/bin/bash\nexit 42");
        chmod($scriptPath, 0o755);

        $plugin = new PluginDefinition(
            command: 'fail',
            description: 'Failing plugin',
            scriptPath: $scriptPath,
        );

        $command = new PluginProxyCommand($plugin);
        $application = new Application();
        $application->addCommand($command);

        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(42, $tester->getStatusCode());
    }

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->tempDir = sys_get_temp_dir().'/dde_test_plugin_'.bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->tempDir);
    }
}
