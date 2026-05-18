<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Manager\DockerManager;
use App\Model\ContainerConfig;
use App\Model\ContainerInfo;
use App\Model\ContainerStatus;
use App\Service\MailpitService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class MailpitServiceTest extends TestCase
{
    private DockerManager&MockObject $dockerManager;

    private MailpitService $service;

    public function testGetName(): void
    {
        $this->assertSame('mailpit', $this->service->getName());
    }

    public function testGetContainerName(): void
    {
        $this->assertSame('dde-mailpit', $this->service->getContainerName());
    }

    public function testGetImageName(): void
    {
        $this->assertSame('axllent/mailpit', $this->service->getImageName());
    }

    public function testGetContainerConfigReturnsCorrectConfig(): void
    {
        $config = $this->service->getContainerConfig();

        $this->assertInstanceOf(ContainerConfig::class, $config);
        $this->assertSame('axllent/mailpit', $config->image);
        $this->assertSame('dde-mailpit', $config->containerName);
        $this->assertSame([], $config->ports);
        $this->assertSame('unless-stopped', $config->restartPolicy);
    }

    public function testGetContainerConfigHasManagedLabel(): void
    {
        $config = $this->service->getContainerConfig();

        $this->assertArrayHasKey('dde.managed', $config->labels);
        $this->assertSame('true', $config->labels['dde.managed']);
    }

    public function testGetContainerConfigHasTraefikLabels(): void
    {
        $config = $this->service->getContainerConfig();

        $this->assertSame('true', $config->labels['traefik.enable']);
        $this->assertSame('Host(`mail.test`)', $config->labels['traefik.http.routers.mailpit.rule']);
        $this->assertSame('Host(`mail.test`)', $config->labels['traefik.http.routers.mailpit-tls.rule']);
        $this->assertSame('true', $config->labels['traefik.http.routers.mailpit-tls.tls']);
        $this->assertSame('8025', $config->labels['traefik.http.services.mailpit.loadbalancer.server.port']);
    }

    public function testGetContainerConfigHasNetworkAlias(): void
    {
        $config = $this->service->getContainerConfig();

        $this->assertSame(['mail'], $config->networkAliases);
    }

    public function testGetContainerConfigDisablesMailpitMessageCap(): void
    {
        // Mailpit defaults to retaining only the most recent 500 messages.
        // Setting MP_MAX_MESSAGES=0 removes the cap so a development run can
        // inspect every mail it produced. Regression test for #143.
        $config = $this->service->getContainerConfig();

        $this->assertArrayHasKey('MP_MAX_MESSAGES', $config->environment);
        $this->assertSame('0', $config->environment['MP_MAX_MESSAGES']);
    }

    public function testStartCallsDockerManagerRun(): void
    {
        $this->dockerManager
            ->method('isContainerRunning')
            ->with('dde-mailpit')
            ->willReturn(false);

        $this->dockerManager
            ->method('containerExists')
            ->with('dde-mailpit')
            ->willReturn(false);

        $this->dockerManager
            ->expects($this->once())
            ->method('run')
            ->with($this->isInstanceOf(ContainerConfig::class));

        $this->service->start();
    }

    public function testStartReactivatesExistingStoppedContainer(): void
    {
        $this->dockerManager
            ->method('isContainerRunning')
            ->with('dde-mailpit')
            ->willReturn(false);

        $this->dockerManager
            ->method('containerExists')
            ->with('dde-mailpit')
            ->willReturn(true);

        $this->dockerManager
            ->expects($this->once())
            ->method('start')
            ->with('dde-mailpit');

        $this->dockerManager
            ->expects($this->never())
            ->method('run');

        $this->service->start();
    }

    public function testStartSkipsWhenAlreadyRunning(): void
    {
        $this->dockerManager
            ->method('isContainerRunning')
            ->with('dde-mailpit')
            ->willReturn(true);

        $this->dockerManager
            ->expects($this->never())
            ->method('run');

        $this->service->start();
    }

    public function testStopOnlyCallsDockerManagerStopWhenRunning(): void
    {
        $this->dockerManager
            ->method('isContainerRunning')
            ->with('dde-mailpit')
            ->willReturn(true);

        $this->dockerManager
            ->expects($this->once())
            ->method('stop')
            ->with('dde-mailpit');

        $this->dockerManager
            ->expects($this->never())
            ->method('remove');

        $this->service->stop();
    }

    public function testRemoveStopsAndRemovesWhenRunning(): void
    {
        $this->dockerManager
            ->method('containerExists')
            ->with('dde-mailpit')
            ->willReturn(true);

        $this->dockerManager
            ->method('isContainerRunning')
            ->with('dde-mailpit')
            ->willReturn(true);

        $this->dockerManager
            ->expects($this->once())
            ->method('stop')
            ->with('dde-mailpit');

        $this->dockerManager
            ->expects($this->once())
            ->method('remove')
            ->with('dde-mailpit');

        $this->service->remove();
    }

    public function testStopSkipsWhenNotRunning(): void
    {
        $this->dockerManager
            ->method('isContainerRunning')
            ->with('dde-mailpit')
            ->willReturn(false);

        $this->dockerManager
            ->expects($this->never())
            ->method('stop');

        $this->dockerManager
            ->expects($this->never())
            ->method('remove');

        $this->service->stop();
    }

    public function testGetProjectNetworkAliasesExposesMailAlias(): void
    {
        $this->assertSame(['mail'], $this->service->getProjectNetworkAliases());
    }

    public function testDoesNotRequireRestartAfterProjectNetworkAttach(): void
    {
        $this->assertFalse($this->service->requiresRestartAfterProjectNetworkAttach());
    }

    public function testStartAttachesMailpitToExistingProjectNetworks(): void
    {
        $this->dockerManager
            ->method('isContainerRunning')
            ->with('dde-mailpit')
            ->willReturn(true);

        $this->dockerManager
            ->method('getContainersByLabel')
            ->with('dde.service')
            ->willReturn([
                new ContainerInfo('dde-mailpit', ContainerStatus::RUNNING, 'axllent/mailpit:latest'),
            ]);

        $this->dockerManager
            ->method('listNetworksWithPrefix')
            ->with('dde-services-')
            ->willReturn(['dde-services-alpha', 'dde-services-empty']);

        $this->dockerManager
            ->method('getConnectedContainerNames')
            ->willReturnMap([
                ['dde-services-alpha', ['alpha-web-1']],
                ['dde-services-empty', []],
            ]);

        $this->dockerManager
            ->expects($this->once())
            ->method('connectContainerToNetwork')
            ->with('dde-mailpit', 'dde-services-alpha', ['mail']);

        // Mailpit doesn't need a restart after attach — only Traefik caches
        // network state.
        $this->dockerManager
            ->expects($this->never())
            ->method('stop');

        $this->service->start();
    }

    public function testIsRunningDelegatesToDockerManager(): void
    {
        $this->dockerManager
            ->expects($this->once())
            ->method('isContainerRunning')
            ->with('dde-mailpit')
            ->willReturn(true);

        $this->assertTrue($this->service->isRunning());
    }

    protected function setUp(): void
    {
        $this->dockerManager = $this->createMock(DockerManager::class);
        $this->service = new MailpitService(
            dockerManager: $this->dockerManager,
        );
    }
}
