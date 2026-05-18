<?php

declare(strict_types=1);

namespace Tests\Unit\Parser;

use App\Parser\DockerComposeParser;
use PHPUnit\Framework\TestCase;

final class DockerComposeParserTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $tempFiles = [];

    private DockerComposeParser $parser;

    public function testParseValidFile(): void
    {
        $yaml = <<<'YAML'
            services:
              web:
                image: nginx
            YAML;

        $path = $this->createTempFile($yaml);
        $result = $this->parser->parse($path);

        $this->assertArrayHasKey('services', $result);
        $this->assertArrayHasKey('web', $result['services']);
    }

    public function testParseNonExistentFileThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/docker-compose file not found/');

        $this->parser->parse('/nonexistent/docker-compose.yml');
    }

    public function testParseInvalidYamlThrowsException(): void
    {
        $path = $this->createTempFile("invalid: yaml: :\n  - broken\n\t- mixed");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Invalid YAML/');

        $this->parser->parse($path);
    }

    public function testParseNonMappingThrowsException(): void
    {
        $path = $this->createTempFile('just a string');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/must contain a YAML mapping/');

        $this->parser->parse($path);
    }

    public function testParseToleratesCustomYamlTags(): void
    {
        $yaml = <<<'YAML'
            services:
              web:
                image: nginx
                environment: !reset {}
                labels: !override
                  - "traefik.enable=true"
            YAML;

        $path = $this->createTempFile($yaml);

        // Must not throw — `!reset` and `!override` are legitimate Compose tags
        // that the parser has to surface untouched so callers see the original
        // YAML structure (and Compose itself applies the semantics at runtime).
        $parsed = $this->parser->parse($path);
        $this->assertArrayHasKey('services', $parsed);
        $this->assertArrayHasKey('web', $parsed['services']);
    }

    public function testGenerateDiffReturnsEmptyForIdenticalConfigs(): void
    {
        $config = [
            'services' => [
                'web' => [
                    'image' => 'nginx',
                ],
            ],
        ];

        $diff = $this->parser->generateDiff($config, $config);

        $this->assertSame('', $diff);
    }

    public function testGenerateDiffShowsChanges(): void
    {
        $original = [
            'services' => [
                'web' => [
                    'image' => 'nginx',
                ],
            ],
        ];
        $modified = [
            'services' => [
                'web' => [
                    'image' => 'nginx',
                ],
            ],
            'networks' => [
                'default' => [
                    'name' => 'dde',
                    'external' => true,
                ],
            ],
        ];

        $diff = $this->parser->generateDiff($original, $modified);

        $this->assertNotSame('', $diff);
        $this->assertStringContainsString('+', $diff);
    }

    public function testGenerateDiffShowsContextLines(): void
    {
        $original = [
            'services' => [
                'web' => [
                    'image' => 'nginx',
                ],
            ],
        ];
        $modified = [
            'services' => [
                'web' => [
                    'image' => 'apache',
                ],
            ],
        ];

        $diff = $this->parser->generateDiff($original, $modified);

        // Should show unchanged lines with "  " prefix and changed lines with -/+
        $this->assertStringContainsString('  services:', $diff);
        $this->assertStringContainsString('- ', $diff);
        $this->assertStringContainsString('+ ', $diff);
        $this->assertStringContainsString('nginx', $diff);
        $this->assertStringContainsString('apache', $diff);
    }

    public function testExtractTraefikDomainsReturnsEmptyForMissingFile(): void
    {
        $this->assertSame([], $this->parser->extractTraefikDomains('/nonexistent/docker-compose.yml'));
    }

    public function testExtractTraefikDomainsExtractsHostLabels(): void
    {
        $yaml = <<<'YAML'
            services:
              web:
                image: nginx
                labels:
                  - "traefik.http.routers.web.rule=Host(`app.test`)"
              api:
                image: php
                labels:
                  traefik.http.routers.api.rule: "Host(`api.test`)"
            YAML;

        $path = $this->createTempFile($yaml);

        $this->assertSame(['app.test', 'api.test'], $this->parser->extractTraefikDomains($path));
    }

    public function testExtractTraefikDomainsDeduplicates(): void
    {
        $yaml = <<<'YAML'
            services:
              web:
                image: nginx
                labels:
                  - "traefik.http.routers.web.rule=Host(`app.test`)"
              api:
                image: php
                labels:
                  - "traefik.http.routers.api.rule=Host(`app.test`)"
            YAML;

        $path = $this->createTempFile($yaml);

        $this->assertSame(['app.test'], $this->parser->extractTraefikDomains($path));
    }

    public function testExtractTraefikDomainsHandlesMultipleHostsInOneRule(): void
    {
        $yaml = <<<'YAML'
            services:
              web:
                image: nginx
                labels:
                  - "traefik.http.routers.web.rule=Host(`app.test`) || Host(`www.app.test`)"
            YAML;

        $path = $this->createTempFile($yaml);

        $this->assertSame(['app.test', 'www.app.test'], $this->parser->extractTraefikDomains($path));
    }

    public function testExtractTraefikDomainsHandlesTraefikV2CommaSyntax(): void
    {
        $yaml = <<<'YAML'
            services:
              web:
                image: nginx
                labels:
                  - "traefik.http.routers.web.rule=Host(`app.test`,`www.app.test`)"
            YAML;

        $path = $this->createTempFile($yaml);

        $this->assertSame(['app.test', 'www.app.test'], $this->parser->extractTraefikDomains($path));
    }

    public function testExtractTraefikDomainsHandlesWhitespace(): void
    {
        $yaml = <<<'YAML'
            services:
              web:
                image: nginx
                labels:
                  - "traefik.http.routers.web.rule=Host( `app.test` )"
            YAML;

        $path = $this->createTempFile($yaml);

        $this->assertSame(['app.test'], $this->parser->extractTraefikDomains($path));
    }

    public function testExtractTraefikDomainsHandlesHostAndPathPrefix(): void
    {
        $yaml = <<<'YAML'
            services:
              web:
                image: nginx
                labels:
                  - "traefik.http.routers.web.rule=Host(`app.test`) && PathPrefix(`/api`)"
            YAML;

        $path = $this->createTempFile($yaml);

        $this->assertSame(['app.test'], $this->parser->extractTraefikDomains($path));
    }

    public function testExtractTraefikDomainsFiltersOnlySpecifiedServices(): void
    {
        $yaml = <<<'YAML'
            services:
              web:
                image: nginx
                labels:
                  - "traefik.http.routers.web.rule=Host(`app.test`)"
              api:
                image: php
                labels:
                  traefik.http.routers.api.rule: "Host(`api.test`)"
              worker:
                image: php
                labels:
                  - "traefik.http.routers.worker.rule=Host(`worker.test`)"
            YAML;

        $path = $this->createTempFile($yaml);

        $this->assertSame(
            ['app.test', 'worker.test'],
            $this->parser->extractTraefikDomains($path, ['web', 'worker']),
        );
    }

    public function testExtractTraefikDomainsReturnsAllWhenFilterIsNull(): void
    {
        $yaml = <<<'YAML'
            services:
              web:
                image: nginx
                labels:
                  - "traefik.http.routers.web.rule=Host(`app.test`)"
              api:
                image: php
                labels:
                  traefik.http.routers.api.rule: "Host(`api.test`)"
            YAML;

        $path = $this->createTempFile($yaml);

        $this->assertSame(
            ['app.test', 'api.test'],
            $this->parser->extractTraefikDomains($path),
        );
    }

    public function testGenerateDiffPreservesLineOrder(): void
    {
        $original = [
            'services' => [
                'web' => [
                    'image' => 'nginx',
                    'environment' => [
                        'FOO=bar',
                    ],
                ],
            ],
        ];
        $modified = [
            'services' => [
                'web' => [
                    'image' => 'nginx',
                    'environment' => [
                        'FOO=bar',
                        'BAZ=qux',
                    ],
                ],
            ],
        ];

        $diff = $this->parser->generateDiff($original, $modified);

        // The added line should appear as "+"
        $this->assertStringContainsString('+ ', $diff);
        $this->assertStringContainsString('BAZ=qux', $diff);
        // The unchanged lines should appear with "  " prefix
        $this->assertStringContainsString('  services:', $diff);
    }

    private function createTempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dde_test_').'.yml';
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }

    protected function setUp(): void
    {
        $this->parser = new DockerComposeParser();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        $this->tempFiles = [];
    }
}
