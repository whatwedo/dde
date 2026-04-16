<?php

declare(strict_types=1);

namespace Tests\Unit\Command\Project;

use App\Command\Project\ProjectInitCommand;
use App\Manager\DockerComposeManager;
use App\Manager\DockerManager;
use App\Manager\ProjectConfigManager;
use App\Manager\ProjectInitAdaptationManager;
use App\Manager\ProjectInitManager;
use App\Output\FormatterResolver;
use App\Output\JsonFormatter;
use App\Output\TextFormatter;
use App\Parser\DockerComposeParser;
use App\Parser\DockerfileParser;
use App\Service\TraefikService;
use App\Util\DockerComposeModifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class ProjectInitCommandTest extends TestCase
{
    private string $tempDir;

    private CommandTester $commandTester;

    public function testExecuteCreatesDirectoryStructure(): void
    {
        $this->commandTester->execute([
            '--name' => 'test-project',
            '--container' => 'web',
            '--shell' => 'bash',
            '--no-docker' => true,
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $this->assertDirectoryExists($this->tempDir.'/.dde');
        $this->assertDirectoryExists($this->tempDir.'/.dde/data');
        $this->assertDirectoryExists($this->tempDir.'/.dde/snapshots');
        $this->assertDirectoryExists($this->tempDir.'/.dde/hooks/project.up.pre');
        $this->assertDirectoryExists($this->tempDir.'/.dde/hooks/project.up.post');
        $this->assertDirectoryExists($this->tempDir.'/.dde/hooks/project.down.pre');
        $this->assertDirectoryExists($this->tempDir.'/.dde/hooks/project.down.post');
        $this->assertDirectoryExists($this->tempDir.'/.dde/adapters');
        $this->assertDirectoryExists($this->tempDir.'/.dde/plugins');
    }

    public function testExecuteCreatesGitignore(): void
    {
        $this->commandTester->execute([
            '--name' => 'test-project',
            '--no-docker' => true,
        ], [
            'interactive' => false,
        ]);

        $gitignorePath = $this->tempDir.'/.dde/.gitignore';
        $this->assertFileExists($gitignorePath);
        $this->assertSame("data/\n!data/.gitkeep\nsnapshots/\n!snapshots/.gitkeep\n", file_get_contents($gitignorePath));
    }

    public function testExecuteCreatesConfigYaml(): void
    {
        $this->commandTester->execute([
            '--name' => 'test-project',
            '--services' => 'mariadb,valkey',
            '--container' => 'app',
            '--shell' => 'zsh',
            '--no-docker' => true,
        ], [
            'interactive' => false,
        ]);

        $configPath = $this->tempDir.'/.dde/config.yml';
        $this->assertFileExists($configPath);

        $content = file_get_contents($configPath);
        $this->assertIsString($content);
        $this->assertStringContainsString('mariadb', $content);
        $this->assertStringContainsString('valkey', $content);
        $this->assertStringContainsString('app', $content);
        $this->assertStringContainsString('zsh', $content);
    }

    public function testExecuteRemovesLegacyDdeYml(): void
    {
        file_put_contents($this->tempDir.'/.dde.yml', "version: 1\n");

        $this->commandTester->execute([
            '--name' => 'test-project',
            '--no-docker' => true,
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $this->assertFileDoesNotExist($this->tempDir.'/.dde.yml');
    }

    public function testExecuteDryRunDoesNotRemoveLegacyDdeYml(): void
    {
        file_put_contents($this->tempDir.'/.dde.yml', "version: 1\n");

        $this->commandTester->execute([
            '--name' => 'test-project',
            '--no-docker' => true,
            '--dry-run' => true,
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $this->assertFileExists($this->tempDir.'/.dde.yml');
    }

    public function testExecuteRemovesLegacyConfigureImageScript(): void
    {
        mkdir($this->tempDir.'/.dde', 0o755, true);
        file_put_contents($this->tempDir.'/.dde/configure-image.sh', "#!/bin/bash\n");

        $this->commandTester->execute([
            '--name' => 'test-project',
            '--no-docker' => true,
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $this->assertFileDoesNotExist($this->tempDir.'/.dde/configure-image.sh');
    }

    public function testExecuteDryRunDoesNotRemoveLegacyConfigureImageScript(): void
    {
        mkdir($this->tempDir.'/.dde', 0o755, true);
        file_put_contents($this->tempDir.'/.dde/configure-image.sh', "#!/bin/bash\n");

        $this->commandTester->execute([
            '--name' => 'test-project',
            '--no-docker' => true,
            '--dry-run' => true,
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $this->assertFileExists($this->tempDir.'/.dde/configure-image.sh');
    }

    public function testExecuteWithDryRunDoesNotCreateFiles(): void
    {
        $this->commandTester->execute([
            '--name' => 'test-project',
            '--no-docker' => true,
            '--dry-run' => true,
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $this->assertDirectoryDoesNotExist($this->tempDir.'/.dde');
    }

    public function testExecuteSkipsExistingDirectories(): void
    {
        mkdir($this->tempDir.'/.dde', 0o755, true);
        mkdir($this->tempDir.'/.dde/data', 0o755, true);

        $this->commandTester->execute([
            '--name' => 'test-project',
            '--no-docker' => true,
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Skipped', $output);
    }

    public function testExecuteWithJsonOutput(): void
    {
        $this->commandTester->execute([
            '--name' => 'test-project',
            '--no-docker' => true,
            '--output' => 'json',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertSame('ok', $decoded['status']);
    }

    public function testExecuteWithDefaultsUsesDirectoryName(): void
    {
        $this->commandTester->execute([
            '--no-docker' => true,
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $configPath = $this->tempDir.'/.dde/config.yml';
        $this->assertFileExists($configPath);
    }

    public function testExecuteIdempotentUpdatesExistingConfig(): void
    {
        mkdir($this->tempDir.'/.dde', 0o755, true);
        file_put_contents($this->tempDir.'/.dde/config.yml', "services: []\n");

        $this->commandTester->execute([
            '--name' => 'test-project',
            '--no-docker' => true,
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        // Config should be updated with new values
        $content = (string) file_get_contents($this->tempDir.'/.dde/config.yml');
        $this->assertStringContainsString('name: test-project', $content);
        $this->assertStringContainsString('containers', $content);
    }

    public function testExecuteAdaptsDockerComposeFile(): void
    {
        $composeContent = <<<'YAML'
            services:
                web:
                    image: nginx
                    environment:
                        - VIRTUAL_HOST=example.test
            YAML;
        file_put_contents($this->tempDir.'/docker-compose.yml', $composeContent);

        $this->commandTester->execute([
            '--name' => 'test-project',
            '--force' => true,
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $content = file_get_contents($this->tempDir.'/docker-compose.yml');
        $this->assertIsString($content);
        $this->assertStringContainsString('traefik.enable=true', $content);
        $this->assertStringNotContainsString('VIRTUAL_HOST', $content);
    }

    public function testExecuteAdaptsDockerfileRemovesV1Boilerplate(): void
    {
        $dockerfileContent = <<<'DOCKERFILE'
            FROM php:8.2-fpm AS base
            RUN apt-get update
            FROM base AS dev
            COPY .dde/configure-image.sh /tmp/dde-configure-image.sh
            ARG DDE_UID=1000
            ARG DDE_GID=1000
            RUN /tmp/dde-configure-image.sh
            RUN echo "dev tools"
            FROM base AS prod
            COPY . /app
            DOCKERFILE;
        file_put_contents($this->tempDir.'/Dockerfile', $dockerfileContent);

        $this->commandTester->execute([
            '--name' => 'test-project',
            '--force' => true,
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $content = file_get_contents($this->tempDir.'/Dockerfile');
        $this->assertIsString($content);
        $this->assertStringNotContainsString('DDE_UID', $content);
        $this->assertStringNotContainsString('DDE_GID', $content);
        $this->assertStringNotContainsString('configure-image.sh', $content);
        $this->assertStringContainsString('dev tools', $content);
        $this->assertStringContainsString('FROM base AS prod', $content);
        $this->assertStringContainsString('COPY . /app', $content);
    }

    public function testExecuteWithForceSkipsConfirmation(): void
    {
        $composeContent = <<<'YAML'
            services:
                web:
                    image: nginx
                    environment:
                        - VIRTUAL_HOST=example.test
            YAML;
        file_put_contents($this->tempDir.'/docker-compose.yml', $composeContent);

        $this->commandTester->execute([
            '--name' => 'test-project',
            '--force' => true,
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $content = file_get_contents($this->tempDir.'/docker-compose.yml');
        $this->assertIsString($content);
        $this->assertStringContainsString('traefik.enable=true', $content);
    }

    public function testExecuteWithNoServicesProducesConfigWithoutServices(): void
    {
        $this->commandTester->execute([
            '--name' => 'test-project',
            '--no-docker' => true,
        ], [
            'interactive' => false,
        ]);

        $configPath = $this->tempDir.'/.dde/config.yml';
        $content = file_get_contents($configPath);
        $this->assertIsString($content);
        $this->assertStringNotContainsString('services', $content);
        $this->assertStringContainsString('containers', $content);
        $this->assertStringContainsString('web', $content);
    }

    public function testDetectsFirstServiceFromComposeFile(): void
    {
        $composeContent = <<<'YAML'
            services:
                app:
                    image: php:8.5
                db:
                    image: mariadb:11.8
            YAML;
        file_put_contents($this->tempDir.'/docker-compose.yml', $composeContent);

        $this->commandTester->execute([
            '--name' => 'test-project',
            '--force' => true,
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
    }

    public function testAdaptComposeFileMigratesSshAgentVolumeInOtherServices(): void
    {
        $composeContent = <<<'YAML'
            services:
              app:
                image: php:8.3
              storybook:
                image: node:20
                environment:
                  - SSH_AUTH_SOCK=/tmp/ssh-agent/socket
                volumes:
                  - ssh-agent_socket-dir:/tmp/ssh-agent:ro
            volumes:
              ssh-agent_socket-dir:
                external: true
            YAML;
        file_put_contents($this->tempDir.'/docker-compose.yml', $composeContent);

        $this->commandTester->execute([
            '--name' => 'test-project',
            '--container' => 'app',
            '--force' => true,
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $content = file_get_contents($this->tempDir.'/docker-compose.yml');
        $this->assertIsString($content);
        $this->assertStringContainsString('dde_ssh-agent_socket-dir', $content);
        $this->assertStringNotContainsString("'ssh-agent_socket-dir:/tmp/ssh-agent:ro'", $content);
    }

    public function testAdaptComposeFileReturnsGracefullyOnInvalidFile(): void
    {
        file_put_contents($this->tempDir.'/docker-compose.yml', "invalid: yaml: content: [broken\n");

        $this->commandTester->execute([
            '--name' => 'test-project',
            '--force' => true,
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
    }

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/dde_test_init_'.bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o755, true);

        $dockerComposeManager = $this->createStub(DockerComposeManager::class);
        $dockerComposeManager->method('findComposeFileOrNull')->willReturnCallback(
            function (string $projectDir): ?string {
                foreach (['docker-compose.yml', 'docker-compose.yaml', 'compose.yml', 'compose.yaml'] as $candidate) {
                    $path = $projectDir.'/'.$candidate;
                    if (file_exists($path)) {
                        return $path;
                    }
                }

                return null;
            },
        );

        $dockerManager = $this->createStub(DockerManager::class);
        $traefikService = new TraefikService(
            dockerManager: $dockerManager,
            filesystem: new Filesystem(),
            dataDir: sys_get_temp_dir(),
        );

        $dockerComposeParser = new DockerComposeParser();
        $dockerComposeModifier = new DockerComposeModifier(
            databaseAdapterRegistry: new \App\Database\DatabaseAdapterRegistry([
                new \App\Database\MariaDbAdapter(),
                new \App\Database\PostgresAdapter(),
            ]),
            traefikService: $traefikService,
        );
        $dockerfileParser = new DockerfileParser();

        $configManager = $this->createStub(ProjectConfigManager::class);

        $adaptationManager = new ProjectInitAdaptationManager(
            $dockerComposeManager,
            $dockerComposeParser,
            $dockerComposeModifier,
            $dockerfileParser,
        );

        $command = new ProjectInitCommand(
            $configManager,
            new ProjectInitManager(new Filesystem()),
            $adaptationManager,
            $dockerComposeManager,
            new FormatterResolver(new TextFormatter(), new JsonFormatter()),
        );

        $application = new Application();
        $application->getDefinition()->addOption(new InputOption(
            'output',
            'o',
            InputOption::VALUE_REQUIRED,
            'Output format',
            'text',
        ));
        $application->addCommand($command);

        // Override getcwd for tests
        chdir($this->tempDir);

        $this->commandTester = new CommandTester($command);
    }

    protected function tearDown(): void
    {
        $filesystem = new Filesystem();

        if (is_dir($this->tempDir)) {
            $filesystem->remove($this->tempDir);
        }
    }
}
