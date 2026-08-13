<?php
/**
 * eMarket - API Produits (catalogue partagé : admin + stock + caisse)
 *
 *   GET    products.php                  liste
 *   GET    products.php?action=invoiceable  produits facturables (sorties validées restantes)
 *   POST   products.php                  création   { name, ref, cat, unit, min, buy, sell, qty }
 *   PUT    products.php?id=5             modification
 *   DELETE products.php?id=5             suppression
 *   POST   products.php?action=entry     entrée de stock  { id, qty, reason }
 *   POST   products.php?action=exit      sortie de stock  { id, qty, reason }
 */

require_once __DIR__ . '/../includes/helpers.php';

$user   = require_auth();
$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

function map_product(array $p): array
{
    return [
        'id'        => (int) $p['id'],
        'name'      => $p['name'],
        'ref'       => $p['ref'],
        'cat'       => $p['category_name'],
        'unit'      => $p['unit'],
        'min'       => (int) $p['min_qty'],
        'buy'       => num_float($p['buy_price']),
        'sell'      => num_float($p['sell_price']),
        'qty'       => (int) $p['qty'],
        'createdAt' => (int) $p['created_at'],
    ];
}

function fetch_product(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM products WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/* --- Lecture (tous les rôles connectés) --- */
if ($method === 'GET') {
    if (param('action') === 'invoiceable') {
        $rows = $pdo->query(
            'SELECT p.*, 
                    COALESCE(SUM(ve.qty - ve.used), 0) AS remaining
             FROM products p
             LEFT JOIN validated_exits ve ON ve.product_id = p.id
             GROUP BY p.id
             HAVING remaining > 0
             ORDER BY p.created_at DESC'
        )->fetchAll();

        $products = array_map(function ($r) {
            $p = map_product($r);
            $p['remaining'] = (int) $r['remaining'];
            return $p;
        }, $rows);

        json_ok($products);
    }

    $rows = $pdo->query('SELECT * FROM products ORDER BY created_at DESC')->fetchAll();
    json_ok(array_map('map_product', $rows));
}

/* --- Écriture (Administrateur ou Gérant de stock) --- */
require_auth(['Administrateur', 'Gérant de stock']);

switch ($method) {
    case 'POST':
        if (param('action') === 'entry' || param('action') === 'exit') {
            $data   = body();
            $id     = (int) ($data['id'] ?? 0);
            $qty    = (int) ($data['qty'] ?? 0);
            $reason = trim($data['reason'] ?? '');

            $product = fetch_product($id);
            if (!$product) json_error('Produit introuvable.', 404);
            if ($qty <= 0) json_error('La quantité doit être supérieure à 0.');
            if ($reason === '') json_error('Précisez le motif du mouvement.');

            $type = param('action') === 'entry' ? 'in' : 'out';
            if ($type === 'out' && $qty > (int) $product['qty']) {
                json_error('Quantité insuffisante : il ne reste que ' . $product['qty'] . '.');
            }

            $newQty = $type === 'in'
                ? (int) $product['qty'] + $qty
                : (int) $product['qty'] - $qty;

            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE products SET qty = ? WHERE id = ?')->execute([$newQty, $id]);
                $pdo->prepare(
                    'INSERT INTO movements (product_id, type, qty, reason, balance, user_id, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([$id, $type, $qty, $reason, $newQty, (int) $user['id'], now_ms()]);
                $moveId = (int) $pdo->lastInsertId();

                if ($type === 'out') {
                    $pdo->prepare(
                        'INSERT INTO validated_exits (move_id, product_id, qty, used, reason, created_at)
                         VALUES (?, ?, ?, 0, ?, ?)'
                    )->execute([$moveId, $id, $qty, $reason, now_ms()]);
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                json_error('Erreur lors du mouvement : ' . $e->getMessage(), 500);
            }

            log_activity(
                $type === 'in' ? 'Entrée de stock' : 'Sortie validée',
                ($type === 'in' ? '+' : '-') . $qty . ' pour "' . $product['name'] . '" (' . $reason . ')'
            );
            json_ok(['qty' => $newQty], $type === 'in' ? 'Entrée enregistrée.' : 'Sortie validée et facturable chez le caissier.');
        }

        // Création
        $data  = body();
        $name  = trim($data['name'] ?? '');
        $ref   = trim($data['ref'] ?? '');
        $cat   = trim($data['cat'] ?? '');
        $unit  = trim($data['unit'] ?? 'unite');
        $min   = max(0, (int) ($data['min'] ?? 0));
        $buy   = max(0, (float) ($data['buy'] ?? 0));
        $sell  = max(0, (float) ($data['sell'] ?? 0));
        $qty   = max(0, (int) ($data['qty'] ?? 0));

        if ($name === '') json_error('Le nom du produit est obligatoire.');

        $pdo->prepare(
            'INSERT INTO products (name, ref, category_name, unit, min_qty, buy_price, sell_price, qty, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$name, $ref, $cat, $unit, $min, $buy, $sell, $qty, now_ms()]);
        $id = (int) $pdo->lastInsertId();

        if ($qty > 0) {
            $pdo->prepare(
                'INSERT INTO movements (product_id, type, qty, reason, balance, user_id, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([$id, 'in', $qty, 'Stock initial', $qty, (int) $user['id'], now_ms()]);
        }

        log_activity('Création produit', 'Produit ' . $name);
        json_ok(['id' => $id], 'Produit ajouté au catalogue.');
        break;

    case 'PUT':
        $id = (int) param('id', 0);
        $product = fetch_product($id);
        if (!$product) json_error('Produit introuvable.', 404);

        $data = body();
        $name = trim($data['name'] ?? $product['name']);
        if ($name === '') json_error('Le nom du produit est obligatoire.');

        $pdo->prepare(
            'UPDATE products SET name = ?, ref = ?, category_name = ?, unit = ?, min_qty = ?, buy_price = ?, sell_price = ? WHERE id = ?'
        )->execute([
            $name,
            $data['ref'] ?? $product['ref'],
            $data['cat'] ?? $product['category_name'],
            $data['unit'] ?? $product['unit'],
            max(0, (int) ($data['min'] ?? $product['min_qty'])),
            max(0, (float) ($data['buy'] ?? $product['buy_price'])),
            max(0, (float) ($data['sell'] ?? $product['sell_price'])),
            $id,
        ]);
        log_activity('Modification produit', 'Produit ' . $name);
        json_ok([], 'Produit modifié avec succès.');
        break;

    case 'DELETE':
        $id = (int) param('id', 0);
        $product = fetch_product($id);
        if (!$product) json_error('Produit introuvable.', 404);

        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM quote_items WHERE product_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM invoice_items WHERE product_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM validated_exits WHERE product_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM movements WHERE product_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_error('Impossible de supprimer ce produit.', 500);
        }
        log_activity('Suppression produit', 'Produit ' . $product['name']);
        json_ok([], 'Produit supprimé.');
        break;

    default:
        json_error('Méthode non autorisée.', 405);
}
