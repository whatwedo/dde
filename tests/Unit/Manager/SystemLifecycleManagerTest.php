<?php

declare(strict_types=1);

namespace Tests\Unit\Manager;

use App\Manager\ClaudeCodeManager;
use App\Manager\CompletionManager;
use App\Manager\DockerManager;
use App\Manager\SystemLifecycleManager;
use App\Model\ContainerInfo;
use App\Model\ContainerStatus;
use App\Model\SystemLifecycleProgress;
use App\Service\ServiceInterface;
use App\Service\ServiceRegistry;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;

#[AllowMockObjectsWithoutExpectations]
final class SystemLifecycleManagerTest extends TestCase
{
    private ServiceRegistry&MockObject $serviceRegistry;

    private DockerManager&MockObject $dockerManager;

    private CompletionManager&MockObject $completionManager;

    private ClaudeCodeManager&MockObject $claudeCodeManager;

    private SystemLifecycleManager $manager;

    public function testUpEnsuresNetworkAndStartsServicesInOrder(): void
    {
        $serviceA = $this->createMock(ServiceInterface::class);
        $serviceA->method('getName')->willReturn('traefik');
        $serviceB = $this->createMock(ServiceInterface::class);
        $serviceB->method('getName')->willReturn('dnsmasq');

        $this->serviceRegistry
            ->method('getGlobalServices')
            ->willReturn([$serviceA, $serviceB]);

        $this->dockerManager->method('networkExists')->willReturnMap([['dde', false]]);
        $this->dockerManager->expects($this->once())->method('createNetwork')->with('dde');

        $serviceA->expects($this->once())->method('start');
        $serviceB->expects($this->once())->method('start');

        $result = $this->manager->up();

        $this->assertSame(
            [[
                'name' => 'traefik',
                'status' => 'started',
            ], [
                'name' => 'dnsmasq',
                'status' => 'started',
            ]],
            $result['globalServices']
        );
    }

    public function testUpReportsAlreadyRunningServices(): void
    {
        $service = $this->createMock(ServiceInterface::class);
        $service->method('getName')->willReturn('traefik');
        $service->method('isRunning')->willReturn(true);

        $this->serviceRegistry
            ->method('getGlobalServices')
            ->willReturn([$service]);

        $service->expects($this->once())->method('start');

        $result = $this->manager->up();

        $this->assertSame('already_running', $result['globalServices'][0]['status']);
    }

    public function testUpEmitsStartingAndStartedEvents(): void
    {
        $service = $this->createMock(ServiceInterface::class);
        $service->method('getName')->willReturn('traefik');
        $service->method('isRunning')->willReturn(false);

        $this->serviceRegistry
            ->method('getGlobalServices')
            ->willReturn([$service]);

        $this->dockerManager->method('networkExists')->willReturn(true);

        /** @var list<array{event: SystemLifecycleProgress, name: string, container: ?string, detail: ?string}> $events */
        $events = [];

        $this->manager->up(static function (SystemLifecycleProgress $event, string $name, ?string $container, ?string $detail) use (&$events): void {
            $events[] = [
                'event' => $event,
                'name' => $name,
                'container' => $container,
                'detail' => $detail,
            ];
        });

        $this->assertCount(2, $events);
        $this->assertSame(SystemLifecycleProgress::Starting, $events[0]['event']);
        $this->assertSame('traefik', $events[0]['name']);
        $this->assertSame(SystemLifecycleProgress::Started, $events[1]['event']);
        $this->assertSame('traefik', $events[1]['name']);
    }

    public function testUpEmitsAlreadyRunningForRunningServices(): void
    {
        $service = $this->createMock(ServiceInterface::class);
        $service->method('getName')->willReturn('traefik');
        $service->method('isRunning')->willReturn(true);

        $this->serviceRegistry
            ->method('getGlobalServices')
            ->willReturn([$service]);

        $this->dockerManager->method('networkExists')->willReturn(true);

        /** @var list<SystemLifecycleProgress> $events */
        $events = [];

        $this->manager->up(static function (SystemLifecycleProgress $event) use (&$events): void {
            $events[] = $event;
        });

        $this->assertSame([SystemLifecycleProgress::Starting, SystemLifecycleProgress::AlreadyRunning], $events);
    }

    public function testStopHaltsGlobalServicesInReverseOrderAndVersionedContainers(): void
    {
        $serviceA = $this->createMock(ServiceInterface::class);
        $serviceA->method('getName')->willReturn('traefik');
        $serviceB = $this->createMock(ServiceInterface::class);
        $serviceB->method('getName')->willReturn('dnsmasq');

        $this->serviceRegistry
            ->method('getGlobalServices')
            ->willReturn([$serviceA, $serviceB]);

        $callOrder = [];
        $serviceA->method('stop')->willReturnCallback(
            static function () use (&$callOrder): void {
                $callOrder[] = 'traefik';
            }
        );
        $serviceB->method('stop')->willReturnCallback(
            static function () use (&$callOrder): void {
                $callOrder[] = 'dnsmasq';
            }
        );

        $versionedContainer = new ContainerInfo(
            name: 'project-db-mariadb',
            status: ContainerStatus::RUNNING,
            image: 'mariadb:10.5',
            labels: [
                'dde.service' => 'mariadb',
            ],
        );

        $this->dockerManager
            ->method('getContainersByLabel')
            ->willReturnMap([['dde.service', [$versionedContainer]]]);

        $this->dockerManager
            ->expects($this->once())
            ->method('stop')
            ->with('project-db-mariadb');

        $this->dockerManager
            ->expects($this->never())
            ->method('remove');

        $result = $this->manager->stop();

        $this->assertSame(['dnsmasq', 'traefik'], $callOrder);
        $this->assertCount(1, $result['versionedContainers']);
        $this->assertSame('project-db-mariadb', $result['versionedContainers'][0]['name']);
    }

    public function testStopEmitsStoppingAndStoppedEventsInReverseOrder(): void
    {
        $serviceA = $this->createMock(ServiceInterface::class);
        $serviceA->method('getName')->willReturn('traefik');
        $serviceA->method('isRunning')->willReturn(true);
        $serviceB = $this->createMock(ServiceInterface::class);
        $serviceB->method('getName')->willReturn('dnsmasq');
        $serviceB->method('isRunning')->willReturn(false);

        $this->serviceRegistry
            ->method('getGlobalServices')
            ->willReturn([$serviceA, $serviceB]);

        $versionedContainer = new ContainerInfo(
            name: 'project-db-mariadb',
            status: ContainerStatus::RUNNING,
            image: 'mariadb:10.5',
            labels: [
                'dde.service' => 'mariadb',
            ],
        );

        $this->dockerManager
            ->method('getContainersByLabel')
            ->willReturnMap([['dde.service', [$versionedContainer]]]);

        /** @var list<array{event: SystemLifecycleProgress, name: string}> $events */
        $events = [];

        $this->manager->stop(static function (SystemLifecycleProgress $event, string $name) use (&$events): void {
            $events[] = [
                'event' => $event,
                'name' => $name,
            ];
        });

        $this->assertSame(
            [
                [
                    'event' => SystemLifecycleProgress::Stopping,
                    'name' => 'dnsmasq',
                ],
                [
                    'event' => SystemLifecycleProgress::AlreadyStopped,
                    'name' => 'dnsmasq',
                ],
                [
                    'event' => SystemLifecycleProgress::Stopping,
                    'name' => 'traefik',
                ],
                [
                    'event' => SystemLifecycleProgress::Stopped,
                    'name' => 'traefik',
                ],
                [
                    'event' => SystemLifecycleProgress::Stopping,
                    'name' => 'project-db-mariadb',
                ],
                [
                    'event' => SystemLifecycleProgress::Stopped,
                    'name' => 'project-db-mariadb',
                ],
            ],
            $events,
        );
    }

    public function testDownRemovesGlobalServicesAndVersionedContainers(): void
    {
        $service = $this->createMock(ServiceInterface::class);
        $service->method('getName')->willReturn('traefik');

        $this->serviceRegistry
            ->method('getGlobalServices')
            ->willReturn([$service]);

        $service->expects($this->once())->method('remove');

        $versionedContainer = new ContainerInfo(
            name: 'project-db-mariadb',
            status: ContainerStatus::RUNNING,
            image: 'mariadb:10.5',
            labels: [
                'dde.service' => 'mariadb',
            ],
        );

        $this->dockerManager
            ->method('getContainersByLabel')
            ->willReturnMap([['dde.service', [$versionedContainer]]]);

        $this->dockerManager
            ->expects($this->once())
            ->method('stop')
            ->with('project-db-mariadb');

        $this->dockerManager
            ->expects($this->once())
            ->method('remove')
            ->with('project-db-mariadb');

        $result = $this->manager->down();

        $this->assertSame('traefik', $result['globalServices'][0]['name']);
        $this->assertSame('removed', $result['globalServices'][0]['status']);
        $this->assertSame('removed', $result['versionedContainers'][0]['status']);
    }

    public function testDownEmitsRemovingAndRemovedEvents(): void
    {
        $service = $this->createMock(ServiceInterface::class);
        $service->method('getName')->willReturn('traefik');

        $this->serviceRegistry
            ->method('getGlobalServices')
            ->willReturn([$service]);

        $versionedContainer = new ContainerInfo(
            name: 'project-db-mariadb',
            status: ContainerStatus::RUNNING,
            image: 'mariadb:10.5',
            labels: [
                'dde.service' => 'mariadb',
            ],
        );

        $this->dockerManager
            ->method('getContainersByLabel')
            ->willReturnMap([['dde.service', [$versionedContainer]]]);

        /** @var list<array{event: SystemLifecycleProgress, name: string}> $events */
        $events = [];

        $this->manager->down(static function (SystemLifecycleProgress $event, string $name) use (&$events): void {
            $events[] = [
                'event' => $event,
                'name' => $name,
            ];
        });

        $this->assertSame(
            [
                [
                    'event' => SystemLifecycleProgress::Removing,
                    'name' => 'traefik',
                ],
                [
                    'event' => SystemLifecycleProgress::Removed,
                    'name' => 'traefik',
                ],
                [
                    'event' => SystemLifecycleProgress::Removing,
                    'name' => 'project-db-mariadb',
                ],
                [
                    'event' => SystemLifecycleProgress::Removed,
                    'name' => 'project-db-mariadb',
                ],
            ],
            $events,
        );
    }

    public function testUpdateRunsDownThenBuildWithPullThenUpThenPostInstall(): void
    {
        $service = $this->createMock(ServiceInterface::class);
        $service->method('getName')->willReturn('traefik');

        $this->serviceRegistry
            ->method('getGlobalServices')
            ->willReturn([$service]);

        $this->dockerManager
            ->method('getContainersByLabel')
            ->willReturn([]);

        $this->dockerManager->method('networkExists')->willReturnMap([['dde', false]]);
        $this->dockerManager->method('createNetwork');

        $callOrder = [];
        $service->method('remove')->willReturnCallback(
            static function () use (&$callOrder): void {
                $callOrder[] = 'remove';
            }
        );
        $service->method('build')->willReturnCallback(
            static function (bool $pull) use (&$callOrder): void {
                $callOrder[] = $pull ? 'build-pull' : 'build';
            }
        );
        $service->method('start')->willReturnCallback(
            static function () use (&$callOrder): void {
                $callOrder[] = 'start';
            }
        );

        $this->completionManager
            ->expects($this->once())
            ->method('installCompletion');

        $this->claudeCodeManager
            ->method('isClaudeCodeInstalled')
            ->willReturn(true);

        $this->claudeCodeManager
            ->expects($this->once())
            ->method('installSkill');

        $result = $this->manager->update(new Application());

        $this->assertSame(['remove', 'build-pull', 'start'], $callOrder);
        $this->assertSame([], $result['postInstallWarnings']);
    }

    public function testUpdateSkipsClaudeSkillWhenClaudeNotInstalled(): void
    {
        $this->serviceRegistry->method('getGlobalServices')->willReturn([]);
        $this->dockerManager->method('getContainersByLabel')->willReturn([]);
        $this->dockerManager->method('networkExists')->willReturnMap([['dde', true]]);

        $this->claudeCodeManager
            ->method('isClaudeCodeInstalled')
            ->willReturn(false);

        $this->claudeCodeManager
            ->expects($this->never())
            ->method('installSkill');

        $this->manager->update(new Application());
    }

    public function testUpdateCollectsPostInstallErrorsAsWarnings(): void
    {
        $this->serviceRegistry->method('getGlobalServices')->willReturn([]);
        $this->dockerManager->method('getContainersByLabel')->willReturn([]);
        $this->dockerManager->method('networkExists')->willReturnMap([['dde', true]]);

        $this->completionManager
            ->method('installCompletion')
            ->willThrowException(new \RuntimeException('completion broken'));

        $this->claudeCodeManager->method('isClaudeCodeInstalled')->willReturn(false);

        $result = $this->manager->update(new Application());

        $this->assertCount(1, $result['postInstallWarnings']);
        $this->assertStringContainsString('completion broken', $result['postInstallWarnings'][0]);
    }

    public function testUpdateEmitsBuildAndPostInstallEvents(): void
    {
        $service = $this->createMock(ServiceInterface::class);
        $service->method('getName')->willReturn('traefik');

        $this->serviceRegistry
            ->method('getGlobalServices')
            ->willReturn([$service]);

        $this->dockerManager->method('getContainersByLabel')->willReturn([]);
        $this->dockerManager->method('networkExists')->willReturnMap([['dde', true]]);

        $this->claudeCodeManager->method('isClaudeCodeInstalled')->willReturn(false);

        /** @var list<SystemLifecycleProgress> $events */
        $events = [];

        $this->manager->update(
            new Application(),
            static function (SystemLifecycleProgress $event) use (&$events): void {
                $events[] = $event;
            },
        );

        $this->assertSame(
            [
                // down(): remove traefik
                SystemLifecycleProgress::Removing,
                SystemLifecycleProgress::Removed,
                // build traefik
                SystemLifecycleProgress::Building,
                SystemLifecycleProgress::Built,
                // up(): start traefik
                SystemLifecycleProgress::Starting,
                SystemLifecycleProgress::Started,
                // post-install: traefik-network
                SystemLifecycleProgress::PostInstallStarting,
                SystemLifecycleProgress::PostInstallOk,
                // post-install: shell-completion
                SystemLifecycleProgress::PostInstallStarting,
                SystemLifecycleProgress::PostInstallOk,
            ],
            $events,
        );
    }

    public function testUpdatePostInstallFailedCarriesErrorDetail(): void
    {
        $this->serviceRegistry->method('getGlobalServices')->willReturn([]);
        $this->dockerManager->method('getContainersByLabel')->willReturn([]);
        $this->dockerManager->method('networkExists')->willReturnMap([['dde', true]]);

        $this->completionManager
            ->method('installCompletion')
            ->willThrowException(new \RuntimeException('completion broken'));

        $this->claudeCodeManager->method('isClaudeCodeInstalled')->willReturn(false);

        /** @var list<array{event: SystemLifecycleProgress, name: string, detail: ?string}> $failures */
        $failures = [];

        $this->manager->update(
            new Application(),
            static function (SystemLifecycleProgress $event, string $name, ?string $container, ?string $detail) use (&$failures): void {
                if ($event === SystemLifecycleProgress::PostInstallFailed) {
                    $failures[] = [
                        'event' => $event,
                        'name' => $name,
                        'detail' => $detail,
                    ];
                }
            },
        );

        $this->assertCount(1, $failures);
        $this->assertSame('shell-completion', $failures[0]['name']);
        $this->assertSame('completion broken', $failures[0]['detail']);
    }

    protected function setUp(): void
    {
        $this->serviceRegistry = $this->createMock(ServiceRegistry::class);
        $this->dockerManager = $this->createMock(DockerManager::class);
        $this->completionManager = $this->createMock(CompletionManager::class);
        $this->claudeCodeManager = $this->createMock(ClaudeCodeManager::class);

        $this->manager = new SystemLifecycleManager(
            $this->serviceRegistry,
            $this->dockerManager,
            $this->completionManager,
            $this->claudeCodeManager,
            '/tmp/dde-config',
        );
    }
}
