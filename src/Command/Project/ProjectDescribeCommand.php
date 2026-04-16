<?php

declare(strict_types=1);

namespace App\Command\Project;

use App\Command\AbstractProjectCommand;
use App\Config\WorktreeInfo;
use App\Manager\DockerComposeManager;
use App\Manager\ProjectConfigManager;
use App\Manager\ProjectInfoManager;
use App\Output\FormatterResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'project:describe',
    description: 'Show detailed project information',
)]
final class ProjectDescribeCommand extends AbstractProjectCommand
{
    public function __construct(
        ProjectConfigManager $configManager,
        private readonly DockerComposeManager $dockerComposeManager,
        private readonly ProjectInfoManager $projectInfoManager,
        FormatterResolver $formatterResolver,
    ) {
        parent::__construct($configManager, $formatterResolver);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = $this->resolveFormatter($output, $input);
        $io = new SymfonyStyle($input, $output);

        try {
            $projectDir = $this->getProjectDirectory();
        } catch (\RuntimeException $runtimeException) {
            return $formatter->error($runtimeException->getMessage());
        }

        $config = $this->getResolvedConfig();
        $liveContainers = $this->dockerComposeManager->ps($projectDir);

        $worktreeInfo = $this->configManager->detectWorktree($projectDir);
        $hostname = $this->configManager->resolveProjectHostname($config->projectName, $worktreeInfo);
        $url = sprintf('https://%s', $hostname);
        $worktreeData = null;

        if ($worktreeInfo instanceof WorktreeInfo) {
            $worktreeData = [
                'branch' => $worktreeInfo->branch,
                'suffix' => $this->configManager->sanitizeWorktreeSuffix($worktreeInfo->suffix, $config->projectName),
                'mainDirectory' => $worktreeInfo->mainDirectory,
            ];
        }

        $services = $this->projectInfoManager->buildServiceData($config);
        $containers = $this->projectInfoManager->buildContainerData($config, $liveContainers);
        $hooks = $this->projectInfoManager->countHooks($projectDir);
        $plugins = $this->projectInfoManager->scanPlugins($projectDir);

        if (!$formatter->isInteractive()) {
            return $formatter->success([
                'project' => $config->projectName,
                'url' => $url,
                'directory' => $projectDir,
                'services' => $services,
                'containers' => $containers,
                'hooks' => $hooks,
                'plugins' => $plugins,
                'worktree' => $worktreeData,
            ]);
        }

        $io->title(sprintf('Project: %s', $config->projectName));

        $io->section('General');

        $generalListing = [
            sprintf('URL: %s', $url),
            sprintf('Directory: %s', $projectDir),
        ];

        if ($worktreeData !== null) {
            $generalListing[] = sprintf('Worktree: %s (branch: %s)', $worktreeData['suffix'], $worktreeData['branch']);
        }

        $io->listing($generalListing);

        if ($services !== []) {
            $io->section('Services');
            $serviceHeaders = ['Name', 'Version', 'Host', 'Port', 'Type'];
            $serviceRows = [];

            foreach ($services as $s) {
                $serviceRows[] = [
                    $s['name'],
                    $s['version'],
                    $s['host'],
                    (string) $s['port'],
                    $s['type'],
                ];
            }

            $formatter->table($serviceHeaders, $serviceRows);
        }

        if ($containers !== []) {
            $io->section('Containers');
            $containerHeaders = ['Name', 'Shell', 'Status'];
            $containerRows = [];

            foreach ($containers as $c) {
                $containerRows[] = [
                    $c['name'],
                    $c['shell'],
                    $c['status'],
                ];
            }

            $formatter->table($containerHeaders, $containerRows);
        }

        $io->section('Hooks');
        $hookEntries = [];

        foreach ($hooks as $hookName => $count) {
            $hookEntries[] = sprintf('%s: %d', $hookName, $count);
        }

        $io->listing($hookEntries);

        if ($plugins !== []) {
            $io->section('Plugins');
            $io->listing($plugins);
        }

        return self::SUCCESS;
    }
}
