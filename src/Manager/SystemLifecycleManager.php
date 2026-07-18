<?php

declare(strict_types=1);

namespace App\Manager;

use App\Model\SystemLifecycleProgress;
use App\Service\AbstractSystemService;
use App\Service\ServiceInterface;
use App\Service\ServiceRegistry;
use Symfony\Component\Console\Application;

readonly class SystemLifecycleManager
{
    public function __construct(
        private ServiceRegistry $serviceRegistry,
        private DockerManager $dockerManager,
        private CompletionManager $completionManager,
        private ClaudeCodeManager $claudeCodeManager,
        private MkcertManager $mkcertManager,
        private string $configDir,
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
        $this->mkcertManager->ensureSystemCertificate();

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

    /**
     * @param (\Closure(SystemLifecycleProgress, string, ?string, ?string): void)|null $onProgress
     *
     * @return array{
     *     globalServices: list<array{name: string, status: string}>,
     *     versionedContainers: list<array{name: string, status: string}>,
     * }
     */
    public function stop(?\Closure $onProgress = null): array
    {
        $globalServices = [];

        foreach (array_reverse($this->serviceRegistry->getGlobalServices()) as $service) {
            $wasRunning = $service->isRunning();
            $container = $this->resolveContainerName($service);

            if ($onProgress instanceof \Closure) {
                $onProgress(SystemLifecycleProgress::Stopping, $service->getName(), $container, null);
            }

            $service->stop();

            if ($onProgress instanceof \Closure) {
                $onProgress(
                    $wasRunning ? SystemLifecycleProgress::Stopped : SystemLifecycleProgress::AlreadyStopped,
                    $service->getName(),
                    $container,
                    null,
                );
            }

            $globalServices[] = [
                'name' => $service->getName(),
                'status' => $wasRunning ? 'stopped' : 'already_stopped',
            ];
        }

        $versionedContainers = [];

        foreach ($this->dockerManager->getContainersByLabel('dde.service') as $container) {
            if ($onProgress instanceof \Closure) {
                $onProgress(SystemLifecycleProgress::Stopping, $container->name, $container->name, null);
            }

            $this->dockerManager->stop($container->name);

            if ($onProgress instanceof \Closure) {
                $onProgress(SystemLifecycleProgress::Stopped, $container->name, $container->name, null);
            }

            $versionedContainers[] = [
                'name' => $container->name,
                'status' => 'stopped',
            ];
        }

        return [
            'globalServices' => $globalServices,
            'versionedContainers' => $versionedContainers,
        ];
    }

    /**
     * @param (\Closure(SystemLifecycleProgress, string, ?string, ?string): void)|null $onProgress
     *
     * @return array{
     *     globalServices: list<array{name: string, status: string}>,
     *     versionedContainers: list<array{name: string, status: string}>,
     * }
     */
    public function down(?\Closure $onProgress = null): array
    {
        $globalServices = [];

        foreach (array_reverse($this->serviceRegistry->getGlobalServices()) as $service) {
            $container = $this->resolveContainerName($service);

            if ($onProgress instanceof \Closure) {
                $onProgress(SystemLifecycleProgress::Removing, $service->getName(), $container, null);
            }

            $service->remove();

            if ($onProgress instanceof \Closure) {
                $onProgress(SystemLifecycleProgress::Removed, $service->getName(), $container, null);
            }

            $globalServices[] = [
                'name' => $service->getName(),
                'status' => 'removed',
            ];
        }

        $versionedContainers = [];

        foreach ($this->dockerManager->getContainersByLabel('dde.service') as $container) {
            if ($onProgress instanceof \Closure) {
                $onProgress(SystemLifecycleProgress::Removing, $container->name, $container->name, null);
            }

            $this->dockerManager->stop($container->name);
            $this->dockerManager->remove($container->name);

            if ($onProgress instanceof \Closure) {
                $onProgress(SystemLifecycleProgress::Removed, $container->name, $container->name, null);
            }

            $versionedContainers[] = [
                'name' => $container->name,
                'status' => 'removed',
            ];
        }

        return [
            'globalServices' => $globalServices,
            'versionedContainers' => $versionedContainers,
        ];
    }

    /**
     * @param (\Closure(SystemLifecycleProgress, string, ?string, ?string): void)|null $onProgress
     *
     * @return array{
     *     globalServices: list<array{name: string, status: string}>,
     *     versionedContainers: list<array{name: string, status: string}>,
     *     postInstallWarnings: list<string>,
     * }
     */
    public function update(Application $application, ?\Closure $onProgress = null): array
    {
        $down = $this->down($onProgress);

        foreach ($this->serviceRegistry->getGlobalServices() as $service) {
            $container = $this->resolveContainerName($service);

            if ($onProgress instanceof \Closure) {
                $onProgress(SystemLifecycleProgress::Building, $service->getName(), $container, null);
            }

            $service->build(pull: true);

            if ($onProgress instanceof \Closure) {
                $onProgress(SystemLifecycleProgress::Built, $service->getName(), $container, null);
            }
        }

        $up = $this->up($onProgress);

        $warnings = [];

        $warnings = $this->runPostInstallStep(
            'traefik-network',
            fn () => $this->ensureDdeNetwork(),
            $warnings,
            $onProgress,
        );

        $warnings = $this->runPostInstallStep(
            'shell-completion',
            fn () => $this->completionManager->installCompletion($this->configDir, $application),
            $warnings,
            $onProgress,
        );

        if ($this->claudeCodeManager->isClaudeCodeInstalled()) {
            $warnings = $this->runPostInstallStep(
                'claude-skill',
                fn () => $this->claudeCodeManager->installSkill(),
                $warnings,
                $onProgress,
            );
        }

        return [
            'globalServices' => $up['globalServices'],
            'versionedContainers' => $down['versionedContainers'],
            'postInstallWarnings' => $warnings,
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

    /**
     * @param list<string>                                                             $warnings
     * @param (\Closure(SystemLifecycleProgress, string, ?string, ?string): void)|null $onProgress
     *
     * @return list<string>
     */
    private function runPostInstallStep(string $name, \Closure $callback, array $warnings, ?\Closure $onProgress = null): array
    {
        if ($onProgress instanceof \Closure) {
            $onProgress(SystemLifecycleProgress::PostInstallStarting, $name, null, null);
        }

        try {
            $callback();

            if ($onProgress instanceof \Closure) {
                $onProgress(SystemLifecycleProgress::PostInstallOk, $name, null, null);
            }

            return $warnings;
        } catch (\Throwable $throwable) {
            if ($onProgress instanceof \Closure) {
                $onProgress(SystemLifecycleProgress::PostInstallFailed, $name, null, $throwable->getMessage());
            }

            $warnings[] = sprintf('%s: %s', $name, $throwable->getMessage());

            return $warnings;
        }
    }
}
