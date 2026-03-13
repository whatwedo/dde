<?php

declare(strict_types=1);

namespace App\Util;

final class NdJsonParser
{
    /**
     * @return list<array<string, mixed>>
     *
     * @throws \RuntimeException
     */
    public static function parse(string $output, string $context): array
    {
        $output = trim($output);

        if ($output === '') {
            return [];
        }

        $results = [];

        foreach (explode("\n", $output) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            try {
                /** @var array<string, mixed> $data */
                $data = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new \RuntimeException(sprintf('Failed to parse %s JSON: %s', $context, $e->getMessage()), $e->getCode(), $e);
            }

            $results[] = $data;
        }

        return $results;
    }
}
