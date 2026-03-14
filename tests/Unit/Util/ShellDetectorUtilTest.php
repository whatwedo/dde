<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use App\Config\GlobalConfig;
use App\Config\ProjectConfig;
use App\Config\ResolvedConfig;
use App\Manager\DockerComposeManager;
use App\Util\ShellDetectorUtil;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ShellDetectorUtilTest extends TestCase
{
    private DockerComposeManager&Stub $dockerComposeManager;

    private ShellDetectorUtil $shellDetector;

    public function testReturnsConfiguredShellFromContainerConfig(): void
    {
        $config = $this->makeConfig(containers: [
            'web' => [
                'shell' => 'fish',
            ],
        ]);

        $result = $this->shellDetector->detect($config, 'web', '/project');

        $this->assertSame('fish', $result);
    }

    public function testDetectsZshWhenAvailableInContainer(): void
    {
        $config = $this->makeConfig();

        $successProcess = $this->createStub(Process::class);
        $successProcess->method('isSuccessful')->willReturn(true);

        $this->dockerComposeManager
            ->method('exec')
            ->willReturn($successProcess);

        $result = $this->shellDetector->detect($config, 'web', '/project');

        $this->assertSame('zsh', $result);
    }

    public function testFallsThroughToNextShellWhenZshNotFound(): void
    {
        $config = $this->makeConfig();

        $failProcess = $this->createStub(Process::class);
        $failProcess->method('isSuccessful')->willReturn(false);

        $bashProcess = $this->createStub(Process::class);
        $bashProcess->method('isSuccessful')->willReturn(true);

        $callIndex = 0;
        $this->dockerComposeManager
            ->method('exec')
            ->willReturnCallback(function (string $dir, string $service, array $cmd) use (&$callIndex, $failProcess, $bashProcess): Process {
                $callIndex++;

                if ($callIndex === 1) {
                    $this->assertSame(['which', 'zsh'], $cmd);

                    return $failProcess;
                }

                $this->assertSame(['which', 'bash'], $cmd);

                return $bashProcess;
            });

        $result = $this->shellDetector->detect($config, 'web', '/project');

        $this->assertSame('bash', $result);
    }

    public function testFallsBackToShWhenNoCandidateFound(): void
    {
        $config = $this->makeConfig();

        $failProcess = $this->createStub(Process::class);
        $failProcess->method('isSuccessful')->willReturn(false);

        $this->dockerComposeManager
            ->method('exec')
            ->willReturn($failProcess);

        $result = $this->shellDetector->detect($config, 'web', '/project');

        $this->assertSame('sh', $result);
    }

    public function testDetectionUsesRootUserAndNoTty(): void
    {
        $config = $this->makeConfig();

        $capturedOptions = [];
        $failProcess = $this->createStub(Process::class);
        $failProcess->method('isSuccessful')->willReturn(false);

        $this->dockerComposeManager
            ->method('exec')
            ->willReturnCallback(function (string $dir, string $service, array $cmd, array $opts) use (&$capturedOptions, $failProcess): Process {
                $capturedOptions[] = $opts;

                return $failProcess;
            });

        $this->shellDetector->detect($config, 'web', '/project');

        $this->assertNotEmpty($capturedOptions);

        foreach ($capturedOptions as $opts) {
            $this->assertSame('root', $opts['user']);
            $this->assertTrue($opts['noTty']);
        }
    }

    public function testDetectCachesResultPerService(): void
    {
        $dockerCompose = $this->createMock(DockerComposeManager::class);

        $process = $this->createStub(Process::class);
        $process->method('isSuccessful')->willReturn(true);

        // Should only be called once for the same service
        $dockerCompose->expects($this->once())
            ->method('exec')
            ->with('/tmp/project', 'web', ['which', 'zsh'], $this->anything())
            ->willReturn($process);

        $detector = new ShellDetectorUtil($dockerCompose);
        $config = $this->makeConfig();

        $shell1 = $detector->detect($config, 'web', '/tmp/project');
        $shell2 = $detector->detect($config, 'web', '/tmp/project');

        $this->assertSame('zsh', $shell1);
        $this->assertSame('zsh', $shell2);
    }

    public function testIgnoresShellConfigForUnknownService(): void
    {
        $config = $this->makeConfig(containers: [
            'web' => [
                'shell' => 'zsh',
            ],
        ]);

        $successProcess = $this->createStub(Process::class);
        $successProcess->method('isSuccessful')->willReturn(true);

        $this->dockerComposeManager
            ->method('exec')
            ->willReturn($successProcess);

        // 'worker' has no explicit shell config — falls back to detection
        $result = $this->shellDetector->detect($config, 'worker', '/project');

        $this->assertSame('zsh', $result);
    }

    /**
     * @param array<string, mixed> $containers
     */
    private function makeConfig(array $containers = [
        'web' => [],
    ]): ResolvedConfig
    {
        return ResolvedConfig::merge(
            new GlobalConfig(),
            new ProjectConfig(name: 'test', containers: $containers),
        );
    }

    protected function setUp(): void
    {
        $this->dockerComposeManager = $this->createStub(DockerComposeManager::class);
        $this->shellDetector = new ShellDetectorUtil($this->dockerComposeManager);
    }
}
