<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Parses one entry of a Docker Compose `environment:` block.
 *
 * Compose accepts two YAML forms inside `environment:`:
 *   - list:  - APP_URL=https://example.com
 *   - map:   APP_URL: https://example.com
 *
 * After Symfony YAML parsing, list entries arrive with int keys and
 * `KEY=VALUE` strings; map entries arrive with string keys and string
 * values. This helper normalises both into a `[KEY, VALUE]` tuple so
 * callers (worktree env rewriting, env_file merging) can treat both
 * shapes uniformly. Anything that isn't a parseable entry returns null.
 */
final class ComposeEnvEntryParser
{
    /**
     * @return array{0: string, 1: string}|null
     */
    public static function extract(int|string $key, mixed $value): ?array
    {
        if (is_int($key)) {
            if (! is_string($value)) {
                return null;
            }

            $eq = strpos($value, '=');

            return $eq === false ? null : [substr($value, 0, $eq), substr($value, $eq + 1)];
        }

        return is_string($value) ? [$key, $value] : null;
    }
}
