<?php
declare(strict_types=1);

/**
 * Configuration. Defaults below; override any key in app/config.local.php
 * (not committed), which returns an array.
 */
function cfg(string $key, mixed $default = null): mixed
{
    static $config = null;
    if ($config === null) {
        $config = [
            // Where "/" and unknown or disabled links go
            'fallback_url'    => 'https://bionikwebdesigns.com/',
            // Private data folder. Default: next to public_html, outside the web root.
            // app -> go -> public_html -> domains/bionikwebdesigns.com/nfc-data
            'data_dir'        => dirname(__DIR__, 3) . '/nfc-data',
            // Timezone used for charts and dates in the admin
            'timezone'        => 'America/Los_Angeles',
            // Public base for tag and QR URLs, e.g. https://go.bionikwebdesigns.com
            // null = work it out from the request
            'public_base_url' => null,
            // URL path prefix, null = detect (empty on the subdomain)
            'base_path'       => null,
            'app_name'        => 'Bionik Links',
        ];
        $local = __DIR__ . '/config.local.php';
        if (is_file($local)) {
            $over = require $local;
            if (is_array($over)) {
                $config = array_merge($config, $over);
            }
        }
    }
    return $config[$key] ?? $default;
}

function h(mixed $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function valid_slug(string $slug): bool
{
    return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $slug);
}

function base_path(): string
{
    $configured = cfg('base_path');
    if ($configured !== null) {
        return rtrim((string) $configured, '/');
    }
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    return ($dir === '/' || $dir === '.' || $dir === '') ? '' : rtrim($dir, '/');
}

function request_path(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $path = rawurldecode($path);
    $base = base_path();
    if ($base !== '' && str_starts_with($path, $base)) {
        $path = substr($path, strlen($base));
    }
    return $path === '' ? '/' : $path;
}

/** Path inside the app, for links and form actions. */
function url(string $path = '/'): string
{
    return base_path() . $path;
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

function public_base(): string
{
    $configured = cfg('public_base_url');
    if ($configured) {
        return rtrim((string) $configured, '/');
    }
    $host = preg_replace('/[^A-Za-z0-9.:-]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
    return (is_https() ? 'https' : 'http') . '://' . $host . base_path();
}

/** The URL written to an NFC tag. */
function nfc_url(string $slug): string
{
    return public_base() . '/' . rawurlencode($slug);
}

/** The URL encoded in a QR code (the ?q marks the scan as QR). */
function qr_url(string $slug): string
{
    return nfc_url($slug) . '?q';
}

function send_redirect(string $to, int $code = 302): never
{
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-LiteSpeed-Cache-Control: no-cache');
    header('Location: ' . $to, true, $code);
    exit;
}

function tz(): DateTimeZone
{
    static $tz = null;
    return $tz ??= new DateTimeZone((string) cfg('timezone'));
}

function local_time(?int $ts, string $format = 'M j, Y g:i a'): string
{
    if (!$ts) {
        return '—';
    }
    return (new DateTimeImmutable('@' . $ts))->setTimezone(tz())->format($format);
}

function local_date_key(int $ts): string
{
    return (new DateTimeImmutable('@' . $ts))->setTimezone(tz())->format('Y-m-d');
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '';
}
