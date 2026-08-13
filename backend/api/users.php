<?php
/**
 * eMarket - API Utilisateurs (module Administration, rôle Administrateur)
 *
 *   GET    users.php                 liste des utilisateurs
 *   POST   users.php                 création      { name, email, role, password? }
 *   PUT    users.php?id=5            modification  { name, email, role }
 *   DELETE users.php?id=5            suppression
 *   POST   users.php?action=toggle   activation/désactivation  { id }
 *   POST   users.php?action=resetpwd réinitialisation mdp      { id, password? }
 */

require_once __DIR__ . '/../includes/helpers.php';

require_auth(['Administrateur']);

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

function map_user(array $u): array
{
    return [
        'id'            => (int) $u['id'],
        'name'          => $u['name'],
        'email'         => $u['email'],
        'role'          => $u['role'],
        'active'        => (int) $u['active'] === 1,
        'hasPassword'   => true,
        'createdAt'     => (int) $u['created_at'],
    ];
}

switch ($method) {
    case 'GET':
        $rows = $pdo->query('SELECT * FROM users ORDER BY created_at DESC')->fetchAll();
        json_ok(array_map('map_user', $rows));
        break;

    case 'POST':
        if (param('action') === 'toggle') {
            $data = body();
            $id   = (int) ($data['id'] ?? 0);
            $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
            $stmt->execute([$id]);
            $user = $stmt->fetch();
            if (!$user) json_error('Utilisateur introuvable.', 404);

            $newActive = (int) $user['active'] === 1 ? 0 : 1;
            $pdo->prepare('UPDATE users SET active = ? WHERE id = ?')->execute([$newActive, $id]);
            log_activity($newActive ? 'Activation compte' : 'Désactivation compte', 'Compte de ' . $user['name']);
            json_ok(['active' => $newActive === 1], $newActive ? 'Compte activé.' : 'Compte désactivé.');
        }

        if (param('action') === 'resetpwd') {
            $data     = body();
            $id       = (int) ($data['id'] ?? 0);
            $password = (string) ($data['password'] ?? '123456');
            if (strlen($password) < 4) json_error('Le mot de passe doit contenir au moins 4 caractères.');
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
            log_activity('Réinitialisation mot de passe', 'Compte #' . $id);
            json_ok([], 'Mot de passe réinitialisé.');
        }

        // Création
        $data     = body();
        $name     = trim($data['name'] ?? '');
        $email    = trim($data['email'] ?? '');
        $role     = $data['role'] ?? '';
        $password = (string) ($data['password'] ?? '123456');

        if ($name === '' || $email === '' || $role === '') json_error('Tous les champs obligatoires doivent être remplis.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_error('Adresse email invalide.');
        if (!in_array($role, ['Administrateur', 'Gérant de stock', 'Caissier'], true)) json_error('Rôle invalide.');
        if (strlen($password) < 4) json_error('Le mot de passe doit contenir au moins 4 caractères.');

        $exists = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $exists->execute([$email]);
        if ($exists->fetch()) json_error('Un utilisateur avec cet email existe déjà.');

        $pdo->prepare('INSERT INTO users (name, email, password_hash, role, active, created_at) VALUES (?, ?, ?, ?, 1, ?)')
            ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, now_ms()]);
        $id = (int) $pdo->lastInsertId();
        log_activity('Création utilisateur', 'Compte de ' . $name);

        json_ok(['id' => $id], 'Utilisateur créé avec succès.');
        break;

    case 'PUT':
        $id   = (int) param('id', 0);
        $data = body();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        if (!$user) json_error('Utilisateur introuvable.', 404);

        $name  = trim($data['name'] ?? $user['name']);
        $email = trim($data['email'] ?? $user['email']);
        $role  = $data['role'] ?? $user['role'];
        if ($name === '' || $email === '' || $role === '') json_error('Tous les champs obligatoires doivent être remplis.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_error('Adresse email invalide.');
        if (!in_array($role, ['Administrateur', 'Gérant de stock', 'Caissier'], true)) json_error('Rôle invalide.');

        $exists = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
        $exists->execute([$email, $id]);
        if ($exists->fetch()) json_error('Un autre utilisateur utilise cet email.');

        $pdo->prepare('UPDATE users SET name = ?, email = ?, role = ? WHERE id = ?')
            ->execute([$name, $email, $role, $id]);
        log_activity('Modification utilisateur', 'Compte de ' . $name);
        json_ok([], 'Utilisateur modifié avec succès.');
        break;

    case 'DELETE':
        $id = (int) param('id', 0);
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        if (!$user) json_error('Utilisateur introuvable.', 404);
        if ((int) $user['id'] === (int) current_user()['id']) json_error('Vous ne pouvez pas supprimer votre propre compte.', 400);

        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        log_activity('Suppression utilisateur', 'Compte de ' . $user['name']);
        json_ok([], 'Utilisateur supprimé.');
        break;

    default:
        json_error('Méthode non autorisée.', 405);
}
