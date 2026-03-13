<?php

declare(strict_types=1);

namespace App\Parser;

use App\Util\DiffUtil;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Exception\ParseException;
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
            $data = Yaml::parseFile($path, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $parseException) {
            throw new \RuntimeException(sprintf('Invalid YAML in "%s": %s', $path, $parseException->getMessage()), $parseException->getCode(), previous: $parseException);
        }

        if (! is_array($data)) {
            throw new \RuntimeException(sprintf('docker-compose file "%s" must contain a YAML mapping', $path));
        }

        return $data;
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
