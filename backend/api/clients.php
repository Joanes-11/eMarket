<?php
/**
 * eMarket - API Clients
 *
 *   GET    clients.php            liste
 *   POST   clients.php            création { name, phone, email, address }
 *   PUT    clients.php?id=5       modification
 *   DELETE clients.php?id=5       suppression
 */

require_once __DIR__ . '/../includes/helpers.php';

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

/* --- Lecture : tout utilisateur connecté --- */
if ($method === 'GET') {
    require_auth();
    $rows = $pdo->query('SELECT * FROM clients ORDER BY created_at DESC')->fetchAll();
    $clients = array_map(function ($c) {
        return [
            'id'        => (int) $c['id'],
            'name'      => $c['name'],
            'phone'     => $c['phone'],
            'email'     => $c['email'],
            'address'   => $c['address'],
            'createdAt' => (int) $c['created_at'],
        ];
    }, $rows);
    json_ok($clients);
}

/* --- Écriture : tous les rôles (le caissier peut ajouter un client en caisse) --- */
require_auth(['Administrateur', 'Gérant de stock', 'Caissier']);

switch ($method) {
    case 'POST':
        $data = body();
        $name = trim($data['name'] ?? '');
        if ($name === '') json_error('Le nom du client est obligatoire.');

        $pdo->prepare('INSERT INTO clients (name, phone, email, address, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([
                $name,
                trim($data['phone'] ?? ''),
                trim($data['email'] ?? ''),
                trim($data['address'] ?? ''),
                now_ms(),
            ]);
        log_activity('Création client', 'Client ' . $name);
        json_ok(['id' => (int) $pdo->lastInsertId()], 'Client ajouté.');
        break;

    case 'PUT':
        $id = (int) param('id', 0);
        $stmt = $pdo->prepare('SELECT * FROM clients WHERE id = ?');
        $stmt->execute([$id]);
        $client = $stmt->fetch();
        if (!$client) json_error('Client introuvable.', 404);

        $data = body();
        $name = trim($data['name'] ?? $client['name']);
        if ($name === '') json_error('Le nom du client est obligatoire.');

        $pdo->prepare('UPDATE clients SET name = ?, phone = ?, email = ?, address = ? WHERE id = ?')
            ->execute([
                $name,
                trim($data['phone'] ?? $client['phone']),
                trim($data['email'] ?? $client['email']),
                trim($data['address'] ?? $client['address']),
                $id,
            ]);
        log_activity('Modification client', 'Client ' . $name);
        json_ok([], 'Client modifié.');
        break;

    case 'DELETE':
        $id = (int) param('id', 0);
        $stmt = $pdo->prepare('SELECT * FROM clients WHERE id = ?');
        $stmt->execute([$id]);
        $client = $stmt->fetch();
        if (!$client) json_error('Client introuvable.', 404);

        $used = $pdo->prepare(
            "SELECT (SELECT COUNT(*) FROM quotes WHERE client_name = ?) +
                    (SELECT COUNT(*) FROM invoices WHERE client_name = ?) AS n"
        );
        $used->execute([$client['name'], $client['name']]);
        if ((int) $used->fetch()['n'] > 0) json_error('Ce client possède des documents, il ne peut pas être supprimé.');

        $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
        log_activity('Suppression client', 'Client ' . $client['name']);
        json_ok([], 'Client supprimé.');
        break;

    default:
        json_error('Méthode non autorisée.', 405);
}
