<?php

declare(strict_types=1);

namespace Tests\Unit\Manager;

use App\Manager\CleanupManager;
use App\Manager\DockerManager;
use App\Model\ContainerInfo;
use App\Model\ContainerStatus;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[AllowMockObjectsWithoutExpectations]
final class CleanupManagerTest extends TestCase
{
    private DockerManager&MockObject $dockerManager;

    private Filesystem&MockObject $filesystem;

    private string $tempDir;

    private CleanupManager $manager;

    public function testCollectAllManagedContainers(): void
    {
        $this->dockerManager->method('getContainersByLabel')->willReturn([
            new ContainerInfo(name: 'dde-web', status: ContainerStatus::EXITED, image: 'test:latest'),
            new ContainerInfo(name: 'dde-db', status: ContainerStatus::RUNNING, image: 'mariadb:10'),
        ]);
        $this->dockerManager->method('listVolumes')->willReturn([]);
        $this->dockerManager->method('listImages')->willReturn([]);

        $items = $this->manager->collectCleanupItems();

        $containerItems = array_filter($items, static fn (array $i): bool => $i['type'] === 'container');
        $this->assertCount(2, $containerItems);
    }

    public function testCollectOrphanedVolumes(): void
    {
        $this->dockerManager->method('getContainersByLabel')->willReturn([]);
        $this->dockerManager->method('listVolumes')->willReturn([
            [
                'Name' => 'dde-project-db',
                'Labels' => 'dde.managed=true',
            ],
        ]);
        $this->dockerManager->method('listImages')->willReturn([]);

        $items = $this->manager->collectCleanupItems();

        $this->assertCount(1, $items);
        $this->assertSame('volume', $items[0]['type']);
        $this->assertSame('dde-project-db', $items[0]['id']);
        $this->assertSame('dde-project-db', $items[0]['name']);
    }

    public function testCollectOrphanedImages(): void
    {
        $this->dockerManager->method('getContainersByLabel')->willReturn([]);
        $this->dockerManager->method('listVolumes')->willReturn([]);
        $this->dockerManager->method('listImages')->willReturn([
            [
                'ID' => 'sha256:abc123',
                'Repository' => 'dde-project',
                'Tag' => 'latest',
                'Size' => '500MB',
            ],
        ]);

        $items = $this->manager->collectCleanupItems();

        $this->assertCount(1, $items);
        $this->assertSame('image', $items[0]['type']);
        $this->assertSame('sha256:abc123', $items[0]['id']);
        $this->assertSame('dde-project:latest', $items[0]['name']);
    }

    public function testCollectCertificates(): void
    {
        $certsDir = $this->tempDir.'/certs';
        mkdir($certsDir, 0o777, true);
        file_put_contents($certsDir.'/example.pem', 'cert content');
        file_put_contents($certsDir.'/other.pem', 'cert content 2');
        file_put_contents($certsDir.'/not-a-cert.txt', 'ignored');

        $this->dockerManager->method('getContainersByLabel')->willReturn([]);
        $this->dockerManager->method('listVolumes')->willReturn([]);
        $this->dockerManager->method('listImages')->willReturn([]);

        $items = $this->manager->collectCleanupItems();

        $this->assertCount(2, $items);
        $certNames = array_column($items, 'name');
        sort($certNames);
        $this->assertSame(['example.pem', 'other.pem'], $certNames);

        foreach ($items as $item) {
            $this->assertSame('cert', $item['type']);
        }
    }

    public function testCollectReturnsEmptyWhenNothingToClean(): void
    {
        $this->dockerManager->method('getContainersByLabel')->willReturn([]);
        $this->dockerManager->method('listVolumes')->willReturn([]);
        $this->dockerManager->method('listImages')->willReturn([]);

        $items = $this->manager->collectCleanupItems();

        $this->assertSame([], $items);
    }

    public function testDeleteContainerCallsRemove(): void
    {
        $this->dockerManager->expects($this->once())
            ->method('remove')
            ->with('dde-web');

        $this->manager->deleteItem([
            'type' => 'container',
            'id' => 'dde-web',
            'name' => 'dde-web',
        ]);
    }

    public function testDeleteVolumeCallsRemoveVolume(): void
    {
        $this->dockerManager->expects($this->once())
            ->method('removeVolume')
            ->with('dde-project-db');

        $this->manager->deleteItem([
            'type' => 'volume',
            'id' => 'dde-project-db',
            'name' => 'dde-project-db',
        ]);
    }

    public function testDeleteImageCallsRemoveImage(): void
    {
        $this->dockerManager->expects($this->once())
            ->method('removeImage')
            ->with('sha256:abc123');

        $this->manager->deleteItem([
            'type' => 'image',
            'id' => 'sha256:abc123',
            'name' => 'dde-project:latest',
        ]);
    }

    public function testDeleteCertCallsFilesystemRemove(): void
    {
        $this->filesystem->expects($this->once())
            ->method('remove')
            ->with('/path/to/certs/example.pem');

        $this->manager->deleteItem([
            'type' => 'cert',
            'id' => '/path/to/certs/example.pem',
            'name' => 'example.pem',
        ]);
    }

    protected function setUp(): void
    {
        $this->dockerManager = $this->createMock(DockerManager::class);
        $this->filesystem = $this->createMock(Filesystem::class);
        $this->tempDir = sys_get_temp_dir().'/dde-test-cleanup-'.bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0o777, true);

        $this->manager = new CleanupManager(
            $this->dockerManager,
            $this->filesystem,
            $this->tempDir,
        );
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tempDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($iterator as $file) {
                assert($file instanceof \SplFileInfo);
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }

            rmdir($this->tempDir);
        }
    }
}
