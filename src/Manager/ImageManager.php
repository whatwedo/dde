<?php

declare(strict_types=1);

namespace App\Manager;

use App\Config\ResolvedConfig;
use App\Model\UserContext;
use App\Util\TempFileUtil;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

readonly class ImageManager
{
    private const string DEV_IMAGE_PREFIX = 'dde-';

    private const string DEV_IMAGE_TAG = 'dev';

    public function __construct(
        private DockerManager $dockerManager,
        private UserContext $userContext,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function hasLabel(string $image, string $label): bool
    {
        try {
            $data = $this->dockerManager->inspect($image);
        } catch (\RuntimeException) {
            return false;
        }

        $labels = $data['Config']['Labels'] ?? [];

        return isset($labels[$label]);
    }

    public function getLabel(string $image, string $label): ?string
    {
        try {
            $data = $this->dockerManager->inspect($image);
        } catch (\RuntimeException) {
            return null;
        }

        $labels = $data['Config']['Labels'] ?? [];

        if (! isset($labels[$label])) {
            return null;
        }

        return is_string($labels[$label]) ? $labels[$label] : null;
    }

    public function buildDevLayer(string $baseImage, string $projectName, ?OutputInterface $output = null): string
    {
        $tag = $this->getDevImageTag($projectName);
        $distro = $this->detectDistro($baseImage);
        $dockerfile = $this->generateDockerfile($baseImage, $projectName, $distro, $this->userContext->uid, $this->userContext->gid);

        $tempDir = TempFileUtil::createTempDir('dde-build-');

        try {
            $this->filesystem->dumpFile($tempDir.'/Dockerfile', $dockerfile);
            $this->dockerManager->buildImage($tempDir, $tag, $output);
        } finally {
            $this->filesystem->remove($tempDir);
        }

        return $tag;
    }

    public function invalidateLayer(string $projectName): void
    {
        $tag = $this->getDevImageTag($projectName);

        if (! $this->dockerManager->imageExists($tag)) {
            return;
        }

        $this->dockerManager->removeImage($tag);
    }

    public function isLayerCached(string $projectName): bool
    {
        return $this->dockerManager->imageExists($this->getDevImageTag($projectName));
    }

    /**
     * @param array<string, array<string, mixed>> $services merged Compose services
     *                                                      (base + user override) — see
     *                                                      DockerComposeManager::getMergedServices().
     *                                                      Passed in so that base files using custom
     *                                                      Compose YAML tags (`!override`, `!reset`)
     *                                                      are parsed once with `PARSE_CUSTOM_TAGS`
     *                                                      upstream instead of being re-parsed here.
     *
     * @return array{serviceName: string, imageTag: string}|null
     */
    public function ensureDevLayers(ResolvedConfig $config, array $services, ?OutputInterface $output = null): ?array
    {
        if ($config->projectName === '') {
            return null;
        }

        if ($this->isLayerCached($config->projectName)) {
            return null;
        }

        foreach ($services as $serviceName => $serviceConfig) {
            if (! is_string($serviceConfig['image'] ?? null)) {
                continue;
            }

            $baseImage = $serviceConfig['image'];

            if (! $this->dockerManager->imageHasShell($baseImage)) {
                continue;
            }

            $imageTag = $this->buildDevLayer($baseImage, $config->projectName, $output);

            return [
                'serviceName' => $serviceName,
                'imageTag' => $imageTag,
            ];
        }

        return null;
    }

    public function detectDistro(string $image): string
    {
        $process = $this->dockerManager->runEphemeral($image, ['cat', '/etc/os-release']);

        if ($process->isSuccessful() && str_contains(strtolower($process->getOutput()), 'alpine')) {
            return 'alpine';
        }

        return 'debian';
    }

    private function getDevImageTag(string $projectName): string
    {
        return self::DEV_IMAGE_PREFIX.$projectName.':'.self::DEV_IMAGE_TAG;
    }

    private function generateDockerfile(string $baseImage, string $projectName, string $distro, int $uid, int $gid): string
    {
        $lines = [
            sprintf('FROM %s', $baseImage),
            'LABEL dde.configured="true"',
            sprintf('LABEL dde.project="%s"', $projectName),
            'USER root',
        ];

        if ($distro === 'alpine') {
            $lines[] = 'RUN apk add --no-cache su-exec shadow \\';
            $lines[] = sprintf('    && addgroup -g %d dde || true \\', $gid);
            $lines[] = sprintf('    && adduser -u %d -G dde -D -h /home/dde dde || true', $uid);
        } else {
            // shadow's C-binary tools, not Debian's Perl adduser/addgroup, which
            // abort under taint mode on these images (world-writable PATH dirs).
            $lines[] = sprintf('RUN groupadd -g %d dde 2>/dev/null || true; \\', $gid);
            $lines[] = sprintf('    useradd -u %d -g %d -m -d /home/dde -s /bin/sh dde 2>/dev/null || true', $uid, $gid);
        }

        return implode("\n", $lines)."\n";
    }
}
