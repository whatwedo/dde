<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use App\Command\AbstractProjectCommand;
use App\Manager\ProjectConfigManager;
use App\Output\FormatterResolver;
use App\Output\JsonFormatter;
use App\Output\TextFormatter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;

final class AbstractProjectCommandTest extends TestCase
{
    public function testExtendsCommand(): void
    {
        $configManager = $this->createStub(ProjectConfigManager::class);
        $formatterResolver = new FormatterResolver(new TextFormatter(), new JsonFormatter());

        $command = new class($configManager, $formatterResolver) extends AbstractProjectCommand {
        };

        $this->assertInstanceOf(Command::class, $command);
    }

    public function testGetProjectDirectoryThrowsWhenConfigManagerNotImplemented(): void
    {
        $configManager = $this->createStub(ProjectConfigManager::class);
        $configManager->method('findProjectDirectory')->willReturn(null);
        $formatterResolver = new FormatterResolver(new TextFormatter(), new JsonFormatter());

        $command = new class($configManager, $formatterResolver) extends AbstractProjectCommand {
            public function callGetProjectDirectory(): string
            {
                return $this->getProjectDirectory();
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('dde project:init');

        $command->callGetProjectDirectory();
    }
}
