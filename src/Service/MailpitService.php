<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\ContainerConfig;

final class MailpitService extends AbstractSystemService implements ProjectNetworkAwareInterface
{
    public const string HOSTNAME = 'mail.test';

    /**
     * SMTP DSN projects use to reach Mailpit. Points at the `mail` alias, the
     * only name under which the container serving `mail.test` is reachable on a
     * project network.
     */
    public const string MAILER_DSN = 'smtp://mail:1025';

    /**
     * @var list<string>
     */
    private const array PROJECT_NETWORK_ALIASES = ['mail'];

    public function getName(): string
    {
        return 'mailpit';
    }

    public function getContainerName(): string
    {
        return 'dde-mailpit';
    }

    public function getImageName(): string
    {
        return 'axllent/mailpit';
    }

    /**
     * @return list<string>
     */
    public function getProjectNetworkAliases(): array
    {
        return self::PROJECT_NETWORK_ALIASES;
    }

    public function requiresRestartAfterProjectNetworkAttach(): bool
    {
        return false;
    }

    public function getContainerConfig(): ContainerConfig
    {
        return new ContainerConfig(
            image: $this->getImageName(),
            containerName: $this->getContainerName(),
            ports: [],
            // Disable Mailpit's default 500-message cap so a development run that
            // sends thousands of mails (batch jobs, broken loops, fixture imports)
            // is fully inspectable. With MP_MAX_MESSAGES=0 messages are retained
            // until the user clears them manually from the UI.
            environment: [
                'MP_MAX_MESSAGES' => '0',
            ],
            labels: [
                ...$this->getDefaultLabels(),
                'traefik.enable' => 'true',
                'traefik.http.routers.mailpit.rule' => sprintf('Host(`%s`)', self::HOSTNAME),
                'traefik.http.routers.mailpit-tls.rule' => sprintf('Host(`%s`)', self::HOSTNAME),
                'traefik.http.routers.mailpit-tls.tls' => 'true',
                'traefik.http.services.mailpit.loadbalancer.server.port' => '8025',
            ],
            networkAliases: self::PROJECT_NETWORK_ALIASES,
        );
    }
}
