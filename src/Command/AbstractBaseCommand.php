<?php

declare(strict_types=1);

namespace App\Command;

use App\Output\FormatterResolver;
use App\Output\OutputFormat;
use App\Output\OutputFormatterInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class AbstractBaseCommand extends Command
{
    public function __construct(
        protected readonly FormatterResolver $formatterResolver,
    ) {
        parent::__construct();
    }

    protected function resolveFormatter(OutputInterface $output, InputInterface $input): OutputFormatterInterface
    {
        if ($this->formatterResolver->isConfigured()) {
            return $this->formatterResolver->getFormatter();
        }

        $format = OutputFormat::tryFrom(is_string($input->getOption('output')) ? $input->getOption('output') : 'text') ?? OutputFormat::TEXT;
        $formatter = $this->formatterResolver->resolve($format);
        $formatter->setOutput($output, $input);

        return $formatter;
    }
}
