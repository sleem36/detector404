<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

$pdo = db();
$now = nowUtc();

$runScheduled = shouldRunScheduledChecks($pdo, $now);

$sites = $pdo->query('SELECT id FROM sites ORDER BY id ASC')->fetchAll();
$siteIdsToCheck = [];
foreach ($sites as $siteRow) {
    $siteId = (int) $siteRow['id'];
    if ($runScheduled || isSiteRecheckDue($pdo, $siteId, $now)) {
        $siteIdsToCheck[] = $siteId;
    }
}

if ($siteIdsToCheck === []) {
    if (PHP_SAPI === 'cli') {
        $interval = getCheckIntervalMinutes($pdo);
        if ($interval === 0) {
            echo "Skipped at {$now}, auto-checks disabled and no due rechecks" . PHP_EOL;
        } else {
            echo "Skipped at {$now}, interval {$interval} min and no due rechecks" . PHP_EOL;
        }
    }
    exit;
}

foreach ($siteIdsToCheck as $siteId) {
    $result = runSiteCheck($pdo, $siteId);
    if (($result['ok'] ?? false) === true) {
        processAlertsAfterCheck($pdo, $siteId, $result, $now);
    }
}

$pdo->exec("DELETE FROM checks WHERE timestamp < datetime('now', '-30 days')");

if (PHP_SAPI === 'cli') {
    echo "Checks completed at {$now}, processed sites: " . count($siteIdsToCheck) . PHP_EOL;
}
