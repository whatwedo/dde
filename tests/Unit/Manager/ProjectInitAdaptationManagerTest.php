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

    public function testAdaptComposeAddsTraefikLabelsAndRemovesDdeBoilerplate(): void
    {
        $composeContent = <<<'YAML'
            services:
                web:
                    image: nginx
                    environment:
                        - VIRTUAL_HOST=example.test
            networks:
                default:
                    name: dde
                    external: true
            YAML;
        $composePath = $this->tempDir.'/docker-compose.yml';
        file_put_contents($composePath, $composeContent);

        $result = $this->manager->adaptCompose($composePath, 'test-project', 'web');

        $this->assertNotNull($result);
        $this->assertNotEmpty($result['changes']);
        $this->assertNotEmpty($result['diff']);
        $this->assertSame($composePath, $result['composePath']);

        // Verify network was removed (now injected by overlay)
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

        // Verify the dde network boilerplate has been removed from config
        $this->assertArrayNotHasKey('networks', $result['config']);
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

    public function testAdaptComposeRemovesDdeNetworkBoilerplate(): void
    {
        $composePath = $this->createTempCompose(<<<'YAML'
            services:
              web:
                image: nginx:latest
            networks:
              default:
                name: dde
                external: true
            YAML);

        $result = $this->manager->adaptCompose($composePath, 'myproject', 'web');

        $this->assertNotNull($result);
        $this->assertContains('Removed external "dde" network (now injected by dde overlay)', $result['changes']);
    }

    public function testAdaptComposeRemovesSshAgentBoilerplate(): void
    {
        $composePath = $this->createTempCompose(<<<'YAML'
            services:
              web:
                image: nginx:latest
                volumes:
                  - 'dde_ssh-agent_socket-dir:/tmp/ssh-agent:ro'
                environment:
                  - 'SSH_AUTH_SOCK=/tmp/ssh-agent/socket'
            volumes:
              dde_ssh-agent_socket-dir:
                external: true
            YAML);

        $result = $this->manager->adaptCompose($composePath, 'myproject', 'web');

        $this->assertNotNull($result);
        $this->assertContains('Removed SSH-Agent volume from service "web" (now injected by dde overlay)', $result['changes']);
    }

    public function testAdaptComposeRemovesOpenUrlEnvVar(): void
    {
        $composePath = $this->createTempCompose(<<<'YAML'
            services:
              web:
                image: nginx:latest
                environment:
                  - 'OPEN_URL=https://myproject.test'
            YAML);

        $result = $this->manager->adaptCompose($composePath, 'myproject', 'web');

        $this->assertNotNull($result);
        $this->assertContains('Removed legacy OPEN_URL from service "web"', $result['changes']);
    }

    public function testAdaptComposeReturnsEmptyWhenNoChanges(): void
    {
        // A fully adapted compose file: no dde boilerplate, traefik labels already present
        $composeContent = <<<'YAML'
            services:
                web:
                    image: nginx
                    labels:
                        - 'traefik.enable=true'
                        - 'traefik.http.routers.web.rule=Host(`test-project.test`)'
                        - 'traefik.http.routers.web.tls=true'
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

    public function testProposeEnvMigrationsNeverInjectsAppEnvIntoCompose(): void
    {
        // Symfony stops loading .env files when APP_ENV is set in the environment, so
        // dde must not move APP_ENV from .env into the docker-compose service definition.
        $composePath = $this->tempDir.'/docker-compose.yml';
        file_put_contents($composePath, <<<'YAML'
            services:
              web:
                image: php:8.5
            YAML);
        $originalContent = file_get_contents($composePath);

        $result = $this->manager->proposeEnvMigrations($this->tempDir, 'beispiel', 'web', [], []);

        $this->assertSame([], $result['appliedChanges']);
        $this->assertSame($originalContent, file_get_contents($composePath));
    }

    public function testProposeEnvMigrationsLeavesExistingAppEnvUntouched(): void
    {
        $composePath = $this->tempDir.'/docker-compose.yml';
        file_put_contents($composePath, <<<'YAML'
            services:
              web:
                image: php:8.5
                environment:
                  APP_ENV: prod
            YAML);
        $originalContent = file_get_contents($composePath);

        $this->manager->proposeEnvMigrations($this->tempDir, 'beispiel', 'web', [], []);

        // APP_ENV in compose is not touched — even when it diverges from "dev"
        $this->assertSame($originalContent, file_get_contents($composePath));
    }

    public function testProposeEnvMigrationsAppliesMailerDsnWhenMailpitConfigured(): void
    {
        $composePath = $this->tempDir.'/docker-compose.yml';
        file_put_contents($composePath, <<<'YAML'
            services:
              web:
                image: php:8.5
            YAML);
        file_put_contents($this->tempDir.'/.env', "MAILER_DSN=smtp://whatever:25\n");

        $this->manager->proposeEnvMigrations($this->tempDir, 'beispiel', 'web', ['mailpit'], []);

        $config = \Symfony\Component\Yaml\Yaml::parseFile($composePath);
        $env = $config['services']['web']['environment'] ?? [];
        $hasCompose = false;

        foreach ($env as $k => $v) {
            if (($k === 'MAILER_DSN' && $v === 'smtp://mailpit:1025')
                || (is_int($k) && $v === 'MAILER_DSN=smtp://mailpit:1025')) {
                $hasCompose = true;
            }
        }

        $this->assertTrue($hasCompose, 'Expected MAILER_DSN=smtp://mailpit:1025 in compose');

        $envContent = (string) file_get_contents($this->tempDir.'/.env');
        $this->assertStringContainsString('MAILER_DSN=null://null', $envContent);
    }

    public function testProposeEnvMigrationsSkipsMailerDsnWhenMailpitNotConfigured(): void
    {
        file_put_contents($this->tempDir.'/docker-compose.yml', <<<'YAML'
            services:
              web:
                image: php:8.5
            YAML);
        file_put_contents($this->tempDir.'/.env', "MAILER_DSN=smtp://whatever:25\n");

        $this->manager->proposeEnvMigrations($this->tempDir, 'beispiel', 'web', ['mariadb'], []);

        $envContent = (string) file_get_contents($this->tempDir.'/.env');
        $this->assertStringContainsString('MAILER_DSN=smtp://whatever:25', $envContent);
        $this->assertStringNotContainsString('null://null', $envContent);
    }

    public function testProposeEnvMigrationsHandlesQuotedDatabaseUrlFromEnv(): void
    {
        // Mirrors the fs-brain case: Symfony-style .env with a double-quoted
        // DATABASE_URL whose value contains unescaped & characters. The parser
        // must strip the surrounding quotes; otherwise the URL regex fails on
        // the leading `"` and the proposal is silently dropped.
        file_put_contents($this->tempDir.'/docker-compose.yml', <<<'YAML'
            services:
              web:
                image: php:8.5
            YAML);
        file_put_contents(
            $this->tempDir.'/.env',
            'DATABASE_URL="mysql://root:root@mariadb:3306/fs-brain?serverVersion=10.5.15-mariadb&charset=utf8mb4"'."\n",
        );

        $services = $this->createServiceDefinitions([
            'mariadb' => '11.8',
        ]);

        $result = $this->manager->proposeEnvMigrations($this->tempDir, 'fs-brain', 'web', ['mariadb'], $services);

        $this->assertCount(1, $result['proposals'], 'Quoted DATABASE_URL should yield a proposal');
        $proposal = $result['proposals'][0];
        $this->assertSame('DATABASE_URL', $proposal->variable);
        $this->assertSame(
            'mysql://root:root@mariadb:3306/fs-brain?serverVersion=10.5.15-mariadb&charset=utf8mb4',
            $proposal->originalValue,
            'originalValue must be unquoted',
        );
        $this->assertSame(
            'mysql://app:changeme@127.0.0.1:3306/fs-brain?serverVersion=10.5.15-mariadb&charset=utf8mb4',
            $proposal->envTargetValue,
        );
        $this->assertSame(
            'mysql://root:root@mariadb/fs_brain?serverVersion=11.8.0-MariaDB&charset=utf8mb4',
            $proposal->composeValue,
        );
    }

    public function testApplyEnvMigrationsPreservesDoubleQuotesOnAcceptedProposal(): void
    {
        // Follow-up to the fs-brain scenario: once the user accepts the proposal,
        // the .env write must preserve the original double-quote style so the
        // line still parses cleanly against shells that source .env.
        file_put_contents($this->tempDir.'/docker-compose.yml', <<<'YAML'
            services:
              web:
                image: php:8.5
            YAML);
        file_put_contents(
            $this->tempDir.'/.env',
            'DATABASE_URL="mysql://root:root@mariadb:3306/fs-brain?charset=utf8mb4"'."\n",
        );

        $proposal = new \App\Manager\EnvMigrationProposal(
            variable: 'DATABASE_URL',
            envFile: '.env',
            originalValue: 'mysql://root:root@mariadb:3306/fs-brain?charset=utf8mb4',
            envTargetValue: 'mysql://app:changeme@127.0.0.1:3306/fs-brain?charset=utf8mb4',
            composeValue: 'mysql://root:root@mariadb/fs_brain?serverVersion=11.8.0-MariaDB&charset=utf8mb4',
            description: 'Migrate DATABASE_URL',
        );

        $this->manager->applyEnvMigrations($this->tempDir, 'web', [$proposal]);

        $this->assertSame(
            'DATABASE_URL="mysql://app:changeme@127.0.0.1:3306/fs-brain?charset=utf8mb4"'."\n",
            (string) file_get_contents($this->tempDir.'/.env'),
        );
    }

    public function testProposeEnvMigrationsReturnsDatabaseUrlProposalForMysqlScheme(): void
    {
        file_put_contents($this->tempDir.'/docker-compose.yml', <<<'YAML'
            services:
              web:
                image: php:8.5
            YAML);
        file_put_contents($this->tempDir.'/.env', "DATABASE_URL=mysql://root:secret@localhost:3306/beispiel?serverVersion=8.0\n");

        $services = $this->createServiceDefinitions([
            'mariadb' => '11.8',
        ]);

        $result = $this->manager->proposeEnvMigrations($this->tempDir, 'beispiel', 'web', ['mariadb'], $services);
        $proposals = $result['proposals'];

        $this->assertCount(1, $proposals);
        $this->assertSame('DATABASE_URL', $proposals[0]->variable);
        $this->assertSame('mysql://app:changeme@127.0.0.1:3306/beispiel?serverVersion=8.0', $proposals[0]->envTargetValue);
        $this->assertSame('mysql://root:root@mariadb/beispiel?serverVersion=11.8.0-MariaDB', $proposals[0]->composeValue);
    }

    public function testProposeEnvMigrationsReturnsDatabaseUrlProposalForPostgresScheme(): void
    {
        file_put_contents($this->tempDir.'/docker-compose.yml', <<<'YAML'
            services:
              web:
                image: php:8.5
            YAML);
        file_put_contents($this->tempDir.'/.env', "DATABASE_URL=postgresql://postgres:secret@localhost:5432/beispiel\n");

        $services = $this->createServiceDefinitions([
            'postgres' => '16',
        ]);

        $result = $this->manager->proposeEnvMigrations($this->tempDir, 'beispiel', 'web', ['postgres'], $services);
        $proposals = $result['proposals'];

        $this->assertCount(1, $proposals);
        $this->assertSame('postgresql://app:changeme@127.0.0.1:5432/beispiel', $proposals[0]->envTargetValue);
        $this->assertSame('postgresql://postgres:postgres@postgres/beispiel?serverVersion=16', $proposals[0]->composeValue);
    }

    public function testProposeEnvMigrationsSkipsDatabaseUrlWhenComposeAlreadyHasIt(): void
    {
        file_put_contents($this->tempDir.'/docker-compose.yml', <<<'YAML'
            services:
              web:
                image: php:8.5
                environment:
                  DATABASE_URL: mysql://existing@db/existing
            YAML);
        file_put_contents($this->tempDir.'/.env', "DATABASE_URL=mysql://root:secret@localhost:3306/beispiel\n");

        $services = $this->createServiceDefinitions([
            'mariadb' => '11.8',
        ]);

        $result = $this->manager->proposeEnvMigrations($this->tempDir, 'beispiel', 'web', ['mariadb'], $services);

        $this->assertCount(0, $result['proposals']);
    }

    public function testProposeEnvMigrationsSkipsDatabaseUrlWhenNoEnvEntry(): void
    {
        file_put_contents($this->tempDir.'/docker-compose.yml', <<<'YAML'
            services:
              web:
                image: php:8.5
            YAML);
        file_put_contents($this->tempDir.'/.env', "SOMETHING=else\n");

        $services = $this->createServiceDefinitions([
            'mariadb' => '11.8',
        ]);

        $result = $this->manager->proposeEnvMigrations($this->tempDir, 'beispiel', 'web', ['mariadb'], $services);

        $this->assertCount(0, $result['proposals']);
    }

    public function testProposeEnvMigrationsSkipsDatabaseUrlWhenNoDbService(): void
    {
        file_put_contents($this->tempDir.'/docker-compose.yml', <<<'YAML'
            services:
              web:
                image: php:8.5
            YAML);
        file_put_contents($this->tempDir.'/.env', "DATABASE_URL=mysql://root@localhost:3306/beispiel\n");

        $result = $this->manager->proposeEnvMigrations($this->tempDir, 'beispiel', 'web', [], []);

        $this->assertCount(0, $result['proposals']);
    }

    public function testProposeEnvMigrationsSkipsDatabaseUrlForUnknownScheme(): void
    {
        file_put_contents($this->tempDir.'/docker-compose.yml', <<<'YAML'
            services:
              web:
                image: php:8.5
            YAML);
        file_put_contents($this->tempDir.'/.env', "DATABASE_URL=sqlite:///tmp/db.sqlite\n");

        $services = $this->createServiceDefinitions([
            'mariadb' => '11.8',
        ]);

        $result = $this->manager->proposeEnvMigrations($this->tempDir, 'beispiel', 'web', ['mariadb'], $services);

        $this->assertCount(0, $result['proposals']);
    }

    public function testProposeEnvMigrationsRecognizesPgsqlSchemeForPostgresService(): void
    {
        $composePath = $this->tempDir.'/docker-compose.yml';
        file_put_contents($composePath, <<<'YAML'
            services:
              web:
                image: php:8.5
            YAML);
        file_put_contents($this->tempDir.'/.env', "DATABASE_URL=pgsql://postgres:secret@localhost:5432/beispiel\n");

        $services = $this->createServiceDefinitions([
            'postgres' => '16',
        ]);

        $result = $this->manager->proposeEnvMigrations($this->tempDir, 'beispiel', 'web', ['postgres'], $services);

        $this->assertCount(1, $result['proposals']);
        $this->assertStringContainsString('postgres:postgres@postgres', $result['proposals'][0]->composeValue);
    }

    public function testProposeEnvMigrationsMariadbThreePartVersionDoesNotDoubleAppendZero(): void
    {
        file_put_contents($this->tempDir.'/docker-compose.yml', <<<'YAML'
            services:
              web:
                image: php:8.5
            YAML);
        file_put_contents($this->tempDir.'/.env', "DATABASE_URL=mysql://root:secret@localhost:3306/beispiel\n");

        // Three-part version like 11.4.1 should produce "11.4.1-MariaDB", not "11.4.1.0-MariaDB".
        $services = $this->createServiceDefinitions([
            'mariadb' => '11.4.1',
        ]);

        $result = $this->manager->proposeEnvMigrations($this->tempDir, 'beispiel', 'web', ['mariadb'], $services);

        $this->assertCount(1, $result['proposals']);
        $this->assertStringContainsString('?serverVersion=11.4.1-MariaDB', $result['proposals'][0]->composeValue);
        $this->assertStringNotContainsString('11.4.1.0-MariaDB', $result['proposals'][0]->composeValue);
    }

    public function testProposeEnvMigrationsSkipsMailerDsnWhenEnvHasNoEntryButMailpitConfigured(): void
    {
        $composePath = $this->tempDir.'/docker-compose.yml';
        file_put_contents($composePath, <<<'YAML'
            services:
              web:
                image: php:8.5
            YAML);
        file_put_contents($this->tempDir.'/.env', "SOMETHING=else\n");

        $this->manager->proposeEnvMigrations($this->tempDir, 'beispiel', 'web', ['mailpit'], []);

        // compose should get MAILER_DSN set
        $config = \Symfony\Component\Yaml\Yaml::parseFile($composePath);
        $env = $config['services']['web']['environment'];
        $hasCompose = false;

        foreach ($env as $k => $v) {
            if (($k === 'MAILER_DSN' && $v === 'smtp://mailpit:1025') || (is_int($k) && $v === 'MAILER_DSN=smtp://mailpit:1025')) {
                $hasCompose = true;
            }
        }

        $this->assertTrue($hasCompose);

        // .env should remain untouched (no MAILER_DSN line to replace)
        $envContent = file_get_contents($this->tempDir.'/.env');
        $this->assertSame("SOMETHING=else\n", $envContent);
    }

    public function testApplyEnvMigrationsWritesAcceptedProposal(): void
    {
        $composePath = $this->tempDir.'/docker-compose.yml';
        file_put_contents($composePath, <<<'YAML'
            services:
              web:
                image: php:8.5
            YAML);
        file_put_contents($this->tempDir.'/.env', "DATABASE_URL=mysql://root:secret@localhost:3306/beispiel\n");

        $proposal = new \App\Manager\EnvMigrationProposal(
            variable: 'DATABASE_URL',
            envFile: '.env',
            originalValue: 'mysql://root:secret@localhost:3306/beispiel',
            envTargetValue: 'mysql://app:changeme@127.0.0.1:3306/beispiel',
            composeValue: 'mysql://root:root@mariadb/beispiel?serverVersion=11.8.0-MariaDB',
            description: 'Migrate DATABASE_URL',
        );

        $this->manager->applyEnvMigrations($this->tempDir, 'web', [$proposal]);

        $envContent = (string) file_get_contents($this->tempDir.'/.env');
        $this->assertStringContainsString('DATABASE_URL=mysql://app:changeme@127.0.0.1:3306/beispiel', $envContent);

        $config = \Symfony\Component\Yaml\Yaml::parseFile($composePath);
        $env = $config['services']['web']['environment'];
        $hasCompose = false;

        foreach ($env as $k => $v) {
            $found = ($k === 'DATABASE_URL' && $v === 'mysql://root:root@mariadb/beispiel?serverVersion=11.8.0-MariaDB')
                || (is_int($k) && $v === 'DATABASE_URL=mysql://root:root@mariadb/beispiel?serverVersion=11.8.0-MariaDB');

            if ($found) {
                $hasCompose = true;
            }
        }

        $this->assertTrue($hasCompose);
    }

    public function testApplyEnvMigrationsNoopWhenProposalsEmpty(): void
    {
        $composePath = $this->createTempCompose(<<<'YAML'
            services:
              web:
                image: php:8.5
            YAML);
        file_put_contents($this->tempDir.'/.env', "DATABASE_URL=mysql://root@localhost:3306/beispiel\n");

        $originalEnv = file_get_contents($this->tempDir.'/.env');
        $originalCompose = file_get_contents($composePath);

        $this->manager->applyEnvMigrations($this->tempDir, 'web', []);

        $this->assertSame($originalEnv, file_get_contents($this->tempDir.'/.env'));
        $this->assertSame($originalCompose, file_get_contents($composePath));
    }

    // --- env parsing: readEnvVariable / writeEnvVariable ---------------------

    public function testReadEnvVariableReturnsNullWhenFileMissing(): void
    {
        $this->assertNull($this->manager->readEnvVariable($this->tempDir, '.env', 'DATABASE_URL'));
    }

    public function testReadEnvVariableReturnsNullWhenVariableAbsent(): void
    {
        file_put_contents($this->tempDir.'/.env', "APP_ENV=dev\n");

        $this->assertNull($this->manager->readEnvVariable($this->tempDir, '.env', 'DATABASE_URL'));
    }

    public function testReadEnvVariableReadsUnquotedValue(): void
    {
        file_put_contents($this->tempDir.'/.env', "APP_ENV=dev\n");

        $this->assertSame('dev', $this->manager->readEnvVariable($this->tempDir, '.env', 'APP_ENV'));
    }

    public function testReadEnvVariableStripsDoubleQuotes(): void
    {
        file_put_contents($this->tempDir.'/.env', 'DATABASE_URL="mysql://root:root@mariadb:3306/fs-brain?serverVersion=10.5.15-mariadb&charset=utf8mb4"'."\n");

        $this->assertSame(
            'mysql://root:root@mariadb:3306/fs-brain?serverVersion=10.5.15-mariadb&charset=utf8mb4',
            $this->manager->readEnvVariable($this->tempDir, '.env', 'DATABASE_URL'),
        );
    }

    public function testReadEnvVariableStripsSingleQuotes(): void
    {
        file_put_contents($this->tempDir.'/.env', "APP_SECRET='s3cr3t!'\n");

        $this->assertSame('s3cr3t!', $this->manager->readEnvVariable($this->tempDir, '.env', 'APP_SECRET'));
    }

    public function testReadEnvVariableKeepsValueWhenOnlyLeadingQuote(): void
    {
        // Mismatched quotes — leave value untouched rather than strip wrongly.
        file_put_contents($this->tempDir.'/.env', 'APP_SECRET="incomplete'."\n");

        $this->assertSame('"incomplete', $this->manager->readEnvVariable($this->tempDir, '.env', 'APP_SECRET'));
    }

    public function testReadEnvVariableHandlesExportPrefix(): void
    {
        file_put_contents($this->tempDir.'/.env', "export DATABASE_URL=mysql://root@db/app\n");

        $this->assertSame(
            'mysql://root@db/app',
            $this->manager->readEnvVariable($this->tempDir, '.env', 'DATABASE_URL'),
        );
    }

    public function testReadEnvVariableHandlesExportPrefixWithQuotes(): void
    {
        file_put_contents($this->tempDir.'/.env', 'export DATABASE_URL="mysql://root@db/app?opt=1&foo=2"'."\n");

        $this->assertSame(
            'mysql://root@db/app?opt=1&foo=2',
            $this->manager->readEnvVariable($this->tempDir, '.env', 'DATABASE_URL'),
        );
    }

    public function testReadEnvVariableIgnoresCommentedLines(): void
    {
        $envContent = "# DATABASE_URL=should-be-ignored\nDATABASE_URL=real\n";
        file_put_contents($this->tempDir.'/.env', $envContent);

        $this->assertSame('real', $this->manager->readEnvVariable($this->tempDir, '.env', 'DATABASE_URL'));
    }

    public function testReadEnvVariableHandlesCrlfLineEndings(): void
    {
        file_put_contents($this->tempDir.'/.env', "APP_ENV=prod\r\nDATABASE_URL=\"mysql://x\"\r\n");

        $this->assertSame('prod', $this->manager->readEnvVariable($this->tempDir, '.env', 'APP_ENV'));
        $this->assertSame('mysql://x', $this->manager->readEnvVariable($this->tempDir, '.env', 'DATABASE_URL'));
    }

    public function testWriteEnvVariablePreservesDoubleQuoteStyle(): void
    {
        file_put_contents($this->tempDir.'/.env', 'DATABASE_URL="mysql://old:old@host:3306/olddb?opt=1"'."\n");

        $this->manager->writeEnvVariable(
            $this->tempDir,
            '.env',
            'DATABASE_URL',
            'mysql://app:changeme@127.0.0.1:3306/fs-brain?serverVersion=10&charset=utf8',
        );

        $this->assertSame(
            'DATABASE_URL="mysql://app:changeme@127.0.0.1:3306/fs-brain?serverVersion=10&charset=utf8"'."\n",
            (string) file_get_contents($this->tempDir.'/.env'),
        );
    }

    public function testWriteEnvVariablePreservesSingleQuoteStyle(): void
    {
        file_put_contents($this->tempDir.'/.env', "APP_SECRET='old'\n");

        $this->manager->writeEnvVariable($this->tempDir, '.env', 'APP_SECRET', 'new');

        $this->assertSame("APP_SECRET='new'\n", (string) file_get_contents($this->tempDir.'/.env'));
    }

    public function testWriteEnvVariableKeepsUnquotedValueUnquoted(): void
    {
        file_put_contents($this->tempDir.'/.env', "MAILER_DSN=smtp://old:25\n");

        $this->manager->writeEnvVariable($this->tempDir, '.env', 'MAILER_DSN', 'null://null');

        $this->assertSame("MAILER_DSN=null://null\n", (string) file_get_contents($this->tempDir.'/.env'));
    }

    public function testWriteEnvVariablePreservesExportPrefix(): void
    {
        file_put_contents($this->tempDir.'/.env', 'export DATABASE_URL="mysql://old"'."\n");

        $this->manager->writeEnvVariable($this->tempDir, '.env', 'DATABASE_URL', 'mysql://new');

        $this->assertSame(
            'export DATABASE_URL="mysql://new"'."\n",
            (string) file_get_contents($this->tempDir.'/.env'),
        );
    }

    public function testWriteEnvVariablePreservesCrlfLineEndings(): void
    {
        file_put_contents($this->tempDir.'/.env', "APP_ENV=prod\r\nMAILER_DSN=smtp://x\r\n");

        $this->manager->writeEnvVariable($this->tempDir, '.env', 'APP_ENV', 'dev');

        $this->assertSame("APP_ENV=dev\r\nMAILER_DSN=smtp://x\r\n", (string) file_get_contents($this->tempDir.'/.env'));
    }

    public function testWriteEnvVariableLeavesOtherLinesUntouched(): void
    {
        $envContent = "# header comment\nAPP_ENV=prod\n\nMAILER_DSN=smtp://keep-me\n";
        file_put_contents($this->tempDir.'/.env', $envContent);

        $this->manager->writeEnvVariable($this->tempDir, '.env', 'APP_ENV', 'dev');

        $this->assertSame(
            "# header comment\nAPP_ENV=dev\n\nMAILER_DSN=smtp://keep-me\n",
            (string) file_get_contents($this->tempDir.'/.env'),
        );
    }

    public function testReadWriteRoundtripPreservesValue(): void
    {
        $value = 'mysql://root:root@mariadb:3306/fs-brain?serverVersion=10.5.15-mariadb&charset=utf8mb4';
        file_put_contents($this->tempDir.'/.env', 'DATABASE_URL="'.$value.'"'."\n");

        $original = $this->manager->readEnvVariable($this->tempDir, '.env', 'DATABASE_URL');
        $this->manager->writeEnvVariable($this->tempDir, '.env', 'DATABASE_URL', $original ?? '');

        $this->assertSame($value, $this->manager->readEnvVariable($this->tempDir, '.env', 'DATABASE_URL'));
    }

    /**
     * @param array<string, string> $servicesWithVersions service name => version
     *
     * @return list<\App\Model\ServiceDefinition>
     */
    private function createServiceDefinitions(array $servicesWithVersions): array
    {
        $result = [];

        foreach ($servicesWithVersions as $name => $version) {
            $result[] = new \App\Model\ServiceDefinition(
                name: $name,
                version: $version,
                containerName: 'dde-'.$name.'-'.$version,
            );
        }

        return $result;
    }

    private function createTempCompose(string $yamlContent): string
    {
        $path = $this->tempDir.'/docker-compose-'.bin2hex(random_bytes(4)).'.yml';
        file_put_contents($path, $yamlContent);

        return $path;
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

        $dockerComposeParser = new DockerComposeParser();
        $dockerComposeModifier = new DockerComposeModifier(
            databaseAdapterRegistry: new \App\Database\DatabaseAdapterRegistry([
                new \App\Database\MariaDbAdapter(),
                new \App\Database\PostgresAdapter(),
            ]),
        );
        $dockerfileParser = new DockerfileParser();

        $serviceRegistry = new \App\Service\ServiceRegistry(
            [],
            new \App\Database\DatabaseAdapterRegistry([
                new \App\Database\MariaDbAdapter(),
                new \App\Database\PostgresAdapter(),
            ]),
        );

        $this->manager = new ProjectInitAdaptationManager(
            $dockerComposeManager,
            $dockerComposeParser,
            $dockerComposeModifier,
            $dockerfileParser,
            $serviceRegistry,
            new \App\Database\DatabaseAdapterRegistry([
                new \App\Database\MariaDbAdapter(),
                new \App\Database\PostgresAdapter(),
            ]),
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
