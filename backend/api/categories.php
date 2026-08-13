<?php
/**
 * eMarket - API Catégories
 *
 *   GET    categories.php            liste
 *   POST   categories.php            création { name, description }
 *   PUT    categories.php?id=5       modification
 *   DELETE categories.php?id=5       suppression
 */

require_once __DIR__ . '/../includes/helpers.php';

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

/* --- Lecture : tout utilisateur connecté --- */
if ($method === 'GET') {
    require_auth();
    $rows = $pdo->query('SELECT * FROM categories ORDER BY name ASC')->fetchAll();
    $cats = array_map(function ($c) {
        return [
            'id'          => (int) $c['id'],
            'name'        => $c['name'],
            'description' => $c['description'],
            'createdAt'   => (int) $c['created_at'],
        ];
    }, $rows);
    json_ok($cats);
}

/* --- Écriture : Administrateur ou Gérant de stock --- */
require_auth(['Administrateur', 'Gérant de stock']);

switch ($method) {
    case 'POST':
        $data = body();
        $name = trim($data['name'] ?? '');
        $desc = trim($data['description'] ?? '');
        if ($name === '') json_error('Le nom de la catégorie est obligatoire.');

        $exists = $pdo->prepare('SELECT id FROM categories WHERE name = ?');
        $exists->execute([$name]);
        if ($exists->fetch()) json_error('Cette catégorie existe déjà.');

        $pdo->prepare('INSERT INTO categories (name, description, created_at) VALUES (?, ?, ?)')
            ->execute([$name, $desc, now_ms()]);
        $id = (int) $pdo->lastInsertId();
        log_activity('Création catégorie', 'Catégorie ' . $name);
        json_ok(['id' => $id], 'Catégorie créée.');
        break;

    case 'PUT':
        $id   = (int) param('id', 0);
        $stmt = $pdo->prepare('SELECT * FROM categories WHERE id = ?');
        $stmt->execute([$id]);
        $cat = $stmt->fetch();
        if (!$cat) json_error('Catégorie introuvable.', 404);

        $data = body();
        $name = trim($data['name'] ?? $cat['name']);
        $desc = trim($data['description'] ?? $cat['description']);
        if ($name === '') json_error('Le nom de la catégorie est obligatoire.');

        $pdo->prepare('UPDATE categories SET name = ?, description = ? WHERE id = ?')
            ->execute([$name, $desc, $id]);
        log_activity('Modification catégorie', 'Catégorie ' . $name);
        json_ok([], 'Catégorie modifiée.');
        break;

    case 'DELETE':
        $id   = (int) param('id', 0);
        $stmt = $pdo->prepare('SELECT * FROM categories WHERE id = ?');
        $stmt->execute([$id]);
        $cat = $stmt->fetch();
        if (!$cat) json_error('Catégorie introuvable.', 404);

        $used = $pdo->prepare('SELECT COUNT(*) AS n FROM products WHERE category_name = ?');
        $used->execute([$cat['name']]);
        if ((int) $used->fetch()['n'] > 0) json_error('Des produits utilisent encore cette catégorie.');

        $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
        log_activity('Suppression catégorie', 'Catégorie ' . $cat['name']);
        json_ok([], 'Catégorie supprimée.');
        break;

    default:
        json_error('Méthode non autorisée.', 405);
}
