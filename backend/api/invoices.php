<?php
/**
 * eMarket - API Factures
 *
 *   GET  invoices.php                    liste
 *   GET  invoices.php?id=5               détail + articles + paiements
 *   POST invoices.php                    création
 *        { client_name, items: [{validated_exit_id, product_id, name, qty, price}] }
 *   POST invoices.php?action=pay         encaissement { invoice_id, amount, method }
 *   DELETE invoices.php?id=5             suppression (rend les sorties validées)
 */

require_once __DIR__ . '/../includes/helpers.php';

require_auth();

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

function map_invoice(array $inv): array
{
    return [
        'id'          => (int) $inv['id'],
        'num'         => $inv['num'],
        'client_name' => $inv['client_name'],
        'date'        => (int) $inv['date'],
        'total'       => num_float($inv['total']),
        'paid'        => num_float($inv['paid']),
        'balance'     => num_float($inv['total'] - $inv['paid']),
        'source'      => $inv['source'],
        'status'      => (float) $inv['paid'] >= (float) $inv['total'] ? 'payee' : 'en attente',
        'createdAt'   => (int) $inv['created_at'],
    ];
}

if ($method === 'GET') {
    if (param('id')) {
        $id   = (int) param('id');
        $stmt = $pdo->prepare('SELECT * FROM invoices WHERE id = ?');
        $stmt->execute([$id]);
        $inv = $stmt->fetch();
        if (!$inv) json_error('Facture introuvable.', 404);

        $items = $pdo->prepare(
            'SELECT ii.id, ii.product_id, ii.validated_exit_id, ii.name, ii.qty, ii.price
             FROM invoice_items ii WHERE ii.invoice_id = ? ORDER BY ii.id ASC'
        );
        $items->execute([$id]);
        $inv['items'] = array_map(function ($i) {
            return [
                'id'                => (int) $i['id'],
                'product_id'        => (int) $i['product_id'],
                'validated_exit_id' => $i['validated_exit_id'] !== null ? (int) $i['validated_exit_id'] : null,
                'name'              => $i['name'],
                'qty'               => (int) $i['qty'],
                'price'             => num_float($i['price']),
            ];
        }, $items->fetchAll());

        $pays = $pdo->prepare('SELECT * FROM payments WHERE invoice_id = ? ORDER BY created_at DESC');
        $pays->execute([$id]);
        $inv['payments'] = array_map(function ($p) {
            return [
                'id'         => (int) $p['id'],
                'amount'     => num_float($p['amount']),
                'method'     => $p['method'],
                'createdAt'  => (int) $p['created_at'],
            ];
        }, $pays->fetchAll());

        json_ok(map_invoice($inv));
    }

    $rows = $pdo->query('SELECT * FROM invoices ORDER BY created_at DESC')->fetchAll();
    json_ok(array_map('map_invoice', $rows));
}

require_auth(['Administrateur', 'Caissier']);

switch ($method) {
    case 'POST':
        if (param('action') === 'pay') {
            $data      = body();
            $id        = (int) ($data['invoice_id'] ?? 0);
            $amount    = (float) ($data['amount'] ?? 0);
            $method    = trim($data['method'] ?? 'Especes');

            $stmt = $pdo->prepare('SELECT * FROM invoices WHERE id = ?');
            $stmt->execute([$id]);
            $inv = $stmt->fetch();
            if (!$inv) json_error('Facture introuvable.', 404);
            if ($amount <= 0) json_error('Le montant doit être supérieur à 0.');

            $balance = (float) $inv['total'] - (float) $inv['paid'];
            if ($amount > $balance + 0.0001) {
                json_error('Montant supérieur au solde restant (' . number_format($balance, 2, ',', ' ') . ').');
            }

            $newPaid = (float) $inv['paid'] + $amount;
            $pdo->beginTransaction();
            try {
                $pdo->prepare('INSERT INTO payments (invoice_id, client_name, amount, method, created_at) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$id, $inv['client_name'], $amount, $method, now_ms()]);
                $pdo->prepare('UPDATE invoices SET paid = ? WHERE id = ?')->execute([$newPaid, $id]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                json_error('Erreur lors de l\'encaissement : ' . $e->getMessage(), 500);
            }

            log_activity('Encaissement', 'Paiement de ' . number_format($amount, 0, ',', ' ') . ' sur ' . $inv['num']);
            json_ok(['paid' => $newPaid], 'Paiement enregistré sur ' . $inv['num'] . '.');
        }

        // Création d'une facture
        $data       = body();
        $clientName = trim($data['client_name'] ?? '');
        $items      = $data['items'] ?? [];

        if ($clientName === '') json_error('Le nom du client est obligatoire.');
        if (!is_array($items) || count($items) === 0) json_error('Ajoutez au moins un article à la facture.');
        foreach ($items as $item) {
            if ((int) ($item['qty'] ?? 0) <= 0) json_error('Quantité invalide pour un article.');
        }

        $pdo->beginTransaction();
        try {
            // Consommer les sorties validées
            $updExit = $pdo->prepare('UPDATE validated_exits SET used = used + ? WHERE id = ? AND used + ? <= qty');
            foreach ($items as $item) {
                if (empty($item['validated_exit_id'])) {
                    json_error('Chaque article doit être lié à une sortie validée.', 400);
                }
                $ok = $updExit->execute([
                    (int) $item['qty'],
                    (int) $item['validated_exit_id'],
                    (int) $item['qty'],
                ]);
                if ($ok && $updExit->rowCount() === 0) {
                    throw new RuntimeException('Quantité disponible insuffisante pour la sortie liée.');
                }
            }

            $num   = next_num('FACT', 'invoices');
            $total = array_sum(array_map(function ($i) {
                return (float) $i['price'] * (int) $i['qty'];
            }, $items));

            $pdo->prepare(
                'INSERT INTO invoices (num, client_name, date, total, paid, source, created_at)
                 VALUES (?, ?, ?, ?, 0, ?, ?)'
            )->execute([$num, $clientName, now_ms(), $total, 'facture', now_ms()]);
            $invoiceId = (int) $pdo->lastInsertId();

            $stmtIns = $pdo->prepare(
                'INSERT INTO invoice_items (invoice_id, product_id, validated_exit_id, name, qty, price)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            foreach ($items as $item) {
                $stmtIns->execute([
                    $invoiceId,
                    (int) ($item['product_id'] ?? 0),
                    (int) ($item['validated_exit_id'] ?? 0) ?: null,
                    trim($item['name'] ?? ''),
                    (int) $item['qty'],
                    (float) $item['price'],
                ]);
            }
            $pdo->commit();
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            json_error($e->getMessage(), 400);
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_error('Erreur lors de la création de la facture : ' . $e->getMessage(), 500);
        }

        log_activity('Création facture', 'Facture ' . $num . ' pour ' . $clientName);
        json_ok(['id' => $invoiceId, 'num' => $num], 'Facture ' . $num . ' enregistrée.');
        break;

    case 'DELETE':
        $id = (int) param('id', 0);
        $stmt = $pdo->prepare('SELECT * FROM invoices WHERE id = ?');
        $stmt->execute([$id]);
        $inv = $stmt->fetch();
        if (!$inv) json_error('Facture introuvable.', 404);

        $pdo->beginTransaction();
        try {
            $items = $pdo->prepare('SELECT * FROM invoice_items WHERE invoice_id = ?');
            $items->execute([$id]);
            $restore = $pdo->prepare('UPDATE validated_exits SET used = GREATEST(used - ?, 0) WHERE id = ?');
            foreach ($items->fetchAll() as $item) {
                if ($item['validated_exit_id']) {
                    $restore->execute([(int) $item['qty'], (int) $item['validated_exit_id']]);
                }
            }
            $pdo->prepare('DELETE FROM payments WHERE invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_error('Impossible de supprimer cette facture.', 500);
        }
        log_activity('Suppression facture', 'Facture ' . $inv['num']);
        json_ok([], 'Facture supprimée (sorties validées restituées).');
        break;

    default:
        json_error('Méthode non autorisée.', 405);
}
