<?php

declare(strict_types=1);

namespace Tests\Unit\Model;

use App\Model\ContainerInfo;
use App\Model\ContainerStatus;
use PHPUnit\Framework\TestCase;

final class ContainerInfoTest extends TestCase
{
    public function testConstructionWithDefaults(): void
    {
        $info = new ContainerInfo(
            name: 'web',
            status: ContainerStatus::RUNNING,
            image: 'nginx:latest',
        );

        $this->assertSame('web', $info->name);
        $this->assertSame(ContainerStatus::RUNNING, $info->status);
        $this->assertSame('nginx:latest', $info->image);
        $this->assertSame([], $info->labels);
    }

    public function testConstructionWithAllFields(): void
    {
        $labels = [
            'com.docker.compose.project' => 'my-project',
        ];

        $info = new ContainerInfo(
            name: 'db',
            status: ContainerStatus::EXITED,
            image: 'mysql:8.0',
            labels: $labels,
        );

        $this->assertSame('db', $info->name);
        $this->assertSame(ContainerStatus::EXITED, $info->status);
        $this->assertSame('mysql:8.0', $info->image);
        $this->assertSame($labels, $info->labels);
    }
}
