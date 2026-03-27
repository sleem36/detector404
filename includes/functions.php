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

function getSetting(PDO $pdo, string $key, ?string $default = null): ?string
{
    $stmt = $pdo->prepare('SELECT value FROM app_settings WHERE key = :key LIMIT 1');
    $stmt->execute([':key' => $key]);
    $row = $stmt->fetch();
    if (!$row) {
        return $default;
    }
    return (string) $row['value'];
}

function setSetting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO app_settings(key, value, updated_at)
         VALUES(:key, :value, CURRENT_TIMESTAMP)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([
        ':key' => $key,
        ':value' => $value,
    ]);
}

function getCheckIntervalMinutes(PDO $pdo): int
{
    $raw = getSetting($pdo, 'check_interval_minutes', (string) (appConfig()['checks']['interval_minutes'] ?? 60));
    $interval = (int) $raw;
    if ($interval < 0) {
        return 60;
    }
    if ($interval > 1440) {
        return 1440;
    }
    return $interval;
}

function setCheckIntervalMinutes(PDO $pdo, int $intervalMinutes): array
{
    if (!in_array($intervalMinutes, [0, 1, 5, 10, 15, 30, 60, 1440], true)) {
        return ['ok' => false, 'error' => 'Недопустимый интервал проверки'];
    }

    setSetting($pdo, 'check_interval_minutes', (string) $intervalMinutes);
    return ['ok' => true];
}

function shouldRunScheduledChecks(PDO $pdo, string $now): bool
{
    $interval = getCheckIntervalMinutes($pdo);
    if ($interval === 0) {
        return false;
    }

    $lastRunRaw = getSetting($pdo, 'last_checks_run_at', null);
    if ($lastRunRaw === null || trim($lastRunRaw) === '') {
        setSetting($pdo, 'last_checks_run_at', $now);
        return true;
    }

    try {
        $nowDt = new DateTimeImmutable($now, new DateTimeZone('UTC'));
        $lastDt = new DateTimeImmutable($lastRunRaw, new DateTimeZone('UTC'));
    } catch (Exception) {
        setSetting($pdo, 'last_checks_run_at', $now);
        return true;
    }

    if (($nowDt->getTimestamp() - $lastDt->getTimestamp()) < ($interval * 60)) {
        return false;
    }

    setSetting($pdo, 'last_checks_run_at', $now);
    return true;
}

function normalizeEmailList(string $raw): array
{
    $parts = preg_split('/[,;\s]+/', trim($raw)) ?: [];
    $emails = [];
    foreach ($parts as $part) {
        $email = strtolower(trim($part));
        if ($email === '') {
            continue;
        }
        $emails[] = $email;
    }

    return array_values(array_unique($emails));
}

function getAlertEmailRecipientsRaw(PDO $pdo): string
{
    return (string) getSetting($pdo, 'alert_email_recipients', '');
}

function getAlertEmailRecipients(PDO $pdo): array
{
    return normalizeEmailList(getAlertEmailRecipientsRaw($pdo));
}

function setAlertEmailRecipients(PDO $pdo, string $raw): array
{
    $emails = normalizeEmailList($raw);
    foreach ($emails as $email) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'В списке есть некорректный email: ' . $email];
        }
    }

    setSetting($pdo, 'alert_email_recipients', implode(',', $emails));
    return ['ok' => true];
}

function emailSubjectUtf8(string $subject): string
{
    return '=?UTF-8?B?' . base64_encode($subject) . '?=';
}

function alertThresholds(): array
{
    $down = (int) (appConfig()['alerts']['down_failures_threshold'] ?? 2);
    $up = (int) (appConfig()['alerts']['up_success_threshold'] ?? 2);

    return [
        'down' => $down > 0 ? $down : 2,
        'up' => $up > 0 ? $up : 2,
    ];
}

function smtpConfig(): array
{
    $cfg = appConfig()['smtp'] ?? [];
    return [
        'enabled' => (bool) ($cfg['enabled'] ?? false),
        'host' => (string) ($cfg['host'] ?? ''),
        'port' => (int) ($cfg['port'] ?? 587),
        'secure' => strtolower((string) ($cfg['secure'] ?? 'tls')),
        'username' => (string) ($cfg['username'] ?? ''),
        'password' => (string) ($cfg['password'] ?? ''),
        'from_email' => (string) ($cfg['from_email'] ?? ''),
        'from_name' => (string) ($cfg['from_name'] ?? ''),
        'reply_to' => (string) ($cfg['reply_to'] ?? ''),
        'timeout' => (int) ($cfg['timeout'] ?? 10),
    ];
}

function defaultFromEmail(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $cleanHost = preg_replace('/[^a-z0-9\.\-]/i', '', (string) $host) ?: 'localhost';
    return 'monitor@' . $cleanHost;
}

function senderIdentity(): array
{
    $smtp = smtpConfig();
    $fromEmail = trim($smtp['from_email']) !== '' ? trim($smtp['from_email']) : defaultFromEmail();
    $fromName = trim((string) $smtp['from_name']) !== '' ? trim((string) $smtp['from_name']) : 'Downdetector Monitor';
    $replyTo = trim((string) $smtp['reply_to']);

    return [
        'from_email' => $fromEmail,
        'from_name' => $fromName,
        'reply_to' => $replyTo,
    ];
}

function plainTextHeaders(array $sender): string
{
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= 'From: "' . str_replace('"', '', $sender['from_name']) . '" <' . $sender['from_email'] . ">\r\n";
    if ($sender['reply_to'] !== '') {
        $headers .= "Reply-To: " . $sender['reply_to'] . "\r\n";
    }
    return $headers;
}

function smtpReadResponse($fp): string
{
    $response = '';
    while (!feof($fp)) {
        $line = fgets($fp, 515);
        if ($line === false) {
            break;
        }
        $response .= $line;
        if (preg_match('/^\d{3}\s/', $line) === 1) {
            break;
        }
    }
    return trim($response);
}

function smtpIsOkCode(string $response, array $okCodes): bool
{
    if (preg_match('/^(\d{3})/', $response, $m) !== 1) {
        return false;
    }
    return in_array((int) $m[1], $okCodes, true);
}

function smtpCommand($fp, string $command, array $okCodes, string &$response): bool
{
    fwrite($fp, $command . "\r\n");
    $response = smtpReadResponse($fp);
    return smtpIsOkCode($response, $okCodes);
}

function smtpSendEmailToRecipientsDetailed(array $recipients, string $subject, string $message, array $sender): array
{
    $smtp = smtpConfig();
    $results = [];
    $sentCount = 0;
    $lastError = null;

    $host = $smtp['host'];
    $port = $smtp['port'];
    $secure = $smtp['secure'];
    $timeout = max(1, (int) $smtp['timeout']);
    $transportHost = $secure === 'ssl' ? ('ssl://' . $host) : $host;

    $fp = @stream_socket_client(
        $transportHost . ':' . $port,
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT
    );

    if (!$fp) {
        $err = 'SMTP connect failed: ' . $errstr . ' (' . $errno . ')';
        foreach ($recipients as $recipient) {
            $results[] = ['recipient' => $recipient, 'ok' => false, 'error' => $err];
        }
        return [
            'sent_count' => 0,
            'from' => $sender['from_email'],
            'transport' => 'smtp',
            'results' => $results,
            'last_error' => $err,
        ];
    }

    stream_set_timeout($fp, $timeout);
    $response = smtpReadResponse($fp);
    if (!smtpIsOkCode($response, [220])) {
        $err = 'SMTP greeting failed: ' . $response;
        fclose($fp);
        foreach ($recipients as $recipient) {
            $results[] = ['recipient' => $recipient, 'ok' => false, 'error' => $err];
        }
        return ['sent_count' => 0, 'from' => $sender['from_email'], 'transport' => 'smtp', 'results' => $results, 'last_error' => $err];
    }

    $ehloHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
    if (!smtpCommand($fp, 'EHLO ' . $ehloHost, [250], $response)) {
        $err = 'SMTP EHLO failed: ' . $response;
        fclose($fp);
        foreach ($recipients as $recipient) {
            $results[] = ['recipient' => $recipient, 'ok' => false, 'error' => $err];
        }
        return ['sent_count' => 0, 'from' => $sender['from_email'], 'transport' => 'smtp', 'results' => $results, 'last_error' => $err];
    }

    if ($secure === 'tls') {
        if (!smtpCommand($fp, 'STARTTLS', [220], $response)) {
            $err = 'SMTP STARTTLS failed: ' . $response;
            fclose($fp);
            foreach ($recipients as $recipient) {
                $results[] = ['recipient' => $recipient, 'ok' => false, 'error' => $err];
            }
            return ['sent_count' => 0, 'from' => $sender['from_email'], 'transport' => 'smtp', 'results' => $results, 'last_error' => $err];
        }
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            $err = 'SMTP TLS handshake failed';
            fclose($fp);
            foreach ($recipients as $recipient) {
                $results[] = ['recipient' => $recipient, 'ok' => false, 'error' => $err];
            }
            return ['sent_count' => 0, 'from' => $sender['from_email'], 'transport' => 'smtp', 'results' => $results, 'last_error' => $err];
        }
        if (!smtpCommand($fp, 'EHLO ' . $ehloHost, [250], $response)) {
            $err = 'SMTP EHLO after STARTTLS failed: ' . $response;
            fclose($fp);
            foreach ($recipients as $recipient) {
                $results[] = ['recipient' => $recipient, 'ok' => false, 'error' => $err];
            }
            return ['sent_count' => 0, 'from' => $sender['from_email'], 'transport' => 'smtp', 'results' => $results, 'last_error' => $err];
        }
    }

    if ($smtp['username'] !== '') {
        if (!smtpCommand($fp, 'AUTH LOGIN', [334], $response)
            || !smtpCommand($fp, base64_encode($smtp['username']), [334], $response)
            || !smtpCommand($fp, base64_encode($smtp['password']), [235], $response)) {
            $err = 'SMTP AUTH failed: ' . $response;
            fclose($fp);
            foreach ($recipients as $recipient) {
                $results[] = ['recipient' => $recipient, 'ok' => false, 'error' => $err];
            }
            return ['sent_count' => 0, 'from' => $sender['from_email'], 'transport' => 'smtp', 'results' => $results, 'last_error' => $err];
        }
    }

    foreach ($recipients as $recipient) {
        if (!smtpCommand($fp, 'MAIL FROM:<' . $sender['from_email'] . '>', [250], $response)) {
            $lastError = 'MAIL FROM failed: ' . $response;
            $results[] = ['recipient' => $recipient, 'ok' => false, 'error' => $lastError];
            continue;
        }
        if (!smtpCommand($fp, 'RCPT TO:<' . $recipient . '>', [250, 251], $response)) {
            $lastError = 'RCPT TO failed: ' . $response;
            $results[] = ['recipient' => $recipient, 'ok' => false, 'error' => $lastError];
            continue;
        }
        if (!smtpCommand($fp, 'DATA', [354], $response)) {
            $lastError = 'DATA failed: ' . $response;
            $results[] = ['recipient' => $recipient, 'ok' => false, 'error' => $lastError];
            continue;
        }

        $data = plainTextHeaders($sender);
        $data .= 'Subject: ' . $subject . "\r\n";
        $data .= 'To: <' . $recipient . ">\r\n\r\n";
        $safeMessage = preg_replace("/(?m)^\./", '..', str_replace(["\r\n", "\r"], "\n", $message) ?? '');
        $data .= str_replace("\n", "\r\n", (string) $safeMessage) . "\r\n.\r\n";
        fwrite($fp, $data);
        $response = smtpReadResponse($fp);
        if (!smtpIsOkCode($response, [250])) {
            $lastError = 'Message rejected: ' . $response;
            $results[] = ['recipient' => $recipient, 'ok' => false, 'error' => $lastError];
            continue;
        }

        $sentCount++;
        $results[] = ['recipient' => $recipient, 'ok' => true, 'error' => null];
    }

    smtpCommand($fp, 'QUIT', [221], $response);
    fclose($fp);

    return [
        'sent_count' => $sentCount,
        'from' => $sender['from_email'],
        'transport' => 'smtp',
        'results' => $results,
        'last_error' => $lastError,
    ];
}

function sendEmailToRecipientsDetailed(array $recipients, string $subject, string $message): array
{
    $sender = senderIdentity();
    if ($recipients === []) {
        return [
            'sent_count' => 0,
            'from' => $sender['from_email'],
            'transport' => 'mail',
            'results' => [],
            'last_error' => null,
        ];
    }

    $smtp = smtpConfig();
    if ($smtp['enabled'] && $smtp['host'] !== '') {
        return smtpSendEmailToRecipientsDetailed($recipients, $subject, $message, $sender);
    }

    $headers = plainTextHeaders($sender);
    $params = '-f' . $sender['from_email'];

    $sentCount = 0;
    $results = [];
    $lastErrorText = null;
    foreach ($recipients as $recipient) {
        $ok = @mail($recipient, $subject, $message, $headers, $params);
        if ($ok) {
            $sentCount++;
            $results[] = ['recipient' => $recipient, 'ok' => true, 'error' => null];
            continue;
        }

        $err = error_get_last();
        $errorText = is_array($err) && isset($err['message']) ? (string) $err['message'] : 'unknown';
        $results[] = ['recipient' => $recipient, 'ok' => false, 'error' => $errorText];
        $lastErrorText = $errorText;
    }

    return [
        'sent_count' => $sentCount,
        'from' => $sender['from_email'],
        'transport' => 'mail',
        'results' => $results,
        'last_error' => $lastErrorText,
    ];
}

function sendEmailToRecipients(array $recipients, string $subject, string $message): int
{
    $details = sendEmailToRecipientsDetailed($recipients, $subject, $message);
    return (int) ($details['sent_count'] ?? 0);
}

function sendTestEmailAlert(PDO $pdo, string $now): array
{
    $recipients = getAlertEmailRecipients($pdo);
    if ($recipients === []) {
        return ['ok' => false, 'error' => 'Список email пуст. Сначала сохраните хотя бы один адрес.'];
    }

    $subject = emailSubjectUtf8('Тест уведомлений мониторинга');
    $message = "Это тестовое сообщение мониторинга сайтов.\n\n"
        . 'Время (UTC): ' . $now . "\n"
        . 'Получатели: ' . implode(', ', $recipients) . "\n\n"
        . "Если письмо получено, значит email-уведомления работают.";

    $details = sendEmailToRecipientsDetailed($recipients, $subject, $message);
    $sentCount = (int) ($details['sent_count'] ?? 0);
    if ($sentCount === 0) {
        return [
            'ok' => false,
            'error' => 'Не удалось отправить тестовое письмо. Проверьте почтовые настройки сервера.',
            'details' => $details,
        ];
    }

    return [
        'ok' => true,
        'sent_count' => $sentCount,
        'details' => $details,
    ];
}

function sendDownEmailAlertIfNeeded(PDO $pdo, int $siteId, array $checkResult, string $now): void
{
    processSiteAlertState($pdo, $siteId, $checkResult, $now);
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

function jsonResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
