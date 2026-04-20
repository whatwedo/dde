<?php

declare(strict_types=1);

namespace Tests\Unit\Command\System;

use App\Command\System\SystemDownCommand;
use App\Manager\SystemLifecycleManager;
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
final class SystemDownCommandTest extends TestCase
{
    private SystemLifecycleManager&MockObject $manager;

    private CommandTester $commandTester;

    public function testExecuteRemovesAllDdeContainers(): void
    {
        $this->manager
            ->expects($this->once())
            ->method('down')
            ->with($this->isInstanceOf(\Closure::class))
            ->willReturn([
                'globalServices' => [[
                    'name' => 'traefik',
                    'status' => 'removed',
                ]],
                'versionedContainers' => [[
                    'name' => 'project-db',
                    'status' => 'removed',
                ]],
            ]);

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
    }

    protected function setUp(): void
    {
        $this->manager = $this->createMock(SystemLifecycleManager::class);

        $command = new SystemDownCommand(
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
        $application->addCommand($command);

        $this->commandTester = new CommandTester($command);
    }
}
