<?php

declare(strict_types=1);

namespace Tests\Unit\Command\System;

use App\Command\System\SystemUpCommand;
use App\Config\GlobalConfig;
use App\Config\SshAgentMode;
use App\Manager\DockerManager;
use App\Manager\GlobalConfigManager;
use App\Manager\SystemLifecycleManager;
use App\Model\UserContext;
use App\Output\FormatterResolver;
use App\Output\JsonFormatter;
use App\Output\TextFormatter;
use App\Service\ImageBuilder;
use App\Service\SshAgentService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

#[AllowMockObjectsWithoutExpectations]
final class SystemUpCommandTest extends TestCase
{
    private SystemLifecycleManager&MockObject $manager;

    private string $tempDir;

    public function testExecuteStartsServices(): void
    {
        $this->manager
            ->expects($this->once())
            ->method('up')
            ->with($this->isInstanceOf(\Closure::class))
            ->willReturn([
                'globalServices' => [[
                    'name' => 'traefik',
                    'status' => 'started',
                ]],
            ]);

        $commandTester = $this->buildCommandTester(SshAgentMode::Managed);
        $commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $commandTester->getStatusCode());
    }

    public function testExecuteJsonOutput(): void
    {
        $this->manager
            ->method('up')
            ->willReturn([
                'globalServices' => [[
                    'name' => 'traefik',
                    'status' => 'already_running',
                ]],
            ]);

        $commandTester = $this->buildCommandTester(SshAgentMode::Managed);
        $commandTester->execute([
            '--output' => 'json',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $commandTester->getStatusCode());
        $decoded = json_decode($commandTester->getDisplay(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('ok', $decoded['status']);
        $this->assertSame('traefik', $decoded['data']['services'][0]['name']);
        $this->assertSame('already_running', $decoded['data']['services'][0]['status']);
    }

    public function testManagedModeConsultsAgentModeAndKeepsKeyAddPathReachable(): void
    {
        $this->manager
            ->method('up')
            ->willReturn([
                'globalServices' => [],
            ]);

        // The interactive key-add block is gated on the resolved agent mode as its
        // first operand. In managed mode the mode must be read (the gate is
        // reachable); removing the gate removes this read and fails the expectation.
        $globalConfigManager = $this->createMock(GlobalConfigManager::class);
        $globalConfigManager
            ->expects($this->atLeastOnce())
            ->method('load')
            ->willReturn(new GlobalConfig(sshAgentMode: SshAgentMode::Managed));

        $commandTester = $this->buildCommandTester(SshAgentMode::Managed, null, $globalConfigManager);
        $commandTester->execute([], [
            'interactive' => true,
        ]);

        $this->assertSame(0, $commandTester->getStatusCode());
    }

    /**
     * Proves the key-add gate's FIRST operand is the *value* `SshAgentMode::Managed`,
     * not merely "the mode was read". All other operands are forced true so the mode
     * is the sole deciding factor:
     *  - input is interactive (CommandTester interactive execute),
     *  - `Process::isTtySupported()` is forced true via the command's test seam
     *    (otherwise CI's missing TTY short-circuits the gate before any mode check),
     *  - the ssh-agent spy reports running with zero loaded keys and one configured key.
     *
     * In managed mode the block runs (keys read, `addKeys()` called, "Adding … SSH
     * key(s)" emitted); in host mode it is skipped (none of that happens). Flipping the
     * operand `Managed`→`Host` at SystemUpCommand.php therefore makes the managed case
     * see an empty block (RED) or the host case see a populated one (RED).
     */
    #[DataProvider('keyAddGateModeProvider')]
    public function testKeyAddGateRunsOnlyInManagedMode(SshAgentMode $mode, bool $expectBlockRuns): void
    {
        $this->manager
            ->method('up')
            ->willReturn([
                'globalServices' => [],
            ]);

        $globalConfigManager = $this->createStub(GlobalConfigManager::class);
        $globalConfigManager->method('load')->willReturn(new GlobalConfig(
            sshKeys: ['/home/dde/.ssh/id_ed25519'],
            sshAgentMode: $mode,
        ));

        // Spy DockerManager so the real (final) SshAgentService produces the
        // gate-satisfying state without Docker: the agent container is running
        // (`isRunning()` true), `ssh-add -l` reports no identities
        // (`getLoadedKeyCount()` 0), and reaching the key-add block calls
        // `execInteractive(... ssh-add ...)`, which we record.
        $addKeyExecCalls = 0;
        $dockerManager = $this->createStub(DockerManager::class);
        $dockerManager->method('isContainerRunning')->willReturnCallback(
            static fn (string $name): bool => $name === 'dde-ssh-agent',
        );
        $dockerManager->method('execCapture')->willReturn('The agent has no identities.');
        $dockerManager->method('execInteractive')->willReturnCallback(
            static function (string $name, array $command) use (&$addKeyExecCalls): void {
                if ($name === 'dde-ssh-agent' && ($command[0] ?? null) === 'ssh-add') {
                    ++$addKeyExecCalls;
                }
            },
        );

        $sshAgentService = new SshAgentService(
            dockerManager: $dockerManager,
            filesystem: new Filesystem(),
            imageBuilder: new ImageBuilder($dockerManager, new Filesystem()),
            userContext: new UserContext('1000', '1000'),
            globalConfigManager: $globalConfigManager,
            projectDir: $this->tempDir,
            userHomeDir: $this->tempDir,
            dataDir: $this->tempDir,
        );

        $command = new SystemUpCommand(
            $this->manager,
            $sshAgentService,
            $globalConfigManager,
            new FormatterResolver(new TextFormatter(), new JsonFormatter()),
            // Force the TTY operand true: without this seam CI's missing TTY
            // short-circuits the gate before the mode is decisive, masking the
            // operand-flip regression this test guards against.
            static fn (): bool => true,
        );

        $commandTester = $this->wrapInTester($command);
        $commandTester->execute([], [
            'interactive' => true,
        ]);

        $this->assertSame(0, $commandTester->getStatusCode());

        if ($expectBlockRuns) {
            $this->assertSame(1, $addKeyExecCalls, 'managed mode must run the key-add block');
            $this->assertStringContainsString('Adding', $commandTester->getDisplay());
            $this->assertStringContainsString('SSH key', $commandTester->getDisplay());
        } else {
            $this->assertSame(0, $addKeyExecCalls, 'host mode must NOT run the key-add block');
            $this->assertStringNotContainsString('Adding', $commandTester->getDisplay());
            $this->assertStringNotContainsString('SSH key', $commandTester->getDisplay());
        }
    }

    /**
     * @return iterable<string, array{SshAgentMode, bool}>
     */
    public static function keyAddGateModeProvider(): iterable
    {
        yield 'managed mode runs the key-add block' => [SshAgentMode::Managed, true];

        yield 'host mode skips the key-add block' => [SshAgentMode::Host, false];
    }

    private function buildCommandTester(SshAgentMode $mode, ?DockerManager $dockerManager = null, ?GlobalConfigManager $globalConfigManager = null): CommandTester
    {
        $dockerManager ??= $this->createStub(DockerManager::class);

        if (! $globalConfigManager instanceof GlobalConfigManager) {
            $stub = $this->createStub(GlobalConfigManager::class);
            $stub->method('load')->willReturn(new GlobalConfig(sshAgentMode: $mode));
            $globalConfigManager = $stub;
        }

        $sshAgentService = new SshAgentService(
            dockerManager: $dockerManager,
            filesystem: new Filesystem(),
            imageBuilder: new ImageBuilder($dockerManager, new Filesystem()),
            userContext: new UserContext('1000', '1000'),
            globalConfigManager: $globalConfigManager,
            projectDir: $this->tempDir,
            userHomeDir: $this->tempDir,
            dataDir: $this->tempDir,
        );

        $command = new SystemUpCommand(
            $this->manager,
            $sshAgentService,
            $globalConfigManager,
            new FormatterResolver(new TextFormatter(), new JsonFormatter()),
        );

        return $this->wrapInTester($command);
    }

    private function wrapInTester(SystemUpCommand $command): CommandTester
    {
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
        $this->manager = $this->createMock(SystemLifecycleManager::class);
        $this->tempDir = sys_get_temp_dir().'/dde-test-cmd-'.bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            (new Filesystem())->remove($this->tempDir);
        }
    }
}
