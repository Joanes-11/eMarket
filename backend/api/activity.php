<?php
/**
 * eMarket - API Journal d'activité
 *
 *   GET activity.php  dernières actions (limit=50 par défaut)
 */

require_once __DIR__ . '/../includes/helpers.php';

require_auth(['Administrateur', 'Gérant de stock']);

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') json_error('Méthode non autorisée.', 405);

$limit = max(1, min(200, (int) param('limit', 50)));

$stmt = db()->prepare('SELECT * FROM activity ORDER BY created_at DESC LIMIT ?');
$stmt->execute([$limit]);

$rows = array_map(function ($a) {
    return [
        'id'        => (int) $a['id'],
        'user_name' => $a['user_name'],
        'action'    => $a['action'],
        'detail'    => $a['detail'],
        'createdAt' => (int) $a['created_at'],
    ];
}, $stmt->fetchAll());

json_ok($rows);
