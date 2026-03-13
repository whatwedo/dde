<?php

declare(strict_types=1);

namespace App\Command;

use App\Config\ResolvedConfig;
use App\Model\ServiceDefinition;
use App\Service\ServiceRegistry;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

abstract class AbstractDatabaseCommand extends AbstractProjectCommand
{
    private ?AsciiSlugger $slugger = null;

    /**
     * @throws \RuntimeException
     */
    protected function resolveDbService(InputInterface $input, ResolvedConfig $config, ServiceRegistry $serviceRegistry): ServiceDefinition
    {
        $serviceName = $input->getOption('service');

        if (is_string($serviceName)) {
            foreach ($config->services as $svc) {
                if ($svc->name === $serviceName) {
                    return $this->withResolvedVersion($svc, $config);
                }
            }

            throw new \RuntimeException(sprintf('Service "%s" not found in project config.', $serviceName));
        }

        foreach ($config->services as $svc) {
            if ($serviceRegistry->isDatabaseService($svc->name)) {
                return $this->withResolvedVersion($svc, $config);
            }
        }

        throw new \RuntimeException('No database service configured in project config.');
    }

    /**
     * Resolves the database name from --database option, config, or project name.
     */
    protected function resolveDatabase(InputInterface $input, ResolvedConfig $config, string $serviceName): string
    {
        $database = $input->hasOption('database') ? $input->getOption('database') : null;

        if (is_string($database) && $database !== '') {
            return $database;
        }

        // Check containers.[serviceName].default_database_name in config
        $containerConfig = $config->containers[$serviceName] ?? [];

        if (is_array($containerConfig) && isset($containerConfig['default_database_name']) && is_string($containerConfig['default_database_name'])) {
            return $containerConfig['default_database_name'];
        }

        // Check containers.web.default_database_name as fallback
        $webConfig = $config->containers['web'] ?? [];

        if (is_array($webConfig) && isset($webConfig['default_database_name']) && is_string($webConfig['default_database_name'])) {
            return $webConfig['default_database_name'];
        }

        // Default: project name (sanitized for DB name)
        return $this->sanitizeDatabaseName($config->projectName);
    }

    protected function sanitizeDatabaseName(string $name): string
    {
        // Preserve a leading underscore (valid DB identifier prefix) before slugging
        $leadingUnderscore = str_starts_with($name, '_');

        // Transliterate unicode to ASCII (über -> uber)
        $this->slugger ??= new AsciiSlugger();
        $name = (string) $this->slugger->slug($name, '_')->lower();

        // Replace any remaining non-alphanumeric/underscore chars
        $name = preg_replace('/[^a-z0-9_]/', '_', $name);

        // Collapse consecutive underscores
        $name = preg_replace('/_+/', '_', (string)$name);

        // Trim trailing underscores
        $name = rtrim((string)$name, '_');

        // Re-apply leading underscore if it was present originally
        if ($leadingUnderscore && $name !== '' && !str_starts_with($name, '_')) {
            $name = '_'.$name;
        }

        // If empty after sanitization, use a fallback
        if ($name === '') {
            return 'project';
        }

        // Prefix if starts with digit (invalid in MySQL/PostgreSQL without quoting)
        if (ctype_digit($name[0])) {
            $name = 'db_'.$name;
        }

        return $name;
    }

    private function withResolvedVersion(ServiceDefinition $service, ResolvedConfig $config): ServiceDefinition
    {
        $resolvedVersion = $config->getServiceVersion($service->name);

        if ($resolvedVersion === $service->version) {
            return $service;
        }

        return new ServiceDefinition(
            name: $service->name,
            version: $resolvedVersion,
            containerName: $service->containerName,
            ports: $service->ports,
        );
    }
}
