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
 * `KEY=VALUE` strings (or bare `KEY` strings that inherit from the host
 * env); map entries arrive with string keys and *any* scalar value —
 * unquoted integers, floats, and booleans are parsed as their PHP types,
 * not as strings. This helper normalises both shapes:
 *
 * - `extract()` returns a `[KEY, VALUE]` tuple when both can be resolved.
 *   Scalar map values are cast to string (`true`/`false` for booleans),
 *   matching how Compose emits them to containers at runtime. Bare list
 *   entries (`- FOO`) and null map values return null because there is no
 *   value to emit.
 * - `extractKey()` returns just the KEY part for any well-formed entry,
 *   even bare list entries — used by callers that need to track which keys
 *   are declared inline so they can suppress conflicting env_file values
 *   per Compose runtime precedence.
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

            // No `=` → bare key (host pass-through), no value to emit.
            // `=` at position 0 → empty key, Compose rejects this.
            if ($eq === false || $eq === 0) {
                return null;
            }

            return [substr($value, 0, $eq), substr($value, $eq + 1)];
        }

        if (is_string($value)) {
            return [$key, $value];
        }

        if (is_bool($value)) {
            // Match Compose's runtime serialisation of YAML booleans.
            return [$key, $value ? 'true' : 'false'];
        }

        if (is_int($value) || is_float($value)) {
            return [$key, (string) $value];
        }

        // null (e.g. `KEY: ~`) means "inherit from host env"; we cannot emit
        // a concrete value, so callers should treat this like a bare key.
        return null;
    }

    /**
     * Returns the env-var name declared by a compose entry, even when no
     * value is resolvable. Useful for callers that need to respect inline
     * declarations as keys that suppress env_file overrides (per Compose
     * runtime precedence), regardless of whether the inline entry carries
     * a value.
     */
    public static function extractKey(int|string $key, mixed $value): ?string
    {
        if (is_string($key)) {
            return $key === '' ? null : $key;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        $eq = strpos($value, '=');
        $name = $eq === false ? $value : substr($value, 0, $eq);

        return $name === '' ? null : $name;
    }
}
