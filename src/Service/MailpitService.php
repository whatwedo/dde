<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\ContainerConfig;

final class MailpitService extends AbstractSystemService
{
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

    public function attachesToProjectNetwork(): bool
    {
        return true;
    }

    /**
     * @return list<string>
     */
    public function getProjectNetworkAliases(): array
    {
        return ['mail'];
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
                'traefik.http.routers.mailpit.rule' => 'Host(`mail.test`)',
                'traefik.http.routers.mailpit-tls.rule' => 'Host(`mail.test`)',
                'traefik.http.routers.mailpit-tls.tls' => 'true',
                'traefik.http.services.mailpit.loadbalancer.server.port' => '8025',
            ],
            networkAliases: [
                'mail',
            ],
        );
    }
}
