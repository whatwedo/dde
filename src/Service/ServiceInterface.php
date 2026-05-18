<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\ServiceStatus;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.global_service')]
interface ServiceInterface
{
    public function getName(): string;

    public function getContainerName(): string;

    public function start(): void;

    public function stop(): void;

    public function remove(): void;

    public function build(bool $pull = false): void;

    public function status(): ServiceStatus;

    public function isRunning(): bool;
}
