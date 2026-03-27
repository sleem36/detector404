<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
$pdo = db();
$sites = getSitesWithStats($pdo);
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Мониторинг сайтов</title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main class="container">
    <header class="page-header with-actions">
        <div>
            <h1>Мониторинг доступности сайтов</h1>
            <p>Последние проверки и средний отклик за 24 часа.</p>
        </div>
        <div class="top-actions">
            <a class="settings-btn" href="settings.php">Настройки</a>
        </div>
    </header>

    <section class="card">
        <table class="table">
            <thead>
            <tr>
                <th>Сайт</th>
                <th>Статус</th>
                <th>Последняя проверка (UTC)</th>
                <th>Средний отклик 24ч (ms)</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($sites as $site): ?>
                <?php
                $isUp = isset($site['last_is_available']) && (int) $site['last_is_available'] === 1;
                $statusText = $site['last_status_code'] ? ('HTTP ' . (int) $site['last_status_code']) : 'Нет данных';
                ?>
                <tr>
                    <td>
                        <a href="site.php?id=<?= (int) $site['id'] ?>"><?= e($site['name']) ?></a><br>
                        <small><?= e($site['url']) ?></small>
                    </td>
                    <td>
                        <span class="badge <?= $isUp ? 'ok' : 'fail' ?>">
                            <?= $isUp ? 'Доступен' : 'Недоступен' ?>
                        </span>
                        <div><small><?= e($statusText) ?></small></div>
                    </td>
                    <td><?= e((string) ($site['last_checked_at'] ?? '—')) ?></td>
                    <td><?= e((string) ($site['avg_response_time_24h'] ?? '—')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</main>
</body>
</html>
