<?php

declare(strict_types=1);

namespace App\Manager;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Filesystem\Filesystem;

final readonly class CompletionManager
{
    public function __construct(
        private Filesystem $filesystem,
    ) {
    }

    public function generateBashCompletion(Application $application): string
    {
        return $this->runCompletionCommand($application, 'bash');
    }

    public function generateZshCompletion(Application $application): string
    {
        return $this->runCompletionCommand($application, 'zsh');
    }

    public function installCompletion(string $configDir, Application $application): void
    {
        $this->filesystem->mkdir($configDir);

        $bashContent = $this->generateBashCompletion($application);
        $bashPath = $configDir.'/completion.bash';
        $this->filesystem->dumpFile($bashPath, $bashContent);

        $zshContent = $this->generateZshCompletion($application);
        $zshPath = $configDir.'/completion.zsh';
        $this->filesystem->dumpFile($zshPath, $zshContent);

        $this->addSourceLine(
            $this->getHomePath().'/.bashrc',
            sprintf('source "%s"', $bashPath),
            'dde-completion',
        );

        $this->addSourceLine(
            $this->getHomePath().'/.zshrc',
            sprintf('source "%s"', $zshPath),
            'dde-completion',
        );
    }

    private function runCompletionCommand(Application $application, string $shell): string
    {
        $command = $application->find('completion');

        $input = new ArrayInput([
            'shell' => $shell,
            '--no-interaction' => true,
        ]);

        $output = new BufferedOutput();
        $exitCode = $command->run($input, $output);

        if ($exitCode !== 0) {
            throw new \RuntimeException(sprintf('Failed to generate %s completion (exit code %d)', $shell, $exitCode));
        }

        return $output->fetch();
    }

    private function addSourceLine(string $rcFile, string $sourceLine, string $marker): void
    {
        $markerLine = '# '.$marker;
        $block = $markerLine."\n".$sourceLine;

        if ($this->filesystem->exists($rcFile)) {
            $content = $this->filesystem->readFile($rcFile);

            if (str_contains($content, $markerLine)) {
                $content = (string) preg_replace(
                    '/'.preg_quote($markerLine, '/').'\n.*/m',
                    $block,
                    $content,
                );
                $this->filesystem->dumpFile($rcFile, $content);

                return;
            }
        }

        $this->filesystem->appendToFile($rcFile, "\n".$block."\n");
    }

    private function getHomePath(): string
    {
        $home = getenv('HOME');

        if ($home === false || $home === '') {
            throw new \RuntimeException('HOME environment variable is not set.');
        }

        return $home;
    }
}
