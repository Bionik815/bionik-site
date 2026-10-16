<?php
declare(strict_types=1);

function data_dir(): string
{
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }
    $preferred = (string) cfg('data_dir');
    if (is_dir($preferred) || @mkdir($preferred, 0750, true)) {
        return $dir = $preferred;
    }
    // Fallback inside app/, which .htaccess blocks from the web
    $fallback = __DIR__ . '/data';
    if (!is_dir($fallback)) {
        @mkdir($fallback, 0750, true);
        @file_put_contents($fallback . '/.htaccess', "Require all denied\n");
    }
    return $dir = $fallback;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $pdo = new PDO('sqlite:' . data_dir() . '/bionik-links.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA busy_timeout = 4000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void
{
    $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
    if ($version < 1) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS links (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                slug       TEXT NOT NULL UNIQUE,
                title      TEXT NOT NULL DEFAULT '',
                url        TEXT NOT NULL,
                client     TEXT NOT NULL DEFAULT '',
                notes      TEXT NOT NULL DEFAULT '',
                utm        INTEGER NOT NULL DEFAULT 0,
                active     INTEGER NOT NULL DEFAULT 1,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            );
            CREATE TABLE IF NOT EXISTS scans (
                id      INTEGER PRIMARY KEY AUTOINCREMENT,
                slug    TEXT NOT NULL,
                known   INTEGER NOT NULL DEFAULT 1,
                source  TEXT NOT NULL,
                ts      INTEGER NOT NULL,
                device  TEXT NOT NULL DEFAULT 'Other',
                visitor TEXT NOT NULL DEFAULT '',
                ua      TEXT NOT NULL DEFAULT ''
            );
            CREATE INDEX IF NOT EXISTS scans_slug_ts ON scans (slug, ts);
            CREATE INDEX IF NOT EXISTS scans_ts ON scans (ts);
            CREATE TABLE IF NOT EXISTS settings (
                key   TEXT PRIMARY KEY,
                value TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS login_attempts (
                id      INTEGER PRIMARY KEY AUTOINCREMENT,
                ip_hash TEXT NOT NULL,
                ts      INTEGER NOT NULL
            );
            PRAGMA user_version = 1;
        ");
    }
    if ($version < 2) {
        // Retiring: a retired link keeps its history and old destination,
        // but scans go to retire_to (one of your own sites) instead.
        $pdo->exec("
            ALTER TABLE links ADD COLUMN retired_at INTEGER;
            ALTER TABLE links ADD COLUMN retire_to TEXT NOT NULL DEFAULT '';
            ALTER TABLE links ADD COLUMN retire_note TEXT NOT NULL DEFAULT '';
            PRAGMA user_version = 2;
        ");
    }
}

/** Sites a retired link can send scans to, as [['name' => …, 'url' => …], …]. */
function retire_targets(): array
{
    $stored = setting('retire_targets');
    $list = $stored !== null ? json_decode($stored, true) : null;
    if (!is_array($list) || !$list) {
        $list = [
            ['name' => 'Bionik Web Designs', 'url' => 'https://bionikwebdesigns.com/'],
            ['name' => 'Barebones Apparel', 'url' => 'https://barebones-apparel.com/'],
        ];
    }
    return $list;
}

function is_retired(array $link): bool
{
    return !empty($link['retired_at']);
}

function setting(string $key): ?string
{
    $st = db()->prepare('SELECT value FROM settings WHERE key = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string) $v;
}

function set_setting(string $key, string $value): void
{
    db()->prepare('INSERT INTO settings (key, value) VALUES (?, ?)
                   ON CONFLICT(key) DO UPDATE SET value = excluded.value')
        ->execute([$key, $value]);
}

/** Per-install secret used to anonymise visitors; created on first use. */
function app_salt(): string
{
    $salt = setting('salt');
    if ($salt === null) {
        $salt = bin2hex(random_bytes(32));
        set_setting('salt', $salt);
    }
    return $salt;
}

function find_link(string $slug): ?array
{
    $st = db()->prepare('SELECT * FROM links WHERE slug = ?');
    $st->execute([strtolower($slug)]);
    $row = $st->fetch();
    return $row ?: null;
}
