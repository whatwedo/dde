<?php

declare(strict_types=1);

namespace App\Command;

use App\Application;
use App\Output\FormatterResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\Kernel;

#[AsCommand(
    name: 'about',
    description: 'Show information about dde',
)]
final class AboutCommand extends AbstractBaseCommand
{
    public function __construct(
        FormatterResolver $formatterResolver,
        private readonly string $configDir,
        private readonly string $dataDir,
    ) {
        parent::__construct($formatterResolver);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $info = $this->gatherInfo();
        $formatter = $this->resolveFormatter($output, $input);

        if (!$formatter->isInteractive()) {
            return $formatter->success($info);
        }

        $io = new SymfonyStyle($input, $output);
        $io->title('dde - Docker Development Environment');

        $io->table([], [
            ['Version', $info['version']],
            ['PHP', $info['php']],
            ['Symfony', $info['symfony']],
            ['Config directory', $info['config_dir']],
            ['Data directory', $info['data_dir']],
        ]);

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function gatherInfo(): array
    {
        return [
            'version' => Application::APP_VERSION,
            'php' => PHP_VERSION,
            'symfony' => Kernel::VERSION,
            'config_dir' => $this->configDir,
            'data_dir' => $this->dataDir,
        ];
    }
}
