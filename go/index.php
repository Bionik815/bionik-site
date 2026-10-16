<?php
/**
 * Bionik Links — front controller for go.bionikwebdesigns.com
 *
 *   /{slug}        → log the scan (NFC by default, QR when ?q is present) and redirect
 *   /admin/...     → password-protected link manager and analytics
 *   /              → main site
 */
declare(strict_types=1);

// Local development with `php -S`: serve real static files directly.
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $file = realpath(__DIR__ . $path);
    if ($file && is_file($file) && str_starts_with($file, __DIR__ . '/assets/')) {
        return false;
    }
}

require __DIR__ . '/app/bootstrap.php';
route();
