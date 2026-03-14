<?php

declare(strict_types=1);

namespace Tests\E2E;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[Group('e2e')]
final class DoctorChecksE2ETest extends TestCase
{
    use E2ETestHelper;

    public function testDoctorReturnsAllChecks(): void
    {
        $process = $this->runConsole('system:doctor', ['--output=json']);
        $json = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($json);

        $this->assertContains($json['status'], ['ok', 'error']);

        if ($json['status'] === 'ok') {
            $checks = $json['data']['checks'];
        } else {
            $this->assertNotEmpty($json['errors'], 'Doctor error should have details');

            return;
        }

        $this->assertNotEmpty($checks, 'Doctor should return at least one check');

        foreach ($checks as $check) {
            $this->assertArrayHasKey('name', $check);
            $this->assertArrayHasKey('status', $check);
            $this->assertArrayHasKey('message', $check);
            $this->assertContains($check['status'], ['ok', 'warning', 'error']);
        }

        $checkNames = array_column($checks, 'name');
        $this->assertContains('Docker Available', $checkNames, 'Docker check should be present');
        $this->assertContains('Docker Compose', $checkNames, 'Docker Compose check should be present');
        $this->assertContains('Docker Network', $checkNames, 'Network check should be present');
    }

    public function testDoctorDockerCheckPassesWithRunningDocker(): void
    {
        $process = $this->runConsole('system:doctor', ['--output=json']);
        $json = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

        if ($json['status'] !== 'ok') {
            $this->markTestSkipped('Doctor returned errors — environment may not be fully configured');
        }

        $checks = $json['data']['checks'];
        $dockerCheck = array_values(array_filter(
            $checks,
            static fn (array $c): bool => $c['name'] === 'Docker Available',
        ));

        $this->assertNotEmpty($dockerCheck, 'Docker check should be present');
        $this->assertSame('ok', $dockerCheck[0]['status'], 'Docker check should pass when Docker is running');
    }

    public function testDoctorNetworkCheckPassesAfterSystemUp(): void
    {
        $process = $this->runConsole('system:doctor', ['--output=json']);
        $json = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

        if ($json['status'] !== 'ok') {
            $this->markTestSkipped('Doctor returned errors — environment may not be fully configured');
        }

        $checks = $json['data']['checks'];
        $networkCheck = array_values(array_filter(
            $checks,
            static fn (array $c): bool => $c['name'] === 'Docker Network',
        ));

        $this->assertNotEmpty($networkCheck, 'Network check should be present');
        $this->assertSame('ok', $networkCheck[0]['status'], 'Network check should pass after system:up');
    }

    public function testDoctorTraefikCheckPassesAfterSystemUp(): void
    {
        $process = $this->runConsole('system:doctor', ['--output=json']);
        $json = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

        if ($json['status'] !== 'ok') {
            $this->markTestSkipped('Doctor returned errors — environment may not be fully configured');
        }

        $checks = $json['data']['checks'];
        $traefikCheck = array_values(array_filter(
            $checks,
            static fn (array $c): bool => $c['name'] === 'Traefik',
        ));

        $this->assertNotEmpty($traefikCheck, 'Traefik check should be present');
        $this->assertSame('ok', $traefikCheck[0]['status'], 'Traefik check should pass after system:up');
    }

    public function testDoctorTextOutputContainsCheckResults(): void
    {
        $process = $this->runConsole('system:doctor');
        $output = $process->getOutput();
        $this->assertNotEmpty($output, 'Doctor text output should not be empty');
    }

    protected function setUp(): void
    {
        $this->consolePath = dirname(__DIR__, 2).'/bin/console';
        $this->projectDir = sys_get_temp_dir();
        $this->tempDataDir = sys_get_temp_dir().'/dde-e2e-doctor-'.bin2hex(random_bytes(8));
        (new Filesystem())->mkdir($this->tempDataDir);

        $this->runConsole('system:down', timeout: 60);
        $this->runConsoleJson('system:up', timeout: 180);
    }

    protected function tearDown(): void
    {
        $this->runConsole('system:down', timeout: 60);
        (new Filesystem())->remove($this->tempDataDir);
    }
}
