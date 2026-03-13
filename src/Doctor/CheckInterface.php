<?php

declare(strict_types=1);

namespace App\Doctor;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('dde.doctor_check')]
interface CheckInterface
{
    public function getName(): string;

    public function run(): CheckResult;

    public function getPriority(): int;

    public function requiresDocker(): bool;
}
