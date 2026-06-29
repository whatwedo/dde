<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Config\SshAgentMode;
use PHPUnit\Framework\TestCase;

final class SshAgentModeTest extends TestCase
{
    public function testManagedIsBackedByManagedString(): void
    {
        $this->assertSame('managed', SshAgentMode::Managed->value);
    }

    public function testHostIsBackedByHostString(): void
    {
        $this->assertSame('host', SshAgentMode::Host->value);
    }

    public function testFromString(): void
    {
        $this->assertSame(SshAgentMode::Managed, SshAgentMode::from('managed'));
        $this->assertSame(SshAgentMode::Host, SshAgentMode::from('host'));
    }
}
