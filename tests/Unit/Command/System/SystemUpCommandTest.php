<?php

declare(strict_types=1);

namespace Tests\Unit\Command\System;

use App\Command\System\SystemUpCommand;
use App\Config\GlobalConfig;
use App\Database\DatabaseAdapterRegistry;
use App\Manager\DockerManager;
use App\Manager\GlobalConfigManager;
use App\Model\ContainerConfig;
use App\Output\FormatterResolver;
use App\Output\JsonFormatter;
use App\Output\TextFormatter;
use App\Service\ServiceRegistry;
use App\Service\SshAgentService;
use App\Service\TraefikService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

#[AllowMockObjectsWithoutExpectations]
final class SystemUpCommandTest extends TestCase
{
    private DockerManager&MockObject $dockerManager;

    private CommandTester $commandTester;

    public function testExecuteStartsServices(): void
    {
        $this->dockerManager
            ->method('networkExists')
            ->willReturn(true);

        $this->dockerManager
            ->method('isContainerRunning')
            ->willReturn(false);

        $this->dockerManager
            ->method('run')
            ->with($this->isInstanceOf(ContainerConfig::class));

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $display = $this->commandTester->getDisplay();
        $this->assertStringContainsString('started', $display);
    }

    public function testExecuteReportsAlreadyRunning(): void
    {
        $this->dockerManager
            ->method('networkExists')
            ->willReturn(true);

        $this->dockerManager
            ->method('isContainerRunning')
            ->willReturn(true);

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteJsonOutput(): void
    {
        $this->dockerManager
            ->method('networkExists')
            ->willReturn(true);

        $this->dockerManager
            ->method('isContainerRunning')
            ->willReturn(false);

        $this->dockerManager
            ->method('run');

        $this->commandTester->execute([
            '--output' => 'json',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $decoded = json_decode($this->commandTester->getDisplay(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('ok', $decoded['status']);
        $this->assertArrayHasKey('data', $decoded);
        $this->assertArrayHasKey('services', $decoded['data']);
        $this->assertNotEmpty($decoded['data']['services']);
        $this->assertSame('started', $decoded['data']['services'][0]['status']);
    }

    public function testEnsureNetworkCalledFirst(): void
    {
        // First call from command, second from TraefikService::start()
        $this->dockerManager
            ->method('networkExists')
            ->with('dde')
            ->willReturnOnConsecutiveCalls(false, true);

        $this->dockerManager
            ->expects($this->once())
            ->method('createNetwork')
            ->with('dde');

        $this->dockerManager
            ->method('isContainerRunning')
            ->willReturn(false);

        $this->dockerManager
            ->method('run');

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
    }

    protected function setUp(): void
    {
        $this->dockerManager = $this->createMock(DockerManager::class);
        $tempDir = sys_get_temp_dir().'/dde-test-cmd-'.bin2hex(random_bytes(8));
        mkdir($tempDir, 0o777, true);

        $traefikService = new TraefikService(
            dockerManager: $this->dockerManager,
            filesystem: new Filesystem(),
            dataDir: $tempDir,
        );

        $registry = new ServiceRegistry([$traefikService], new DatabaseAdapterRegistry([]));
        $formatterResolver = new FormatterResolver(new TextFormatter(), new JsonFormatter());

        $globalConfigManager = $this->createStub(GlobalConfigManager::class);
        $globalConfigManager->method('load')->willReturn(new GlobalConfig());

        $sshAgentService = new SshAgentService(
            dockerManager: $this->dockerManager,
            filesystem: new Filesystem(),
            imageBuilder: new \App\Service\ImageBuilder($this->dockerManager, new Filesystem()),
            userContext: new \App\Model\UserContext('1000', '1000'),
            globalConfigManager: $globalConfigManager,
            projectDir: $tempDir,
            userHomeDir: $tempDir,
            dataDir: $tempDir,
        );

        $command = new SystemUpCommand($registry, $traefikService, $sshAgentService, $formatterResolver);

        $application = new Application();
        $application->getDefinition()->addOption(new InputOption(
            'output',
            'o',
            InputOption::VALUE_REQUIRED,
            'Output format',
            'text',
        ));
        $application->addCommand($command);

        $this->commandTester = new CommandTester($command);
    }
}
