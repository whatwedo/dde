<?php

declare(strict_types=1);

namespace App\Command\Project\Service;

use App\Command\AbstractProjectCommand;
use App\Manager\ConfigManager;
use App\Manager\ServiceConfigManager;
use App\Model\ServiceDefinition;
use App\Output\FormatterResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'project:service:disable',
    description: 'Disable a service in the project config',
)]
final class ServiceDisableCommand extends AbstractProjectCommand
{
    public function __construct(
        ConfigManager $configManager,
        FormatterResolver $formatterResolver,
        private readonly ServiceConfigManager $serviceConfigManager = new ServiceConfigManager(),
    ) {
        parent::__construct($configManager, $formatterResolver);
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestArgumentValuesFor('service')) {
            try {
                $projectDir = $this->getProjectDirectory();
                $projectConfig = $this->configManager->loadProjectConfig($projectDir);
                $activeNames = array_map(
                    static fn (ServiceDefinition $svc): string => $svc->name,
                    $projectConfig->services,
                );
                $suggestions->suggestValues(array_values($activeNames));
            } catch (\Throwable) {
                // gracefully return empty suggestions
            }
        }
    }

    protected function configure(): void
    {
        $this
            ->addArgument('service', InputArgument::REQUIRED, 'Service name to disable')
            ->addOption('service-version', null, InputOption::VALUE_REQUIRED, 'If multiple versions of same service, specify which one to remove');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = $this->resolveFormatter($output, $input);

        $serviceName = $input->getArgument('service');
        $version = $input->getOption('service-version');

        if (! is_string($serviceName) || $serviceName === '') {
            return $formatter->error('Service name is required.');
        }

        $version = is_string($version) && $version !== '' ? $version : null;

        try {
            $projectDir = $this->getProjectDirectory();
        } catch (\RuntimeException $runtimeException) {
            return $formatter->error($runtimeException->getMessage());
        }

        $configPath = $projectDir.'/'.ConfigManager::CONFIG_DIR.'/'.ConfigManager::CONFIG_FILE;

        if (! $this->serviceConfigManager->configFileExists($configPath)) {
            return $formatter->error(sprintf('Config file not found: %s', $configPath));
        }

        $removed = $this->serviceConfigManager->disableService($serviceName, $configPath, $version);

        if (! $removed) {
            $message = sprintf("Service '%s' not found.", $serviceName);

            return $formatter->success([
                'status' => 'ok',
                'service' => $serviceName,
                'message' => $message,
            ], $message);
        }

        // Validate by reloading
        $this->configManager->loadProjectConfig($projectDir);

        $message = sprintf('Service %s disabled.', $serviceName);

        return $formatter->success([
            'status' => 'ok',
            'service' => $serviceName,
            'message' => $message,
        ], $message);
    }
}
