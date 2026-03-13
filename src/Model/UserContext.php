<?php

declare(strict_types=1);

namespace App\Model;

final readonly class UserContext
{
    public int $uid;

    public int $gid;

    public function __construct(string $uid = '', string $gid = '')
    {
        $this->uid = $uid !== '' ? (int) $uid : posix_getuid();
        $this->gid = $gid !== '' ? (int) $gid : posix_getgid();
    }
}
