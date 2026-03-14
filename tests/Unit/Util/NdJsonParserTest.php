<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use App\Util\NdJsonParser;
use PHPUnit\Framework\TestCase;

final class NdJsonParserTest extends TestCase
{
    public function testEmptyStringReturnsEmptyArray(): void
    {
        $this->assertSame([], NdJsonParser::parse('', 'test'));
    }

    public function testWhitespaceOnlyStringReturnsEmptyArray(): void
    {
        $this->assertSame([], NdJsonParser::parse("  \n  \n  ", 'test'));
    }

    public function testSingleJsonLineReturnsOneResult(): void
    {
        $result = NdJsonParser::parse('{"Name":"foo","Status":"running"}', 'container');

        $this->assertCount(1, $result);
        $this->assertSame('foo', $result[0]['Name']);
        $this->assertSame('running', $result[0]['Status']);
    }

    public function testMultipleLinesReturnsMultipleResults(): void
    {
        $output = implode("\n", [
            '{"Name":"foo","Status":"running"}',
            '{"Name":"bar","Status":"stopped"}',
            '{"Name":"baz","Status":"running"}',
        ]);

        $result = NdJsonParser::parse($output, 'container');

        $this->assertCount(3, $result);
        $this->assertSame('foo', $result[0]['Name']);
        $this->assertSame('bar', $result[1]['Name']);
        $this->assertSame('baz', $result[2]['Name']);
    }

    public function testEmptyLinesBetweenJsonAreSkipped(): void
    {
        $output = implode("\n", [
            '{"Name":"foo"}',
            '',
            '{"Name":"bar"}',
            '   ',
            '{"Name":"baz"}',
        ]);

        $result = NdJsonParser::parse($output, 'container');

        $this->assertCount(3, $result);
        $this->assertSame('foo', $result[0]['Name']);
        $this->assertSame('bar', $result[1]['Name']);
        $this->assertSame('baz', $result[2]['Name']);
    }

    public function testInvalidJsonThrowsRuntimeException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to parse container JSON:/');

        NdJsonParser::parse('not-valid-json', 'container');
    }

    public function testInvalidJsonExceptionIncludesContext(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to parse docker compose ps JSON:/');

        NdJsonParser::parse('{invalid}', 'docker compose ps');
    }

    public function testInvalidJsonOnSecondLineThrowsRuntimeException(): void
    {
        $output = implode("\n", [
            '{"Name":"foo"}',
            'not-valid-json',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Failed to parse volume JSON:/');

        NdJsonParser::parse($output, 'volume');
    }

    public function testLeadingAndTrailingWhitespaceIsTrimmed(): void
    {
        $output = "\n  {\"Name\":\"foo\"}  \n";

        $result = NdJsonParser::parse($output, 'container');

        $this->assertCount(1, $result);
        $this->assertSame('foo', $result[0]['Name']);
    }
}
