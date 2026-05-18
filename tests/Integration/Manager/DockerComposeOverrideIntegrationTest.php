<?php

declare(strict_types=1);

namespace App\Tests\Integration\Manager;

use App\Adapter\AdapterRegistry;
use App\Config\Definition\GlobalConfigDefinition;
use App\Config\GlobalConfig;
use App\Config\ProjectConfig;
use App\Config\ResolvedConfig;
use App\Config\WorktreeInfo;
use App\Manager\DockerComposeManager;
use App\Manager\DockerManager;
use App\Manager\WorktreeManager;
use App\Model\UserContext;
use App\Service\TraefikService;
use App\Util\ProcessFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

final class DockerComposeOverrideIntegrationTest extends TestCase
{
    private string $tempDir;

    private Filesystem $filesystem;

    private DockerComposeManager $manager;

    private AdapterRegistry $adapterRegistry;

    public function testGenerateOverrideSingleService(): void
    {
        $projectDir = $this->createProjectDir(<<<'YAML'
            services:
              web:
                image: nginx:latest
            YAML);

        $config = $this->makeResolvedConfig();
        $overridePath = $this->manager->generateOverride($config, $projectDir);

        self::assertFileExists($overridePath);

        $parsed = Yaml::parseFile($overridePath, Yaml::PARSE_CUSTOM_TAGS);

        // Service web must be present
        self::assertIsArray($parsed['services']);
        self::assertArrayHasKey('web', $parsed['services']);

        $web = $parsed['services']['web'];

        // Entrypoint
        self::assertSame(['/dde/entrypoint.sh'], $web['entrypoint']);

        // Environment
        self::assertIsArray($web['environment']);
        $env = $web['environment'];
        self::assertArrayHasKey('DDE_UID', $env);
        self::assertArrayHasKey('DDE_GID', $env);
        self::assertSame('1000', (string) $env['DDE_UID']);
        self::assertSame('1000', (string) $env['DDE_GID']);

        // Labels
        self::assertIsArray($web['labels']);
        self::assertContains('dde.managed=true', $web['labels']);

        // Volumes: entrypoint.sh and adapters dir
        self::assertIsArray($web['volumes']);
        $volumes = $web['volumes'];

        $entrypointPath = $this->adapterRegistry->getEntrypointPath();
        $adaptersDir = $this->adapterRegistry->getBuiltinAdaptersDir();

        self::assertContains($entrypointPath.':/dde/entrypoint.sh:ro', $volumes);
        self::assertContains($adaptersDir.':/dde/adapters:ro', $volumes);

        // Global volume dde_ssh-agent_socket-dir defined as external
        self::assertIsArray($parsed['volumes']);
        self::assertArrayHasKey('dde_ssh-agent_socket-dir', $parsed['volumes']);
        self::assertTrue($parsed['volumes']['dde_ssh-agent_socket-dir']['external']);

        // Networks
        self::assertIsArray($parsed['networks']);
        self::assertArrayHasKey('dde', $parsed['networks']);

        // SSH_AUTH_SOCK env var
        self::assertArrayHasKey('SSH_AUTH_SOCK', $env);
        self::assertSame('/tmp/ssh-agent/socket', $env['SSH_AUTH_SOCK']);
    }

    public function testGenerateOverrideMultipleServices(): void
    {
        $projectDir = $this->createProjectDir(<<<'YAML'
            services:
              web:
                image: nginx:latest
              api:
                image: php:8.3-fpm
            YAML);

        $config = $this->makeResolvedConfig();
        $overridePath = $this->manager->generateOverride($config, $projectDir);

        $parsed = Yaml::parseFile($overridePath, Yaml::PARSE_CUSTOM_TAGS);

        self::assertArrayHasKey('web', $parsed['services']);
        self::assertArrayHasKey('api', $parsed['services']);

        foreach (['web', 'api'] as $serviceName) {
            $service = $parsed['services'][$serviceName];
            self::assertSame(['/dde/entrypoint.sh'], $service['entrypoint']);
            self::assertArrayHasKey('DDE_UID', $service['environment']);
            self::assertArrayHasKey('DDE_GID', $service['environment']);
            self::assertContains('dde.managed=true', $service['labels']);
        }
    }

    public function testGenerateOverrideIncludesProjectAdapters(): void
    {
        $projectDir = $this->createProjectDir(<<<'YAML'
            services:
              web:
                image: nginx:latest
            YAML);

        // Create .dde/adapters with a custom adapter
        $projectAdaptersDir = $projectDir.'/.dde/adapters';
        $this->filesystem->mkdir($projectAdaptersDir);
        $this->filesystem->dumpFile($projectAdaptersDir.'/custom.sh', "#!/bin/sh\necho custom\n");

        $config = $this->makeResolvedConfig();
        $overridePath = $this->manager->generateOverride($config, $projectDir);

        $parsed = Yaml::parseFile($overridePath, Yaml::PARSE_CUSTOM_TAGS);
        $volumes = $parsed['services']['web']['volumes'];

        self::assertContains($projectAdaptersDir.':/dde/adapters-project:ro', $volumes);
    }

    public function testGenerateOverrideWithWorktreeInfoAddsTraefikLabels(): void
    {
        $projectDir = $this->createProjectDir(<<<'YAML'
            services:
              web:
                image: nginx:latest
            YAML);

        $worktreeInfo = new WorktreeInfo(
            mainDirectory: '/some/main/dir',
            worktreeDirectory: $projectDir,
            branch: 'feature/my-branch',
            suffix: 'myproject-feature-branch',
        );

        $config = $this->makeResolvedConfig('myproject');
        $overridePath = $this->manager->generateOverride($config, $projectDir, $worktreeInfo);

        $parsed = Yaml::parseFile($overridePath, Yaml::PARSE_CUSTOM_TAGS);
        $labelsValue = $parsed['services']['web']['labels'];

        // Worktree overrides wrap labels with !override so Compose replaces
        // (not merges) the base labels on the worktree container.
        self::assertInstanceOf(\Symfony\Component\Yaml\Tag\TaggedValue::class, $labelsValue);
        self::assertSame('override', $labelsValue->getTag());
        $labels = $labelsValue->getValue();

        self::assertContains('dde.managed=true', $labels);
        self::assertContains('traefik.enable=true', $labels);

        $traefikLabels = array_filter($labels, static fn (string $l): bool => str_contains($l, 'traefik.'));
        self::assertNotEmpty($traefikLabels);
    }

    public function testGenerateOverrideWritesToTempFile(): void
    {
        $projectDir = $this->createProjectDir(<<<'YAML'
            services:
              web:
                image: nginx:latest
            YAML);

        $config = $this->makeResolvedConfig();
        $overridePath = $this->manager->generateOverride($config, $projectDir);

        self::assertFileExists($overridePath);
        self::assertIsReadable($overridePath);

        $content = file_get_contents($overridePath);
        self::assertIsString($content);
        self::assertNotEmpty($content);

        // Must be valid YAML
        $parsed = Yaml::parse($content, Yaml::PARSE_CUSTOM_TAGS);
        self::assertIsArray($parsed);
        self::assertArrayHasKey('services', $parsed);
    }

    public function testGenerateOverrideThrowsWithoutComposeFile(): void
    {
        $projectDir = $this->tempDir.'/empty_project_'.bin2hex(random_bytes(4));
        $this->filesystem->mkdir($projectDir);

        $config = $this->makeResolvedConfig();

        $this->expectException(\RuntimeException::class);

        $this->manager->generateOverride($config, $projectDir);
    }

    public function testGenerateOverrideAttachesOnlyProjectNetworkWhenGiven(): void
    {
        $projectDir = $this->createProjectDir(<<<'YAML'
            services:
              web:
                image: nginx:latest
            YAML);

        $config = $this->makeResolvedConfig('myproject');
        $overridePath = $this->manager->generateOverride($config, $projectDir, null, 'dde-services-myproject');

        $parsed = Yaml::parseFile($overridePath, Yaml::PARSE_CUSTOM_TAGS);

        self::assertIsArray($parsed['networks']);
        self::assertArrayNotHasKey('dde', $parsed['networks']);
        self::assertArrayHasKey('dde-services-myproject', $parsed['networks']);
        self::assertTrue($parsed['networks']['dde-services-myproject']['external']);

        $web = $parsed['services']['web'];
        self::assertIsArray($web['networks']);
        self::assertArrayNotHasKey('dde', $web['networks']);
        self::assertArrayHasKey('dde-services-myproject', $web['networks']);

        self::assertContains('traefik.docker.network=dde-services-myproject', $web['labels']);
    }

    public function testGenerateOverrideOmitsTraefikDockerNetworkWithoutProjectNetwork(): void
    {
        $projectDir = $this->createProjectDir(<<<'YAML'
            services:
              web:
                image: nginx:latest
            YAML);

        $config = $this->makeResolvedConfig('myproject');
        $overridePath = $this->manager->generateOverride($config, $projectDir);

        $parsed = Yaml::parseFile($overridePath, Yaml::PARSE_CUSTOM_TAGS);

        $labels = $parsed['services']['web']['labels'];
        $traefikNetworkLabels = array_filter(
            $labels,
            static fn (string $label): bool => str_starts_with($label, 'traefik.docker.network='),
        );
        self::assertSame([], $traefikNetworkLabels);
    }

    public function testGenerateOverrideWithoutProjectNetworkInjectsOnlyDdeNetwork(): void
    {
        $projectDir = $this->createProjectDir(<<<'YAML'
            services:
              web:
                image: nginx:latest
            YAML);

        $config = $this->makeResolvedConfig('myproject');
        $overridePath = $this->manager->generateOverride($config, $projectDir);

        $parsed = Yaml::parseFile($overridePath, Yaml::PARSE_CUSTOM_TAGS);

        self::assertIsArray($parsed['networks']);
        self::assertArrayHasKey('dde', $parsed['networks']);
        self::assertArrayNotHasKey('dde-services-myproject', $parsed['networks']);
    }

    public function testGenerateOverrideInjectsSshAgentVolumeAndEnv(): void
    {
        $projectDir = $this->createProjectDir(<<<'YAML'
            services:
              web:
                image: nginx:latest
            YAML);

        $config = $this->makeResolvedConfig('myproject');
        $overridePath = $this->manager->generateOverride($config, $projectDir);

        $parsed = Yaml::parseFile($overridePath, Yaml::PARSE_CUSTOM_TAGS);

        self::assertArrayHasKey('dde_ssh-agent_socket-dir', $parsed['volumes']);
        self::assertTrue($parsed['volumes']['dde_ssh-agent_socket-dir']['external']);

        $web = $parsed['services']['web'];
        self::assertIsArray($web['environment']);
        self::assertArrayHasKey('SSH_AUTH_SOCK', $web['environment']);
        self::assertSame('/tmp/ssh-agent/socket', $web['environment']['SSH_AUTH_SOCK']);

        self::assertIsArray($web['volumes']);
        self::assertContains('dde_ssh-agent_socket-dir:/tmp/ssh-agent:ro', $web['volumes']);
    }

    public function testGenerateOverrideAttachesUserOverrideOnlyServicesToProjectNetwork(): void
    {
        $projectDir = $this->createProjectDir(<<<'YAML'
            services:
              web:
                image: nginx:latest
            YAML);

        $userOverridePath = $projectDir.'/docker-compose.override.yml';
        $this->filesystem->dumpFile($userOverridePath, <<<'YAML'
            services:
              debug:
                image: ubuntu:latest
                command: ["tail", "-f", "/dev/null"]
            YAML);

        $config = $this->makeResolvedConfig('myproject');
        $overridePath = $this->manager->generateOverride($config, $projectDir, null, 'dde-services-myproject', $userOverridePath);

        $parsed = Yaml::parseFile($overridePath, Yaml::PARSE_CUSTOM_TAGS);

        self::assertArrayHasKey('debug', $parsed['services']);
        $debug = $parsed['services']['debug'];

        self::assertIsArray($debug['networks']);
        self::assertArrayHasKey('dde-services-myproject', $debug['networks']);

        self::assertIsArray($debug['labels']);
        self::assertContains('traefik.docker.network=dde-services-myproject', $debug['labels']);
    }

    public function testGenerateOverrideDoesNotDuplicateBaseServicesFromUserOverride(): void
    {
        $projectDir = $this->createProjectDir(<<<'YAML'
            services:
              web:
                image: nginx:latest
            YAML);

        $userOverridePath = $projectDir.'/docker-compose.override.yml';
        $this->filesystem->dumpFile($userOverridePath, <<<'YAML'
            services:
              web:
                environment:
                  FOO: bar
            YAML);

        $config = $this->makeResolvedConfig('myproject');
        $overridePath = $this->manager->generateOverride($config, $projectDir, null, 'dde-services-myproject', $userOverridePath);

        $parsed = Yaml::parseFile($overridePath, Yaml::PARSE_CUSTOM_TAGS);

        // The base service `web` keeps its full dde overlay (entrypoint,
        // volumes, env, …) — not the minimal override-only stub.
        self::assertArrayHasKey('web', $parsed['services']);
        self::assertSame(['/dde/entrypoint.sh'], $parsed['services']['web']['entrypoint']);
    }

    public function testGenerateOverrideOmitsTraefikLabelForOverrideServiceWithoutProjectNetwork(): void
    {
        $projectDir = $this->createProjectDir(<<<'YAML'
            services:
              web:
                image: nginx:latest
            YAML);

        $userOverridePath = $projectDir.'/docker-compose.override.yml';
        $this->filesystem->dumpFile($userOverridePath, <<<'YAML'
            services:
              debug:
                image: ubuntu:latest
            YAML);

        $config = $this->makeResolvedConfig('myproject');
        $overridePath = $this->manager->generateOverride($config, $projectDir, null, null, $userOverridePath);

        $parsed = Yaml::parseFile($overridePath, Yaml::PARSE_CUSTOM_TAGS);

        self::assertArrayHasKey('debug', $parsed['services']);
        self::assertArrayNotHasKey('labels', $parsed['services']['debug']);
    }

    public function testGenerateOverrideDoesNotAddExtraHosts(): void
    {
        $projectDir = $this->createProjectDir(<<<'YAML'
            services:
              web:
                image: nginx:latest
            YAML);

        $config = $this->makeResolvedConfigWithServices([
            new \App\Model\ServiceDefinition(name: 'mariadb', version: '10.6'),
        ]);

        $overridePath = $this->manager->generateOverride($config, $projectDir);
        $parsed = Yaml::parseFile($overridePath, Yaml::PARSE_CUSTOM_TAGS);

        self::assertArrayNotHasKey('extra_hosts', $parsed['services']['web']);
    }

    private function makeResolvedConfig(string $projectName = 'test-project'): ResolvedConfig
    {
        $global = new GlobalConfig(
            output: GlobalConfigDefinition::OUTPUT,
            dnsForward: GlobalConfigDefinition::DNS_FORWARD,
            sshKeys: GlobalConfigDefinition::SSH_KEYS,
            serviceVersions: [],
            warnings: [],
        );
        $project = new ProjectConfig(
            name: $projectName,
            services: [],
            containers: [],
        );

        return ResolvedConfig::merge($global, $project);
    }

    /**
     * @param list<\App\Model\ServiceDefinition> $services
     */
    private function makeResolvedConfigWithServices(array $services, string $projectName = 'test-project'): ResolvedConfig
    {
        $global = new GlobalConfig(
            output: GlobalConfigDefinition::OUTPUT,
            dnsForward: GlobalConfigDefinition::DNS_FORWARD,
            sshKeys: GlobalConfigDefinition::SSH_KEYS,
            serviceVersions: [],
            warnings: [],
        );
        $project = new ProjectConfig(
            name: $projectName,
            services: $services,
            containers: [],
        );

        return ResolvedConfig::merge($global, $project);
    }

    private function createProjectDir(string $composeContent): string
    {
        $projectDir = $this->tempDir.'/project_'.bin2hex(random_bytes(4));
        $this->filesystem->mkdir($projectDir);
        $this->filesystem->dumpFile($projectDir.'/docker-compose.yml', $composeContent);

        return $projectDir;
    }

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->tempDir = sys_get_temp_dir().'/dde_override_integration_'.bin2hex(random_bytes(8));
        $this->filesystem->mkdir($this->tempDir);

        $userContext = new UserContext('1000', '1000');

        $dockerManager = $this->createStub(DockerManager::class);
        $dockerManager->method('imageHasShell')->willReturn(true);
        $dockerManager->method('inspectImage')->willReturn('null');

        $traefikService = new TraefikService(
            dockerManager: $dockerManager,
            filesystem: $this->filesystem,
            dataDir: $this->tempDir.'/data',
        );

        $this->adapterRegistry = new AdapterRegistry(
            resourcesDir: dirname(__DIR__, 3).'/resources',
            dataDir: $this->tempDir.'/data',
        );

        $this->manager = new DockerComposeManager(
            adapterRegistry: $this->adapterRegistry,
            dockerManager: $dockerManager,
            traefikService: $traefikService,
            userContext: $userContext,
            worktreeManager: new WorktreeManager(new ProcessFactory()),
        );
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->tempDir);
    }
}
