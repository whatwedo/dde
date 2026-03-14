<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Config\WorktreeInfo;
use PHPUnit\Framework\TestCase;

final class WorktreeInfoTest extends TestCase
{
    public function testConstruction(): void
    {
        $info = new WorktreeInfo(
            mainDirectory: '/path/to/main',
            worktreeDirectory: '/path/to/worktree',
            branch: 'feature-x',
            suffix: 'project-feature-x',
        );

        $this->assertSame('/path/to/main', $info->mainDirectory);
        $this->assertSame('/path/to/worktree', $info->worktreeDirectory);
        $this->assertSame('feature-x', $info->branch);
        $this->assertSame('project-feature-x', $info->suffix);
    }

    public function testReadonlyProperties(): void
    {
        $info = new WorktreeInfo(
            mainDirectory: '/main',
            worktreeDirectory: '/wt',
            branch: 'main',
            suffix: 'wt',
        );

        $reflection = new \ReflectionClass($info);

        $this->assertTrue($reflection->isReadOnly());

        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue($property->isReadOnly(), sprintf('Property "%s" should be readonly', $property->getName()));
        }
    }
}
