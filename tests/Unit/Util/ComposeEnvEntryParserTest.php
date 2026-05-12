<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use App\Util\ComposeEnvEntryParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ComposeEnvEntryParserTest extends TestCase
{
    /**
     * @return iterable<string, array{int|string, mixed, array{0: string, 1: string}|null}>
     */
    public static function provideExtract(): iterable
    {
        yield 'list-form key=value' => [0, 'APP_URL=https://example.com', ['APP_URL', 'https://example.com']];
        yield 'map-form string key and string value' => ['APP_URL', 'https://example.com', ['APP_URL', 'https://example.com']];
        yield 'list-form entry without equals sign' => [0, 'NOEQUALS', null];
        yield 'list-form entry with non-string value' => [0, 42, null];
        yield 'map-form entry with non-string value' => ['APP_PORT', 8080, null];
        yield 'list-form value with multiple equals splits at first' => [0, 'KEY=a=b=c', ['KEY', 'a=b=c']];
        yield 'map-form empty value' => ['EMPTY_VAR', '', ['EMPTY_VAR', '']];
        yield 'list-form value with equals at start produces empty key' => [0, '=value', ['', 'value']];
    }

    /**
     * @param array{0: string, 1: string}|null $expected
     */
    #[DataProvider('provideExtract')]
    public function testExtract(int|string $key, mixed $value, ?array $expected): void
    {
        $this->assertSame($expected, ComposeEnvEntryParser::extract($key, $value));
    }
}
