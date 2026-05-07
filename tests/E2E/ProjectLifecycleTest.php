<?php

declare(strict_types=1);

namespace Tests\E2E;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[Group('e2e')]
final class ProjectLifecycleTest extends TestCase
{
    use E2ETestHelper;

    private Filesystem $filesystem;

    public function testFullProjectLifecycle(): void
    {
        // 1. project:init (mailpit is started globally by system:up, not as a project service)
        $result = $this->runConsoleJson('project:init', [
            '--name=e2e-test',
            '--services=mariadb',
            '--shell=bash',
            '--force',
        ]);
        $this->assertSame('ok', $result['status'], 'project:init should succeed');
        $this->assertFileExists($this->projectDir.'/.dde/config.yml');

        // 2. system:up
        $result = $this->runConsoleJson('system:up', timeout: 180);
        $this->assertSame('ok', $result['status'], 'system:up should succeed');

        // 3. project:up
        $result = $this->runConsoleJson('project:up', timeout: 180);
        $this->assertSame('ok', $result['status'], 'project:up should succeed');
        $this->assertSame('started', $result['data']['status']);

        // Wait for MariaDB to be ready
        $this->waitForMariaDb();

        // 4. project:exec -- whoami (as dde user)
        $process = $this->runConsole('project:exec', ['--service=web', '--', 'whoami']);
        $this->assertTrue($process->isSuccessful(), 'project:exec whoami should succeed');
        $this->assertSame('dde', trim($process->getOutput()));

        // 5. project:exec --root -- whoami
        $process = $this->runConsole('project:exec', ['--service=web', '--root', '--', 'whoami']);
        $this->assertTrue($process->isSuccessful(), 'project:exec --root whoami should succeed');
        $this->assertSame('root', trim($process->getOutput()));

        // 6. project:exec -- id -u dde (verify UID matches host)
        $process = $this->runConsole('project:exec', ['--service=web', '--', 'id', '-u', 'dde']);
        $this->assertTrue($process->isSuccessful(), 'project:exec id -u dde should succeed');
        $this->assertSame((string) posix_getuid(), trim($process->getOutput()));

        // 7. Traefik reverse proxy: verify HTTPS access via curl to e2e-test.test
        $this->waitForHttpResponse('https://e2e-test.test/index.php', 'dde E2E test OK');

        // 8. MariaDB connectivity from PHP container
        $process = $this->runConsole('project:exec', ['--service=web', '--', 'php', '-r', implode(' ', [
            "new PDO('mysql:host=mariadb;port=3306', 'root', 'root');",
            "echo 'DB_OK';",
        ])]);
        $this->assertTrue($process->isSuccessful(), 'MariaDB connection from container should succeed: '.$process->getErrorOutput());
        $this->assertStringContainsString('DB_OK', $process->getOutput());

        // 8b. Mailpit: reachable via Traefik at mail.test (no host port forwarding since refactor)
        $this->waitForHttpResponse('https://mail.test/', 'Mailpit');

        // 9. project:status --output=json
        $result = $this->runConsoleJson('project:status');
        $this->assertSame('ok', $result['status'], 'project:status should succeed');
        $this->assertNotEmpty($result['data']['containers']);

        // 10. project:describe --output=json
        $result = $this->runConsoleJson('project:describe');
        $this->assertSame('ok', $result['status'], 'project:describe should succeed');
        $this->assertSame('e2e-test', $result['data']['project']);
        $this->assertSame('https://e2e-test.test', $result['data']['url']);
        $this->assertArrayHasKey('services', $result['data']);
        $this->assertArrayHasKey('containers', $result['data']);
        $this->assertArrayHasKey('hooks', $result['data']);
        $this->assertArrayHasKey('plugins', $result['data']);

        // 11. project:logs
        $process = $this->runConsole('project:logs', ['--tail=3', '--no-follow', '--service=web']);
        $this->assertTrue($process->isSuccessful(), 'project:logs should succeed');

        // 12. project:status --output=json
        $result = $this->runConsoleJson('project:status');
        $this->assertSame('ok', $result['status'], 'project:status should succeed');

        // 13. Hooks: create a post-up hook, stop + up, verify it ran
        $hookDir = $this->projectDir.'/.dde/hooks/project.up.post';
        $this->filesystem->mkdir($hookDir);
        $hookFile = $hookDir.'/01-e2e.sh';
        $hookOutputFile = $this->projectDir.'/hook-output.txt';
        $this->filesystem->dumpFile($hookFile, "#!/bin/bash\necho 'HOOK_OK' > ".$hookOutputFile."\n");
        chmod($hookFile, 0o755);

        $result = $this->runConsoleJson('project:stop', timeout: 60);
        $this->assertSame('ok', $result['status'], 'project:stop should succeed');

        $result = $this->runConsoleJson('project:up', timeout: 180);
        $this->assertSame('ok', $result['status'], 'project:up after stop should succeed');
        $this->assertFileExists($hookOutputFile, 'post-up hook should have created output file');
        $this->assertSame('HOOK_OK', trim((string) file_get_contents($hookOutputFile)));

        // 14. --skip-hooks: verify hooks are skipped
        $this->filesystem->remove($hookOutputFile);
        $this->runConsole('project:down', ['--skip-hooks'], timeout: 60);
        $this->runConsole('project:up', ['--skip-hooks'], timeout: 180);
        $this->assertFileDoesNotExist($hookOutputFile, 'hook should not run when --skip-hooks is used');

        // 15. Plugin: create plugin, verify it can be executed
        $pluginDir = $this->projectDir.'/.dde/plugins';
        $this->filesystem->mkdir($pluginDir);
        $this->filesystem->dumpFile($pluginDir.'/hello.sh', implode("\n", [
            '#!/bin/bash',
            '# @command web:hello',
            '# @description Say hello',
            "echo 'PLUGIN_OK'",
        ]));
        chmod($pluginDir.'/hello.sh', 0o755);

        $process = $this->runConsole('project:web:hello');
        $this->assertTrue($process->isSuccessful(), 'plugin command should succeed');
        $this->assertStringContainsString('PLUGIN_OK', $process->getOutput());

        // 16. project:down
        $result = $this->runConsoleJson('project:down', timeout: 60);
        $this->assertSame('ok', $result['status'], 'project:down should succeed');
        $this->assertSame('stopped', $result['data']['status']);
    }

    protected function setUp(): void
    {
        $this->initE2EProject();

        // Ensure services start fresh (previous runs may have left containers)
        $this->runConsole('system:down', timeout: 60);
        $this->cleanupLeftoverContainers();

        $this->filesystem = new Filesystem();
    }

    protected function tearDown(): void
    {
        $this->tearDownE2EProject();
    }
}
