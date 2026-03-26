<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

$config = appConfig();
$pdo = db();
$now = nowUtc();

$sites = $pdo->query('SELECT id, url FROM sites ORDER BY id ASC')->fetchAll();
$insert = $pdo->prepare(
    'INSERT INTO checks(site_id, timestamp, status_code, response_time_ms, is_available)
     VALUES(:site_id, :timestamp, :status_code, :response_time_ms, :is_available)'
);

foreach ($sites as $site) {
    $ch = curl_init($site['url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_NOBODY => true,
        CURLOPT_TIMEOUT => (int) $config['curl']['timeout'],
        CURLOPT_CONNECTTIMEOUT => (int) $config['curl']['connect_timeout'],
        CURLOPT_USERAGENT => (string) $config['curl']['user_agent'],
    ]);

    $start = microtime(true);
    curl_exec($ch);
    $elapsedMs = (int) round((microtime(true) - $start) * 1000);
    $curlError = curl_errno($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlError !== 0) {
        $statusCode = 0;
        $isAvailable = 0;
        $elapsedMs = null;
    } else {
        $isAvailable = ($statusCode >= 200 && $statusCode < 400) ? 1 : 0;
    }

    $insert->execute([
        ':site_id' => (int) $site['id'],
        ':timestamp' => $now,
        ':status_code' => $statusCode > 0 ? $statusCode : null,
        ':response_time_ms' => $elapsedMs,
        ':is_available' => $isAvailable,
    ]);
}

$pdo->exec("DELETE FROM checks WHERE timestamp < datetime('now', '-30 days')");

if (PHP_SAPI === 'cli') {
    echo "Checks completed at {$now}" . PHP_EOL;
}
