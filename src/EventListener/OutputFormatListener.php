<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Output\FormatterResolver;
use App\Output\OutputFormat;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: ConsoleEvents::COMMAND, priority: 100)]
final readonly class OutputFormatListener
{
    public function __construct(
        private FormatterResolver $formatterResolver,
    ) {
    }

    public function __invoke(ConsoleCommandEvent $event): void
    {
        $input = $event->getInput();
        $output = $event->getOutput();

        $rawFormat = $input->getOption('output');

        if (!is_string($rawFormat)) {
            return;
        }

        $format = OutputFormat::tryFrom($rawFormat);

        if (!$format instanceof OutputFormat) {
            throw new \RuntimeException(\sprintf(
                'Invalid output format "%s". Allowed values: %s.',
                $rawFormat,
                implode(', ', array_column(OutputFormat::cases(), 'value')),
            ));
        }

        $formatter = $this->formatterResolver->resolve($format);
        $formatter->setOutput($output, $input);

        $this->formatterResolver->setFormatter($formatter);
    }
}
