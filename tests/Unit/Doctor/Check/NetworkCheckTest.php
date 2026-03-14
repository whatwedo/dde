<?php

declare(strict_types=1);

namespace Tests\Unit\Doctor\Check;

use App\Doctor\Check\NetworkCheck;
use App\Doctor\CheckStatus;
use App\Manager\DockerManager;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class NetworkCheckTest extends TestCase
{
    private DockerManager&Stub $dockerManager;

    private NetworkCheck $check;

    public function testGetName(): void
    {
        $this->assertSame('Docker Network', $this->check->getName());
    }

    public function testRunReturnsOkWhenNetworkExists(): void
    {
        $this->dockerManager
            ->method('networkExists')
            ->willReturn(true);

        $result = $this->check->run();

        $this->assertSame(CheckStatus::OK, $result->status);
        $this->assertStringContainsString("'dde' exists", $result->message);
        $this->assertSame('', $result->fixHint);
    }

    public function testRunReturnsErrorWhenNetworkDoesNotExist(): void
    {
        $this->dockerManager
            ->method('networkExists')
            ->willReturn(false);

        $result = $this->check->run();

        $this->assertSame(CheckStatus::ERROR, $result->status);
        $this->assertStringContainsString("'dde' not found", $result->message);
        $this->assertSame('Run: dde system:up', $result->fixHint);
    }

    public function testResultNameMatchesCheckName(): void
    {
        $this->dockerManager->method('networkExists')->willReturn(true);
        $result = $this->check->run();

        $this->assertSame($this->check->getName(), $result->name);
    }

    protected function setUp(): void
    {
        $this->dockerManager = $this->createStub(DockerManager::class);
        $this->check = new NetworkCheck($this->dockerManager);
    }
}
