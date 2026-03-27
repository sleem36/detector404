<?php
declare(strict_types=1);

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function nowUtc(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
}

function appDisplayTimezone(): DateTimeZone
{
    $name = (string) (appConfig()['ui']['timezone'] ?? 'Europe/Moscow');
    try {
        return new DateTimeZone($name);
    } catch (Exception) {
        return new DateTimeZone('UTC');
    }
}

function displayTimezoneLabel(): string
{
    return appDisplayTimezone()->getName();
}

function formatUtcForUi(?string $utcDateTime): string
{
    if ($utcDateTime === null || trim($utcDateTime) === '') {
        return '—';
    }

    try {
        $utc = new DateTimeImmutable($utcDateTime, new DateTimeZone('UTC'));
        return $utc->setTimezone(appDisplayTimezone())->format('Y-m-d H:i:s');
    } catch (Exception) {
        return $utcDateTime;
    }
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

function jsonResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
