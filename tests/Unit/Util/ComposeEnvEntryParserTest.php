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
        yield 'list-form value with multiple equals splits at first' => [0, 'KEY=a=b=c', ['KEY', 'a=b=c']];
        yield 'map-form empty value' => ['EMPTY_VAR', '', ['EMPTY_VAR', '']];
        yield 'list-form value with equals at start rejected (empty key)' => [0, '=value', null];
        yield 'map-form integer scalar cast to string' => ['APP_PORT', 8080, ['APP_PORT', '8080']];
        yield 'map-form float scalar cast to string' => ['RATIO', 1.5, ['RATIO', '1.5']];
        yield 'map-form boolean true cast to string' => ['DEBUG', true, ['DEBUG', 'true']];
        yield 'map-form boolean false cast to string' => ['DEBUG', false, ['DEBUG', 'false']];
        yield 'map-form null value means inherit from host (no value)' => ['INHERITED', null, null];
        yield 'map-form array value rejected' => ['NESTED', ['x'], null];
    }

    /**
     * @param array{0: string, 1: string}|null $expected
     */
    #[DataProvider('provideExtract')]
    public function testExtract(int|string $key, mixed $value, ?array $expected): void
    {
        $this->assertSame($expected, ComposeEnvEntryParser::extract($key, $value));
    }

    /**
     * @return iterable<string, array{int|string, mixed, string|null}>
     */
    public static function provideExtractKey(): iterable
    {
        yield 'list-form bare key (host pass-through)' => [0, 'INHERITED', 'INHERITED'];
        yield 'list-form key=value' => [0, 'APP_URL=https://example.com', 'APP_URL'];
        yield 'list-form empty entry' => [0, '', null];
        yield 'list-form starting with equals rejected' => [0, '=value', null];
        yield 'list-form non-string rejected' => [0, 42, null];
        yield 'map-form string key returned verbatim' => ['APP_URL', 'https://example.com', 'APP_URL'];
        yield 'map-form string key with null value still declares the key' => ['INHERITED', null, 'INHERITED'];
        yield 'map-form empty string key rejected' => ['', 'value', null];
    }

    #[DataProvider('provideExtractKey')]
    public function testExtractKey(int|string $key, mixed $value, ?string $expected): void
    {
        $this->assertSame($expected, ComposeEnvEntryParser::extractKey($key, $value));
    }
}
