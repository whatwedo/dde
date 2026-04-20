<?php

declare(strict_types=1);

namespace Tests\Unit\Manager;

use App\Manager\DockerManager;
use App\Manager\SystemLifecycleManager;
use App\Model\SystemLifecycleProgress;
use App\Service\ServiceInterface;
use App\Service\ServiceRegistry;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class SystemLifecycleManagerTest extends TestCase
{
    private ServiceRegistry&MockObject $serviceRegistry;

    private DockerManager&MockObject $dockerManager;

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

        $this->dockerManager->method('networkExists')->with('dde')->willReturn(false);
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

    protected function setUp(): void
    {
        $this->serviceRegistry = $this->createMock(ServiceRegistry::class);
        $this->dockerManager = $this->createMock(DockerManager::class);

        $this->manager = new SystemLifecycleManager(
            $this->serviceRegistry,
            $this->dockerManager,
        );
    }
}
