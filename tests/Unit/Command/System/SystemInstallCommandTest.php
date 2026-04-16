<?php

declare(strict_types=1);

namespace Tests\Unit\Command\System;

use App\Command\System\SystemInstallCommand;
use App\Config\GlobalConfig;
use App\Manager\CompletionManager;
use App\Manager\DockerManager;
use App\Manager\GlobalConfigManager;
use App\Manager\MkcertManager;
use App\Model\UserContext;
use App\Output\FormatterResolver;
use App\Output\JsonFormatter;
use App\Output\TextFormatter;
use App\Service\DnsmasqService;
use App\Service\ImageBuilder;
use App\Service\SshAgentService;
use App\Service\TraefikService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

#[Group('e2e')]
final class SystemInstallCommandTest extends TestCase
{
    private DockerManager&Stub $dockerManager;

    private string $tempDir;

    private string|false $originalHome;

    private CommandTester $commandTester;

    private SystemInstallCommand $command;

    public function testCommandName(): void
    {
        $this->assertSame('system:install', $this->command->getName());
    }

    public function testCommandDescription(): void
    {
        $this->assertSame('Install and configure the dde system', $this->command->getDescription());
    }

    public function testExecuteSuccessTextOutput(): void
    {
        $this->dockerManager->method('networkExists')->willReturn(true);
        $this->dockerManager->method('isContainerRunning')->willReturn(false);
        $this->dockerManager->method('run');
        $this->dockerManager->method('imageExists')->willReturn(true);

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $display = $this->commandTester->getDisplay();
        $this->assertStringContainsString('successfully', $display);
    }

    public function testExecuteJsonOutputAllSuccess(): void
    {
        $this->dockerManager->method('networkExists')->willReturn(true);
        $this->dockerManager->method('isContainerRunning')->willReturn(false);
        $this->dockerManager->method('run');
        $this->dockerManager->method('imageExists')->willReturn(true);

        $this->commandTester->execute([
            '--output' => 'json',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $decoded = json_decode($this->commandTester->getDisplay(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('ok', $decoded['status']);
        $this->assertArrayHasKey('steps', $decoded['data']);
    }

    public function testExecuteReportsStepStatusInJson(): void
    {
        $this->dockerManager->method('networkExists')->willReturn(true);
        $this->dockerManager->method('isContainerRunning')->willReturn(false);
        $this->dockerManager->method('run');
        $this->dockerManager->method('imageExists')->willReturn(true);

        $this->commandTester->execute([
            '--output' => 'json',
        ], [
            'interactive' => false,
        ]);

        $decoded = json_decode($this->commandTester->getDisplay(), true);
        $steps = $decoded['data']['steps'];

        // Verify step structure
        foreach ($steps as $step) {
            $this->assertArrayHasKey('step', $step);
            $this->assertArrayHasKey('status', $step);
            $this->assertArrayHasKey('message', $step);
        }
    }

    public function testTraefikFailureReportsErrorInText(): void
    {
        $this->dockerManager->method('networkExists')->willReturn(false);
        $this->dockerManager->method('createNetwork');
        $this->dockerManager->method('isContainerRunning')->willReturn(false);
        $this->dockerManager->method('imageExists')->willReturn(true);
        $this->dockerManager->method('run')->willThrowException(new \RuntimeException('Docker daemon not running'));

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertSame(1, $this->commandTester->getStatusCode());
        $display = $this->commandTester->getDisplay();
        $this->assertStringContainsString('failed', $display);
    }

    public function testServiceFailureReportsErrorInJson(): void
    {
        $this->dockerManager->method('networkExists')->willReturn(false);
        $this->dockerManager->method('createNetwork');
        $this->dockerManager->method('isContainerRunning')->willReturn(false);
        $this->dockerManager->method('imageExists')->willReturn(true);
        $this->dockerManager->method('run')->willThrowException(new \RuntimeException('Docker daemon not running'));

        $this->commandTester->execute([
            '--output' => 'json',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(1, $this->commandTester->getStatusCode());
        $decoded = json_decode($this->commandTester->getDisplay(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('error', $decoded['status']);
    }

    public function testShellCompletionStepAppearsInTextOutput(): void
    {
        $this->dockerManager->method('networkExists')->willReturn(true);
        $this->dockerManager->method('isContainerRunning')->willReturn(false);
        $this->dockerManager->method('run');
        $this->dockerManager->method('imageExists')->willReturn(true);

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $display = $this->commandTester->getDisplay();
        $this->assertStringContainsString('shell completion', $display);
    }

    public function testShellCompletionStepAppearsInJsonOutput(): void
    {
        $this->dockerManager->method('networkExists')->willReturn(true);
        $this->dockerManager->method('isContainerRunning')->willReturn(false);
        $this->dockerManager->method('run');
        $this->dockerManager->method('imageExists')->willReturn(true);

        $this->commandTester->execute([
            '--output' => 'json',
        ], [
            'interactive' => false,
        ]);

        $decoded = json_decode($this->commandTester->getDisplay(), true);
        $steps = $decoded['data']['steps'];
        $completionStep = array_filter($steps, static fn (array $s): bool => $s['step'] === 'shell-completion');
        $this->assertNotEmpty($completionStep);
    }

    public function testNonInteractiveMode(): void
    {
        $this->dockerManager->method('networkExists')->willReturn(true);
        $this->dockerManager->method('isContainerRunning')->willReturn(false);
        $this->dockerManager->method('run');
        $this->dockerManager->method('imageExists')->willReturn(true);

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
    }

    protected function setUp(): void
    {
        $this->dockerManager = $this->createStub(DockerManager::class);
        $this->tempDir = sys_get_temp_dir().'/dde-test-install-'.bin2hex(random_bytes(8));
        $this->originalHome = getenv('HOME');
        putenv('HOME='.$this->tempDir);
        $filesystem = new Filesystem();
        $filesystem->mkdir($this->tempDir);

        // Create docker context dirs expected by DnsmasqService and SshAgentService
        $filesystem->mkdir($this->tempDir.'/resources/docker/dnsmasq');
        $filesystem->dumpFile($this->tempDir.'/resources/docker/dnsmasq/Dockerfile', 'FROM alpine');
        $filesystem->mkdir($this->tempDir.'/resources/docker/ssh-agent');
        $filesystem->dumpFile($this->tempDir.'/resources/docker/ssh-agent/Dockerfile', 'FROM alpine');
        $filesystem->dumpFile($this->tempDir.'/resources/docker/ssh-agent/run.sh', '#!/bin/sh');

        $mkcertService = $this->createStub(MkcertManager::class);
        $mkcertService->method('isMkcertInstalled')->willReturn(true);

        $imageBuilder = new ImageBuilder(
            dockerManager: $this->dockerManager,
            filesystem: $filesystem,
        );

        $dnsmasqService = new DnsmasqService(
            dockerManager: $this->dockerManager,
            filesystem: $filesystem,
            imageBuilder: $imageBuilder,
            projectDir: $this->tempDir,
            dataDir: $this->tempDir,
        );

        $traefikService = new TraefikService(
            dockerManager: $this->dockerManager,
            filesystem: $filesystem,
            dataDir: $this->tempDir,
        );

        $globalConfigManager = $this->createStub(GlobalConfigManager::class);
        $globalConfigManager->method('load')->willReturn(new GlobalConfig());

        $sshAgentService = new SshAgentService(
            dockerManager: $this->dockerManager,
            filesystem: $filesystem,
            imageBuilder: $imageBuilder,
            userContext: new UserContext('1000', '1000'),
            globalConfigManager: $globalConfigManager,
            projectDir: $this->tempDir,
            userHomeDir: $this->tempDir,
            dataDir: $this->tempDir,
        );

        $completionService = new CompletionManager(
            filesystem: $filesystem,
        );

        $formatterResolver = new FormatterResolver(new TextFormatter(), new JsonFormatter());

        $claudeCodeManager = new \App\Manager\ClaudeCodeManager($this->tempDir);

        $this->command = new SystemInstallCommand(
            $mkcertService,
            $dnsmasqService,
            $traefikService,
            $sshAgentService,
            $completionService,
            $claudeCodeManager,
            $this->tempDir.'/config',
            $formatterResolver,
        );

        $application = new Application();
        $application->getDefinition()->addOption(new InputOption(
            'output',
            'o',
            InputOption::VALUE_REQUIRED,
            'Output format',
            'text',
        ));
        $application->addCommand($this->command);

        $this->commandTester = new CommandTester($this->command);
    }

    protected function tearDown(): void
    {
        if ($this->originalHome !== false) {
            putenv('HOME='.$this->originalHome);
        }

        if (is_dir($this->tempDir)) {
            (new Filesystem())->remove($this->tempDir);
        }
    }
}
