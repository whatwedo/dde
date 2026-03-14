<?php

declare(strict_types=1);

namespace Tests\Unit\Command\System;

use App\Command\System\SystemStatusCommand;
use App\Database\DatabaseAdapterRegistry;
use App\Manager\DockerManager;
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

final class SystemStatusCommandTest extends TestCase
{
    private DockerManager&Stub $dockerManager;

    private CommandTester $commandTester;

    public function testExecuteShowsRunningStatus(): void
    {
        $this->dockerManager
            ->method('isContainerRunning')
            ->willReturn(true);

        $this->dockerManager
            ->method('networkExists')
            ->willReturn(true);

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $display = $this->commandTester->getDisplay();
        $this->assertStringContainsString('traefik', $display);
        $this->assertStringContainsString('running', $display);
        $this->assertStringContainsString('dde-traefik', $display);
    }

    public function testExecuteShowsStoppedStatus(): void
    {
        $this->dockerManager
            ->method('isContainerRunning')
            ->willReturn(false);

        $this->dockerManager
            ->method('networkExists')
            ->willReturn(false);

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $display = $this->commandTester->getDisplay();
        $this->assertStringContainsString('stopped', $display);
    }

    public function testExecuteJsonOutput(): void
    {
        $this->dockerManager
            ->method('isContainerRunning')
            ->willReturn(true);

        $this->dockerManager
            ->method('networkExists')
            ->willReturn(true);

        $this->commandTester->execute([
            '--output' => 'json',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $decoded = json_decode($this->commandTester->getDisplay(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('ok', $decoded['status']);
        $this->assertArrayHasKey('services', $decoded['data']);
        $this->assertArrayHasKey('network', $decoded['data']);
        $this->assertTrue($decoded['data']['network']);
        $this->assertSame('running', $decoded['data']['services'][0]['status']);
        $this->assertSame('traefik', $decoded['data']['services'][0]['name']);
        $this->assertSame('dde-traefik', $decoded['data']['services'][0]['container']);
    }

    public function testExecuteJsonOutputNetworkDown(): void
    {
        $this->dockerManager
            ->method('isContainerRunning')
            ->willReturn(false);

        $this->dockerManager
            ->method('networkExists')
            ->willReturn(false);

        $this->commandTester->execute([
            '--output' => 'json',
        ], [
            'interactive' => false,
        ]);

        $decoded = json_decode($this->commandTester->getDisplay(), true);
        $this->assertIsArray($decoded);
        $this->assertFalse($decoded['data']['network']);
        $this->assertSame('stopped', $decoded['data']['services'][0]['status']);
    }

    protected function setUp(): void
    {
        $this->dockerManager = $this->createStub(DockerManager::class);
        $tempDir = sys_get_temp_dir().'/dde-test-cmd-'.bin2hex(random_bytes(8));
        mkdir($tempDir, 0o777, true);

        $traefikService = new TraefikService(
            dockerManager: $this->dockerManager,
            filesystem: new Filesystem(),
            dataDir: $tempDir,
        );

        $registry = new ServiceRegistry([$traefikService], new DatabaseAdapterRegistry([]));
        $formatterResolver = new FormatterResolver(new TextFormatter(), new JsonFormatter());

        $command = new SystemStatusCommand($registry, $this->dockerManager, $formatterResolver);

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
