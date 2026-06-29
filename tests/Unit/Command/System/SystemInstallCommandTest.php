<?php

declare(strict_types=1);

namespace Tests\Unit\Command\System;

use App\Command\System\SystemInstallCommand;
use App\Config\GlobalConfig;
use App\Config\SshAgentMode;
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

    private Filesystem $filesystem;

    private CompletionManager $completionManager;

    private MkcertManager&Stub $mkcertManager;

    private DnsmasqService $dnsmasqService;

    private TraefikService $traefikService;

    private ImageBuilder $imageBuilder;

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

    // The SSH-agent install gate (SystemInstallCommand.php:93) is covered by the
    // CI-runnable SshAgentInstallGateTest. It cannot be asserted here: this class
    // is #[Group('e2e')] and runs the full install pipeline, whose real DNS
    // configuration pollutes stdout so the JSON decode returns null. See that
    // test for the non-e2e, TTY-independent gate guard.

    private function buildCommand(SshAgentMode $mode): void
    {
        $globalConfigManager = $this->createStub(GlobalConfigManager::class);
        $globalConfigManager->method('load')->willReturn(new GlobalConfig(sshAgentMode: $mode));

        $sshAgentService = new SshAgentService(
            dockerManager: $this->dockerManager,
            filesystem: $this->filesystem,
            imageBuilder: $this->imageBuilder,
            userContext: new UserContext('1000', '1000'),
            globalConfigManager: $globalConfigManager,
            projectDir: $this->tempDir,
            userHomeDir: $this->tempDir,
            dataDir: $this->tempDir,
        );

        $formatterResolver = new FormatterResolver(new TextFormatter(), new JsonFormatter());

        $claudeCodeManager = new \App\Manager\ClaudeCodeManager($this->tempDir);

        $this->command = new SystemInstallCommand(
            $this->mkcertManager,
            $this->dnsmasqService,
            $this->traefikService,
            $sshAgentService,
            $this->completionManager,
            $claudeCodeManager,
            $globalConfigManager,
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

    protected function setUp(): void
    {
        $this->dockerManager = $this->createStub(DockerManager::class);
        $this->tempDir = sys_get_temp_dir().'/dde-test-install-'.bin2hex(random_bytes(8));
        $this->originalHome = getenv('HOME');
        putenv('HOME='.$this->tempDir);
        $this->filesystem = new Filesystem();
        $this->filesystem->mkdir($this->tempDir);

        // Create docker context dirs expected by DnsmasqService and SshAgentService
        $this->filesystem->mkdir($this->tempDir.'/resources/docker/dnsmasq');
        $this->filesystem->dumpFile($this->tempDir.'/resources/docker/dnsmasq/Dockerfile', 'FROM alpine');
        $this->filesystem->mkdir($this->tempDir.'/resources/docker/ssh-agent');
        $this->filesystem->dumpFile($this->tempDir.'/resources/docker/ssh-agent/Dockerfile', 'FROM alpine');
        $this->filesystem->dumpFile($this->tempDir.'/resources/docker/ssh-agent/run.sh', '#!/bin/sh');

        $this->mkcertManager = $this->createStub(MkcertManager::class);
        $this->mkcertManager->method('isMkcertInstalled')->willReturn(true);

        $this->imageBuilder = new ImageBuilder(
            dockerManager: $this->dockerManager,
            filesystem: $this->filesystem,
        );

        $this->dnsmasqService = new DnsmasqService(
            dockerManager: $this->dockerManager,
            filesystem: $this->filesystem,
            imageBuilder: $this->imageBuilder,
            projectDir: $this->tempDir,
            dataDir: $this->tempDir,
        );

        $this->traefikService = new TraefikService(
            dockerManager: $this->dockerManager,
            filesystem: $this->filesystem,
            dataDir: $this->tempDir,
        );

        $this->completionManager = new CompletionManager(
            filesystem: $this->filesystem,
        );

        $this->buildCommand(SshAgentMode::Managed);
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
