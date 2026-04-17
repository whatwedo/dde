<?php

declare(strict_types=1);

namespace App\Util;

use Symfony\Component\String\Slugger\AsciiSlugger;

final class IdentifierSanitizer
{
    private const int DNS_LABEL_MAX = 63;

    private const int DB_IDENTIFIER_MAX = 63;

    public static function forHostname(string $dirName, string $projectName): string
    {
        $suffix = self::stripProjectPrefix($dirName, $projectName, '-');
        $suffix = self::slugify($suffix, '-');

        if ($suffix === '') {
            $suffix = 'worktree';
        }

        if (strlen($suffix) > self::DNS_LABEL_MAX) {
            $suffix = rtrim(substr($suffix, 0, self::DNS_LABEL_MAX), '-');
        }

        return $suffix;
    }

    public static function forDatabase(string $name): string
    {
        $leadingUnderscore = str_starts_with($name, '_');

        $result = self::slugify($name, '_');

        if ($leadingUnderscore && $result !== '' && ! str_starts_with($result, '_')) {
            $result = '_'.$result;
        }

        if ($result === '') {
            return 'project';
        }

        if (ctype_digit($result[0])) {
            $result = 'db_'.$result;
        }

        if (strlen($result) > self::DB_IDENTIFIER_MAX) {
            $result = rtrim(substr($result, 0, self::DB_IDENTIFIER_MAX), '_');
        }

        return $result;
    }

    public static function forDatabaseSuffix(string $dirName, string $projectName): string
    {
        $suffix = self::stripProjectPrefix($dirName, $projectName, '-');
        $suffix = self::slugify($suffix, '_');

        if ($suffix === '') {
            $suffix = 'worktree';
        }

        if (strlen($suffix) > self::DB_IDENTIFIER_MAX) {
            $suffix = rtrim(substr($suffix, 0, self::DB_IDENTIFIER_MAX), '_');
        }

        return $suffix;
    }

    private static function stripProjectPrefix(string $input, string $projectName, string $separator): string
    {
        $prefix = strtolower($projectName).$separator;

        if (str_starts_with(strtolower($input), $prefix)) {
            return substr($input, strlen($projectName) + 1);
        }

        if (strcasecmp($input, $projectName) === 0) {
            return '';
        }

        return $input;
    }

    private static function slugify(string $input, string $separator): string
    {
        $slugger = new AsciiSlugger();
        $slug = (string) $slugger->slug($input, $separator)->lower();

        $pattern = $separator === '-' ? '/[^a-z0-9-]/' : '/[^a-z0-9_]/';
        $slug = (string) preg_replace($pattern, $separator, $slug);

        $collapse = $separator === '-' ? '/-{2,}/' : '/_{2,}/';
        $slug = (string) preg_replace($collapse, $separator, $slug);

        return trim($slug, $separator);
    }
}
