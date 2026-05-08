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

        $data = Yaml::parseFile($overridePath, Yaml::PARSE_CUSTOM_TAGS);

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
        $data = Yaml::parseFile($overridePath, Yaml::PARSE_CUSTOM_TAGS);

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
        $data = Yaml::parseFile($overridePath, Yaml::PARSE_CUSTOM_TAGS);

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
        $data = Yaml::parseFile($overridePath, Yaml::PARSE_CUSTOM_TAGS);

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
        $data = Yaml::parseFile($overridePath, Yaml::PARSE_CUSTOM_TAGS);

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
        $data = Yaml::parseFile($overridePath, Yaml::PARSE_CUSTOM_TAGS);

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
        $data = Yaml::parseFile($overridePath, Yaml::PARSE_CUSTOM_TAGS);

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
        $data = Yaml::parseFile($overridePath, Yaml::PARSE_CUSTOM_TAGS);

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
        $data = Yaml::parseFile($overridePath, Yaml::PARSE_CUSTOM_TAGS);

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
        $data = Yaml::parseFile($overridePath, Yaml::PARSE_CUSTOM_TAGS);

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
        $data = Yaml::parseFile($overridePath, Yaml::PARSE_CUSTOM_TAGS);

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
        $data = Yaml::parseFile($overridePath, Yaml::PARSE_CUSTOM_TAGS);

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
        $data = Yaml::parseFile($overridePath, Yaml::PARSE_CUSTOM_TAGS);

        // Worktree overrides must wrap labels with !override so Docker Compose
        // replaces (not merges) the base file's labels on the worktree container.
        $this->assertInstanceOf(\Symfony\Component\Yaml\Tag\TaggedValue::class, $data['services']['web']['labels']);
        $this->assertSame('override', $data['services']['web']['labels']->getTag());
        $labels = $data['services']['web']['labels']->getValue();

        // Router names are renamed to match the worktree hostname so main and
        // worktree containers can coexist without Traefik router-name conflicts.
        // Host() rules are rewritten to the worktree hostname.
        $this->assertContains('traefik.enable=true', $labels);
        $this->assertContains('traefik.http.routers.meseto-feature-test-web.rule=Host(`meseto-feature.test`)', $labels);
        $this->assertContains('traefik.http.routers.meseto-feature-test-web-tls.rule=Host(`meseto-feature.test`)', $labels);
        $this->assertContains('traefik.http.routers.meseto-feature-test-web-tls.tls=true', $labels);

        // Must NOT contain the original router name or the original hostname
        foreach ($labels as $label) {
            $this->assertStringNotContainsString('routers.meseto-test-web', $label, 'Old router name must be gone');

            if (str_contains($label, '.rule=')) {
                $this->assertStringNotContainsString('Host(`meseto.test`)', $label);
            }
        }

        unlink($overridePath);
    }

    public function testGenerateOverrideWorktreeRewritesSubdomainTraefikLabels(): void
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
            'preview' => [
                'image' => 'nginx:latest',
                'labels' => [
                    'traefik.enable=true',
                    'traefik.http.routers.preview-meseto-test-preview.rule=Host(`preview.meseto.test`)',
                    'traefik.http.routers.preview-meseto-test-preview-tls.rule=Host(`preview.meseto.test`)',
                    'traefik.http.routers.preview-meseto-test-preview-tls.tls=true',
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
        $data = Yaml::parseFile($overridePath, Yaml::PARSE_CUSTOM_TAGS);

        $previewLabels = $data['services']['preview']['labels']->getValue();

        // Subdomain Host() rule must be rewritten to the worktree variant.
        $this->assertContains(
            'traefik.http.routers.preview-meseto-feature-test-preview.rule=Host(`preview.meseto-feature.test`)',
            $previewLabels,
        );
        $this->assertContains(
            'traefik.http.routers.preview-meseto-feature-test-preview-tls.rule=Host(`preview.meseto-feature.test`)',
            $previewLabels,
        );

        foreach ($previewLabels as $label) {
            $this->assertStringNotContainsString('Host(`preview.meseto.test`)', $label, 'Old subdomain host must be gone');
            $this->assertStringNotContainsString('routers.preview-meseto-test-preview', $label, 'Old subdomain router must be renamed');
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
        $data = Yaml::parseFile($overridePath, Yaml::PARSE_CUSTOM_TAGS);

        $this->assertInstanceOf(\Symfony\Component\Yaml\Tag\TaggedValue::class, $data['services']['web']['labels']);
        $labels = $data['services']['web']['labels']->getValue();

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

        $manager = $this->createManagerWithRealWorktreeManager();
        $config = ResolvedConfig::merge(new GlobalConfig(), new ProjectConfig(name: 'meseto'));

        $overridePath = $manager->generateOverride($config, $this->tempDir, $worktreeInfo);
        $data = Yaml::parseFile($overridePath, Yaml::PARSE_CUSTOM_TAGS);

        $env = $data['services']['web']['environment'];

        $this->assertSame('meseto-wt-feature.test', $env['VIRTUAL_HOST']);
        $this->assertSame('http://mercure.meseto-wt-feature.test/.well-known/mercure', $env['MERCURE_URL']);
        $this->assertSame('https://meseto-wt-feature.test', $env['OPEN_URL']);

        // DATABASE_URL path segment gets worktree suffix appended
        $this->assertSame('mysql://root@db:3306/app_wt_feature', $env['DATABASE_URL']);

        unlink($overridePath);
    }

    public function testGenerateOverrideSetsHostnameFromProjectAndServiceName(): void
    {
        $this->createComposeFile([
            'web' => [
                'image' => 'nginx:latest',
            ],
            'worker' => [
                'image' => 'php:8.5',
            ],
        ]);

        $config = ResolvedConfig::merge(new GlobalConfig(), new ProjectConfig(name: 'meseto'));

        $overridePath = $this->manager->generateOverride($config, $this->tempDir);
        $data = Yaml::parseFile($overridePath);

        $this->assertSame('meseto-web', $data['services']['web']['hostname']);
        $this->assertSame('meseto-worker', $data['services']['worker']['hostname']);

        unlink($overridePath);
    }

    public function testGenerateOverrideSanitizesHostnameCharacters(): void
    {
        $this->createComposeFile([
            'web' => [
                'image' => 'nginx:latest',
            ],
        ]);

        $config = ResolvedConfig::merge(new GlobalConfig(), new ProjectConfig(name: 'My Project_42'));

        $overridePath = $this->manager->generateOverride($config, $this->tempDir);
        $data = Yaml::parseFile($overridePath);

        $this->assertSame('my-project-42-web', $data['services']['web']['hostname']);

        unlink($overridePath);
    }

    public function testGenerateOverrideRespectsHostnameFromComposeFile(): void
    {
        $this->createComposeFile([
            'web' => [
                'image' => 'nginx:latest',
                'hostname' => 'custom-host',
            ],
        ]);

        $config = ResolvedConfig::merge(new GlobalConfig(), new ProjectConfig(name: 'meseto'));

        $overridePath = $this->manager->generateOverride($config, $this->tempDir);
        $data = Yaml::parseFile($overridePath);

        $this->assertArrayNotHasKey('hostname', $data['services']['web']);

        unlink($overridePath);
    }

    public function testGenerateOverrideSetsHostnameForShellLessServices(): void
    {
        $this->createComposeFile([
            'mercure' => [
                'image' => 'dunglas/mercure',
            ],
        ]);

        $dockerManager = $this->createStub(DockerManager::class);
        $dockerManager->method('imageHasShell')->willReturn(false);

        $manager = $this->createManagerWithDockerManager($dockerManager);

        $config = ResolvedConfig::merge(new GlobalConfig(), new ProjectConfig(name: 'meseto'));

        $overridePath = $manager->generateOverride($config, $this->tempDir);
        $data = Yaml::parseFile($overridePath);

        $this->assertSame('meseto-mercure', $data['services']['mercure']['hostname']);

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

        $manager = $this->createManagerWithRealWorktreeManager();
        $config = ResolvedConfig::merge(new GlobalConfig(), new ProjectConfig(name: 'meseto'));

        $overridePath = $manager->generateOverride($config, $this->tempDir, $worktreeInfo);
        $data = Yaml::parseFile($overridePath, Yaml::PARSE_CUSTOM_TAGS);

        $env = $data['services']['web']['environment'];

        $this->assertSame('https://meseto-wt-feature.test', $env['OPEN_URL']);
        $this->assertArrayNotHasKey('APP_SECRET', $env);

        unlink($overridePath);
    }

    public function testGenerateOverrideWorktreeRewritesDatabaseUrl(): void
    {
        $this->createComposeFile([
            'web' => [
                'image' => 'nginx:latest',
                'environment' => [
                    'DATABASE_URL=mysql://root:pw@mariadb:3306/meseto?serverVersion=11.8.0-MariaDB',
                    'APP_URL=https://meseto.test',
                ],
            ],
        ]);

        $worktreeInfo = new WorktreeInfo(
            mainDirectory: '/projects/meseto',
            worktreeDirectory: '/projects/meseto-wt-feature',
            branch: 'feature/test',
            suffix: 'meseto-wt-feature',
        );

        $manager = $this->createManagerWithRealWorktreeManager();
        $config = ResolvedConfig::merge(new GlobalConfig(), new ProjectConfig(name: 'meseto'));

        $overridePath = $manager->generateOverride($config, $this->tempDir, $worktreeInfo);
        $data = Yaml::parseFile($overridePath, Yaml::PARSE_CUSTOM_TAGS);
        $env = $data['services']['web']['environment'];

        $this->assertSame(
            'mysql://root:pw@mariadb:3306/meseto_wt_feature?serverVersion=11.8.0-MariaDB',
            $env['DATABASE_URL'],
        );
        $this->assertSame('https://meseto-wt-feature.test', $env['APP_URL']);

        unlink($overridePath);
    }

    private function createManagerWithWorktreeSupport(string $worktreeHostname): DockerComposeManager
    {
        $dockerManager = $this->createStub(DockerManager::class);
        $dockerManager->method('imageHasShell')->willReturn(true);
        $dockerManager->method('inspectImage')->willReturn('null');

        $resourcesDir = dirname(__DIR__, 3).'/resources';
        $adapterRegistry = new AdapterRegistry($resourcesDir, $this->tempDir.'/data');

        $worktreeManager = $this->createStub(\App\Manager\WorktreeManager::class);
        $worktreeManager->method('resolveHostname')->willReturn($worktreeHostname);
        $worktreeManager->method('computeEnvironmentOverrides')->willReturnCallback(
            function (array $env, string $project, \App\Config\WorktreeInfo $info) use ($worktreeHostname): array {
                $result = [];
                foreach ($env as $k => $v) {
                    $key = is_int($k) ? explode('=', (string) $v, 2)[0] : $k;
                    $val = is_int($k) ? (explode('=', (string) $v, 2)[1] ?? '') : (string) $v;
                    if (str_contains($val, $project.'.test')) {
                        $result[$key] = str_replace($project.'.test', $worktreeHostname, $val);
                    }
                }

                return $result;
            },
        );

        $traefikService = new TraefikService(
            dockerManager: $dockerManager,
            filesystem: new \Symfony\Component\Filesystem\Filesystem(),
            dataDir: $this->tempDir,
        );

        return new DockerComposeManager($adapterRegistry, $dockerManager, $traefikService, new UserContext(), $worktreeManager);
    }

    private function createManagerWithRealWorktreeManager(): DockerComposeManager
    {
        $dockerManager = $this->createStub(DockerManager::class);
        $dockerManager->method('imageHasShell')->willReturn(true);
        $dockerManager->method('inspectImage')->willReturn('null');

        $resourcesDir = dirname(__DIR__, 3).'/resources';
        $adapterRegistry = new AdapterRegistry($resourcesDir, $this->tempDir.'/data');

        $worktreeManager = new \App\Manager\WorktreeManager(new \App\Util\ProcessFactory());

        $traefikService = new TraefikService(
            dockerManager: $dockerManager,
            filesystem: new \Symfony\Component\Filesystem\Filesystem(),
            dataDir: $this->tempDir,
        );

        return new DockerComposeManager($adapterRegistry, $dockerManager, $traefikService, new UserContext(), $worktreeManager);
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
        $worktreeManager = $this->createStub(\App\Manager\WorktreeManager::class);
        $traefikService = new TraefikService(
            dockerManager: $dockerManager,
            filesystem: new \Symfony\Component\Filesystem\Filesystem(),
            dataDir: $this->tempDir,
        );

        return new DockerComposeManager($adapterRegistry, $dockerManager, $traefikService, new UserContext(), $worktreeManager);
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
