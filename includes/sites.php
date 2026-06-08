<?php
declare(strict_types=1);

require_once __DIR__ . '/http_probe.php';

function parseEndpointUrls(string $raw): array
{
    $parts = preg_split('/\r\n|\r|\n/', trim($raw)) ?: [];
    $urls = [];
    foreach ($parts as $part) {
        $url = normalizeUrl($part);
        if ($url === '') {
            continue;
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return ['error' => 'Некорректный URL: ' . $url];
        }
        $segments = parse_url($url);
        if (!isset($segments['scheme']) || !in_array(strtolower((string) $segments['scheme']), ['http', 'https'], true)) {
            return ['error' => 'Поддерживаются только http/https URL: ' . $url];
        }
        $urls[$url] = true;
    }

    return ['urls' => array_keys($urls)];
}

function getSiteEndpointsBySiteId(PDO $pdo, int $siteId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, site_id, url, is_active, created_at
         FROM site_endpoints
         WHERE site_id = :site_id AND is_active = 1
         ORDER BY id ASC'
    );
    $stmt->execute([':site_id' => $siteId]);
    return $stmt->fetchAll() ?: [];
}

function syncSiteEndpoints(PDO $pdo, int $siteId, array $urls): void
{
    $existingStmt = $pdo->prepare('SELECT id, url FROM site_endpoints WHERE site_id = :site_id');
    $existingStmt->execute([':site_id' => $siteId]);
    $existingRows = $existingStmt->fetchAll() ?: [];
    $existingMap = [];
    foreach ($existingRows as $row) {
        $existingMap[(string) $row['url']] = (int) $row['id'];
    }

    $insertStmt = $pdo->prepare(
        'INSERT INTO site_endpoints(site_id, url, is_active, created_at)
         VALUES(:site_id, :url, 1, CURRENT_TIMESTAMP)'
    );
    $activateStmt = $pdo->prepare('UPDATE site_endpoints SET is_active = 1 WHERE id = :id');
    $deactivateStmt = $pdo->prepare('UPDATE site_endpoints SET is_active = 0 WHERE id = :id');

    foreach ($urls as $url) {
        if (isset($existingMap[$url])) {
            $activateStmt->execute([':id' => $existingMap[$url]]);
            unset($existingMap[$url]);
            continue;
        }
        $insertStmt->execute([
            ':site_id' => $siteId,
            ':url' => $url,
        ]);
    }

    foreach ($existingMap as $id) {
        $deactivateStmt->execute([':id' => $id]);
    }
}

function getSitesWithStats(PDO $pdo): array
{
    $sql = <<<SQL
SELECT
    s.id,
    s.name,
    s.url,
    (
        SELECT COUNT(*) FROM site_endpoints se
        WHERE se.site_id = s.id AND se.is_active = 1
    ) AS endpoints_count,
    (
        SELECT GROUP_CONCAT(se2.url, '\n') FROM site_endpoints se2
        WHERE se2.site_id = s.id AND se2.is_active = 1
        ORDER BY se2.id ASC
    ) AS endpoint_urls_text,
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
    if (!$row) {
        return null;
    }

    $row['endpoints'] = getSiteEndpointsBySiteId($pdo, (int) $row['id']);
    $row['endpoint_urls_text'] = implode("\n", array_column($row['endpoints'], 'url'));
    return $row;
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

function addSite(PDO $pdo, string $name, string $urlsRaw): array
{
    $name = trim($name);
    $parsed = parseEndpointUrls($urlsRaw);

    if ($name === '') {
        return ['ok' => false, 'error' => 'Введите название сайта'];
    }

    if (isset($parsed['error'])) {
        return ['ok' => false, 'error' => (string) $parsed['error']];
    }

    $urls = $parsed['urls'] ?? [];
    if (!is_array($urls) || $urls === []) {
        return ['ok' => false, 'error' => 'Введите хотя бы один URL (каждый с новой строки)'];
    }
    $primaryUrl = (string) $urls[0];

    $insertStmt = $pdo->prepare(
        'INSERT INTO sites(name, url, created_at) VALUES(:name, :url, CURRENT_TIMESTAMP)'
    );
    $insertStmt->execute([
        ':name' => $name,
        ':url' => $primaryUrl,
    ]);

    $siteId = (int) $pdo->lastInsertId();
    syncSiteEndpoints($pdo, $siteId, $urls);

    return ['ok' => true];
}

function updateSite(PDO $pdo, int $siteId, string $name, string $urlsRaw): array
{
    if ($siteId <= 0) {
        return ['ok' => false, 'error' => 'Некорректный id сайта'];
    }

    $name = trim($name);
    $parsed = parseEndpointUrls($urlsRaw);

    if ($name === '') {
        return ['ok' => false, 'error' => 'Введите название сайта'];
    }

    if (isset($parsed['error'])) {
        return ['ok' => false, 'error' => (string) $parsed['error']];
    }

    $urls = $parsed['urls'] ?? [];
    if (!is_array($urls) || $urls === []) {
        return ['ok' => false, 'error' => 'Введите хотя бы один URL (каждый с новой строки)'];
    }

    $primaryUrl = (string) $urls[0];

    $existsStmt = $pdo->prepare('SELECT id FROM sites WHERE id = :id LIMIT 1');
    $existsStmt->execute([':id' => $siteId]);
    if (!$existsStmt->fetch()) {
        return ['ok' => false, 'error' => 'Сайт не найден'];
    }

    $updateStmt = $pdo->prepare('UPDATE sites SET name = :name, url = :url WHERE id = :id');
    $updateStmt->execute([
        ':id' => $siteId,
        ':name' => $name,
        ':url' => $primaryUrl,
    ]);
    syncSiteEndpoints($pdo, $siteId, $urls);

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

    $siteStmt = $pdo->prepare('SELECT id, url, name FROM sites WHERE id = :id LIMIT 1');
    $siteStmt->execute([':id' => $siteId]);
    $site = $siteStmt->fetch();
    if (!$site) {
        return ['ok' => false, 'error' => 'Сайт не найден'];
    }

    $endpoints = getSiteEndpointsBySiteId($pdo, (int) $site['id']);
    if ($endpoints === []) {
        // Fallback for old records before endpoints migration.
        $legacyUrl = normalizeUrl((string) $site['url']);
        if ($legacyUrl !== '') {
            syncSiteEndpoints($pdo, (int) $site['id'], [$legacyUrl]);
            $endpoints = getSiteEndpointsBySiteId($pdo, (int) $site['id']);
        }
    }
    if ($endpoints === []) {
        return ['ok' => false, 'error' => 'Для сайта не задано ни одной ссылки для проверки'];
    }

    $probeSettings = httpProbeSettings();
    $now = nowUtc();
    $endpointInsert = $pdo->prepare(
        'INSERT INTO endpoint_checks(site_id, endpoint_id, timestamp, status_code, response_time_ms, is_available)
         VALUES(:site_id, :endpoint_id, :timestamp, :status_code, :response_time_ms, :is_available)'
    );
    $endpointResults = [];

    foreach ($endpoints as $endpoint) {
        $endpointUrl = (string) $endpoint['url'];
        $probe = probeHttpEndpoint($endpointUrl);
        $statusCode = $probe['status_code'] ?? null;
        $elapsedMs = $probe['response_time_ms'] ?? null;
        $isAvailable = (int) ($probe['is_available'] ?? 0);

        $endpointInsert->execute([
            ':site_id' => (int) $site['id'],
            ':endpoint_id' => (int) $endpoint['id'],
            ':timestamp' => $now,
            ':status_code' => $statusCode,
            ':response_time_ms' => $elapsedMs,
            ':is_available' => $isAvailable,
        ]);

        $endpointResults[] = [
            'url' => $endpointUrl,
            'status_code' => $statusCode,
            'response_time_ms' => $elapsedMs,
            'is_available' => $isAvailable,
        ];
    }

    $aggregate = evaluateSiteAvailability($endpointResults, $probeSettings['availability_mode']);
    $isAvailable = (int) ($aggregate['is_available'] ?? 0);
    $statusCode = $aggregate['status_code'] ?? null;
    $elapsedMs = $aggregate['response_time_ms'] ?? null;
    $failedEndpoints = $aggregate['failed_endpoints'] ?? [];

    $siteInsert = $pdo->prepare(
        'INSERT INTO checks(site_id, endpoint_id, timestamp, status_code, response_time_ms, is_available)
         VALUES(:site_id, NULL, :timestamp, :status_code, :response_time_ms, :is_available)'
    );
    $siteInsert->execute([
        ':site_id' => (int) $site['id'],
        ':timestamp' => $now,
        ':status_code' => $statusCode,
        ':response_time_ms' => $elapsedMs,
        ':is_available' => $isAvailable,
    ]);

    return [
        'ok' => true,
        'status_code' => $statusCode,
        'response_time_ms' => $elapsedMs,
        'is_available' => $isAvailable,
        'checked_at' => $now,
        'failed_endpoints' => $failedEndpoints,
        'checked_endpoints_count' => count($endpoints),
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
