<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

$pdo = db();
$now = nowUtc();

if (!shouldRunScheduledChecks($pdo, $now)) {
    if (PHP_SAPI === 'cli') {
        $interval = getCheckIntervalMinutes($pdo);
        if ($interval === 0) {
            echo "Skipped at {$now}, auto-checks disabled" . PHP_EOL;
        } else {
            echo "Skipped at {$now}, interval {$interval} min" . PHP_EOL;
        }
    }
    exit;
}

$sites = $pdo->query('SELECT id FROM sites ORDER BY id ASC')->fetchAll();
foreach ($sites as $site) {
    $siteId = (int) $site['id'];
    $result = runSiteCheck($pdo, $siteId);
    if (($result['ok'] ?? false) === true) {
        sendDownEmailAlertIfNeeded($pdo, $siteId, $result, $now);
    }
}

$pdo->exec("DELETE FROM checks WHERE timestamp < datetime('now', '-30 days')");

if (PHP_SAPI === 'cli') {
    echo "Checks completed at {$now}" . PHP_EOL;
}
