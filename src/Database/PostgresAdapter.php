<?php

declare(strict_types=1);

namespace App\Database;

final class PostgresAdapter implements DatabaseAdapterInterface
{
    public function getServiceName(): string
    {
        return 'postgres';
    }

    public function supports(string $serviceName): bool
    {
        return in_array($serviceName, ['postgres', 'postgresql'], true);
    }

    public function getUsername(): string
    {
        return 'postgres';
    }

    public function getPassword(): string
    {
        return 'postgres';
    }

    public function getDefaultPort(): int
    {
        return 5432;
    }

    /**
     * @return list<string>
     */
    public function getDumpCommand(string $database): array
    {
        return [
            'pg_dump',
            '-U',
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
            'psql',
            '-U',
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
            'psql',
            '-U',
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
            'postgresql://%s:%s@%s:%d',
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
