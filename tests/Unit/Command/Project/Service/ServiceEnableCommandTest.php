<?php

declare(strict_types=1);

namespace Tests\Unit\Command\Project\Service;

use App\Command\Project\Service\ServiceEnableCommand;
use App\Config\Definition\ProjectConfigDefinition;
use App\Config\ProjectConfig;
use App\Database\DatabaseAdapterRegistry;
use App\Manager\ConfigManager;
use App\Manager\ServiceConfigManager;
use App\Output\FormatterResolver;
use App\Output\JsonFormatter;
use App\Output\TextFormatter;
use App\Service\ServiceRegistry;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

final class ServiceEnableCommandTest extends TestCase
{
    private string $tempDir;

    private ConfigManager&Stub $configManager;

    private ServiceRegistry $serviceRegistry;

    private CommandTester $commandTester;

    private ServiceEnableCommand $command;

    private Filesystem $filesystem;

    public function testCommandIsRegistered(): void
    {
        $this->assertSame('project:service:enable', $this->command->getName());
        $this->assertSame('Enable a service in the project config', $this->command->getDescription());
    }

    public function testEnableKnownServiceWithoutVersion(): void
    {
        $this->setupProjectFixture([]);

        $this->commandTester->execute([
            'service' => 'mariadb',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());

        $data = Yaml::parseFile($this->configPath());
        $this->assertIsArray($data);
        $this->assertContains('mariadb', $data['services']);
    }

    public function testEnableKnownServiceWithVersion(): void
    {
        $this->setupProjectFixture([]);

        $this->commandTester->execute([
            'service' => 'mariadb',
            '--service-version' => '10.6',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());

        $data = Yaml::parseFile($this->configPath());
        $this->assertIsArray($data);
        $this->assertContains([
            'name' => 'mariadb',
            'version' => '10.6',
        ], $data['services']);
    }

    public function testEnableJsonOutput(): void
    {
        $this->setupProjectFixture([]);

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
        $this->assertArrayHasKey('version', $decoded['data']);
        $this->assertArrayHasKey('message', $decoded['data']);
    }

    public function testEnableAlreadyEnabledServiceWarns(): void
    {
        $this->setupProjectFixture(['mariadb']);

        $this->commandTester->execute([
            'service' => 'mariadb',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('already enabled', $this->commandTester->getDisplay());
    }

    public function testEnableUnknownServiceFails(): void
    {
        $this->setupProjectFixture([]);

        $this->commandTester->execute([
            'service' => 'unknown-service',
        ], [
            'interactive' => false,
        ]);

        $this->assertNotSame(0, $this->commandTester->getStatusCode());
    }

    public function testEnableFailsWhenConfigFileNotFound(): void
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

    public function testEnableFailsWhenNoProjectDirectoryFound(): void
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

    public function testEnableAddsToExistingServices(): void
    {
        $this->setupProjectFixture(['valkey']);

        $this->commandTester->execute([
            'service' => 'mariadb',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());

        $data = Yaml::parseFile($this->configPath());
        $this->assertIsArray($data);
        $this->assertContains('valkey', $data['services']);
        $this->assertContains('mariadb', $data['services']);
        $this->assertCount(2, $data['services']);
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

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/dde_test_svc_enable_'.bin2hex(random_bytes(8));
        mkdir($this->tempDir.'/.dde', 0o755, true);

        $this->filesystem = new Filesystem();
        $this->configManager = $this->createStub(ConfigManager::class);
        $this->serviceRegistry = new ServiceRegistry([], new DatabaseAdapterRegistry([]));

        $formatterResolver = new FormatterResolver(new TextFormatter(), new JsonFormatter());

        $serviceConfigManager = new ServiceConfigManager($this->filesystem);

        $this->command = new ServiceEnableCommand(
            $this->configManager,
            $this->serviceRegistry,
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
