<?php

declare(strict_types=1);

namespace Tests\Unit\Manager;

use App\Config\WorktreeInfo;
use App\Database\DatabaseAdapterRegistry;
use App\Database\MariaDbAdapter;
use App\Database\PostgresAdapter;
use App\Manager\WorktreeManager;
use App\Util\ProcessFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[AllowMockObjectsWithoutExpectations]
final class WorktreeManagerTest extends TestCase
{
    private ProcessFactory&MockObject $processFactory;

    private WorktreeManager $manager;

    public function testDetectReturnsNullWhenOnlyMainWorktreeExists(): void
    {
        $this->stubGitWorktreeList("worktree /tmp/main\nbranch refs/heads/master\n");

        $result = $this->manager->detect('/tmp/main');

        $this->assertNull($result);
    }

    public function testDetectReturnsNullWhenProcessFails(): void
    {
        $process = $this->createStub(Process::class);
        $process->method('isSuccessful')->willReturn(false);
        $this->processFactory->expects($this->once())->method('create')->willReturn($process);

        $result = $this->manager->detect('/tmp/projectdir');

        $this->assertNull($result);
    }

    public function testDetectReturnsNullWhenProcessThrows(): void
    {
        $process = $this->createStub(Process::class);
        $process->method('run')->willThrowException(new \RuntimeException('git not found'));
        $this->processFactory->expects($this->once())->method('create')->willReturn($process);

        $result = $this->manager->detect('/tmp/projectdir');

        $this->assertNull($result);
    }

    public function testDetectReturnsNullForEmptyOutput(): void
    {
        $this->stubGitWorktreeList('');

        $result = $this->manager->detect('/tmp/projectdir');

        $this->assertNull($result);
    }

    public function testDetectReturnsNullWhenProjectDirIsMainWorktree(): void
    {
        $tempMain = sys_get_temp_dir().'/dde-wt-test-main-'.bin2hex(random_bytes(4));
        mkdir($tempMain);

        $this->stubGitWorktreeList(sprintf(
            "worktree %s\nbranch refs/heads/master\n\nworktree %s-wt\nbranch refs/heads/feature\n",
            $tempMain,
            $tempMain,
        ));

        $result = $this->manager->detect($tempMain, $tempMain);

        $this->assertNull($result);

        rmdir($tempMain);
    }

    public function testDetectReturnsWorktreeInfoForNonMainWorktree(): void
    {
        $tempMain = sys_get_temp_dir().'/dde-wt-test-main-'.bin2hex(random_bytes(4));
        $tempWorktree = sys_get_temp_dir().'/dde-wt-test-wt-'.bin2hex(random_bytes(4));
        mkdir($tempMain);
        mkdir($tempWorktree);

        $this->stubGitWorktreeList(sprintf(
            "worktree %s\nbranch refs/heads/master\n\nworktree %s\nbranch refs/heads/feature/xyz\n",
            $tempMain,
            $tempWorktree,
        ));

        $result = $this->manager->detect($tempWorktree, $tempWorktree);

        $this->assertInstanceOf(WorktreeInfo::class, $result);
        $this->assertSame($tempMain, $result->mainDirectory);
        $this->assertSame(realpath($tempWorktree), $result->worktreeDirectory);
        $this->assertSame('feature/xyz', $result->branch);
        $this->assertSame(basename($tempWorktree), $result->suffix);

        rmdir($tempMain);
        rmdir($tempWorktree);
    }

    public function testDetectStripsRefsHeadsPrefixFromBranch(): void
    {
        $tempMain = sys_get_temp_dir().'/dde-wt-test-main-'.bin2hex(random_bytes(4));
        $tempWorktree = sys_get_temp_dir().'/dde-wt-test-wt-'.bin2hex(random_bytes(4));
        mkdir($tempMain);
        mkdir($tempWorktree);

        $this->stubGitWorktreeList(sprintf(
            "worktree %s\nbranch refs/heads/main\n\nworktree %s\nbranch refs/heads/feature-x\n",
            $tempMain,
            $tempWorktree,
        ));

        $result = $this->manager->detect($tempWorktree, $tempWorktree);

        $this->assertInstanceOf(WorktreeInfo::class, $result);
        $this->assertSame('feature-x', $result->branch);

        rmdir($tempMain);
        rmdir($tempWorktree);
    }

    public function testDetectHandlesDetachedHeadWorktreeWithEmptyBranch(): void
    {
        $tempMain = sys_get_temp_dir().'/dde-wt-test-main-'.bin2hex(random_bytes(4));
        $tempWorktree = sys_get_temp_dir().'/dde-wt-test-wt-'.bin2hex(random_bytes(4));
        mkdir($tempMain);
        mkdir($tempWorktree);

        $this->stubGitWorktreeList(sprintf(
            "worktree %s\nbranch refs/heads/master\n\nworktree %s\nHEAD abc123def456\ndetached\n",
            $tempMain,
            $tempWorktree,
        ));

        $result = $this->manager->detect($tempWorktree, $tempWorktree);

        $this->assertInstanceOf(WorktreeInfo::class, $result);
        $this->assertSame('', $result->branch);
        $this->assertSame(realpath($tempWorktree), $result->worktreeDirectory);

        rmdir($tempMain);
        rmdir($tempWorktree);
    }

    public function testDetectFallsBackToStringMatchWhenRealpathFails(): void
    {
        // Use a project directory that exists (so detect() can realpath it), but
        // the git output references a path that does not exist on disk. This exercises
        // the pathsEqual string-match fallback.
        $tempWorktree = sys_get_temp_dir().'/dde-wt-test-wt-'.bin2hex(random_bytes(4));
        mkdir($tempWorktree);
        $realWorktree = realpath($tempWorktree);
        $this->assertNotFalse($realWorktree);

        $nonExistentMain = '/tmp/does-not-exist-main-'.bin2hex(random_bytes(4));

        // Note the trailing slash on the main path — ensures pathsEqual's rtrim('/') is exercised.
        $this->stubGitWorktreeList(sprintf(
            "worktree %s/\nbranch refs/heads/master\n\nworktree %s\nbranch refs/heads/feature\n",
            $nonExistentMain,
            $realWorktree,
        ));

        $result = $this->manager->detect($tempWorktree, $tempWorktree);

        $this->assertInstanceOf(WorktreeInfo::class, $result);
        $this->assertSame('feature', $result->branch);

        rmdir($tempWorktree);
    }

    public function testResolveHostnameWithoutWorktreeReturnsProjectTest(): void
    {
        $this->assertSame('beispiel.test', $this->manager->resolveHostname('beispiel', null));
    }

    public function testResolveHostnameWithWorktreeAppendsSuffix(): void
    {
        $info = new WorktreeInfo('/main', '/wt', 'feature/x', 'beispiel-feature-x');

        $this->assertSame('beispiel-feature-x.test', $this->manager->resolveHostname('beispiel', $info));
    }

    public function testResolveHostnameSanitizesWorktreeSuffix(): void
    {
        $info = new WorktreeInfo('/main', '/wt', 'x', 'Beispiel-PROJ/123');

        $this->assertSame('beispiel-proj-123.test', $this->manager->resolveHostname('beispiel', $info));
    }

    public function testResolveDatabaseNameWithoutWorktreeReturnsBase(): void
    {
        $this->assertSame('mydb', $this->manager->resolveDatabaseName('mydb', null, 'beispiel'));
    }

    public function testResolveDatabaseNameAppendsSuffix(): void
    {
        $info = new WorktreeInfo('/main', '/wt', 'x', 'beispiel-feature-x');

        $this->assertSame('mydb_feature_x', $this->manager->resolveDatabaseName('mydb', $info, 'beispiel'));
    }

    public function testResolveDatabaseNameTruncatesTo63CharsWithoutTrailingSeparator(): void
    {
        $longSuffix = 'beispiel-'.str_repeat('a', 70);
        $info = new WorktreeInfo('/main', '/wt', 'x', $longSuffix);

        $result = $this->manager->resolveDatabaseName('mydb', $info, 'beispiel');

        $this->assertLessThanOrEqual(63, strlen($result));
        $this->assertStringEndsNotWith('_', $result);
    }

    public function testComputeEnvironmentOverridesHostnameRewriteMapFormat(): void
    {
        $info = new WorktreeInfo('/main', '/wt', 'x', 'beispiel-feature-xyz');
        $env = [
            'APP_URL' => 'https://beispiel.test',
            'APP_SECRET' => 'abc',
        ];

        $result = $this->manager->computeEnvironmentOverrides($env, 'beispiel', $info, []);

        $this->assertSame([
            'APP_URL' => 'https://beispiel-feature-xyz.test',
        ], $result);
    }

    public function testComputeEnvironmentOverridesHostnameRewriteListFormat(): void
    {
        $info = new WorktreeInfo('/main', '/wt', 'x', 'beispiel-feature-xyz');
        $env = [
            'APP_URL=https://beispiel.test',
            'APP_SECRET=abc',
        ];

        $result = $this->manager->computeEnvironmentOverrides($env, 'beispiel', $info, []);

        $this->assertSame([
            'APP_URL' => 'https://beispiel-feature-xyz.test',
        ], $result);
    }

    public function testComputeEnvironmentOverridesDatabaseUrlRewrite(): void
    {
        $info = new WorktreeInfo('/main', '/wt', 'x', 'beispiel-feature-xyz');
        $env = [
            'DATABASE_URL' => 'mysql://root:pw@mariadb:3306/beispiel?serverVersion=11.8.0-MariaDB',
        ];

        $result = $this->manager->computeEnvironmentOverrides($env, 'beispiel', $info, ['mariadb']);

        $this->assertArrayHasKey('DATABASE_URL', $result);
        $this->assertSame(
            'mysql://root:pw@mariadb:3306/beispiel_feature_xyz?serverVersion=11.8.0-MariaDB',
            $result['DATABASE_URL'],
        );
    }

    public function testComputeEnvironmentOverridesDatabaseUrlSkipsWhenNoPath(): void
    {
        $info = new WorktreeInfo('/main', '/wt', 'x', 'beispiel-feature-xyz');
        $env = [
            'DATABASE_URL' => 'mysql://root@mariadb:3306',
        ];

        $result = $this->manager->computeEnvironmentOverrides($env, 'beispiel', $info, ['mariadb']);

        $this->assertArrayNotHasKey('DATABASE_URL', $result);
    }

    public function testComputeEnvironmentOverridesDatabaseUrlSkipsWhenEmptyPath(): void
    {
        $info = new WorktreeInfo('/main', '/wt', 'x', 'beispiel-feature-xyz');
        $env = [
            'DATABASE_URL' => 'mysql://root@mariadb:3306/',
        ];

        $result = $this->manager->computeEnvironmentOverrides($env, 'beispiel', $info, ['mariadb']);

        $this->assertArrayNotHasKey('DATABASE_URL', $result);
    }

    public function testComputeEnvironmentOverridesDatabaseUrlSkipsNonUrlValue(): void
    {
        $info = new WorktreeInfo('/main', '/wt', 'x', 'beispiel-feature-xyz');
        $env = [
            'DATABASE_URL' => 'not a url',
        ];

        $result = $this->manager->computeEnvironmentOverrides($env, 'beispiel', $info, ['mariadb']);

        $this->assertArrayNotHasKey('DATABASE_URL', $result);
    }

    public function testComputeEnvironmentOverridesDatabaseUrlPreservesPercentEncoding(): void
    {
        $info = new WorktreeInfo('/main', '/wt', 'x', 'beispiel-feature');
        $env = [
            'DATABASE_URL' => 'mysql://user:p%40ss@mariadb:3306/mydb?opt=1',
        ];

        $result = $this->manager->computeEnvironmentOverrides($env, 'beispiel', $info, ['mariadb']);

        $this->assertSame(
            'mysql://user:p%40ss@mariadb:3306/mydb_feature?opt=1',
            $result['DATABASE_URL'],
        );
    }

    public function testComputeEnvironmentOverridesDatabaseUrlTruncatesOver63Chars(): void
    {
        $info = new WorktreeInfo('/main', '/wt', 'x', 'beispiel-'.str_repeat('a', 100));
        $env = [
            'DATABASE_URL' => 'mysql://root@mariadb:3306/'.str_repeat('b', 40),
        ];

        $result = $this->manager->computeEnvironmentOverrides($env, 'beispiel', $info, ['mariadb']);

        $this->assertArrayHasKey('DATABASE_URL', $result);
        // Path segment between "@mariadb:3306/" and next "?" or end must be <= 63
        $matched = preg_match('#/([^/?]+)(?:\?.*)?$#', $result['DATABASE_URL'], $m);
        $this->assertSame(1, $matched, 'Expected a path segment in the DATABASE_URL');
        $this->assertLessThanOrEqual(63, strlen($m[1]));
        $this->assertStringEndsNotWith('_', $m[1]);
    }

    public function testComputeEnvironmentOverridesAppliesHostnameRewriteWithoutDatabaseRewriteForExternalDbHost(): void
    {
        $info = new WorktreeInfo('/main', '/wt', 'x', 'beispiel-feature-xyz');
        // URL host is the project's public domain, not a dde-managed DB alias.
        // The hostname rewrite still fires (so URL points at the worktree's
        // public hostname), but the DB-name rewrite is skipped — the URL is
        // not pointing at the dde-managed mariadb container.
        $env = [
            'DATABASE_URL' => 'mysql://root@beispiel.test:3306/beispiel',
        ];

        $result = $this->manager->computeEnvironmentOverrides($env, 'beispiel', $info, ['mariadb']);

        $this->assertSame(
            'mysql://root@beispiel-feature-xyz.test:3306/beispiel',
            $result['DATABASE_URL'],
        );
    }

    public function testComputeEnvironmentOverridesRewritesNonDatabaseUrlEnvKeyByScheme(): void
    {
        $info = new WorktreeInfo('/main', '/wt', 'x', 'beispiel-feature-xyz');
        $env = [
            'GUACAMOLE_DATABASE_URL' => 'postgresql://user:pw@postgres:5432/guacamole_db?sslmode=disable',
        ];

        $result = $this->manager->computeEnvironmentOverrides($env, 'beispiel', $info, ['postgres']);

        $this->assertSame(
            'postgresql://user:pw@postgres:5432/guacamole_db_feature_xyz?sslmode=disable',
            $result['GUACAMOLE_DATABASE_URL'],
        );
    }

    public function testComputeEnvironmentOverridesRewritesMultipleDatabaseUrls(): void
    {
        $info = new WorktreeInfo('/main', '/wt', 'x', 'beispiel-feature-xyz');
        $env = [
            'DATABASE_URL' => 'mysql://root:pw@mariadb:3306/app',
            'GUACAMOLE_DATABASE_URL' => 'mariadb://root:pw@mariadb:3306/guac',
            'APP_SECRET' => 'leave-me-alone',
        ];

        $result = $this->manager->computeEnvironmentOverrides($env, 'beispiel', $info, ['mariadb']);

        $this->assertSame([
            'DATABASE_URL' => 'mysql://root:pw@mariadb:3306/app_feature_xyz',
            'GUACAMOLE_DATABASE_URL' => 'mariadb://root:pw@mariadb:3306/guac_feature_xyz',
        ], $result);
    }

    public function testComputeEnvironmentOverridesSkipsDatabaseUrlWhenNoDatabaseServiceConfigured(): void
    {
        $info = new WorktreeInfo('/main', '/wt', 'x', 'beispiel-feature-xyz');
        $env = [
            'DATABASE_URL' => 'mysql://root@db:3306/beispiel',
        ];

        $result = $this->manager->computeEnvironmentOverrides($env, 'beispiel', $info, []);

        $this->assertArrayNotHasKey('DATABASE_URL', $result);
    }

    public function testComputeEnvironmentOverridesSkipsDatabaseUrlWhenSchemeDoesNotMatchConfiguredService(): void
    {
        $info = new WorktreeInfo('/main', '/wt', 'x', 'beispiel-feature-xyz');
        // mysql:// implies a MariaDB/MySQL backend, but only postgres is configured
        // — the URL must point at an external DB and must not be rewritten.
        $env = [
            'DATABASE_URL' => 'mysql://root@external-db:3306/external',
        ];

        $result = $this->manager->computeEnvironmentOverrides($env, 'beispiel', $info, ['postgres']);

        $this->assertArrayNotHasKey('DATABASE_URL', $result);
    }

    public function testComputeEnvironmentOverridesSkipsDatabaseUrlForExternalHostOfSameEngine(): void
    {
        $info = new WorktreeInfo('/main', '/wt', 'x', 'beispiel-feature-xyz');
        // Project has a dde-managed postgres, but this URL points at an
        // external analytics DB. The host (`external.example`) does not match
        // any dde-managed service alias, so the URL must pass through unchanged
        // — otherwise the worktree would silently redirect analytics writes to
        // a non-existent `<db>_<suffix>` on the external server.
        $env = [
            'ANALYTICS_DATABASE_URL' => 'postgresql://readonly@external.example/analytics',
        ];

        $result = $this->manager->computeEnvironmentOverrides($env, 'beispiel', $info, ['postgres']);

        $this->assertArrayNotHasKey('ANALYTICS_DATABASE_URL', $result);
    }

    public function testComputeEnvironmentOverridesRewritesDatabaseUrlForManagedAliasHost(): void
    {
        $info = new WorktreeInfo('/main', '/wt', 'x', 'beispiel-feature-xyz');
        $env = [
            'DATABASE_URL' => 'postgresql://postgres:postgres@postgres:5432/beispiel',
        ];

        $result = $this->manager->computeEnvironmentOverrides($env, 'beispiel', $info, ['postgres']);

        $this->assertSame(
            'postgresql://postgres:postgres@postgres:5432/beispiel_feature_xyz',
            $result['DATABASE_URL'],
        );
    }

    public function testComputeEnvironmentOverridesIgnoresUnsupportedScheme(): void
    {
        $info = new WorktreeInfo('/main', '/wt', 'x', 'beispiel-feature-xyz');
        $env = [
            'REDIS_URL' => 'redis://cache:6379/0',
        ];

        $result = $this->manager->computeEnvironmentOverrides($env, 'beispiel', $info, ['mariadb', 'postgres']);

        $this->assertArrayNotHasKey('REDIS_URL', $result);
    }

    public function testRewriteHostnameRewritesProjectHost(): void
    {
        $info = new WorktreeInfo('/main', '/wt', 'x', 'beispiel-feature');

        $this->assertSame('beispiel-feature.test', $this->manager->rewriteHostname('beispiel.test', 'beispiel', $info));
    }

    public function testRewriteHostnameRewritesSubdomain(): void
    {
        $info = new WorktreeInfo('/main', '/wt', 'x', 'beispiel-feature');

        $this->assertSame(
            'preview.beispiel-feature.test',
            $this->manager->rewriteHostname('preview.beispiel.test', 'beispiel', $info),
        );
    }

    public function testRewriteHostnamePassesThroughUnrelatedHost(): void
    {
        $info = new WorktreeInfo('/main', '/wt', 'x', 'beispiel-feature');

        $this->assertSame(
            'partner-api.example.com',
            $this->manager->rewriteHostname('partner-api.example.com', 'beispiel', $info),
        );
    }

    public function testRewriteHostnamePassesThroughLookalikeHost(): void
    {
        $info = new WorktreeInfo('/main', '/wt', 'x', 'beispiel-feature');

        // Suffix must be `.beispiel.test`, not just contain `beispiel.test`.
        $this->assertSame(
            'notbeispiel.test',
            $this->manager->rewriteHostname('notbeispiel.test', 'beispiel', $info),
        );
    }

    public function testRewriteExtraHostsRewritesListFormProjectSubdomain(): void
    {
        $info = new WorktreeInfo('/main', '/wt', 'x', 'beispiel-feature');
        $hosts = ['preview.beispiel.test:host-gateway'];

        $result = $this->manager->rewriteExtraHosts($hosts, 'beispiel', $info);

        $this->assertSame(['preview.beispiel-feature.test:host-gateway'], $result);
    }

    public function testRewriteExtraHostsRewritesListFormBareProjectHost(): void
    {
        $info = new WorktreeInfo('/main', '/wt', 'x', 'beispiel-feature');
        $hosts = ['beispiel.test:host-gateway'];

        $result = $this->manager->rewriteExtraHosts($hosts, 'beispiel', $info);

        $this->assertSame(['beispiel-feature.test:host-gateway'], $result);
    }

    public function testRewriteExtraHostsAcceptsEqualsSeparator(): void
    {
        // Compose v2.24+ recommends `host=ip` to disambiguate IPv6 values.
        $info = new WorktreeInfo('/main', '/wt', 'x', 'beispiel-feature');
        $hosts = ['preview.beispiel.test=host-gateway'];

        $result = $this->manager->rewriteExtraHosts($hosts, 'beispiel', $info);

        $this->assertSame(['preview.beispiel-feature.test=host-gateway'], $result);
    }

    public function testRewriteExtraHostsRewritesMapForm(): void
    {
        $info = new WorktreeInfo('/main', '/wt', 'x', 'beispiel-feature');
        $hosts = [
            'preview.beispiel.test' => 'host-gateway',
        ];

        $result = $this->manager->rewriteExtraHosts($hosts, 'beispiel', $info);

        $this->assertSame(['preview.beispiel-feature.test:host-gateway'], $result);
    }

    public function testRewriteExtraHostsKeepsUnrelatedEntries(): void
    {
        $info = new WorktreeInfo('/main', '/wt', 'x', 'beispiel-feature');
        $hosts = [
            'preview.beispiel.test:host-gateway',
            'partner-api.example.com:1.2.3.4',
        ];

        $result = $this->manager->rewriteExtraHosts($hosts, 'beispiel', $info);

        $this->assertSame([
            'preview.beispiel-feature.test:host-gateway',
            'partner-api.example.com:1.2.3.4',
        ], $result);
    }

    public function testRewriteExtraHostsReturnsNullWhenNothingChanged(): void
    {
        $info = new WorktreeInfo('/main', '/wt', 'x', 'beispiel-feature');
        $hosts = ['partner-api.example.com:1.2.3.4'];

        $result = $this->manager->rewriteExtraHosts($hosts, 'beispiel', $info);

        $this->assertNull($result);
    }

    public function testRewriteExtraHostsReturnsNullForEmptyInput(): void
    {
        $info = new WorktreeInfo('/main', '/wt', 'x', 'beispiel-feature');

        $this->assertNull($this->manager->rewriteExtraHosts([], 'beispiel', $info));
    }

    public function testRewriteExtraHostsRewritesMultipleSubdomains(): void
    {
        $info = new WorktreeInfo('/main', '/wt', 'x', 'beispiel-feature');
        $hosts = [
            'preview.beispiel.test:host-gateway',
            'admin.beispiel.test:host-gateway',
        ];

        $result = $this->manager->rewriteExtraHosts($hosts, 'beispiel', $info);

        $this->assertSame([
            'preview.beispiel-feature.test:host-gateway',
            'admin.beispiel-feature.test:host-gateway',
        ], $result);
    }

    public function testDetectReturnsWorktreeInfoWhenCwdIsInsideNestedWorktreeWithoutDdeDir(): void
    {
        // Models the real-world setup where the .dde/ directory only lives in
        // the main checkout and a worktree is nested inside it (e.g. under
        // .claude/worktrees/<name>). The walk-up logic in ProjectConfigManager
        // finds the main's .dde/ first, so $projectDir is the main, not the
        // worktree. detect() must use the actual CWD (passed explicitly here)
        // to discover that we are physically inside a worktree.
        $tempMain = sys_get_temp_dir().'/dde-wt-test-main-'.bin2hex(random_bytes(4));
        $tempWorktree = $tempMain.'/.claude/worktrees/inner';
        mkdir($tempMain);
        mkdir($tempWorktree, 0o777, true);

        $this->stubGitWorktreeList(sprintf(
            "worktree %s\nbranch refs/heads/master\n\nworktree %s\nbranch refs/heads/feature/x\n",
            $tempMain,
            $tempWorktree,
        ));

        $result = $this->manager->detect($tempMain, $tempWorktree);

        $this->assertInstanceOf(WorktreeInfo::class, $result);
        $this->assertSame($tempMain, $result->mainDirectory);
        $this->assertSame(realpath($tempWorktree), $result->worktreeDirectory);
        $this->assertSame('feature/x', $result->branch);
        $this->assertSame('inner', $result->suffix);

        rmdir($tempWorktree);
        rmdir($tempMain.'/.claude/worktrees');
        rmdir($tempMain.'/.claude');
        rmdir($tempMain);
    }

    public function testDetectReturnsNullWhenCwdIsInsideMainEvenIfWorktreesExist(): void
    {
        $tempMain = sys_get_temp_dir().'/dde-wt-test-main-'.bin2hex(random_bytes(4));
        $tempWorktree = $tempMain.'/.claude/worktrees/inner';
        mkdir($tempMain);
        mkdir($tempWorktree, 0o777, true);

        $this->stubGitWorktreeList(sprintf(
            "worktree %s\nbranch refs/heads/master\n\nworktree %s\nbranch refs/heads/feature/x\n",
            $tempMain,
            $tempWorktree,
        ));

        // CWD points at a sibling of the worktree but inside main — must not
        // trigger worktree detection just because nested worktrees exist.
        $cwdInsideMain = $tempMain;

        $result = $this->manager->detect($tempMain, $cwdInsideMain);

        $this->assertNull($result);

        rmdir($tempWorktree);
        rmdir($tempMain.'/.claude/worktrees');
        rmdir($tempMain.'/.claude');
        rmdir($tempMain);
    }

    private function stubGitWorktreeList(string $output): void
    {
        $process = $this->createStub(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $process->method('getOutput')->willReturn($output);

        $this->processFactory
            ->expects($this->once())
            ->method('create')
            ->with(
                $this->equalTo(['git', 'worktree', 'list', '--porcelain']),
                $this->anything(),
                $this->anything(),
            )
            ->willReturn($process);
    }

    protected function setUp(): void
    {
        $this->processFactory = $this->createMock(ProcessFactory::class);
        $this->manager = new WorktreeManager(
            $this->processFactory,
            new DatabaseAdapterRegistry([new MariaDbAdapter(), new PostgresAdapter()]),
        );
    }
}
