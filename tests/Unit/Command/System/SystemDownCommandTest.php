<?php

declare(strict_types=1);

namespace Tests\Unit\Command\System;

use App\Command\System\SystemDownCommand;
use App\Database\DatabaseAdapterRegistry;
use App\Manager\DockerManager;
use App\Output\FormatterResolver;
use App\Output\JsonFormatter;
use App\Output\TextFormatter;
use App\Service\ServiceRegistry;
use App\Service\TraefikService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

#[AllowMockObjectsWithoutExpectations]
final class SystemDownCommandTest extends TestCase
{
    private DockerManager&MockObject $dockerManager;

    private CommandTester $commandTester;

    public function testExecuteStopsRunningServices(): void
    {
        $this->dockerManager
            ->method('isContainerRunning')
            ->willReturn(true);

        $this->dockerManager
            ->method('containerExists')
            ->willReturn(true);

        $this->dockerManager
            ->expects($this->once())
            ->method('stop')
            ->with('dde-traefik');

        $this->dockerManager
            ->expects($this->once())
            ->method('remove')
            ->with('dde-traefik');

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $display = $this->commandTester->getDisplay();
        $this->assertStringContainsString('stopped', $display);
    }

    public function testExecuteSkipsAlreadyStopped(): void
    {
        $this->dockerManager
            ->method('isContainerRunning')
            ->willReturn(false);

        $this->dockerManager
            ->method('containerExists')
            ->willReturn(false);

        $this->dockerManager
            ->expects($this->never())
            ->method('stop');

        $this->dockerManager
            ->expects($this->never())
            ->method('remove');

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteJsonOutput(): void
    {
        $this->dockerManager
            ->method('isContainerRunning')
            ->willReturn(true);

        $this->dockerManager
            ->method('containerExists')
            ->willReturn(true);

        $this->dockerManager->method('stop');
        $this->dockerManager->method('remove');

        $this->commandTester->execute([
            '--output' => 'json',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $decoded = json_decode($this->commandTester->getDisplay(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('ok', $decoded['status']);
        $this->assertSame('stopped', $decoded['data']['services'][0]['status']);
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

        $this->dockerManager->method('getContainersByLabel')->willReturn([]);
        $command = new SystemDownCommand($registry, $this->dockerManager, $formatterResolver);

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
