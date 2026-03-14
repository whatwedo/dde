<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use App\Command\AbstractProjectCommand;
use App\Database\DatabaseAdapterRegistry;
use App\Manager\ConfigManager;
use App\Output\FormatterResolver;
use App\Output\JsonFormatter;
use App\Output\TextFormatter;
use App\Service\ServiceRegistry;
use App\Util\ProcessFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;

final class AbstractProjectCommandTest extends TestCase
{
    public function testExtendsCommand(): void
    {
        $configManager = new ConfigManager(configDir: '/tmp', serviceRegistry: new ServiceRegistry([], new DatabaseAdapterRegistry([])), processFactory: new ProcessFactory());
        $formatterResolver = new FormatterResolver(new TextFormatter(), new JsonFormatter());

        $command = new class($configManager, $formatterResolver) extends AbstractProjectCommand {
        };

        $this->assertInstanceOf(Command::class, $command);
    }

    public function testGetProjectDirectoryThrowsWhenConfigManagerNotImplemented(): void
    {
        $configManager = new ConfigManager(configDir: '/tmp', serviceRegistry: new ServiceRegistry([], new DatabaseAdapterRegistry([])), processFactory: new ProcessFactory());
        $formatterResolver = new FormatterResolver(new TextFormatter(), new JsonFormatter());

        $command = new class($configManager, $formatterResolver) extends AbstractProjectCommand {
            public function callGetProjectDirectory(): string
            {
                return $this->getProjectDirectory();
            }
        };

        $this->expectException(\RuntimeException::class);

        $command->callGetProjectDirectory();
    }
}
