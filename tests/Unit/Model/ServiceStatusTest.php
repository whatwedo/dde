<?php

declare(strict_types=1);

namespace Tests\Unit\Model;

use App\Model\ServiceStatus;
use PHPUnit\Framework\TestCase;

final class ServiceStatusTest extends TestCase
{
    public function testAllEnumValuesExist(): void
    {
        $cases = ServiceStatus::cases();

        $this->assertCount(2, $cases);
        $this->assertContains(ServiceStatus::RUNNING, $cases);
        $this->assertContains(ServiceStatus::STOPPED, $cases);
    }

    public function testValuesMatchExpectedStrings(): void
    {
        $this->assertSame('running', ServiceStatus::RUNNING->value);
        $this->assertSame('stopped', ServiceStatus::STOPPED->value);
    }

    public function testFromValidValue(): void
    {
        $this->assertSame(ServiceStatus::RUNNING, ServiceStatus::from('running'));
    }

    public function testFromInvalidValueThrowsError(): void
    {
        $this->expectException(\ValueError::class);

        ServiceStatus::from('invalid');
    }
}
