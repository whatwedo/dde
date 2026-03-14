<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use App\Util\DiffUtil;
use PHPUnit\Framework\TestCase;

final class DiffUtilTest extends TestCase
{
    public function testEmptyInputsReturnEmptyString(): void
    {
        $this->assertSame('', DiffUtil::generateTextDiff([], []));
    }

    public function testIdenticalLinesShowOnlyContext(): void
    {
        $lines = ['foo', 'bar'];
        $diff = DiffUtil::generateTextDiff($lines, $lines);

        $this->assertStringContainsString('  foo', $diff);
        $this->assertStringContainsString('  bar', $diff);
        $this->assertStringNotContainsString('- ', $diff);
        $this->assertStringNotContainsString('+ ', $diff);
    }

    public function testInsertedLinesShowPlusPrefix(): void
    {
        $diff = DiffUtil::generateTextDiff([], ['added']);

        $this->assertStringContainsString('+ added', $diff);
    }

    public function testDeletedLinesShowMinusPrefix(): void
    {
        $diff = DiffUtil::generateTextDiff(['removed'], []);

        $this->assertStringContainsString('- removed', $diff);
    }

    public function testReplacedLinesShowBothPrefixes(): void
    {
        $diff = DiffUtil::generateTextDiff(['old'], ['new']);

        $this->assertStringContainsString('- old', $diff);
        $this->assertStringContainsString('+ new', $diff);
    }

    public function testMixedChanges(): void
    {
        $original = ['a', 'b', 'c'];
        $modified = ['a', 'x', 'c', 'd'];

        $diff = DiffUtil::generateTextDiff($original, $modified);

        $this->assertStringContainsString('  a', $diff);
        $this->assertStringContainsString('- b', $diff);
        $this->assertStringContainsString('+ x', $diff);
        $this->assertStringContainsString('  c', $diff);
        $this->assertStringContainsString('+ d', $diff);
    }
}
