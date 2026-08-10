<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use App\Command\AbstractDatabaseCommand;
use App\Manager\ProjectConfigManager;
use App\Output\FormatterResolver;
use App\Output\JsonFormatter;
use App\Output\TextFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Attribute\AsCommand;
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
        $configManager = $this->createStub(ProjectConfigManager::class);
        $formatterResolver = new FormatterResolver(new TextFormatter(), new JsonFormatter());

        return new #[AsCommand(name: 'test:command')] class($configManager, $formatterResolver) extends AbstractDatabaseCommand {
            public function exposeSanitizeDatabaseName(string $name): string
            {
                return $this->sanitizeDatabaseName($name);
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                return self::SUCCESS;
            }
        };
    }
}
