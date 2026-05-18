<?php

declare(strict_types=1);

namespace App\Parser;

use App\Util\DiffUtil;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

readonly class DockerComposeParser
{
    public function __construct(
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \RuntimeException
     */
    public function parse(string $path): array
    {
        if (! $this->filesystem->exists($path)) {
            throw new \RuntimeException(sprintf('docker-compose file not found: "%s"', $path));
        }

        try {
            $data = Yaml::parseFile($path, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE | Yaml::PARSE_CUSTOM_TAGS);
        } catch (ParseException $parseException) {
            throw new \RuntimeException(sprintf('Invalid YAML in "%s": %s', $path, $parseException->getMessage()), $parseException->getCode(), previous: $parseException);
        }

        if (! is_array($data)) {
            throw new \RuntimeException(sprintf('docker-compose file "%s" must contain a YAML mapping', $path));
        }

        return $data;
    }

    /**
     * Extracts all unique domains from Traefik `Host()` labels in one or more
     * docker-compose files.
     *
     * Pass a list of paths to walk a base + override stack — labels are
     * collected from every file and de-duplicated, mirroring how Compose
     * itself merges labels across `-f` arguments.
     *
     * Returns an empty list when no file exists or none contain Traefik host
     * rules. Parses both map- and list-style label definitions, and tolerates
     * `!override` / `!reset` tags on the labels value (only the inner list is
     * scanned — Compose semantics are not re-implemented).
     *
     * When $onlyServices is provided, only labels from those service names are
     * considered — useful for filtering by actually running containers (e.g.
     * when profiles exclude some services).
     *
     * @param string|list<string> $paths        single file or ordered stack (base first)
     * @param list<string>|null   $onlyServices service names to include (null = all)
     *
     * @return list<string>
     */
    public function extractTraefikDomains(string|array $paths, ?array $onlyServices = null): array
    {
        $domains = [];

        foreach (is_string($paths) ? [$paths] : $paths as $path) {
            if (! $this->filesystem->exists($path)) {
                continue;
            }

            $config = $this->parse($path);

            if (! isset($config['services']) || ! is_array($config['services'])) {
                continue;
            }

            foreach ($config['services'] as $serviceName => $service) {
                if ($onlyServices !== null && ! in_array($serviceName, $onlyServices, true)) {
                    continue;
                }

                if (! is_array($service)) {
                    continue;
                }

                $labels = $service['labels'] ?? [];

                if ($labels instanceof TaggedValue) {
                    $labels = $labels->getValue();
                }

                if (! is_array($labels)) {
                    continue;
                }

                foreach ($labels as $key => $value) {
                    // List format: "traefik.http.routers.xxx.rule=Host(`example.test`)"
                    $label = is_int($key) ? (string) $value : $key.'='.$value;

                    if (preg_match_all('/Host\(([^)]+)\)/', $label, $hostMatches)) {
                        foreach ($hostMatches[1] as $hostContent) {
                            if (preg_match_all('/`([^`]+)`/', $hostContent, $domainMatches)) {
                                foreach ($domainMatches[1] as $domain) {
                                    $domains[] = $domain;
                                }
                            }
                        }
                    }
                }
            }
        }

        return array_values(array_unique($domains));
    }

    /**
     * @param array<string, mixed> $original
     * @param array<string, mixed> $modified
     */
    public function generateDiff(array $original, array $modified): string
    {
        $originalYaml = Yaml::dump($original, 10, 4, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
        $modifiedYaml = Yaml::dump($modified, 10, 4, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);

        if ($originalYaml === $modifiedYaml) {
            return '';
        }

        return DiffUtil::generateTextDiff(
            explode("\n", rtrim($originalYaml, "\n")),
            explode("\n", rtrim($modifiedYaml, "\n")),
        );
    }
}
