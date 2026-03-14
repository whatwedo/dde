<?php

declare(strict_types=1);

namespace Tests\E2E;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

trait E2ETestHelper
{
    private string $projectDir;

    private string $consolePath;

    private string $tempDataDir;

    /**
     * @param list<string> $args
     */
    private function runConsole(string $command, array $args = [], int $timeout = 120): Process
    {
        $cmd = ['php', $this->consolePath, $command, ...$args];
        $process = new Process($cmd, $this->projectDir);
        $process->setEnv([
            'DDE_CONFIG_DIR' => $this->tempDataDir.'/config',
            'DDE_DATA_DIR' => $this->tempDataDir,
        ]);
        $process->setTimeout($timeout);
        $process->run();

        return $process;
    }

    /**
     * @param list<string> $args
     *
     * @return array<string, mixed>
     */
    private function runConsoleJson(string $command, array $args = [], int $timeout = 120): array
    {
        $process = $this->runConsole($command, ['--output=json', ...$args], $timeout);

        $this->assertTrue(
            $process->isSuccessful(),
            sprintf("Command '%s' failed:\nSTDOUT: %s\nSTDERR: %s", $command, $process->getOutput(), $process->getErrorOutput()),
        );

        $json = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($json);

        return $json;
    }

    private function waitForHttpResponse(string $url, string $expectedContent, int $maxAttempts = 60): void
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        for ($i = 0; $i < $maxAttempts; $i++) {
            $process = new Process(['curl', '-sk', '--connect-timeout', '3', '--max-time', '5', '--resolve', $host.':443:127.0.0.1', $url]);
            $process->setTimeout(10);
            $process->run();

            if ($process->isSuccessful() && str_contains($process->getOutput(), $expectedContent)) {
                $this->assertStringContainsString($expectedContent, $process->getOutput());

                return;
            }

            usleep(2_000_000);
        }

        $this->fail(sprintf('URL "%s" did not return expected content "%s" within %d attempts', $url, $expectedContent, $maxAttempts));
    }

    private function waitForMariaDb(int $maxAttempts = 60): void
    {
        for ($i = 0; $i < $maxAttempts; $i++) {
            $process = new Process(['docker', 'exec', 'dde-mariadb-11.8', 'mariadb', '-uroot', '-proot', '-e', 'SELECT 1']);
            $process->setTimeout(5);
            $process->run();

            if ($process->isSuccessful()) {
                return;
            }

            usleep(1_000_000);
        }

        $this->fail('MariaDB did not become ready within '.$maxAttempts.' seconds');
    }

    /**
     * Initialize a standard E2E project with nginx-php.
     * Sets up projectDir, tempDataDir, consolePath, and creates docker-compose.yml + index.php.
     */
    private function initE2EProject(): void
    {
        $filesystem = new Filesystem();
        $this->consolePath = dirname(__DIR__, 2).'/bin/console';
        $this->projectDir = sys_get_temp_dir().'/dde-e2e-'.bin2hex(random_bytes(8));
        $filesystem->mkdir($this->projectDir);

        $this->tempDataDir = sys_get_temp_dir().'/dde-e2e-data-'.bin2hex(random_bytes(8));
        $filesystem->mkdir($this->tempDataDir);

        $filesystem->dumpFile($this->projectDir.'/docker-compose.yml', implode("\n", [
            'services:',
            '  web:',
            '    image: registry.whatwedo.ch/whatwedo/docker-base-images/nginx-php:v2.11',
            '    volumes:',
            '      - ./:/var/www',
        ]));

        $filesystem->dumpFile($this->projectDir.'/index.php', "<?php\necho 'dde E2E test OK';\n");
    }

    /**
     * Run project:init + system:up + project:up to get a fully running project.
     */
    private function bootProject(string $name = 'e2e-test', string $services = 'mariadb'): void
    {
        $this->initE2EProject();

        // Ensure clean state: stop system services and remove leftover containers
        $this->runConsole('system:down', timeout: 60);
        $this->cleanupLeftoverContainers();

        $result = $this->runConsoleJson('project:init', [
            '--name='.$name,
            '--services='.$services,
            '--shell=bash',
            '--force',
        ]);
        $this->assertSame('ok', $result['status'], 'project:init should succeed');

        $result = $this->runConsoleJson('system:up', timeout: 180);
        $this->assertSame('ok', $result['status'], 'system:up should succeed');

        $result = $this->runConsoleJson('project:up', timeout: 180);
        $this->assertSame('ok', $result['status'], 'project:up should succeed');
    }

    private function cleanupLeftoverContainers(): void
    {
        $process = new Process(['docker', 'ps', '-a', '--format', '{{.Names}}', '--filter', 'label=dde.managed=true']);
        $process->run();

        foreach (array_filter(explode("\n", trim($process->getOutput()))) as $container) {
            (new Process(['docker', 'rm', '-f', $container]))->run();
        }

        $process = new Process(['docker', 'ps', '-a', '--format', '{{.Names}}', '--filter', 'name=dde-']);
        $process->run();

        foreach (array_filter(explode("\n", trim($process->getOutput()))) as $container) {
            (new Process(['docker', 'rm', '-f', $container]))->run();
        }
    }

    /**
     * Tear down project and system, clean up temp dirs.
     */
    private function tearDownE2EProject(): void
    {
        $this->runConsole('project:down', timeout: 60);
        $this->runConsole('system:down', timeout: 60);
        $this->cleanupLeftoverContainers();

        $filesystem = new Filesystem();
        $filesystem->remove($this->projectDir);
        $filesystem->remove($this->tempDataDir);
    }
}
