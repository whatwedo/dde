<?php

declare(strict_types=1);

namespace Tests\Unit\Output;

use App\Output\JsonFormatter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

final class JsonFormatterTest extends TestCase
{
    public function testSuccessOutputsCorrectJsonFormat(): void
    {
        $formatter = new JsonFormatter();
        $captured = '';
        $output = $this->createStub(OutputInterface::class);
        $output->method('writeln')
            ->willReturnCallback(function (string $text) use (&$captured): void {
                $captured = $text;
            });

        $formatter->setOutput($output);
        $result = $formatter->success([
            'key' => 'value',
        ], 'it worked');

        $this->assertSame(Command::SUCCESS, $result);

        $decoded = json_decode($captured, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('ok', $decoded['status']);
        $this->assertSame('it worked', $decoded['message']);
        $this->assertSame([
            'key' => 'value',
        ], $decoded['data']);
        $this->assertSame([], $decoded['errors']);
    }

    public function testErrorOutputsCorrectJsonFormat(): void
    {
        $formatter = new JsonFormatter();
        $captured = '';
        $output = $this->createStub(OutputInterface::class);
        $output->method('writeln')
            ->willReturnCallback(function (string $text) use (&$captured): void {
                $captured = $text;
            });

        $formatter->setOutput($output);
        $result = $formatter->error('something failed', ['detail1', 'detail2']);

        $this->assertSame(Command::FAILURE, $result);

        $decoded = json_decode($captured, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('error', $decoded['status']);
        $this->assertSame('something failed', $decoded['message']);
        $this->assertNull($decoded['data']);
        $this->assertSame(['detail1', 'detail2'], $decoded['errors']);
    }

    public function testTableOutputsJsonArray(): void
    {
        $formatter = new JsonFormatter();
        $captured = '';
        $output = $this->createStub(OutputInterface::class);
        $output->method('writeln')
            ->willReturnCallback(function (string $text) use (&$captured): void {
                $captured = $text;
            });

        $formatter->setOutput($output);
        $formatter->table(['Name', 'Status'], [
            ['web', 'running'],
            ['db', 'stopped'],
        ]);

        $decoded = json_decode($captured, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('ok', $decoded['status']);
        $this->assertCount(2, $decoded['data']);
        $this->assertSame([
            'Name' => 'web',
            'Status' => 'running',
        ], $decoded['data'][0]);
        $this->assertSame([
            'Name' => 'db',
            'Status' => 'stopped',
        ], $decoded['data'][1]);
    }

    public function testSuccessWithoutSetOutputThrowsRuntimeException(): void
    {
        $formatter = new JsonFormatter();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Output not initialized. Call setOutput() first.');

        $formatter->success(null);
    }

    public function testErrorWithoutSetOutputThrowsRuntimeException(): void
    {
        $formatter = new JsonFormatter();

        $this->expectException(\RuntimeException::class);

        $formatter->error('fail');
    }

    public function testTableWithoutSetOutputThrowsRuntimeException(): void
    {
        $formatter = new JsonFormatter();

        $this->expectException(\RuntimeException::class);

        $formatter->table([], []);
    }
}
