<?php
declare(strict_types=1);

function envLoadMap(): array
{
    static $env = null;
    if ($env !== null) {
        return $env;
    }

    $env = [];
    $envFile = __DIR__ . '/../.env';
    if (!is_file($envFile)) {
        return $env;
    }

    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return $env;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }

        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        if ($key === '') {
            continue;
        }

        $len = strlen($value);
        if ($len >= 2) {
            $first = $value[0];
            $last = $value[$len - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        $env[$key] = $value;
    }

    return $env;
}

function envValue(string $key): ?string
{
    $env = envLoadMap();
    if (!array_key_exists($key, $env)) {
        return null;
    }
    return $env[$key];
}

function envBool(string $key, ?bool $default = null): ?bool
{
    $value = envValue($key);
    if ($value === null) {
        return $default;
    }

    $normalized = strtolower(trim($value));
    if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }
    if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }
    return $default;
}

function envInt(string $key, ?int $default = null): ?int
{
    $value = envValue($key);
    if ($value === null || trim($value) === '') {
        return $default;
    }
    return is_numeric($value) ? (int) $value : $default;
}

function applyEnvOverrides(array $config): array
{
    $authPassword = envValue('APP_SETTINGS_PASSWORD');
    if ($authPassword !== null && $authPassword !== '') {
        $config['auth']['settings_password'] = $authPassword;
    }

    $smtpEnabled = envBool('SMTP_ENABLED', null);
    if ($smtpEnabled !== null) {
        $config['smtp']['enabled'] = $smtpEnabled;
    }

    $smtpHost = envValue('SMTP_HOST');
    if ($smtpHost !== null) {
        $config['smtp']['host'] = $smtpHost;
    }

    $smtpPort = envInt('SMTP_PORT', null);
    if ($smtpPort !== null) {
        $config['smtp']['port'] = $smtpPort;
    }

    $smtpSecure = envValue('SMTP_SECURE');
    if ($smtpSecure !== null) {
        $config['smtp']['secure'] = strtolower($smtpSecure);
    }

    $smtpUsername = envValue('SMTP_USERNAME');
    if ($smtpUsername !== null) {
        $config['smtp']['username'] = $smtpUsername;
    }

    $smtpPassword = envValue('SMTP_PASSWORD');
    if ($smtpPassword !== null) {
        $config['smtp']['password'] = $smtpPassword;
    }

    $smtpFromEmail = envValue('SMTP_FROM_EMAIL');
    if ($smtpFromEmail !== null) {
        $config['smtp']['from_email'] = $smtpFromEmail;
    }

    $smtpFromName = envValue('SMTP_FROM_NAME');
    if ($smtpFromName !== null) {
        $config['smtp']['from_name'] = $smtpFromName;
    }

    $smtpReplyTo = envValue('SMTP_REPLY_TO');
    if ($smtpReplyTo !== null) {
        $config['smtp']['reply_to'] = $smtpReplyTo;
    }

    $smtpTimeout = envInt('SMTP_TIMEOUT', null);
    if ($smtpTimeout !== null) {
        $config['smtp']['timeout'] = $smtpTimeout;
    }

    return $config;
}

function appConfig(): array
{
    static $config = null;
    if ($config === null) {
        $baseConfig = require __DIR__ . '/../config.php';
        $config = applyEnvOverrides($baseConfig);
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
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS incidents (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            site_id INTEGER NOT NULL,
            started_at DATETIME NOT NULL,
            resolved_at DATETIME,
            duration_seconds INTEGER,
            status TEXT NOT NULL DEFAULT "open",
            start_status_code INTEGER,
            end_status_code INTEGER,
            FOREIGN KEY(site_id) REFERENCES sites(id) ON DELETE CASCADE
        )'
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_incidents_site_status ON incidents(site_id, status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_incidents_started_at ON incidents(started_at)');
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
