<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Manager\DockerManager;
use App\Model\ContainerConfig;
use App\Model\ServiceStatus;
use App\Service\AbstractSystemService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class AbstractSystemServiceTest extends TestCase
{
    private DockerManager&MockObject $dockerManager;

    private AbstractSystemService $service;

    public function testStartSkipsWhenAlreadyRunning(): void
    {
        $this->dockerManager
            ->expects($this->once())
            ->method('isContainerRunning')
            ->with('dde-test')
            ->willReturn(true);

        $this->dockerManager
            ->expects($this->never())
            ->method('run');

        $this->dockerManager
            ->expects($this->never())
            ->method('start');

        $this->service->start();
    }

    public function testStartCallsRunWhenContainerDoesNotExist(): void
    {
        $this->dockerManager
            ->expects($this->once())
            ->method('isContainerRunning')
            ->with('dde-test')
            ->willReturn(false);

        $this->dockerManager
            ->expects($this->once())
            ->method('containerExists')
            ->with('dde-test')
            ->willReturn(false);

        $this->dockerManager
            ->expects($this->once())
            ->method('run')
            ->with($this->isInstanceOf(ContainerConfig::class));

        $this->dockerManager
            ->expects($this->never())
            ->method('start');

        $this->service->start();
    }

    public function testStartReactivatesExistingStoppedContainer(): void
    {
        $this->dockerManager
            ->expects($this->once())
            ->method('isContainerRunning')
            ->with('dde-test')
            ->willReturn(false);

        $this->dockerManager
            ->expects($this->once())
            ->method('containerExists')
            ->with('dde-test')
            ->willReturn(true);

        $this->dockerManager
            ->expects($this->once())
            ->method('start')
            ->with('dde-test');

        $this->dockerManager
            ->expects($this->never())
            ->method('run');

        $this->service->start();
    }

    public function testStopSkipsWhenNotRunning(): void
    {
        $this->dockerManager
            ->expects($this->once())
            ->method('isContainerRunning')
            ->with('dde-test')
            ->willReturn(false);

        $this->dockerManager
            ->expects($this->never())
            ->method('stop');

        $this->dockerManager
            ->expects($this->never())
            ->method('remove');

        $this->service->stop();
    }

    public function testStopOnlyCallsStopWhenRunning(): void
    {
        $this->dockerManager
            ->expects($this->once())
            ->method('isContainerRunning')
            ->with('dde-test')
            ->willReturn(true);

        $this->dockerManager
            ->expects($this->once())
            ->method('stop')
            ->with('dde-test');

        $this->dockerManager
            ->expects($this->never())
            ->method('remove');

        $this->service->stop();
    }

    public function testRemoveSkipsWhenContainerDoesNotExist(): void
    {
        $this->dockerManager
            ->expects($this->once())
            ->method('containerExists')
            ->with('dde-test')
            ->willReturn(false);

        $this->dockerManager
            ->expects($this->never())
            ->method('stop');

        $this->dockerManager
            ->expects($this->never())
            ->method('remove');

        $this->service->remove();
    }

    public function testRemoveStopsAndRemovesWhenRunning(): void
    {
        $this->dockerManager
            ->expects($this->once())
            ->method('containerExists')
            ->with('dde-test')
            ->willReturn(true);

        $this->dockerManager
            ->expects($this->once())
            ->method('isContainerRunning')
            ->with('dde-test')
            ->willReturn(true);

        $this->dockerManager
            ->expects($this->once())
            ->method('stop')
            ->with('dde-test');

        $this->dockerManager
            ->expects($this->once())
            ->method('remove')
            ->with('dde-test');

        $this->service->remove();
    }

    public function testRemoveOnlyRemovesWhenContainerExistsButIsStopped(): void
    {
        $this->dockerManager
            ->expects($this->once())
            ->method('containerExists')
            ->with('dde-test')
            ->willReturn(true);

        $this->dockerManager
            ->expects($this->once())
            ->method('isContainerRunning')
            ->with('dde-test')
            ->willReturn(false);

        $this->dockerManager
            ->expects($this->never())
            ->method('stop');

        $this->dockerManager
            ->expects($this->once())
            ->method('remove')
            ->with('dde-test');

        $this->service->remove();
    }

    public function testBuildIsNoOpByDefault(): void
    {
        $this->dockerManager
            ->expects($this->never())
            ->method('buildImage');

        $this->service->build();
        $this->service->build(true);
    }

    public function testIsRunningDelegatesToDockerManager(): void
    {
        $this->dockerManager
            ->expects($this->exactly(2))
            ->method('isContainerRunning')
            ->with('dde-test')
            ->willReturnOnConsecutiveCalls(true, false);

        $this->assertTrue($this->service->isRunning());
        $this->assertFalse($this->service->isRunning());
    }

    public function testStatusReturnsRunningWhenContainerIsRunning(): void
    {
        $this->dockerManager
            ->method('isContainerRunning')
            ->with('dde-test')
            ->willReturn(true);

        $this->assertSame(ServiceStatus::RUNNING, $this->service->status());
    }

    public function testStatusReturnsStoppedWhenContainerIsNotRunning(): void
    {
        $this->dockerManager
            ->method('isContainerRunning')
            ->with('dde-test')
            ->willReturn(false);

        $this->assertSame(ServiceStatus::STOPPED, $this->service->status());
    }

    public function testGetDefaultLabelsIncludesManagedAndServiceName(): void
    {
        $config = $this->service->getContainerConfig();

        $this->assertArrayHasKey('dde.managed', $config->labels);
        $this->assertSame('true', $config->labels['dde.managed']);
        $this->assertArrayHasKey('dde.service', $config->labels);
        $this->assertSame('test', $config->labels['dde.service']);
    }

    protected function setUp(): void
    {
        $this->dockerManager = $this->createMock(DockerManager::class);

        $this->service = new class($this->dockerManager) extends AbstractSystemService {
            public function getName(): string
            {
                return 'test';
            }

            public function getContainerName(): string
            {
                return 'dde-test';
            }

            public function getImageName(): string
            {
                return 'test-image:latest';
            }

            public function getContainerConfig(): ContainerConfig
            {
                return new ContainerConfig(
                    image: $this->getImageName(),
                    containerName: $this->getContainerName(),
                    labels: $this->getDefaultLabels(),
                );
            }
        };
    }
}
