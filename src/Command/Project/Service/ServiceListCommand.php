<?php

declare(strict_types=1);

namespace App\Command\Project\Service;

use App\Command\AbstractProjectCommand;
use App\Manager\ProjectConfigManager;
use App\Output\FormatterResolver;
use App\Service\ServiceRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'project:service:list',
    description: 'List available and active services',
)]
final class ServiceListCommand extends AbstractProjectCommand
{
    public function __construct(
        ProjectConfigManager $configManager,
        private readonly ServiceRegistry $serviceRegistry,
        FormatterResolver $formatterResolver,
    ) {
        parent::__construct($configManager, $formatterResolver);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $formatter = $this->resolveFormatter($output, $input);

        try {
            $projectDir = $this->getProjectDirectory();
        } catch (\RuntimeException $runtimeException) {
            return $formatter->error($runtimeException->getMessage());
        }

        $projectConfig = $this->configManager->loadProjectConfig($projectDir);

        // Index active services by name
        $activeServices = [];

        foreach ($projectConfig->services as $service) {
            $activeServices[$service->name] = $service->version;
        }

        $allServiceTypes = $this->serviceRegistry->getAllServiceTypes();
        $services = [];

        foreach ($allServiceTypes as $serviceType) {
            $isActive = array_key_exists($serviceType, $activeServices);
            $version = $isActive ? $activeServices[$serviceType] : null;

            // Resolve "latest" to the actual default version
            if ($version === 'latest') {
                $version = $this->serviceRegistry->getServiceVersion($serviceType);
            }

            $services[] = [
                'name' => $serviceType,
                'status' => $isActive ? 'active' : 'available',
                'version' => $version,
            ];
        }

        if (!$formatter->isInteractive()) {
            return $formatter->success([
                'services' => $services,
            ]);
        }

        $rows = [];

        foreach ($services as $service) {
            $rows[] = [
                $service['name'],
                $service['status'] === 'active' ? '✓ active' : 'available',
                $service['version'] ?? '-',
            ];
        }

        $formatter->table(['Name', 'Status', 'Version'], $rows);

        return self::SUCCESS;
    }
}
