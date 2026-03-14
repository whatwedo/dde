<?php

declare(strict_types=1);

namespace Tests\E2E;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[Group('e2e')]
final class MultiServiceProjectTest extends TestCase
{
    use E2ETestHelper;

    private Filesystem $filesystem;

    public function testMultiServiceProjectLifecycle(): void
    {
        // 1. project:init with mariadb service
        $result = $this->runConsoleJson('project:init', [
            '--name=e2e-multi',
            '--services=mariadb',
            '--shell=bash',
            '--force',
        ]);
        $this->assertSame('ok', $result['status'], 'project:init should succeed');

        // 2. system:up
        $result = $this->runConsoleJson('system:up', timeout: 180);
        $this->assertSame('ok', $result['status'], 'system:up should succeed');

        // 3. project:up
        $result = $this->runConsoleJson('project:up', timeout: 180);
        $this->assertSame('ok', $result['status'], 'project:up should succeed');

        // 4. Verify both services are running via project:status
        $result = $this->runConsoleJson('project:status');
        $this->assertSame('ok', $result['status']);
        $containerNames = array_column($result['data']['containers'], 'name');
        $this->assertContains('web', $containerNames, 'web service should be running');
        $this->assertContains('api', $containerNames, 'api service should be running');

        // 5. Verify exec works on web service
        $process = $this->runConsole('project:exec', ['--service=web', '--', 'whoami']);
        $this->assertTrue($process->isSuccessful(), 'exec on web should succeed');
        $this->assertSame('dde', trim($process->getOutput()));

        // 6. Verify exec works on api service
        $process = $this->runConsole('project:exec', ['--service=api', '--', 'whoami']);
        $this->assertTrue($process->isSuccessful(), 'exec on api should succeed');
        $this->assertSame('dde', trim($process->getOutput()));

        // 7. Verify HTTPS on web domain (e2e-multi.test)
        $this->waitForHttpResponse('https://e2e-multi.test/index.php', 'web service OK');

        // 8. Verify HTTPS on api domain (api.e2e-multi.test)
        $this->waitForHttpResponse('https://api.e2e-multi.test/index.php', 'api service OK');

        // 9. project:describe should show both containers and the project URL
        $result = $this->runConsoleJson('project:describe');
        $this->assertSame('ok', $result['status']);
        $this->assertSame('e2e-multi', $result['data']['project']);
        $this->assertArrayHasKey('containers', $result['data']);
        $describeServices = array_column($result['data']['containers'], 'name');
        $this->assertContains('web', $describeServices);
        $this->assertContains('api', $describeServices);

        // 10. MariaDB connectivity from web container
        $process = $this->runConsole('project:exec', ['--service=web', '--', 'php', '-r', implode(' ', [
            "new PDO('mysql:host=mariadb;port=3306', 'root', 'root');",
            "echo 'DB_OK';",
        ])]);
        $this->assertTrue($process->isSuccessful(), 'MariaDB from web should succeed: '.$process->getErrorOutput());
        $this->assertStringContainsString('DB_OK', $process->getOutput());

        // 11. MariaDB connectivity from api container
        $process = $this->runConsole('project:exec', ['--service=api', '--', 'php', '-r', implode(' ', [
            "new PDO('mysql:host=mariadb;port=3306', 'root', 'root');",
            "echo 'DB_OK';",
        ])]);
        $this->assertTrue($process->isSuccessful(), 'MariaDB from api should succeed: '.$process->getErrorOutput());
        $this->assertStringContainsString('DB_OK', $process->getOutput());

        // 12. project:down
        $result = $this->runConsoleJson('project:down', timeout: 60);
        $this->assertSame('ok', $result['status'], 'project:down should succeed');
    }

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->consolePath = dirname(__DIR__, 2).'/bin/console';
        $this->projectDir = sys_get_temp_dir().'/dde-e2e-'.bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->projectDir);

        $this->tempDataDir = sys_get_temp_dir().'/dde-e2e-data-'.bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->tempDataDir);

        // Create docker-compose.yml with two services and two .test domains
        $this->filesystem->dumpFile($this->projectDir.'/docker-compose.yml', implode("\n", [
            'services:',
            '  web:',
            '    image: registry.whatwedo.ch/whatwedo/docker-base-images/nginx-php:v2.11',
            '    volumes:',
            '      - ./web:/var/www',
            '    environment:',
            '      - VIRTUAL_HOST=e2e-multi.test',
            '  api:',
            '    image: registry.whatwedo.ch/whatwedo/docker-base-images/nginx-php:v2.11',
            '    volumes:',
            '      - ./api:/var/www',
            '    environment:',
            '      - VIRTUAL_HOST=api.e2e-multi.test',
        ]));

        // Create web index.php
        $this->filesystem->mkdir($this->projectDir.'/web');
        $this->filesystem->dumpFile($this->projectDir.'/web/index.php', "<?php\necho 'web service OK';\n");

        // Create api index.php
        $this->filesystem->mkdir($this->projectDir.'/api');
        $this->filesystem->dumpFile($this->projectDir.'/api/index.php', "<?php\necho 'api service OK';\n");

        // Ensure clean state
        $this->runConsole('system:down', timeout: 60);
        $this->cleanupLeftoverContainers();
    }

    protected function tearDown(): void
    {
        $this->tearDownE2EProject();
    }
}
