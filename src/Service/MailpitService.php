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

    public function getContainerConfig(): ContainerConfig
    {
        return new ContainerConfig(
            image: $this->getImageName(),
            containerName: $this->getContainerName(),
            ports: [],
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
