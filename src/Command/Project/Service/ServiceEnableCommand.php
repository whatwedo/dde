<?php

declare(strict_types=1);

namespace App\Command\Project\Service;

use App\Command\AbstractProjectCommand;
use App\Manager\ProjectConfigManager;
use App\Manager\ServiceConfigManager;
use App\Model\ServiceDefinition;
use App\Output\FormatterResolver;
use App\Service\ServiceRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'project:service:enable',
    description: 'Enable a service in the project config',
)]
final class ServiceEnableCommand extends AbstractProjectCommand
{
    public function __construct(
        ProjectConfigManager $configManager,
        private readonly ServiceRegistry $serviceRegistry,
        FormatterResolver $formatterResolver,
        private readonly ServiceConfigManager $serviceConfigManager = new ServiceConfigManager(),
    ) {
        parent::__construct($configManager, $formatterResolver);
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestArgumentValuesFor('service')) {
            try {
                $allServices = $this->serviceRegistry->getAllServiceTypes();
                $projectDir = $this->getProjectDirectory();
                $projectConfig = $this->configManager->loadProjectConfig($projectDir);
                $activeNames = array_map(
                    static fn (ServiceDefinition $svc): string => $svc->name,
                    $projectConfig->services,
                );
                $available = array_diff($allServices, $activeNames);
                $suggestions->suggestValues(array_values($available));
            } catch (\Throwable) {
                $suggestions->suggestValues(array_values($this->serviceRegistry->getAllServiceTypes()));
            }
        }
    }

    protected function configure(): void
    {
        $this
            ->addArgument('service', InputArgument::REQUIRED, 'Service name to enable (e.g. mariadb, valkey)')
            ->addOption('service-version', null, InputOption::VALUE_REQUIRED, 'Specific version to use');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = $this->resolveFormatter($output, $input);

        $serviceName = $input->getArgument('service');
        $version = $input->getOption('service-version');

        if (! is_string($serviceName) || $serviceName === '') {
            return $formatter->error('Service name is required.');
        }

        if (! $this->serviceRegistry->isKnownService($serviceName)) {
            return $formatter->error(sprintf(
                'Unknown service "%s". Supported services: %s',
                $serviceName,
                implode(', ', $this->serviceRegistry->getAllServiceTypes()),
            ));
        }

        $version = is_string($version) && $version !== '' ? $version : null;

        try {
            $projectDir = $this->getProjectDirectory();
        } catch (\RuntimeException $runtimeException) {
            return $formatter->error($runtimeException->getMessage());
        }

        $configPath = $projectDir.'/'.ProjectConfigManager::CONFIG_DIR.'/'.ProjectConfigManager::CONFIG_FILE;

        if (! $this->serviceConfigManager->configFileExists($configPath)) {
            return $formatter->error(sprintf('Config file not found: %s', $configPath));
        }

        $projectConfig = $this->configManager->loadProjectConfig($projectDir);

        // Check if service already enabled
        foreach ($projectConfig->services as $service) {
            if ($service->name === $serviceName && ($version === null || $service->version === $version)) {
                $message = sprintf("Service '%s' is already enabled.", $serviceName);

                return $formatter->success([
                    'status' => 'ok',
                    'service' => $serviceName,
                    'message' => $message,
                ], $message);
            }
        }

        $this->serviceConfigManager->enableService($serviceName, $configPath, $version);

        // Validate by reloading
        $this->configManager->loadProjectConfig($projectDir);

        $effectiveVersion = $version ?? $this->serviceRegistry->getServiceVersion($serviceName);
        $message = sprintf('Service %s enabled.', $serviceName);

        return $formatter->success([
            'status' => 'ok',
            'service' => $serviceName,
            'version' => $effectiveVersion,
            'message' => $message,
        ], $message);
    }
}
