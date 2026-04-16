<?php

declare(strict_types=1);

namespace Tests\Unit\Manager;

use App\Config\ProjectConfig;
use App\Config\ResolvedConfig;
use App\Config\WorktreeInfo;
use App\Database\DatabaseAdapterRegistry;
use App\Manager\GlobalConfigManager;
use App\Manager\ProjectConfigManager;
use App\Service\ServiceRegistry;
use App\Util\ProcessFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ProjectConfigManagerTest extends TestCase
{
    private string|false $originalCwd;

    /**
     * @var list<string>
     */
    private array $tempFiles = [];

    /**
     * @var list<string>
     */
    private array $tempDirs = [];

    // --- loadProjectConfig tests ---

    public function testLoadProjectConfigParsesFile(): void
    {
        $projectDir = $this->createTempDir();
        $configDir = $projectDir.'/.dde';
        mkdir($configDir, 0o755, true);
        $this->tempDirs[] = $configDir;

        $configPath = $configDir.'/config.yml';
        $yaml = <<<'YAML'
            name: my-project
            services:
                - mariadb
            YAML;
        file_put_contents($configPath, $yaml);
        $this->tempFiles[] = $configPath;

        $manager = $this->createManager();
        $config = $manager->loadProjectConfig($projectDir);

        $this->assertSame('my-project', $config->name);
        $this->assertCount(1, $config->services);
        $this->assertSame('mariadb', $config->services[0]->name);
    }

    public function testLoadProjectConfigReturnsDefaultWhenMissing(): void
    {
        $manager = $this->createManager();
        $config = $manager->loadProjectConfig('/nonexistent/project/dir');

        $this->assertSame('', $config->name);
        $this->assertSame([], $config->services);
        $this->assertSame([], $config->containers);
    }

    public function testLoadProjectConfigWithMalformedYamlThrowsRuntimeException(): void
    {
        $projectDir = $this->createTempDir();
        $ddeDir = $projectDir.'/.dde';
        mkdir($ddeDir, 0o755, true);
        $this->tempDirs[] = $ddeDir;

        $configPath = $ddeDir.'/config.yml';
        file_put_contents($configPath, 'invalid: yaml: content: [broken');
        $this->tempFiles[] = $configPath;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Invalid YAML/');

        $this->createManager()->loadProjectConfig($projectDir);
    }

    public function testLoadProjectConfigWithEmptyFileReturnsDefaults(): void
    {
        $projectDir = $this->createTempDir();
        $ddeDir = $projectDir.'/.dde';
        mkdir($ddeDir, 0o755, true);
        $this->tempDirs[] = $ddeDir;

        $configPath = $ddeDir.'/config.yml';
        file_put_contents($configPath, '');
        $this->tempFiles[] = $configPath;

        $manager = $this->createManager();
        $config = $manager->loadProjectConfig($projectDir);

        $this->assertInstanceOf(ProjectConfig::class, $config);
        $this->assertSame('', $config->name);
        $this->assertSame([], $config->services);
        $this->assertSame([], $config->containers);
    }

    public function testLoadProjectConfigWithUnknownKeysThrowsException(): void
    {
        $projectDir = $this->createTempDir();
        $ddeDir = $projectDir.'/.dde';
        mkdir($ddeDir, 0o755, true);
        $this->tempDirs[] = $ddeDir;

        $configPath = $ddeDir.'/config.yml';
        file_put_contents($configPath, "unknown_key: value\n");
        $this->tempFiles[] = $configPath;

        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        $this->createManager()->loadProjectConfig($projectDir);
    }

    // --- resolveConfig tests ---

    public function testResolveConfigMergesGlobalAndProject(): void
    {
        $globalConfigDir = $this->createTempDir();
        file_put_contents($globalConfigDir.'/config.yml', "output: json\n");
        $this->tempFiles[] = $globalConfigDir.'/config.yml';

        $projectDir = $this->createTempDir();
        $ddeDir = $projectDir.'/.dde';
        mkdir($ddeDir, 0o755, true);
        $this->tempDirs[] = $ddeDir;

        file_put_contents($ddeDir.'/config.yml', "name: test-app\n");
        $this->tempFiles[] = $ddeDir.'/config.yml';

        $manager = $this->createManager(globalConfigDir: $globalConfigDir);
        $resolved = $manager->resolveConfig($projectDir);

        $this->assertInstanceOf(ResolvedConfig::class, $resolved);
        $this->assertSame('json', $resolved->output);
        $this->assertSame('test-app', $resolved->projectName);
    }

    public function testResolveConfigIncludesDefaultServiceVersions(): void
    {
        $projectDir = $this->createTempDir();
        $ddeDir = $projectDir.'/.dde';
        mkdir($ddeDir, 0o755, true);
        $this->tempDirs[] = $ddeDir;
        file_put_contents($ddeDir.'/config.yml', "name: test\n");
        $this->tempFiles[] = $ddeDir.'/config.yml';

        $manager = $this->createManager();
        $resolved = $manager->resolveConfig($projectDir);

        // Default versions from ServiceRegistry must be present
        $this->assertArrayHasKey('mariadb', $resolved->serviceVersions);
        $this->assertArrayHasKey('postgres', $resolved->serviceVersions);
    }

    // --- findProjectDirectory tests ---

    public function testFindProjectDirectoryFindsDdeConfig(): void
    {
        $projectDir = $this->createTempDir();
        $ddeDir = $projectDir.'/.dde';
        mkdir($ddeDir, 0o755, true);
        $this->tempDirs[] = $ddeDir;

        $configPath = $ddeDir.'/config.yml';
        file_put_contents($configPath, 'name: test');
        $this->tempFiles[] = $configPath;

        chdir($projectDir);

        $result = $this->createManager()->findProjectDirectory();
        $this->assertSame($projectDir, $result);
    }

    public function testFindProjectDirectoryReturnsNullWhenNotFound(): void
    {
        $emptyDir = $this->createTempDir();
        chdir($emptyDir);

        $result = $this->createManager()->findProjectDirectory();
        $this->assertNull($result);
    }

    public function testFindProjectDirectoryDoesNotMatchDockerCompose(): void
    {
        $projectDir = $this->createTempDir();
        $subDir = $projectDir.'/src/deep';
        mkdir($subDir, 0o755, true);
        $this->tempDirs[] = $projectDir.'/src/deep';
        $this->tempDirs[] = $projectDir.'/src';

        file_put_contents($projectDir.'/docker-compose.yml', 'version: "3"');
        $this->tempFiles[] = $projectDir.'/docker-compose.yml';

        chdir($subDir);

        $result = $this->createManager()->findProjectDirectory();
        $this->assertNull($result);
    }

    // --- findDockerProjectDirectory tests ---

    public function testFindDockerProjectDirectoryFindsDockerCompose(): void
    {
        $projectDir = $this->createTempDir();
        $subDir = $projectDir.'/src/deep';
        mkdir($subDir, 0o755, true);
        $this->tempDirs[] = $projectDir.'/src/deep';
        $this->tempDirs[] = $projectDir.'/src';

        file_put_contents($projectDir.'/docker-compose.yml', 'version: "3"');
        $this->tempFiles[] = $projectDir.'/docker-compose.yml';

        chdir($subDir);

        $result = $this->createManager()->findDockerProjectDirectory();
        $this->assertSame($projectDir, $result);
    }

    public function testFindDockerProjectDirectoryReturnsNullWhenNotFound(): void
    {
        $emptyDir = $this->createTempDir();
        chdir($emptyDir);

        $result = $this->createManager()->findDockerProjectDirectory();
        $this->assertNull($result);
    }

    // --- sanitizeWorktreeSuffix tests ---

    public function testSanitizeSubdomainStripsProjectPrefix(): void
    {
        $this->assertSame('feature-x', $this->createManager()->sanitizeWorktreeSuffix('beispiel-feature-x', 'beispiel'));
    }

    public function testSanitizeSubdomainLowercases(): void
    {
        $this->assertSame('proj-123', $this->createManager()->sanitizeWorktreeSuffix('beispiel-PROJ-123', 'beispiel'));
    }

    public function testSanitizeSubdomainReplacesSpecialChars(): void
    {
        $this->assertSame('special-chars', $this->createManager()->sanitizeWorktreeSuffix('beispiel-special_chars!@#', 'beispiel'));
    }

    public function testSanitizeSubdomainEmptySuffixFallback(): void
    {
        $this->assertSame('worktree', $this->createManager()->sanitizeWorktreeSuffix('beispiel-', 'beispiel'));
    }

    public function testSanitizeSubdomainDirNameSameAsProject(): void
    {
        $this->assertSame('worktree', $this->createManager()->sanitizeWorktreeSuffix('beispiel', 'beispiel'));
    }

    public function testSanitizeSubdomainWithoutProjectPrefix(): void
    {
        $this->assertSame('other-dir', $this->createManager()->sanitizeWorktreeSuffix('other-dir', 'beispiel'));
    }

    public function testSanitizeSubdomainTruncatesLongNames(): void
    {
        $longSuffix = str_repeat('a', 100);
        $result = $this->createManager()->sanitizeWorktreeSuffix('beispiel-'.$longSuffix, 'beispiel');

        $this->assertLessThanOrEqual(63, strlen($result));
        $this->assertStringStartsNotWith('-', $result);
    }

    public function testSanitizeSubdomainCaseInsensitivePrefixRemoval(): void
    {
        $this->assertSame('feature', $this->createManager()->sanitizeWorktreeSuffix('Beispiel-feature', 'beispiel'));
    }

    public function testSanitizeSubdomainCollapsesConsecutiveHyphens(): void
    {
        $this->assertSame('foo-bar', $this->createManager()->sanitizeWorktreeSuffix('beispiel-foo---bar', 'beispiel'));
    }

    public function testSanitizeSubdomainTrimsTrailingHyphensAfterTruncation(): void
    {
        $suffix = str_repeat('a', 60).'---bbb';
        $result = $this->createManager()->sanitizeWorktreeSuffix($suffix, 'nonmatch');

        $this->assertLessThanOrEqual(63, strlen($result));
        $this->assertFalse(str_ends_with($result, '-'));
    }

    public function testSanitizeSubdomainHandlesUnicode(): void
    {
        $this->assertSame('uberarbeitung', $this->createManager()->sanitizeWorktreeSuffix('beispiel-überarbeitung', 'beispiel'));
    }

    public function testSanitizeSubdomainPureSpecialCharsFallback(): void
    {
        $this->assertSame('worktree', $this->createManager()->sanitizeWorktreeSuffix('beispiel-!!!', 'beispiel'));
    }

    // --- resolveProjectHostname tests ---

    public function testResolveProjectHostnameMainCheckout(): void
    {
        $this->assertSame('beispiel.test', $this->createManager()->resolveProjectHostname('beispiel', null));
    }

    public function testResolveProjectHostnameWorktree(): void
    {
        $worktreeInfo = new WorktreeInfo(
            mainDirectory: '/main',
            worktreeDirectory: '/wt',
            branch: 'feature-x',
            suffix: 'beispiel-feature-x',
        );

        $this->assertSame('beispiel-feature-x.test', $this->createManager()->resolveProjectHostname('beispiel', $worktreeInfo));
    }

    // --- detectWorktree tests ---

    #[Group('e2e')]
    public function testDetectWorktreeReturnsNullForMainCheckout(): void
    {
        $mainDir = $this->createGitRepo();

        $result = $this->createManager()->detectWorktree($mainDir);
        $this->assertNull($result);
    }

    #[Group('e2e')]
    public function testDetectWorktreeReturnsInfoForWorktree(): void
    {
        $mainDir = $this->createGitRepo();
        $worktreeDir = $this->createWorktree($mainDir, 'feature-branch');

        $result = $this->createManager()->detectWorktree($worktreeDir);

        $this->assertInstanceOf(WorktreeInfo::class, $result);
        $this->assertSame(realpath($mainDir), $result->mainDirectory);
        $this->assertSame(realpath($worktreeDir), $result->worktreeDirectory);
        $this->assertSame('feature-branch', $result->branch);
        $this->assertSame(basename($worktreeDir), $result->suffix);
    }

    #[Group('e2e')]
    public function testDetectWorktreeReturnsNullForNonGitDirectory(): void
    {
        $tmpDir = $this->createTempDir();

        $result = $this->createManager()->detectWorktree($tmpDir);
        $this->assertNull($result);
    }

    // --- helper methods ---

    private function createManager(string $globalConfigDir = '/tmp/nonexistent-dde-config'): ProjectConfigManager
    {
        return new ProjectConfigManager(
            globalConfigManager: new GlobalConfigManager(configDir: $globalConfigDir),
            serviceRegistry: new ServiceRegistry([], new DatabaseAdapterRegistry([])),
            processFactory: new ProcessFactory(),
        );
    }

    private function createTempDir(): string
    {
        $baseDir = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
        $dir = $baseDir.'/dde_pcm_test_'.bin2hex(random_bytes(8));
        mkdir($dir, 0o755, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function createGitRepo(): string
    {
        $dir = $this->createTempDir();

        $this->runProcess(['git', 'init'], $dir);
        $this->runProcess(['git', 'config', 'user.email', 'test@example.com'], $dir);
        $this->runProcess(['git', 'config', 'user.name', 'Test'], $dir);

        file_put_contents($dir.'/README.md', 'test');
        $this->runProcess(['git', 'add', '.'], $dir);
        $this->runProcess(['git', 'commit', '-m', 'initial commit', '--no-gpg-sign'], $dir);

        return $dir;
    }

    private function createWorktree(string $mainDir, string $branchName): string
    {
        $baseDir = realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
        $worktreeDir = $baseDir.'/dde_wt_test_'.bin2hex(random_bytes(8));
        $this->tempDirs[] = $worktreeDir;

        $this->runProcess(['git', 'worktree', 'add', '-b', $branchName, $worktreeDir], $mainDir);

        return $worktreeDir;
    }

    /**
     * @param list<string> $command
     */
    private function runProcess(array $command, string $cwd): void
    {
        $process = new Process($command, $cwd);
        $process->mustRun();
    }

    private function removeDirectory(string $dir): void
    {
        $gitDir = $dir.'/.git';
        if (is_file($gitDir)) {
            $content = file_get_contents($gitDir);
            if ($content !== false && preg_match('/gitdir: (.+)/', $content, $matches)) {
                $worktreeGitDir = dirname(trim($matches[1]));
                $mainGitDir = dirname($worktreeGitDir);
                if (is_dir($mainGitDir)) {
                    $process = new Process(['git', 'worktree', 'remove', '--force', $dir], dirname($mainGitDir));
                    $process->run();

                    return;
                }
            }
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }

        @rmdir($dir);
    }

    protected function setUp(): void
    {
        $cwd = getcwd();
        $this->originalCwd = $cwd;
    }

    protected function tearDown(): void
    {
        if ($this->originalCwd !== false) {
            chdir($this->originalCwd);
        }

        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        foreach (array_reverse($this->tempDirs) as $dir) {
            if (is_dir($dir)) {
                $this->removeDirectory($dir);
            }
        }

        $this->tempFiles = [];
        $this->tempDirs = [];
    }
}
