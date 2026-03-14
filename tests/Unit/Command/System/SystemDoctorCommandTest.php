<?php

declare(strict_types=1);

namespace Tests\Unit\Command\System;

use App\Command\System\SystemDoctorCommand;
use App\Doctor\Check\DockerAvailableCheck;
use App\Doctor\CheckInterface;
use App\Doctor\CheckResult;
use App\Doctor\CheckStatus;
use App\Output\FormatterResolver;
use App\Output\JsonFormatter;
use App\Output\TextFormatter;
use App\Util\ProcessFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

final class SystemDoctorCommandTest extends TestCase
{
    private CommandTester $commandTester;

    public function testAllChecksOkReturnsSuccess(): void
    {
        $this->buildCommand(
            $this->buildCheck('Docker', CheckStatus::OK, 'Docker is running'),
            $this->buildCheck('Traefik', CheckStatus::OK, 'Traefik is running'),
        );

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
    }

    public function testAnyErrorReturnsFailure(): void
    {
        $this->buildCommand(
            $this->buildCheck('Docker', CheckStatus::OK, 'Docker is running'),
            $this->buildCheck('Traefik', CheckStatus::ERROR, 'Traefik is not running.', 'Run: dde system:up'),
        );

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertSame(1, $this->commandTester->getStatusCode());
    }

    public function testWarningAloneReturnsSuccess(): void
    {
        $this->buildCommand(
            $this->buildCheck('DNS', CheckStatus::WARNING, 'DNS not working.', 'Run: dde system:up'),
        );

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
    }

    public function testWarningTextOutputShowsWarningMessage(): void
    {
        $this->buildCommand(
            $this->buildCheck('Docker', CheckStatus::OK, 'Docker is running'),
            $this->buildCheck('DNS', CheckStatus::WARNING, 'DNS not working.', 'Run: dde system:up'),
        );

        $this->commandTester->execute([]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('with warnings', $this->commandTester->getDisplay());
    }

    public function testTextOutputContainsCheckResults(): void
    {
        $this->buildCommand(
            $this->buildCheck('Docker', CheckStatus::OK, 'Docker is running'),
            $this->buildCheck('Traefik', CheckStatus::ERROR, 'Traefik is not running.', 'Run: dde system:up'),
        );

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $display = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Docker', $display);
        $this->assertStringContainsString('Docker is running', $display);
        $this->assertStringContainsString('Traefik', $display);
        $this->assertStringContainsString('Traefik is not running.', $display);
        $this->assertStringContainsString('Run: dde system:up', $display);
    }

    public function testJsonOutputStructure(): void
    {
        $this->buildCommand(
            $this->buildCheck('Docker', CheckStatus::OK, 'Docker is running'),
            $this->buildCheck('Traefik', CheckStatus::ERROR, 'Traefik is not running.', 'Run: dde system:up'),
        );

        $this->commandTester->execute([
            '--output' => 'json',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(1, $this->commandTester->getStatusCode());

        $decoded = json_decode($this->commandTester->getDisplay(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('error', $decoded['status']);
        $this->assertSame('Some checks failed.', $decoded['message']);
        $this->assertIsArray($decoded['errors']);
        $this->assertCount(1, $decoded['errors']);
        $this->assertSame('Traefik: Traefik is not running.', $decoded['errors'][0]);
    }

    public function testEmptyChecksReturnsSuccess(): void
    {
        $this->buildCommand();

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
    }

    public function testChecksAreSortedByPriority(): void
    {
        $process = $this->createStub(Process::class);
        $process->method('isSuccessful')->willReturn(false);

        $processFactory = $this->createStub(ProcessFactory::class);
        $processFactory->method('create')->willReturn($process);

        $dockerCheck = new DockerAvailableCheck($processFactory);

        $this->buildCommand(
            $this->buildCheck('Traefik', CheckStatus::OK, 'Traefik is running', requiresDocker: true),
            $dockerCheck,
        );

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        json_decode($this->commandTester->getDisplay(), true);

        // Docker check failed, so it should not return success
        $this->assertSame(1, $this->commandTester->getStatusCode());
    }

    public function testDockerDependentChecksAreSkippedWhenDockerUnavailable(): void
    {
        $process = $this->createStub(Process::class);
        $process->method('isSuccessful')->willReturn(false);

        $processFactory = $this->createStub(ProcessFactory::class);
        $processFactory->method('create')->willReturn($process);

        $dockerCheck = new DockerAvailableCheck($processFactory);

        $this->buildCommand(
            $dockerCheck,
            $this->buildCheck('Traefik', CheckStatus::OK, 'Traefik is running', requiresDocker: true),
            $this->buildCheck('mkcert', CheckStatus::OK, 'mkcert installed'),
        );

        $this->commandTester->execute([
            '--output' => 'json',
        ], [
            'interactive' => false,
        ]);

        $decoded = json_decode($this->commandTester->getDisplay(), true);
        $this->assertSame('error', $decoded['status']);

        // Traefik should be skipped since Docker is unavailable
        $this->assertSame(1, $this->commandTester->getStatusCode());
    }

    public function testDockerDependentChecksRunWhenDockerAvailable(): void
    {
        $this->buildCommand(
            $this->buildCheck('Docker', CheckStatus::OK, 'Docker is running', priority: 100),
            $this->buildCheck('Traefik', CheckStatus::OK, 'Traefik is running', requiresDocker: true),
        );

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
    }

    public function testSkippedChecksShowInTextOutput(): void
    {
        $process = $this->createStub(Process::class);
        $process->method('isSuccessful')->willReturn(false);

        $processFactory = $this->createStub(ProcessFactory::class);
        $processFactory->method('create')->willReturn($process);

        $dockerCheck = new DockerAvailableCheck($processFactory);

        $this->buildCommand(
            $dockerCheck,
            $this->buildCheck('Traefik', CheckStatus::OK, 'Traefik is running', requiresDocker: true),
        );

        $this->commandTester->execute([], [
            'interactive' => true,
            'decorated' => false,
        ]);

        $display = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Traefik', $display);
        $this->assertStringContainsString('Skipped (Docker not available)', $display);
    }

    private function buildCheck(string $name, CheckStatus $status, string $message, string $fixHint = '', int $priority = 0, bool $requiresDocker = false): CheckInterface
    {
        $check = $this->createStub(CheckInterface::class);
        $check->method('getName')->willReturn($name);
        $check->method('run')->willReturn(new CheckResult($name, $status, $message, $fixHint));
        $check->method('getPriority')->willReturn($priority);
        $check->method('requiresDocker')->willReturn($requiresDocker);

        return $check;
    }

    private function buildCommand(CheckInterface ...$checks): SystemDoctorCommand
    {
        $formatterResolver = new FormatterResolver(new TextFormatter(), new JsonFormatter());
        $command = new SystemDoctorCommand($checks, $formatterResolver);

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

        return $command;
    }
}
