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
    'auth' => [
        'settings_password' => 'admin123',
    ],
    'sites' => [
        ['name' => 'Crystal Motors', 'url' => 'https://crystal-motors.ru/'],
        ['name' => 'Autocred1 Barnaul', 'url' => 'https://barnaul.autocred1.ru/'],
        ['name' => 'Autohouse24 Barnaul', 'url' => 'https://barnaul.autohouse24.ru/'],
        ['name' => 'SelectAuto24', 'url' => 'https://selectauto24.ru/'],
    ],
];
