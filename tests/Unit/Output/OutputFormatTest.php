<?php

declare(strict_types=1);

namespace Tests\Unit\Output;

use App\Output\OutputFormat;
use PHPUnit\Framework\TestCase;

final class OutputFormatTest extends TestCase
{
    public function testCasesCount(): void
    {
        $this->assertCount(2, OutputFormat::cases());
    }

    public function testTextValue(): void
    {
        $this->assertSame('text', OutputFormat::TEXT->value);
    }

    public function testJsonValue(): void
    {
        $this->assertSame('json', OutputFormat::JSON->value);
    }

    public function testTryFromWithValidValue(): void
    {
        $this->assertSame(OutputFormat::TEXT, OutputFormat::tryFrom('text'));
        $this->assertSame(OutputFormat::JSON, OutputFormat::tryFrom('json'));
    }

    public function testTryFromWithInvalidValueReturnsNull(): void
    {
        $this->assertNull(OutputFormat::tryFrom('xml'));
    }
}
