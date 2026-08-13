<?php
/**
 * eMarket - API Authentification
 *
 *   POST ../backend/api/auth.php?action=login   { username, password }
 *   GET  ../backend/api/auth.php?action=me
 *   POST ../backend/api/auth.php?action=logout
 */

require_once __DIR__ . '/../includes/helpers.php';

$action = param('action');

switch ($action) {
    case 'login':
        $data     = body();
        $username = trim($data['username'] ?? '');
        $password = (string) ($data['password'] ?? '');

        if ($username === '' || $password === '') {
            json_error('Veuillez saisir votre nom d\'utilisateur et votre mot de passe.');
        }

        $stmt = db()->prepare(
            'SELECT * FROM users WHERE email = :u OR name = :u2 LIMIT 1'
        );
        $stmt->execute([':u' => $username, ':u2' => $username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            json_error('Identifiants incorrects.', 401);
        }
        if ((int) $user['active'] !== 1) {
            json_error('Ce compte est désactivé. Contactez l\'administrateur.', 403);
        }

        login_user($user);
        log_activity('Connexion', 'Connexion de ' . $user['name'] . ' (' . $user['role'] . ').');

        json_ok([
            'id'    => (int) $user['id'],
            'name'  => $user['name'],
            'email' => $user['email'],
            'role'  => $user['role'],
        ], 'Connexion réussie.');
        break;

    case 'me':
        $user = require_auth();
        json_ok($user);
        break;

    case 'logout':
        $user = current_user();
        if ($user) {
            log_activity('Déconnexion', 'Déconnexion de ' . $user['name'] . '.');
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        json_ok([], 'Déconnexion réussie.');
        break;

    default:
        json_error('Action inconnue.', 400);
}
