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

    protected function setUp(): void
    {
        $this->manager = new DockerManager(new ProcessFactory());
    }
}
