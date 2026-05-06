<?php
declare(strict_types=1);

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

function normalizeTelegramRecipients(string $raw): array
{
    $parts = preg_split('/[,;\s]+/', trim($raw)) ?: [];
    $recipients = [];
    foreach ($parts as $part) {
        $id = trim($part);
        if ($id === '') {
            continue;
        }
        $recipients[] = $id;
    }

    return array_values(array_unique($recipients));
}

function getAlertTelegramRecipientsRaw(PDO $pdo): string
{
    return (string) getSetting($pdo, 'alert_telegram_recipients', '');
}

function getAlertTelegramRecipients(PDO $pdo): array
{
    return normalizeTelegramRecipients(getAlertTelegramRecipientsRaw($pdo));
}

function isTelegramRecipientValid(string $recipient): bool
{
    if ($recipient === '') {
        return false;
    }

    // Numeric chat_id (user/group/channel), optionally negative for groups.
    if (preg_match('/^-?\d+$/', $recipient) === 1) {
        return true;
    }

    // Channel/public username form: @name
    return preg_match('/^@[a-zA-Z0-9_]{5,}$/', $recipient) === 1;
}

function setAlertTelegramRecipients(PDO $pdo, string $raw): array
{
    $recipients = normalizeTelegramRecipients($raw);
    foreach ($recipients as $recipient) {
        if (!isTelegramRecipientValid($recipient)) {
            return ['ok' => false, 'error' => 'В списке есть некорректный Telegram chat_id/username: ' . $recipient];
        }
    }

    setSetting($pdo, 'alert_telegram_recipients', implode(',', $recipients));
    return ['ok' => true];
}

function recheckPendingKey(int $siteId): string
{
    return 'site_recheck_pending_' . $siteId;
}

function recheckDueKey(int $siteId): string
{
    return 'site_recheck_due_' . $siteId;
}

function scheduleSiteRecheck(PDO $pdo, int $siteId, string $now, int $delayMinutes = 2): void
{
    if ($delayMinutes < 1) {
        $delayMinutes = 1;
    }

    try {
        $dueAt = (new DateTimeImmutable($now, new DateTimeZone('UTC')))
            ->add(new DateInterval('PT' . $delayMinutes . 'M'))
            ->format('Y-m-d H:i:s');
    } catch (Exception) {
        $dueAt = $now;
    }

    setSetting($pdo, recheckPendingKey($siteId), '1');
    setSetting($pdo, recheckDueKey($siteId), $dueAt);
}

function clearSiteRecheck(PDO $pdo, int $siteId): void
{
    setSetting($pdo, recheckPendingKey($siteId), '0');
    setSetting($pdo, recheckDueKey($siteId), '');
}

function isSiteRecheckDue(PDO $pdo, int $siteId, string $now): bool
{
    $pending = (int) getSetting($pdo, recheckPendingKey($siteId), '0') === 1;
    if (!$pending) {
        return false;
    }

    $dueAt = (string) getSetting($pdo, recheckDueKey($siteId), '');
    if ($dueAt === '') {
        return false;
    }

    try {
        $nowDt = new DateTimeImmutable($now, new DateTimeZone('UTC'));
        $dueDt = new DateTimeImmutable($dueAt, new DateTimeZone('UTC'));
    } catch (Exception) {
        return false;
    }

    return $nowDt >= $dueDt;
}
