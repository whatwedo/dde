<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use App\Util\ProcessFactory;
use App\Util\UrlOpenerUtil;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class UrlOpenerUtilTest extends TestCase
{
    public function testOpenUsesCorrectCommandForCurrentPlatform(): void
    {
        $expectedCmd = match (PHP_OS_FAMILY) {
            'Darwin' => 'open',
            'Windows' => 'start',
            default => 'xdg-open',
        };

        $lastCommand = null;
        $processFactory = $this->createMock(ProcessFactory::class);
        $processFactory->method('create')
            ->willReturnCallback(static function (array $cmd) use (&$lastCommand): Process {
                $lastCommand = $cmd;
                $process = new Process(['true']);
                $process->run();

                return $process;
            });

        $opener = new UrlOpenerUtil($processFactory);
        $opener->open('https://example.test');

        $this->assertSame([$expectedCmd, 'https://example.test'], $lastCommand);
    }

    public function testOpenReturnsFalseOnFailure(): void
    {
        $processFactory = $this->createMock(ProcessFactory::class);
        $processFactory->method('create')
            ->willReturnCallback(static function (): Process {
                $process = new Process(['false']);
                $process->run();

                return $process;
            });

        $opener = new UrlOpenerUtil($processFactory);

        $this->assertFalse($opener->open('https://example.test'));
    }

    public function testOpenReturnsTrueOnSuccess(): void
    {
        $processFactory = $this->createMock(ProcessFactory::class);
        $processFactory->method('create')
            ->willReturnCallback(static function (): Process {
                $process = new Process(['true']);
                $process->run();

                return $process;
            });

        $opener = new UrlOpenerUtil($processFactory);

        $this->assertTrue($opener->open('https://example.test'));
    }
}
