<?php

declare(strict_types=1);

namespace Tests\Unit\Output;

use App\Output\TextFormatter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\BufferedOutput;

final class TextFormatterTest extends TestCase
{
    public function testSuccessReturnsSuccessExitCode(): void
    {
        $formatter = new TextFormatter();
        $formatter->setOutput(new BufferedOutput());

        $result = $formatter->success(null, 'all good');

        $this->assertSame(Command::SUCCESS, $result);
    }

    public function testErrorReturnsFailureExitCode(): void
    {
        $formatter = new TextFormatter();
        $formatter->setOutput(new BufferedOutput());

        $result = $formatter->error('something went wrong');

        $this->assertSame(Command::FAILURE, $result);
    }

    public function testSuccessWithoutSetOutputThrowsRuntimeException(): void
    {
        $formatter = new TextFormatter();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Output not initialized. Call setOutput() first.');

        $formatter->success(null);
    }

    public function testErrorWithoutSetOutputThrowsRuntimeException(): void
    {
        $formatter = new TextFormatter();

        $this->expectException(\RuntimeException::class);

        $formatter->error('fail');
    }
}
