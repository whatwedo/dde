<?php

declare(strict_types=1);

namespace Tests\Unit\Manager;

use App\Manager\DockerManager;
use App\Util\ProcessFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class DockerManagerTest extends TestCase
{
    private DockerManager $manager;

    #[Group('e2e')]
    public function testIsContainerRunningReturnsFalseForNonExistentContainer(): void
    {
        $this->assertFalse($this->manager->isContainerRunning('dde-nonexistent-test-container-'.bin2hex(random_bytes(8))));
    }

    #[Group('e2e')]
    public function testNetworkExistsReturnsFalseForNonExistentNetwork(): void
    {
        $this->assertFalse($this->manager->networkExists('dde-nonexistent-test-network-'.bin2hex(random_bytes(8))));
    }

    #[Group('e2e')]
    public function testGetContainerUptimeReturnsNullForNonExistentContainer(): void
    {
        $this->assertNull($this->manager->getContainerUptime('dde-nonexistent-test-container-'.bin2hex(random_bytes(8))));
    }

    #[Group('e2e')]
    public function testGetContainerPortsReturnsEmptyForNonExistentContainer(): void
    {
        $this->assertSame([], $this->manager->getContainerPorts('dde-nonexistent-test-container-'.bin2hex(random_bytes(8))));
    }

    #[Group('e2e')]
    public function testGetContainersByLabelReturnsEmptyForNonExistentLabel(): void
    {
        $result = $this->manager->getContainersByLabel('dde.test.nonexistent', 'true');

        $this->assertSame([], $result);
    }

    #[Group('e2e')]
    public function testInspectThrowsForNonExistentContainer(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->manager->inspect('dde-nonexistent-test-container-'.bin2hex(random_bytes(8)));
    }

    #[Group('e2e')]
    public function testExecCaptureThrowsForNonExistentContainer(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->manager->execCapture('dde-nonexistent-test-container-'.bin2hex(random_bytes(8)), ['echo', 'test']);
    }

    #[Group('e2e')]
    public function testExecCaptureWithEnvThrowsForNonExistentContainer(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->manager->execCaptureWithEnv('dde-nonexistent-test-container-'.bin2hex(random_bytes(8)), ['echo', 'test'], []);
    }

    #[Group('e2e')]
    public function testExecWithInputThrowsForNonExistentContainer(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->manager->execWithInput('dde-nonexistent-test-container-'.bin2hex(random_bytes(8)), ['cat'], 'input', []);
    }

    #[Group('e2e')]
    public function testListVolumesReturnsArray(): void
    {
        $result = $this->manager->listVolumes([]);

        $this->assertIsArray($result);
    }

    #[Group('e2e')]
    public function testListImagesReturnsArray(): void
    {
        $result = $this->manager->listImages([]);

        $this->assertIsArray($result);
    }

    #[Group('e2e')]
    public function testGetContainerEnvThrowsForNonExistentContainer(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->manager->getContainerEnv('dde-nonexistent-test-container-'.bin2hex(random_bytes(8)));
    }

    #[Group('e2e')]
    public function testGetContainerNetworkIpReturnsNullForNonExistentContainer(): void
    {
        $result = $this->manager->getContainerNetworkIp('dde-nonexistent-test-container-'.bin2hex(random_bytes(8)));

        $this->assertNull($result);
    }

    // --- determineOverallStatus ---

    public function testOverallStatusIsStoppedForEmptyContainerList(): void
    {
        $this->assertSame('stopped', $this->manager->determineOverallStatus([]));
    }

    public function testOverallStatusIsRunningWhenAllContainersRunning(): void
    {
        $containers = [
            [
                'State' => 'running',
            ],
            [
                'State' => 'running',
            ],
        ];

        $this->assertSame('running', $this->manager->determineOverallStatus($containers));
    }

    public function testOverallStatusIsPartialWhenSomeContainersRunning(): void
    {
        $containers = [
            [
                'State' => 'running',
            ],
            [
                'State' => 'exited',
            ],
        ];

        $this->assertSame('partial', $this->manager->determineOverallStatus($containers));
    }

    public function testOverallStatusIsStoppedWhenNoContainerRunning(): void
    {
        $containers = [
            [
                'State' => 'exited',
            ],
            [
                'State' => 'dead',
            ],
        ];

        $this->assertSame('stopped', $this->manager->determineOverallStatus($containers));
    }

    public function testOverallStatusReadsLowercaseStateKey(): void
    {
        $containers = [[
            'state' => 'running',
        ]];

        $this->assertSame('running', $this->manager->determineOverallStatus($containers));
    }

    public function testOverallStatusReadsStatusKeyAsFallback(): void
    {
        $containers = [[
            'Status' => 'running',
        ]];

        $this->assertSame('running', $this->manager->determineOverallStatus($containers));
    }

    // --- extractPorts ---

    public function testExtractPortsReturnsEmptyForMissingKey(): void
    {
        $this->assertSame([], $this->manager->extractPorts([]));
    }

    public function testExtractPortsReturnsEmptyForEmptyStringPorts(): void
    {
        $this->assertSame([], $this->manager->extractPorts([
            'Ports' => '',
        ]));
    }

    public function testExtractPortsReturnsStringPortAsArray(): void
    {
        $result = $this->manager->extractPorts([
            'Ports' => '0.0.0.0:8080->80/tcp',
        ]);

        $this->assertSame(['0.0.0.0:8080->80/tcp'], $result);
    }

    public function testExtractPortsFormatsPublisherObjects(): void
    {
        $container = [
            'Publishers' => [
                [
                    'TargetPort' => 80,
                    'Protocol' => 'tcp',
                ],
                [
                    'TargetPort' => 443,
                    'Protocol' => 'tcp',
                ],
            ],
        ];

        $result = $this->manager->extractPorts($container);

        $this->assertSame(['80/tcp', '443/tcp'], $result);
    }

    public function testExtractPortsSkipsPublisherWithZeroTargetPort(): void
    {
        $container = [
            'Publishers' => [
                [
                    'TargetPort' => 0,
                    'Protocol' => 'tcp',
                ],
                [
                    'TargetPort' => 8080,
                    'Protocol' => 'tcp',
                ],
            ],
        ];

        $result = $this->manager->extractPorts($container);

        $this->assertSame(['8080/tcp'], $result);
    }

    public function testExtractPortsDefaultsToTcpProtocol(): void
    {
        $container = [
            'Publishers' => [
                [
                    'TargetPort' => 5432,
                ],
            ],
        ];

        $result = $this->manager->extractPorts($container);

        $this->assertSame(['5432/tcp'], $result);
    }

    public function testExtractPortsHandlesLowercaseKeys(): void
    {
        $container = [
            'ports' => [
                [
                    'target' => 3306,
                    'protocol' => 'tcp',
                ],
            ],
        ];

        $result = $this->manager->extractPorts($container);

        $this->assertSame(['3306/tcp'], $result);
    }

    public function testExtractPortsHandlesMixedStringAndArrayEntries(): void
    {
        $container = [
            'Ports' => [
                '0.0.0.0:9000->9000/tcp',
                [
                    'TargetPort' => 9001,
                    'Protocol' => 'tcp',
                ],
            ],
        ];

        $result = $this->manager->extractPorts($container);

        $this->assertSame(['0.0.0.0:9000->9000/tcp', '9001/tcp'], $result);
    }

    public function testExtractPortsReturnsEmptyForNonArrayNonStringPorts(): void
    {
        $result = $this->manager->extractPorts([
            'Ports' => 12345,
        ]);

        $this->assertSame([], $result);
    }

    // --- connectContainerToNetwork / disconnectContainerFromNetwork ---

    public function testConnectContainerToNetworkCallsDockerNetworkConnect(): void
    {
        $this->expectNotToPerformAssertions();

        $process = $this->createStub(\Symfony\Component\Process\Process::class);
        $process->method('isSuccessful')->willReturn(true);

        $processFactory = $this->createStub(ProcessFactory::class);
        $processFactory->method('create')->willReturn($process);

        $manager = new DockerManager($processFactory);
        $manager->connectContainerToNetwork('dde-mariadb-10.6', 'dde-services-myproject', ['mariadb']);
    }

    public function testDisconnectContainerFromNetworkCallsDockerNetworkDisconnect(): void
    {
        $this->expectNotToPerformAssertions();

        $process = $this->createStub(\Symfony\Component\Process\Process::class);
        $process->method('isSuccessful')->willReturn(true);

        $processFactory = $this->createStub(ProcessFactory::class);
        $processFactory->method('create')->willReturn($process);

        $manager = new DockerManager($processFactory);
        $manager->disconnectContainerFromNetwork('dde-mariadb-10.6', 'dde-services-myproject');
    }

    public function testDisconnectContainerFromNetworkSilentlyIgnoresFailure(): void
    {
        $this->expectNotToPerformAssertions();

        $process = $this->createStub(\Symfony\Component\Process\Process::class);
        $process->method('isSuccessful')->willReturn(false);

        $processFactory = $this->createStub(ProcessFactory::class);
        $processFactory->method('create')->willReturn($process);

        $manager = new DockerManager($processFactory);
        $manager->disconnectContainerFromNetwork('dde-mariadb-10.6', 'dde-services-myproject');
    }

    public function testConnectContainerToNetworkThrowsOnFailure(): void
    {
        $process = $this->createStub(\Symfony\Component\Process\Process::class);
        $process->method('isSuccessful')->willReturn(false);
        $process->method('getCommandLine')->willReturn('docker network connect ...');
        $process->method('getExitCode')->willReturn(1);
        $process->method('getExitCodeText')->willReturn('General error');
        $process->method('getWorkingDirectory')->willReturn('/tmp');
        $process->method('isOutputDisabled')->willReturn(false);
        $process->method('getOutput')->willReturn('');
        $process->method('getErrorOutput')->willReturn('network not found');

        $processFactory = $this->createStub(ProcessFactory::class);
        $processFactory->method('create')->willReturn($process);

        $manager = new DockerManager($processFactory);

        $this->expectException(\Symfony\Component\Process\Exception\ProcessFailedException::class);
        $manager->connectContainerToNetwork('dde-mariadb-10.6', 'nonexistent-network', ['mariadb']);
    }

    public function testConnectContainerToNetworkIgnoresAlreadyConnectedError(): void
    {
        $process = $this->createStub(\Symfony\Component\Process\Process::class);
        $process->method('isSuccessful')->willReturn(false);
        $process->method('getErrorOutput')->willReturn('Error response from daemon: endpoint with name web already exists in network dde-services-myproject');

        $processFactory = $this->createStub(ProcessFactory::class);
        $processFactory->method('create')->willReturn($process);

        $manager = new DockerManager($processFactory);
        // Must not throw — already connected is idempotent
        $this->expectNotToPerformAssertions();
        $manager->connectContainerToNetwork('web', 'dde-services-myproject', ['web']);
    }

    // --- getContainerNetworkIp ---

    public function testGetContainerNetworkIpReturnsIpWhenContainerOnNetwork(): void
    {
        $process = $this->createStub(\Symfony\Component\Process\Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $process->method('getOutput')->willReturn("172.20.0.5\n");

        $processFactory = $this->createStub(ProcessFactory::class);
        $processFactory->method('create')->willReturn($process);

        $manager = new DockerManager($processFactory);
        $result = $manager->getContainerNetworkIp('dde-mariadb-10.6');

        $this->assertSame('172.20.0.5', $result);
    }

    public function testGetContainerNetworkIpReturnsNullWhenOutputIsEmpty(): void
    {
        $process = $this->createStub(\Symfony\Component\Process\Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $process->method('getOutput')->willReturn("\n");

        $processFactory = $this->createStub(ProcessFactory::class);
        $processFactory->method('create')->willReturn($process);

        $manager = new DockerManager($processFactory);
        $result = $manager->getContainerNetworkIp('dde-mariadb-10.6');

        $this->assertNull($result);
    }

    // --- imageHasShell ---

    public function testImageHasShellReturnsTrueWhenShellProbeSucceeds(): void
    {
        $manager = $this->createManagerWithShellProbeResult(true);

        $this->assertTrue($manager->imageHasShell('nginx:latest'));
    }

    public function testImageHasShellReturnsFalseWhenShellProbeFails(): void
    {
        $manager = $this->createManagerWithShellProbeResult(false);

        $this->assertFalse($manager->imageHasShell('dunglas/mercure'));
    }

    private function createManagerWithShellProbeResult(bool $successful): DockerManager
    {
        $process = $this->createStub(\Symfony\Component\Process\Process::class);
        $process->method('isSuccessful')->willReturn($successful);

        $processFactory = $this->createStub(ProcessFactory::class);
        $processFactory->method('create')->willReturn($process);

        return new DockerManager($processFactory);
    }

    protected function setUp(): void
    {
        $this->manager = new DockerManager(new ProcessFactory());
    }
}
