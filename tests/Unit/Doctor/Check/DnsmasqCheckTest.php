<?php

declare(strict_types=1);

namespace Tests\Unit\Doctor\Check;

use App\Doctor\Check\DnsmasqCheck;
use App\Doctor\CheckStatus;
use App\Manager\DockerManager;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class DnsmasqCheckTest extends TestCase
{
    private DockerManager&Stub $dockerManager;

    private DnsmasqCheck $check;

    public function testGetName(): void
    {
        $this->assertSame('dnsmasq', $this->check->getName());
    }

    public function testCheckReturnsOkWhenContainerIsRunning(): void
    {
        $this->dockerManager
            ->method('isContainerRunning')
            ->willReturn(true);

        $result = $this->check->run();

        $this->assertSame(CheckStatus::OK, $result->status);
        $this->assertSame('dnsmasq container is running', $result->message);
        $this->assertSame('', $result->fixHint);
    }

    public function testCheckReturnsErrorWhenContainerIsNotRunning(): void
    {
        $this->dockerManager
            ->method('isContainerRunning')
            ->willReturn(false);

        $result = $this->check->run();

        $this->assertSame(CheckStatus::ERROR, $result->status);
        $this->assertSame('dnsmasq container is not running.', $result->message);
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
        $this->check = new DnsmasqCheck($this->dockerManager);
    }
}
