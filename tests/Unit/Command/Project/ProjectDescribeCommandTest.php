<?php

declare(strict_types=1);

namespace Tests\Unit\Command\Project;

use App\Command\Project\ProjectDescribeCommand;
use App\Config\GlobalConfig;
use App\Config\ProjectConfig;
use App\Config\ResolvedConfig;
use App\Database\DatabaseAdapterRegistry;
use App\Manager\DockerComposeManager;
use App\Manager\ProjectConfigManager;
use App\Manager\ProjectInfoManager;
use App\Manager\WorktreeManager;
use App\Model\ServiceDefinition;
use App\Output\FormatterResolver;
use App\Output\JsonFormatter;
use App\Output\TextFormatter;
use App\Service\ServiceRegistry;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class ProjectDescribeCommandTest extends TestCase
{
    private string $tempDir;

    private ProjectConfigManager&Stub $configManager;

    private DockerComposeManager&Stub $dockerComposeManager;

    private WorktreeManager&Stub $worktreeManager;

    private CommandTester $commandTester;

    private ProjectDescribeCommand $command;

    public function testCommandIsRegistered(): void
    {
        $this->assertSame('project:describe', $this->command->getName());
        $this->assertSame('Show detailed project information', $this->command->getDescription());
    }

    public function testDescribeWithTextOutput(): void
    {
        $this->setupProjectFixture();

        $this->dockerComposeManager
            ->method('ps')
            ->willReturn([
                [
                    'Service' => 'web',
                    'State' => 'running',
                ],
            ]);

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $display = $this->commandTester->getDisplay();
        $this->assertStringContainsString('test-project', $display);
        $this->assertStringContainsString('https://test-project.test', $display);
        $this->assertStringContainsString('mariadb', $display);
    }

    public function testDescribeWithJsonOutput(): void
    {
        $this->setupProjectFixture();

        $this->dockerComposeManager
            ->method('ps')
            ->willReturn([
                [
                    'Service' => 'web',
                    'State' => 'running',
                ],
            ]);

        $this->commandTester->execute([
            '--output' => 'json',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $decoded = json_decode($this->commandTester->getDisplay(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('ok', $decoded['status']);
        $this->assertSame('test-project', $decoded['data']['project']);
        $this->assertSame('https://test-project.test', $decoded['data']['url']);
        $this->assertSame($this->tempDir, $decoded['data']['directory']);
        $this->assertNull($decoded['data']['worktree']);

        // Services
        $this->assertCount(1, $decoded['data']['services']);
        $this->assertSame('mariadb', $decoded['data']['services'][0]['name']);
        $this->assertSame('11.8', $decoded['data']['services'][0]['version']);
        $this->assertSame('mariadb', $decoded['data']['services'][0]['host']);
        $this->assertSame(3306, $decoded['data']['services'][0]['port']);
        $this->assertSame('mariadb', $decoded['data']['services'][0]['type']);

        // Containers
        $this->assertNotEmpty($decoded['data']['containers']);
        $this->assertSame('web', $decoded['data']['containers'][0]['name']);
        $this->assertSame('running', $decoded['data']['containers'][0]['status']);

        // Hooks
        $this->assertArrayHasKey('up.pre', $decoded['data']['hooks']);
        $this->assertArrayHasKey('up.post', $decoded['data']['hooks']);
        $this->assertArrayHasKey('down.pre', $decoded['data']['hooks']);
        $this->assertArrayHasKey('down.post', $decoded['data']['hooks']);

        // Plugins
        $this->assertIsArray($decoded['data']['plugins']);
    }

    public function testDescribeWithHooksAndPlugins(): void
    {
        $this->setupProjectFixture();

        // Create hook directories with scripts
        $hookDir = $this->tempDir.'/.dde/hooks/project.up.pre';
        mkdir($hookDir, 0o755, true);
        file_put_contents($hookDir.'/01-migrate.sh', '#!/bin/bash');
        file_put_contents($hookDir.'/02-seed.sh', '#!/bin/bash');

        $hookDirPost = $this->tempDir.'/.dde/hooks/project.up.post';
        mkdir($hookDirPost, 0o755, true);
        file_put_contents($hookDirPost.'/01-cache.sh', '#!/bin/bash');

        // Create plugin
        $pluginDir = $this->tempDir.'/.dde/plugins';
        mkdir($pluginDir, 0o755, true);
        file_put_contents($pluginDir.'/hash-pw.sh', "#!/bin/bash\n# @command web:hash-pw\necho 'hashed'");

        $this->dockerComposeManager
            ->method('ps')
            ->willReturn([]);

        $this->commandTester->execute([
            '--output' => 'json',
        ], [
            'interactive' => false,
        ]);

        $decoded = json_decode($this->commandTester->getDisplay(), true);
        $this->assertSame(2, $decoded['data']['hooks']['up.pre']);
        $this->assertSame(1, $decoded['data']['hooks']['up.post']);
        $this->assertSame(0, $decoded['data']['hooks']['down.pre']);
        $this->assertSame(0, $decoded['data']['hooks']['down.post']);
        $this->assertContains('web:hash-pw', $decoded['data']['plugins']);
    }

    public function testDescribeWithContainersFromConfig(): void
    {
        $services = [new ServiceDefinition(name: 'mariadb', version: 'latest')];

        $projectConfig = new ProjectConfig(
            name: 'test-project',
            services: $services,
            containers: [
                'web' => [
                    'shell' => 'zsh',
                ],
                'worker' => [
                    'shell' => 'bash',
                ],
            ],
        );

        $resolvedConfig = ResolvedConfig::merge(new GlobalConfig(), $projectConfig, $this->defaultVersions());

        $this->configManager
            ->method('findProjectDirectory')
            ->willReturn($this->tempDir);

        $this->configManager
            ->method('resolveConfig')
            ->willReturn($resolvedConfig);

        $this->worktreeManager
            ->method('detect')
            ->willReturn(null);

        $this->worktreeManager
            ->method('resolveHostname')
            ->willReturn('test-project.test');

        $this->dockerComposeManager
            ->method('ps')
            ->willReturn([
                [
                    'Service' => 'web',
                    'State' => 'running',
                ],
            ]);

        $this->commandTester->execute([
            '--output' => 'json',
        ], [
            'interactive' => false,
        ]);

        $decoded = json_decode($this->commandTester->getDisplay(), true);
        $containers = $decoded['data']['containers'];

        $this->assertCount(2, $containers);
        $this->assertSame('web', $containers[0]['name']);
        $this->assertSame('zsh', $containers[0]['shell']);
        $this->assertSame('running', $containers[0]['status']);
        $this->assertSame('worker', $containers[1]['name']);
        $this->assertSame('bash', $containers[1]['shell']);
        $this->assertSame('stopped', $containers[1]['status']);
    }

    public function testErrorWhenNoProjectDirectoryFound(): void
    {
        $this->configManager
            ->method('findProjectDirectory')
            ->willReturn(null);

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertNotSame(0, $this->commandTester->getStatusCode());
    }

    private function setupProjectFixture(): void
    {
        $services = [new ServiceDefinition(name: 'mariadb', version: 'latest')];

        $projectConfig = new ProjectConfig(
            name: 'test-project',
            services: $services,
        );

        $resolvedConfig = ResolvedConfig::merge(new GlobalConfig(), $projectConfig, $this->defaultVersions());

        $this->configManager
            ->method('findProjectDirectory')
            ->willReturn($this->tempDir);

        $this->configManager
            ->method('resolveConfig')
            ->willReturn($resolvedConfig);

        $this->worktreeManager
            ->method('detect')
            ->willReturn(null);

        $this->worktreeManager
            ->method('resolveHostname')
            ->willReturn('test-project.test');
    }

    /**
     * @return array<string, string>
     */
    private function defaultVersions(): array
    {
        return [
            'mariadb' => '11.8',
            'postgres' => '18.3',
            'valkey' => '9',
            'mailpit' => 'latest',
        ];
    }

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/dde_test_describe_'.bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o755, true);

        $this->configManager = $this->createStub(ProjectConfigManager::class);
        $this->dockerComposeManager = $this->createStub(DockerComposeManager::class);
        $this->worktreeManager = $this->createStub(WorktreeManager::class);

        $formatterResolver = new FormatterResolver(new TextFormatter(), new JsonFormatter());

        $this->command = new ProjectDescribeCommand(
            $this->configManager,
            $this->dockerComposeManager,
            new ProjectInfoManager(new ServiceRegistry([], new DatabaseAdapterRegistry([]))),
            $this->worktreeManager,
            $formatterResolver,
        );

        $application = new Application();
        $application->getDefinition()->addOption(new InputOption(
            'output',
            'o',
            InputOption::VALUE_REQUIRED,
            'Output format',
            'text',
        ));
        $application->addCommand($this->command);

        $this->commandTester = new CommandTester($this->command);
    }

    protected function tearDown(): void
    {
        $filesystem = new Filesystem();

        if (is_dir($this->tempDir)) {
            $filesystem->remove($this->tempDir);
        }
    }
}
