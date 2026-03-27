<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

$pdo = db();
$now = nowUtc();

$sites = $pdo->query('SELECT id FROM sites ORDER BY id ASC')->fetchAll();
foreach ($sites as $site) {
    runSiteCheck($pdo, (int) $site['id']);
}

$pdo->exec("DELETE FROM checks WHERE timestamp < datetime('now', '-30 days')");

if (PHP_SAPI === 'cli') {
    echo "Checks completed at {$now}" . PHP_EOL;
}
