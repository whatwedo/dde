<?php

declare(strict_types=1);

namespace Tests\Unit\Manager;

use App\Manager\MkcertManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

final class MkcertManagerTest extends TestCase
{
    private string $tempDir;

    private MkcertManager $service;

    private Filesystem $filesystem;

    private MockClock $clock;

    public function testGetCertificatePathReturnsCorrectPath(): void
    {
        $this->assertSame(
            $this->tempDir.'/certs/myproject.pem',
            $this->service->getCertificatePath('myproject'),
        );
    }

    public function testGetKeyPathReturnsCorrectPath(): void
    {
        $this->assertSame(
            $this->tempDir.'/certs/myproject-key.pem',
            $this->service->getKeyPath('myproject'),
        );
    }

    public function testLoadRegistryReturnsEmptyArrayWhenFileDoesNotExist(): void
    {
        $this->assertSame([], $this->service->loadRegistry());
    }

    public function testLoadRegistryReturnsEmptyArrayForEmptyFile(): void
    {
        $this->filesystem->mkdir($this->tempDir.'/certs');
        $this->filesystem->dumpFile($this->tempDir.'/certs/registry.json', '');

        $this->assertSame([], $this->service->loadRegistry());
    }

    public function testLoadRegistryReturnsEmptyArrayForInvalidJson(): void
    {
        $this->filesystem->mkdir($this->tempDir.'/certs');
        $this->filesystem->dumpFile($this->tempDir.'/certs/registry.json', 'not json');

        $this->assertSame([], $this->service->loadRegistry());
    }

    public function testLoadRegistryReturnsDataForValidJson(): void
    {
        $data = [
            'myproject' => [
                'domains' => ['myproject.test', '*.myproject.test'],
                'created' => '2026-03-15',
            ],
        ];

        $this->filesystem->mkdir($this->tempDir.'/certs');
        $this->filesystem->dumpFile(
            $this->tempDir.'/certs/registry.json',
            json_encode($data, JSON_THROW_ON_ERROR),
        );

        $this->assertSame($data, $this->service->loadRegistry());
    }

    public function testSaveRegistryWritesJsonFile(): void
    {
        $data = [
            'myproject' => [
                'domains' => ['myproject.test'],
                'created' => '2026-03-15',
            ],
        ];

        $this->service->saveRegistry($data);

        $registryPath = $this->tempDir.'/certs/registry.json';
        $this->assertFileExists($registryPath);

        $content = file_get_contents($registryPath);
        $this->assertIsString($content);

        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($data, $decoded);
    }

    public function testUpdateRegistryAddsNewEntry(): void
    {
        $this->service->updateRegistry('myproject', ['myproject.test', '*.myproject.test']);

        $registry = $this->service->loadRegistry();

        $this->assertArrayHasKey('myproject', $registry);
        $this->assertSame(['myproject.test', '*.myproject.test'], $registry['myproject']['domains']);
        $this->assertSame('2026-03-21', $registry['myproject']['created']);
    }

    public function testUpdateRegistryOverwritesExistingEntry(): void
    {
        $this->service->updateRegistry('myproject', ['old.test']);
        $this->service->updateRegistry('myproject', ['new.test']);

        $registry = $this->service->loadRegistry();

        $this->assertSame(['new.test'], $registry['myproject']['domains']);
    }

    public function testUpdateRegistryPreservesOtherEntries(): void
    {
        $this->service->updateRegistry('project1', ['p1.test']);
        $this->service->updateRegistry('project2', ['p2.test']);

        $registry = $this->service->loadRegistry();

        $this->assertArrayHasKey('project1', $registry);
        $this->assertArrayHasKey('project2', $registry);
    }

    public function testUpdateTraefikDynamicConfigRemovesFileWhenRegistryEmpty(): void
    {
        $dynamicConfigPath = $this->tempDir.'/traefik/dynamic/tls.yml';
        $this->filesystem->mkdir(dirname($dynamicConfigPath));
        $this->filesystem->dumpFile($dynamicConfigPath, "tls:\n  certificates: []\n");

        $this->service->updateTraefikDynamicConfig();

        $this->assertFileDoesNotExist($dynamicConfigPath);
    }

    public function testUpdateTraefikDynamicConfigWritesConfigForExistingCerts(): void
    {
        $this->service->updateRegistry('myproject', ['myproject.test']);

        // Create the actual cert file so it gets picked up
        $this->filesystem->dumpFile($this->tempDir.'/certs/myproject.pem', 'cert-content');

        $this->service->updateTraefikDynamicConfig();

        $dynamicConfigPath = $this->tempDir.'/traefik/dynamic/tls.yml';
        $this->assertFileExists($dynamicConfigPath);

        $content = file_get_contents($dynamicConfigPath);
        $this->assertIsString($content);
        $this->assertStringContainsString('tls:', $content);
        $this->assertStringContainsString('certificates:', $content);
        $this->assertStringContainsString('/certs/myproject.pem', $content);
        $this->assertStringContainsString('/certs/myproject-key.pem', $content);
    }

    public function testUpdateTraefikDynamicConfigSkipsMissingCertFiles(): void
    {
        $this->service->updateRegistry('missing', ['missing.test']);

        // Do NOT create the cert file

        $this->service->updateTraefikDynamicConfig();

        $dynamicConfigPath = $this->tempDir.'/traefik/dynamic/tls.yml';
        $this->assertFileDoesNotExist($dynamicConfigPath);
    }

    public function testUpdateTraefikDynamicConfigCreatesDirectoryStructure(): void
    {
        $this->service->updateRegistry('myproject', ['myproject.test']);
        $this->filesystem->dumpFile($this->tempDir.'/certs/myproject.pem', 'cert-content');

        $this->service->updateTraefikDynamicConfig();

        $this->assertDirectoryExists($this->tempDir.'/traefik/dynamic');
    }

    public function testExtractDomainsFromComposeFileReturnsEmptyForMissingFile(): void
    {
        $domains = $this->service->extractDomainsFromComposeFile($this->tempDir.'/missing.yml');

        $this->assertSame([], $domains);
    }

    public function testExtractDomainsFromComposeFileExtractsHostLabels(): void
    {
        $composeContent = <<<'YAML'
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

        $composeFile = $this->tempDir.'/docker-compose.yml';
        $this->filesystem->dumpFile($composeFile, $composeContent);

        $domains = $this->service->extractDomainsFromComposeFile($composeFile);

        $this->assertSame(['app.test', 'api.test'], $domains);
    }

    public function testExtractDomainsFromComposeFileDeduplicates(): void
    {
        $composeContent = <<<'YAML'
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

        $composeFile = $this->tempDir.'/docker-compose.yml';
        $this->filesystem->dumpFile($composeFile, $composeContent);

        $domains = $this->service->extractDomainsFromComposeFile($composeFile);

        $this->assertSame(['app.test'], $domains);
    }

    public function testExtractDomainsFromComposeFileHandlesMultipleHostsInOneRule(): void
    {
        $composeContent = <<<'YAML'
services:
  web:
    image: nginx
    labels:
      - "traefik.http.routers.web.rule=Host(`app.test`) || Host(`www.app.test`)"
YAML;

        $composeFile = $this->tempDir.'/docker-compose.yml';
        $this->filesystem->dumpFile($composeFile, $composeContent);

        $domains = $this->service->extractDomainsFromComposeFile($composeFile);

        $this->assertSame(['app.test', 'www.app.test'], $domains);
    }

    public function testExtractDomainsFromComposeFileHandlesTraefikV2CommaSyntax(): void
    {
        $composeContent = <<<'YAML'
services:
  web:
    image: nginx
    labels:
      - "traefik.http.routers.web.rule=Host(`app.test`,`www.app.test`)"
YAML;

        $composeFile = $this->tempDir.'/docker-compose.yml';
        $this->filesystem->dumpFile($composeFile, $composeContent);

        $domains = $this->service->extractDomainsFromComposeFile($composeFile);

        $this->assertSame(['app.test', 'www.app.test'], $domains);
    }

    public function testExtractDomainsFromComposeFileHandlesWhitespace(): void
    {
        $composeContent = <<<'YAML'
services:
  web:
    image: nginx
    labels:
      - "traefik.http.routers.web.rule=Host( `app.test` )"
YAML;

        $composeFile = $this->tempDir.'/docker-compose.yml';
        $this->filesystem->dumpFile($composeFile, $composeContent);

        $domains = $this->service->extractDomainsFromComposeFile($composeFile);

        $this->assertSame(['app.test'], $domains);
    }

    public function testExtractDomainsFromComposeFileHandlesHostAndPathPrefix(): void
    {
        $composeContent = <<<'YAML'
services:
  web:
    image: nginx
    labels:
      - "traefik.http.routers.web.rule=Host(`app.test`) && PathPrefix(`/api`)"
YAML;

        $composeFile = $this->tempDir.'/docker-compose.yml';
        $this->filesystem->dumpFile($composeFile, $composeContent);

        $domains = $this->service->extractDomainsFromComposeFile($composeFile);

        $this->assertSame(['app.test'], $domains);
    }

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->clock = new MockClock('2026-03-21');
        $this->tempDir = sys_get_temp_dir().'/dde-test-'.bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o777, true);

        $this->service = new MkcertManager(
            filesystem: $this->filesystem,
            dataDir: $this->tempDir,
            clock: $this->clock,
        );
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->tempDir);
    }
}
