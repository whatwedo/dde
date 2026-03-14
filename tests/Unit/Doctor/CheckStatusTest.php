<?php

declare(strict_types=1);

namespace Tests\Unit\Doctor;

use App\Doctor\CheckStatus;
use PHPUnit\Framework\TestCase;

final class CheckStatusTest extends TestCase
{
    public function testEnumCases(): void
    {
        $this->assertSame('ok', CheckStatus::OK->value);
        $this->assertSame('warning', CheckStatus::WARNING->value);
        $this->assertSame('error', CheckStatus::ERROR->value);
        $this->assertSame('skipped', CheckStatus::SKIPPED->value);
    }

    public function testAllCasesExist(): void
    {
        $cases = CheckStatus::cases();
        $values = array_map(static fn (CheckStatus $c) => $c->value, $cases);

        $this->assertContains('ok', $values);
        $this->assertContains('warning', $values);
        $this->assertContains('error', $values);
        $this->assertContains('skipped', $values);
        $this->assertCount(4, $cases);
    }

    public function testFromValue(): void
    {
        $this->assertSame(CheckStatus::OK, CheckStatus::from('ok'));
        $this->assertSame(CheckStatus::WARNING, CheckStatus::from('warning'));
        $this->assertSame(CheckStatus::ERROR, CheckStatus::from('error'));
        $this->assertSame(CheckStatus::SKIPPED, CheckStatus::from('skipped'));
    }
}
