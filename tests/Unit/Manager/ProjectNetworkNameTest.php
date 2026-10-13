<?php

declare(strict_types=1);

namespace Tests\Unit\Manager;

use App\Config\WorktreeInfo;
use App\Manager\ProjectLifecycleManager;
use PHPUnit\Framework\TestCase;

final class ProjectNetworkNameTest extends TestCase
{
    public function testWorktreeDoesNotShareNetworkWithAnotherMainProject(): void
    {
        $worktree = new WorktreeInfo('/projects/shop', '/projects/shop-feature', 'feature', 'shop-feature');

        self::assertNotSame(
            ProjectLifecycleManager::buildProjectNetworkName('shop-feature'),
            ProjectLifecycleManager::buildProjectNetworkName('shop', $worktree),
        );
    }

    public function testSameBasenameInDifferentDirectoriesHasSeparateNetworks(): void
    {
        $first = new WorktreeInfo('/projects/shop', '/projects/first/feature', 'first', 'feature');
        $second = new WorktreeInfo('/projects/shop', '/projects/second/feature', 'second', 'feature');

        self::assertNotSame(
            ProjectLifecycleManager::buildProjectNetworkName('shop', $first),
            ProjectLifecycleManager::buildProjectNetworkName('shop', $second),
        );
    }

    public function testSanitizedSuffixesDoNotMergeNetworks(): void
    {
        $first = new WorktreeInfo('/projects/shop', '/projects/feature_one', 'first', 'feature_one');
        $second = new WorktreeInfo('/projects/shop', '/projects/feature-one', 'second', 'feature-one');

        self::assertNotSame(
            ProjectLifecycleManager::buildProjectNetworkName('shop', $first),
            ProjectLifecycleManager::buildProjectNetworkName('shop', $second),
        );
    }

    public function testSwitchingBranchesKeepsNetworkIdentity(): void
    {
        $first = new WorktreeInfo('/projects/shop', '/projects/feature', 'first', 'feature');
        $second = new WorktreeInfo('/projects/shop', '/projects/feature', 'second', 'feature');

        self::assertSame(
            ProjectLifecycleManager::buildProjectNetworkName('shop', $first),
            ProjectLifecycleManager::buildProjectNetworkName('shop', $second),
        );
        self::assertSame('dde-services-shop', ProjectLifecycleManager::buildProjectNetworkName('shop'));
    }
}
