<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

/**
 * Noyau de l'application Symfony CINAF.
 *
 * MicroKernelTrait charge la configuration standard : bundles déclarés dans
 * config/bundles.php, services et paquets de config/, routes de config/routes.yaml
 * et config/routes/. Seuls les dossiers de cache et de logs sont personnalisés.
 */
class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Dossier du cache Symfony, par environnement.
     *
     * Sous Windows (séparateur `\`), il est placé dans le dossier temporaire
     * système (`%TEMP%/cinaf_backend/cache/{env}`) plutôt que dans `var/cache` :
     * le projet vit dans OneDrive, ce qui provoque des conflits de permissions
     * NTFS/OneDrive sur les fichiers écrits en continu. Contrepartie : Windows
     * peut purger ce dossier temporaire (voir le gotcha n° 19 de BACKEND_MEMORY.md).
     * Sur les autres systèmes (serveur Linux), le dossier standard
     * `var/cache/{env}` est conservé.
     */
    public function getCacheDir(): string
    {
        // Symfony cache hors OneDrive pour éviter les conflits de permissions NTFS/OneDrive
        if ('\\' === \DIRECTORY_SEPARATOR) {
            return sys_get_temp_dir() . '/cinaf_backend/cache/' . $this->environment;
        }
        return parent::getCacheDir();
    }

    /**
     * Dossier des logs : même logique que getCacheDir(), `%TEMP%/cinaf_backend/log`
     * sous Windows (commun à tous les environnements), `var/log` ailleurs.
     */
    public function getLogDir(): string
    {
        if ('\\' === \DIRECTORY_SEPARATOR) {
            return sys_get_temp_dir() . '/cinaf_backend/log';
        }
        return parent::getLogDir();
    }
}
