<?php
/**
 * eMarket - API Mouvements de stock + sorties validées
 *
 *   GET movements.php                          historique complet (joins produits)
 *   GET movements.php?action=validated-exits   sorties validées facturables
 */

require_once __DIR__ . '/../includes/helpers.php';

require_auth();

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') json_error('Méthode non autorisée.', 405);

$pdo = db();

if (param('action') === 'validated-exits') {
    $rows = $pdo->query(
        'SELECT ve.id, ve.product_id, p.name AS product_name, p.sell_price,
                ve.qty, ve.used, (ve.qty - ve.used) AS remaining,
                ve.reason, ve.created_at
         FROM validated_exits ve
         JOIN products p ON p.id = ve.product_id
         WHERE ve.qty > ve.used
         ORDER BY ve.created_at ASC'
    )->fetchAll();

    $exits = array_map(function ($r) {
        return [
            'id'           => (int) $r['id'],
            'product_id'   => (int) $r['product_id'],
            'product_name' => $r['product_name'],
            'sell_price'   => num_float($r['sell_price']),
            'qty'          => (int) $r['qty'],
            'used'         => (int) $r['used'],
            'remaining'    => (int) $r['remaining'],
            'reason'       => $r['reason'],
            'createdAt'    => (int) $r['created_at'],
        ];
    }, $rows);

    json_ok($exits);
}

$rows = $pdo->query(
    'SELECT m.id, m.type, m.qty, m.reason, m.balance, m.user_id,
            u.name AS user_name, p.id AS product_id, p.name AS product_name,
            m.created_at
     FROM movements m
     JOIN products p ON p.id = m.product_id
     LEFT JOIN users u ON u.id = m.user_id
     ORDER BY m.created_at DESC
     LIMIT 500'
)->fetchAll();

$moves = array_map(function ($r) {
    return [
        'id'           => (int) $r['id'],
        'type'         => $r['type'],
        'qty'          => (int) $r['qty'],
        'reason'       => $r['reason'],
        'balance'      => (int) $r['balance'],
        'user'         => $r['user_name'],
        'product_id'   => (int) $r['product_id'],
        'product_name' => $r['product_name'],
        'createdAt'    => (int) $r['created_at'],
    ];
}, $rows);

json_ok($moves);
