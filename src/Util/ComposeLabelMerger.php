<?php

declare(strict_types=1);

namespace App\Util;

use Symfony\Component\Yaml\Tag\TaggedValue;

/**
 * Merges a Compose service's `labels:` value across a base + override stack
 * using Compose's own override semantics, so dde callers (worktree label
 * rewriter, Traefik domain extractor) see the same effective label set
 * Compose itself would assemble when both files are passed via `-f`.
 *
 * Compose label merge rules:
 *
 *   - List-form labels: the override is appended to the base. Duplicate
 *     entries are not de-duplicated by Compose either — last write wins
 *     at runtime when Docker normalises the label set.
 *   - Map-form labels: per-key merge, override wins. Equivalent to
 *     `array_merge(base, override)`.
 *   - `!override` / `!reset` tagged values: the base is replaced
 *     entirely with the tagged value's content (`!reset` typically
 *     carries `[]` or `{}` to clear the base, `!override` carries the
 *     replacement set).
 *   - Mixed forms (one list, one map) are coerced to list-form before
 *     concatenating — Compose itself rejects the mix, but defensively
 *     producing a flat list keeps downstream consumers (regex-based
 *     label scanners) working.
 */
final class ComposeLabelMerger
{
    /**
     * @param array<int|string, mixed> $base
     *
     * @return array<int|string, mixed>
     */
    public static function merge(array $base, mixed $override): array
    {
        if ($override === null) {
            return $base;
        }

        if ($override instanceof TaggedValue) {
            $tag = $override->getTag();
            $value = $override->getValue();

            if ($tag === 'override' || $tag === 'reset') {
                return is_array($value) ? $value : [];
            }

            // Unknown tag — fall through with the unwrapped value so
            // standard merge logic applies.
            $override = $value;
        }

        if (! is_array($override)) {
            return $base;
        }

        if ($override === []) {
            return $base;
        }

        $baseIsList = array_is_list($base);
        $overrideIsList = array_is_list($override);

        if ($baseIsList && $overrideIsList) {
            return array_merge($base, $override);
        }

        if (! $baseIsList && ! $overrideIsList) {
            return array_merge($base, $override);
        }

        return array_merge(self::toListForm($base), self::toListForm($override));
    }

    /**
     * @param array<int|string, mixed> $labels
     *
     * @return list<string>
     */
    private static function toListForm(array $labels): array
    {
        $list = [];

        foreach ($labels as $key => $value) {
            if (is_int($key)) {
                $list[] = is_scalar($value) ? (string) $value : '';
            } else {
                $list[] = $key.'='.(is_scalar($value) ? (string) $value : '');
            }
        }

        return $list;
    }
}
