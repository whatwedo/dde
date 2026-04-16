<?php

declare(strict_types=1);

namespace Tests\Unit\Command\Project;

use App\Command\Project\ProjectStopCommand;
use App\Config\GlobalConfig;
use App\Config\ProjectConfig;
use App\Config\ResolvedConfig;
use App\Manager\DockerComposeManager;
use App\Manager\ProjectConfigManager;
use App\Output\FormatterResolver;
use App\Output\JsonFormatter;
use App\Output\TextFormatter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

#[AllowMockObjectsWithoutExpectations]
final class ProjectStopCommandTest extends TestCase
{
    private string $tempDir;

    private ProjectConfigManager&Stub $configManager;

    private DockerComposeManager&MockObject $dockerComposeManager;

    private CommandTester $commandTester;

    public function testCommandIsRegistered(): void
    {
        $command = new ProjectStopCommand(
            $this->configManager,
            $this->dockerComposeManager,
            new FormatterResolver(new TextFormatter(), new JsonFormatter()),
        );

        $this->assertSame('project:stop', $command->getName());
    }

    public function testSuccessfulStopWithTextOutput(): void
    {
        $this->setupProjectFixture();

        $this->dockerComposeManager
            ->expects($this->once())
            ->method('stop')
            ->with($this->tempDir);

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $display = $this->commandTester->getDisplay();
        $this->assertStringContainsString('test-project', $display);
        $this->assertStringContainsString('stopped', $display);
    }

    public function testSuccessfulStopWithJsonOutput(): void
    {
        $this->setupProjectFixture();

        $this->dockerComposeManager
            ->method('stop');

        $this->commandTester->execute([
            '--output' => 'json',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $decoded = json_decode($this->commandTester->getDisplay(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('ok', $decoded['status']);
        $this->assertSame('stopped', $decoded['data']['status']);
    }

    public function testErrorWhenNoProjectDirectoryFound(): void
    {
        $this->configManager
            ->method('findProjectDirectory')
            ->willReturn(null);

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertNotSame(0, $this->commandTester->getStatusCode());
    }

    public function testStopCommandBubblesUpDockerComposeException(): void
    {
        $this->setupProjectFixture();

        $this->dockerComposeManager
            ->method('stop')
            ->willThrowException(new \RuntimeException('Docker compose not found'));

        $this->expectException(\RuntimeException::class);

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);
    }

    private function setupProjectFixture(): void
    {
        $resolvedConfig = ResolvedConfig::merge(new GlobalConfig(), new ProjectConfig(name: 'test-project'));

        $this->configManager
            ->method('findProjectDirectory')
            ->willReturn($this->tempDir);

        $this->configManager
            ->method('resolveConfig')
            ->willReturn($resolvedConfig);
    }

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/dde_test_stop_'.bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o755, true);

        $this->configManager = $this->createStub(ProjectConfigManager::class);
        $this->dockerComposeManager = $this->createMock(DockerComposeManager::class);

        $command = new ProjectStopCommand(
            $this->configManager,
            $this->dockerComposeManager,
            new FormatterResolver(new TextFormatter(), new JsonFormatter()),
        );

        $application = new Application();
        $application->getDefinition()->addOption(new InputOption('output', 'o', InputOption::VALUE_REQUIRED, '', 'text'));
        $application->addCommand($command);

        $this->commandTester = new CommandTester($command);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            (new Filesystem())->remove($this->tempDir);
        }
    }
}
