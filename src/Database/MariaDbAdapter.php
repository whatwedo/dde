<?php

declare(strict_types=1);

namespace App\Database;

final class MariaDbAdapter implements DatabaseAdapterInterface
{
    public function getServiceName(): string
    {
        return 'mariadb';
    }

    public function supports(string $serviceName): bool
    {
        return in_array($serviceName, ['mariadb', 'mysql'], true);
    }

    public function getUsername(): string
    {
        return 'root';
    }

    public function getPassword(): string
    {
        return 'root';
    }

    public function getDefaultPort(): int
    {
        return 3306;
    }

    /**
     * @return list<string>
     */
    public function getDumpCommand(string $database): array
    {
        return [
            'mariadb-dump',
            '-u',
            $this->getUsername(),
            $database,
        ];
    }

    /**
     * @return list<string>
     */
    public function getRestoreCommand(string $database): array
    {
        return [
            'mariadb',
            '-u',
            $this->getUsername(),
            $database,
        ];
    }

    /**
     * @return list<string>
     */
    public function getShellCommand(string $database = ''): array
    {
        $cmd = [
            'mariadb',
            '-u',
            $this->getUsername(),
        ];

        if ($database !== '') {
            $cmd[] = $database;
        }

        return $cmd;
    }

    public function getDsn(string $host = '127.0.0.1', int $port = 0, ?string $database = null): string
    {
        $dsn = sprintf(
            'mysql://%s:%s@%s:%d',
            rawurlencode($this->getUsername()),
            rawurlencode($this->getPassword()),
            $host,
            $port > 0 ? $port : $this->getDefaultPort(),
        );

        if ($database !== null) {
            $dsn .= '/'.$database;
        }

        return $dsn;
    }
}
