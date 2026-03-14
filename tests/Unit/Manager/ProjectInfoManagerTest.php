<?php

declare(strict_types=1);

namespace Tests\Unit\Manager;

use App\Config\GlobalConfig;
use App\Config\ProjectConfig;
use App\Config\ResolvedConfig;
use App\Database\DatabaseAdapterRegistry;
use App\Manager\ProjectInfoManager;
use App\Model\ServiceDefinition;
use App\Service\ServiceRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ProjectInfoManagerTest extends TestCase
{
    private string $tempDir;

    private ProjectInfoManager $manager;

    public function testBuildServiceDataReturnsServiceInfo(): void
    {
        $services = [new ServiceDefinition(name: 'mariadb', version: 'latest')];
        $projectConfig = new ProjectConfig(name: 'my-project', services: $services);
        $config = ResolvedConfig::merge(new GlobalConfig(), $projectConfig, [
            'mariadb' => '11.8',
        ]);

        $result = $this->manager->buildServiceData($config);

        $this->assertCount(1, $result);
        $this->assertSame('mariadb', $result[0]['name']);
        $this->assertSame('11.8', $result[0]['version']);
        $this->assertSame('mariadb', $result[0]['host']);
        $this->assertSame(3306, $result[0]['port']);
        $this->assertSame('mariadb', $result[0]['type']);
    }

    public function testBuildServiceDataReturnsZeroPortForUnknownService(): void
    {
        $services = [new ServiceDefinition(name: 'custom-svc', version: '1.0')];
        $projectConfig = new ProjectConfig(name: 'my-project', services: $services);
        $config = ResolvedConfig::merge(new GlobalConfig(), $projectConfig, []);

        $result = $this->manager->buildServiceData($config);

        $this->assertCount(1, $result);
        $this->assertSame('custom-svc', $result[0]['name']);
        $this->assertSame(0, $result[0]['port']);
    }

    public function testBuildContainerDataMergesLiveContainers(): void
    {
        $projectConfig = new ProjectConfig(
            name: 'my-project',
            containers: [
                'web' => [
                    'shell' => 'zsh',
                ],
            ],
        );
        $config = ResolvedConfig::merge(new GlobalConfig(), $projectConfig, []);

        $liveContainers = [
            [
                'Service' => 'web',
                'State' => 'running',
            ],
        ];

        $result = $this->manager->buildContainerData($config, $liveContainers);

        $this->assertCount(1, $result);
        $this->assertSame('web', $result[0]['name']);
        $this->assertSame('zsh', $result[0]['shell']);
        $this->assertSame('running', $result[0]['status']);
    }

    public function testBuildContainerDataSetsStoppedForMissingLiveContainer(): void
    {
        $projectConfig = new ProjectConfig(
            name: 'my-project',
            containers: [
                'worker' => [
                    'shell' => 'bash',
                ],
            ],
        );
        $config = ResolvedConfig::merge(new GlobalConfig(), $projectConfig, []);

        $result = $this->manager->buildContainerData($config, []);

        $this->assertCount(1, $result);
        $this->assertSame('worker', $result[0]['name']);
        $this->assertSame('bash', $result[0]['shell']);
        $this->assertSame('stopped', $result[0]['status']);
    }

    public function testBuildContainerDataAddsUnknownLiveContainers(): void
    {
        $projectConfig = new ProjectConfig(name: 'my-project', containers: []);
        $config = ResolvedConfig::merge(new GlobalConfig(), $projectConfig, []);

        $liveContainers = [
            [
                'Service' => 'scheduler',
                'State' => 'running',
            ],
        ];

        $result = $this->manager->buildContainerData($config, $liveContainers);

        $this->assertCount(1, $result);
        $this->assertSame('scheduler', $result[0]['name']);
        $this->assertSame('bash', $result[0]['shell']);
        $this->assertSame('running', $result[0]['status']);
    }

    public function testCountHooksReturnsZeroForMissingDirs(): void
    {
        $result = $this->manager->countHooks($this->tempDir);

        $this->assertSame(0, $result['up.pre']);
        $this->assertSame(0, $result['up.post']);
        $this->assertSame(0, $result['down.pre']);
        $this->assertSame(0, $result['down.post']);
    }

    public function testCountHooksCountsFilesInEachHookDir(): void
    {
        $hookDir = $this->tempDir.'/.dde/hooks/project.up.pre';
        mkdir($hookDir, 0o755, true);
        file_put_contents($hookDir.'/01-migrate.sh', '#!/bin/bash');
        file_put_contents($hookDir.'/02-seed.sh', '#!/bin/bash');

        $hookDirPost = $this->tempDir.'/.dde/hooks/project.up.post';
        mkdir($hookDirPost, 0o755, true);
        file_put_contents($hookDirPost.'/01-cache.sh', '#!/bin/bash');

        $result = $this->manager->countHooks($this->tempDir);

        $this->assertSame(2, $result['up.pre']);
        $this->assertSame(1, $result['up.post']);
        $this->assertSame(0, $result['down.pre']);
        $this->assertSame(0, $result['down.post']);
    }

    public function testScanPluginsReturnsEmptyForMissingPluginsDir(): void
    {
        $result = $this->manager->scanPlugins($this->tempDir);

        $this->assertSame([], $result);
    }

    public function testScanPluginsReturnsCommandNames(): void
    {
        $pluginDir = $this->tempDir.'/.dde/plugins';
        mkdir($pluginDir, 0o755, true);
        file_put_contents($pluginDir.'/hash-pw.sh', "#!/bin/bash\n# @command web:hash-pw\necho 'hashed'");
        file_put_contents($pluginDir.'/deploy.sh', "#!/bin/bash\n# @command project:deploy\n");

        $result = $this->manager->scanPlugins($this->tempDir);

        $this->assertCount(2, $result);
        $this->assertContains('web:hash-pw', $result);
        $this->assertContains('project:deploy', $result);
    }

    public function testScanPluginsSkipsFilesWithoutCommandAnnotation(): void
    {
        $pluginDir = $this->tempDir.'/.dde/plugins';
        mkdir($pluginDir, 0o755, true);
        file_put_contents($pluginDir.'/no-annotation.sh', "#!/bin/bash\necho 'nothing'");
        file_put_contents($pluginDir.'/with-annotation.sh', "#!/bin/bash\n# @command my:cmd\n");

        $result = $this->manager->scanPlugins($this->tempDir);

        $this->assertCount(1, $result);
        $this->assertSame('my:cmd', $result[0]);
    }

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/dde_test_projectinfo_'.bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o755, true);

        $this->manager = new ProjectInfoManager(new ServiceRegistry([], new DatabaseAdapterRegistry([])));
    }

    protected function tearDown(): void
    {
        $filesystem = new Filesystem();

        if (is_dir($this->tempDir)) {
            $filesystem->remove($this->tempDir);
        }
    }
}
