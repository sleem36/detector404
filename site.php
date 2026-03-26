<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

$siteId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$siteId) {
    http_response_code(400);
    echo 'Некорректный id сайта';
    exit;
}

$site = getSiteById(db(), (int) $siteId);
if (!$site) {
    http_response_code(404);
    echo 'Сайт не найден';
    exit;
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($site['name']) ?> - график</title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main class="container">
    <header class="page-header">
        <h1><?= e($site['name']) ?></h1>
        <p><a href="<?= e($site['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e($site['url']) ?></a></p>
        <p><a href="index.php">← К списку сайтов</a></p>
    </header>

    <section class="card">
        <div class="toolbar">
            <button type="button" class="period-btn active" data-period="24h">24ч</button>
            <button type="button" class="period-btn" data-period="7d">7д</button>
            <button type="button" class="period-btn" data-period="30d">30д</button>
        </div>
        <canvas id="historyChart" height="110"></canvas>
    </section>
</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
window.MONITOR_SITE_ID = <?= (int) $siteId ?>;
</script>
<script src="assets/script.js"></script>
</body>
</html>
