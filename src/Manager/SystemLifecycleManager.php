<?php

declare(strict_types=1);

namespace App\Manager;

use App\Model\SystemLifecycleProgress;
use App\Service\AbstractSystemService;
use App\Service\ServiceInterface;
use App\Service\ServiceRegistry;

readonly class SystemLifecycleManager
{
    public function __construct(
        private ServiceRegistry $serviceRegistry,
        private DockerManager $dockerManager,
    ) {
    }

    /**
     * @param (\Closure(SystemLifecycleProgress, string, ?string, ?string): void)|null $onProgress
     *
     * @return array{globalServices: list<array{name: string, status: string}>}
     */
    public function up(?\Closure $onProgress = null): array
    {
        $this->ensureDdeNetwork();

        $globalServices = [];

        foreach ($this->serviceRegistry->getGlobalServices() as $service) {
            $wasRunning = $service->isRunning();
            $container = $this->resolveContainerName($service);

            if ($onProgress instanceof \Closure) {
                $onProgress(SystemLifecycleProgress::Starting, $service->getName(), $container, null);
            }

            $service->start();

            if ($onProgress instanceof \Closure) {
                $onProgress(
                    $wasRunning ? SystemLifecycleProgress::AlreadyRunning : SystemLifecycleProgress::Started,
                    $service->getName(),
                    $container,
                    null,
                );
            }

            $globalServices[] = [
                'name' => $service->getName(),
                'status' => $wasRunning ? 'already_running' : 'started',
            ];
        }

        return [
            'globalServices' => $globalServices,
        ];
    }

    private function ensureDdeNetwork(): void
    {
        if (! $this->dockerManager->networkExists('dde')) {
            $this->dockerManager->createNetwork('dde');
        }
    }

    private function resolveContainerName(ServiceInterface $service): ?string
    {
        if ($service instanceof AbstractSystemService) {
            return $service->getContainerName();
        }

        return null;
    }
}
