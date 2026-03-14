<?php

declare(strict_types=1);

namespace Tests\E2E;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[Group('e2e')]
final class AboutCommandTest extends TestCase
{
    use E2ETestHelper;

    public function testAboutTextOutput(): void
    {
        $process = $this->runConsole('about');
        $this->assertTrue($process->isSuccessful(), 'about should succeed');
        $this->assertStringContainsString('dde', $process->getOutput());
    }

    public function testAboutJsonOutput(): void
    {
        $result = $this->runConsoleJson('about');
        $this->assertSame('ok', $result['status']);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('version', $result['data']);
        $this->assertArrayHasKey('php', $result['data']);
        $this->assertArrayHasKey('symfony', $result['data']);
        $this->assertArrayHasKey('config_dir', $result['data']);
        $this->assertArrayHasKey('data_dir', $result['data']);
    }

    protected function setUp(): void
    {
        $this->consolePath = dirname(__DIR__, 2).'/bin/console';
        $this->projectDir = sys_get_temp_dir();
        $this->tempDataDir = sys_get_temp_dir().'/dde-e2e-about-'.bin2hex(random_bytes(8));
        (new Filesystem())->mkdir($this->tempDataDir);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tempDataDir);
    }
}
