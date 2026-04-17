<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use App\Util\IdentifierSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IdentifierSanitizerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideHostname(): iterable
    {
        yield 'strips project prefix' => ['beispiel-feature-x', 'beispiel', 'feature-x'];
        yield 'lowercases' => ['beispiel-PROJ-123', 'beispiel', 'proj-123'];
        yield 'replaces special chars' => ['beispiel-special_chars!@#', 'beispiel', 'special-chars'];
        yield 'empty suffix fallback' => ['beispiel-', 'beispiel', 'worktree'];
        yield 'dir name equals project name' => ['beispiel', 'beispiel', 'worktree'];
        yield 'no project prefix present' => ['other-dir', 'beispiel', 'other-dir'];
        yield 'case-insensitive prefix removal' => ['Beispiel-feature', 'beispiel', 'feature'];
        yield 'collapses consecutive hyphens' => ['beispiel-foo---bar', 'beispiel', 'foo-bar'];
        yield 'unicode transliteration' => ['beispiel-überarbeitung', 'beispiel', 'uberarbeitung'];
        yield 'pure special chars fallback' => ['beispiel-!!!', 'beispiel', 'worktree'];
    }

    #[DataProvider('provideHostname')]
    public function testForHostname(string $dirName, string $projectName, string $expected): void
    {
        $this->assertSame($expected, IdentifierSanitizer::forHostname($dirName, $projectName));
    }

    public function testForHostnameTruncatesLongNamesTo63Chars(): void
    {
        $longSuffix = str_repeat('a', 100);
        $result = IdentifierSanitizer::forHostname('beispiel-'.$longSuffix, 'beispiel');

        $this->assertLessThanOrEqual(63, strlen($result));
        $this->assertStringStartsNotWith('-', $result);
        $this->assertStringEndsNotWith('-', $result);
    }

    public function testForHostnameTrimsTrailingHyphensAfterTruncation(): void
    {
        $suffix = str_repeat('a', 60).'---bbb';
        $result = IdentifierSanitizer::forHostname($suffix, 'nonmatch');

        $this->assertLessThanOrEqual(63, strlen($result));
        $this->assertFalse(str_ends_with($result, '-'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideDatabase(): iterable
    {
        yield 'simple' => ['myproject', 'myproject'];
        yield 'hyphens' => ['my-project', 'my_project'];
        yield 'dots' => ['my.cool.project', 'my_cool_project'];
        yield 'spaces' => ['my project', 'my_project'];
        yield 'leading digit' => ['2fast2furious', 'db_2fast2furious'];
        yield 'leading underscore' => ['_test', '_test'];
        yield 'special chars' => ['my@project!', 'my_project'];
        yield 'unicode' => ['über-cool', 'uber_cool'];
        yield 'multiple consecutive separators' => ['my--project..name', 'my_project_name'];
        yield 'empty after sanitize' => ['!!!', 'project'];
    }

    #[DataProvider('provideDatabase')]
    public function testForDatabase(string $input, string $expected): void
    {
        $this->assertSame($expected, IdentifierSanitizer::forDatabase($input));
    }

    public function testForDatabaseTruncatesLongNamesTo63Chars(): void
    {
        $long = str_repeat('a', 100);
        $result = IdentifierSanitizer::forDatabase($long);

        $this->assertLessThanOrEqual(63, strlen($result));
        $this->assertStringEndsNotWith('_', $result);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideDatabaseSuffix(): iterable
    {
        yield 'strips project prefix with underscore separator' => ['beispiel-feature-x', 'beispiel', 'feature_x'];
        yield 'replaces hyphens with underscores' => ['feature-branch-name', 'unrelated', 'feature_branch_name'];
        yield 'lowercases and sanitizes' => ['beispiel-PROJ/123', 'beispiel', 'proj_123'];
        yield 'dir name equals project returns fallback' => ['beispiel', 'beispiel', 'worktree'];
        yield 'empty suffix fallback' => ['beispiel-', 'beispiel', 'worktree'];
        yield 'unicode transliteration' => ['beispiel-überarbeitung', 'beispiel', 'uberarbeitung'];
        yield 'collapses consecutive separators' => ['beispiel--foo--bar', 'beispiel', 'foo_bar'];
        yield 'pure special chars fallback' => ['beispiel-!!!', 'beispiel', 'worktree'];
    }

    #[DataProvider('provideDatabaseSuffix')]
    public function testForDatabaseSuffix(string $dirName, string $projectName, string $expected): void
    {
        $this->assertSame($expected, IdentifierSanitizer::forDatabaseSuffix($dirName, $projectName));
    }

    public function testForDatabaseSuffixTruncatesLongNamesTo63Chars(): void
    {
        $long = str_repeat('a', 100);
        $result = IdentifierSanitizer::forDatabaseSuffix('beispiel-'.$long, 'beispiel');

        $this->assertLessThanOrEqual(63, strlen($result));
        $this->assertStringEndsNotWith('_', $result);
    }
}
