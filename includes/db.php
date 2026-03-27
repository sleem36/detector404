<?php
declare(strict_types=1);

function appConfig(): array
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/../config.php';
    }
    return $config;
}

function ensureStorageDirs(): void
{
    $dbDir = dirname(appConfig()['db_path']);
    if (!is_dir($dbDir)) {
        mkdir($dbDir, 0775, true);
    }
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    ensureStorageDirs();
    $pdo = new PDO('sqlite:' . appConfig()['db_path']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');

    initDb($pdo);
    seedSites($pdo);
    seedSettings($pdo);

    return $pdo;
}

function initDb(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS sites (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            url TEXT NOT NULL UNIQUE,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS checks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            site_id INTEGER NOT NULL,
            timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            status_code INTEGER,
            response_time_ms INTEGER,
            is_available INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE
        )'
    );

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_checks_site_time ON checks(site_id, timestamp)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_checks_time ON checks(timestamp)');
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS app_settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );
}

function seedSites(PDO $pdo): void
{
    $existingUrls = [];
    foreach ($pdo->query('SELECT url FROM sites')->fetchAll() as $row) {
        $existingUrls[(string) $row['url']] = true;
    }

    $toInsert = [];
    foreach (appConfig()['sites'] as $site) {
        if (!isset($existingUrls[$site['url']])) {
            $toInsert[] = $site;
        }
    }

    if ($toInsert === []) {
        return;
    }

    $stmt = $pdo->prepare('INSERT INTO sites(name, url, created_at) VALUES(:name, :url, CURRENT_TIMESTAMP)');
    foreach ($toInsert as $site) {
        $stmt->execute([
            ':name' => (string) $site['name'],
            ':url' => (string) $site['url'],
        ]);
    }
}

function seedSettings(PDO $pdo): void
{
    $defaultInterval = (int) (appConfig()['checks']['interval_minutes'] ?? 60);
    if ($defaultInterval < 1) {
        $defaultInterval = 60;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO app_settings(key, value, updated_at)
         VALUES(:key, :value, CURRENT_TIMESTAMP)
         ON CONFLICT(key) DO NOTHING'
    );
    $stmt->execute([
        ':key' => 'check_interval_minutes',
        ':value' => (string) $defaultInterval,
    ]);
}
