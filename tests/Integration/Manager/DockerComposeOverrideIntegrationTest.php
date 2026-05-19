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

        // Networks — always the per-project network derived from the config,
        // never the shared `dde` network.
        self::assertIsArray($parsed['networks']);
        self::assertArrayHasKey('dde-services-test-project', $parsed['networks']);
        self::assertArrayNotHasKey('dde', $parsed['networks']);

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

    public function testGenerateOverrideWithWorktreeInfoRewritesDeclaredTraefikLabels(): void
    {
        $projectDir = $this->createProjectDir(<<<'YAML'
            services:
              web:
                image: nginx:latest
                labels:
                  - "traefik.enable=true"
                  - "traefik.http.routers.web.rule=Host(`myproject.test`)"
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

        $joined = implode("\n", $labels);
        self::assertStringContainsString('Host(`myproject-feature-branch.test`)', $joined);
    }

    public function testGenerateOverrideHandlesTaggedLabelsInWorktreeRewriter(): void
    {
        // A base file with `labels: !override [...]` survives parsing thanks
        // to PARSE_CUSTOM_TAGS, but the worktree rewriter then receives the
        // labels as a TaggedValue. Without unwrapping, the type would not
        // match the rewriter's `array` parameter.
        $projectDir = $this->createProjectDir(<<<'YAML'
            services:
              web:
                image: nginx:latest
                labels: !override
                  - "traefik.http.routers.web.rule=Host(`myproject.test`)"
            YAML);

        $worktreeInfo = new WorktreeInfo(
            mainDirectory: '/main',
            worktreeDirectory: $projectDir,
            branch: 'feature/x',
            suffix: 'feature-x',
        );

        $config = $this->makeResolvedConfig('myproject');
        $overridePath = $this->manager->generateOverride($config, $projectDir, $worktreeInfo);

        $parsed = Yaml::parseFile($overridePath, Yaml::PARSE_CUSTOM_TAGS);
        $labels = $parsed['services']['web']['labels']->getValue();

        $joined = implode("\n", $labels);
        self::assertStringContainsString('Host(`myproject-feature-x.test`)', $joined);
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
        // Service `networks:` is emitted with `!override` so Compose replaces
        // (not merges) any networks from the base compose. The wrapped value
        // contains exactly the per-project network; no `dde` membership can
        // slip in through a hand-edited or legacy `networks: [dde]` on the
        // base service.
        self::assertInstanceOf(\Symfony\Component\Yaml\Tag\TaggedValue::class, $web['networks']);
        self::assertSame('override', $web['networks']->getTag());
        self::assertSame([
            'dde-services-myproject' => null,
        ], $web['networks']->getValue());

        self::assertContains('traefik.docker.network=dde-services-myproject', $web['labels']);
    }

    public function testGenerateOverrideDropsLegacyDdeNetworkFromService(): void
    {
        // Regression: when the base compose attached the service to the
        // shared `dde` network (legacy v1 layout or a hand-edited file), a
        // plain merge kept that membership next to the per-project network —
        // reintroducing the cross-checkout DNS alias collision that the
        // per-project isolation was meant to prevent. The overlay now emits
        // service networks with `!override`, so the base list is replaced
        // outright and the service joins exactly the per-project network.
        $projectDir = $this->createProjectDir(<<<'YAML'
            services:
              web:
                image: nginx:latest
                networks:
                  - dde
            networks:
              dde:
                external: true
            YAML);

        $config = $this->makeResolvedConfig('myproject');
        $overridePath = $this->manager->generateOverride($config, $projectDir);

        $parsed = Yaml::parseFile($overridePath, Yaml::PARSE_CUSTOM_TAGS);

        $web = $parsed['services']['web'];
        self::assertInstanceOf(\Symfony\Component\Yaml\Tag\TaggedValue::class, $web['networks']);
        self::assertSame('override', $web['networks']->getTag());
        self::assertSame([
            'dde-services-myproject' => null,
        ], $web['networks']->getValue());
    }

    public function testGenerateOverrideDerivesProjectNetworkFromConfigWhenNotProvided(): void
    {
        // Standalone caller does not pass `$projectNetwork`. The overlay must
        // still emit a per-project network (derived from `$config->projectName`)
        // and the matching Traefik label — there is no fallback to the shared
        // `dde` network anymore.
        $projectDir = $this->createProjectDir(<<<'YAML'
            services:
              web:
                image: nginx:latest
            YAML);

        $config = $this->makeResolvedConfig('myproject');
        $overridePath = $this->manager->generateOverride($config, $projectDir);

        $parsed = Yaml::parseFile($overridePath, Yaml::PARSE_CUSTOM_TAGS);

        self::assertIsArray($parsed['networks']);
        self::assertArrayHasKey('dde-services-myproject', $parsed['networks']);
        self::assertArrayNotHasKey('dde', $parsed['networks']);

        self::assertContains(
            'traefik.docker.network=dde-services-myproject',
            $parsed['services']['web']['labels'],
        );
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

        $this->adapterRegistry = new AdapterRegistry(
            resourcesDir: dirname(__DIR__, 3).'/resources',
            dataDir: $this->tempDir.'/data',
        );

        $this->manager = new DockerComposeManager(
            adapterRegistry: $this->adapterRegistry,
            dockerManager: $dockerManager,
            userContext: $userContext,
            worktreeManager: new WorktreeManager(new ProcessFactory()),
        );
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->tempDir);
    }
}
