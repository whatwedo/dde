<?php

declare(strict_types=1);

namespace Tests\Unit\Exception;

use App\Exception\HookFailedException;
use PHPUnit\Framework\TestCase;

final class HookFailedExceptionTest extends TestCase
{
    public function testMessageContainsScriptName(): void
    {
        $exception = new HookFailedException('/path/to/01-migrate.sh', 1, 'some error');

        $this->assertStringContainsString('01-migrate.sh', $exception->getMessage());
        $this->assertStringContainsString('exit code 1', $exception->getMessage());
        $this->assertStringContainsString('some error', $exception->getMessage());
    }

    public function testScriptPropertyIsAccessible(): void
    {
        $exception = new HookFailedException('/path/to/script.sh', 42, '');

        $this->assertSame('/path/to/script.sh', $exception->script);
    }

    public function testExtendsRuntimeException(): void
    {
        $exception = new HookFailedException('/script.sh', 1, '');

        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }
}
