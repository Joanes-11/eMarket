<?php
/**
 * eMarket - API Paiements
 *
 *   GET payments.php            liste des paiements (avec num facture)
 *   POST payments.php           encaissement { invoice_id, amount, method }
 */

require_once __DIR__ . '/../includes/helpers.php';

require_auth();

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

if ($method === 'GET') {
    $rows = $pdo->query(
        'SELECT p.id, p.invoice_id, i.num AS invoice_num, p.client_name, p.amount, p.method, p.created_at
         FROM payments p
         JOIN invoices i ON i.id = p.invoice_id
         ORDER BY p.created_at DESC
         LIMIT 500'
    )->fetchAll();

    $payments = array_map(function ($p) {
        return [
            'id'           => (int) $p['id'],
            'invoice_id'   => (int) $p['invoice_id'],
            'invoice_num'  => $p['invoice_num'],
            'client_name'  => $p['client_name'],
            'amount'       => num_float($p['amount']),
            'method'       => $p['method'],
            'createdAt'    => (int) $p['created_at'],
        ];
    }, $rows);

    json_ok($payments);
}

require_auth(['Administrateur', 'Caissier']);

if ($method === 'POST') {
    $data      = body();
    $invoiceId = (int) ($data['invoice_id'] ?? 0);
    $amount    = (float) ($data['amount'] ?? 0);
    $method    = trim($data['method'] ?? 'Especes');

    $stmt = $pdo->prepare('SELECT * FROM invoices WHERE id = ?');
    $stmt->execute([$invoiceId]);
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
            ->execute([$invoiceId, $inv['client_name'], $amount, $method, now_ms()]);
        $pdo->prepare('UPDATE invoices SET paid = ? WHERE id = ?')->execute([$newPaid, $invoiceId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_error('Erreur lors de l\'encaissement : ' . $e->getMessage(), 500);
    }

    log_activity('Encaissement', 'Paiement de ' . number_format($amount, 0, ',', ' ') . ' sur ' . $inv['num']);
    json_ok(['paid' => $newPaid], 'Paiement enregistré sur ' . $inv['num'] . '.');
}

json_error('Méthode non autorisée.', 405);
