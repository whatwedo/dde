<?php

declare(strict_types=1);

namespace Tests\Unit\Doctor;

use App\Doctor\CheckResult;
use App\Doctor\CheckStatus;
use PHPUnit\Framework\TestCase;

final class CheckResultTest extends TestCase
{
    public function testConstructionWithAllFields(): void
    {
        $result = new CheckResult(
            name: 'Test Check',
            status: CheckStatus::OK,
            message: 'Everything is fine',
            fixHint: 'No fix needed',
        );

        $this->assertSame('Test Check', $result->name);
        $this->assertSame(CheckStatus::OK, $result->status);
        $this->assertSame('Everything is fine', $result->message);
        $this->assertSame('No fix needed', $result->fixHint);
    }

    public function testConstructionWithDefaultFixHint(): void
    {
        $result = new CheckResult(
            name: 'Test Check',
            status: CheckStatus::WARNING,
            message: 'Something might be wrong',
        );

        $this->assertSame('Test Check', $result->name);
        $this->assertSame(CheckStatus::WARNING, $result->status);
        $this->assertSame('Something might be wrong', $result->message);
        $this->assertSame('', $result->fixHint);
    }

    public function testErrorStatus(): void
    {
        $result = new CheckResult(
            name: 'Failing Check',
            status: CheckStatus::ERROR,
            message: 'Something is broken',
            fixHint: 'Fix it now',
        );

        $this->assertSame(CheckStatus::ERROR, $result->status);
        $this->assertSame('Fix it now', $result->fixHint);
    }
}
