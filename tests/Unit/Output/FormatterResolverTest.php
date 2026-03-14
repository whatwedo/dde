<?php

declare(strict_types=1);

namespace Tests\Unit\Output;

use App\Output\FormatterResolver;
use App\Output\JsonFormatter;
use App\Output\OutputFormat;
use App\Output\OutputFormatterInterface;
use App\Output\TextFormatter;
use PHPUnit\Framework\TestCase;

final class FormatterResolverTest extends TestCase
{
    private TextFormatter $textFormatter;

    private JsonFormatter $jsonFormatter;

    private FormatterResolver $resolver;

    public function testResolveTextReturnsTextFormatter(): void
    {
        $this->assertSame($this->textFormatter, $this->resolver->resolve(OutputFormat::TEXT));
    }

    public function testResolveJsonReturnsJsonFormatter(): void
    {
        $this->assertSame($this->jsonFormatter, $this->resolver->resolve(OutputFormat::JSON));
    }

    public function testGetFormatterReturnsTextFormatterByDefault(): void
    {
        $this->assertSame($this->textFormatter, $this->resolver->getFormatter());
    }

    public function testSetFormatterAndGetFormatterReturnsSetFormatter(): void
    {
        $customFormatter = $this->createStub(OutputFormatterInterface::class);
        $this->resolver->setFormatter($customFormatter);

        $this->assertSame($customFormatter, $this->resolver->getFormatter());
    }

    protected function setUp(): void
    {
        $this->textFormatter = new TextFormatter();
        $this->jsonFormatter = new JsonFormatter();
        $this->resolver = new FormatterResolver($this->textFormatter, $this->jsonFormatter);
    }
}
