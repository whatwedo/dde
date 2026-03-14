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

    public function testWorktreeProjectGetsSubdomain(): void
    {
        // 1. Init project in main repo
        $result = $this->runConsoleJsonInDir($this->mainRepoDir, 'project:init', [
            '--name=e2e-wt',
            '--services=mariadb',
            '--shell=bash',
            '--force',
        ]);
        $this->assertSame('ok', $result['status'], 'project:init in main should succeed');

        // 2. Commit .dde/ so worktree has it
        (new Process(['git', 'add', '-A'], $this->mainRepoDir))->mustRun();
        (new Process(['git', 'commit', '-m', 'add dde config'], $this->mainRepoDir))->mustRun();

        // 3. Create git worktree
        $worktreeBranch = 'feature-test';
        $process = new Process(['git', 'checkout', '-b', $worktreeBranch], $this->mainRepoDir);
        $process->run();
        $this->assertTrue($process->isSuccessful(), 'git checkout -b should succeed');

        // Go back to main for worktree creation
        $process = new Process(['git', 'checkout', 'main'], $this->mainRepoDir);
        $process->run();

        $this->worktreeDir = $this->mainRepoDir.'-wt-feature-test';
        $process = new Process(['git', 'worktree', 'add', $this->worktreeDir, $worktreeBranch], $this->mainRepoDir);
        $process->run();
        $this->assertTrue($process->isSuccessful(), 'git worktree add should succeed: '.$process->getErrorOutput());

        // 4. system:up
        $result = $this->runConsoleJsonInDir($this->worktreeDir, 'system:up', timeout: 180);
        $this->assertSame('ok', $result['status'], 'system:up should succeed');

        // 5. project:up from worktree
        $result = $this->runConsoleJsonInDir($this->worktreeDir, 'project:up', timeout: 180);
        $this->assertSame('ok', $result['status'], 'project:up in worktree should succeed');

        // 6. project:describe should show worktree-specific URL
        $result = $this->runConsoleJsonInDir($this->worktreeDir, 'project:describe');
        $this->assertSame('ok', $result['status']);
        // Worktree URL should follow the pattern: <project>-<suffix>.test
        $this->assertArrayHasKey('url', $result['data']);
        $url = $result['data']['url'];
        $this->assertStringContainsString('e2e-wt', $url, 'URL should contain project name');
        $this->assertStringEndsWith('.test', $url, 'URL should end with .test');
        $this->assertNotSame('https://e2e-wt.test', $url, 'URL should differ from main project URL');

        // 7. project:status should work
        $result = $this->runConsoleJsonInDir($this->worktreeDir, 'project:status');
        $this->assertSame('ok', $result['status']);
        $this->assertNotEmpty($result['data']['containers']);

        // 8. project:down
        $result = $this->runConsoleJsonInDir($this->worktreeDir, 'project:down', timeout: 60);
        $this->assertSame('ok', $result['status']);
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

        // projectDir is used by E2ETestHelper for runConsole
        $this->projectDir = $this->mainRepoDir;
        $this->worktreeDir = '';

        // Initialize git repo with a commit
        (new Process(['git', 'init', '-b', 'main'], $this->mainRepoDir))->mustRun();
        (new Process(['git', 'config', 'user.email', 'test@test.com'], $this->mainRepoDir))->mustRun();
        (new Process(['git', 'config', 'user.name', 'Test'], $this->mainRepoDir))->mustRun();
        (new Process(['git', 'config', 'commit.gpgsign', 'false'], $this->mainRepoDir))->mustRun();

        // Create docker-compose.yml
        $this->filesystem->dumpFile($this->mainRepoDir.'/docker-compose.yml', implode("\n", [
            'services:',
            '  web:',
            '    image: registry.whatwedo.ch/whatwedo/docker-base-images/nginx-php:v2.11',
            '    volumes:',
            '      - ./:/var/www',
        ]));

        $this->filesystem->dumpFile($this->mainRepoDir.'/index.php', "<?php\necho 'worktree test OK';\n");

        // Initial commit
        (new Process(['git', 'add', '-A'], $this->mainRepoDir))->mustRun();
        (new Process(['git', 'commit', '-m', 'initial'], $this->mainRepoDir))->mustRun();

        // Ensure clean state
        $this->runConsole('system:down', timeout: 60);
        $this->cleanupLeftoverContainers();
    }

    protected function tearDown(): void
    {
        // Tear down from worktree dir if it was used
        if ($this->worktreeDir !== '' && is_dir($this->worktreeDir)) {
            $this->projectDir = $this->worktreeDir;
            $this->runConsole('project:down', timeout: 60);
        }

        $this->projectDir = $this->mainRepoDir;
        $this->runConsole('system:down', timeout: 60);

        // Remove worktree first
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
