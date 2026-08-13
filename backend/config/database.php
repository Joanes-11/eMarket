<?php
/**
 * eMarket - Configuration de la base de données
 * Modifiez ces constantes selon votre environnement XAMPP.
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'emarket');
define('DB_USER', 'root');
define('DB_PASS', '');

/**
 * Retourne l'instance PDO (connexion unique, pattern singleton).
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }

    return $pdo;
}
