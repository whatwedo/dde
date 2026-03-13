<?php

declare(strict_types=1);

namespace App\Database;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('dde.database_adapter')]
interface DatabaseAdapterInterface
{
    public function getServiceName(): string;

    /**
     * Whether this adapter supports the given compose service name.
     */
    public function supports(string $serviceName): bool;

    public function getUsername(): string;

    public function getPassword(): string;

    public function getDefaultPort(): int;

    /**
     * @return list<string>
     */
    public function getDumpCommand(string $database): array;

    /**
     * @return list<string>
     */
    public function getRestoreCommand(string $database): array;

    /**
     * @return list<string>
     */
    public function getShellCommand(string $database = ''): array;

    public function getDsn(string $host = '127.0.0.1', int $port = 0, ?string $database = null): string;
}
