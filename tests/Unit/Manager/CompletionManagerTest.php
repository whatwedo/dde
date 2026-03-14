<?php

declare(strict_types=1);

namespace Tests\Unit\Manager;

use App\Manager\CompletionManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

final class CompletionManagerTest extends TestCase
{
    private string $tempDir;

    private Filesystem $filesystem;

    public function testGenerateBashCompletionCallsCompletionCommand(): void
    {
        $service = new CompletionManager($this->filesystem);
        $application = $this->createApplicationWithCompletionCommand();

        $result = $service->generateBashCompletion($application);

        $this->assertIsString($result);
    }

    public function testGenerateZshCompletionCallsCompletionCommand(): void
    {
        $service = new CompletionManager($this->filesystem);
        $application = $this->createApplicationWithCompletionCommand();

        $result = $service->generateZshCompletion($application);

        $this->assertIsString($result);
    }

    public function testGenerateBashCompletionThrowsOnFailure(): void
    {
        $service = new CompletionManager($this->filesystem);
        $application = $this->createApplicationWithFailingCommand();

        $this->expectException(\Throwable::class);

        $service->generateBashCompletion($application);
    }

    public function testAddSourceLineIsIdempotent(): void
    {
        $rcFile = $this->tempDir.'/.bashrc';
        $sourceLine = 'source "/some/path/completion.bash"';
        $marker = 'dde-completion';

        // Write the marker and source line once
        $this->filesystem->dumpFile($rcFile, sprintf('# %s%s%s%s', $marker, PHP_EOL, $sourceLine, PHP_EOL));

        $service = new CompletionManager($this->filesystem);

        $reflection = new \ReflectionMethod($service, 'addSourceLine');
        $reflection->invoke($service, $rcFile, $sourceLine, $marker);

        // Content should not be duplicated
        $content = file_get_contents($rcFile);
        $this->assertNotFalse($content);
        $this->assertSame(1, substr_count($content, $sourceLine));
    }

    public function testAddSourceLineReplacesExistingMarkerBlock(): void
    {
        $rcFile = $this->tempDir.'/.bashrc';
        $marker = 'dde-completion';
        $oldLine = 'source "/old/path/completion.bash"';
        $newLine = 'source "/new/path/completion.bash"';

        $this->filesystem->dumpFile($rcFile, "# existing config\n# {$marker}\n{$oldLine}\n");

        $service = new CompletionManager($this->filesystem);

        $reflection = new \ReflectionMethod($service, 'addSourceLine');
        $reflection->invoke($service, $rcFile, $newLine, $marker);

        $content = file_get_contents($rcFile);
        $this->assertNotFalse($content);
        $this->assertStringContainsString($newLine, $content);
        $this->assertStringNotContainsString($oldLine, $content);
    }

    public function testAddSourceLineAppendsToExistingFile(): void
    {
        $rcFile = $this->tempDir.'/.bashrc';
        $existingContent = "# existing config\nalias ll='ls -la'\n";
        $sourceLine = 'source "/some/path/completion.bash"';
        $marker = 'dde-completion';

        $this->filesystem->dumpFile($rcFile, $existingContent);

        $service = new CompletionManager($this->filesystem);

        $reflection = new \ReflectionMethod($service, 'addSourceLine');
        $reflection->invoke($service, $rcFile, $sourceLine, $marker);

        $content = file_get_contents($rcFile);
        $this->assertNotFalse($content);
        $this->assertStringContainsString($existingContent, $content);
        $this->assertStringContainsString($sourceLine, $content);
        $this->assertStringContainsString('# '.$marker, $content);
    }

    public function testAddSourceLineCreatesFileIfMissing(): void
    {
        $rcFile = $this->tempDir.'/.zshrc';
        $sourceLine = 'source "/some/path/completion.zsh"';
        $marker = 'dde-completion';

        $this->assertFileDoesNotExist($rcFile);

        $service = new CompletionManager($this->filesystem);

        $reflection = new \ReflectionMethod($service, 'addSourceLine');
        $reflection->invoke($service, $rcFile, $sourceLine, $marker);

        $this->assertFileExists($rcFile);
        $content = file_get_contents($rcFile);
        $this->assertNotFalse($content);
        $this->assertStringContainsString($sourceLine, $content);
        $this->assertStringContainsString('# '.$marker, $content);
    }

    public function testGetHomePathThrowsWhenNotSet(): void
    {
        $originalHome = getenv('HOME');

        try {
            putenv('HOME=');

            $service = new CompletionManager($this->filesystem);

            $reflection = new \ReflectionMethod($service, 'getHomePath');

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('HOME environment variable is not set.');

            $reflection->invoke($service);
        } finally {
            if ($originalHome !== false) {
                putenv('HOME='.$originalHome);
            }
        }
    }

    public function testGetHomePathReturnsHomeValue(): void
    {
        $originalHome = getenv('HOME');

        try {
            putenv('HOME=/tmp/test-home');

            $service = new CompletionManager($this->filesystem);

            $reflection = new \ReflectionMethod($service, 'getHomePath');

            $this->assertSame('/tmp/test-home', $reflection->invoke($service));
        } finally {
            if ($originalHome !== false) {
                putenv('HOME='.$originalHome);
            }
        }
    }

    private function createApplicationWithCompletionCommand(): Application
    {
        $application = new Application('dde', 'test');
        $application->setAutoExit(false);

        return $application;
    }

    private function createApplicationWithFailingCommand(): Application
    {
        $application = new Application('dde', 'test');
        $application->setAutoExit(false);

        $failingCommand = new class('completion') extends Command {
            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                return Command::FAILURE;
            }
        };

        $application->addCommand($failingCommand);

        return $application;
    }

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->tempDir = sys_get_temp_dir().'/dde-test-completion-'.bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->filesystem->remove($this->tempDir);
        }
    }
}
