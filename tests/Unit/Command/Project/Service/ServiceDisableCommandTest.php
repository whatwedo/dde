<?php

declare(strict_types=1);

namespace Tests\Unit\Command\Project\Service;

use App\Command\Project\Service\ServiceDisableCommand;
use App\Config\Definition\ProjectConfigDefinition;
use App\Config\ProjectConfig;
use App\Manager\ConfigManager;
use App\Manager\ServiceConfigManager;
use App\Output\FormatterResolver;
use App\Output\JsonFormatter;
use App\Output\TextFormatter;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

final class ServiceDisableCommandTest extends TestCase
{
    private string $tempDir;

    private ConfigManager&Stub $configManager;

    private CommandTester $commandTester;

    private ServiceDisableCommand $command;

    private Filesystem $filesystem;

    public function testCommandIsRegistered(): void
    {
        $this->assertSame('project:service:disable', $this->command->getName());
        $this->assertSame('Disable a service in the project config', $this->command->getDescription());
    }

    public function testDisableEnabledService(): void
    {
        $this->setupProjectFixture(['mariadb', 'valkey']);

        $this->commandTester->execute([
            'service' => 'mariadb',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());

        $data = Yaml::parseFile($this->configPath());
        $this->assertIsArray($data);
        $this->assertNotContains('mariadb', $data['services']);
        $this->assertContains('valkey', $data['services']);
    }

    public function testDisableServiceWithVersion(): void
    {
        $this->setupProjectFixtureWithVersionedService('mariadb', '10.6');

        $this->commandTester->execute([
            'service' => 'mariadb',
            '--service-version' => '10.6',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());

        $data = Yaml::parseFile($this->configPath());
        $this->assertIsArray($data);
        $this->assertSame([], $data['services']);
    }

    public function testDisableJsonOutput(): void
    {
        $this->setupProjectFixture(['valkey']);

        $this->commandTester->execute([
            'service' => 'valkey',
            '--output' => 'json',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());

        $decoded = json_decode($this->commandTester->getDisplay(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('ok', $decoded['status']);
        $this->assertSame('valkey', $decoded['data']['service']);
        $this->assertArrayHasKey('message', $decoded['data']);
    }

    public function testDisableNotEnabledServiceWarns(): void
    {
        $this->setupProjectFixture([]);

        $this->commandTester->execute([
            'service' => 'mariadb',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('not found', $this->commandTester->getDisplay());
    }

    public function testDisableFailsWhenConfigFileNotFound(): void
    {
        $this->configManager
            ->method('findProjectDirectory')
            ->willReturn($this->tempDir);

        // Don't create the config file

        $this->commandTester->execute([
            'service' => 'mariadb',
        ], [
            'interactive' => false,
        ]);

        $this->assertNotSame(0, $this->commandTester->getStatusCode());
    }

    public function testDisableFailsWhenNoProjectDirectoryFound(): void
    {
        $this->configManager
            ->method('findProjectDirectory')
            ->willReturn(null);

        $this->commandTester->execute([
            'service' => 'mariadb',
        ], [
            'interactive' => false,
        ]);

        $this->assertNotSame(0, $this->commandTester->getStatusCode());
    }

    public function testDisableOnlyRemovesFirstOccurrence(): void
    {
        $this->setupProjectFixture(['mariadb', 'valkey', 'mariadb']);

        $this->commandTester->execute([
            'service' => 'mariadb',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());

        $data = Yaml::parseFile($this->configPath());
        $this->assertIsArray($data);
        // Only one mariadb remains after removing the first occurrence
        $this->assertContains('valkey', $data['services']);
        $mariadbCount = count(array_filter($data['services'], static fn (mixed $s): bool => $s === 'mariadb'));
        $this->assertSame(1, $mariadbCount);
    }

    private function configPath(): string
    {
        return $this->tempDir.'/.dde/config.yml';
    }

    /**
     * @param list<string> $services
     */
    private function setupProjectFixture(array $services): void
    {
        $this->configManager
            ->method('findProjectDirectory')
            ->willReturn($this->tempDir);

        $yamlData = [
            'name' => 'test-project',
            'services' => $services,
        ];
        $this->filesystem->dumpFile($this->configPath(), Yaml::dump($yamlData, 4, 2));

        $this->configManager
            ->method('loadProjectConfig')
            ->willReturnCallback(function (): ProjectConfig {
                $data = Yaml::parseFile($this->configPath());
                $processed = (new Processor())->processConfiguration(new ProjectConfigDefinition(), [$data]);

                return ProjectConfig::fromProcessedConfig($processed);
            });
    }

    private function setupProjectFixtureWithVersionedService(string $serviceName, string $version): void
    {
        $this->configManager
            ->method('findProjectDirectory')
            ->willReturn($this->tempDir);

        $yamlData = [
            'name' => 'test-project',
            'services' => [
                [
                    'name' => $serviceName,
                    'version' => $version,
                ],
            ],
        ];
        $this->filesystem->dumpFile($this->configPath(), Yaml::dump($yamlData, 4, 2));

        $this->configManager
            ->method('loadProjectConfig')
            ->willReturnCallback(function (): ProjectConfig {
                $data = Yaml::parseFile($this->configPath());
                $processed = (new Processor())->processConfiguration(new ProjectConfigDefinition(), [$data]);

                return ProjectConfig::fromProcessedConfig($processed);
            });
    }

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/dde_test_svc_disable_'.bin2hex(random_bytes(8));
        mkdir($this->tempDir.'/.dde', 0o755, true);

        $this->filesystem = new Filesystem();
        $this->configManager = $this->createStub(ConfigManager::class);

        $formatterResolver = new FormatterResolver(new TextFormatter(), new JsonFormatter());

        $serviceConfigManager = new ServiceConfigManager($this->filesystem);

        $this->command = new ServiceDisableCommand(
            $this->configManager,
            $formatterResolver,
            $serviceConfigManager,
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
        if (is_dir($this->tempDir)) {
            $this->filesystem->remove($this->tempDir);
        }
    }
}
