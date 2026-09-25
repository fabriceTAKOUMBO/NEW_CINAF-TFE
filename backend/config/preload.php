<?php

/**
 * Préchargement OPcache : utilisé seulement si php.ini désigne ce fichier dans
 * `opcache.preload` (serveur de production). Il charge la liste de classes
 * générée par Symfony dans le cache prod. Sous Windows, le cache est placé
 * hors du projet (Kernel::getCacheDir()) : ce fichier n'y trouve rien, sans
 * conséquence puisque le préchargement OPcache n'est pas disponible sous Windows.
 */

if (file_exists(dirname(__DIR__).'/var/cache/prod/App_KernelProdContainer.preload.php')) {
    require dirname(__DIR__).'/var/cache/prod/App_KernelProdContainer.preload.php';
}
