<?php

declare(strict_types=1);

namespace Tests\Unit\Command\System;

use App\Command\System\SystemUpCommand;
use App\Config\GlobalConfig;
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

    private CommandTester $commandTester;

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

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteJsonOutput(): void
    {
        $this->manager
            ->method('up')
            ->with($this->isNull())
            ->willReturn([
                'globalServices' => [[
                    'name' => 'traefik',
                    'status' => 'already_running',
                ]],
            ]);

        $this->commandTester->execute([
            '--output' => 'json',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $decoded = json_decode($this->commandTester->getDisplay(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('ok', $decoded['status']);
        $this->assertSame('traefik', $decoded['data']['services'][0]['name']);
        $this->assertSame('already_running', $decoded['data']['services'][0]['status']);
    }

    protected function setUp(): void
    {
        $this->manager = $this->createMock(SystemLifecycleManager::class);

        $tempDir = sys_get_temp_dir().'/dde-test-cmd-'.bin2hex(random_bytes(8));
        mkdir($tempDir, 0o777, true);

        $dockerManager = $this->createStub(DockerManager::class);

        $globalConfigManager = $this->createStub(GlobalConfigManager::class);
        $globalConfigManager->method('load')->willReturn(new GlobalConfig());

        $sshAgentService = new SshAgentService(
            dockerManager: $dockerManager,
            filesystem: new Filesystem(),
            imageBuilder: new ImageBuilder($dockerManager, new Filesystem()),
            userContext: new UserContext('1000', '1000'),
            globalConfigManager: $globalConfigManager,
            projectDir: $tempDir,
            userHomeDir: $tempDir,
            dataDir: $tempDir,
        );

        $command = new SystemUpCommand(
            $this->manager,
            $sshAgentService,
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

        $this->commandTester = new CommandTester($command);
    }
}
