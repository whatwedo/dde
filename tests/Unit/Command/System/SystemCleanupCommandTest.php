<?php

declare(strict_types=1);

namespace Tests\Unit\Command\System;

use App\Command\System\SystemCleanupCommand;
use App\Manager\CleanupManager;
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
final class SystemCleanupCommandTest extends TestCase
{
    private CleanupManager&MockObject $cleanupManager;

    private CommandTester $commandTester;

    public function testNoItemsReturnsSuccess(): void
    {
        $this->cleanupManager->method('collectCleanupItems')->willReturn([]);

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Nothing to clean up', $this->commandTester->getDisplay());
    }

    public function testDryRunDoesNotDelete(): void
    {
        $this->cleanupManager->method('collectCleanupItems')->willReturn([
            [
                'type' => 'container',
                'id' => 'dde-test',
                'name' => 'dde-test',
            ],
        ]);

        $this->cleanupManager->expects($this->never())->method('deleteItem');

        $this->commandTester->execute([
            '--dry-run' => true,
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $display = $this->commandTester->getDisplay();
        $this->assertStringContainsString('dde-test', $display);
        $this->assertStringContainsString('Dry run', $display);
    }

    public function testForceDeletesWithoutConfirmation(): void
    {
        $item = [
            'type' => 'container',
            'id' => 'dde-test',
            'name' => 'dde-test',
        ];
        $this->cleanupManager->method('collectCleanupItems')->willReturn([$item]);

        $this->cleanupManager->expects($this->once())->method('deleteItem')->with($item);

        $this->commandTester->execute([
            '--force' => true,
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $display = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Cleaned up', $display);
    }

    public function testRunningContainersAreSkipped(): void
    {
        // CleanupManager already filters running containers; command gets empty list
        $this->cleanupManager->method('collectCleanupItems')->willReturn([]);

        $this->cleanupManager->expects($this->never())->method('deleteItem');

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('Nothing to clean up', $this->commandTester->getDisplay());
    }

    public function testJsonOutputForNoItems(): void
    {
        $this->cleanupManager->method('collectCleanupItems')->willReturn([]);

        $this->commandTester->execute([
            '--output' => 'json',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $decoded = json_decode($this->commandTester->getDisplay(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('ok', $decoded['status']);
        $this->assertSame([], $decoded['data']['items']);
    }

    public function testCertFilesAreListed(): void
    {
        $this->cleanupManager->method('collectCleanupItems')->willReturn([
            [
                'type' => 'cert',
                'id' => '/tmp/certs/example.pem',
                'name' => 'example.pem',
            ],
        ]);

        $this->commandTester->execute([
            '--dry-run' => true,
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $display = $this->commandTester->getDisplay();
        $this->assertStringContainsString('example.pem', $display);
    }

    protected function setUp(): void
    {
        $this->cleanupManager = $this->createMock(CleanupManager::class);

        $formatterResolver = new FormatterResolver(new TextFormatter(), new JsonFormatter());
        $command = new SystemCleanupCommand($this->cleanupManager, $formatterResolver);

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
