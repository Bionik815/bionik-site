<?php
declare(strict_types=1);

const BOT_PATTERN = '/bot|crawl|spider|slurp|preview|facebookexternalhit|embedly|whatsapp|telegram|discord|skype|curl|wget|python-requests|headless/i';

function handle_redirect(string $slug, bool $isQr): never
{
    $link = null;

    try {
        $link = find_link($slug);
        log_scan($slug, $link, $isQr);
    } catch (Throwable $e) {
        // Never let a database problem stop the visitor getting where they're going
        error_log('Bionik Links: ' . $e->getMessage());
    }

    // Retired (lost tag, departed client): send scans to the chosen site of ours
    if ($link && is_retired($link) && $link['retire_to'] !== '') {
        send_redirect(add_utm(['url' => $link['retire_to'], 'utm' => 1, 'slug' => $link['slug'],
                               'client' => 'retired'] , $isQr));
    }

    if ($link && (int) $link['active'] === 1 && trim((string) $link['url']) !== '') {
        send_redirect(add_utm($link, $isQr));
    }

    $state = !$link ? 'unknown' : ((int) $link['active'] !== 1 ? 'off' : 'unassigned');
    render_tag_status($slug, $state, $isQr);
}

/**
 * Shown when a tag has nowhere to go yet: not in the admin, turned off,
 * or created without a destination. Big tag number so it's easy to
 * tell which sticker is in hand while programming.
 */
function render_tag_status(string $slug, string $state, bool $isQr): never
{
    [$headline, $detail] = match ($state) {
        'unknown' => ["This tag isn't registered", 'There is no link for this tag ID yet.'],
        'off'     => ['This tag is turned off', 'Its link exists but is switched off right now.'],
        default   => ["This tag isn't set up yet", 'It has no destination yet. Check back soon.'],
    };
    $setup = $state === 'unknown'
        ? url('/admin/new') . '?slug=' . rawurlencode($slug)
        : url('/admin/link/' . rawurlencode($slug) . '/edit');
    $home = (string) cfg('fallback_url');

    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-LiteSpeed-Cache-Control: no-cache');
    header('X-Robots-Tag: noindex, nofollow');

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#0b0d12">
<title>Tag ' . h($slug) . ' · ' . h($headline) . '</title>
<style>
  :root { --bg:#0b0d12; --ink:#eef1f6; --muted:#98a0ad; --line:#232836; --accent:#89f0c8; --warn:#f5b544; }
  * { box-sizing: border-box; }
  body { margin:0; min-height:100vh; display:grid; place-items:center; padding:24px 16px;
    background:var(--bg); color:var(--ink); font:16px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif; text-align:center; }
  .wrap { max-width: 420px; width: 100%; }
  .label { text-transform:uppercase; letter-spacing:.14em; font-size:.78rem; color:var(--muted); margin:0; }
  .tag { font:700 clamp(4.5rem, 26vw, 8.5rem)/1 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
    color:var(--accent); margin:8px 0 18px; word-break:break-all; letter-spacing:-.02em; }
  .state-off .tag, .state-unknown .tag { color: var(--warn); }
  h1 { font-size:1.35rem; margin:0 0 6px; }
  p.detail { color:var(--muted); margin:0; }
  .meta { display:inline-flex; gap:8px; margin-top:22px; flex-wrap:wrap; justify-content:center; }
  .meta span { border:1px solid var(--line); border-radius:999px; padding:4px 12px; font-size:.85rem; color:var(--muted); }
  footer { margin-top:36px; font-size:.85rem; color:var(--muted); }
  footer a { color:var(--muted); }
  .setup { display:inline-block; margin-top:4px; font-size:.8rem; color:var(--muted); opacity:.7; }
</style></head>
<body class="state-' . h($state) . '"><main class="wrap">
  <p class="label">Tag</p>
  <div class="tag">' . h($slug) . '</div>
  <h1>' . h($headline) . '</h1>
  <p class="detail">' . h($detail) . '</p>
  <div class="meta"><span>Scanned by ' . ($isQr ? 'QR code' : 'NFC') . '</span><span>' . h(local_time(time(), 'M j, g:i a')) . '</span></div>
  <footer><a href="' . h($home) . '">Bionik Web Designs</a><br>
  <a class="setup" href="' . h($setup) . '">Set up tag ' . h($slug) . '</a></footer>
</main></body></html>';
    exit;
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
