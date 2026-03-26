<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function nowUtc(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
}

function periodStart(string $period): DateTimeImmutable
{
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    return match ($period) {
        '7d' => $now->sub(new DateInterval('P7D')),
        '30d' => $now->sub(new DateInterval('P30D')),
        default => $now->sub(new DateInterval('PT24H')),
    };
}

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

function jsonResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
