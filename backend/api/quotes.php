<?php
/**
 * eMarket - API Devis
 *
 *   GET  quotes.php                liste (avec total)
 *   GET  quotes.php?id=5           détail + articles
 *   POST quotes.php                création  { client_name, items: [{product_id, qty, price}], status? }
 *   PUT  quotes.php?id=5           modification
 *   DELETE quotes.php?id=5         suppression
 *   POST quotes.php?action=convert id de devis -> facture
 */

require_once __DIR__ . '/../includes/helpers.php';

require_auth();

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

function map_quote(array $q): array
{
    return [
        'id'          => (int) $q['id'],
        'num'         => $q['num'],
        'client_name' => $q['client_name'],
        'date'        => (int) $q['date'],
        'status'      => $q['status'],
        'total'       => num_float($q['total']),
        'createdAt'   => (int) $q['created_at'],
    ];
}

if ($method === 'GET') {
    if (param('id')) {
        $id   = (int) param('id');
        $stmt = $pdo->prepare('SELECT * FROM quotes WHERE id = ?');
        $stmt->execute([$id]);
        $quote = $stmt->fetch();
        if (!$quote) json_error('Devis introuvable.', 404);

        $items = $pdo->prepare(
            'SELECT qi.id, qi.product_id, qi.name, qi.qty, qi.price
             FROM quote_items qi WHERE qi.quote_id = ? ORDER BY qi.id ASC'
        );
        $items->execute([$id]);
        $quote['items'] = array_map(function ($i) {
            return [
                'id'         => (int) $i['id'],
                'product_id' => (int) $i['product_id'],
                'name'       => $i['name'],
                'qty'        => (int) $i['qty'],
                'price'      => num_float($i['price']),
            ];
        }, $items->fetchAll());

        json_ok(map_quote($quote));
    }

    $rows = $pdo->query('SELECT * FROM quotes ORDER BY created_at DESC')->fetchAll();
    json_ok(array_map('map_quote', $rows));
}

require_auth(['Administrateur', 'Caissier']);

switch ($method) {
    case 'POST':
        if (param('action') === 'convert') {
            $id   = (int) (body()['id'] ?? 0);
            $stmt = $pdo->prepare('SELECT * FROM quotes WHERE id = ?');
            $stmt->execute([$id]);
            $quote = $stmt->fetch();
            if (!$quote) json_error('Devis introuvable.', 404);
            if ($quote['status'] === 'converti') json_error('Ce devis a déjà été converti.');

            $items = $pdo->prepare('SELECT * FROM quote_items WHERE quote_id = ?');
            $items->execute([$id]);
            $quoteItems = $items->fetchAll();

            $pdo->beginTransaction();
            try {
                $invNum = next_num('FACT', 'invoices');
                $pdo->prepare(
                    'INSERT INTO invoices (num, client_name, date, total, paid, source, created_at)
                     VALUES (?, ?, ?, ?, 0, ?, ?)'
                )->execute([$invNum, $quote['client_name'], now_ms(), $quote['total'], 'devis', now_ms()]);
                $invoiceId = (int) $pdo->lastInsertId();

                $stmtIns = $pdo->prepare(
                    'INSERT INTO invoice_items (invoice_id, product_id, name, qty, price) VALUES (?, ?, ?, ?, ?)'
                );
                foreach ($quoteItems as $item) {
                    $stmtIns->execute([$invoiceId, $item['product_id'], $item['name'], $item['qty'], $item['price']]);
                }

                $pdo->prepare('UPDATE quotes SET status = ? WHERE id = ?')->execute(['converti', $id]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                json_error('Erreur lors de la conversion : ' . $e->getMessage(), 500);
            }

            log_activity('Conversion devis', 'Devis ' . $quote['num'] . ' converti en ' . $invNum);
            json_ok(['invoice_id' => $invoiceId], 'Devis converti en facture ' . $invNum . '.');
        }

        // Création
        $data       = body();
        $clientName = trim($data['client_name'] ?? '');
        $items      = $data['items'] ?? [];
        $status     = $data['status'] ?? 'envoye';

        if ($clientName === '') json_error('Le nom du client est obligatoire.');
        if (!is_array($items) || count($items) === 0) json_error('Ajoutez au moins un article au devis.');
        foreach ($items as $item) {
            if ((int) ($item['qty'] ?? 0) <= 0) json_error('Quantité invalide pour un article.');
        }

        $total = array_sum(array_map(function ($i) {
            return (float) $i['price'] * (int) $i['qty'];
        }, $items));

        $pdo->beginTransaction();
        try {
            $num = next_num('DEV', 'quotes');
            $pdo->prepare(
                'INSERT INTO quotes (num, client_name, date, status, total, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([$num, $clientName, now_ms(), $status, $total, now_ms()]);
            $quoteId = (int) $pdo->lastInsertId();

            $stmtIns = $pdo->prepare(
                'INSERT INTO quote_items (quote_id, product_id, name, qty, price) VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($items as $item) {
                $stmtIns->execute([
                    $quoteId,
                    (int) ($item['product_id'] ?? 0),
                    trim($item['name'] ?? ''),
                    (int) $item['qty'],
                    (float) $item['price'],
                ]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_error('Erreur lors de l\'enregistrement du devis : ' . $e->getMessage(), 500);
        }

        log_activity('Création devis', 'Devis ' . $num . ' pour ' . $clientName);
        json_ok(['id' => $quoteId, 'num' => $num], 'Devis enregistré.');
        break;

    case 'PUT':
        $id = (int) param('id', 0);
        $stmt = $pdo->prepare('SELECT * FROM quotes WHERE id = ?');
        $stmt->execute([$id]);
        $quote = $stmt->fetch();
        if (!$quote) json_error('Devis introuvable.', 404);

        $data       = body();
        $clientName = trim($data['client_name'] ?? $quote['client_name']);
        $status     = $data['status'] ?? $quote['status'];
        $items      = $data['items'] ?? null;

        $total = (float) $quote['total'];
        if (is_array($items)) {
            foreach ($items as $item) {
                if ((int) ($item['qty'] ?? 0) <= 0) json_error('Quantité invalide pour un article.');
            }
            $total = array_sum(array_map(function ($i) {
                return (float) $i['price'] * (int) $i['qty'];
            }, $items));
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE quotes SET client_name = ?, status = ?, total = ? WHERE id = ?')
                ->execute([$clientName, $status, $total, $id]);
            if (is_array($items)) {
                $pdo->prepare('DELETE FROM quote_items WHERE quote_id = ?')->execute([$id]);
                $stmtIns = $pdo->prepare(
                    'INSERT INTO quote_items (quote_id, product_id, name, qty, price) VALUES (?, ?, ?, ?, ?)'
                );
                foreach ($items as $item) {
                    $stmtIns->execute([
                        $id,
                        (int) ($item['product_id'] ?? 0),
                        trim($item['name'] ?? ''),
                        (int) $item['qty'],
                        (float) $item['price'],
                    ]);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_error('Erreur lors de la mise à jour : ' . $e->getMessage(), 500);
        }

        log_activity('Modification devis', 'Devis ' . $quote['num']);
        json_ok([], 'Devis modifié.');
        break;

    case 'DELETE':
        $id = (int) param('id', 0);
        $stmt = $pdo->prepare('SELECT * FROM quotes WHERE id = ?');
        $stmt->execute([$id]);
        $quote = $stmt->fetch();
        if (!$quote) json_error('Devis introuvable.', 404);

        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM quote_items WHERE quote_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM quotes WHERE id = ?')->execute([$id]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_error('Impossible de supprimer ce devis.', 500);
        }
        log_activity('Suppression devis', 'Devis ' . $quote['num']);
        json_ok([], 'Devis supprimé.');
        break;

    default:
        json_error('Méthode non autorisée.', 405);
}
