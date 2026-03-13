<?php

declare(strict_types=1);

namespace App\Output;

final class FormatterResolver
{
    private ?OutputFormatterInterface $formatter = null;

    public function __construct(
        private readonly TextFormatter $textFormatter,
        private readonly JsonFormatter $jsonFormatter,
    ) {
    }

    public function resolve(OutputFormat $format): OutputFormatterInterface
    {
        return match ($format) {
            OutputFormat::JSON => $this->jsonFormatter,
            OutputFormat::TEXT => $this->textFormatter,
        };
    }

    public function setFormatter(OutputFormatterInterface $formatter): void
    {
        $this->formatter = $formatter;
    }

    public function getFormatter(): OutputFormatterInterface
    {
        return $this->formatter ?? $this->textFormatter;
    }

    public function isConfigured(): bool
    {
        return $this->formatter instanceof OutputFormatterInterface;
    }
}
