<?php
/**
 * eMarket - Helpers partagés (sessions, réponses JSON, authentification, journal).
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';

/* ---------- Réponses JSON ---------- */

function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(string $msg, int $code = 400): void
{
    json_out(['success' => false, 'message' => $msg], $code);
}

function json_ok($data = [], string $message = 'OK'): void
{
    json_out(['success' => true, 'message' => $message, 'data' => $data]);
}

/* ---------- Corps de requête ---------- */

function body(): array
{
    $raw  = file_get_contents('php://input');
    $data = json_decode((string) $raw, true);
    return is_array($data) ? $data : [];
}

function param(string $key, $default = null)
{
    return $_GET[$key] ?? $default;
}

/* ---------- Utilitaires ---------- */

function now_ms(): int
{
    return (int) round(microtime(true) * 1000);
}

function num_float($v): float
{
    return (float) $v;
}

/**
 * Génère le prochain numéro de document : PREFIX-001, PREFIX-002, ...
 */
function next_num(string $prefix, string $table): string
{
    $pdo    = db();
    $q      = $pdo->prepare(
        "SELECT MAX(CAST(SUBSTRING(num, " . (strlen($prefix) + 2) . ") AS UNSIGNED)) AS m
         FROM `$table` WHERE num LIKE :like"
    );
    $q->execute([':like' => $prefix . '-%']);
    $row  = $q->fetch();
    $next = ((int) ($row['m'] ?? 0)) + 1;
    return $prefix . '-' . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
}

/* ---------- Authentification ---------- */

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_auth($roles = null): array
{
    $user = current_user();
    if (!$user) {
        json_error('Non connecté. Veuillez vous authentifier.', 401);
    }
    if ($roles !== null && !in_array($user['role'], (array) $roles, true)) {
        json_error('Accès refusé pour votre rôle.', 403);
    }
    return $user;
}

function login_user(array $dbUser): void
{
    $_SESSION['user'] = [
        'id'    => (int) $dbUser['id'],
        'name'  => $dbUser['name'],
        'email' => $dbUser['email'],
        'role'  => $dbUser['role'],
    ];
}

/* ---------- Journal d'activité ---------- */

function log_activity(string $action, string $detail): void
{
    $user = current_user();
    db()->prepare(
        'INSERT INTO activity (user_name, action, detail, created_at) VALUES (?, ?, ?, ?)'
    )->execute([
        $user['name'] ?? 'Système',
        $action,
        $detail,
        now_ms(),
    ]);
}
