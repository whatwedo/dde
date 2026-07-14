<?php

declare(strict_types=1);

namespace App\Plugin;

use App\Util\ProcessFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

final class PluginProxyCommand extends Command
{
    public function __construct(
        private readonly PluginDefinition $plugin,
        private readonly ProcessFactory $processFactory = new ProcessFactory(),
    ) {
        parent::__construct('project:'.$plugin->command);
    }

    protected function configure(): void
    {
        $this
            ->setDescription($this->plugin->description)
            ->addArgument('args', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Arguments passed to the plugin script');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $realScript = realpath($this->plugin->scriptPath);

        if ($realScript === false) {
            $output->writeln(sprintf(
                '<error>Plugin script "%s" does not exist.</error>',
                $this->plugin->scriptPath,
            ));
            return self::FAILURE;
        }

        if ($this->plugin->pluginDir !== null) {
            $realPluginDir = realpath($this->plugin->pluginDir);
            if ($realPluginDir !== false && !str_starts_with($realScript, $realPluginDir)) {
                $output->writeln(sprintf(
                    '<error>Plugin script "%s" resolves outside plugin directory, skipped.</error>',
                    $this->plugin->scriptPath,
                ));
                return self::FAILURE;
            }
        }

        if (!is_executable($realScript)) {
            $output->writeln(sprintf(
                '<error>Plugin script "%s" is not executable. Run: chmod +x %s</error>',
                $this->plugin->scriptPath,
                $this->plugin->scriptPath,
            ));
            return self::FAILURE;
        }

        $args = $input->getArgument('args');
        /** @var list<string> $command */
        $command = array_merge([$this->plugin->scriptPath], is_array($args) ? array_values($args) : []);

        // No timeout: plugins may run arbitrarily long (deploys, imports,
        // shells). The developer watches the output and aborts with Ctrl-C.
        $process = $this->processFactory->create($command, null, null);

        if (Process::isTtySupported() && $output instanceof ConsoleOutputInterface) {
            $process->setTty(true);
            $process->run();
        } else {
            $process->run(function (string $type, string $buffer) use ($output): void {
                $output->write($buffer);
            });
        }

        return $process->getExitCode() ?? self::SUCCESS;
    }
}
