<?php

declare(strict_types=1);

namespace Tests\Unit\Manager;

use App\Config\WorktreeInfo;
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

        $result = $this->manager->detect($tempMain);

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

        $result = $this->manager->detect($tempWorktree);

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

        $result = $this->manager->detect($tempWorktree);

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

        $result = $this->manager->detect($tempWorktree);

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

        $result = $this->manager->detect($tempWorktree);

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

        $result = $this->manager->computeEnvironmentOverrides($env, 'beispiel', $info);

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

        $result = $this->manager->computeEnvironmentOverrides($env, 'beispiel', $info);

        $this->assertSame([
            'APP_URL' => 'https://beispiel-feature-xyz.test',
        ], $result);
    }

    public function testComputeEnvironmentOverridesDatabaseUrlRewrite(): void
    {
        $info = new WorktreeInfo('/main', '/wt', 'x', 'beispiel-feature-xyz');
        $env = [
            'DATABASE_URL' => 'mysql://root:pw@db:3306/beispiel?serverVersion=11.8.0-MariaDB',
        ];

        $result = $this->manager->computeEnvironmentOverrides($env, 'beispiel', $info);

        $this->assertArrayHasKey('DATABASE_URL', $result);
        $this->assertSame(
            'mysql://root:pw@db:3306/beispiel_feature_xyz?serverVersion=11.8.0-MariaDB',
            $result['DATABASE_URL'],
        );
    }

    public function testComputeEnvironmentOverridesDatabaseUrlSkipsWhenNoPath(): void
    {
        $info = new WorktreeInfo('/main', '/wt', 'x', 'beispiel-feature-xyz');
        $env = [
            'DATABASE_URL' => 'mysql://root@db:3306',
        ];

        $result = $this->manager->computeEnvironmentOverrides($env, 'beispiel', $info);

        $this->assertArrayNotHasKey('DATABASE_URL', $result);
    }

    public function testComputeEnvironmentOverridesDatabaseUrlSkipsWhenEmptyPath(): void
    {
        $info = new WorktreeInfo('/main', '/wt', 'x', 'beispiel-feature-xyz');
        $env = [
            'DATABASE_URL' => 'mysql://root@db:3306/',
        ];

        $result = $this->manager->computeEnvironmentOverrides($env, 'beispiel', $info);

        $this->assertArrayNotHasKey('DATABASE_URL', $result);
    }

    public function testComputeEnvironmentOverridesDatabaseUrlSkipsNonUrlValue(): void
    {
        $info = new WorktreeInfo('/main', '/wt', 'x', 'beispiel-feature-xyz');
        $env = [
            'DATABASE_URL' => 'not a url',
        ];

        $result = $this->manager->computeEnvironmentOverrides($env, 'beispiel', $info);

        $this->assertArrayNotHasKey('DATABASE_URL', $result);
    }

    public function testComputeEnvironmentOverridesDatabaseUrlPreservesPercentEncoding(): void
    {
        $info = new WorktreeInfo('/main', '/wt', 'x', 'beispiel-feature');
        $env = [
            'DATABASE_URL' => 'mysql://user:p%40ss@db:3306/mydb?opt=1',
        ];

        $result = $this->manager->computeEnvironmentOverrides($env, 'beispiel', $info);

        $this->assertSame(
            'mysql://user:p%40ss@db:3306/mydb_feature?opt=1',
            $result['DATABASE_URL'],
        );
    }

    public function testComputeEnvironmentOverridesDatabaseUrlTruncatesOver63Chars(): void
    {
        $info = new WorktreeInfo('/main', '/wt', 'x', 'beispiel-'.str_repeat('a', 100));
        $env = [
            'DATABASE_URL' => 'mysql://root@db:3306/'.str_repeat('b', 40),
        ];

        $result = $this->manager->computeEnvironmentOverrides($env, 'beispiel', $info);

        $this->assertArrayHasKey('DATABASE_URL', $result);
        // Path segment between "@db:3306/" and next "?" or end must be <= 63
        $matched = preg_match('#/([^/?]+)(?:\?.*)?$#', $result['DATABASE_URL'], $m);
        $this->assertSame(1, $matched, 'Expected a path segment in the DATABASE_URL');
        $this->assertLessThanOrEqual(63, strlen($m[1]));
        $this->assertStringEndsNotWith('_', $m[1]);
    }

    public function testComputeEnvironmentOverridesCombinesHostnameAndDatabaseUrlRewrite(): void
    {
        $info = new WorktreeInfo('/main', '/wt', 'x', 'beispiel-feature-xyz');
        $env = [
            'DATABASE_URL' => 'mysql://root@beispiel.test:3306/beispiel',
        ];

        $result = $this->manager->computeEnvironmentOverrides($env, 'beispiel', $info);

        $this->assertSame(
            'mysql://root@beispiel-feature-xyz.test:3306/beispiel_feature_xyz',
            $result['DATABASE_URL'],
        );
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
        $this->manager = new WorktreeManager($this->processFactory);
    }
}
