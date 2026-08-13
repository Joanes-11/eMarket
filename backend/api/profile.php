<?php
/**
 * eMarket - API Profil
 *
 *   GET  profile.php             infos du compte connecté
 *   POST profile.php             mise à jour { name, email }
 *   POST profile.php?action=pwd  changement de mot de passe { old_password, new_password }
 */

require_once __DIR__ . '/../includes/helpers.php';

$user   = require_auth();
$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

if ($method === 'GET') {
    $stmt = $pdo->prepare('SELECT id, name, email, role, created_at FROM users WHERE id = ?');
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch();

    json_ok([
        'id'        => (int) $row['id'],
        'name'      => $row['name'],
        'email'     => $row['email'],
        'role'      => $row['role'],
        'createdAt' => (int) $row['created_at'],
    ]);
}

if ($method === 'POST') {
    $data = body();

    if (param('action') === 'pwd') {
        $old = (string) ($data['old_password'] ?? '');
        $new = (string) ($data['new_password'] ?? '');

        if (strlen($new) < 4) json_error('Le nouveau mot de passe doit contenir au moins 4 caractères.');

        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$user['id']]);
        $row = $stmt->fetch();

        if (!password_verify($old, $row['password_hash'])) {
            json_error('Ancien mot de passe incorrect.', 401);
        }

        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
        log_activity('Changement mot de passe', 'Compte de ' . $user['name']);
        json_ok([], 'Mot de passe modifié avec succès.');
    }

    // Mise à jour nom / email
    $name  = trim($data['name'] ?? '');
    $email = trim($data['email'] ?? '');
    if ($name === '' || $email === '') json_error('Nom et email sont obligatoires.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_error('Adresse email invalide.');

    $exists = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
    $exists->execute([$email, $user['id']]);
    if ($exists->fetch()) json_error('Un autre utilisateur utilise cet email.');

    $pdo->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?')
        ->execute([$name, $email, $user['id']]);
    login_user($pdo->query('SELECT * FROM users WHERE id = ' . (int) $user['id'])->fetch());
    log_activity('Modification profil', 'Compte de ' . $name);

    json_ok(current_user(), 'Profil mis à jour.');
}

json_error('Méthode non autorisée.', 405);
