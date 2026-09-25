<?php

/**
 * Amorçage de PHPUnit, déclaré par `bootstrap="tests/bootstrap.php"` dans
 * phpunit.xml.dist (qui force aussi APP_ENV=test et KERNEL_CLASS).
 *
 * Charge l'autoloader Composer puis les variables d'environnement. Sans
 * config/bootstrap.php (absent de ce projet), Dotenv::bootEnv() lit .env puis
 * .env.test (et .env.test.local s'il existe) ; .env.local est ignoré en
 * environnement de test, les secrets locaux ne fuient donc pas dans les tests.
 */

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (file_exists(dirname(__DIR__).'/config/bootstrap.php')) {
    require dirname(__DIR__).'/config/bootstrap.php';
} elseif (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

// En mode debug, les fichiers créés pendant les tests (cache, logs) restent
// modifiables par tous les utilisateurs (recette Symfony standard).
if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
