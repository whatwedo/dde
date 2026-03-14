<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use App\Command\AbstractDatabaseCommand;
use App\Database\DatabaseAdapterRegistry;
use App\Manager\ConfigManager;
use App\Output\FormatterResolver;
use App\Output\JsonFormatter;
use App\Output\TextFormatter;
use App\Service\ServiceRegistry;
use App\Util\ProcessFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class SanitizeDatabaseNameTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideProjectNames(): iterable
    {
        yield 'simple' => ['myproject', 'myproject'];
        yield 'hyphens' => ['my-project', 'my_project'];
        yield 'dots' => ['my.cool.project', 'my_cool_project'];
        yield 'spaces' => ['my project', 'my_project'];
        yield 'leading digit' => ['2fast2furious', 'db_2fast2furious'];
        yield 'leading underscore' => ['_test', '_test'];
        yield 'special chars' => ['my@project!', 'my_project'];
        yield 'unicode' => ['über-cool', 'uber_cool'];
        yield 'multiple consecutive separators' => ['my--project..name', 'my_project_name'];
        yield 'empty after sanitize' => ['!!!', 'project'];
    }

    #[DataProvider('provideProjectNames')]
    public function testSanitizeDatabaseName(string $input, string $expected): void
    {
        $command = $this->createTestCommand();

        $this->assertSame($expected, $command->exposeSanitizeDatabaseName($input)); // @phpstan-ignore method.notFound
    }

    private function createTestCommand(): AbstractDatabaseCommand
    {
        $configManager = new ConfigManager(configDir: '/tmp', serviceRegistry: new ServiceRegistry([], new DatabaseAdapterRegistry([])), processFactory: new ProcessFactory());
        $formatterResolver = new FormatterResolver(new TextFormatter(), new JsonFormatter());

        return new class($configManager, $formatterResolver) extends AbstractDatabaseCommand {
            public function exposeSanitizeDatabaseName(string $name): string
            {
                return $this->sanitizeDatabaseName($name);
            }

            protected function configure(): void
            {
                $this->setName('test:command');
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                return 0;
            }
        };
    }
}
