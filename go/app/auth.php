<?php
declare(strict_types=1);

const LOGIN_WINDOW = 900;   // 15 minutes
const LOGIN_LIMIT  = 8;     // failed attempts per window per address

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('bionik_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => (base_path() ?: '') . '/admin',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
}

function admin_exists(): bool
{
    return setting('admin_hash') !== null;
}

function is_logged_in(): bool
{
    return !empty($_SESSION['admin']) && ($_SESSION['admin_hash_ver'] ?? '') === substr((string) setting('admin_hash'), -12);
}

function require_login(): void
{
    if (!admin_exists()) {
        send_redirect(url('/admin/setup'));
    }
    if (!is_logged_in()) {
        send_redirect(url('/admin/login'));
    }
}

function csrf_token(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function check_csrf(): void
{
    $sent = (string) ($_POST['csrf'] ?? '');
    if ($sent === '' || !hash_equals(csrf_token(), $sent)) {
        http_response_code(400);
        exit('This form expired. Go back, reload the page, and try again.');
    }
}

function ip_hash(): string
{
    return substr(hash('sha256', app_salt() . '|login|' . client_ip()), 0, 24);
}

function too_many_attempts(): bool
{
    $st = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip_hash = ? AND ts > ?');
    $st->execute([ip_hash(), time() - LOGIN_WINDOW]);
    return (int) $st->fetchColumn() >= LOGIN_LIMIT;
}

function record_failed_attempt(): void
{
    db()->prepare('INSERT INTO login_attempts (ip_hash, ts) VALUES (?, ?)')->execute([ip_hash(), time()]);
    db()->prepare('DELETE FROM login_attempts WHERE ts < ?')->execute([time() - 86400]);
}

function log_in(): void
{
    session_regenerate_id(true);
    $_SESSION['admin'] = true;
    $_SESSION['admin_hash_ver'] = substr((string) setting('admin_hash'), -12);
    db()->prepare('DELETE FROM login_attempts WHERE ip_hash = ?')->execute([ip_hash()]);
}

function log_out(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
