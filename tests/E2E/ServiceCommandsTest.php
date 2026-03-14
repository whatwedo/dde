<?php

declare(strict_types=1);

namespace Tests\E2E;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('e2e')]
final class ServiceCommandsTest extends TestCase
{
    use E2ETestHelper;

    public function testServiceListJson(): void
    {
        $result = $this->runConsoleJson('project:service:list');
        $this->assertSame('ok', $result['status']);
        $this->assertArrayHasKey('services', $result['data']);
        $this->assertNotEmpty($result['data']['services']);

        // mariadb should be active
        $activeServices = array_filter(
            $result['data']['services'],
            static fn (array $s): bool => $s['status'] === 'active',
        );
        $activeNames = array_column(array_values($activeServices), 'name');
        $this->assertContains('mariadb', $activeNames);
    }

    public function testServiceListTextOutput(): void
    {
        $process = $this->runConsole('project:service:list');
        $this->assertTrue($process->isSuccessful());
        $this->assertStringContainsString('mariadb', $process->getOutput());
        $this->assertStringContainsString('active', $process->getOutput());
    }

    public function testServiceEnableAndDisable(): void
    {
        // Enable valkey (should not be active yet)
        $result = $this->runConsoleJson('project:service:enable', ['valkey']);
        $this->assertSame('ok', $result['status']);
        $this->assertSame('valkey', $result['data']['service']);

        // Verify it appears in service:list as active
        $result = $this->runConsoleJson('project:service:list');
        $activeServices = array_filter(
            $result['data']['services'],
            static fn (array $s): bool => $s['status'] === 'active',
        );
        $activeNames = array_column(array_values($activeServices), 'name');
        $this->assertContains('valkey', $activeNames);
        $this->assertContains('mariadb', $activeNames);

        // Disable valkey
        $result = $this->runConsoleJson('project:service:disable', ['valkey']);
        $this->assertSame('ok', $result['status']);

        // Verify it's gone from active list
        $result = $this->runConsoleJson('project:service:list');
        $activeServices = array_filter(
            $result['data']['services'],
            static fn (array $s): bool => $s['status'] === 'active',
        );
        $activeNames = array_column(array_values($activeServices), 'name');
        $this->assertNotContains('valkey', $activeNames);
        $this->assertContains('mariadb', $activeNames, 'mariadb should still be active');
    }

    public function testServiceEnableUnknownService(): void
    {
        $process = $this->runConsole('project:service:enable', ['--output=json', 'nonexistent']);
        $json = json_decode($process->getOutput(), true);
        $this->assertSame('error', $json['status'] ?? null);
    }

    public function testServiceEnableAlreadyEnabled(): void
    {
        // mariadb is already enabled from init
        $process = $this->runConsole('project:service:enable', ['mariadb']);
        $this->assertTrue($process->isSuccessful(), 'Enabling already-enabled service should not fail');
        $combinedOutput = $process->getOutput().$process->getErrorOutput();
        $this->assertStringContainsString('already enabled', $combinedOutput);
    }

    protected function setUp(): void
    {
        $this->initE2EProject();

        // Clean up leftover containers
        $this->cleanupLeftoverContainers();

        // Init project with only mariadb
        $result = $this->runConsoleJson('project:init', [
            '--name=e2e-svc-test',
            '--services=mariadb',
            '--shell=bash',
            '--force',
        ]);
        $this->assertSame('ok', $result['status']);
    }

    protected function tearDown(): void
    {
        $this->tearDownE2EProject();
    }
}
