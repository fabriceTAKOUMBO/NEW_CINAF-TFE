<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function getCacheDir(): string
    {
        // Symfony cache hors OneDrive pour éviter les conflits de permissions NTFS/OneDrive
        if ('\\' === \DIRECTORY_SEPARATOR) {
            return sys_get_temp_dir() . '/cinaf_backend/cache/' . $this->environment;
        }
        return parent::getCacheDir();
    }

    public function getLogDir(): string
    {
        if ('\\' === \DIRECTORY_SEPARATOR) {
            return sys_get_temp_dir() . '/cinaf_backend/log';
        }
        return parent::getLogDir();
    }
}
