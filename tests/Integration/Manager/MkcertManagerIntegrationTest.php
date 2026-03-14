<?php

declare(strict_types=1);

namespace App\Tests\Integration\Manager;

use App\Manager\MkcertManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Filesystem\Filesystem;

final class MkcertManagerIntegrationTest extends TestCase
{
    private string $tempDir;

    private Filesystem $filesystem;

    public function testExtractDomainsFromSingleHostLabel(): void
    {
        $composeFile = $this->writeComposeFile('single-host.yml', <<<'YAML'
services:
  web:
    image: nginx
    labels:
      - 'traefik.http.routers.web.rule=Host(`example.test`)'
YAML);

        $manager = $this->buildMkcertManager();

        self::assertSame(['example.test'], $manager->extractDomainsFromComposeFile($composeFile));
    }

    public function testExtractDomainsFromMultipleServicesAndHosts(): void
    {
        $composeFile = $this->writeComposeFile('multi-service.yml', <<<'YAML'
services:
  web:
    image: nginx
    labels:
      - 'traefik.http.routers.web.rule=Host(`app.test`)'
  api:
    image: php-fpm
    labels:
      - 'traefik.http.routers.api.rule=Host(`api.test`)'
YAML);

        $manager = $this->buildMkcertManager();
        $domains = $manager->extractDomainsFromComposeFile($composeFile);

        self::assertContains('app.test', $domains);
        self::assertContains('api.test', $domains);
        self::assertCount(2, $domains);
    }

    public function testExtractDomainsFromMultiHostRule(): void
    {
        $composeFile = $this->writeComposeFile('multi-host-rule.yml', <<<'YAML'
services:
  web:
    image: nginx
    labels:
      - 'traefik.http.routers.web.rule=Host(`a.test`) || Host(`b.test`)'
YAML);

        $manager = $this->buildMkcertManager();
        $domains = $manager->extractDomainsFromComposeFile($composeFile);

        self::assertSame(['a.test', 'b.test'], $domains);
    }

    public function testExtractDomainsDeduplicates(): void
    {
        $composeFile = $this->writeComposeFile('dedup.yml', <<<'YAML'
services:
  web:
    image: nginx
    labels:
      - 'traefik.http.routers.web.rule=Host(`shared.test`)'
  api:
    image: php-fpm
    labels:
      - 'traefik.http.routers.api.rule=Host(`shared.test`)'
YAML);

        $manager = $this->buildMkcertManager();

        self::assertSame(['shared.test'], $manager->extractDomainsFromComposeFile($composeFile));
    }

    public function testExtractDomainsFromNonExistentFile(): void
    {
        $manager = $this->buildMkcertManager();

        self::assertSame([], $manager->extractDomainsFromComposeFile($this->tempDir.'/does-not-exist.yml'));
    }

    public function testExtractDomainsFromComposeWithoutLabels(): void
    {
        $composeFile = $this->writeComposeFile('no-labels.yml', <<<'YAML'
services:
  web:
    image: nginx
YAML);

        $manager = $this->buildMkcertManager();

        self::assertSame([], $manager->extractDomainsFromComposeFile($composeFile));
    }

    public function testMkcertRegistryRoundTrip(): void
    {
        $mkcertManager = new MkcertManager($this->filesystem, $this->tempDir, new NativeClock());

        $mkcertManager->updateRegistry('myproject', ['a.test', 'b.test']);

        $registry = $mkcertManager->loadRegistry();

        self::assertArrayHasKey('myproject', $registry);
        self::assertSame(['a.test', 'b.test'], $registry['myproject']['domains']);
        self::assertSame(date('Y-m-d'), $registry['myproject']['created']);
    }

    public function testMkcertUpdateTraefikDynamicConfig(): void
    {
        $mkcertManager = new MkcertManager($this->filesystem, $this->tempDir, new NativeClock());

        $mkcertManager->updateRegistry('myproject', ['myproject.test']);

        // Create fake cert .pem file so it gets picked up
        $certsDir = $this->tempDir.'/certs';
        $this->filesystem->mkdir($certsDir);
        $this->filesystem->dumpFile($certsDir.'/myproject.pem', 'fake-cert-content');

        $mkcertManager->updateTraefikDynamicConfig();

        $dynamicConfigPath = $this->tempDir.'/traefik/dynamic/tls.yml';
        self::assertFileExists($dynamicConfigPath);

        $content = (string) file_get_contents($dynamicConfigPath);
        self::assertStringContainsString('tls:', $content);
        self::assertStringContainsString('certificates:', $content);
        self::assertStringContainsString('/certs/myproject.pem', $content);
        self::assertStringContainsString('/certs/myproject-key.pem', $content);
    }

    private function writeComposeFile(string $filename, string $content): string
    {
        $path = $this->tempDir.'/'.$filename;
        $this->filesystem->dumpFile($path, $content);

        return $path;
    }

    private function buildMkcertManager(): MkcertManager
    {
        return new MkcertManager($this->filesystem, $this->tempDir, new NativeClock());
    }

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->tempDir = sys_get_temp_dir().'/dde_cert_integration_'.bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->tempDir);
    }
}
