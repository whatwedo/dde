<?php

declare(strict_types=1);

namespace App\Tests\Integration\Util;

use App\Database\DatabaseAdapterRegistry;
use App\Database\MariaDbAdapter;
use App\Database\PostgresAdapter;
use App\Manager\DockerManager;
use App\Parser\DockerComposeParser;
use App\Service\TraefikService;
use App\Util\DockerComposeModifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class DockerComposeModifierIntegrationTest extends TestCase
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

    private DockerComposeParser $parser;

    public function testFullModificationChainOnRealisticComposeFile(): void
    {
        $composePath = $this->createTempFile(<<<'YAML'
services:
    web:
        image: nginx:1.27
        ports:
            - '8080:80'
        volumes:
            - ./src:/var/www/html:cached
        environment:
            - VIRTUAL_HOST=myproject.test
            - VIRTUAL_PORT=80
            - APP_ENV=dev
    mariadb:
        image: mariadb:11.8
        environment:
            MYSQL_ROOT_PASSWORD: root
            MYSQL_DATABASE: myproject
        volumes:
            - mariadb_data:/var/lib/mysql

volumes:
    mariadb_data: {}
YAML);

        // Parse the original file
        $config = $this->parser->parse($composePath);

        // Apply full modification chain
        $networkChanged = $this->modifier->addNetwork($config, 'dde');
        $traefikChanged = $this->modifier->addTraefikLabels($config, 'web', 'myproject', true);
        $sshChanged = $this->modifier->addSshAgentVolume($config, 'web');
        $envChanges = $this->modifier->addServiceEnvironment($config, 'web', 'myproject');

        // Verify all modifications returned true/non-empty
        $this->assertTrue($networkChanged);
        $this->assertTrue($traefikChanged);
        $this->assertTrue($sshChanged);
        $this->assertNotEmpty($envChanges);

        // Write modified config
        $outputPath = $this->createTempFilePath();
        $this->modifier->write($outputPath, $config);

        // Re-parse and verify round-trip
        $reparsed = $this->parser->parse($outputPath);

        // Verify network
        $this->assertSame('dde', $reparsed['networks']['default']['name']);
        $this->assertTrue($reparsed['networks']['default']['external']);

        // Verify Traefik labels on web service
        $this->assertIsArray($reparsed['services']['web']['labels']);
        $labels = $reparsed['services']['web']['labels'];
        $this->assertContains('traefik.enable=true', $labels);

        // Verify VIRTUAL_HOST and VIRTUAL_PORT were removed from environment
        $webEnv = $reparsed['services']['web']['environment'] ?? [];
        foreach ($webEnv as $key => $value) {
            $envStr = is_string($key) ? $key.'='.$value : $value;
            $this->assertStringNotContainsString('VIRTUAL_HOST', $envStr);
            $this->assertStringNotContainsString('VIRTUAL_PORT', $envStr);
        }

        // Verify APP_ENV preserved
        $this->assertContains('APP_ENV=dev', $reparsed['services']['web']['environment']);

        // Verify SSH-agent volume
        $this->assertContains('dde_ssh-agent_socket-dir:/tmp/ssh-agent:ro', $reparsed['services']['web']['volumes']);
        $this->assertArrayHasKey('dde_ssh-agent_socket-dir', $reparsed['volumes']);

        // Verify DATABASE_URL was added
        $foundDbUrl = false;
        foreach ($reparsed['services']['web']['environment'] as $key => $value) {
            $envStr = is_string($key) ? $key.'='.$value : $value;
            if (str_contains($envStr, 'DATABASE_URL=')) {
                $foundDbUrl = true;
                $this->assertStringContainsString('mariadb', $envStr);
            }
        }

        $this->assertTrue($foundDbUrl, 'DATABASE_URL should have been added');

        // Verify original volume still present
        $this->assertContains('./src:/var/www/html:cached', $reparsed['services']['web']['volumes']);

        // Verify mariadb service was NOT modified with labels
        $this->assertArrayNotHasKey('labels', $reparsed['services']['mariadb']);

        // Verify YAML formatting uses 4-space indent
        $content = file_get_contents($outputPath);
        $this->assertIsString($content);
        $this->assertStringContainsString('    web:', $content);
        $this->assertStringContainsString('        image:', $content);

        // Verify labels are single-quoted
        $this->assertMatchesRegularExpression("/- 'traefik\\.enable=true'/", $content);
    }

    public function testModifierPreservesExistingLabelsAndVolumes(): void
    {
        $composePath = $this->createTempFile(<<<'YAML'
services:
    web:
        image: php:8.5-fpm
        labels:
            - 'com.example.description=My web app'
            - 'com.example.department=IT'
        volumes:
            - ./app:/var/www/html
            - ./config:/etc/app/config:ro
        environment:
            - APP_ENV=dev
YAML);

        $config = $this->parser->parse($composePath);

        // Apply Traefik labels
        $changed = $this->modifier->addTraefikLabels($config, 'web', 'mywebapp', true);
        $this->assertTrue($changed);

        // Apply SSH-agent volume
        $sshChanged = $this->modifier->addSshAgentVolume($config, 'web');
        $this->assertTrue($sshChanged);

        // Write and re-parse
        $outputPath = $this->createTempFilePath();
        $this->modifier->write($outputPath, $config);
        $reparsed = $this->parser->parse($outputPath);

        // Verify custom labels are preserved
        $labels = $reparsed['services']['web']['labels'];
        $this->assertContains('com.example.description=My web app', $labels);
        $this->assertContains('com.example.department=IT', $labels);

        // Verify Traefik labels were added
        $this->assertContains('traefik.enable=true', $labels);
        $this->assertGreaterThan(2, count($labels), 'Should have custom + Traefik labels');

        // Verify existing volumes are preserved
        $volumes = $reparsed['services']['web']['volumes'];
        $this->assertContains('./app:/var/www/html', $volumes);
        $this->assertContains('./config:/etc/app/config:ro', $volumes);

        // Verify SSH-agent volume was added
        $this->assertContains('dde_ssh-agent_socket-dir:/tmp/ssh-agent:ro', $volumes);

        // Verify environment preserved
        $this->assertContains('APP_ENV=dev', $reparsed['services']['web']['environment']);
    }

    public function testModifierHandlesMultiServiceComposeFile(): void
    {
        $composePath = $this->createTempFile(<<<'YAML'
services:
    web:
        image: nginx:1.27
        environment:
            - VIRTUAL_HOST=myapp.test
            - APP_ENV=dev
    worker:
        image: php:8.5-cli
        command: php bin/console messenger:consume
        environment:
            - APP_ENV=dev
    valkey:
        image: valkey/valkey:9
        volumes:
            - valkey_data:/data
    mariadb:
        image: mariadb:11.8
        environment:
            MYSQL_ROOT_PASSWORD: root

volumes:
    valkey_data: {}
YAML);

        $config = $this->parser->parse($composePath);

        // Apply modifications only to web service
        $this->modifier->addNetwork($config, 'dde');
        $this->modifier->addTraefikLabels($config, 'web', 'myapp', true);
        $this->modifier->addSshAgentVolume($config, 'web');
        $this->modifier->addServiceEnvironment($config, 'web', 'myapp');

        // Write and re-parse
        $outputPath = $this->createTempFilePath();
        $this->modifier->write($outputPath, $config);
        $reparsed = $this->parser->parse($outputPath);

        // Web service should have Traefik labels, SSH-agent, DATABASE_URL
        $this->assertArrayHasKey('labels', $reparsed['services']['web']);
        $this->assertContains('traefik.enable=true', $reparsed['services']['web']['labels']);
        $this->assertContains('dde_ssh-agent_socket-dir:/tmp/ssh-agent:ro', $reparsed['services']['web']['volumes']);

        $webEnv = $reparsed['services']['web']['environment'];
        $foundDbUrl = false;
        foreach ($webEnv as $key => $value) {
            $envStr = is_string($key) ? $key.'='.$value : $value;
            if (str_contains($envStr, 'DATABASE_URL=')) {
                $foundDbUrl = true;
            }
        }

        $this->assertTrue($foundDbUrl, 'DATABASE_URL should have been added to web');

        // Worker service should NOT have Traefik labels or SSH-agent
        $this->assertArrayNotHasKey('labels', $reparsed['services']['worker']);
        $this->assertArrayNotHasKey('volumes', $reparsed['services']['worker']);
        $this->assertSame(['APP_ENV=dev'], $reparsed['services']['worker']['environment']);

        // Valkey service should be untouched
        $this->assertArrayNotHasKey('labels', $reparsed['services']['valkey']);
        $this->assertSame(['valkey_data:/data'], $reparsed['services']['valkey']['volumes']);
        $this->assertArrayNotHasKey('environment', $reparsed['services']['valkey']);

        // MariaDB service should be untouched
        $this->assertArrayNotHasKey('labels', $reparsed['services']['mariadb']);
        $this->assertSame([
            'MYSQL_ROOT_PASSWORD' => 'root',
        ], $reparsed['services']['mariadb']['environment']);

        // Network should apply globally
        $this->assertSame('dde', $reparsed['networks']['default']['name']);
        $this->assertTrue($reparsed['networks']['default']['external']);

        // SSH-agent volume should be defined at top level
        $this->assertArrayHasKey('dde_ssh-agent_socket-dir', $reparsed['volumes']);

        // Original valkey_data volume should still exist
        $this->assertArrayHasKey('valkey_data', $reparsed['volumes']);
    }

    private function createTempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dde_integration_').'.yml';
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function createTempFilePath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dde_integration_').'.yml';
        $this->tempFiles[] = $path;

        return $path;
    }

    protected function setUp(): void
    {
        $adapterRegistry = new DatabaseAdapterRegistry([
            new MariaDbAdapter(),
            new PostgresAdapter(),
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
        $this->parser = new DockerComposeParser();
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
