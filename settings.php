<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/settings_actions.php';
require_once __DIR__ . '/includes/settings_view.php';

$pdo = db();
$authError = null;
$formError = null;
$formSuccess = null;
$mailDiagnostics = null;

if (isset($_GET['logout']) && $_GET['logout'] === '1') {
    unset($_SESSION['settings_auth']);
    header('Location: settings.php');
    exit;
}

$isAuthed = ($_SESSION['settings_auth'] ?? false) === true;

if (!$isAuthed && $_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'login') {
    $password = (string) ($_POST['password'] ?? '');
    if (isSettingsPasswordValid($password)) {
        $_SESSION['settings_auth'] = true;
        header('Location: settings.php');
        exit;
    }
    $authError = 'Неверный пароль';
}

if ($isAuthed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $result = runSettingsAction($pdo, $action);
    if ($result !== null) {
        if (array_key_exists('mailDiagnostics', $result)) {
            $mailDiagnostics = $result['mailDiagnostics'];
        }
        if (isset($result['success'])) {
            $formSuccess = (string) $result['success'];
        }
        if (isset($result['error'])) {
            $formError = (string) $result['error'];
        }
    }
}

$sites = $isAuthed ? getSitesWithStats($pdo) : [];
$tzLabel = displayTimezoneLabel();
$currentInterval = $isAuthed ? getCheckIntervalMinutes($pdo) : 60;
$alertEmailsRaw = $isAuthed ? getAlertEmailRecipientsRaw($pdo) : '';
$intervalOptions = [
    0 => 'Отключено',
    1 => '1 мин',
    5 => '5 мин',
    10 => '10 мин',
    15 => '15 мин',
    30 => '30 мин',
    60 => '60 мин (1 час)',
    1440 => '24 часа',
];
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Настройки мониторинга</title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main class="container">
    <header class="page-header with-actions">
        <div>
            <h1>Настройки мониторинга</h1>
            <p>Управление списком сайтов.</p>
        </div>
        <div class="top-actions">
            <a class="settings-btn" href="index.php">На главную</a>
            <?php if ($isAuthed): ?>
                <a class="settings-btn" href="settings.php?logout=1">Выйти</a>
            <?php endif; ?>
        </div>
    </header>

    <?php if (!$isAuthed): ?>
        <section class="card">
            <h2>Вход по паролю</h2>
            <?php if ($authError !== null): ?>
                <div class="alert fail"><?= e($authError) ?></div>
            <?php endif; ?>
            <form method="post" class="add-site-form">
                <input type="hidden" name="action" value="login">
                <label>
                    Пароль
                    <input type="password" name="password" required>
                </label>
                <button type="submit">Войти</button>
            </form>
        </section>
    <?php else: ?>
        <section class="card">
            <h2>Интервал авто-проверок</h2>
            <form method="post" class="inline-form">
                <input type="hidden" name="action" value="update_interval">
                <label>
                    Интервал
                    <select name="interval_minutes" required>
                        <?php foreach ($intervalOptions as $option => $title): ?>
                            <option value="<?= $option ?>" <?= $currentInterval === $option ? 'selected' : '' ?>>
                                <?= e($title) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit">Сохранить</button>
            </form>
        </section>

        <section class="card">
            <h2>Email-уведомления о недоступности</h2>
            <form method="post" class="add-site-form">
                <input type="hidden" name="action" value="update_alert_emails">
                <label>
                    Email адреса через запятую
                    <textarea name="alert_emails" rows="3" placeholder="admin@example.com, devops@example.com"><?= e($alertEmailsRaw) ?></textarea>
                </label>
                <div class="inline-form">
                    <button type="submit">Сохранить</button>
                </div>
            </form>
            <form method="post" class="inline-form">
                <input type="hidden" name="action" value="test_alert_emails">
                <button type="submit">Тест почты</button>
            </form>
            <?php renderMailDiagnostics($mailDiagnostics); ?>
        </section>

        <section class="card">
            <h2>Добавить сайт</h2>
            <?php if ($formError !== null): ?>
                <div class="alert fail"><?= e($formError) ?></div>
            <?php endif; ?>
            <?php if ($formSuccess !== null): ?>
                <div class="alert ok"><?= e($formSuccess) ?></div>
            <?php endif; ?>
            <form method="post" class="add-site-form">
                <input type="hidden" name="action" value="add_site">
                <label>
                    Название
                    <input type="text" name="name" maxlength="255" required>
                </label>
                <label>
                    URL
                    <input type="url" name="url" maxlength="2048" placeholder="https://example.ru/" required>
                </label>
                <button type="submit">Добавить</button>
            </form>
        </section>

        <section class="card">
            <h2>Текущие сайты</h2>
            <table class="table">
                <thead>
                <tr>
                    <th>Редактирование</th>
                    <th>Текущий URL</th>
                    <th>Последняя проверка (<?= e($tzLabel) ?>)</th>
                    <th>Действие</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($sites as $site): ?>
                    <tr>
                        <td>
                            <form method="post" class="inline-form">
                                <input type="hidden" name="action" value="update_site">
                                <input type="hidden" name="site_id" value="<?= (int) $site['id'] ?>">
                                <input type="text" name="name" value="<?= e($site['name']) ?>" maxlength="255" required>
                                <input type="url" name="url" value="<?= e($site['url']) ?>" maxlength="2048" required>
                                <button type="submit">Сохранить</button>
                            </form>
                        </td>
                        <td><small><?= e($site['url']) ?></small></td>
                        <td><small><?= e(formatUtcForUi(isset($site['last_checked_at']) ? (string) $site['last_checked_at'] : null)) ?></small></td>
                        <td class="actions-cell">
                            <form method="post" class="inline-form">
                                <input type="hidden" name="action" value="run_check">
                                <input type="hidden" name="site_id" value="<?= (int) $site['id'] ?>">
                                <button type="submit">Проверить сейчас</button>
                            </form>
                            <form method="post" class="inline-form" onsubmit="return confirm('Удалить сайт из мониторинга?');">
                                <input type="hidden" name="action" value="delete_site">
                                <input type="hidden" name="site_id" value="<?= (int) $site['id'] ?>">
                                <button type="submit">Удалить</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
