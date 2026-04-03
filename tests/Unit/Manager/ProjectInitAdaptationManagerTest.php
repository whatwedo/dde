<?php

declare(strict_types=1);

namespace Tests\Unit\Manager;

use App\Manager\DockerComposeManager;
use App\Manager\ProjectInitAdaptationManager;
use App\Parser\DockerComposeParser;
use App\Parser\DockerfileParser;
use App\Util\DockerComposeModifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ProjectInitAdaptationManagerTest extends TestCase
{
    private string $tempDir;

    private ProjectInitAdaptationManager $manager;

    public function testDetectFirstServiceReturnsFirstService(): void
    {
        $composeContent = <<<'YAML'
            services:
                app:
                    image: php:8.5
                db:
                    image: mariadb:11.8
            YAML;
        $composePath = $this->tempDir.'/docker-compose.yml';
        file_put_contents($composePath, $composeContent);

        $result = $this->manager->detectFirstService($composePath);

        $this->assertSame('app', $result);
    }

    public function testDetectFirstServiceReturnsNullForInvalidFile(): void
    {
        $composePath = $this->tempDir.'/docker-compose.yml';
        file_put_contents($composePath, "invalid: yaml: content: [broken\n");

        $result = $this->manager->detectFirstService($composePath);

        $this->assertNull($result);
    }

    public function testDetectFirstServiceReturnsNullForNull(): void
    {
        $result = $this->manager->detectFirstService(null);

        $this->assertNull($result);
    }

    public function testAdaptComposeAddsNetworkAndLabels(): void
    {
        $composeContent = <<<'YAML'
            services:
                web:
                    image: nginx
                    environment:
                        - VIRTUAL_HOST=example.test
            YAML;
        $composePath = $this->tempDir.'/docker-compose.yml';
        file_put_contents($composePath, $composeContent);

        $result = $this->manager->adaptCompose($composePath, 'test-project', 'web');

        $this->assertNotNull($result);
        $this->assertNotEmpty($result['changes']);
        $this->assertNotEmpty($result['diff']);
        $this->assertSame($composePath, $result['composePath']);

        // Verify network was added
        $hasNetworkChange = false;
        $hasTraefikChange = false;

        foreach ($result['changes'] as $change) {
            if (str_contains($change, 'network')) {
                $hasNetworkChange = true;
            }

            if (str_contains($change, 'Traefik')) {
                $hasTraefikChange = true;
            }
        }

        $this->assertTrue($hasNetworkChange, 'Expected network change');
        $this->assertTrue($hasTraefikChange, 'Expected Traefik labels change');

        // Verify the config has the network
        $this->assertArrayHasKey('networks', $result['config']);
        $this->assertSame('dde', $result['config']['networks']['default']['name']);
    }

    public function testAdaptComposeRemovesContainerName(): void
    {
        $composeContent = <<<'YAML'
            services:
                web:
                    container_name: my-web
                    image: nginx
                    labels:
                        - 'traefik.enable=true'
                        - 'traefik.http.routers.web.rule=Host(`test-project.test`)'
                        - 'traefik.http.routers.web.tls=true'
                    volumes:
                        - 'dde_ssh-agent_socket-dir:/tmp/ssh-agent:ro'
                guacd:
                    container_name: my-guacd
                    image: guacd:1.5.3
            networks:
                default:
                    name: dde
                    external: true
            volumes:
                dde_ssh-agent_socket-dir:
                    external: true
            YAML;
        $composePath = $this->tempDir.'/docker-compose.yml';
        file_put_contents($composePath, $composeContent);

        $result = $this->manager->adaptCompose($composePath, 'test-project', 'web');

        $this->assertNotNull($result);
        $this->assertNotEmpty($result['changes']);

        // container_name should be removed from both services
        $this->assertArrayNotHasKey('container_name', $result['config']['services']['web']);
        $this->assertArrayNotHasKey('container_name', $result['config']['services']['guacd']);

        $containerNameChanges = array_filter(
            $result['changes'],
            static fn (string $c): bool => str_contains($c, 'container_name'),
        );
        $this->assertCount(2, $containerNameChanges);
    }

    public function testAdaptComposeReturnsEmptyWhenNoChanges(): void
    {
        $composeContent = <<<'YAML'
            services:
                web:
                    image: nginx
                    labels:
                        - 'traefik.enable=true'
                        - 'traefik.http.routers.web.rule=Host(`test-project.test`)'
                        - 'traefik.http.routers.web.tls=true'
                    volumes:
                        - 'dde_ssh-agent_socket-dir:/tmp/ssh-agent:ro'
            networks:
                default:
                    name: dde
                    external: true
            volumes:
                dde_ssh-agent_socket-dir:
                    external: true
            YAML;
        $composePath = $this->tempDir.'/docker-compose.yml';
        file_put_contents($composePath, $composeContent);

        $result = $this->manager->adaptCompose($composePath, 'test-project', 'web');

        $this->assertNotNull($result);
        $this->assertSame([], $result['changes']);
        $this->assertSame('', $result['diff']);
    }

    public function testAdaptComposeReturnsNullForInvalidFile(): void
    {
        $composePath = $this->tempDir.'/docker-compose.yml';
        file_put_contents($composePath, "invalid: yaml: content: [broken\n");

        $result = $this->manager->adaptCompose($composePath, 'test-project', 'web');

        $this->assertNull($result);
    }

    public function testAdaptDockerfileRemovesV1Boilerplate(): void
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
        $dockerfilePath = $this->tempDir.'/Dockerfile';
        file_put_contents($dockerfilePath, $dockerfileContent);

        $result = $this->manager->adaptDockerfile($dockerfilePath);

        $this->assertNotNull($result);
        $this->assertNotEmpty($result['changes']);
        $this->assertNotEmpty($result['diff']);
        $this->assertSame($dockerfilePath, $result['dockerfilePath']);

        // Verify boilerplate is removed from the cleaned lines
        $content = implode("\n", $result['lines']);
        $this->assertStringNotContainsString('DDE_UID', $content);
        $this->assertStringNotContainsString('DDE_GID', $content);
        $this->assertStringNotContainsString('configure-image.sh', $content);
        $this->assertStringContainsString('dev tools', $content);
    }

    public function testAdaptDockerfileReturnsEmptyWhenNoBoilerplate(): void
    {
        $dockerfileContent = <<<'DOCKERFILE'
            FROM php:8.2-fpm AS base
            RUN apt-get update
            FROM base AS dev
            RUN echo "dev tools"
            DOCKERFILE;
        $dockerfilePath = $this->tempDir.'/Dockerfile';
        file_put_contents($dockerfilePath, $dockerfileContent);

        $result = $this->manager->adaptDockerfile($dockerfilePath);

        $this->assertNotNull($result);
        $this->assertSame([], $result['changes']);
        $this->assertSame('', $result['diff']);
    }

    public function testAdaptDockerfileReturnsNullForMissingFile(): void
    {
        $result = $this->manager->adaptDockerfile($this->tempDir.'/nonexistent/Dockerfile');

        $this->assertNull($result);
    }

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/dde_test_adaptation_'.bin2hex(random_bytes(8));
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

        $dockerManager = $this->createStub(\App\Manager\DockerManager::class);
        $traefikService = new \App\Service\TraefikService(
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

        $this->manager = new ProjectInitAdaptationManager(
            $dockerComposeManager,
            $dockerComposeParser,
            $dockerComposeModifier,
            $dockerfileParser,
        );
    }

    protected function tearDown(): void
    {
        $filesystem = new Filesystem();

        if (is_dir($this->tempDir)) {
            $filesystem->remove($this->tempDir);
        }
    }
}
