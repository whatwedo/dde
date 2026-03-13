<?php

declare(strict_types=1);

namespace App\Util;

final class DiffUtil
{
    private function __construct()
    {
    }

    /**
     * @param list<string> $originalLines
     * @param list<string> $modifiedLines
     */
    public static function generateTextDiff(array $originalLines, array $modifiedLines): string
    {
        $diff = '';
        $opcodes = self::computeOpcodes($originalLines, $modifiedLines);

        foreach ($opcodes as [$tag, $oldLines, $newLines]) {
            match ($tag) {
                'equal' => $diff .= implode('', array_map(static fn (string $l): string => sprintf('  %s%s', $l, PHP_EOL), $oldLines)),
                'delete' => $diff .= implode('', array_map(static fn (string $l): string => sprintf('- %s%s', $l, PHP_EOL), $oldLines)),
                'insert' => $diff .= implode('', array_map(static fn (string $l): string => sprintf('+ %s%s', $l, PHP_EOL), $newLines)),
                'replace' => $diff .= implode('', array_map(static fn (string $l): string => sprintf('- %s%s', $l, PHP_EOL), $oldLines))
                    .implode('', array_map(static fn (string $l): string => sprintf('+ %s%s', $l, PHP_EOL), $newLines)),
                default => null,
            };
        }

        return $diff;
    }

    /**
     * Compute edit opcodes between two arrays of lines using a simple
     * longest-common-subsequence algorithm.
     *
     * @param list<string> $old
     * @param list<string> $new
     *
     * @return list<array{0: string, 1: list<string>, 2: list<string>}>
     */
    private static function computeOpcodes(array $old, array $new): array
    {
        $oldLen = count($old);
        $newLen = count($new);

        // Build LCS length table
        /** @var array<int, array<int, int>> $lcs */
        $lcs = [];

        for ($i = 0; $i <= $oldLen; $i++) {
            $lcs[$i] = [];

            for ($j = 0; $j <= $newLen; $j++) {
                $lcs[$i][$j] = 0;
            }
        }

        for ($i = 1; $i <= $oldLen; $i++) {
            for ($j = 1; $j <= $newLen; $j++) {
                $lcs[$i][$j] = $old[$i - 1] === $new[$j - 1] ? $lcs[$i - 1][$j - 1] + 1 : max($lcs[$i - 1][$j], $lcs[$i][$j - 1]);
            }
        }

        // Backtrack to produce raw opcodes (tag + single line)
        /** @var list<array{0: string, 1: list<string>, 2: list<string>}> $raw */
        $raw = [];
        $i = $oldLen;
        $j = $newLen;

        while ($i > 0 || $j > 0) {
            if ($i > 0 && $j > 0 && $old[$i - 1] === $new[$j - 1]) {
                array_unshift($raw, ['equal', [$old[$i - 1]], [$new[$j - 1]]]);
                $i--;
                $j--;
            } elseif ($j > 0 && ($i === 0 || $lcs[$i][$j - 1] >= $lcs[$i - 1][$j])) {
                array_unshift($raw, ['insert', [], [$new[$j - 1]]]);
                $j--;
            } else {
                array_unshift($raw, ['delete', [$old[$i - 1]], []]);
                $i--;
            }
        }

        // Merge consecutive opcodes of the same type
        /** @var list<array{0: string, 1: list<string>, 2: list<string>}> $merged */
        $merged = [];

        foreach ($raw as $op) {
            $lastKey = array_key_last($merged);

            if ($lastKey !== null && $merged[$lastKey][0] === $op[0]) {
                /** @var list<string> $mergedOld */
                $mergedOld = array_merge($merged[$lastKey][1], $op[1]);
                /** @var list<string> $mergedNew */
                $mergedNew = array_merge($merged[$lastKey][2], $op[2]);
                $merged[$lastKey] = [$merged[$lastKey][0], $mergedOld, $mergedNew];
            } else {
                $merged[] = $op;
            }
        }

        return $merged;
    }
}
