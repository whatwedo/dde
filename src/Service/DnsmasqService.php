<?php

declare(strict_types=1);

namespace App\Service;

use App\Manager\DockerManager;
use App\Model\ContainerConfig;
use App\Util\PrivilegeEscalator;
use App\Util\ProcessFactory;
use Symfony\Component\Filesystem\Filesystem;

final class DnsmasqService extends AbstractSystemService
{
    public function __construct(
        DockerManager $dockerManager,
        private readonly Filesystem $filesystem,
        private readonly ImageBuilder $imageBuilder,
        private readonly PrivilegeEscalator $escalator,
        private readonly string $projectDir,
        private readonly string $dataDir,
        private readonly ProcessFactory $processFactory = new ProcessFactory(),
    ) {
        parent::__construct($dockerManager);
    }

    public function getName(): string
    {
        return 'dnsmasq';
    }

    public function getContainerName(): string
    {
        return 'dde-dnsmasq';
    }

    public function getImageName(): string
    {
        return 'dde-dnsmasq:local';
    }

    public function getContainerConfig(): ContainerConfig
    {
        return new ContainerConfig(
            image: $this->getImageName(),
            containerName: $this->getContainerName(),
            ports: [
                '127.0.0.1:53:53/udp',
                '127.0.0.1:53:53/tcp',
            ],
            volumes: [
                $this->dataDir.'/dnsmasq/dnsmasq.conf' => '/etc/dnsmasq.conf:ro',
            ],
            labels: $this->getDefaultLabels(),
        );
    }

    public function start(): void
    {
        $this->ensureConfig();
        $this->build();

        parent::start();
    }

    public function build(bool $pull = false): void
    {
        $this->buildImage($pull);
    }

    /**
     * @param array<string> $forwardDns
     */
    public function ensureConfig(array $forwardDns = ['9.9.9.9', '149.112.112.112']): void
    {
        $configDir = $this->dataDir.'/dnsmasq';
        $this->filesystem->mkdir($configDir);

        $lines = [
            'address=/test/127.0.0.1',
        ];

        foreach ($forwardDns as $dns) {
            $lines[] = 'server='.$dns;
        }

        $lines[] = 'no-dhcp-interface=';
        $lines[] = 'log-queries';
        $lines[] = 'log-facility=-';

        $this->filesystem->dumpFile($configDir.'/dnsmasq.conf', implode("\n", $lines)."\n");
    }

    public function buildImage(bool $pull = false): void
    {
        $resourceDir = $this->projectDir.'/resources/docker/dnsmasq';
        $dockerfilePath = $resourceDir.'/Dockerfile';

        if (!$this->filesystem->exists($dockerfilePath)) {
            throw new \RuntimeException(sprintf('Dockerfile not found at "%s"', $dockerfilePath));
        }

        $this->imageBuilder->buildIfChanged(
            $this->getImageName(),
            $this->dataDir.'/dnsmasq/.build-hash',
            [
                'Dockerfile' => $this->filesystem->readFile($dockerfilePath),
            ],
            'dde-dnsmasq-',
            $pull,
        );
    }

    /**
     * Configures DNS resolution for the .test TLD.
     *
     * On macOS: writes a resolver file to /etc/resolver/test.
     * On Linux: configures systemd-resolved or NetworkManager.
     *
     * The Linux /etc/** writes are routed through {@see PrivilegeEscalator}
     * (optimistic-then-sudo): the operation is attempted as the current user
     * first; on a permission failure, it is retried once via sudo with TTY
     * forwarding. dde must NOT be invoked under sudo — bin/console rejects that
     * case up-front; see {@see PrivilegeEscalator} and the bin/console root-guard.
     *
     * @throws \RuntimeException if the platform is not supported
     */
    public function configureDns(): void
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            $this->configureDnsMacOs();

            return;
        }

        if (PHP_OS_FAMILY === 'Linux') {
            $this->configureDnsLinux();

            return;
        }

        throw new \RuntimeException(sprintf('DNS configuration is not yet supported on %s. Configure your DNS resolver manually to forward .test to 127.0.0.1.', PHP_OS_FAMILY));
    }

    public function getResolverContent(): string
    {
        return "nameserver 127.0.0.1\n";
    }

    private function configureDnsLinux(): void
    {
        if ($this->isSystemdResolvedActive()) {
            $this->configureDnsSystemdResolved();

            return;
        }

        if ($this->isNetworkManagerActive()) {
            $this->configureDnsNetworkManager();

            return;
        }

        throw new \RuntimeException('No supported DNS resolver found on Linux. Expected systemd-resolved or NetworkManager.');
    }

    private function isSystemdResolvedActive(): bool
    {
        $process = $this->processFactory->create(['systemctl', 'is-active', 'systemd-resolved']);
        $process->run();

        return $process->isSuccessful() && trim($process->getOutput()) === 'active';
    }

    private function isNetworkManagerActive(): bool
    {
        $process = $this->processFactory->create(['systemctl', 'is-active', 'NetworkManager']);
        $process->run();

        return $process->isSuccessful() && trim($process->getOutput()) === 'active';
    }

    private function configureDnsSystemdResolved(): void
    {
        $configDir = '/etc/systemd/resolved.conf.d';
        $configFile = $configDir.'/dde-test.conf';
        $content = "[Resolve]\nDNS=127.0.0.1\nDomains=~test\n";

        if ($this->filesystem->exists($configFile) && $this->filesystem->readFile($configFile) === $content) {
            return;
        }

        $this->escalator->ensureDir($configDir);
        $this->escalator->writeFile($configFile, $content);
        $this->escalator->run(['systemctl', 'restart', 'systemd-resolved']);
    }

    private function configureDnsNetworkManager(): void
    {
        $configDir = '/etc/NetworkManager/dnsmasq.d';
        $configFile = $configDir.'/dde-test.conf';
        $content = "server=/test/127.0.0.1\n";

        if ($this->filesystem->exists($configFile) && $this->filesystem->readFile($configFile) === $content) {
            return;
        }

        $this->escalator->ensureDir($configDir);
        $this->escalator->writeFile($configFile, $content);
        $this->escalator->run(['systemctl', 'restart', 'NetworkManager']);
    }

    private function configureDnsMacOs(): void
    {
        $resolverDir = '/etc/resolver';
        $resolverFile = $resolverDir.'/test';

        $content = $this->getResolverContent();

        if ($this->filesystem->exists($resolverFile) && $this->filesystem->readFile($resolverFile) === $content) {
            return;
        }

        $this->filesystem->mkdir($resolverDir);
        $this->filesystem->dumpFile($resolverFile, $content);
    }
}
