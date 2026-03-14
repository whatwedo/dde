<?php

declare(strict_types=1);

namespace Tests\E2E;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('e2e')]
final class ProjectStopUpdateTest extends TestCase
{
    use E2ETestHelper;

    public function testProjectStop(): void
    {
        // Verify project is running
        $result = $this->runConsoleJson('project:status');
        $this->assertSame('ok', $result['status']);

        // Stop (without removing)
        $result = $this->runConsoleJson('project:stop', timeout: 60);
        $this->assertSame('ok', $result['status'], 'project:stop should succeed');

        // After stop, containers should exist but be stopped
        // project:status should still show them
        $result = $this->runConsoleJson('project:status');
        $this->assertSame('ok', $result['status']);

        // Restart with project:up (should work because containers still exist)
        $result = $this->runConsoleJson('project:up', timeout: 180);
        $this->assertSame('ok', $result['status'], 'project:up after stop should succeed');
    }

    public function testProjectUpdate(): void
    {
        // project:update does: down, pull, build, up
        $result = $this->runConsoleJson('project:update', timeout: 300);
        $this->assertSame('ok', $result['status'], 'project:update should succeed');

        // Verify project is running again after update
        $result = $this->runConsoleJson('project:status');
        $this->assertSame('ok', $result['status']);

        // Verify web container is accessible using project:exec
        // Uses new IS_ARRAY argument syntax (no -- separator needed)
        $process = $this->runConsole('project:exec', ['-s', 'web', 'whoami']);
        $this->assertTrue($process->isSuccessful());
        $this->assertSame('dde', trim($process->getOutput()));
    }

    public function testProjectExecDefaultService(): void
    {
        // project:exec without --service should use first service (web)
        $process = $this->runConsole('project:exec', ['whoami']);
        $this->assertTrue($process->isSuccessful(), 'project:exec without --service should succeed');
        $this->assertSame('dde', trim($process->getOutput()));
    }

    public function testProjectExecAsRoot(): void
    {
        $process = $this->runConsole('project:exec', ['--root', '--', 'whoami']);
        $this->assertTrue($process->isSuccessful());
        $this->assertSame('root', trim($process->getOutput()));
    }

    public function testProjectExecMultiWordCommand(): void
    {
        // Test IS_ARRAY argument with multiple words
        $process = $this->runConsole('project:exec', ['--', 'id', '-u', 'dde']);
        $this->assertTrue($process->isSuccessful());
        $this->assertSame((string) posix_getuid(), trim($process->getOutput()));
    }

    public function testProjectShellRejectsJson(): void
    {
        // project:shell with --output=json should return error
        $process = $this->runConsole('project:shell', ['--output=json']);
        $json = json_decode($process->getOutput(), true);
        $this->assertSame('error', $json['status'] ?? null);
        $this->assertStringContainsString('not supported', $json['message'] ?? '');
    }

    protected function setUp(): void
    {
        $this->bootProject('e2e-stop-test', 'mariadb');
    }

    protected function tearDown(): void
    {
        $this->tearDownE2EProject();
    }
}
