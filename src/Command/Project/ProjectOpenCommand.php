<?php

declare(strict_types=1);

namespace App\Command\Project;

use App\Command\AbstractProjectCommand;
use App\Manager\DockerComposeManager;
use App\Manager\MkcertManager;
use App\Manager\ProjectConfigManager;
use App\Manager\WorktreeManager;
use App\Output\FormatterResolver;
use App\Util\UrlOpenerUtil;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'project:open',
    description: 'Open the project in the default browser',
    aliases: ['open'],
)]
final class ProjectOpenCommand extends AbstractProjectCommand
{
    public function __construct(
        ProjectConfigManager $configManager,
        FormatterResolver $formatterResolver,
        private readonly DockerComposeManager $dockerComposeManager,
        private readonly MkcertManager $mkcertManager,
        private readonly WorktreeManager $worktreeManager,
        private readonly UrlOpenerUtil $urlOpenerUtil = new UrlOpenerUtil(),
    ) {
        parent::__construct($configManager, $formatterResolver);
    }

    protected function configure(): void
    {
        $this->addOption('url-only', null, InputOption::VALUE_NONE, 'Only print the URL without opening the browser');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = $this->resolveFormatter($output, $input);

        try {
            $projectDir = $this->getProjectDirectory();
        } catch (\RuntimeException $runtimeException) {
            return $formatter->error($runtimeException->getMessage());
        }

        $config = $this->getResolvedConfig();
        $url = $this->resolveProjectUrl($config->projectName, $projectDir);

        if (! $formatter->isInteractive()) {
            return $formatter->success([
                'url' => $url,
            ]);
        }

        if ($input->getOption('url-only')) {
            $output->writeln($url);

            return self::SUCCESS;
        }

        $this->urlOpenerUtil->open($url);
        $output->writeln(sprintf('Opening <info>%s</info>', $url));

        return self::SUCCESS;
    }

    private function resolveProjectUrl(string $projectName, string $projectDir): string
    {
        // Prefer the actual hostname from Traefik labels in the compose file
        $composeFile = $this->dockerComposeManager->findComposeFileOrNull($projectDir);

        if ($composeFile !== null) {
            $domains = $this->mkcertManager->extractDomainsFromComposeFile($composeFile);

            if ($domains !== []) {
                return sprintf('https://%s', $domains[0]);
            }
        }

        // Fallback: construct from project name
        $worktreeInfo = $this->worktreeManager->detect($projectDir);
        $hostname = $this->worktreeManager->resolveHostname($projectName, $worktreeInfo);

        return sprintf('https://%s', $hostname);
    }
}
