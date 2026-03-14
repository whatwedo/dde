<?php

declare(strict_types=1);

namespace Tests\Unit\EventListener;

use App\EventListener\OutputFormatListener;
use App\Output\FormatterResolver;
use App\Output\JsonFormatter;
use App\Output\TextFormatter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class OutputFormatListenerTest extends TestCase
{
    private TextFormatter $textFormatter;

    private JsonFormatter $jsonFormatter;

    private FormatterResolver $formatterResolver;

    private OutputFormatListener $listener;

    public function testOnCommandWithTextFormatSetsTextFormatter(): void
    {
        $event = $this->createEvent('text');

        ($this->listener)($event);

        $this->assertSame($this->textFormatter, $this->formatterResolver->getFormatter());
    }

    public function testOnCommandWithJsonFormatSetsJsonFormatter(): void
    {
        $event = $this->createEvent('json');

        ($this->listener)($event);

        $this->assertSame($this->jsonFormatter, $this->formatterResolver->getFormatter());
    }

    public function testOnCommandWithInvalidFormatThrowsRuntimeException(): void
    {
        $event = $this->createEvent('xml');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid output format "xml". Allowed values: text, json.');

        ($this->listener)($event);
    }

    private function createEvent(string $format, ?Command $command = null): ConsoleCommandEvent
    {
        $input = $this->createStub(InputInterface::class);
        $input->method('getOption')->willReturn($format);

        $output = $this->createStub(OutputInterface::class);

        return new ConsoleCommandEvent(
            $command ?? new Command('test'),
            $input,
            $output,
        );
    }

    protected function setUp(): void
    {
        $this->textFormatter = new TextFormatter();
        $this->jsonFormatter = new JsonFormatter();
        $this->formatterResolver = new FormatterResolver($this->textFormatter, $this->jsonFormatter);
        $this->listener = new OutputFormatListener($this->formatterResolver);
    }
}
