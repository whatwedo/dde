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
