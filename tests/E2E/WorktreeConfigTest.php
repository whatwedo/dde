<?php

declare(strict_types=1);

namespace Tests\E2E;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[Group('e2e')]
final class WorktreeConfigTest extends TestCase
{
    use E2ETestHelper;

    private Filesystem $filesystem;

    private string $mainRepoDir;

    private string $worktreeDir;

    private bool $mainProjectStarted = false;

    public function testWorktreeProjectGetsSubdomainAndRewritesEnvironment(): void
    {
        $this->initProjectInMain();
        $this->createWorktree('feature-test');

        // system:up + project:up from worktree
        $result = $this->runConsoleJsonInDir($this->worktreeDir, 'system:up', timeout: 180);
        $this->assertSame('ok', $result['status'], 'system:up should succeed');

        $result = $this->runConsoleJsonInDir($this->worktreeDir, 'project:up', timeout: 180);
        $this->assertSame('ok', $result['status'], 'project:up in worktree should succeed');

        // project:describe returns the worktree-specific URL
        $result = $this->runConsoleJsonInDir($this->worktreeDir, 'project:describe');
        $this->assertSame('ok', $result['status']);
        $this->assertArrayHasKey('url', $result['data']);
        $url = $result['data']['url'];
        $this->assertStringContainsString('e2e-wt', $url, 'URL should contain project name');
        $this->assertStringEndsWith('.test', $url, 'URL should end with .test');
        $this->assertNotSame('https://e2e-wt.test', $url, 'URL should differ from main project URL');

        // Traefik actually routes the worktree hostname to the web container
        $this->waitForHttpResponse($url.'/index.php', 'WORKTREE_CONTENT');

        // DATABASE_URL path segment is rewritten with the worktree suffix
        $dbUrl = $this->execInWorktree('echo $DATABASE_URL');
        $this->assertNotSame('', $dbUrl, 'DATABASE_URL should be set in the worktree container');

        $parts = explode('/', $dbUrl);
        $dbName = explode('?', end($parts), 2)[0];
        $this->assertStringStartsWith('e2e_wt_', $dbName, 'Worktree DB name should keep base name + separator: '.$dbName);
        $this->assertNotSame('e2e_wt', $dbName, 'Worktree DB name should differ from base DB name');
        $this->assertLessThanOrEqual(63, strlen($dbName), 'DB name must fit MySQL/Postgres identifier limit');

        // Other hostname-bearing env vars are rewritten too
        $appUrl = $this->execInWorktree('echo $APP_URL');
        $this->assertSame($url, $appUrl, 'APP_URL should be rewritten to worktree hostname');

        $mercureUrl = $this->execInWorktree('echo $MERCURE_URL');
        $host = (string) parse_url($url, PHP_URL_HOST);
        $this->assertStringContainsString($host, $mercureUrl, 'MERCURE_URL should contain worktree hostname');
        $this->assertStringNotContainsString('mercure.e2e-wt.test', $mercureUrl, 'main-project hostname must be gone');

        // MariaDB is actually reachable from the worktree container
        $pdoOutput = $this->execInWorktreeSuccess(['php', '-r', implode(' ', [
            "new PDO('mysql:host=mariadb;port=3306', 'root', 'root');",
            "echo 'DB_OK';",
        ])]);
        $this->assertStringContainsString('DB_OK', $pdoOutput, 'PDO connection to mariadb from worktree should succeed');

        $result = $this->runConsoleJsonInDir($this->worktreeDir, 'project:status');
        $this->assertSame('ok', $result['status']);
        $this->assertNotEmpty($result['data']['containers']);

        $result = $this->runConsoleJsonInDir($this->worktreeDir, 'project:down', timeout: 60);
        $this->assertSame('ok', $result['status']);
    }

    public function testMainAndWorktreeRunInParallelWithSeparateRouting(): void
    {
        $this->initProjectInMain();

        // Distinguish main from worktree by index.php content
        $this->filesystem->dumpFile($this->mainRepoDir.'/index.php', "<?php\necho 'MAIN_CONTENT';\n");
        (new Process(['git', 'add', 'index.php'], $this->mainRepoDir))->mustRun();
        (new Process(['git', 'commit', '-m', 'mark as main'], $this->mainRepoDir))->mustRun();

        $this->createWorktree('parallel-branch');
        $this->filesystem->dumpFile($this->worktreeDir.'/index.php', "<?php\necho 'WORKTREE_CONTENT';\n");

        // system:up once (shared), then both projects up
        $result = $this->runConsoleJsonInDir($this->mainRepoDir, 'system:up', timeout: 180);
        $this->assertSame('ok', $result['status'], 'system:up should succeed');

        $result = $this->runConsoleJsonInDir($this->mainRepoDir, 'project:up', timeout: 180);
        $this->assertSame('ok', $result['status'], 'main project:up should succeed');
        $this->mainProjectStarted = true;

        $result = $this->runConsoleJsonInDir($this->worktreeDir, 'project:up', timeout: 180);
        $this->assertSame('ok', $result['status'], 'worktree project:up should succeed');

        // Resolve both URLs via project:describe
        $mainDescribe = $this->runConsoleJsonInDir($this->mainRepoDir, 'project:describe');
        $this->assertSame('ok', $mainDescribe['status']);
        $mainUrl = $mainDescribe['data']['url'];
        $this->assertSame('https://e2e-wt.test', $mainUrl, 'main URL should be plain project hostname');

        $worktreeDescribe = $this->runConsoleJsonInDir($this->worktreeDir, 'project:describe');
        $this->assertSame('ok', $worktreeDescribe['status']);
        $worktreeUrl = $worktreeDescribe['data']['url'];
        $this->assertNotSame($mainUrl, $worktreeUrl, 'URLs must differ');

        // Traefik routes each hostname to the correct container
        $this->waitForHttpResponse($mainUrl.'/index.php', 'MAIN_CONTENT');
        $this->waitForHttpResponse($worktreeUrl.'/index.php', 'WORKTREE_CONTENT');

        // DATABASE_URL differs between main and worktree containers
        $mainDbUrl = $this->execInMain('echo $DATABASE_URL');
        $worktreeDbUrl = $this->execInWorktree('echo $DATABASE_URL');
        $this->assertNotSame($mainDbUrl, $worktreeDbUrl, 'main and worktree must see different DATABASE_URL');
        $this->assertStringContainsString('/e2e_wt?', $mainDbUrl, 'main DB name stays as e2e_wt');
        $this->assertStringContainsString('/e2e_wt_', $worktreeDbUrl, 'worktree DB name has suffix');

        // Both containers can reach the shared MariaDB service
        $mainPdo = $this->execInMainSuccess(['php', '-r', "new PDO('mysql:host=mariadb;port=3306', 'root', 'root'); echo 'DB_OK';"]);
        $this->assertStringContainsString('DB_OK', $mainPdo);

        $worktreePdo = $this->execInWorktreeSuccess(['php', '-r', "new PDO('mysql:host=mariadb;port=3306', 'root', 'root'); echo 'DB_OK';"]);
        $this->assertStringContainsString('DB_OK', $worktreePdo);

        // Tearing down the worktree while main is still up must NOT disconnect
        // the shared MariaDB service from the per-project network — otherwise
        // main's web container loses the `mariadb` alias and cannot reach the DB.
        $result = $this->runConsoleJsonInDir($this->worktreeDir, 'project:down', timeout: 60);
        $this->assertSame('ok', $result['status']);

        $this->waitForHttpResponse($mainUrl.'/index.php', 'MAIN_CONTENT');
        $mainPdoAfterWorktreeDown = $this->execInMainSuccess(['php', '-r', "new PDO('mysql:host=mariadb;port=3306', 'root', 'root'); echo 'DB_OK';"]);
        $this->assertStringContainsString(
            'DB_OK',
            $mainPdoAfterWorktreeDown,
            'main must still reach MariaDB after the worktree project:down',
        );

        $result = $this->runConsoleJsonInDir($this->mainRepoDir, 'project:down', timeout: 60);
        $this->assertSame('ok', $result['status']);
        $this->mainProjectStarted = false;
    }

    private function initProjectInMain(): void
    {
        $result = $this->runConsoleJsonInDir($this->mainRepoDir, 'project:init', [
            '--name=e2e-wt',
            '--services=mariadb',
            '--shell=bash',
            '--force',
        ]);
        $this->assertSame('ok', $result['status'], 'project:init in main should succeed');

        // Commit .dde/ so worktree has it
        (new Process(['git', 'add', '-A'], $this->mainRepoDir))->mustRun();
        (new Process(['git', 'commit', '-m', 'add dde config'], $this->mainRepoDir))->mustRun();
    }

    private function createWorktree(string $branch): void
    {
        $process = new Process(['git', 'checkout', '-b', $branch], $this->mainRepoDir);
        $process->run();
        $this->assertTrue($process->isSuccessful(), 'git checkout -b should succeed');

        $process = new Process(['git', 'checkout', 'main'], $this->mainRepoDir);
        $process->run();

        $this->worktreeDir = $this->mainRepoDir.'-wt-'.$branch;
        $process = new Process(['git', 'worktree', 'add', $this->worktreeDir, $branch], $this->mainRepoDir);
        $process->run();
        $this->assertTrue($process->isSuccessful(), 'git worktree add should succeed: '.$process->getErrorOutput());
    }

    private function execInWorktree(string $shellCmd): string
    {
        return $this->execInDir($this->worktreeDir, ['sh', '-c', $shellCmd]);
    }

    private function execInMain(string $shellCmd): string
    {
        return $this->execInDir($this->mainRepoDir, ['sh', '-c', $shellCmd]);
    }

    /**
     * @param list<string> $args
     */
    private function execInWorktreeSuccess(array $args): string
    {
        return $this->execInDir($this->worktreeDir, $args);
    }

    /**
     * @param list<string> $args
     */
    private function execInMainSuccess(array $args): string
    {
        return $this->execInDir($this->mainRepoDir, $args);
    }

    /**
     * @param list<string> $args
     */
    private function execInDir(string $dir, array $args): string
    {
        $originalProjectDir = $this->projectDir;
        $this->projectDir = $dir;
        $process = $this->runConsole('project:exec', ['--service=web', '--', ...$args]);
        $this->projectDir = $originalProjectDir;

        $this->assertTrue(
            $process->isSuccessful(),
            'project:exec should succeed: '.$process->getErrorOutput(),
        );

        return trim($process->getOutput());
    }

    /**
     * @param list<string> $args
     *
     * @return array<string, mixed>
     */
    private function runConsoleJsonInDir(string $dir, string $command, array $args = [], int $timeout = 120): array
    {
        $originalProjectDir = $this->projectDir;
        $this->projectDir = $dir;
        $result = $this->runConsoleJson($command, $args, $timeout);
        $this->projectDir = $originalProjectDir;

        return $result;
    }

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->consolePath = dirname(__DIR__, 2).'/bin/console';

        $this->mainRepoDir = sys_get_temp_dir().'/dde-e2e-wt-'.bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->mainRepoDir);

        $this->tempDataDir = sys_get_temp_dir().'/dde-e2e-wt-data-'.bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->tempDataDir);

        $this->projectDir = $this->mainRepoDir;
        $this->worktreeDir = '';

        (new Process(['git', 'init', '-b', 'main'], $this->mainRepoDir))->mustRun();
        (new Process(['git', 'config', 'user.email', 'test@test.com'], $this->mainRepoDir))->mustRun();
        (new Process(['git', 'config', 'user.name', 'Test'], $this->mainRepoDir))->mustRun();
        (new Process(['git', 'config', 'commit.gpgsign', 'false'], $this->mainRepoDir))->mustRun();

        // docker-compose.yml holds the hostname-bearing and DB env vars that the
        // WorktreeManager must rewrite when the project runs inside a worktree.
        // In real projects these values typically arrive via the .env migration
        // performed by project:init or are authored manually.
        $this->filesystem->dumpFile($this->mainRepoDir.'/docker-compose.yml', implode("\n", [
            'services:',
            '  web:',
            '    image: registry.whatwedo.ch/whatwedo/docker-base-images/nginx-php:v2.11',
            '    volumes:',
            '      - ./:/var/www',
            '    environment:',
            '      - DATABASE_URL=mysql://root:root@mariadb:3306/e2e_wt?serverVersion=11.8.0-MariaDB',
            '      - APP_URL=https://e2e-wt.test',
            '      - MERCURE_URL=http://mercure.e2e-wt.test/.well-known/mercure',
        ]));

        $this->filesystem->dumpFile($this->mainRepoDir.'/index.php', "<?php\necho 'WORKTREE_CONTENT';\n");

        (new Process(['git', 'add', '-A'], $this->mainRepoDir))->mustRun();
        (new Process(['git', 'commit', '-m', 'initial'], $this->mainRepoDir))->mustRun();

        $this->runConsole('system:down', timeout: 60);
        $this->cleanupLeftoverContainers();
    }

    protected function tearDown(): void
    {
        if ($this->worktreeDir !== '' && is_dir($this->worktreeDir)) {
            $this->projectDir = $this->worktreeDir;
            $this->runConsole('project:down', timeout: 60);
        }

        if ($this->mainProjectStarted) {
            $this->projectDir = $this->mainRepoDir;
            $this->runConsole('project:down', timeout: 60);
        }

        $this->projectDir = $this->mainRepoDir;
        $this->runConsole('system:down', timeout: 60);

        if ($this->worktreeDir !== '' && is_dir($this->worktreeDir)) {
            (new Process(['git', 'worktree', 'remove', '--force', $this->worktreeDir], $this->mainRepoDir))->run();
        }

        $this->filesystem->remove($this->mainRepoDir);

        if ($this->worktreeDir !== '' && is_dir($this->worktreeDir)) {
            $this->filesystem->remove($this->worktreeDir);
        }

        $this->filesystem->remove($this->tempDataDir);
    }
}
