<?php
declare(strict_types=1);

function renderMailDiagnostics(?array $mailDiagnostics): void
{
    if ($mailDiagnostics === null) {
        return;
    }
    ?>
    <div class="mail-log">
        <div><strong>Диагностика отправки</strong></div>
        <div><small>Транспорт: <?= e((string) ($mailDiagnostics['transport'] ?? 'mail')) ?></small></div>
        <div><small>From: <?= e((string) ($mailDiagnostics['from'] ?? '')) ?></small></div>
        <?php if (isset($mailDiagnostics['results']) && is_array($mailDiagnostics['results'])): ?>
            <?php foreach ($mailDiagnostics['results'] as $item): ?>
                <?php
                $ok = (bool) ($item['ok'] ?? false);
                $recipient = (string) ($item['recipient'] ?? '');
                $error = (string) ($item['error'] ?? '');
                ?>
                <div>
                    <small>
                        <?= $ok ? 'OK' : 'FAIL' ?> — <?= e($recipient) ?>
                        <?= $error !== '' ? (' | ' . e($error)) : '' ?>
                    </small>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php if (!empty($mailDiagnostics['last_error'])): ?>
            <div><small>Последняя ошибка: <?= e((string) $mailDiagnostics['last_error']) ?></small></div>
        <?php endif; ?>
        <div><small>Важно: успешная отправка означает принятие почтовым сервером, но не гарантирует доставку во входящие.</small></div>
    </div>
    <?php
}
