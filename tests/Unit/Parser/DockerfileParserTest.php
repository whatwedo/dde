<?php

declare(strict_types=1);

namespace Tests\Unit\Parser;

use App\Parser\DockerfileParser;
use PHPUnit\Framework\TestCase;

final class DockerfileParserTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $tempFiles = [];

    private DockerfileParser $parser;

    public function testParseValidFile(): void
    {
        $content = <<<'DOCKERFILE'
            FROM php:8.3-fpm
            RUN apt-get update
            COPY . /app
            DOCKERFILE;

        $path = $this->createTempFile($content);
        $lines = $this->parser->parse($path);

        $this->assertCount(3, $lines);
        $this->assertStringContainsString('FROM php:8.3-fpm', $lines[0]);
    }

    public function testParseNonExistentFileThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Dockerfile not found/');

        $this->parser->parse('/nonexistent/Dockerfile');
    }

    public function testFindV1BoilerplateFindsAllPatterns(): void
    {
        $lines = [
            'FROM php:8.3-fpm AS base',
            'RUN apt-get update',
            'FROM base AS dev',
            'COPY .dde/configure-image.sh /tmp/dde-configure-image.sh',
            'ARG DDE_UID=1000',
            'ARG DDE_GID=1000',
            'RUN /tmp/dde-configure-image.sh',
            'COPY . /app',
        ];

        $found = $this->parser->findV1Boilerplate($lines);

        $this->assertSame([3, 4, 5, 6], $found);
    }

    public function testFindV1BoilerplateMatchesSuffixedDevStage(): void
    {
        $lines = [
            'FROM php:8.3-fpm AS api_base',
            'RUN apt-get update',
            'FROM api_base AS api_dev',
            'COPY .dde/configure-image.sh /tmp/dde-configure-image.sh',
            'ARG DDE_UID',
            'ARG DDE_GID',
            'COPY . /app',
        ];

        $found = $this->parser->findV1Boilerplate($lines);

        $this->assertSame([3, 4, 5], $found);
    }

    public function testFindV1BoilerplateReturnsEmptyForCleanDockerfile(): void
    {
        $lines = [
            'FROM php:8.3-fpm',
            'RUN apt-get update',
            'COPY . /app',
        ];

        $found = $this->parser->findV1Boilerplate($lines);

        $this->assertSame([], $found);
    }

    public function testFindV1BoilerplateFindsBoilerplateInAnyStage(): void
    {
        $lines = [
            'FROM php:8.3-fpm AS base',
            'ARG DDE_UID',
            'COPY .dde/configure-image.sh /tmp/dde-configure-image.sh',
            'FROM base AS dev',
            'RUN echo "clean dev"',
            'FROM base AS prod',
            'COPY . /app',
        ];

        $found = $this->parser->findV1Boilerplate($lines);

        $this->assertSame([1, 2], $found);
    }

    public function testFindV1BoilerplateFindsConfigureImageRunInAnyStage(): void
    {
        $lines = [
            'FROM php:8.3-fpm AS base',
            'RUN /tmp/dde-configure-image.sh',
            'FROM base AS app_storybook',
            'RUN /tmp/dde-configure-image.sh',
            'FROM base AS dev',
            'RUN echo "clean dev"',
        ];

        $found = $this->parser->findV1Boilerplate($lines);

        $this->assertSame([1, 3], $found);
    }

    public function testFindV1BoilerplateFindsMultiStageBoilerplate(): void
    {
        $lines = [
            'FROM base AS api_dev',
            'COPY .dde/configure-image.sh /tmp/dde-configure-image.sh',
            'ARG DDE_UID',
            'ARG DDE_GID',
            'RUN /tmp/dde-configure-image.sh',
            'FROM base AS app_playwright',
            'COPY .dde/configure-image.sh /tmp/dde-configure-image.sh',
            'ARG DDE_UID',
            'ARG DDE_GID',
            'RUN /tmp/dde-configure-image.sh',
        ];

        $found = $this->parser->findV1Boilerplate($lines);

        $this->assertSame([1, 2, 3, 4, 6, 7, 8, 9], $found);
    }

    public function testRemoveLines(): void
    {
        $lines = ['line0', 'line1', 'line2', 'line3', 'line4'];

        $result = $this->parser->removeLines($lines, [1, 3]);

        $this->assertSame(['line0', 'line2', 'line4'], $result);
    }

    public function testRemoveLinesPreservesContinuationAsNewRun(): void
    {
        $lines = [
            'FROM base AS dev',
            '',
            'COPY .dde/configure-image.sh /tmp/dde-configure-image.sh',
            'ARG DDE_UID',
            'ARG DDE_GID',
            '',
            'RUN /tmp/dde-configure-image.sh && \\',
            '    echo "installing additional dev packages" && \\',
            '    apk add --no-cache make curl',
        ];

        $boilerplate = $this->parser->findV1Boilerplate($lines);
        $result = $this->parser->removeLines($lines, $boilerplate);

        $this->assertSame([
            'FROM base AS dev',
            '',
            '',
            'RUN echo "installing additional dev packages" && \\'."\n".'    apk add --no-cache make curl',
        ], $result);
    }

    public function testRemoveLinesHandlesBoilerplateInMiddleOfChain(): void
    {
        $lines = [
            'FROM base AS dev',
            'COPY .dde/configure-image.sh /tmp/dde-configure-image.sh',
            'ARG DDE_UID',
            'ARG DDE_GID',
            'RUN echo "setup" && \\',
            '    /tmp/dde-configure-image.sh && \\',
            '    echo "after" && \\',
            '    apk add --no-cache make',
        ];

        $boilerplate = $this->parser->findV1Boilerplate($lines);
        $result = $this->parser->removeLines($lines, $boilerplate);

        $this->assertSame([
            'FROM base AS dev',
            'RUN echo "setup" && \\'."\n".'    echo "after" && \\'."\n".'    apk add --no-cache make',
        ], $result);
    }

    public function testRemoveLinesHandlesStandaloneRunWithoutContinuation(): void
    {
        $lines = [
            'FROM base AS dev',
            'COPY .dde/configure-image.sh /tmp/dde-configure-image.sh',
            'ARG DDE_UID',
            'ARG DDE_GID',
            'RUN /tmp/dde-configure-image.sh',
            'COPY . /app',
        ];

        $boilerplate = $this->parser->findV1Boilerplate($lines);
        $result = $this->parser->removeLines($lines, $boilerplate);

        $this->assertSame([
            'FROM base AS dev',
            'COPY . /app',
        ], $result);
    }

    public function testRemoveLinesHandlesOnlyBoilerplateInRun(): void
    {
        $lines = [
            'FROM base AS dev',
            'COPY .dde/configure-image.sh /tmp/dde-configure-image.sh',
            'ARG DDE_UID',
            'ARG DDE_GID',
            'RUN /tmp/dde-configure-image.sh && \\',
            '    echo "only boilerplate call"',
        ];

        $boilerplate = $this->parser->findV1Boilerplate($lines);
        $result = $this->parser->removeLines($lines, $boilerplate);

        $this->assertSame([
            'FROM base AS dev',
            'RUN echo "only boilerplate call"',
        ], $result);
    }

    public function testMultiStageDockerfileOnlyModifiesDevStage(): void
    {
        $lines = [
            'FROM registry.example.com/base:v2 AS base',
            'ARG GIT_COMMIT_SHORT_SHA=UNKNOWN',
            'RUN apk add --no-cache openssl',
            'WORKDIR /var/www',
            '',
            'FROM base AS dev',
            '',
            'COPY .dde/configure-image.sh /tmp/dde-configure-image.sh',
            'ARG DDE_UID',
            'ARG DDE_GID',
            '',
            'RUN /tmp/dde-configure-image.sh && \\',
            '    echo "installing additional dev packages" && \\',
            '    apk add --no-cache \\',
            '    make \\',
            '    curl',
            '',
            'FROM base AS build_step_backend',
            'COPY . /var/www/',
            'RUN composer install --prefer-dist',
            '',
            'FROM base AS prod',
            'COPY --from=build_step_backend /var/www /var/www/',
        ];

        $boilerplate = $this->parser->findV1Boilerplate($lines);
        $result = $this->parser->removeLines($lines, $boilerplate);

        $this->assertSame([
            'FROM registry.example.com/base:v2 AS base',
            'ARG GIT_COMMIT_SHORT_SHA=UNKNOWN',
            'RUN apk add --no-cache openssl',
            'WORKDIR /var/www',
            '',
            'FROM base AS dev',
            '',
            '',
            'RUN echo "installing additional dev packages" && \\'."\n".'    apk add --no-cache make curl',
            '',
            'FROM base AS build_step_backend',
            'COPY . /var/www/',
            'RUN composer install --prefer-dist',
            '',
            'FROM base AS prod',
            'COPY --from=build_step_backend /var/www /var/www/',
        ], $result);
    }

    public function testWriteCreatesFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'dde_test_');
        $this->tempFiles[] = $path;

        $lines = ['FROM php:8.3-fpm', 'COPY . /app'];

        $this->parser->write($path, $lines);

        $this->assertFileExists($path);
        $content = file_get_contents($path);
        $this->assertIsString($content);
        $this->assertSame("FROM php:8.3-fpm\nCOPY . /app", $content);
    }

    public function testHasDevStageReturnsTrueWhenPresent(): void
    {
        $lines = [
            'FROM php:8.3-fpm AS base',
            'RUN apt-get update',
            'FROM base AS dev',
            'RUN install-dev-tools',
        ];

        $this->assertTrue($this->parser->hasDevStage($lines));
    }

    public function testHasDevStageReturnsFalseWhenAbsent(): void
    {
        $lines = [
            'FROM php:8.3-fpm AS base',
            'RUN apt-get update',
            'FROM base AS production',
        ];

        $this->assertFalse($this->parser->hasDevStage($lines));
    }

    public function testHasDevStageCaseInsensitive(): void
    {
        $lines = [
            'FROM php:8.3-fpm AS base',
            'FROM base AS Dev',
        ];

        $this->assertTrue($this->parser->hasDevStage($lines));
    }

    private function createTempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dde_test_');
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }

    protected function setUp(): void
    {
        $this->parser = new DockerfileParser();
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
