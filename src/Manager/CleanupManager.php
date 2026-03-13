<?php

declare(strict_types=1);

namespace App\Manager;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

readonly class CleanupManager
{
    public function __construct(
        private DockerManager $dockerManager,
        private Filesystem $filesystem,
        private string $dataDir,
    ) {
    }

    /**
     * Collects items in deletion order: containers first, network last.
     *
     * @return array<array{type: string, id: string, name: string}>
     */
    public function collectCleanupItems(): array
    {
        /** @var array<array{type: string, id: string, name: string}> $items */
        $items = [];

        // 1. All dde-managed containers (running and stopped)
        $containers = $this->dockerManager->getContainersByLabel('dde.managed', 'true');

        foreach ($containers as $container) {
            $items[] = [
                'type' => 'container',
                'id' => $container->name,
                'name' => $container->name,
            ];
        }

        // 2. Orphaned volumes with dde.managed=true label
        $volumes = $this->dockerManager->listVolumes([
            'label' => 'dde.managed=true',
        ]);

        foreach ($volumes as $volume) {
            if ($volume['Name'] !== '') {
                $items[] = [
                    'type' => 'volume',
                    'id' => $volume['Name'],
                    'name' => $volume['Name'],
                ];
            }
        }

        // 3. Orphaned dde layer images with dde.configured=true label
        $images = $this->dockerManager->listImages([
            'label' => 'dde.configured=true',
        ]);

        foreach ($images as $image) {
            $imageId = $image['ID'];
            $imageName = $image['Repository'] !== '' ? sprintf('%s:%s', $image['Repository'], $image['Tag']) : $imageId;

            if ($imageId !== '') {
                $items[] = [
                    'type' => 'image',
                    'id' => $imageId,
                    'name' => $imageName,
                ];
            }
        }

        // 4. Certificate files in dataDir/certs/
        $certsDir = $this->dataDir.'/certs';

        if (is_dir($certsDir)) {
            $finder = new Finder();
            $finder->files()->in($certsDir)->name('*.pem');

            foreach ($finder as $file) {
                $items[] = [
                    'type' => 'cert',
                    'id' => $file->getPathname(),
                    'name' => $file->getFilename(),
                ];
            }
        }

        // 5. dde Docker network (last — requires all containers removed first)
        if ($this->dockerManager->networkExists('dde')) {
            $items[] = [
                'type' => 'network',
                'id' => 'dde',
                'name' => 'dde',
            ];
        }

        return $items;
    }

    /**
     * @param array{type: string, id: string, name: string} $item
     */
    public function deleteItem(array $item): void
    {
        match ($item['type']) {
            'container' => $this->forceRemoveContainer($item['id']),
            'image' => $this->dockerManager->removeImage($item['id']),
            'volume' => $this->dockerManager->removeVolume($item['id']),
            'network' => $this->dockerManager->removeNetwork($item['id']),
            'cert' => $this->filesystem->remove($item['id']),
            default => null,
        };
    }

    private function forceRemoveContainer(string $name): void
    {
        if ($this->dockerManager->isContainerRunning($name)) {
            $this->dockerManager->stop($name);
        }

        $this->dockerManager->remove($name);
    }
}
