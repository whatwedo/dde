<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Generates the Traefik v3 label set for a project hostname or list of
 * aliases. Pure transformation — no I/O, no Docker, no filesystem — so
 * `DockerComposeModifier` can inject labels during `project:init` without
 * pulling in the full TraefikService dependency tree.
 */
final class TraefikLabelGenerator
{
    /**
     * Defense-in-depth allow-list for hostnames that end up between Traefik
     * `Host()` backticks. The compose entry point (`VIRTUAL_HOST`) is
     * user-controlled and could otherwise smuggle in a closing backtick plus
     * an extra rule like `` )||PathPrefix(`/admin` ``. The worktree path is
     * already safe (sanitised via `IdentifierSanitizer::forHostname`); this
     * just keeps the unsanitised entry point honest.
     */
    private const string HOSTNAME_PATTERN = '/^[A-Za-z0-9.-]+$/';

    /**
     * @param list<string> $hostnames
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException
     */
    public static function generateLabels(array $hostnames, string $serviceName, ?int $port = null): array
    {
        if ($hostnames === []) {
            throw new \InvalidArgumentException('At least one hostname is required');
        }

        foreach ($hostnames as $hostname) {
            if (preg_match(self::HOSTNAME_PATTERN, $hostname) !== 1) {
                throw new \InvalidArgumentException(sprintf(
                    'Hostname %s contains characters that would not survive Traefik label interpolation safely',
                    var_export($hostname, true),
                ));
            }
        }

        $primaryHostname = $hostnames[0];
        $routerName = self::generateRouterName($primaryHostname, $serviceName);
        $hostRule = self::generateHostRule($hostnames);

        $labels = [
            'traefik.enable=true',
            sprintf('traefik.http.routers.%s.rule=%s', $routerName, $hostRule),
        ];

        if ($port !== null) {
            $labels[] = sprintf('traefik.http.services.%s.loadbalancer.server.port=%d', $routerName, $port);
        }

        $labels[] = sprintf('traefik.http.routers.%s-tls.rule=%s', $routerName, $hostRule);
        $labels[] = sprintf('traefik.http.routers.%s-tls.tls=true', $routerName);

        return $labels;
    }

    private static function generateRouterName(string $hostname, string $serviceName): string
    {
        // Compose allows dots in service names (`^[a-zA-Z0-9._-]+$`). A dot
        // embedded in the router name would be parsed as a label-path
        // separator in keys like `traefik.http.routers.<name>.rule`,
        // producing an ambiguous Traefik config key. The hostname is already
        // sanitised here for the same reason; do the same for the service.
        return str_replace('.', '-', $hostname).'-'.str_replace('.', '-', $serviceName);
    }

    /**
     * @param list<string> $hostnames
     */
    private static function generateHostRule(array $hostnames): string
    {
        $parts = array_map(
            static fn (string $h): string => sprintf('Host(`%s`)', $h),
            $hostnames,
        );

        return implode(' || ', $parts);
    }
}
