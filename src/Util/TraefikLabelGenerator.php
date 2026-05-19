<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Generates the Traefik v3 label set for a project hostname or list of
 * aliases. Pure transformation — no I/O, no Docker, no filesystem — which
 * lets both `DockerComposeManager` (worktree label rewrite fallback) and
 * `DockerComposeModifier` (project:init label injection) consume it without
 * pulling in the full TraefikService dependency tree.
 */
final class TraefikLabelGenerator
{
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

    public static function generateRouterName(string $hostname, string $serviceName): string
    {
        return str_replace('.', '-', $hostname).'-'.$serviceName;
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
