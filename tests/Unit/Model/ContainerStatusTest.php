<?php

declare(strict_types=1);

namespace Tests\Unit\Model;

use App\Model\ContainerStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContainerStatusTest extends TestCase
{
    /**
     * @return array<string, array{string, ContainerStatus}>
     */
    public static function dockerStatusProvider(): array
    {
        return [
            'up with duration' => ['Up 2 hours', ContainerStatus::RUNNING],
            'up seconds' => ['Up 30 seconds', ContainerStatus::RUNNING],
            'running lowercase' => ['running', ContainerStatus::RUNNING],
            'exited with code' => ['Exited (0) 5 minutes ago', ContainerStatus::EXITED],
            'exited nonzero' => ['Exited (1) 2 hours ago', ContainerStatus::EXITED],
            'exit lowercase' => ['exit', ContainerStatus::EXITED],
            'paused' => ['Paused', ContainerStatus::PAUSED],
            'paused lowercase' => ['paused', ContainerStatus::PAUSED],
            'restarting' => ['Restarting (1) 3 seconds ago', ContainerStatus::RESTARTING],
            'restarting lowercase' => ['restarting', ContainerStatus::RESTARTING],
            'created' => ['Created', ContainerStatus::CREATED],
            'created lowercase' => ['created', ContainerStatus::CREATED],
            'dead' => ['Dead', ContainerStatus::DEAD],
            'dead lowercase' => ['dead', ContainerStatus::DEAD],
            'empty string' => ['', ContainerStatus::UNKNOWN],
            'unknown value' => ['foobar', ContainerStatus::UNKNOWN],
        ];
    }

    #[DataProvider('dockerStatusProvider')]
    public function testFromDockerStatus(string $dockerStatus, ContainerStatus $expected): void
    {
        $this->assertSame($expected, ContainerStatus::fromDockerStatus($dockerStatus));
    }
}
