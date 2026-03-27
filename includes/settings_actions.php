<?php
declare(strict_types=1);

function flashFromOperationResult(array $result, string $successMessage, string $defaultErrorMessage): array
{
    if (($result['ok'] ?? false) === true) {
        return ['success' => $successMessage];
    }
    return ['error' => (string) ($result['error'] ?? $defaultErrorMessage)];
}

function runSettingsAction(PDO $pdo, string $action): ?array
{
    $handlers = [
        'add_site' => static function () use ($pdo): array {
            $name = (string) ($_POST['name'] ?? '');
            $url = (string) ($_POST['url'] ?? '');
            return flashFromOperationResult(addSite($pdo, $name, $url), 'Сайт добавлен', 'Ошибка добавления');
        },
        'delete_site' => static function () use ($pdo): array {
            $siteId = filter_input(INPUT_POST, 'site_id', FILTER_VALIDATE_INT);
            return flashFromOperationResult(deleteSite($pdo, (int) $siteId), 'Сайт удален', 'Ошибка удаления');
        },
        'update_site' => static function () use ($pdo): array {
            $siteId = filter_input(INPUT_POST, 'site_id', FILTER_VALIDATE_INT);
            $name = (string) ($_POST['name'] ?? '');
            $url = (string) ($_POST['url'] ?? '');
            return flashFromOperationResult(updateSite($pdo, (int) $siteId, $name, $url), 'Сайт обновлен', 'Ошибка обновления');
        },
        'run_check' => static function () use ($pdo): array {
            $siteId = filter_input(INPUT_POST, 'site_id', FILTER_VALIDATE_INT);
            $result = runSiteCheck($pdo, (int) $siteId);
            if ($result['ok'] !== true) {
                return ['error' => (string) ($result['error'] ?? 'Ошибка проверки')];
            }

            $statusText = $result['status_code'] !== null ? ('HTTP ' . (int) $result['status_code']) : 'нет HTTP кода';
            $timeText = $result['response_time_ms'] !== null ? ((string) $result['response_time_ms'] . ' ms') : '—';
            return ['success' => 'Проверка выполнена: ' . $statusText . ', отклик ' . $timeText];
        },
        'update_interval' => static function () use ($pdo): array {
            $interval = filter_input(INPUT_POST, 'interval_minutes', FILTER_VALIDATE_INT);
            return flashFromOperationResult(
                setCheckIntervalMinutes($pdo, (int) $interval),
                'Интервал проверок обновлен',
                'Ошибка сохранения интервала'
            );
        },
        'update_alert_emails' => static function () use ($pdo): array {
            $emailsRaw = (string) ($_POST['alert_emails'] ?? '');
            return flashFromOperationResult(
                setAlertEmailRecipients($pdo, $emailsRaw),
                'Email-уведомления обновлены',
                'Ошибка сохранения email-уведомлений'
            );
        },
        'test_alert_emails' => static function () use ($pdo): array {
            $result = sendTestEmailAlert($pdo, nowUtc());
            $payload = ['mailDiagnostics' => ($result['details'] ?? null)];
            if ($result['ok'] === true) {
                $count = (int) ($result['sent_count'] ?? 0);
                $payload['success'] = 'Тестовое письмо отправлено. Успешно: ' . $count;
                return $payload;
            }
            $payload['error'] = (string) ($result['error'] ?? 'Ошибка отправки тестового письма');
            return $payload;
        },
    ];

    if (!isset($handlers[$action])) {
        return null;
    }

    return $handlers[$action]();
}
