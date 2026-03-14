<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use App\Command\AbstractBaseCommand;
use App\Output\FormatterResolver;
use App\Output\JsonFormatter;
use App\Output\OutputFormatterInterface;
use App\Output\TextFormatter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class AbstractBaseCommandTest extends TestCase
{
    private TextFormatter $textFormatter;

    private JsonFormatter $jsonFormatter;

    private FormatterResolver $formatterResolver;

    public function testExtendsCommand(): void
    {
        $command = $this->createTestCommand();

        $this->assertInstanceOf(Command::class, $command);
    }

    public function testResolveFormatterWithJsonReturnsJsonFormatter(): void
    {
        $command = $this->createTestCommand();

        $input = $this->createStub(InputInterface::class);
        $input->method('getOption')
            ->willReturn('json');

        $output = $this->createStub(OutputInterface::class);

        $formatter = $command->callResolveFormatter($output, $input);

        $this->assertInstanceOf(JsonFormatter::class, $formatter);
    }

    public function testResolveFormatterWithTextReturnsTextFormatter(): void
    {
        $command = $this->createTestCommand();

        $input = $this->createStub(InputInterface::class);
        $input->method('getOption')
            ->willReturn('text');

        $output = $this->createStub(OutputInterface::class);

        $formatter = $command->callResolveFormatter($output, $input);

        $this->assertInstanceOf(TextFormatter::class, $formatter);
    }

    public function testResolveFormatterReturnsPreConfiguredFormatter(): void
    {
        $preConfiguredFormatter = $this->createStub(OutputFormatterInterface::class);
        $this->formatterResolver->setFormatter($preConfiguredFormatter);

        $command = $this->createTestCommand();

        $input = $this->createStub(InputInterface::class);
        $output = $this->createStub(OutputInterface::class);

        $formatter = $command->callResolveFormatter($output, $input);

        $this->assertSame($preConfiguredFormatter, $formatter);
    }

    public function testResolveFormatterWithNonStringOptionFallsBackToText(): void
    {
        $command = $this->createTestCommand();

        $input = $this->createStub(InputInterface::class);
        $input->method('getOption')
            ->willReturn(null);

        $output = $this->createStub(OutputInterface::class);

        $formatter = $command->callResolveFormatter($output, $input);

        $this->assertInstanceOf(TextFormatter::class, $formatter);
    }

    private function createTestCommand(): AbstractBaseCommand
    {
        return new class($this->formatterResolver) extends AbstractBaseCommand {
            public function callResolveFormatter(OutputInterface $output, InputInterface $input): OutputFormatterInterface
            {
                return $this->resolveFormatter($output, $input);
            }
        };
    }

    protected function setUp(): void
    {
        $this->textFormatter = new TextFormatter();
        $this->jsonFormatter = new JsonFormatter();
        $this->formatterResolver = new FormatterResolver($this->textFormatter, $this->jsonFormatter);
    }
}
