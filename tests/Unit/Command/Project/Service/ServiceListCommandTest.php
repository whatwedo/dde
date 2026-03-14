<?php

declare(strict_types=1);

namespace Tests\Unit\Command\Project\Service;

use App\Command\Project\Service\ServiceListCommand;
use App\Config\ProjectConfig;
use App\Database\DatabaseAdapterRegistry;
use App\Manager\ConfigManager;
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

final class ServiceListCommandTest extends TestCase
{
    private string $tempDir;

    private ConfigManager&Stub $configManager;

    private ServiceRegistry $serviceRegistry;

    private CommandTester $commandTester;

    private ServiceListCommand $command;

    public function testCommandIsRegistered(): void
    {
        $this->assertSame('project:service:list', $this->command->getName());
        $this->assertSame('List available and active services', $this->command->getDescription());
    }

    public function testListWithNoActiveServices(): void
    {
        $this->setupProjectFixture([]);

        $this->commandTester->execute([
            '--output' => 'json',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());

        $decoded = json_decode($this->commandTester->getDisplay(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('ok', $decoded['status']);
        $this->assertIsArray($decoded['data']['services']);

        foreach ($decoded['data']['services'] as $service) {
            $this->assertSame('available', $service['status']);
            $this->assertNull($service['version']);
        }
    }

    public function testListWithActiveServices(): void
    {
        $this->setupProjectFixture([
            new ServiceDefinition(name: 'mariadb', version: '11.8'),
        ]);

        $this->commandTester->execute([
            '--output' => 'json',
        ], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());

        $decoded = json_decode($this->commandTester->getDisplay(), true);
        $this->assertIsArray($decoded);

        $servicesByName = [];

        foreach ($decoded['data']['services'] as $service) {
            $servicesByName[$service['name']] = $service;
        }

        $this->assertSame('active', $servicesByName['mariadb']['status']);
        $this->assertSame('11.8', $servicesByName['mariadb']['version']);
        $this->assertSame('available', $servicesByName['valkey']['status']);
        $this->assertNull($servicesByName['valkey']['version']);
    }

    public function testListAllKnownServicesIncluded(): void
    {
        $this->setupProjectFixture([]);

        $this->commandTester->execute([
            '--output' => 'json',
        ], [
            'interactive' => false,
        ]);

        $decoded = json_decode($this->commandTester->getDisplay(), true);
        $this->assertIsArray($decoded);

        $names = array_column($decoded['data']['services'], 'name');
        $this->assertContains('mariadb', $names);
        $this->assertContains('valkey', $names);
        $this->assertContains('postgres', $names);
        $this->assertContains('mailpit', $names);
        $this->assertCount(4, $decoded['data']['services']);
    }

    public function testListTextOutputShowsTable(): void
    {
        $this->setupProjectFixture([
            new ServiceDefinition(name: 'valkey', version: '9'),
        ]);

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $display = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Name', $display);
        $this->assertStringContainsString('Status', $display);
        $this->assertStringContainsString('Version', $display);
        $this->assertStringContainsString('valkey', $display);
    }

    public function testListFailsWhenNoProjectDirectoryFound(): void
    {
        $this->configManager
            ->method('findProjectDirectory')
            ->willReturn(null);

        $this->commandTester->execute([], [
            'interactive' => false,
        ]);

        $this->assertNotSame(0, $this->commandTester->getStatusCode());
    }

    /**
     * @param list<ServiceDefinition> $services
     */
    private function setupProjectFixture(array $services): void
    {
        $this->configManager
            ->method('findProjectDirectory')
            ->willReturn($this->tempDir);

        $projectConfig = new ProjectConfig(
            name: 'test-project',
            services: $services,
        );

        $this->configManager
            ->method('loadProjectConfig')
            ->willReturn($projectConfig);
    }

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/dde_test_svc_list_'.bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o755, true);

        $this->configManager = $this->createStub(ConfigManager::class);
        $this->serviceRegistry = new ServiceRegistry([], new DatabaseAdapterRegistry([]));

        $formatterResolver = new FormatterResolver(new TextFormatter(), new JsonFormatter());

        $this->command = new ServiceListCommand(
            $this->configManager,
            $this->serviceRegistry,
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
