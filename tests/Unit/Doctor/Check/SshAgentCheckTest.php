<?php

declare(strict_types=1);

namespace Tests\Unit\Doctor\Check;

use App\Doctor\Check\SshAgentCheck;
use App\Doctor\CheckStatus;
use App\Manager\DockerManager;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class SshAgentCheckTest extends TestCase
{
    private DockerManager&Stub $dockerManager;

    private SshAgentCheck $check;

    public function testGetName(): void
    {
        $this->assertSame('SSH Agent', $this->check->getName());
    }

    public function testCheckReturnsOkWhenContainerIsRunning(): void
    {
        $this->dockerManager
            ->method('isContainerRunning')
            ->willReturn(true);

        $result = $this->check->run();

        $this->assertSame(CheckStatus::OK, $result->status);
        $this->assertSame('SSH agent container is running', $result->message);
        $this->assertSame('', $result->fixHint);
    }

    public function testCheckReturnsErrorWhenContainerIsNotRunning(): void
    {
        $this->dockerManager
            ->method('isContainerRunning')
            ->willReturn(false);

        $result = $this->check->run();

        $this->assertSame(CheckStatus::ERROR, $result->status);
        $this->assertSame('SSH agent container is not running.', $result->message);
        $this->assertSame('Run: dde system:up', $result->fixHint);
    }

    public function testResultNameMatchesCheckName(): void
    {
        $this->dockerManager->method('isContainerRunning')->willReturn(true);
        $result = $this->check->run();

        $this->assertSame($this->check->getName(), $result->name);
    }

    protected function setUp(): void
    {
        $this->dockerManager = $this->createStub(DockerManager::class);
        $this->check = new SshAgentCheck($this->dockerManager);
    }
}
