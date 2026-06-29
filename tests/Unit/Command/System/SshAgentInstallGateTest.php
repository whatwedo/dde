<?php

declare(strict_types=1);

namespace Tests\Unit\Command\System;

use App\Command\System\SystemInstallCommand;
use App\Config\GlobalConfig;
use App\Config\SshAgentMode;
use App\Manager\ClaudeCodeManager;
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
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * CI-runnable (non-e2e) coverage for the `system:install` SSH-agent gate at
 * SystemInstallCommand.php:93 — `if (… === SshAgentMode::Managed)`.
 *
 * The full install pipeline (mkcert, dnsmasq DNS configuration, …) cannot run
 * as a unit test: `DnsmasqService::configureDns()` touches `/etc/resolver`
 * (macOS) or restarts systemd-resolved/NetworkManager (Linux), which is why
 * the end-to-end `SystemInstallCommandTest` is `#[Group('e2e')]` and excluded
 * from `make test`. That class also proves nothing about the gate in CI: the
 * polluted DNS output makes its JSON decode return null and the assertions die
 * with `array_column(null)` before they ever reach the gate.
 *
 * This test isolates the gate WITHOUT asserting on the pipeline's JSON. It uses
 * a spy DockerManager that records whether the managed `dde-ssh-agent`
 * container/image was ever probed — `SshAgentService::start()` is the only
 * caller that probes it, and `start()` is reached only when the gate evaluates
 * the mode to Managed. The DNS step still throws inside `runStep`, but that is
 * caught and irrelevant here: the assertions read the spy, not stdout.
 *
 * `SshAgentService` is `final` (not mockable), so — exactly like the up-side
 * host test — the gate is exercised through a real `SshAgentService` wired to
 * a spy collaborator rather than a mock of the service itself.
 */
#[AllowMockObjectsWithoutExpectations]
final class SshAgentInstallGateTest extends TestCase
{
    private string $tempDir;

    private string|false $originalHome;

    public function testManagedModeReachesSshAgentStart(): void
    {
        $probe = $this->buildSpyDockerManager();

        $commandTester = $this->buildCommandTester(SshAgentMode::Managed, $probe['dockerManager']);
        $commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertTrue(
            $probe['reached'](),
            'managed mode must reach SshAgentService::start() (probe the dde-ssh-agent container/image)',
        );
    }

    public function testHostModeSkipsSshAgentStart(): void
    {
        $probe = $this->buildSpyDockerManager();

        $commandTester = $this->buildCommandTester(SshAgentMode::Host, $probe['dockerManager']);
        $commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertFalse(
            $probe['reached'](),
            'host mode must short-circuit before SshAgentService::start() — no dde-ssh-agent probe',
        );
    }

    /**
     * A spy DockerManager recording whether the managed ssh-agent container or
     * image was ever inspected. Both `imageExists('dde-ssh-agent:local')`
     * (from the image build inside `SshAgentService::start()`) and
     * `isContainerRunning('dde-ssh-agent')` (from `parent::start()`) fire only
     * when the gate lets `start()` run.
     *
     * @return array{dockerManager: DockerManager, reached: \Closure(): bool}
     */
    private function buildSpyDockerManager(): array
    {
        $reached = false;
        $dockerManager = $this->createStub(DockerManager::class);
        $dockerManager->method('imageExists')->willReturnCallback(
            static function (string $name) use (&$reached): bool {
                if ($name === 'dde-ssh-agent:local') {
                    $reached = true;
                }

                return true;
            },
        );
        $dockerManager->method('isContainerRunning')->willReturnCallback(
            static function (string $name) use (&$reached): bool {
                if ($name === 'dde-ssh-agent') {
                    $reached = true;
                }

                return false;
            },
        );
        $dockerManager->method('networkExists')->willReturn(true);

        return [
            'dockerManager' => $dockerManager,
            'reached' => static function () use (&$reached): bool {
                return $reached;
            },
        ];
    }

    private function buildCommandTester(SshAgentMode $mode, DockerManager $dockerManager): CommandTester
    {
        $filesystem = new Filesystem();

        $globalConfigManager = $this->createStub(GlobalConfigManager::class);
        $globalConfigManager->method('load')->willReturn(new GlobalConfig(sshAgentMode: $mode));

        $imageBuilder = new ImageBuilder(
            dockerManager: $dockerManager,
            filesystem: $filesystem,
        );

        $mkcertManager = $this->createStub(MkcertManager::class);
        $mkcertManager->method('isMkcertInstalled')->willReturn(true);

        $dnsmasqService = new DnsmasqService(
            dockerManager: $dockerManager,
            filesystem: $filesystem,
            imageBuilder: $imageBuilder,
            projectDir: $this->tempDir,
            dataDir: $this->tempDir,
        );

        $traefikService = new TraefikService(
            dockerManager: $dockerManager,
            filesystem: $filesystem,
            dataDir: $this->tempDir,
        );

        $sshAgentService = new SshAgentService(
            dockerManager: $dockerManager,
            filesystem: $filesystem,
            imageBuilder: $imageBuilder,
            userContext: new UserContext('1000', '1000'),
            globalConfigManager: $globalConfigManager,
            projectDir: $this->tempDir,
            userHomeDir: $this->tempDir,
            dataDir: $this->tempDir,
        );

        $command = new SystemInstallCommand(
            $mkcertManager,
            $dnsmasqService,
            $traefikService,
            $sshAgentService,
            new CompletionManager(filesystem: $filesystem),
            new ClaudeCodeManager($this->tempDir),
            $globalConfigManager,
            $this->tempDir.'/config',
            new FormatterResolver(new TextFormatter(), new JsonFormatter()),
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

        return new CommandTester($command);
    }

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/dde-test-install-gate-'.bin2hex(random_bytes(8));
        $this->originalHome = getenv('HOME');
        putenv('HOME='.$this->tempDir);

        $filesystem = new Filesystem();
        $filesystem->mkdir($this->tempDir);
        $filesystem->mkdir($this->tempDir.'/resources/docker/ssh-agent');
        $filesystem->dumpFile($this->tempDir.'/resources/docker/ssh-agent/Dockerfile', 'FROM alpine');
        $filesystem->dumpFile($this->tempDir.'/resources/docker/ssh-agent/run.sh', '#!/bin/sh');
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
