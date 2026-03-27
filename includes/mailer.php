<?php
declare(strict_types=1);

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
    $cleanHost = preg_replace('/[^a-z0-9\.\-]/i', (string) $host, '') ?: 'localhost';
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
