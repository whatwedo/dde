<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Kernel;
use PHPUnit\Framework\TestCase;

final class KernelTest extends TestCase
{
    public function testKernelBoots(): void
    {
        $kernel = new Kernel('test', true);
        $kernel->boot();

        $this->assertNotNull($kernel->getContainer());
    }

    public function testGetCacheDirInNonPharMode(): void
    {
        $kernel = new Kernel('test', true);

        $cacheDir = $kernel->getCacheDir();

        // In non-PHAR mode, cache dir should follow Symfony default pattern
        $this->assertStringContainsString('test', $cacheDir);
        $this->assertStringContainsString('cache', $cacheDir);
    }

    public function testGetLogDirInNonPharMode(): void
    {
        $kernel = new Kernel('test', true);

        $logDir = $kernel->getLogDir();

        // In non-PHAR mode, log dir should follow Symfony default pattern
        $this->assertStringContainsString('log', $logDir);
        // Should NOT point to sys_get_temp_dir since we're not in a PHAR
        $this->assertStringNotContainsString('dde/log', $logDir);
    }

    public function testIsRunningAsPharReturnsFalseInTests(): void
    {
        $kernel = new Kernel('test', true);

        $reflection = new \ReflectionMethod($kernel, 'isRunningAsPhar');

        $this->assertFalse($reflection->invoke($kernel));
    }

    public function testGetCacheDirIncludesEnvironment(): void
    {
        $kernel = new Kernel('prod', false);

        $cacheDir = $kernel->getCacheDir();

        $this->assertStringContainsString('prod', $cacheDir);
    }

    public function testGetCacheDirDiffersPerEnvironment(): void
    {
        $kernelTest = new Kernel('test', true);
        $kernelProd = new Kernel('prod', false);

        $this->assertNotSame($kernelTest->getCacheDir(), $kernelProd->getCacheDir());
    }
}
