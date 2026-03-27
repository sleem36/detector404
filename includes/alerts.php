<?php
declare(strict_types=1);

function processAlertsAfterCheck(PDO $pdo, int $siteId, array $checkResult, string $now): void
{
    processSiteAlertState($pdo, $siteId, $checkResult, $now);
}

// Backward-compatible alias; keep while older callers may exist.
function sendDownEmailAlertIfNeeded(PDO $pdo, int $siteId, array $checkResult, string $now): void
{
    processAlertsAfterCheck($pdo, $siteId, $checkResult, $now);
}

function getOpenIncidentBySite(PDO $pdo, int $siteId): ?array
{
    $stmt = $pdo->prepare('SELECT id, started_at FROM incidents WHERE site_id = :site_id AND status = "open" ORDER BY id DESC LIMIT 1');
    $stmt->execute([':site_id' => $siteId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function openIncident(PDO $pdo, int $siteId, string $now, ?int $statusCode): void
{
    if (getOpenIncidentBySite($pdo, $siteId) !== null) {
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO incidents(site_id, started_at, status, start_status_code)
         VALUES(:site_id, :started_at, "open", :start_status_code)'
    );
    $stmt->execute([
        ':site_id' => $siteId,
        ':started_at' => $now,
        ':start_status_code' => $statusCode,
    ]);
}

function closeIncident(PDO $pdo, int $siteId, string $now, ?int $statusCode): ?int
{
    $incident = getOpenIncidentBySite($pdo, $siteId);
    if ($incident === null) {
        return null;
    }

    try {
        $startDt = new DateTimeImmutable((string) $incident['started_at'], new DateTimeZone('UTC'));
        $endDt = new DateTimeImmutable($now, new DateTimeZone('UTC'));
        $duration = max(0, $endDt->getTimestamp() - $startDt->getTimestamp());
    } catch (Exception) {
        $duration = 0;
    }

    $stmt = $pdo->prepare(
        'UPDATE incidents
         SET resolved_at = :resolved_at, duration_seconds = :duration_seconds, status = "resolved", end_status_code = :end_status_code
         WHERE id = :id'
    );
    $stmt->execute([
        ':resolved_at' => $now,
        ':duration_seconds' => $duration,
        ':end_status_code' => $statusCode,
        ':id' => (int) $incident['id'],
    ]);

    return $duration;
}

function sendSiteStatusAlert(PDO $pdo, int $siteId, array $checkResult, string $now, string $eventType, ?int $durationSeconds = null): void
{
    $site = getSiteById($pdo, $siteId);
    if (!$site) {
        return;
    }

    $recipients = getAlertEmailRecipients($pdo);
    if ($recipients === []) {
        return;
    }

    $status = $checkResult['status_code'] !== null ? ('HTTP ' . (int) $checkResult['status_code']) : 'Нет HTTP-кода';
    $response = $checkResult['response_time_ms'] !== null ? ((string) $checkResult['response_time_ms'] . ' ms') : '—';

    if ($eventType === 'down') {
        $subject = emailSubjectUtf8('Мониторинг: сайт недоступен - ' . (string) $site['name']);
        $message = "Зафиксирована недоступность сайта.\n\n"
            . 'Сайт: ' . (string) $site['name'] . "\n"
            . 'URL: ' . (string) $site['url'] . "\n"
            . 'Статус: ' . $status . "\n"
            . 'Отклик: ' . $response . "\n"
            . 'Время (UTC): ' . $now . "\n";
    } else {
        $subject = emailSubjectUtf8('Мониторинг: сайт восстановлен - ' . (string) $site['name']);
        $durationText = $durationSeconds !== null ? ((string) $durationSeconds . ' сек') : '—';
        $message = "Сайт снова доступен.\n\n"
            . 'Сайт: ' . (string) $site['name'] . "\n"
            . 'URL: ' . (string) $site['url'] . "\n"
            . 'Статус: ' . $status . "\n"
            . 'Отклик: ' . $response . "\n"
            . 'Длительность инцидента: ' . $durationText . "\n"
            . 'Время восстановления (UTC): ' . $now . "\n";
    }

    sendEmailToRecipients($recipients, $subject, $message);
}

function processSiteAlertState(PDO $pdo, int $siteId, array $checkResult, string $now): void
{
    if (!isset($checkResult['is_available'])) {
        return;
    }

    $thresholds = alertThresholds();
    $stateKey = 'site_state_' . $siteId;
    $failKey = 'site_fail_count_' . $siteId;
    $successKey = 'site_success_count_' . $siteId;

    $state = (string) getSetting($pdo, $stateKey, 'unknown');
    $failCount = (int) getSetting($pdo, $failKey, '0');
    $successCount = (int) getSetting($pdo, $successKey, '0');
    $isAvailable = (int) $checkResult['is_available'] === 1;
    $statusCode = isset($checkResult['status_code']) && $checkResult['status_code'] !== null ? (int) $checkResult['status_code'] : null;

    if (!$isAvailable) {
        $failCount++;
        $successCount = 0;
        setSetting($pdo, $failKey, (string) $failCount);
        setSetting($pdo, $successKey, '0');

        if ($failCount >= $thresholds['down'] && $state !== 'down') {
            setSetting($pdo, $stateKey, 'down');
            openIncident($pdo, $siteId, $now, $statusCode);
            sendSiteStatusAlert($pdo, $siteId, $checkResult, $now, 'down');
        }
        return;
    }

    $successCount++;
    $failCount = 0;
    setSetting($pdo, $successKey, (string) $successCount);
    setSetting($pdo, $failKey, '0');

    if ($state === 'down' && $successCount >= $thresholds['up']) {
        setSetting($pdo, $stateKey, 'up');
        $duration = closeIncident($pdo, $siteId, $now, $statusCode);
        sendSiteStatusAlert($pdo, $siteId, $checkResult, $now, 'up', $duration);
        return;
    }

    if ($state === 'unknown' && $successCount >= $thresholds['up']) {
        setSetting($pdo, $stateKey, 'up');
    }
}
