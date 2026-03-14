<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Model\ServiceStartStatus;
use PHPUnit\Framework\TestCase;

final class ServiceStartStatusTest extends TestCase
{
    public function testValues(): void
    {
        $this->assertSame('started', ServiceStartStatus::STARTED->value);
        $this->assertSame('already_running', ServiceStartStatus::ALREADY_RUNNING->value);
    }

    public function testCases(): void
    {
        $cases = ServiceStartStatus::cases();
        $this->assertCount(2, $cases);
    }

    public function testFromValid(): void
    {
        $this->assertSame(ServiceStartStatus::STARTED, ServiceStartStatus::from('started'));
        $this->assertSame(ServiceStartStatus::ALREADY_RUNNING, ServiceStartStatus::from('already_running'));
    }

    public function testTryFromInvalid(): void
    {
        $this->assertNull(ServiceStartStatus::tryFrom('unknown'));
    }
}
