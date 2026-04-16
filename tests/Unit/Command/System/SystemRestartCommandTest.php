<?php

declare(strict_types=1);

namespace Tests\Unit\Command\System;

use App\Command\System\SystemRestartCommand;
use App\Config\GlobalConfig;
use App\Database\DatabaseAdapterRegistry;
use App\Manager\DockerManager;
use App\Manager\GlobalConfigManager;
use App\Model\UserContext;
use App\Output\FormatterResolver;
use App\Output\JsonFormatter;
use App\Output\TextFormatter;
use App\Service\ImageBuilder;
use App\Service\ServiceRegistry;
use App\Service\SshAgentService;
use App\Service\TraefikService;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class SystemRestartCommandTest extends TestCase
{
    private DockerManager&Stub $dockerManager;

    private CommandTester $commandTester;

    public function testExecuteStopsThenStartsServices(): void
    {
        $callOrder = [];
        $stopped = false;

        // First calls (stop phase): running=true; after stop: running=false for start phase
        $this->dockerManager
            ->method('isContainerRunning')
            ->willReturnCallback(function () use (&$callOrder, &$stopped): bool {
                $callOrder[] = 'isRunning';

                return ! $stopped;
            });

        $this->dockerManager
            ->method('stop')
            ->willReturnCallback(function () use (&$callOrder, &$stopped): void {
                $callOrder[] = 'stop';
                $stopped = true;
            });

        $this->dockerManager
            ->method('remove')
            ->willReturnCallback(function () use (&$callOrder): void {
                $callOrder[] = 'remove';
            });

        $this->dockerManager
            ->method('networkExists')
            ->willReturn(true);

        $this->dockerManager
            ->method('run')
            ->willReturnCallback(function () use (&$callOrder): void {
                $callOrder[] = 'run';
            });

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());

        // Verify stop happened before run
        $stopIndex = array_search('stop', $callOrder, true);
        $runIndex = array_search('run', $callOrder, true);
        $this->assertNotFalse($stopIndex);
        $this->assertNotFalse($runIndex);
        $this->assertLessThan($runIndex, $stopIndex);
    }

    public function testExecuteJsonOutput(): void
    {
        $this->dockerManager
            ->method('isContainerRunning')
            ->willReturn(false);

        $this->dockerManager
            ->method('networkExists')
            ->willReturn(true);

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
        $this->assertSame('restarted', $decoded['data']['services'][0]['status']);
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

        $globalConfigManager = $this->createStub(GlobalConfigManager::class);
        $globalConfigManager->method('load')->willReturn(new GlobalConfig());

        $sshAgentService = new SshAgentService(
            dockerManager: $this->dockerManager,
            filesystem: new Filesystem(),
            imageBuilder: new ImageBuilder($this->dockerManager, new Filesystem()),
            userContext: new UserContext(),
            globalConfigManager: $globalConfigManager,
            projectDir: $tempDir,
            userHomeDir: $tempDir,
            dataDir: $tempDir,
        );

        $command = new SystemRestartCommand($registry, $traefikService, $sshAgentService, $formatterResolver);

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
