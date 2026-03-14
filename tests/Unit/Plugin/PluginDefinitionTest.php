<?php

declare(strict_types=1);

namespace Tests\Unit\Plugin;

use App\Plugin\PluginDefinition;
use PHPUnit\Framework\TestCase;

final class PluginDefinitionTest extends TestCase
{
    public function testConstructionSetsProperties(): void
    {
        $definition = new PluginDefinition(
            command: 'web:hash-pw',
            description: 'Generate password hash',
            scriptPath: '/path/to/hash-pw.sh',
        );

        $this->assertSame('web:hash-pw', $definition->command);
        $this->assertSame('Generate password hash', $definition->description);
        $this->assertSame('/path/to/hash-pw.sh', $definition->scriptPath);
    }

    public function testIsReadonly(): void
    {
        $definition = new PluginDefinition(
            command: 'test:cmd',
            description: 'Test',
            scriptPath: '/test.sh',
        );

        $reflection = new \ReflectionClass($definition);

        $this->assertTrue($reflection->isReadOnly());
    }
}
