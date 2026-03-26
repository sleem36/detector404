<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

$pdo = db();
$action = $_GET['action'] ?? '';

if ($action === 'sites') {
    jsonResponse([
        'ok' => true,
        'data' => getSitesWithStats($pdo),
    ]);
}

if ($action === 'history') {
    $siteId = filter_input(INPUT_GET, 'site_id', FILTER_VALIDATE_INT);
    $from = $_GET['from'] ?? null;
    $to = $_GET['to'] ?? null;

    if (!$siteId) {
        jsonResponse(['ok' => false, 'error' => 'Invalid site_id'], 422);
    }

    if (!$from || !$to) {
        $period = $_GET['period'] ?? '24h';
        $start = periodStart($period === '7d' || $period === '30d' ? $period : '24h');
        $from = $start->format('Y-m-d H:i:s');
        $to = nowUtc();
    }

    jsonResponse([
        'ok' => true,
        'data' => getHistory($pdo, (int) $siteId, $from, $to),
    ]);
}

jsonResponse(['ok' => false, 'error' => 'Unknown action'], 404);
