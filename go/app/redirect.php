<?php
declare(strict_types=1);

const BOT_PATTERN = '/bot|crawl|spider|slurp|preview|facebookexternalhit|embedly|whatsapp|telegram|discord|skype|curl|wget|python-requests|headless/i';

function handle_redirect(string $slug, bool $isQr): never
{
    $link = null;
    $dest = (string) cfg('fallback_url');

    try {
        $link = find_link($slug);
        if ($link && (int) $link['active'] === 1) {
            $dest = add_utm($link, $isQr);
        }
        log_scan($slug, $link, $isQr);
    } catch (Throwable $e) {
        // Never let a database problem stop the visitor getting where they're going
        error_log('Bionik Links: ' . $e->getMessage());
        if ($link && (int) $link['active'] === 1) {
            $dest = (string) $link['url'];
        }
    }

    send_redirect($dest);
}

function add_utm(array $link, bool $isQr): string
{
    $url = (string) $link['url'];
    if ((int) $link['utm'] !== 1) {
        return $url;
    }
    $params = http_build_query([
        'utm_source'   => $isQr ? 'qr' : 'nfc',
        'utm_medium'   => $isQr ? 'qr_code' : 'nfc_tag',
        'utm_campaign' => $link['client'] !== '' ? $link['client'] : 'bionik-links',
        'utm_content'  => $link['slug'],
    ]);
    $fragment = '';
    if (($hashPos = strpos($url, '#')) !== false) {
        $fragment = substr($url, $hashPos);
        $url = substr($url, 0, $hashPos);
    }
    return $url . (str_contains($url, '?') ? '&' : '?') . $params . $fragment;
}

function log_scan(string $slug, ?array $link, bool $isQr): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return; // HEAD and others are link checkers, not people
    }
    $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300);
    if ($ua === '' || preg_match(BOT_PATTERN, $ua)) {
        return;
    }

    $device = match (true) {
        (bool) preg_match('/iPhone|iPad|iPod/i', $ua) => 'iOS',
        stripos($ua, 'Android') !== false            => 'Android',
        default                                       => 'Other',
    };

    // Anonymous visitor key: salted hash of IP + browser, rotates daily. No IPs stored.
    $visitor = substr(hash('sha256', app_salt() . '|' . client_ip() . '|' . $ua . '|' . gmdate('Y-m-d')), 0, 16);

    db()->prepare('INSERT INTO scans (slug, known, source, ts, device, visitor, ua)
                   VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$slug, $link ? 1 : 0, $isQr ? 'qr' : 'nfc', time(), $device, $visitor, $ua]);
}
