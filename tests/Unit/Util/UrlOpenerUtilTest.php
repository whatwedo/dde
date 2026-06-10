<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use App\Util\ProcessFactory;
use App\Util\UrlOpenerUtil;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[AllowMockObjectsWithoutExpectations]
final class UrlOpenerUtilTest extends TestCase
{
    /**
     * @param non-empty-string $expectedCommand
     */
    #[DataProvider('browserResolutionProvider')]
    public function testOpenResolvesTheExpectedCommand(?string $browser, string $expectedCommand): void
    {
        $lastCommand = null;
        $opener = new UrlOpenerUtil($this->commandCapturingFactory($lastCommand));

        $opener->open('https://example.test', $browser);

        $this->assertSame([$expectedCommand, 'https://example.test'], $lastCommand);
    }

    /**
     * @return iterable<string, array{?string, string}>
     */
    public static function browserResolutionProvider(): iterable
    {
        $platformDefault = match (PHP_OS_FAMILY) {
            'Darwin' => 'open',
            'Windows' => 'start',
            default => 'xdg-open',
        };

        yield 'no browser uses platform default' => [null, $platformDefault];
        yield 'empty browser falls back to platform default' => ['', $platformDefault];
        yield 'configured browser overrides platform default' => ['/usr/bin/firefox', '/usr/bin/firefox'];
    }

    public function testOpenReturnsFalseOnFailure(): void
    {
        $opener = new UrlOpenerUtil($this->fixedResultFactory(false));

        $this->assertFalse($opener->open('https://example.test'));
    }

    public function testOpenReturnsTrueOnSuccess(): void
    {
        $opener = new UrlOpenerUtil($this->fixedResultFactory(true));

        $this->assertTrue($opener->open('https://example.test'));
    }

    /**
     * @param list<string>|null $lastCommand captured by reference for assertions
     */
    private function commandCapturingFactory(?array &$lastCommand): ProcessFactory
    {
        $processFactory = $this->createMock(ProcessFactory::class);
        $processFactory->method('create')
            ->willReturnCallback(static function (array $cmd) use (&$lastCommand): Process {
                $lastCommand = $cmd;
                $process = new Process(['true']);
                $process->run();

                return $process;
            });

        return $processFactory;
    }

    private function fixedResultFactory(bool $successful): ProcessFactory
    {
        $processFactory = $this->createMock(ProcessFactory::class);
        $processFactory->method('create')
            ->willReturnCallback(static function () use ($successful): Process {
                $process = new Process([$successful ? 'true' : 'false']);
                $process->run();

                return $process;
            });

        return $processFactory;
    }
}
