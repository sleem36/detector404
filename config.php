<?php
declare(strict_types=1);

return [
    'db_path' => __DIR__ . '/db/monitor.db',
    'curl' => [
        'timeout' => 10,
        'connect_timeout' => 10,
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        'accept_language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
    ],
    'checks' => [
        'interval_minutes' => 60,
        // HEAD -> GET retry helps when WAF blocks HEAD but allows browser-like GET.
        'retry_with_get' => true,
        'get_body_limit_bytes' => 8192,
        // primary = only first URL decides up/down; all = strict multi-endpoint mode.
        'availability_mode' => 'primary',
        'challenge_status_codes' => [403, 429, 503],
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
        ['name' => 'Crystal Motors', 'url' => 'https://crystal-motors.ru/'],
        ['name' => 'Autocred1 Barnaul', 'url' => 'https://barnaul.autocred1.ru/'],
        ['name' => 'Autohouse24 Barnaul', 'url' => 'https://barnaul.autohouse24.ru/'],
        ['name' => 'SelectAuto24', 'url' => 'https://selectauto24.ru/'],
    ],
];
