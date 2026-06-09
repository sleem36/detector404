<?php
declare(strict_types=1);

return [
    'db_path' => __DIR__ . '/db/monitor.db',
    'curl' => [
        'timeout' => 10,
        'connect_timeout' => 10,
        'user_agent' => 'Detector404/1.0 (+local-monitor)',
    ],
    'checks' => [
        'interval_minutes' => 60,
        'get_body_limit_bytes' => 8192,
        'retry_with_get' => false,
        'availability_mode' => 'all',
        'challenge_status_codes' => [403, 429, 503],
    ],
    'site_probe_profiles' => [
        'selectauto24.ru' => [
            'browser_like' => true,
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'accept_language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
            'retry_with_get' => true,
            'availability_mode' => 'primary',
        ],
    ],
    'alerts' => [
        'down_failures_threshold' => 3,
        'up_success_threshold' => 2,
    ],
    'smtp' => [
        'enabled' => false,
        'host' => '',
        'port' => 587,
        'secure' => 'tls', // tls, ssl, none
        'username' => '',
        'password' => '',
        'from_email' => 'monitor@downdetector.na4u.ru',
        'from_name' => 'Downdetector Monitor',
        'reply_to' => '',
        'timeout' => 10,
    ],
    'telegram' => [
        'enabled' => false,
        'bot_token' => '',
        'timeout' => 10,
        'api_base' => 'https://api.telegram.org',
    ],
    'auth' => [
        'settings_password' => 'admin123',
    ],
    'ui' => [
        'timezone' => 'Europe/Yekaterinburg',
    ],
    'sites' => [

    ],
];
