<?php
/**
 * eMarket - Installation / initialisation
 * Crée la base, les tables et injecte les données de démonstration.
 *
 * Accès : http://localhost/eMarket/backend/setup.php  (une seule fois)
 */

require_once __DIR__ . '/config/database.php';

header('Content-Type: application/json; charset=utf-8');

$sqlFile = __DIR__ . '/sql/schema.sql';

if (!file_exists($sqlFile)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Fichier schema.sql introuvable.']);
    exit;
}

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $sql = file_get_contents($sqlFile);
    $pdo->exec($sql);

    // Connexion avec la base sélectionnée pour vérifier le seed
    $pdo->exec('USE ' . DB_NAME);
    $count = (int) $pdo->query('SELECT COUNT(*) AS n FROM users')->fetch()['n'];

    echo json_encode([
        'success' => true,
        'message' => 'Base "' . DB_NAME . '" initialisée avec succès.',
        'users'   => $count,
        'seed'    => $count >= 3,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Erreur : ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
