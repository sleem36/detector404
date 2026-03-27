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
    ],
    'alerts' => [
        'down_failures_threshold' => 2,
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
    'auth' => [
        'settings_password' => 'admin123',
    ],
    'ui' => [
        'timezone' => 'Europe/Moscow',
    ],
    'sites' => [
        ['name' => 'Crystal Motors', 'url' => 'https://crystal-motors.ru/'],
        ['name' => 'Autocred1 Barnaul', 'url' => 'https://barnaul.autocred1.ru/'],
        ['name' => 'Autohouse24 Barnaul', 'url' => 'https://barnaul.autohouse24.ru/'],
        ['name' => 'SelectAuto24', 'url' => 'https://selectauto24.ru/'],
    ],
];
