<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function getCacheDir(): string
    {
        if ($this->isRunningAsPhar()) {
            return $this->getProjectDir().'/var/cache/'.$this->environment;
        }

        return parent::getCacheDir();
    }

    public function getLogDir(): string
    {
        if ($this->isRunningAsPhar()) {
            return sys_get_temp_dir().'/dde/log';
        }

        return parent::getLogDir();
    }

    private function isRunningAsPhar(): bool
    {
        return str_starts_with(__DIR__, 'phar://');
    }
}
