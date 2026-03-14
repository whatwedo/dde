<?php

declare(strict_types=1);

namespace Tests\Unit\Manager;

use App\Manager\DockerManager;
use App\Manager\ImageManager;
use App\Model\UserContext;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class ImageManagerTest extends TestCase
{
    private ImageManager $manager;

    private DockerManager&MockObject $dockerManager;

    #[AllowMockObjectsWithoutExpectations]
    public function testHasLabelReturnsTrueWhenLabelExists(): void
    {
        $this->dockerManager->method('inspect')
            ->with('nginx:latest')
            ->willReturn([
                'Config' => [
                    'Labels' => [
                        'dde.configured' => 'true',
                    ],
                ],
            ]);

        $this->assertTrue($this->manager->hasLabel('nginx:latest', 'dde.configured'));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testHasLabelReturnsFalseWhenLabelMissing(): void
    {
        $this->dockerManager->method('inspect')
            ->with('nginx:latest')
            ->willReturn([
                'Config' => [
                    'Labels' => [],
                ],
            ]);

        $this->assertFalse($this->manager->hasLabel('nginx:latest', 'dde.configured'));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testHasLabelReturnsFalseWhenNoLabels(): void
    {
        $this->dockerManager->method('inspect')
            ->with('nginx:latest')
            ->willReturn([
                'Config' => [],
            ]);

        $this->assertFalse($this->manager->hasLabel('nginx:latest', 'dde.configured'));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testHasLabelReturnsFalseOnInspectFailure(): void
    {
        $this->dockerManager->method('inspect')
            ->with('nonexistent:image')
            ->willThrowException(new \RuntimeException('inspect failed'));

        $this->assertFalse($this->manager->hasLabel('nonexistent:image', 'dde.configured'));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetLabelReturnsValueWhenExists(): void
    {
        $this->dockerManager->method('inspect')
            ->with('nginx:latest')
            ->willReturn([
                'Config' => [
                    'Labels' => [
                        'dde.project' => 'my-project',
                    ],
                ],
            ]);

        $this->assertSame('my-project', $this->manager->getLabel('nginx:latest', 'dde.project'));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetLabelReturnsNullWhenMissing(): void
    {
        $this->dockerManager->method('inspect')
            ->with('nginx:latest')
            ->willReturn([
                'Config' => [
                    'Labels' => [],
                ],
            ]);

        $this->assertNull($this->manager->getLabel('nginx:latest', 'dde.project'));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testGetLabelReturnsNullOnInspectFailure(): void
    {
        $this->dockerManager->method('inspect')
            ->with('nonexistent:image')
            ->willThrowException(new \RuntimeException('inspect failed'));

        $this->assertNull($this->manager->getLabel('nonexistent:image', 'dde.project'));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testIsLayerCachedDelegatesToDockerManager(): void
    {
        $this->dockerManager->method('imageExists')
            ->with('dde-my-project:dev')
            ->willReturn(true);

        $this->assertTrue($this->manager->isLayerCached('my-project'));
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testIsLayerCachedReturnsFalseWhenNotCached(): void
    {
        $this->dockerManager->method('imageExists')
            ->with('dde-my-project:dev')
            ->willReturn(false);

        $this->assertFalse($this->manager->isLayerCached('my-project'));
    }

    public function testBuildDevLayerCallsBuildImageWithCorrectTag(): void
    {
        $this->dockerManager->expects($this->once())
            ->method('buildImage')
            ->with(
                $this->callback(fn (string $dir): bool => is_dir($dir) && file_exists($dir.'/Dockerfile')),
                'dde-test-project:dev',
                null,
            );

        $this->manager->buildDevLayer('nginx:latest', 'test-project');
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testBuildDevLayerReturnsImageTag(): void
    {
        $this->dockerManager->method('buildImage');

        $result = $this->manager->buildDevLayer('nginx:latest', 'test-project');

        $this->assertSame('dde-test-project:dev', $result);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testBuildDevLayerCleansTempDirectory(): void
    {
        $capturedDir = null;

        $this->dockerManager->method('buildImage')
            ->willReturnCallback(function (string $dir) use (&$capturedDir): void {
                $capturedDir = $dir;
                $this->assertFileExists($dir.'/Dockerfile');
            });

        $this->manager->buildDevLayer('nginx:latest', 'test-project');

        $this->assertNotNull($capturedDir);
        $this->assertDirectoryDoesNotExist($capturedDir);
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testBuildDevLayerUsesCurrentUserUidGid(): void
    {
        $capturedDockerfile = null;

        $this->dockerManager->method('buildImage')
            ->willReturnCallback(function (string $dir) use (&$capturedDockerfile): void {
                $capturedDockerfile = file_get_contents($dir.'/Dockerfile');
            });

        $this->manager->buildDevLayer('nginx:latest', 'uid-test');

        $this->assertIsString($capturedDockerfile);
        $expectedUid = posix_getuid();
        $expectedGid = posix_getgid();
        $this->assertStringContainsString((string) $expectedUid, $capturedDockerfile);
        $this->assertStringContainsString((string) $expectedGid, $capturedDockerfile);
        // Only assert non-1000 when the current user is not actually UID 1000
        if ($expectedUid !== 1000 && $expectedGid !== 1000) {
            $this->assertStringNotContainsString('-g 1000', $capturedDockerfile, 'UID/GID should be dynamic, not hardcoded to 1000');
        }
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testBuildDevLayerDockerfileContainsDdeLabels(): void
    {
        $capturedDockerfile = null;

        $this->dockerManager->method('buildImage')
            ->willReturnCallback(function (string $dir) use (&$capturedDockerfile): void {
                $capturedDockerfile = file_get_contents($dir.'/Dockerfile');
            });

        $this->manager->buildDevLayer('nginx:latest', 'label-test');

        $this->assertIsString($capturedDockerfile);
        $this->assertStringContainsString('FROM nginx:latest', $capturedDockerfile);
        $this->assertStringContainsString('LABEL dde.configured="true"', $capturedDockerfile);
        $this->assertStringContainsString('LABEL dde.project="label-test"', $capturedDockerfile);
        $this->assertStringContainsString('adduser', $capturedDockerfile);
        $this->assertStringContainsString('addgroup', $capturedDockerfile);
    }

    public function testInvalidateLayerDoesNothingWhenImageDoesNotExist(): void
    {
        $this->dockerManager->method('imageExists')
            ->with('dde-my-project:dev')
            ->willReturn(false);

        $this->dockerManager->expects($this->never())
            ->method('removeImage');

        $this->manager->invalidateLayer('my-project');
    }

    public function testBuildDevLayerThrowsRuntimeExceptionOnBuildFailure(): void
    {
        $this->dockerManager->method('runEphemeral')
            ->willReturn(new \Symfony\Component\Process\Process(['true']));

        $this->dockerManager->method('buildImage')
            ->willThrowException(new \RuntimeException('build failed'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('build failed');

        $this->manager->buildDevLayer('nginx:latest', 'fail-project');
    }

    public function testEnsureDevLayersSkipsBuildWhenCached(): void
    {
        $config = new \App\Config\ResolvedConfig(
            globalConfig: new \App\Config\GlobalConfig(),
            projectConfig: new \App\Config\ProjectConfig(name: 'cached-project'),
        );

        $this->dockerManager->method('imageExists')
            ->with('dde-cached-project:dev')
            ->willReturn(true);

        $this->dockerManager->expects($this->never())
            ->method('buildImage');

        $result = $this->manager->ensureDevLayers($config, '/tmp/nonexistent-compose.yml');

        $this->assertNull($result);
    }

    protected function setUp(): void
    {
        $this->dockerManager = $this->createMock(DockerManager::class);
        $this->manager = new ImageManager($this->dockerManager, new UserContext());
    }
}
