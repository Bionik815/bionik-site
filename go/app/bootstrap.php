<?php
declare(strict_types=1);

require __DIR__ . '/helpers.php';
require __DIR__ . '/db.php';
require __DIR__ . '/redirect.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/admin.php';

date_default_timezone_set('UTC');

function route(): void
{
    $path = request_path();

    if ($path === '/' || $path === '') {
        send_redirect((string) cfg('fallback_url'));
    }

    if ($path === '/admin' || str_starts_with($path, '/admin/')) {
        $sub = substr($path, strlen('/admin'));
        admin_route($sub === '' ? '/' : $sub);
        return;
    }

    $slug = trim($path, '/');
    if (!valid_slug($slug)) {
        // favicon.ico, robots probes, junk paths: send to the main site without logging
        send_redirect((string) cfg('fallback_url'));
    }

    handle_redirect(strtolower($slug), array_key_exists('q', $_GET));
}
