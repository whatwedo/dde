<?php

declare(strict_types=1);

namespace Tests\Unit\Command\System;

use App\Command\System\SystemServiceUpCommand;
use App\Config\GlobalConfig;
use App\Database\DatabaseAdapterRegistry;
use App\Manager\DockerManager;
use App\Manager\GlobalConfigManager;
use App\Manager\SystemServiceManager;
use App\Output\FormatterResolver;
use App\Output\JsonFormatter;
use App\Output\TextFormatter;
use App\Service\ServiceRegistry;
use App\Service\TraefikService;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class SystemServiceUpCommandTest extends TestCase
{
    private DockerManager&Stub $dockerManager;

    private GlobalConfigManager&Stub $globalConfigManager;

    private CommandTester $commandTester;

    public function testUnknownServiceReturnsError(): void
    {
        $this->globalConfigManager
            ->method('load')
            ->willReturn(new GlobalConfig());

        $this->commandTester->execute([
            'name' => 'unknown-service',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(1, $this->commandTester->getStatusCode());
        $display = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Unknown service', $display);
        $this->assertStringContainsString('mariadb', $display);
    }

    public function testAlreadyRunningReturnsAlreadyRunningStatus(): void
    {
        $this->globalConfigManager
            ->method('load')
            ->willReturn(new GlobalConfig());

        $this->dockerManager
            ->method('isContainerRunning')
            ->willReturn(true);

        $this->dockerManager
            ->method('getContainerPorts')
            ->willReturn([
                '3306/tcp' => [
                    [
                        'HostIp' => '127.0.0.1',
                        'HostPort' => '3306',
                    ],
                ],
            ]);

        $this->commandTester->execute([
            'name' => 'mariadb',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $display = $this->commandTester->getDisplay();
        $this->assertStringContainsString('already running', $display);
    }

    public function testAlreadyRunningJsonOutput(): void
    {
        $this->globalConfigManager
            ->method('load')
            ->willReturn(new GlobalConfig());

        $this->dockerManager
            ->method('isContainerRunning')
            ->willReturn(true);

        $this->dockerManager
            ->method('getContainerPorts')
            ->willReturn([
                '3306/tcp' => [
                    [
                        'HostIp' => '127.0.0.1',
                        'HostPort' => '3306',
                    ],
                ],
            ]);

        $this->commandTester->execute([
            'name' => 'mariadb',
            '--output' => 'json',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $decoded = json_decode($this->commandTester->getDisplay(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('ok', $decoded['status']);
        $this->assertSame('already_running', $decoded['data']['status']);
        $this->assertSame('dde-mariadb-11.8', $decoded['data']['container']);
        $this->assertSame(3306, $decoded['data']['port']);
        $this->assertSame('mariadb', $decoded['data']['service']);
        $this->assertSame('11.8', $decoded['data']['version']);
    }

    public function testSuccessfulStartReturnsOkStatus(): void
    {
        $this->globalConfigManager
            ->method('load')
            ->willReturn(new GlobalConfig());

        $this->dockerManager
            ->method('isContainerRunning')
            ->willReturnOnConsecutiveCalls(false, false);

        $this->dockerManager
            ->method('getContainersByLabel')
            ->willReturn([]);

        $this->dockerManager
            ->method('run');

        $this->dockerManager
            ->method('getContainerPorts')
            ->willReturn([
                '3306/tcp' => [
                    [
                        'HostIp' => '127.0.0.1',
                        'HostPort' => '3306',
                    ],
                ],
            ]);

        $this->commandTester->execute([
            'name' => 'mariadb',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $display = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Started service', $display);
        $this->assertStringContainsString('mariadb:11.8', $display);
        $this->assertStringContainsString('dde-mariadb-11.8', $display);
    }

    public function testSuccessfulStartJsonOutput(): void
    {
        $this->globalConfigManager
            ->method('load')
            ->willReturn(new GlobalConfig());

        $this->dockerManager
            ->method('isContainerRunning')
            ->willReturnOnConsecutiveCalls(false, false);

        $this->dockerManager
            ->method('getContainersByLabel')
            ->willReturn([]);

        $this->dockerManager
            ->method('run');

        $this->dockerManager
            ->method('getContainerPorts')
            ->willReturn([
                '3306/tcp' => [
                    [
                        'HostIp' => '127.0.0.1',
                        'HostPort' => '3306',
                    ],
                ],
            ]);

        $this->commandTester->execute([
            'name' => 'mariadb',
            '--output' => 'json',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $decoded = json_decode($this->commandTester->getDisplay(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('ok', $decoded['status']);
        $this->assertSame('ok', $decoded['data']['status']);
        $this->assertSame('dde-mariadb-11.8', $decoded['data']['container']);
        $this->assertSame('mariadb', $decoded['data']['service']);
        $this->assertSame('11.8', $decoded['data']['version']);
        $this->assertSame(3306, $decoded['data']['port']);
        $this->assertSame('127.0.0.1', $decoded['data']['host']);
    }

    public function testCustomVersionOption(): void
    {
        $this->globalConfigManager
            ->method('load')
            ->willReturn(new GlobalConfig());

        $this->dockerManager
            ->method('isContainerRunning')
            ->willReturnOnConsecutiveCalls(false, false);

        $this->dockerManager
            ->method('getContainersByLabel')
            ->willReturn([]);

        $this->dockerManager
            ->method('run');

        $this->dockerManager
            ->method('getContainerPorts')
            ->willReturn([
                '3306/tcp' => [
                    [
                        'HostIp' => '127.0.0.1',
                        'HostPort' => '10001',
                    ],
                ],
            ]);

        $this->commandTester->execute([
            'name' => 'mariadb',
            '--service-version' => '10',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $display = $this->commandTester->getDisplay();
        $this->assertStringContainsString('mariadb:10', $display);
        $this->assertStringContainsString('dde-mariadb-10', $display);
    }

    protected function setUp(): void
    {
        $this->dockerManager = $this->createStub(DockerManager::class);
        $this->globalConfigManager = $this->createStub(GlobalConfigManager::class);

        $tempDir = sys_get_temp_dir().'/dde-test-cmd-'.bin2hex(random_bytes(8));
        mkdir($tempDir, 0o777, true);

        $traefikService = new TraefikService(
            dockerManager: $this->dockerManager,
            filesystem: new Filesystem(),
            dataDir: $tempDir,
        );

        $registry = new ServiceRegistry([$traefikService], new DatabaseAdapterRegistry([]));

        $serviceManager = new SystemServiceManager(
            dockerManager: $this->dockerManager,
            serviceRegistry: $registry,
            filesystem: new Filesystem(),
            dataDir: $tempDir,
        );

        $formatterResolver = new FormatterResolver(new TextFormatter(), new JsonFormatter());

        $command = new SystemServiceUpCommand(
            serviceManager: $serviceManager,
            serviceRegistry: $registry,
            dockerManager: $this->dockerManager,
            globalConfigManager: $this->globalConfigManager,
            formatterResolver: $formatterResolver,
        );

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
