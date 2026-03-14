<?php

declare(strict_types=1);

namespace Tests\Unit\Doctor\Check;

use App\Doctor\Check\MailpitCheck;
use App\Doctor\CheckStatus;
use App\Manager\DockerManager;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class MailpitCheckTest extends TestCase
{
    private DockerManager&Stub $dockerManager;

    private MailpitCheck $check;

    public function testGetName(): void
    {
        $this->assertSame('Mailpit', $this->check->getName());
    }

    public function testCheckReturnsOkWhenContainerIsRunning(): void
    {
        $this->dockerManager
            ->method('isContainerRunning')
            ->willReturn(true);

        $result = $this->check->run();

        $this->assertSame(CheckStatus::OK, $result->status);
        $this->assertSame('Mailpit container is running', $result->message);
        $this->assertSame('', $result->fixHint);
    }

    public function testCheckReturnsWarningWhenContainerIsNotRunning(): void
    {
        $this->dockerManager
            ->method('isContainerRunning')
            ->willReturn(false);

        $result = $this->check->run();

        $this->assertSame(CheckStatus::WARNING, $result->status);
        $this->assertSame('Mailpit container is not running.', $result->message);
        $this->assertSame('Run: dde system:up', $result->fixHint);
    }

    public function testResultNameMatchesCheckName(): void
    {
        $this->dockerManager->method('isContainerRunning')->willReturn(true);
        $result = $this->check->run();

        $this->assertSame($this->check->getName(), $result->name);
    }

    public function testGetPriority(): void
    {
        $this->assertSame(0, $this->check->getPriority());
    }

    public function testRequiresDocker(): void
    {
        $this->assertTrue($this->check->requiresDocker());
    }

    protected function setUp(): void
    {
        $this->dockerManager = $this->createStub(DockerManager::class);
        $this->check = new MailpitCheck($this->dockerManager);
    }
}
