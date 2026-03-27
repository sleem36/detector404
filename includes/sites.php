<?php
declare(strict_types=1);

function getSitesWithStats(PDO $pdo): array
{
    $sql = <<<SQL
SELECT
    s.id,
    s.name,
    s.url,
    (
        SELECT c1.status_code FROM checks c1
        WHERE c1.site_id = s.id
        ORDER BY c1.timestamp DESC
        LIMIT 1
    ) AS last_status_code,
    (
        SELECT c2.is_available FROM checks c2
        WHERE c2.site_id = s.id
        ORDER BY c2.timestamp DESC
        LIMIT 1
    ) AS last_is_available,
    (
        SELECT c3.timestamp FROM checks c3
        WHERE c3.site_id = s.id
        ORDER BY c3.timestamp DESC
        LIMIT 1
    ) AS last_checked_at,
    (
        SELECT ROUND(AVG(c4.response_time_ms), 0) FROM checks c4
        WHERE c4.site_id = s.id
          AND c4.timestamp >= datetime('now', '-24 hours')
          AND c4.response_time_ms IS NOT NULL
    ) AS avg_response_time_24h
FROM sites s
ORDER BY s.id ASC
SQL;

    return $pdo->query($sql)->fetchAll();
}

function getSiteById(PDO $pdo, int $siteId): ?array
{
    $stmt = $pdo->prepare('SELECT id, name, url, created_at FROM sites WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $siteId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function getHistory(PDO $pdo, int $siteId, string $from, string $to): array
{
    $stmt = $pdo->prepare(
        'SELECT timestamp, status_code, response_time_ms, is_available
         FROM checks
         WHERE site_id = :site_id AND timestamp BETWEEN :from AND :to
         ORDER BY timestamp ASC'
    );
    $stmt->execute([
        ':site_id' => $siteId,
        ':from' => $from,
        ':to' => $to,
    ]);

    return $stmt->fetchAll();
}

function normalizeUrl(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }

    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }

    return rtrim($url, '/') . '/';
}

function addSite(PDO $pdo, string $name, string $url): array
{
    $name = trim($name);
    $url = normalizeUrl($url);

    if ($name === '') {
        return ['ok' => false, 'error' => 'Введите название сайта'];
    }

    if ($url === '') {
        return ['ok' => false, 'error' => 'Введите URL сайта'];
    }

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return ['ok' => false, 'error' => 'Некорректный URL'];
    }

    $parts = parse_url($url);
    if (!isset($parts['scheme']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
        return ['ok' => false, 'error' => 'Поддерживаются только http/https URL'];
    }

    $existsStmt = $pdo->prepare('SELECT id FROM sites WHERE url = :url LIMIT 1');
    $existsStmt->execute([':url' => $url]);
    if ($existsStmt->fetch()) {
        return ['ok' => false, 'error' => 'Такой сайт уже есть в списке'];
    }

    $insertStmt = $pdo->prepare(
        'INSERT INTO sites(name, url, created_at) VALUES(:name, :url, CURRENT_TIMESTAMP)'
    );
    $insertStmt->execute([
        ':name' => $name,
        ':url' => $url,
    ]);

    return ['ok' => true];
}

function updateSite(PDO $pdo, int $siteId, string $name, string $url): array
{
    if ($siteId <= 0) {
        return ['ok' => false, 'error' => 'Некорректный id сайта'];
    }

    $name = trim($name);
    $url = normalizeUrl($url);

    if ($name === '') {
        return ['ok' => false, 'error' => 'Введите название сайта'];
    }

    if ($url === '') {
        return ['ok' => false, 'error' => 'Введите URL сайта'];
    }

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return ['ok' => false, 'error' => 'Некорректный URL'];
    }

    $parts = parse_url($url);
    if (!isset($parts['scheme']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
        return ['ok' => false, 'error' => 'Поддерживаются только http/https URL'];
    }

    $existsStmt = $pdo->prepare('SELECT id FROM sites WHERE id = :id LIMIT 1');
    $existsStmt->execute([':id' => $siteId]);
    if (!$existsStmt->fetch()) {
        return ['ok' => false, 'error' => 'Сайт не найден'];
    }

    $duplicateStmt = $pdo->prepare('SELECT id FROM sites WHERE url = :url AND id != :id LIMIT 1');
    $duplicateStmt->execute([
        ':url' => $url,
        ':id' => $siteId,
    ]);
    if ($duplicateStmt->fetch()) {
        return ['ok' => false, 'error' => 'Такой URL уже используется другим сайтом'];
    }

    $updateStmt = $pdo->prepare('UPDATE sites SET name = :name, url = :url WHERE id = :id');
    $updateStmt->execute([
        ':id' => $siteId,
        ':name' => $name,
        ':url' => $url,
    ]);

    return ['ok' => true];
}

function deleteSite(PDO $pdo, int $siteId): array
{
    if ($siteId <= 0) {
        return ['ok' => false, 'error' => 'Некорректный id сайта'];
    }

    $existsStmt = $pdo->prepare('SELECT id FROM sites WHERE id = :id LIMIT 1');
    $existsStmt->execute([':id' => $siteId]);
    if (!$existsStmt->fetch()) {
        return ['ok' => false, 'error' => 'Сайт не найден'];
    }

    $deleteStmt = $pdo->prepare('DELETE FROM sites WHERE id = :id');
    $deleteStmt->execute([':id' => $siteId]);

    return ['ok' => true];
}

function runSiteCheck(PDO $pdo, int $siteId): array
{
    if ($siteId <= 0) {
        return ['ok' => false, 'error' => 'Некорректный id сайта'];
    }

    $siteStmt = $pdo->prepare('SELECT id, url FROM sites WHERE id = :id LIMIT 1');
    $siteStmt->execute([':id' => $siteId]);
    $site = $siteStmt->fetch();
    if (!$site) {
        return ['ok' => false, 'error' => 'Сайт не найден'];
    }

    $config = appConfig();
    $now = nowUtc();
    $ch = curl_init((string) $site['url']);
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

    $insert = $pdo->prepare(
        'INSERT INTO checks(site_id, timestamp, status_code, response_time_ms, is_available)
         VALUES(:site_id, :timestamp, :status_code, :response_time_ms, :is_available)'
    );
    $insert->execute([
        ':site_id' => (int) $site['id'],
        ':timestamp' => $now,
        ':status_code' => $statusCode > 0 ? $statusCode : null,
        ':response_time_ms' => $elapsedMs,
        ':is_available' => $isAvailable,
    ]);

    return [
        'ok' => true,
        'status_code' => $statusCode > 0 ? $statusCode : null,
        'response_time_ms' => $elapsedMs,
        'is_available' => $isAvailable,
        'checked_at' => $now,
    ];
}

function isSettingsPasswordValid(string $password): bool
{
    $password = trim($password);
    if ($password === '') {
        return false;
    }

    $configured = (string) (appConfig()['auth']['settings_password'] ?? '');
    if ($configured === '') {
        return false;
    }

    if (str_starts_with($configured, '$2y$')) {
        return password_verify($password, $configured);
    }

    return hash_equals($configured, $password);
}
