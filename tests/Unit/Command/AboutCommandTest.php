<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use App\Application;
use App\Command\AboutCommand;
use App\Output\FormatterResolver;
use App\Output\JsonFormatter;
use App\Output\TextFormatter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\CommandTester;

final class AboutCommandTest extends TestCase
{
    private CommandTester $commandTester;

    public function testExecuteShowsAboutInfo(): void
    {
        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $display = $this->commandTester->getDisplay();
        $this->assertStringContainsString('dde - Docker Development Environment', $display);
        $this->assertStringContainsString(Application::resolveVersion(), $display);
        $this->assertStringContainsString(PHP_VERSION, $display);
    }

    public function testExecuteJsonOutput(): void
    {
        $this->commandTester->execute([
            '--output' => 'json',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $decoded = json_decode($this->commandTester->getDisplay(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('ok', $decoded['status']);
        $this->assertSame(Application::resolveVersion(), $decoded['data']['version']);
        $this->assertSame(PHP_VERSION, $decoded['data']['php']);
        $this->assertArrayHasKey('config_dir', $decoded['data']);
        $this->assertArrayHasKey('data_dir', $decoded['data']);
    }

    protected function setUp(): void
    {
        $formatterResolver = new FormatterResolver(new TextFormatter(), new JsonFormatter());

        $command = new AboutCommand($formatterResolver, '/tmp/.dde', '/tmp/.dde/data');

        $application = new ConsoleApplication();
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
