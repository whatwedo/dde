<?php

declare(strict_types=1);

namespace Tests\Unit\Model;

use App\Model\UserContext;
use PHPUnit\Framework\TestCase;

final class UserContextTest extends TestCase
{
    public function testConstructorWithExplicitStringValues(): void
    {
        $context = new UserContext(uid: '1000', gid: '1000');

        $this->assertSame(1000, $context->uid);
        $this->assertSame(1000, $context->gid);
    }

    public function testConstructorWithDifferentUidGid(): void
    {
        $context = new UserContext(uid: '501', gid: '20');

        $this->assertSame(501, $context->uid);
        $this->assertSame(20, $context->gid);
    }

    public function testConstructorDefaultsToCurrentUser(): void
    {
        $context = new UserContext();

        $this->assertSame(posix_getuid(), $context->uid);
        $this->assertSame(posix_getgid(), $context->gid);
    }

    public function testConstructorWithEmptyStringsDefaultsToCurrentUser(): void
    {
        $context = new UserContext(uid: '', gid: '');

        $this->assertSame(posix_getuid(), $context->uid);
        $this->assertSame(posix_getgid(), $context->gid);
    }

    public function testConstructorCastsStringToInt(): void
    {
        $context = new UserContext(uid: '0', gid: '0');

        $this->assertSame(0, $context->uid);
        $this->assertSame(0, $context->gid);
    }
}
