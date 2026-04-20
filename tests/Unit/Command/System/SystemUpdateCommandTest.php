<?php

declare(strict_types=1);

namespace Tests\Unit\Command\System;

use App\Command\System\SystemUpdateCommand;
use App\Manager\SystemLifecycleManager;
use App\Model\SystemLifecycleProgress;
use App\Output\FormatterResolver;
use App\Output\JsonFormatter;
use App\Output\TextFormatter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\CommandTester;

#[AllowMockObjectsWithoutExpectations]
final class SystemUpdateCommandTest extends TestCase
{
    private SystemLifecycleManager&MockObject $manager;

    private SystemUpdateCommand $command;

    private CommandTester $commandTester;

    public function testCommandIsRegistered(): void
    {
        $this->assertSame('system:update', $this->command->getName());
    }

    public function testExecuteCallsManagerUpdate(): void
    {
        $this->manager
            ->expects($this->once())
            ->method('update')
            ->with($this->anything(), $this->isInstanceOf(\Closure::class))
            ->willReturn([
                'globalServices' => [[
                    'name' => 'traefik',
                    'status' => 'started',
                ]],
                'versionedContainers' => [],
                'postInstallWarnings' => [],
            ]);

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
    }

    public function testWarningsAreSurfacedButDontFailTheCommand(): void
    {
        $this->manager
            ->method('update')
            ->willReturn([
                'globalServices' => [],
                'versionedContainers' => [],
                'postInstallWarnings' => ['claude-skill: write failed'],
            ]);

        $this->commandTester->execute([], [
            'interactive' => true,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('claude-skill', $this->commandTester->getDisplay());
    }

    public function testProgressCallbackRendersBuildAndPostInstallEvents(): void
    {
        $this->manager
            ->method('update')
            ->willReturnCallback(function (\Symfony\Component\Console\Application $application, \Closure $onProgress): array {
                $onProgress(SystemLifecycleProgress::Building, 'traefik', 'dde-traefik', null);
                $onProgress(SystemLifecycleProgress::Built, 'traefik', 'dde-traefik', null);
                $onProgress(SystemLifecycleProgress::PostInstallStarting, 'shell-completion', null, null);
                $onProgress(SystemLifecycleProgress::PostInstallOk, 'shell-completion', null, null);
                $onProgress(SystemLifecycleProgress::PostInstallStarting, 'claude-skill', null, null);
                $onProgress(SystemLifecycleProgress::PostInstallFailed, 'claude-skill', null, 'write failed');

                return [
                    'globalServices' => [],
                    'versionedContainers' => [],
                    'postInstallWarnings' => ['claude-skill: write failed'],
                ];
            });

        $this->commandTester->execute([], [
            'interactive' => true,
        ]);

        $display = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Building', $display);
        $this->assertStringContainsString('traefik', $display);
        $this->assertStringContainsString('Refreshing', $display);
        $this->assertStringContainsString('shell-completion', $display);
        $this->assertStringContainsString('claude-skill', $display);
        $this->assertStringContainsString('write failed', $display);
    }

    protected function setUp(): void
    {
        $this->manager = $this->createMock(SystemLifecycleManager::class);

        $this->command = new SystemUpdateCommand(
            $this->manager,
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
        $application->addCommand($this->command);

        $this->commandTester = new CommandTester($this->command);
    }
}
