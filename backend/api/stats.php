<?php
/**
 * eMarket - API Statistiques du tableau de bord
 *
 *   GET stats.php  vue d'ensemble
 */

require_once __DIR__ . '/../includes/helpers.php';

require_auth();

$pdo  = db();
$user = current_user();

/* Cartes principales */
$countProducts = (int) $pdo->query('SELECT COUNT(*) AS n FROM products')->fetch()['n'];
$stockValue    = num_float($pdo->query('SELECT COALESCE(SUM(buy_price * qty), 0) AS v FROM products')->fetch()['v']);
$lowStock      = $pdo->query('SELECT * FROM products WHERE qty <= min_qty ORDER BY qty ASC')->fetchAll();
$invoiceable   = (int) $pdo->query('SELECT COUNT(*) AS n FROM validated_exits WHERE qty > used')->fetch()['n'];

$countClients = (int) $pdo->query('SELECT COUNT(*) AS n FROM clients')->fetch()['n'];
$countInvoices = (int) $pdo->query('SELECT COUNT(*) AS n FROM invoices')->fetch()['n'];
$unpaidTotal  = num_float($pdo->query('SELECT COALESCE(SUM(total - paid), 0) AS v FROM invoices')->fetch()['v']);
$monthPayments = num_float(
    $pdo->query(
        'SELECT COALESCE(SUM(amount), 0) AS v FROM payments
         WHERE created_at >= UNIX_TIMESTAMP(DATE_FORMAT(NOW(), "%Y-%m-01"))*1000'
    )->fetch()['v']
);

/* Répartition par catégorie */
$catRows = $pdo->query(
    'SELECT category_name AS name, COUNT(*) AS products, SUM(qty) AS qty
     FROM products GROUP BY category_name ORDER BY products DESC'
)->fetchAll();

/* Ventes des 6 derniers mois (encaissé par mois) */
$monthRows = $pdo->query(
    "SELECT DATE_FORMAT(FROM_UNIXTIME(created_at/1000), '%Y-%m') AS month,
            SUM(amount) AS total
     FROM payments
     WHERE created_at >= UNIX_TIMESTAMP(DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 5 MONTH), '%Y-%m-01'))*1000
     GROUP BY month ORDER BY month ASC"
)->fetchAll();

/* Dernière activité */
$recentActivity = $pdo->query('SELECT * FROM activity ORDER BY created_at DESC LIMIT 6')->fetchAll();

json_ok([
    'cards' => [
        'products'    => $countProducts,
        'stockValue'  => $stockValue,
        'lowStock'    => count($lowStock),
        'invoiceable' => $invoiceable,
        'clients'     => $countClients,
        'invoices'    => $countInvoices,
        'unpaidTotal' => $unpaidTotal,
        'monthPayments' => $monthPayments,
        'role'        => $user['role'],
    ],
    'categories'  => $catRows,
    'months'      => $monthRows,
    'activity'    => $recentActivity,
]);
