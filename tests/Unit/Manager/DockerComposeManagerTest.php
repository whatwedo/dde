<?php

declare(strict_types=1);

namespace Tests\Unit\Manager;

use App\Adapter\AdapterRegistry;
use App\Config\GlobalConfig;
use App\Config\ProjectConfig;
use App\Config\ResolvedConfig;
use App\Config\WorktreeInfo;
use App\Manager\DockerComposeManager;
use App\Manager\DockerManager;
use App\Manager\ProjectConfigManager;
use App\Model\UserContext;
use App\Service\TraefikService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class DockerComposeManagerTest extends TestCase
{
    private DockerComposeManager $manager;

    private string $tempDir;

    public function testExecReturnsProcessWithCorrectCommand(): void
    {
        $process = $this->manager->exec('/tmp', 'web', ['whoami']);

        $commandLine = $process->getCommandLine();

        $this->assertStringContainsString('exec', $commandLine);
        $this->assertStringContainsString('web', $commandLine);
        $this->assertStringContainsString('whoami', $commandLine);
    }

    public function testExecWithUserOption(): void
    {
        $process = $this->manager->exec('/tmp', 'web', ['ls'], [
            'user' => 'dde',
        ]);

        $commandLine = $process->getCommandLine();

        $this->assertStringContainsString('-u', $commandLine);
        $this->assertStringContainsString('dde', $commandLine);
    }

    public function testExecWithNoTtyOption(): void
    {
        $process = $this->manager->exec('/tmp', 'web', ['ls'], [
            'noTty' => true,
        ]);

        $commandLine = $process->getCommandLine();

        $this->assertStringContainsString('--no-TTY', $commandLine);
    }

    public function testExecWithEnvOption(): void
    {
        $process = $this->manager->exec('/tmp', 'web', ['env'], [
            'env' => [
                'FOO' => 'bar',
            ],
        ]);

        $commandLine = $process->getCommandLine();

        $this->assertStringContainsString('-e', $commandLine);
        $this->assertStringContainsString('FOO=bar', $commandLine);
    }

    public function testExecWithComposeFiles(): void
    {
        $process = $this->manager->exec('/tmp', 'web', ['ls'], [
            'composeFiles' => ['/path/to/compose.yml', '/path/to/override.yml'],
        ]);

        $commandLine = $process->getCommandLine();

        $this->assertStringContainsString('-f', $commandLine);
        $this->assertStringContainsString('/path/to/compose.yml', $commandLine);
        $this->assertStringContainsString('/path/to/override.yml', $commandLine);
    }

    public function testLogsReturnsProcessWithCorrectCommand(): void
    {
        $process = $this->manager->logs('/tmp', 'web');

        $commandLine = $process->getCommandLine();

        $this->assertStringContainsString('logs', $commandLine);
        $this->assertStringContainsString('web', $commandLine);
    }

    public function testLogsWithFollowOption(): void
    {
        $process = $this->manager->logs('/tmp', 'web', [
            'follow' => true,
        ]);

        $commandLine = $process->getCommandLine();

        $this->assertStringContainsString('--follow', $commandLine);
    }

    public function testLogsWithTailOption(): void
    {
        $process = $this->manager->logs('/tmp', 'web', [
            'tail' => 100,
        ]);

        $commandLine = $process->getCommandLine();

        $this->assertStringContainsString('--tail', $commandLine);
        $this->assertStringContainsString('100', $commandLine);
    }

    public function testLogsWithEmptyServiceOmitsServiceName(): void
    {
        $process = $this->manager->logs('/tmp', '');

        $commandLine = $process->getCommandLine();

        $this->assertStringContainsString('logs', $commandLine);
        // command should end with 'logs', no service argument
        $this->assertStringEndsWith("'logs'", $commandLine);
    }

    public function testGenerateOverrideCreatesValidYaml(): void
    {
        $this->createComposeFile([
            'web' => [
                'image' => 'nginx:latest',
            ],
        ]);

        $config = ResolvedConfig::merge(new GlobalConfig(), new ProjectConfig(name: 'test-project'));

        $overridePath = $this->manager->generateOverride($config, $this->tempDir);

        $this->assertFileExists($overridePath);

        $data = Yaml::parseFile($overridePath);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('volumes', $data);
        $this->assertArrayHasKey('services', $data);

        unlink($overridePath);
    }

    public function testGenerateOverrideContainsExternalSshAgentVolume(): void
    {
        $this->createComposeFile([
            'web' => [
                'image' => 'nginx:latest',
            ],
        ]);

        $config = ResolvedConfig::merge(new GlobalConfig(), new ProjectConfig(name: 'test-project'));

        $overridePath = $this->manager->generateOverride($config, $this->tempDir);
        $data = Yaml::parseFile($overridePath);

        $this->assertTrue($data['volumes']['dde_ssh-agent_socket-dir']['external']);

        unlink($overridePath);
    }

    public function testGenerateOverrideAddsEnvironmentToAllServices(): void
    {
        $this->createComposeFile([
            'web' => [
                'image' => 'nginx:latest',
            ],
            'db' => [
                'image' => 'mysql:8',
            ],
        ]);

        $config = ResolvedConfig::merge(new GlobalConfig(), new ProjectConfig(name: 'test-project'));

        $overridePath = $this->manager->generateOverride($config, $this->tempDir);
        $data = Yaml::parseFile($overridePath);

        foreach (['web', 'db'] as $service) {
            $this->assertArrayHasKey($service, $data['services']);
            $this->assertArrayHasKey('environment', $data['services'][$service]);
            $this->assertArrayHasKey('DDE_UID', $data['services'][$service]['environment']);
            $this->assertArrayHasKey('DDE_GID', $data['services'][$service]['environment']);
            $this->assertSame((string) posix_getuid(), $data['services'][$service]['environment']['DDE_UID']);
            $this->assertSame((string) posix_getgid(), $data['services'][$service]['environment']['DDE_GID']);
        }

        unlink($overridePath);
    }

    public function testGenerateOverrideAddsDdeManagedLabel(): void
    {
        $this->createComposeFile([
            'web' => [
                'image' => 'nginx:latest',
            ],
        ]);

        $config = ResolvedConfig::merge(new GlobalConfig(), new ProjectConfig(name: 'test-project'));

        $overridePath = $this->manager->generateOverride($config, $this->tempDir);
        $data = Yaml::parseFile($overridePath);

        $this->assertContains('dde.managed=true', $data['services']['web']['labels']);

        unlink($overridePath);
    }

    public function testGenerateOverrideAddsEntrypointToEachService(): void
    {
        $this->createComposeFile([
            'web' => [
                'image' => 'nginx:latest',
            ],
        ]);

        $config = ResolvedConfig::merge(new GlobalConfig(), new ProjectConfig(name: 'test-project'));

        $overridePath = $this->manager->generateOverride($config, $this->tempDir);
        $data = Yaml::parseFile($overridePath);

        $this->assertArrayHasKey('entrypoint', $data['services']['web']);
        $this->assertSame(['/dde/entrypoint.sh'], $data['services']['web']['entrypoint']);

        unlink($overridePath);
    }

    public function testGenerateOverridePreservesComposeCommandWithImageEntrypoint(): void
    {
        $this->createComposeFile([
            'storage' => [
                'image' => 'minio/minio',
                'command' => 'server /data --console-address :9001',
            ],
        ]);

        // Simulate image with entrypoint ["minio"]
        $dockerManager = $this->createStub(DockerManager::class);
        $dockerManager->method('imageHasShell')->willReturn(true);
        $dockerManager->method('inspectImage')->willReturnCallback(
            static fn (string $image, string $format): string => match ($format) {
                '{{json .Config.Entrypoint}}' => '["minio"]',
                '{{json .Config.Cmd}}' => 'null',
                default => '',
            },
        );

        $manager = $this->createManagerWithDockerManager($dockerManager);

        $config = ResolvedConfig::merge(new GlobalConfig(), new ProjectConfig(name: 'test-project'));

        $overridePath = $manager->generateOverride($config, $this->tempDir);
        $data = Yaml::parseFile($overridePath);

        $this->assertSame(['minio', 'server', '/data', '--console-address', ':9001'], $data['services']['storage']['command']);

        unlink($overridePath);
    }

    public function testGenerateOverridePreservesComposeCommandAsList(): void
    {
        $this->createComposeFile([
            'storage' => [
                'image' => 'minio/minio',
                'command' => ['server', '/data'],
            ],
        ]);

        $config = ResolvedConfig::merge(new GlobalConfig(), new ProjectConfig(name: 'test-project'));

        $overridePath = $this->manager->generateOverride($config, $this->tempDir);
        $data = Yaml::parseFile($overridePath);

        $this->assertSame(['server', '/data'], $data['services']['storage']['command']);

        unlink($overridePath);
    }

    public function testGenerateOverridePreservesComposeEntrypoint(): void
    {
        $this->createComposeFile([
            'web' => [
                'image' => 'nginx:latest',
                'entrypoint' => ['/custom-entrypoint.sh'],
                'command' => ['nginx', '-g', 'daemon off;'],
            ],
        ]);

        $config = ResolvedConfig::merge(new GlobalConfig(), new ProjectConfig(name: 'test-project'));

        $overridePath = $this->manager->generateOverride($config, $this->tempDir);
        $data = Yaml::parseFile($overridePath);

        $this->assertSame(['/custom-entrypoint.sh', 'nginx', '-g', 'daemon off;'], $data['services']['web']['command']);

        unlink($overridePath);
    }

    public function testGenerateOverrideEscapesDollarSignsInImageCmd(): void
    {
        $this->createComposeFile([
            'guacd' => [
                'image' => 'guacd:1.5.3',
            ],
        ]);

        // Image CMD contains $GUACD_LOG_LEVEL meant for shell expansion at runtime
        $dockerManager = $this->createStub(DockerManager::class);
        $dockerManager->method('imageHasShell')->willReturn(true);
        $dockerManager->method('inspectImage')->willReturnCallback(
            static fn (string $image, string $format): string => match ($format) {
                '{{json .Config.Entrypoint}}' => 'null',
                '{{json .Config.Cmd}}' => '["/bin/sh","-c","/opt/guacamole/sbin/guacd -b 0.0.0.0 -L $GUACD_LOG_LEVEL -f"]',
                default => '',
            },
        );

        $manager = $this->createManagerWithDockerManager($dockerManager);

        $config = ResolvedConfig::merge(new GlobalConfig(), new ProjectConfig(name: 'test-project'));

        $overridePath = $manager->generateOverride($config, $this->tempDir);
        $data = Yaml::parseFile($overridePath);

        // $ must be escaped to $$ so Docker Compose does not interpolate it
        $this->assertSame(
            ['/bin/sh', '-c', '/opt/guacamole/sbin/guacd -b 0.0.0.0 -L $$GUACD_LOG_LEVEL -f'],
            $data['services']['guacd']['command'],
        );

        unlink($overridePath);
    }

    public function testGenerateOverrideSkipsEntrypointForShellLessImage(): void
    {
        $this->createComposeFile([
            'mercure' => [
                'image' => 'dunglas/mercure',
            ],
        ]);

        $dockerManager = $this->createStub(DockerManager::class);
        $dockerManager->method('imageHasShell')->willReturn(false);

        $manager = $this->createManagerWithDockerManager($dockerManager);

        $config = ResolvedConfig::merge(new GlobalConfig(), new ProjectConfig(name: 'test-project'));

        $overridePath = $manager->generateOverride($config, $this->tempDir);
        $data = Yaml::parseFile($overridePath);

        // Should only have labels, no entrypoint/volumes/environment
        $this->assertArrayHasKey('mercure', $data['services']);
        $this->assertArrayNotHasKey('entrypoint', $data['services']['mercure']);
        $this->assertArrayNotHasKey('volumes', $data['services']['mercure']);
        $this->assertArrayNotHasKey('environment', $data['services']['mercure']);
        $this->assertContains('dde.managed=true', $data['services']['mercure']['labels']);

        unlink($overridePath);
    }

    public function testGenerateOverrideMixesShellAndShellLessServices(): void
    {
        $this->createComposeFile([
            'web' => [
                'image' => 'nginx:latest',
            ],
            'mercure' => [
                'image' => 'dunglas/mercure',
            ],
        ]);

        $dockerManager = $this->createStub(DockerManager::class);
        $dockerManager->method('imageHasShell')->willReturnCallback(
            static fn (string $image): bool => $image !== 'dunglas/mercure',
        );
        $dockerManager->method('inspectImage')->willReturn('null');

        $manager = $this->createManagerWithDockerManager($dockerManager);

        $config = ResolvedConfig::merge(new GlobalConfig(), new ProjectConfig(name: 'test-project'));

        $overridePath = $manager->generateOverride($config, $this->tempDir);
        $data = Yaml::parseFile($overridePath);

        // web gets full override
        $this->assertArrayHasKey('entrypoint', $data['services']['web']);
        $this->assertArrayHasKey('volumes', $data['services']['web']);

        // mercure gets labels only
        $this->assertArrayNotHasKey('entrypoint', $data['services']['mercure']);
        $this->assertArrayNotHasKey('volumes', $data['services']['mercure']);

        unlink($overridePath);
    }

    public function testGenerateOverrideThrowsWhenNoServicesFound(): void
    {
        // empty temp dir with no compose file

        $config = ResolvedConfig::merge(new GlobalConfig(), new ProjectConfig(name: 'test-project'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No services found/');

        $this->manager->generateOverride($config, $this->tempDir);
    }

    public function testGenerateOverrideDiscoversComposeYml(): void
    {
        // use compose.yml instead of docker-compose.yml
        $composeData = [
            'services' => [
                'app' => [
                    'image' => 'php:8.5',
                ],
            ],
        ];

        file_put_contents($this->tempDir.'/compose.yml', Yaml::dump($composeData, 4, 2));

        $config = ResolvedConfig::merge(new GlobalConfig(), new ProjectConfig(name: 'test-project'));

        $overridePath = $this->manager->generateOverride($config, $this->tempDir);
        $data = Yaml::parseFile($overridePath);

        $this->assertArrayHasKey('app', $data['services']);

        unlink($overridePath);
    }

    public function testDiscoverServiceNamesReturnsServiceNamesFromComposeFile(): void
    {
        $this->createComposeFile([
            'web' => [
                'image' => 'nginx:latest',
            ],
            'worker' => [
                'image' => 'php:8.5',
            ],
        ]);

        $names = $this->manager->discoverServiceNames($this->tempDir);

        $this->assertSame(['web', 'worker'], $names);
    }

    public function testDiscoverServiceNamesReturnsEmptyForMissingComposeFile(): void
    {
        $names = $this->manager->discoverServiceNames($this->tempDir);

        $this->assertSame([], $names);
    }

    public function testPsThrowsOnNonExistentProjectDir(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->manager->ps('/nonexistent-dir-'.bin2hex(random_bytes(8)));
    }

    public function testUpThrowsOnNonExistentProjectDir(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->manager->up('/nonexistent-dir-'.bin2hex(random_bytes(8)));
    }

    public function testDownThrowsOnNonExistentProjectDir(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->manager->down('/nonexistent-dir-'.bin2hex(random_bytes(8)));
    }

    public function testFindComposeFileThrowsWhenNoComposeFileFound(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No compose file found/');

        $this->manager->findComposeFile($this->tempDir);
    }

    public function testGenerateOverrideWorktreeOverridesTraefikLabels(): void
    {
        $this->createComposeFile([
            'web' => [
                'image' => 'nginx:latest',
                'labels' => [
                    'traefik.enable=true',
                    'traefik.http.routers.meseto-test-web.rule=Host(`meseto.test`)',
                    'traefik.http.routers.meseto-test-web-tls.rule=Host(`meseto.test`)',
                    'traefik.http.routers.meseto-test-web-tls.tls=true',
                ],
            ],
        ]);

        $worktreeInfo = new WorktreeInfo(
            mainDirectory: '/projects/meseto',
            worktreeDirectory: '/projects/meseto-wt-feature',
            branch: 'feature/test',
            suffix: 'meseto-wt-feature',
        );

        $manager = $this->createManagerWithWorktreeSupport('meseto-feature.test');
        $config = ResolvedConfig::merge(new GlobalConfig(), new ProjectConfig(name: 'meseto'));

        $overridePath = $manager->generateOverride($config, $this->tempDir, $worktreeInfo);
        $data = Yaml::parseFile($overridePath);

        $labels = $data['services']['web']['labels'];

        // Router names stay the same, but Host() changes to worktree hostname
        $this->assertContains('traefik.enable=true', $labels);
        $this->assertContains('traefik.http.routers.meseto-test-web.rule=Host(`meseto-feature.test`)', $labels);
        $this->assertContains('traefik.http.routers.meseto-test-web-tls.rule=Host(`meseto-feature.test`)', $labels);
        $this->assertContains('traefik.http.routers.meseto-test-web-tls.tls=true', $labels);

        // Must NOT contain the original hostname as standalone Host()
        foreach ($labels as $label) {
            if (str_contains($label, '.rule=')) {
                $this->assertStringNotContainsString('Host(`meseto.test`)', $label);
            }
        }

        unlink($overridePath);
    }

    public function testGenerateOverrideWorktreeFallbackWhenNoTraefikLabels(): void
    {
        $this->createComposeFile([
            'web' => [
                'image' => 'nginx:latest',
            ],
        ]);

        $worktreeInfo = new WorktreeInfo(
            mainDirectory: '/projects/meseto',
            worktreeDirectory: '/projects/meseto-wt-feature',
            branch: 'feature/test',
            suffix: 'meseto-wt-feature',
        );

        $manager = $this->createManagerWithWorktreeSupport('meseto-feature.test');
        $config = ResolvedConfig::merge(new GlobalConfig(), new ProjectConfig(name: 'meseto'));

        $overridePath = $manager->generateOverride($config, $this->tempDir, $worktreeInfo);
        $data = Yaml::parseFile($overridePath);

        $labels = $data['services']['web']['labels'];

        // Should generate new labels with worktree hostname
        $this->assertContains('traefik.enable=true', $labels);

        $hasWorktreeHost = false;

        foreach ($labels as $label) {
            if (str_contains($label, 'meseto-feature.test')) {
                $hasWorktreeHost = true;
            }
        }

        $this->assertTrue($hasWorktreeHost, 'Generated labels should contain worktree hostname');

        unlink($overridePath);
    }

    public function testGenerateOverrideWorktreeOverridesEnvironmentHostnames(): void
    {
        $this->createComposeFile([
            'web' => [
                'image' => 'nginx:latest',
                'environment' => [
                    'VIRTUAL_HOST=meseto.test',
                    'MERCURE_URL=http://mercure.meseto.test/.well-known/mercure',
                    'OPEN_URL=https://meseto.test',
                    'DATABASE_URL=mysql://root@db:3306/app',
                ],
            ],
        ]);

        $worktreeInfo = new WorktreeInfo(
            mainDirectory: '/projects/meseto',
            worktreeDirectory: '/projects/meseto-wt-feature',
            branch: 'feature/test',
            suffix: 'meseto-wt-feature',
        );

        $manager = $this->createManagerWithWorktreeSupport('meseto-feature.test');
        $config = ResolvedConfig::merge(new GlobalConfig(), new ProjectConfig(name: 'meseto'));

        $overridePath = $manager->generateOverride($config, $this->tempDir, $worktreeInfo);
        $data = Yaml::parseFile($overridePath);

        $env = $data['services']['web']['environment'];

        $this->assertSame('meseto-feature.test', $env['VIRTUAL_HOST']);
        $this->assertSame('http://mercure.meseto-feature.test/.well-known/mercure', $env['MERCURE_URL']);
        $this->assertSame('https://meseto-feature.test', $env['OPEN_URL']);

        // DATABASE_URL does not contain the hostname, should NOT be overridden
        $this->assertArrayNotHasKey('DATABASE_URL', $env);

        unlink($overridePath);
    }

    public function testGenerateOverrideWorktreeOverridesMapFormatEnvironment(): void
    {
        $this->createComposeFile([
            'web' => [
                'image' => 'nginx:latest',
                'environment' => [
                    'OPEN_URL' => 'https://meseto.test',
                    'APP_SECRET' => 'abc123',
                ],
            ],
        ]);

        $worktreeInfo = new WorktreeInfo(
            mainDirectory: '/projects/meseto',
            worktreeDirectory: '/projects/meseto-wt-feature',
            branch: 'feature/test',
            suffix: 'meseto-wt-feature',
        );

        $manager = $this->createManagerWithWorktreeSupport('meseto-feature.test');
        $config = ResolvedConfig::merge(new GlobalConfig(), new ProjectConfig(name: 'meseto'));

        $overridePath = $manager->generateOverride($config, $this->tempDir, $worktreeInfo);
        $data = Yaml::parseFile($overridePath);

        $env = $data['services']['web']['environment'];

        $this->assertSame('https://meseto-feature.test', $env['OPEN_URL']);
        $this->assertArrayNotHasKey('APP_SECRET', $env);

        unlink($overridePath);
    }

    private function createManagerWithWorktreeSupport(string $worktreeHostname): DockerComposeManager
    {
        $dockerManager = $this->createStub(DockerManager::class);
        $dockerManager->method('imageHasShell')->willReturn(true);
        $dockerManager->method('inspectImage')->willReturn('null');

        $resourcesDir = dirname(__DIR__, 3).'/resources';
        $adapterRegistry = new AdapterRegistry($resourcesDir, $this->tempDir.'/data');

        $configManager = $this->createStub(ProjectConfigManager::class);
        $configManager->method('resolveProjectHostname')->willReturn($worktreeHostname);

        $traefikService = new TraefikService(
            dockerManager: $dockerManager,
            filesystem: new \Symfony\Component\Filesystem\Filesystem(),
            dataDir: $this->tempDir,
        );

        return new DockerComposeManager($adapterRegistry, $configManager, $dockerManager, $traefikService, new UserContext());
    }

    /**
     * @param array<string, array<string, mixed>> $services
     */
    private function createComposeFile(array $services): void
    {
        $composeData = [
            'services' => $services,
        ];

        file_put_contents($this->tempDir.'/docker-compose.yml', Yaml::dump($composeData, 4, 2));
    }

    private function createManagerWithDockerManager(DockerManager $dockerManager): DockerComposeManager
    {
        $resourcesDir = dirname(__DIR__, 3).'/resources';
        $adapterRegistry = new AdapterRegistry($resourcesDir, $this->tempDir.'/data');
        $configManager = $this->createStub(ProjectConfigManager::class);
        $traefikService = new TraefikService(
            dockerManager: $dockerManager,
            filesystem: new \Symfony\Component\Filesystem\Filesystem(),
            dataDir: $this->tempDir,
        );

        return new DockerComposeManager($adapterRegistry, $configManager, $dockerManager, $traefikService, new UserContext());
    }

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/dde-test-'.bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o755, true);

        $dockerManager = $this->createStub(DockerManager::class);
        $dockerManager->method('imageHasShell')->willReturn(true);
        $this->manager = $this->createManagerWithDockerManager($dockerManager);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempDir.'/*');

        if ($files !== false) {
            foreach ($files as $file) {
                unlink($file);
            }
        }

        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }
}
