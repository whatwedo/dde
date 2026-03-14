<?php

declare(strict_types=1);

namespace Tests\E2E;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[Group('e2e')]
final class ErrorHandlingTest extends TestCase
{
    use E2ETestHelper;

    public function testProjectUpOutsideProject(): void
    {
        $process = $this->runConsole('project:up', ['--output=json']);
        $json = json_decode($process->getOutput(), true);
        $this->assertSame('error', $json['status'] ?? null, 'project:up outside project should fail');
    }

    public function testProjectStatusOutsideProject(): void
    {
        $process = $this->runConsole('project:status', ['--output=json']);
        $json = json_decode($process->getOutput(), true);
        $this->assertSame('error', $json['status'] ?? null);
    }

    public function testProjectExecOutsideProject(): void
    {
        $process = $this->runConsole('project:exec', ['whoami']);
        $this->assertFalse($process->isSuccessful());
    }

    public function testProjectDescribeOutsideProject(): void
    {
        $process = $this->runConsole('project:describe', ['--output=json']);
        $json = json_decode($process->getOutput(), true);
        $this->assertSame('error', $json['status'] ?? null);
    }

    public function testProjectLogsOutsideProject(): void
    {
        $process = $this->runConsole('project:logs', ['--no-follow']);
        $this->assertFalse($process->isSuccessful());
    }

    public function testDbExportOutsideProject(): void
    {
        $process = $this->runConsole('project:db:export', ['--output=json', '/tmp/test.sql']);
        $json = json_decode($process->getOutput(), true);
        $this->assertSame('error', $json['status'] ?? null);
    }

    public function testServiceListOutsideProject(): void
    {
        $process = $this->runConsole('project:service:list', ['--output=json']);
        $json = json_decode($process->getOutput(), true);
        $this->assertSame('error', $json['status'] ?? null);
    }

    public function testProjectShellOutsideProject(): void
    {
        $process = $this->runConsole('project:shell');
        $this->assertFalse($process->isSuccessful());
    }

    protected function setUp(): void
    {
        $this->consolePath = dirname(__DIR__, 2).'/bin/console';
        $this->tempDataDir = sys_get_temp_dir().'/dde-e2e-err-'.bin2hex(random_bytes(8));
        (new Filesystem())->mkdir($this->tempDataDir);

        // Use a directory that is NOT a dde project
        $this->projectDir = sys_get_temp_dir().'/dde-e2e-noproj-'.bin2hex(random_bytes(8));
        (new Filesystem())->mkdir($this->projectDir);
    }

    protected function tearDown(): void
    {
        $fs = new Filesystem();
        $fs->remove($this->projectDir);
        $fs->remove($this->tempDataDir);
    }
}
