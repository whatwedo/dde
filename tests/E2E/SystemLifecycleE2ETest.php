<?php

declare(strict_types=1);

namespace Tests\E2E;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[Group('e2e')]
final class SystemLifecycleE2ETest extends TestCase
{
    use E2ETestHelper;

    /**
     * Walks the full system lifecycle end-to-end:
     * system:install -> system:stop -> system:up -> system:update -> system:down.
     *
     * The goal is to assert the visible container states at each transition so
     * that the manager orchestration (SystemLifecycleManager) keeps its contract
     * with docker: stop leaves containers present-but-not-running, up brings
     * them back, update replaces images while keeping the service running, and
     * down removes them entirely.
     */
    public function testFullLifecycleInstallStopUpUpdateDown(): void
    {
        // 1. system:install — bootstraps dde (traefik + network + certs) and
        //    leaves traefik running.
        $install = $this->runDde(['system:install', '--no-interaction'], timeout: 300);
        $this->assertSame(
            0,
            $install->getExitCode(),
            sprintf("system:install failed:\nSTDOUT: %s\nSTDERR: %s", $install->getOutput(), $install->getErrorOutput()),
        );

        $this->assertContainerRunning('dde-traefik');

        // 2. system:stop — containers stay around but are not running.
        $stop = $this->runDde(['system:stop'], timeout: 60);
        $this->assertSame(
            0,
            $stop->getExitCode(),
            sprintf("system:stop failed:\nSTDOUT: %s\nSTDERR: %s", $stop->getOutput(), $stop->getErrorOutput()),
        );
        $this->assertContainerNotRunningButExists('dde-traefik');

        // 3. system:up — starts the previously stopped containers again.
        $up = $this->runDde(['system:up'], timeout: 180);
        $this->assertSame(
            0,
            $up->getExitCode(),
            sprintf("system:up failed:\nSTDOUT: %s\nSTDERR: %s", $up->getOutput(), $up->getErrorOutput()),
        );
        $this->assertContainerRunning('dde-traefik');

        // Capture the image id before update so we can compare afterwards.
        // (The actual image id may or may not change depending on whether an
        // upstream base image update is available, but `system:update` must at
        // minimum leave the container running on a valid image afterwards.)
        $imageIdBefore = $this->inspectImage('dde-traefik');
        $this->assertNotSame('', $imageIdBefore, 'traefik should have an image id after system:up');

        // 4. system:update — rebuilds with --pull and restarts.
        $update = $this->runDde(['system:update', '--no-interaction'], timeout: 600);
        $this->assertSame(
            0,
            $update->getExitCode(),
            sprintf("system:update failed:\nSTDOUT: %s\nSTDERR: %s", $update->getOutput(), $update->getErrorOutput()),
        );
        $this->assertContainerRunning('dde-traefik');

        $imageIdAfter = $this->inspectImage('dde-traefik');
        $this->assertNotSame('', $imageIdAfter, 'traefik should have an image id after system:update');

        // 5. system:down — removes the containers entirely.
        $down = $this->runDde(['system:down'], timeout: 60);
        $this->assertSame(
            0,
            $down->getExitCode(),
            sprintf("system:down failed:\nSTDOUT: %s\nSTDERR: %s", $down->getOutput(), $down->getErrorOutput()),
        );
        $this->assertContainerGone('dde-traefik');
    }

    /**
     * Thin wrapper around runConsole that delegates to the E2ETestHelper so
     * this test shares the project's isolated DDE_CONFIG_DIR / DDE_DATA_DIR
     * setup, while exposing the helper signature expected by the plan.
     *
     * @param list<string> $args
     */
    private function runDde(array $args, int $timeout = 120): Process
    {
        $command = array_shift($args);
        $this->assertIsString($command, 'runDde expects at least a command name as first element of $args');

        return $this->runConsole($command, $args, $timeout);
    }

    private function assertContainerRunning(string $name): void
    {
        $process = new Process(['docker', 'ps', '--filter', 'name=^'.$name.'$', '--format', '{{.Names}}']);
        $process->run();
        $this->assertSame(
            $name,
            trim($process->getOutput()),
            sprintf('Expected container "%s" to be running, got "%s"', $name, trim($process->getOutput())),
        );
    }

    private function assertContainerNotRunningButExists(string $name): void
    {
        $psRunning = new Process(['docker', 'ps', '--filter', 'name=^'.$name.'$', '--format', '{{.Names}}']);
        $psRunning->run();
        $this->assertSame(
            '',
            trim($psRunning->getOutput()),
            sprintf('Container "%s" should not be running, but is.', $name),
        );

        $psAll = new Process(['docker', 'ps', '-a', '--filter', 'name=^'.$name.'$', '--format', '{{.Names}}']);
        $psAll->run();
        $this->assertSame(
            $name,
            trim($psAll->getOutput()),
            sprintf('Container "%s" should still exist (created/exited), but was not found.', $name),
        );
    }

    private function assertContainerGone(string $name): void
    {
        $psAll = new Process(['docker', 'ps', '-a', '--filter', 'name=^'.$name.'$', '--format', '{{.Names}}']);
        $psAll->run();
        $this->assertSame(
            '',
            trim($psAll->getOutput()),
            sprintf('Container "%s" should be gone after system:down, but still exists.', $name),
        );
    }

    private function inspectImage(string $containerName): string
    {
        $process = new Process(['docker', 'inspect', '--format', '{{.Image}}', $containerName]);
        $process->run();

        return trim($process->getOutput());
    }

    protected function setUp(): void
    {
        $this->consolePath = \dirname(__DIR__, 2).'/bin/console';
        $this->projectDir = sys_get_temp_dir();
        $this->tempDataDir = sys_get_temp_dir().'/dde-e2e-system-lifecycle-'.bin2hex(random_bytes(8));
        (new Filesystem())->mkdir($this->tempDataDir);

        // Ensure we start from a known-clean state.
        $this->runConsole('system:down', timeout: 60);
        $this->cleanupLeftoverContainers();
    }

    protected function tearDown(): void
    {
        $this->runConsole('system:down', timeout: 60);
        $this->cleanupLeftoverContainers();
        (new Filesystem())->remove($this->tempDataDir);
    }
}
