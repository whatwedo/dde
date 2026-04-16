<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use App\Manager\DockerManager;
use App\Service\TraefikService;
use App\Util\DockerComposeModifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class DockerComposeModifierTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $tempFiles = [];

    /**
     * @var list<string>
     */
    private array $tempDirs = [];

    private DockerComposeModifier $modifier;

    public function testAddNetworkAddsExternalNetwork(): void
    {
        $config = [
            'services' => [
                'web' => [
                    'image' => 'nginx',
                ],
            ],
        ];

        $changed = $this->modifier->addNetwork($config, 'dde');

        $this->assertTrue($changed);
        $this->assertSame('dde', $config['networks']['default']['name']);
        $this->assertTrue($config['networks']['default']['external']);
    }

    public function testAddNetworkSkipsIfAlreadyPresent(): void
    {
        $config = [
            'services' => [
                'web' => [
                    'image' => 'nginx',
                ],
            ],
            'networks' => [
                'default' => [
                    'name' => 'dde',
                    'external' => true,
                ],
            ],
        ];

        $changed = $this->modifier->addNetwork($config, 'dde');

        $this->assertFalse($changed);
    }

    public function testAddTraefikLabelsRemovesVirtualHostAndAddsLabels(): void
    {
        $config = [
            'services' => [
                'web' => [
                    'image' => 'nginx',
                    'environment' => [
                        'VIRTUAL_HOST=myproject.test',
                        'APP_ENV=dev',
                    ],
                ],
            ],
        ];

        $changed = $this->modifier->addTraefikLabels($config, 'web', 'myproject', true);

        $this->assertTrue($changed);
        $this->assertSame(['APP_ENV=dev'], $config['services']['web']['environment']);
        $this->assertContains('traefik.enable=true', $config['services']['web']['labels']);
        $this->assertContains('traefik.http.routers.myproject-test-web.rule=Host(`myproject.test`)', $config['services']['web']['labels']);
        $this->assertContains('traefik.http.routers.myproject-test-web-tls.rule=Host(`myproject.test`)', $config['services']['web']['labels']);
        $this->assertContains('traefik.http.routers.myproject-test-web-tls.tls=true', $config['services']['web']['labels']);
    }

    public function testAddTraefikLabelsWithKeyValueEnvironment(): void
    {
        $config = [
            'services' => [
                'web' => [
                    'image' => 'nginx',
                    'environment' => [
                        'VIRTUAL_HOST' => 'myproject.test',
                        'APP_ENV' => 'dev',
                    ],
                ],
            ],
        ];

        $changed = $this->modifier->addTraefikLabels($config, 'web', 'myproject', true);

        $this->assertTrue($changed);
        $this->assertArrayNotHasKey('VIRTUAL_HOST', $config['services']['web']['environment']);
        $this->assertSame('dev', $config['services']['web']['environment']['APP_ENV']);
    }

    public function testAddTraefikLabelsUsesProjectNameWhenNoVirtualHost(): void
    {
        $config = [
            'services' => [
                'web' => [
                    'image' => 'nginx',
                ],
            ],
        ];

        $changed = $this->modifier->addTraefikLabels($config, 'web', 'myproject', true);

        $this->assertTrue($changed);
        $this->assertContains('traefik.http.routers.myproject-test-web.rule=Host(`myproject.test`)', $config['services']['web']['labels']);
    }

    public function testAddTraefikLabelsCommaSeparatedVirtualHost(): void
    {
        $config = [
            'services' => [
                'web' => [
                    'image' => 'nginx',
                    'environment' => [
                        'VIRTUAL_HOST=myproject.test,www.myproject.test',
                    ],
                ],
            ],
        ];

        $changed = $this->modifier->addTraefikLabels($config, 'web', 'myproject', true);

        $this->assertTrue($changed);
        $this->assertContains(
            'traefik.http.routers.myproject-test-web.rule=Host(`myproject.test`) || Host(`www.myproject.test`)',
            $config['services']['web']['labels'],
        );
        $this->assertContains(
            'traefik.http.routers.myproject-test-web-tls.rule=Host(`myproject.test`) || Host(`www.myproject.test`)',
            $config['services']['web']['labels'],
        );
    }

    public function testAddTraefikLabelsSkipsIfAlreadyPresent(): void
    {
        $config = [
            'services' => [
                'web' => [
                    'image' => 'nginx',
                    'environment' => [
                        'VIRTUAL_HOST=myproject.test',
                    ],
                    'labels' => ['traefik.enable=true'],
                ],
            ],
        ];

        $changed = $this->modifier->addTraefikLabels($config, 'web', 'myproject', true);

        $this->assertFalse($changed);
    }

    public function testAddTraefikLabelsDoesNotRemoveEnvVarsWhenLabelsAlreadyExist(): void
    {
        $config = [
            'services' => [
                'web' => [
                    'image' => 'nginx',
                    'environment' => [
                        'VIRTUAL_HOST=example.test',
                        'APP_ENV=dev',
                    ],
                    'labels' => ['traefik.enable=true'],
                ],
            ],
        ];

        $changed = $this->modifier->addTraefikLabels($config, 'web', 'myproject', true);

        $this->assertFalse($changed);
        $this->assertContains('VIRTUAL_HOST=example.test', $config['services']['web']['environment']);
    }

    public function testAddTraefikLabelsReturnsFalseForMissingService(): void
    {
        $config = [
            'services' => [
                'web' => [
                    'image' => 'nginx',
                ],
            ],
        ];

        $changed = $this->modifier->addTraefikLabels($config, 'nonexistent', 'myproject');

        $this->assertFalse($changed);
    }

    public function testAddSshAgentVolumeAddsVolume(): void
    {
        $config = [
            'services' => [
                'web' => [
                    'image' => 'nginx',
                ],
            ],
        ];

        $changed = $this->modifier->addSshAgentVolume($config, 'web');

        $this->assertTrue($changed);
        $this->assertContains('dde_ssh-agent_socket-dir:/tmp/ssh-agent:ro', $config['services']['web']['volumes']);
    }

    public function testAddSshAgentVolumeReplacesExistingV1Volume(): void
    {
        $config = [
            'services' => [
                'web' => [
                    'image' => 'nginx',
                    'volumes' => ['ssh-agent_socket-dir:/tmp/ssh-agent:ro'],
                ],
            ],
            'volumes' => [
                'ssh-agent_socket-dir' => [
                    'external' => true,
                ],
            ],
        ];

        $changed = $this->modifier->addSshAgentVolume($config, 'web');

        $this->assertTrue($changed);
        $this->assertContains('dde_ssh-agent_socket-dir:/tmp/ssh-agent:ro', $config['services']['web']['volumes']);
        $this->assertNotContains('ssh-agent_socket-dir:/tmp/ssh-agent:ro', $config['services']['web']['volumes']);
        $this->assertArrayNotHasKey('ssh-agent_socket-dir', $config['volumes']);
        $this->assertArrayHasKey('dde_ssh-agent_socket-dir', $config['volumes']);
    }

    public function testAddSshAgentVolumeKeepsCorrectVolumeWhenAlreadyPresent(): void
    {
        $config = [
            'services' => [
                'web' => [
                    'image' => 'nginx',
                    'volumes' => ['dde_ssh-agent_socket-dir:/tmp/ssh-agent:ro'],
                ],
            ],
        ];

        $changed = $this->modifier->addSshAgentVolume($config, 'web');

        $this->assertFalse($changed);
        $this->assertCount(1, $config['services']['web']['volumes']);
        $this->assertContains('dde_ssh-agent_socket-dir:/tmp/ssh-agent:ro', $config['services']['web']['volumes']);
    }

    public function testAddSshAgentVolumeReturnsFalseForMissingService(): void
    {
        $config = [
            'services' => [
                'web' => [
                    'image' => 'nginx',
                ],
            ],
        ];

        $changed = $this->modifier->addSshAgentVolume($config, 'nonexistent');

        $this->assertFalse($changed);
    }

    public function testWriteCreatesFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'dde_test_').'.yml';
        $this->tempFiles[] = $path;

        $config = [
            'services' => [
                'web' => [
                    'image' => 'nginx',
                ],
            ],
        ];

        $this->modifier->write($path, $config);

        $this->assertFileExists($path);
        $content = file_get_contents($path);
        $this->assertIsString($content);
        $this->assertStringContainsString('services', $content);
    }

    public function testAddTraefikLabelsRemovesEmptyEnvironment(): void
    {
        $config = [
            'services' => [
                'web' => [
                    'image' => 'nginx',
                    'environment' => [
                        'VIRTUAL_HOST=myproject.test',
                    ],
                ],
            ],
        ];

        $this->modifier->addTraefikLabels($config, 'web', 'myproject', true);

        $this->assertArrayNotHasKey('environment', $config['services']['web']);
    }

    public function testAddTraefikLabelsMigratesVirtualPort(): void
    {
        $config = [
            'services' => [
                'web' => [
                    'image' => 'nginx',
                    'environment' => [
                        'VIRTUAL_HOST=myproject.test',
                        'VIRTUAL_PORT=8080',
                        'APP_ENV=dev',
                    ],
                ],
            ],
        ];

        $changed = $this->modifier->addTraefikLabels($config, 'web', 'myproject', true);

        $this->assertTrue($changed);
        $this->assertSame(['APP_ENV=dev'], $config['services']['web']['environment']);
        $this->assertContains('traefik.http.services.myproject-test-web.loadbalancer.server.port=8080', $config['services']['web']['labels']);
    }

    public function testAddTraefikLabelsMultipleServicesWithAndWithoutVirtualHost(): void
    {
        $config = [
            'services' => [
                'web' => [
                    'image' => 'nginx',
                    'environment' => [
                        'VIRTUAL_HOST=myapp.test',
                        'VIRTUAL_PORT=8080',
                    ],
                ],
                'worker' => [
                    'image' => 'php:8.5-cli',
                    'environment' => [
                        'APP_ENV=dev',
                    ],
                ],
                'api' => [
                    'image' => 'nginx',
                    'environment' => [
                        'VIRTUAL_HOST=api.myapp.test',
                    ],
                ],
            ],
        ];

        $webChanged = $this->modifier->addTraefikLabels($config, 'web', 'myapp');
        $workerChanged = $this->modifier->addTraefikLabels($config, 'worker', 'myapp');
        $apiChanged = $this->modifier->addTraefikLabels($config, 'api', 'myapp');

        // web: uses its own VIRTUAL_HOST (myapp.test)
        $this->assertTrue($webChanged);
        $this->assertContains('traefik.http.routers.myapp-test-web.rule=Host(`myapp.test`)', $config['services']['web']['labels']);
        $this->assertContains('traefik.http.services.myapp-test-web.loadbalancer.server.port=8080', $config['services']['web']['labels']);
        $this->assertArrayNotHasKey('environment', $config['services']['web']);

        // worker: no VIRTUAL_HOST and not primary -> no labels added
        $this->assertFalse($workerChanged);
        $this->assertArrayNotHasKey('labels', $config['services']['worker']);
        $this->assertSame(['APP_ENV=dev'], $config['services']['worker']['environment']);

        // api: uses its own VIRTUAL_HOST (api.myapp.test), different from web
        $this->assertTrue($apiChanged);
        $this->assertContains('traefik.http.routers.api-myapp-test-api.rule=Host(`api.myapp.test`)', $config['services']['api']['labels']);
        foreach ($config['services']['api']['labels'] as $label) {
            $this->assertStringNotContainsString('loadbalancer.server.port', is_string($label) ? $label : '');
        }
    }

    public function testAddTraefikLabelsMultipleServicesWithVirtualPortOnlyOnSome(): void
    {
        $config = [
            'services' => [
                'frontend' => [
                    'image' => 'node:22',
                    'environment' => [
                        'VIRTUAL_HOST' => 'frontend.myapp.test',
                        'VIRTUAL_PORT' => '3000',
                        'NODE_ENV' => 'development',
                    ],
                ],
                'backend' => [
                    'image' => 'php:8.5-fpm',
                    'environment' => [
                        'VIRTUAL_HOST' => 'backend.myapp.test',
                        'APP_ENV' => 'dev',
                    ],
                ],
            ],
        ];

        $frontendChanged = $this->modifier->addTraefikLabels($config, 'frontend', 'myapp');
        $backendChanged = $this->modifier->addTraefikLabels($config, 'backend', 'myapp');

        // frontend: uses its own VIRTUAL_HOST, has VIRTUAL_PORT
        $this->assertTrue($frontendChanged);
        $this->assertContains('traefik.http.routers.frontend-myapp-test-frontend.rule=Host(`frontend.myapp.test`)', $config['services']['frontend']['labels']);
        $this->assertContains('traefik.http.services.frontend-myapp-test-frontend.loadbalancer.server.port=3000', $config['services']['frontend']['labels']);
        $this->assertSame([
            'NODE_ENV' => 'development',
        ], $config['services']['frontend']['environment']);

        // backend: uses its own VIRTUAL_HOST, no VIRTUAL_PORT
        $this->assertTrue($backendChanged);
        $this->assertContains('traefik.http.routers.backend-myapp-test-backend.rule=Host(`backend.myapp.test`)', $config['services']['backend']['labels']);
        foreach ($config['services']['backend']['labels'] as $label) {
            $this->assertStringNotContainsString('loadbalancer.server.port', is_string($label) ? $label : '');
        }

        $this->assertSame([
            'APP_ENV' => 'dev',
        ], $config['services']['backend']['environment']);
    }

    public function testAddServiceEnvironmentAddsMariadbDatabaseUrl(): void
    {
        $config = [
            'services' => [
                'web' => [
                    'image' => 'nginx',
                ],
                'mariadb' => [
                    'image' => 'mariadb:11.8',
                ],
            ],
        ];

        $changes = $this->modifier->addServiceEnvironment($config, 'web', 'my-project');

        $this->assertCount(1, $changes);
        $this->assertStringContainsString('DATABASE_URL', $changes[0]);
        $this->assertContains('DATABASE_URL=mysql://root:root@mariadb:3306/my_project', $config['services']['web']['environment']);
    }

    public function testAddServiceEnvironmentAddsPostgresDatabaseUrl(): void
    {
        $config = [
            'services' => [
                'web' => [
                    'image' => 'nginx',
                ],
                'postgres' => [
                    'image' => 'postgres:18.3',
                ],
            ],
        ];

        $changes = $this->modifier->addServiceEnvironment($config, 'web', 'my-project');

        $this->assertCount(1, $changes);
        $this->assertContains('DATABASE_URL=postgresql://postgres:postgres@postgres:5432/my_project', $config['services']['web']['environment']);
    }

    public function testAddServiceEnvironmentAddsMailerDsn(): void
    {
        $config = [
            'services' => [
                'web' => [
                    'image' => 'nginx',
                ],
                'mailpit' => [
                    'image' => 'axllent/mailpit',
                ],
            ],
        ];

        $changes = $this->modifier->addServiceEnvironment($config, 'web', 'myproject');

        $this->assertCount(1, $changes);
        $this->assertContains('MAILER_DSN=smtp://mailpit:1025', $config['services']['web']['environment']);
    }

    public function testAddServiceEnvironmentAddsBothDatabaseAndMailer(): void
    {
        $config = [
            'services' => [
                'web' => [
                    'image' => 'nginx',
                ],
                'mariadb' => [
                    'image' => 'mariadb:11.8',
                ],
                'mailpit' => [
                    'image' => 'axllent/mailpit',
                ],
            ],
        ];

        $changes = $this->modifier->addServiceEnvironment($config, 'web', 'myproject');

        $this->assertCount(2, $changes);
        $this->assertContains('DATABASE_URL=mysql://root:root@mariadb:3306/myproject', $config['services']['web']['environment']);
        $this->assertContains('MAILER_DSN=smtp://mailpit:1025', $config['services']['web']['environment']);
    }

    public function testAddServiceEnvironmentSkipsExistingVariables(): void
    {
        $config = [
            'services' => [
                'web' => [
                    'image' => 'nginx',
                    'environment' => [
                        'DATABASE_URL=mysql://custom:custom@db:3306/custom',
                    ],
                ],
                'mariadb' => [
                    'image' => 'mariadb:11.8',
                ],
            ],
        ];

        $changes = $this->modifier->addServiceEnvironment($config, 'web', 'myproject');

        $this->assertCount(0, $changes);
        $this->assertSame(['DATABASE_URL=mysql://custom:custom@db:3306/custom'], $config['services']['web']['environment']);
    }

    public function testAddServiceEnvironmentReturnEmptyForMissingService(): void
    {
        $config = [
            'services' => [
                'web' => [
                    'image' => 'nginx',
                ],
            ],
        ];

        $changes = $this->modifier->addServiceEnvironment($config, 'nonexistent', 'myproject');

        $this->assertSame([], $changes);
    }

    public function testAddServiceEnvironmentPreservesMapFormat(): void
    {
        $config = [
            'services' => [
                'web' => [
                    'image' => 'nginx',
                    'environment' => [
                        'APP_ENV' => 'dev',
                    ],
                ],
                'mariadb' => [
                    'image' => 'mariadb:11.8',
                ],
            ],
        ];

        $changes = $this->modifier->addServiceEnvironment($config, 'web', 'myproject');

        $this->assertCount(1, $changes);
        $this->assertSame('dev', $config['services']['web']['environment']['APP_ENV']);
        $this->assertSame('mysql://root:root@mariadb:3306/myproject', $config['services']['web']['environment']['DATABASE_URL']);
    }

    public function testSetEnvironmentVariableAddsToEmptyService(): void
    {
        $service = [
            'image' => 'nginx',
        ];

        $result = $this->modifier->setEnvironmentVariable($service, 'FOO', 'bar');

        $this->assertTrue($result);
        $this->assertSame(['FOO=bar'], $service['environment']);
    }

    public function testSetEnvironmentVariableSkipsIfAlreadySet(): void
    {
        $service = [
            'image' => 'nginx',
            'environment' => [
                'FOO=existing',
            ],
        ];

        $result = $this->modifier->setEnvironmentVariable($service, 'FOO', 'bar');

        $this->assertFalse($result);
        $this->assertSame(['FOO=existing'], $service['environment']);
    }

    public function testSetEnvironmentVariableSkipsIfDefinedInEnvFile(): void
    {
        $projectDir = $this->createTempDir();
        file_put_contents($projectDir.'/.env', "APP_ENV=dev\nDATABASE_URL=mysql://localhost/mydb\n");

        $service = [
            'image' => 'nginx',
        ];

        $result = $this->modifier->setEnvironmentVariable($service, 'DATABASE_URL', 'mysql://root:root@mariadb:3306/test', $projectDir);

        $this->assertFalse($result);
        $this->assertArrayNotHasKey('environment', $service);
    }

    public function testSetEnvironmentVariableSkipsIfDefinedInEnvDevFile(): void
    {
        $projectDir = $this->createTempDir();
        file_put_contents($projectDir.'/.env.dev', "MAILER_DSN=smtp://localhost:1025\n");

        $service = [
            'image' => 'nginx',
        ];

        $result = $this->modifier->setEnvironmentVariable($service, 'MAILER_DSN', 'smtp://mailpit:1025', $projectDir);

        $this->assertFalse($result);
        $this->assertArrayNotHasKey('environment', $service);
    }

    public function testSetEnvironmentVariableIgnoresCommentedLinesInEnvFile(): void
    {
        $projectDir = $this->createTempDir();
        file_put_contents($projectDir.'/.env', "# DATABASE_URL=mysql://localhost/mydb\n");

        $service = [
            'image' => 'nginx',
        ];

        $result = $this->modifier->setEnvironmentVariable($service, 'DATABASE_URL', 'mysql://root:root@mariadb:3306/test', $projectDir);

        $this->assertTrue($result);
        $this->assertSame(['DATABASE_URL=mysql://root:root@mariadb:3306/test'], $service['environment']);
    }

    public function testSetEnvironmentVariableHandlesExportPrefixInEnvFile(): void
    {
        $projectDir = $this->createTempDir();
        file_put_contents($projectDir.'/.env', "export DATABASE_URL=mysql://localhost/mydb\n");

        $service = [
            'image' => 'nginx',
        ];

        $result = $this->modifier->setEnvironmentVariable($service, 'DATABASE_URL', 'mysql://root:root@mariadb:3306/test', $projectDir);

        $this->assertFalse($result);
    }

    public function testSetEnvironmentVariableAddsWhenEnvFilesDoNotExist(): void
    {
        $projectDir = $this->createTempDir();

        $service = [
            'image' => 'nginx',
        ];

        $result = $this->modifier->setEnvironmentVariable($service, 'DATABASE_URL', 'mysql://root:root@mariadb:3306/test', $projectDir);

        $this->assertTrue($result);
        $this->assertSame(['DATABASE_URL=mysql://root:root@mariadb:3306/test'], $service['environment']);
    }

    public function testSetEnvironmentVariableAddsWhenDifferentVarInEnvFile(): void
    {
        $projectDir = $this->createTempDir();
        file_put_contents($projectDir.'/.env', "APP_ENV=dev\n");

        $service = [
            'image' => 'nginx',
        ];

        $result = $this->modifier->setEnvironmentVariable($service, 'DATABASE_URL', 'mysql://root:root@mariadb:3306/test', $projectDir);

        $this->assertTrue($result);
        $this->assertSame(['DATABASE_URL=mysql://root:root@mariadb:3306/test'], $service['environment']);
    }

    public function testAddServiceEnvironmentSkipsWhenDefinedInEnvFile(): void
    {
        $projectDir = $this->createTempDir();
        file_put_contents($projectDir.'/.env', "DATABASE_URL=mysql://custom:custom@db:3306/custom\n");

        $config = [
            'services' => [
                'web' => [
                    'image' => 'nginx',
                ],
                'mariadb' => [
                    'image' => 'mariadb:11.8',
                ],
            ],
        ];

        $changes = $this->modifier->addServiceEnvironment($config, 'web', 'myproject', $projectDir);

        $this->assertCount(0, $changes);
        $this->assertArrayNotHasKey('environment', $config['services']['web']);
    }

    public function testWriteQuotesAllLabels(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'dde_test_').'.yml';
        $this->tempFiles[] = $path;

        $config = [
            'services' => [
                'web' => [
                    'image' => 'nginx',
                    'labels' => [
                        'traefik.enable=true',
                        'traefik.http.routers.web.rule=Host(`example.test`)',
                    ],
                ],
            ],
        ];

        $this->modifier->write($path, $config);

        $content = file_get_contents($path);
        $this->assertIsString($content);
        $this->assertStringContainsString("- 'traefik.enable=true'", $content);
        $this->assertStringContainsString("- 'traefik.http.routers.web.rule=Host(`example.test`)'", $content);
    }

    public function testRemoveV1BuildArgsRemovesDdeUidAndGid(): void
    {
        $config = [
            'services' => [
                'web' => [
                    'build' => [
                        'context' => '.',
                        'target' => 'dev',
                        'args' => [
                            'DDE_UID' => '${DDE_UID}',
                            'DDE_GID' => '${DDE_GID}',
                        ],
                    ],
                ],
            ],
        ];

        $changed = $this->modifier->removeV1BuildArgs($config, 'web');

        $this->assertTrue($changed);
        $this->assertArrayNotHasKey('args', $config['services']['web']['build']);
        $this->assertSame('.', $config['services']['web']['build']['context']);
        $this->assertSame('dev', $config['services']['web']['build']['target']);
    }

    public function testRemoveV1BuildArgsKeepsOtherArgs(): void
    {
        $config = [
            'services' => [
                'web' => [
                    'build' => [
                        'context' => '.',
                        'args' => [
                            'DDE_UID' => '${DDE_UID}',
                            'DDE_GID' => '${DDE_GID}',
                            'GIT_COMMIT' => 'abc123',
                        ],
                    ],
                ],
            ],
        ];

        $changed = $this->modifier->removeV1BuildArgs($config, 'web');

        $this->assertTrue($changed);
        $this->assertSame([
            'GIT_COMMIT' => 'abc123',
        ], $config['services']['web']['build']['args']);
    }

    public function testRemoveV1BuildArgsReturnsFalseWhenNoBuildArgs(): void
    {
        $config = [
            'services' => [
                'web' => [
                    'image' => 'nginx',
                ],
            ],
        ];

        $changed = $this->modifier->removeV1BuildArgs($config, 'web');

        $this->assertFalse($changed);
    }

    public function testWriteUsesFourSpaceIndent(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'dde_test_').'.yml';
        $this->tempFiles[] = $path;

        $config = [
            'services' => [
                'web' => [
                    'image' => 'nginx',
                ],
            ],
        ];

        $this->modifier->write($path, $config);

        $content = file_get_contents($path);
        $this->assertIsString($content);
        $this->assertStringContainsString("    web:\n        image: nginx", $content);
    }

    public function testRemoveContainerNameRemovesProperty(): void
    {
        $config = [
            'services' => [
                'web' => [
                    'image' => 'nginx',
                    'container_name' => 'my-web',
                ],
            ],
        ];

        $changed = $this->modifier->removeContainerName($config, 'web');

        $this->assertTrue($changed);
        $this->assertArrayNotHasKey('container_name', $config['services']['web']);
        $this->assertSame('nginx', $config['services']['web']['image']);
    }

    public function testRemoveContainerNameReturnsFalseWhenNotSet(): void
    {
        $config = [
            'services' => [
                'web' => [
                    'image' => 'nginx',
                ],
            ],
        ];

        $changed = $this->modifier->removeContainerName($config, 'web');

        $this->assertFalse($changed);
    }

    public function testServiceHasOldSshAgentVolumeReturnsTrueForOldFormat(): void
    {
        $config = [
            'services' => [
                'web' => [
                    'image' => 'nginx',
                    'volumes' => ['ssh-agent_socket-dir:/tmp/ssh-agent:ro'],
                ],
            ],
        ];

        $this->assertTrue($this->modifier->serviceHasOldSshAgentVolume($config, 'web'));
    }

    public function testServiceHasOldSshAgentVolumeReturnsFalseForNewFormat(): void
    {
        $config = [
            'services' => [
                'web' => [
                    'image' => 'nginx',
                    'volumes' => ['dde_ssh-agent_socket-dir:/tmp/ssh-agent:ro'],
                ],
            ],
        ];

        $this->assertFalse($this->modifier->serviceHasOldSshAgentVolume($config, 'web'));
    }

    public function testRemoveDdeNetworkBoilerplateRemovesDefaultNetwork(): void
    {
        $config = [
            'networks' => [
                'default' => [
                    'name' => 'dde',
                    'external' => true,
                ],
            ],
        ];

        $result = $this->modifier->removeDdeNetworkBoilerplate($config);

        $this->assertTrue($result);
        $this->assertArrayNotHasKey('networks', $config);
    }

    public function testRemoveDdeNetworkBoilerplateReturnsFalseWhenNotPresent(): void
    {
        $config = [
            'services' => [
                'web' => [
                    'image' => 'nginx',
                ],
            ],
        ];
        $result = $this->modifier->removeDdeNetworkBoilerplate($config);

        $this->assertFalse($result);
    }

    public function testRemoveDdeNetworkBoilerplateReturnsFalseForDifferentNetwork(): void
    {
        $config = [
            'networks' => [
                'default' => [
                    'name' => 'other-network',
                    'external' => true,
                ],
            ],
        ];

        $result = $this->modifier->removeDdeNetworkBoilerplate($config);

        $this->assertFalse($result);
        $this->assertArrayHasKey('networks', $config);
    }

    public function testRemoveSshAgentBoilerplateRemovesVolumeAndEnvAndTopLevel(): void
    {
        $config = [
            'services' => [
                'web' => [
                    'image' => 'nginx',
                    'volumes' => ['dde_ssh-agent_socket-dir:/tmp/ssh-agent:ro'],
                    'environment' => ['SSH_AUTH_SOCK=/tmp/ssh-agent/socket'],
                ],
            ],
            'volumes' => [
                'dde_ssh-agent_socket-dir' => [
                    'external' => true,
                ],
            ],
        ];

        $result = $this->modifier->removeSshAgentBoilerplate($config, 'web');

        $this->assertTrue($result);
        $this->assertArrayNotHasKey('volumes', $config['services']['web']);
        $this->assertArrayNotHasKey('SSH_AUTH_SOCK', $config['services']['web']['environment'] ?? []);
        $this->assertArrayNotHasKey('volumes', $config);
    }

    public function testRemoveSshAgentBoilerplateReturnsFalseWhenNothingToRemove(): void
    {
        $config = [
            'services' => [
                'web' => [
                    'image' => 'nginx',
                ],
            ],
        ];

        $result = $this->modifier->removeSshAgentBoilerplate($config, 'web');

        $this->assertFalse($result);
    }

    public function testRemoveSshAgentBoilerplatePreservesOtherVolumes(): void
    {
        $config = [
            'services' => [
                'web' => [
                    'image' => 'nginx',
                    'volumes' => [
                        './src:/var/www',
                        'dde_ssh-agent_socket-dir:/tmp/ssh-agent:ro',
                    ],
                ],
            ],
        ];

        $this->modifier->removeSshAgentBoilerplate($config, 'web');

        $this->assertSame(['./src:/var/www'], $config['services']['web']['volumes']);
    }

    public function testServiceHasOldSshAgentVolumeReturnsFalseForNoVolumes(): void
    {
        $config = [
            'services' => [
                'web' => [
                    'image' => 'nginx',
                ],
            ],
        ];

        $this->assertFalse($this->modifier->serviceHasOldSshAgentVolume($config, 'web'));
    }

    private function createTempDir(): string
    {
        $dir = sys_get_temp_dir().'/dde_test_'.bin2hex(random_bytes(8));
        mkdir($dir, 0o777, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    protected function setUp(): void
    {
        $adapterRegistry = new \App\Database\DatabaseAdapterRegistry([
            new \App\Database\MariaDbAdapter(),
            new \App\Database\PostgresAdapter(),
        ]);
        $dockerManager = $this->createStub(DockerManager::class);
        $traefikService = new TraefikService(
            dockerManager: $dockerManager,
            filesystem: new Filesystem(),
            dataDir: sys_get_temp_dir(),
        );
        $this->modifier = new DockerComposeModifier(
            databaseAdapterRegistry: $adapterRegistry,
            traefikService: $traefikService,
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        foreach ($this->tempDirs as $dir) {
            if (is_dir($dir)) {
                $files = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST,
                );

                foreach ($files as $fileInfo) {
                    if ($fileInfo->isDir()) {
                        rmdir($fileInfo->getRealPath());
                    } else {
                        unlink($fileInfo->getRealPath());
                    }
                }

                rmdir($dir);
            }
        }

        $this->tempFiles = [];
        $this->tempDirs = [];
    }
}
