<?php

declare(strict_types=1);

namespace App\Manager;

use App\Parser\DockerComposeParser;
use App\Util\ProcessFactory;
use Psr\Clock\ClockInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;

readonly class MkcertManager
{
    public function __construct(
        private Filesystem $filesystem,
        private string $dataDir,
        private ClockInterface $clock,
        private DockerComposeParser $composeParser = new DockerComposeParser(),
        private ProcessFactory $processFactory = new ProcessFactory(),
    ) {
    }

    /**
     * Extracts domains from Traefik labels in the compose file(s) and ensures
     * TLS certificates exist. Pass a list of files (base + user override) so
     * routes declared only in an override are covered too.
     *
     * @param string|list<string> $composeFiles
     */
    public function ensureForComposeFile(string $projectName, string|array $composeFiles): void
    {
        if (! $this->isMkcertInstalled()) {
            return;
        }

        $domains = $this->composeParser->extractTraefikDomains($composeFiles);

        if ($domains === []) {
            return;
        }

        $this->ensureCertificate($projectName, $domains);
        $this->updateTraefikDynamicConfig();
    }

    /**
     * Ensures TLS certificates exist for the given domains.
     *
     * @param list<string> $domains
     */
    public function ensureForDomains(string $certName, array $domains): void
    {
        if (! $this->isMkcertInstalled()) {
            return;
        }

        if ($domains === []) {
            return;
        }

        $this->ensureCertificate($certName, $domains);
        $this->updateTraefikDynamicConfig();
    }

    /**
     * Ensures a wildcard default certificate for *.test exists.
     * This certificate is used by Traefik as the default TLS certificate
     * for any .test domain that doesn't have a project-specific certificate.
     *
     * @throws \RuntimeException
     */
    public function ensureDefaultCertificate(): void
    {
        if (! $this->isMkcertInstalled()) {
            return;
        }

        $certsDir = $this->dataDir.'/certs';
        $this->filesystem->mkdir($certsDir);

        $certFile = $certsDir.'/_default.pem';
        $keyFile = $certsDir.'/_default-key.pem';

        if ($this->filesystem->exists($certFile) && $this->filesystem->exists($keyFile)) {
            return;
        }

        $process = $this->processFactory->create([
            'mkcert',
            '-cert-file', $certFile,
            '-key-file', $keyFile,
            '*.test',
        ]);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('Failed to generate default wildcard certificate for *.test');
        }

        $this->updateTraefikDynamicConfig();
    }

    public function install(): void
    {
        if (! $this->isMkcertInstalled()) {
            throw new \RuntimeException('mkcert is not installed. Install it via: brew install mkcert (macOS) or apt install mkcert (Linux)');
        }

        $process = $this->processFactory->create(['mkcert', '-install']);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    public function isMkcertInstalled(): bool
    {
        $process = $this->processFactory->create(['which', 'mkcert']);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * @param array<string> $domains
     *
     * @throws \RuntimeException
     * @throws ProcessFailedException
     */
    public function ensureCertificate(string $projectName, array $domains): void
    {
        if (! $this->isMkcertInstalled()) {
            throw new \RuntimeException('mkcert is not installed. Install it via: brew install mkcert (macOS) or apt install mkcert (Linux)');
        }

        $certsDir = $this->dataDir.'/certs';
        $this->filesystem->mkdir($certsDir);

        $certFile = $this->getCertificatePath($projectName);
        $keyFile = $this->getKeyPath($projectName);

        $command = ['mkcert', '-cert-file', $certFile, '-key-file', $keyFile];

        foreach ($domains as $domain) {
            $command[] = $domain;
        }

        $process = $this->processFactory->create($command);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $this->updateRegistry($projectName, $domains);
    }

    public function getCertificatePath(string $projectName): string
    {
        return $this->dataDir.'/certs/'.$projectName.'.pem';
    }

    public function getKeyPath(string $projectName): string
    {
        return $this->dataDir.'/certs/'.$projectName.'-key.pem';
    }

    public function updateTraefikDynamicConfig(): void
    {
        $certsDir = $this->dataDir.'/certs';
        $dynamicConfigPath = $this->dataDir.'/traefik/dynamic/tls.yml';

        $this->filesystem->mkdir(dirname($dynamicConfigPath));

        $lines = ["tls:\n"];

        // Default wildcard certificate for *.test
        $hasDefault = $this->filesystem->exists($certsDir.'/_default.pem')
            && $this->filesystem->exists($certsDir.'/_default-key.pem');

        if ($hasDefault) {
            $lines[] = "  stores:\n";
            $lines[] = "    default:\n";
            $lines[] = "      defaultCertificate:\n";
            $lines[] = "        certFile: /certs/_default.pem\n";
            $lines[] = "        keyFile: /certs/_default-key.pem\n";
        }

        // Per-project certificates
        $registry = $this->loadRegistry();
        $certificates = [];

        foreach (array_keys($registry) as $projectName) {
            $certFile = '/certs/'.$projectName.'.pem';
            $keyFile = '/certs/'.$projectName.'-key.pem';

            if ($this->filesystem->exists($certsDir.'/'.$projectName.'.pem')) {
                $certificates[] = [
                    'certFile' => $certFile,
                    'keyFile' => $keyFile,
                ];
            }
        }

        if (!$hasDefault && $certificates === []) {
            if ($this->filesystem->exists($dynamicConfigPath)) {
                $this->filesystem->remove($dynamicConfigPath);
            }

            return;
        }

        if ($certificates !== []) {
            $lines[] = "  certificates:\n";

            foreach ($certificates as $cert) {
                $lines[] = sprintf("    - certFile: %s\n", $cert['certFile']);
                $lines[] = sprintf("      keyFile: %s\n", $cert['keyFile']);
            }
        }

        $this->filesystem->dumpFile($dynamicConfigPath, implode('', $lines));
    }

    /**
     * @return array<string, array{domains: array<string>, created: string}>
     */
    public function loadRegistry(): array
    {
        $registryPath = $this->dataDir.'/certs/registry.json';

        if (! $this->filesystem->exists($registryPath)) {
            return [];
        }

        $content = $this->filesystem->readFile($registryPath);

        if ($content === '') {
            return [];
        }

        try {
            /** @var array<string, array{domains: array<string>, created: string}> $data */
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return $data;
    }

    /**
     * @param array<string, array{domains: array<string>, created: string}> $registry
     */
    public function saveRegistry(array $registry): void
    {
        $registryPath = $this->dataDir.'/certs/registry.json';

        $this->filesystem->mkdir(dirname($registryPath));
        $this->filesystem->dumpFile(
            $registryPath,
            json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
        );
    }

    /**
     * @param array<string> $domains
     */
    public function updateRegistry(string $projectName, array $domains): void
    {
        $registry = $this->loadRegistry();
        $registry[$projectName] = [
            'domains' => $domains,
            'created' => $this->clock->now()->format('Y-m-d'),
        ];
        $this->saveRegistry($registry);
    }
}
