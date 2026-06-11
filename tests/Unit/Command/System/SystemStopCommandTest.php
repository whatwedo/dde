<?php

declare(strict_types=1);

namespace Tests\Unit\Command\System;

use App\Command\System\SystemStopCommand;
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
final class SystemStopCommandTest extends TestCase
{
    private SystemLifecycleManager&MockObject $manager;

    private SystemStopCommand $command;

    private CommandTester $commandTester;

    public function testCommandIsRegistered(): void
    {
        $this->assertSame('system:stop', $this->command->getName());
    }

    public function testExecuteStopsServices(): void
    {
        $this->manager
            ->expects($this->once())
            ->method('stop')
            ->with($this->isInstanceOf(\Closure::class))
            ->willReturn([
                'globalServices' => [[
                    'name' => 'traefik',
                    'status' => 'stopped',
                ]],
                'versionedContainers' => [],
            ]);

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
    }

    public function testExecuteRendersVersionedContainersInInteractiveMode(): void
    {
        $this->manager
            ->method('stop')
            ->willReturnCallback(function (\Closure $onProgress): array {
                $onProgress(SystemLifecycleProgress::Stopping, 'traefik', 'dde-traefik', null);
                $onProgress(SystemLifecycleProgress::Stopped, 'traefik', 'dde-traefik', null);
                $onProgress(SystemLifecycleProgress::Stopping, 'project-db', 'project-db', null);
                $onProgress(SystemLifecycleProgress::Stopped, 'project-db', 'project-db', null);

                return [
                    'globalServices' => [[
                        'name' => 'traefik',
                        'status' => 'stopped',
                    ]],
                    'versionedContainers' => [[
                        'name' => 'project-db',
                        'status' => 'stopped',
                    ]],
                ];
            });

        $this->commandTester->execute([], [
            'interactive' => true,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $display = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Stopping', $display);
        $this->assertStringContainsString('traefik', $display);
        $this->assertStringContainsString('project-db', $display);
        $this->assertStringContainsString('done', $display);
    }

    public function testExecuteJsonOutput(): void
    {
        $this->manager
            ->method('stop')
            ->willReturn([
                'globalServices' => [[
                    'name' => 'traefik',
                    'status' => 'stopped',
                ]],
                'versionedContainers' => [[
                    'name' => 'project-db',
                    'status' => 'stopped',
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
        $this->assertSame('traefik', $decoded['data']['globalServices'][0]['name']);
        $this->assertSame('project-db', $decoded['data']['versionedContainers'][0]['name']);
    }

    protected function setUp(): void
    {
        $this->manager = $this->createMock(SystemLifecycleManager::class);

        $this->command = new SystemStopCommand(
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
