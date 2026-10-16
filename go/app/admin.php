<?php
declare(strict_types=1);

const PERIODS = [7 => '7 days', 30 => '30 days', 90 => '90 days', 365 => '1 year', 0 => 'All time'];

function admin_route(string $sub): void
{
    header('Cache-Control: no-store');
    header('X-LiteSpeed-Cache-Control: no-cache');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Robots-Tag: noindex, nofollow');

    start_session();
    $post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    if ($post) {
        check_csrf();
    }

    $sub = rtrim($sub, '/') ?: '/';

    switch (true) {
        case $sub === '/setup':
            page_setup($post);
            return;
        case $sub === '/login':
            page_login($post);
            return;
        case $sub === '/logout' && $post:
            log_out();
            send_redirect(url('/admin/login'));
    }

    require_login();

    if ($sub === '/') {
        page_dashboard();
    } elseif ($sub === '/new') {
        page_link_form(null, $post);
    } elseif ($sub === '/bulk') {
        page_bulk($post);
    } elseif ($sub === '/export') {
        export_csv();
    } elseif ($sub === '/password') {
        page_password($post);
    } elseif ($sub === '/qr') {
        page_qr_sheet();
    } elseif (preg_match('#^/link/([a-z0-9][a-z0-9_-]{0,63})(/edit|/clear|/delete)?$#', $sub, $m)) {
        $link = find_link($m[1]);
        $action = $m[2] ?? '';
        if (!$link && $action !== '') {
            not_found();
        }
        match ($action) {
            '/edit'   => page_link_form($link, $post),
            '/clear'  => $post ? clear_scans($m[1]) : not_found(),
            '/delete' => $post ? delete_link($link) : not_found(),
            default   => page_link_detail($m[1], $link),
        };
    } else {
        not_found();
    }
}

function not_found(): never
{
    http_response_code(404);
    layout('Not found', '<section class="card"><h1>Not found</h1><p><a href="' . h(url('/admin')) . '">Back to links</a></p></section>');
    exit;
}

/* ------------------------------------------------------------------ layout */

function layout(string $title, string $body, bool $nav = true, array $extraScripts = []): void
{
    $app = h(cfg('app_name'));
    $css = h(url('/assets/admin.css'));
    $js  = h(url('/assets/admin.js'));
    $qr  = h(url('/assets/qrcode.js'));
    $extra = '';
    foreach ($extraScripts as $s) {
        $extra .= '<script src="' . h(url('/assets/' . $s)) . '"></script>';
    }
    $navHtml = '';
    if ($nav && is_logged_in()) {
        $navHtml = '<nav class="top">
            <a class="brand" href="' . h(url('/admin')) . '">' . $app . '</a>
            <div class="nav-links">
              <a href="' . h(url('/admin')) . '">Links</a>
              <a href="' . h(url('/admin/new')) . '">New link</a>
              <a href="' . h(url('/admin/bulk')) . '">Bulk create</a>
              <a href="' . h(url('/admin/qr')) . '">QR codes</a>
              <a href="' . h(url('/admin/password')) . '">Password</a>
              <form method="post" action="' . h(url('/admin/logout')) . '">' . csrf_field() . '<button class="link">Sign out</button></form>
            </div>
          </nav>';
    }
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>' . h($title) . ' · ' . $app . '</title>
<link rel="stylesheet" href="' . $css . '">
</head><body>' . $navHtml . '<main>' . flash_html() . $body . '</main>
<script src="' . $qr . '"></script>' . $extra . '<script src="' . $js . '"></script>
</body></html>';
}

function flash(string $msg, string $kind = 'ok'): void
{
    $_SESSION['flash'] = [$msg, $kind];
}

function flash_html(): string
{
    if (empty($_SESSION['flash'])) {
        return '';
    }
    [$msg, $kind] = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return '<div class="flash ' . h($kind) . '">' . h($msg) . '</div>';
}

function selected_period(): int
{
    $d = (int) ($_GET['days'] ?? 30);
    return array_key_exists($d, PERIODS) ? $d : 30;
}

function period_since(int $days): int
{
    return $days === 0 ? 0 : time() - $days * 86400;
}

function period_tabs(int $days, array $extra = []): string
{
    $out = '<div class="tabs">';
    foreach (PERIODS as $d => $label) {
        $q = http_build_query(array_filter(['days' => $d] + $extra, fn($v) => $v !== '' && $v !== null));
        $out .= '<a class="' . ($d === $days ? 'on' : '') . '" href="?' . h($q) . '">' . h($label) . '</a>';
    }
    return $out . '</div>';
}

/* ------------------------------------------------------------------ auth pages */

function page_setup(bool $post): void
{
    if (admin_exists()) {
        send_redirect(url('/admin/login'));
    }
    $error = '';
    if ($post) {
        $user = trim((string) ($_POST['user'] ?? ''));
        $pass = (string) ($_POST['pass'] ?? '');
        $confirm = (string) ($_POST['confirm'] ?? '');
        if (!preg_match('/^[A-Za-z0-9._@-]{3,64}$/', $user)) {
            $error = 'Username: 3–64 letters, numbers, or . _ @ -';
        } elseif (strlen($pass) < 12) {
            $error = 'Use a password of at least 12 characters.';
        } elseif (!hash_equals($pass, $confirm)) {
            $error = 'The passwords don\'t match.';
        } else {
            set_setting('admin_user', $user);
            set_setting('admin_hash', password_hash($pass, PASSWORD_DEFAULT));
            log_in();
            flash('Admin account created. Add your first link.');
            send_redirect(url('/admin'));
        }
    }
    layout('Set up', '<section class="card narrow">
      <h1>Create your admin login</h1>
      <p class="muted">This page only works until an account exists. Do this right after the first deploy.</p>
      ' . ($error ? '<div class="flash err">' . h($error) . '</div>' : '') . '
      <form method="post">' . csrf_field() . '
        <label>Username <input name="user" required autocomplete="username" value="' . h($_POST['user'] ?? '') . '"></label>
        <label>Password <input type="password" name="pass" required minlength="12" autocomplete="new-password"></label>
        <label>Confirm password <input type="password" name="confirm" required minlength="12" autocomplete="new-password"></label>
        <button class="primary">Create account</button>
      </form></section>', false);
}

function page_login(bool $post): void
{
    if (!admin_exists()) {
        send_redirect(url('/admin/setup'));
    }
    if (is_logged_in()) {
        send_redirect(url('/admin'));
    }
    $error = '';
    if ($post) {
        if (too_many_attempts()) {
            $error = 'Too many attempts. Wait 15 minutes and try again.';
        } else {
            $user = (string) ($_POST['user'] ?? '');
            $pass = (string) ($_POST['pass'] ?? '');
            if (hash_equals((string) setting('admin_user'), $user) && password_verify($pass, (string) setting('admin_hash'))) {
                log_in();
                send_redirect(url('/admin'));
            }
            record_failed_attempt();
            usleep(400000);
            $error = 'Wrong username or password.';
        }
    }
    layout('Sign in', '<section class="card narrow">
      <h1>' . h(cfg('app_name')) . '</h1>
      ' . ($error ? '<div class="flash err">' . h($error) . '</div>' : '') . '
      <form method="post">' . csrf_field() . '
        <label>Username <input name="user" required autocomplete="username" autofocus></label>
        <label>Password <input type="password" name="pass" required autocomplete="current-password"></label>
        <button class="primary">Sign in</button>
      </form></section>', false);
}

function page_password(bool $post): void
{
    $error = '';
    if ($post) {
        $current = (string) ($_POST['current'] ?? '');
        $pass = (string) ($_POST['pass'] ?? '');
        if (!password_verify($current, (string) setting('admin_hash'))) {
            $error = 'Current password is wrong.';
        } elseif (strlen($pass) < 12) {
            $error = 'Use a password of at least 12 characters.';
        } elseif (!hash_equals($pass, (string) ($_POST['confirm'] ?? ''))) {
            $error = 'The new passwords don\'t match.';
        } else {
            set_setting('admin_hash', password_hash($pass, PASSWORD_DEFAULT));
            log_in();
            flash('Password changed.');
            send_redirect(url('/admin'));
        }
    }
    layout('Password', '<section class="card narrow"><h1>Change password</h1>
      ' . ($error ? '<div class="flash err">' . h($error) . '</div>' : '') . '
      <form method="post">' . csrf_field() . '
        <label>Current password <input type="password" name="current" required autocomplete="current-password"></label>
        <label>New password <input type="password" name="pass" required minlength="12" autocomplete="new-password"></label>
        <label>Confirm new password <input type="password" name="confirm" required minlength="12" autocomplete="new-password"></label>
        <button class="primary">Change password</button>
      </form></section>');
}

/* ------------------------------------------------------------------ stats */

/** Per-slug totals since a timestamp, optionally for one slug. */
function scan_totals(int $since, ?string $slug = null): array
{
    $sql = "SELECT slug, MAX(known) AS known, COUNT(*) AS total,
                   SUM(source = 'nfc') AS nfc, SUM(source = 'qr') AS qr,
                   SUM(device = 'iOS') AS ios, SUM(device = 'Android') AS android,
                   COUNT(DISTINCT visitor) AS uniq, MAX(ts) AS last
            FROM scans WHERE ts >= ?" . ($slug !== null ? ' AND slug = ?' : '') . ' GROUP BY slug';
    $st = db()->prepare($sql);
    $st->execute($slug !== null ? [$since, $slug] : [$since]);
    $out = [];
    foreach ($st as $r) {
        $out[$r['slug']] = $r;
    }
    return $out;
}

/** Daily counts split by source, in the admin timezone. */
function daily_series(int $days, ?array $slugs = null): array
{
    $span = $days === 0 ? 90 : min($days, 365);
    $start = (new DateTimeImmutable('now', tz()))->setTime(0, 0)->modify('-' . ($span - 1) . ' days');
    $series = [];
    for ($i = 0; $i < $span; $i++) {
        $series[$start->modify("+$i days")->format('Y-m-d')] = ['nfc' => 0, 'qr' => 0];
    }
    $sql = 'SELECT ts, source FROM scans WHERE ts >= ?';
    $args = [$start->getTimestamp()];
    if ($slugs !== null) {
        if (!$slugs) {
            return $series;
        }
        $sql .= ' AND slug IN (' . implode(',', array_fill(0, count($slugs), '?')) . ')';
        $args = array_merge($args, $slugs);
    }
    $st = db()->prepare($sql);
    $st->execute($args);
    foreach ($st as $r) {
        $key = local_date_key((int) $r['ts']);
        if (isset($series[$key])) {
            $series[$key][$r['source'] === 'qr' ? 'qr' : 'nfc']++;
        }
    }
    return $series;
}

/** Stacked daily bar chart (NFC + QR) as inline SVG. */
function chart_svg(array $series, string $label): string
{
    $n = count($series);
    $max = 0;
    foreach ($series as $v) {
        $max = max($max, $v['nfc'] + $v['qr']);
    }
    $W = 720; $H = 180; $left = 36; $bottom = 24; $top = 12;
    $plotW = $W - $left - 8; $plotH = $H - $bottom - $top;
    $step = $plotW / max(1, $n);
    $barW = max(1.5, $step * 0.72);
    $scaleMax = max(4, (int) ceil($max / 4) * 4);
    $y = fn($v) => $top + $plotH - ($v / $scaleMax) * $plotH;

    $svg = '<svg class="chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="' . h($label) . '">';
    for ($i = 0; $i <= 4; $i++) {
        $val = $scaleMax * $i / 4;
        $yy = round($y($val), 1);
        $svg .= '<line x1="' . $left . '" x2="' . ($W - 8) . '" y1="' . $yy . '" y2="' . $yy . '" class="grid"/>';
        $svg .= '<text x="' . ($left - 6) . '" y="' . ($yy + 4) . '" class="tick" text-anchor="end">' . (int) $val . '</text>';
    }
    $i = 0;
    $keys = array_keys($series);
    foreach ($series as $date => $v) {
        $x = round($left + $i * $step + ($step - $barW) / 2, 2);
        $title = h(local_label($date) . ': ' . $v['nfc'] . ' NFC, ' . $v['qr'] . ' QR');
        $svg .= '<g><title>' . $title . '</title>';
        if ($v['nfc'] > 0) {
            $svg .= '<rect class="bar-nfc" x="' . $x . '" y="' . round($y($v['nfc']), 2) . '" width="' . round($barW, 2) . '" height="' . round($plotH * $v['nfc'] / $scaleMax, 2) . '" rx="1.5"/>';
        }
        if ($v['qr'] > 0) {
            $svg .= '<rect class="bar-qr" x="' . $x . '" y="' . round($y($v['nfc'] + $v['qr']), 2) . '" width="' . round($barW, 2) . '" height="' . round($plotH * $v['qr'] / $scaleMax, 2) . '" rx="1.5"/>';
        }
        $svg .= '<rect x="' . round($left + $i * $step, 2) . '" y="' . $top . '" width="' . round($step, 2) . '" height="' . $plotH . '" fill="transparent"/></g>';
        $i++;
    }
    $svg .= '<text x="' . $left . '" y="' . ($H - 6) . '" class="tick">' . h(local_label($keys[0])) . '</text>';
    $svg .= '<text x="' . ($W - 8) . '" y="' . ($H - 6) . '" class="tick" text-anchor="end">' . h(local_label(end($keys))) . '</text>';
    return $svg . '</svg><div class="legend"><span><i class="sw-nfc"></i>NFC</span><span><i class="sw-qr"></i>QR</span></div>';
}

function local_label(string $ymd): string
{
    return DateTimeImmutable::createFromFormat('Y-m-d', $ymd, tz())->format('M j');
}

/* ------------------------------------------------------------------ dashboard */

function page_dashboard(): void
{
    $days = selected_period();
    $client = trim((string) ($_GET['client'] ?? ''));
    $search = trim((string) ($_GET['q'] ?? ''));
    $since = period_since($days);

    $links = db()->query('SELECT * FROM links ORDER BY slug')->fetchAll();
    $clients = array_values(array_unique(array_filter(array_column($links, 'client'))));
    sort($clients, SORT_NATURAL | SORT_FLAG_CASE);

    $visible = array_filter($links, function ($l) use ($client, $search) {
        if ($client !== '' && $l['client'] !== $client) {
            return false;
        }
        if ($search !== '') {
            $hay = strtolower($l['slug'] . ' ' . $l['title'] . ' ' . $l['url'] . ' ' . $l['client'] . ' ' . $l['notes']);
            return str_contains($hay, strtolower($search));
        }
        return true;
    });

    $totals = scan_totals($since);
    $sum = ['total' => 0, 'nfc' => 0, 'qr' => 0];
    foreach ($visible as $l) {
        foreach ($sum as $k => $_) {
            $sum[$k] += (int) ($totals[$l['slug']][$k] ?? 0);
        }
    }
    $unknown = array_filter($totals, fn($t) => (int) $t['known'] === 0);

    $filterQs = ['client' => $client, 'q' => $search];
    $exportQs = http_build_query(array_filter(['days' => $days, 'client' => $client]));

    $clientOpts = '<option value="">All clients</option>';
    foreach ($clients as $c) {
        $clientOpts .= '<option' . ($c === $client ? ' selected' : '') . '>' . h($c) . '</option>';
    }

    usort($visible, fn($a, $b) => ((int) ($totals[$b['slug']]['total'] ?? 0)) <=> ((int) ($totals[$a['slug']]['total'] ?? 0)) ?: strnatcmp($a['slug'], $b['slug']));

    $rows = '';
    foreach ($visible as $l) {
        $t = $totals[$l['slug']] ?? [];
        $rows .= '<tr' . ((int) $l['active'] ? '' : ' class="off"') . '>
          <td><a class="slug" href="' . h(url('/admin/link/' . $l['slug'])) . '">' . h($l['slug']) . '</a>' . ((int) $l['active'] ? '' : ' <span class="pill">off</span>') . '</td>
          <td>' . h($l['title']) . '<div class="dest">' . ($l['url'] !== '' ? h(short_url($l['url'])) : '<span class="pill warn">not set up</span>') . '</div></td>
          <td>' . h($l['client']) . '</td>
          <td class="n">' . (int) ($t['total'] ?? 0) . '</td>
          <td class="n">' . (int) ($t['nfc'] ?? 0) . '</td>
          <td class="n">' . (int) ($t['qr'] ?? 0) . '</td>
          <td class="when">' . h(local_time(isset($t['last']) ? (int) $t['last'] : null, 'M j g:i a')) . '</td>
        </tr>';
    }
    if ($rows === '') {
        $rows = '<tr><td colspan="7" class="empty">' . ($links ? 'No links match.' : 'No links yet. <a href="' . h(url('/admin/new')) . '">Create one</a> or <a href="' . h(url('/admin/bulk')) . '">bulk create 001–050</a>.') . '</td></tr>';
    }

    $unknownHtml = '';
    if ($unknown) {
        $items = '';
        foreach ($unknown as $u) {
            $items .= '<li><code>' . h($u['slug']) . '</code> · ' . (int) $u['total'] . ' scans · last ' . h(local_time((int) $u['last'], 'M j g:i a')) . ' · <a href="' . h(url('/admin/new') . '?slug=' . rawurlencode($u['slug'])) . '">create link</a></li>';
        }
        $unknownHtml = '<section class="card"><h2>Scans of unknown tags</h2><p class="muted">These tags were scanned but have no link, so visitors saw the "isn\'t registered" page with the tag number.</p><ul class="plain">' . $items . '</ul></section>';
    }

    $chart = chart_svg(daily_series($days, ($client !== '' || $search !== '') ? array_column($visible, 'slug') : null), 'Daily scans');

    layout('Links', '
      <div class="head">
        <h1>Links' . ($client !== '' ? ' · ' . h($client) : '') . '</h1>
        ' . period_tabs($days, $filterQs) . '
      </div>
      <div class="kpis">
        <div><span>Scans</span><strong>' . $sum['total'] . '</strong></div>
        <div><span>NFC taps</span><strong>' . $sum['nfc'] . '</strong></div>
        <div><span>QR scans</span><strong>' . $sum['qr'] . '</strong></div>
        <div><span>Links</span><strong>' . count($visible) . '</strong></div>
      </div>
      <section class="card">' . $chart . '</section>
      <section class="card">
        <form class="filters" method="get">
          <input type="hidden" name="days" value="' . $days . '">
          <select name="client" onchange="this.form.submit()">' . $clientOpts . '</select>
          <input name="q" placeholder="Search links" value="' . h($search) . '">
          <button>Filter</button>
          <a class="button" href="' . h(url('/admin/export') . ($exportQs ? '?' . $exportQs : '')) . '">Download CSV</a>
          <a class="button" href="' . h(url('/admin/qr') . ($client !== '' ? '?client=' . rawurlencode($client) : '')) . '">QR codes</a>
        </form>
        <div class="wrap"><table>
          <thead><tr><th>Tag</th><th>Label / destination</th><th>Client</th><th class="n">Scans</th><th class="n">NFC</th><th class="n">QR</th><th>Last scan</th></tr></thead>
          <tbody>' . $rows . '</tbody>
        </table></div>
      </section>' . $unknownHtml);
}

/* ------------------------------------------------------------------ QR sheet */

function page_qr_sheet(): void
{
    $client = trim((string) ($_GET['client'] ?? ''));
    $search = trim((string) ($_GET['q'] ?? ''));
    $includeOff = isset($_GET['all']);

    $links = db()->query('SELECT slug, title, client, notes, url, active FROM links ORDER BY slug')->fetchAll();
    $clients = array_values(array_unique(array_filter(array_column($links, 'client'))));
    sort($clients, SORT_NATURAL | SORT_FLAG_CASE);

    $chosen = array_values(array_filter($links, function ($l) use ($client, $search, $includeOff) {
        if (!$includeOff && (int) $l['active'] !== 1) {
            return false;
        }
        if ($client !== '' && $l['client'] !== $client) {
            return false;
        }
        if ($search !== '') {
            return str_contains(strtolower($l['slug'] . ' ' . $l['title'] . ' ' . $l['client'] . ' ' . $l['notes']), strtolower($search));
        }
        return true;
    }));
    usort($chosen, fn($a, $b) => strnatcmp($a['slug'], $b['slug']));

    $data = array_map(fn($l) => [
        'slug'   => $l['slug'],
        'title'  => $l['title'],
        'client' => $l['client'],
        'qr'     => qr_url($l['slug']),
        'nfc'    => nfc_url($l['slug']),
        'dest'   => $l['url'],
    ], $chosen);
    $json = json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);

    $clientOpts = '<option value="">All clients</option>';
    foreach ($clients as $c) {
        $clientOpts .= '<option' . ($c === $client ? ' selected' : '') . '>' . h($c) . '</option>';
    }
    $zipName = 'bionik-qr-' . ($client !== '' ? preg_replace('/[^a-z0-9-]+/i', '-', strtolower($client)) : 'all') . '-' . local_time(time(), 'Y-m-d');

    layout('QR codes', '
      <div class="head no-print">
        <h1>QR codes' . ($client !== '' ? ' · ' . h($client) : '') . '</h1>
        <span class="muted">' . count($data) . ' code' . (count($data) === 1 ? '' : 's') . '</span>
      </div>
      <section class="card no-print">
        <form class="filters" method="get">
          <select name="client" onchange="this.form.submit()">' . $clientOpts . '</select>
          <input name="q" placeholder="Filter by tag, label, or notes" value="' . h($search) . '">
          <label class="check inline"><input type="checkbox" name="all"' . ($includeOff ? ' checked' : '') . ' onchange="this.form.submit()"> Include turned-off links</label>
          <button>Filter</button>
        </form>
        <div class="sheet-tools">
          <div class="tool">
            <h2>Download ZIP</h2>
            <p class="muted">One SVG and one PNG per code, plus a CSV listing each tag and URL.</p>
            <div class="row-inline">
              <label>PNG size
                <select id="qr-png-size">
                  <option value="1000">1000 × 1000</option>
                  <option value="2000" selected>2000 × 2000</option>
                  <option value="3000">3000 × 3000</option>
                  <option value="4000">4000 × 4000</option>
                </select>
              </label>
              <button type="button" class="primary" id="qr-zip"' . ($data ? '' : ' disabled') . '>Download ZIP</button>
            </div>
            <p class="muted small" id="qr-zip-status" aria-live="polite"></p>
          </div>
          <div class="tool">
            <h2>Print sheet</h2>
            <p class="muted">Labeled grid of every code below. Print or save as PDF.</p>
            <div class="row-inline">
              <label>Per row
                <select id="qr-cols">
                  <option value="2">2</option><option value="3">3</option>
                  <option value="4" selected>4</option><option value="5">5</option><option value="6">6</option>
                </select>
              </label>
              <label class="check inline"><input type="checkbox" id="qr-labels" checked> Labels</label>
              <label class="check inline"><input type="checkbox" id="qr-urls"> URLs</label>
              <button type="button" id="qr-print"' . ($data ? '' : ' disabled') . '>Print</button>
            </div>
          </div>
        </div>
      </section>
      ' . ($data ? '' : '<section class="card"><p class="empty">No links match. <a href="' . h(url('/admin/bulk')) . '">Bulk create some</a>.</p></section>') . '
      <div class="qr-grid" id="qr-grid" style="--cols:4"></div>
      <script type="application/json" id="qr-sheet-data" data-zip-name="' . h($zipName) . '">' . $json . '</script>', true, ['jszip.min.js']);
}

function short_url(string $u): string
{
    $u = preg_replace('#^https?://(www\.)?#i', '', $u);
    return strlen($u) > 60 ? substr($u, 0, 57) . '…' : $u;
}

/* ------------------------------------------------------------------ link detail */

function page_link_detail(string $slug, ?array $link): void
{
    $days = selected_period();
    $since = period_since($days);
    $t = scan_totals($since, $slug)[$slug] ?? [];
    if (!$link && !$t) {
        not_found();
    }

    $st = db()->prepare('SELECT ts, source, device FROM scans WHERE slug = ? ORDER BY ts DESC LIMIT 25');
    $st->execute([$slug]);
    $recent = '';
    foreach ($st as $r) {
        $recent .= '<tr><td>' . h(local_time((int) $r['ts'])) . '</td><td>' . strtoupper(h($r['source'])) . '</td><td>' . h($r['device']) . '</td></tr>';
    }
    if ($recent === '') {
        $recent = '<tr><td colspan="3" class="empty">No scans yet.</td></tr>';
    }

    $nfc = nfc_url($slug);
    $qr  = qr_url($slug);
    $total = (int) ($t['total'] ?? 0);

    $info = $link ? '
        <dl class="facts">
          <dt>Destination</dt><dd>' . ($link['url'] !== '' ? '<a href="' . h($link['url']) . '" target="_blank" rel="noopener">' . h($link['url']) . '</a>' : 'Not set up yet — scans show the tag number page') . '</dd>
          <dt>Client</dt><dd>' . h($link['client'] ?: '—') . '</dd>
          <dt>Status</dt><dd>' . ((int) $link['active'] ? 'Active' : 'Off — scans show a "turned off" page') . '</dd>
          <dt>UTM tags</dt><dd>' . ((int) $link['utm'] ? 'Added to the destination' : 'No') . '</dd>
          ' . ($link['notes'] !== '' ? '<dt>Notes</dt><dd>' . nl2br(h($link['notes'])) . '</dd>' : '') . '
          <dt>Created</dt><dd>' . h(local_time((int) $link['created_at'])) . '</dd>
        </dl>
        <div class="actions"><a class="button primary" href="' . h(url('/admin/link/' . $slug . '/edit')) . '">Edit link</a></div>'
        : '<p class="flash warn">No link exists for this tag, so scans show an "isn\'t registered" page. <a href="' . h(url('/admin/new') . '?slug=' . rawurlencode($slug)) . '">Create it</a>.</p>';

    $danger = '<section class="card danger"><h2>Maintenance</h2>
        <form method="post" action="' . h(url('/admin/link/' . $slug . '/clear')) . '" data-confirm="Delete all ' . $total . ' scans recorded for ' . h($slug) . '? This can\'t be undone.">' . csrf_field() . '<button>Clear scan history</button> <span class="muted">Use after testing a tag.</span></form>'
        . ($link ? '<form method="post" action="' . h(url('/admin/link/' . $slug . '/delete')) . '" data-confirm="Delete link ' . h($slug) . '? Tags pointing here will show an &quot;isn&#39;t registered&quot; page. Scan history is kept.">' . csrf_field() . '<button>Delete link</button> <span class="muted">Turning it off in Edit is usually better.</span></form>' : '')
        . '</section>';

    layout($slug, '
      <div class="head">
        <h1><span class="slug big">' . h($slug) . '</span> ' . h($link['title'] ?? '') . '</h1>
        ' . period_tabs($days) . '
      </div>
      <div class="kpis">
        <div><span>Scans</span><strong>' . $total . '</strong></div>
        <div><span>NFC / QR</span><strong>' . (int) ($t['nfc'] ?? 0) . ' / ' . (int) ($t['qr'] ?? 0) . '</strong></div>
        <div><span>iOS / Android</span><strong>' . (int) ($t['ios'] ?? 0) . ' / ' . (int) ($t['android'] ?? 0) . '</strong></div>
        <div><span>Last scan</span><strong class="small">' . h(local_time(isset($t['last']) ? (int) $t['last'] : null, 'M j g:i a')) . '</strong></div>
      </div>
      <section class="card">' . chart_svg(daily_series($days, [$slug]), 'Daily scans for ' . $slug) . '</section>
      <div class="cols">
        <section class="card">' . $info . '</section>
        <section class="card">
          <h2>Write to NFC tag</h2>
          <div class="copy"><code>' . h($nfc) . '</code><button type="button" data-copy="' . h($nfc) . '">Copy</button></div>
          <p class="muted">' . strlen($nfc) . ' characters · NTAG213 holds about 130.</p>
          <h2>QR code</h2>
          <div class="qr" data-qr="' . h($qr) . '" data-name="' . h($slug) . '"></div>
          <div class="copy"><code>' . h($qr) . '</code><button type="button" data-copy="' . h($qr) . '">Copy</button></div>
          <div class="actions"><button type="button" data-qr-download="svg">Download SVG</button><button type="button" data-qr-download="png">Download PNG (2000px)</button></div>
        </section>
      </div>
      <section class="card"><h2>Recent scans</h2><div class="wrap"><table>
        <thead><tr><th>Time (' . h(cfg('timezone')) . ')</th><th>Type</th><th>Device</th></tr></thead>
        <tbody>' . $recent . '</tbody></table></div></section>' . $danger);
}

/* ------------------------------------------------------------------ create / edit */

function link_input(?array $existing): array
{
    return [
        'slug'   => strtolower(trim((string) ($_POST['slug'] ?? ($existing['slug'] ?? '')))),
        'title'  => trim((string) ($_POST['title'] ?? '')),
        'url'    => trim((string) ($_POST['url'] ?? '')),
        'client' => trim((string) ($_POST['client'] ?? '')),
        'notes'  => trim((string) ($_POST['notes'] ?? '')),
        'utm'    => isset($_POST['utm']) ? 1 : 0,
        'active' => isset($_POST['active']) ? 1 : 0,
    ];
}

function valid_destination(string $url): bool
{
    if ($url === '') {
        return true; // no destination yet: the tag shows its "not set up" page
    }
    return (bool) filter_var($url, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $url);
}

function page_link_form(?array $link, bool $post): void
{
    $editing = $link !== null;
    $v = $link ?? ['slug' => strtolower((string) ($_GET['slug'] ?? '')), 'title' => '', 'url' => '', 'client' => '', 'notes' => '', 'utm' => 0, 'active' => 1];
    $error = '';

    if ($post) {
        $v = link_input($link);
        if ($editing) {
            $v['slug'] = $link['slug']; // the slug is printed on tags; never change it
        }
        if (!valid_slug($v['slug'])) {
            $error = 'Tag ID: letters, numbers, - or _, up to 64 characters.';
        } elseif (in_array($v['slug'], ['admin', 'assets', 'app'], true)) {
            $error = 'That tag ID is reserved.';
        } elseif (!valid_destination($v['url'])) {
            $error = 'Destination must be a full http:// or https:// URL, or left blank.';
        } elseif (!$editing && find_link($v['slug'])) {
            $error = 'A link with that tag ID already exists.';
        } else {
            $now = time();
            if ($editing) {
                db()->prepare('UPDATE links SET title=?, url=?, client=?, notes=?, utm=?, active=?, updated_at=? WHERE slug=?')
                    ->execute([$v['title'], $v['url'], $v['client'], $v['notes'], $v['utm'], $v['active'], $now, $v['slug']]);
                flash('Saved. Scans of ' . $v['slug'] . ' now go to the new destination.');
            } else {
                db()->prepare('INSERT INTO links (slug, title, url, client, notes, utm, active, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)')
                    ->execute([$v['slug'], $v['title'], $v['url'], $v['client'], $v['notes'], $v['utm'], $v['active'], $now, $now]);
                db()->prepare('UPDATE scans SET known = 1 WHERE slug = ?')->execute([$v['slug']]);
                flash('Link ' . $v['slug'] . ' created.');
            }
            send_redirect(url('/admin/link/' . $v['slug']));
        }
    }

    $clients = db()->query("SELECT DISTINCT client FROM links WHERE client <> '' ORDER BY client")->fetchAll(PDO::FETCH_COLUMN);
    $dl = '<datalist id="clients">';
    foreach ($clients as $c) {
        $dl .= '<option value="' . h($c) . '">';
    }
    $dl .= '</datalist>';

    layout($editing ? 'Edit ' . $link['slug'] : 'New link', '<section class="card narrow">
      <h1>' . ($editing ? 'Edit <span class="slug big">' . h($link['slug']) . '</span>' : 'New link') . '</h1>
      ' . ($error ? '<div class="flash err">' . h($error) . '</div>' : '') . '
      <form method="post">' . csrf_field() . '
        <label>Tag ID
          <input name="slug" required pattern="[A-Za-z0-9][A-Za-z0-9_\-]{0,63}" value="' . h($v['slug']) . '"' . ($editing ? ' readonly' : ' placeholder="e.g. 001 or acme-ig"') . '>
          <small>' . ($editing ? 'Fixed, because it\'s written on the tag. Change the destination instead.' : 'Becomes ' . h(public_base()) . '/<em>tag-id</em>') . '</small>
        </label>
        <label>Destination URL <input name="url" type="url" value="' . h($v['url']) . '" placeholder="https://instagram.com/handle">
          <small>Leave blank and the tag shows a "Tag ' . h($v['slug'] ?: '###') . ' isn\'t set up yet" page.</small></label>
        <label>Label <input name="title" value="' . h($v['title']) . '" placeholder="Booth table sticker"></label>
        <label>Client <input name="client" list="clients" value="' . h($v['client']) . '" placeholder="client-a">' . $dl . '</label>
        <label>Notes <textarea name="notes" rows="3">' . h($v['notes']) . '</textarea></label>
        <label class="check"><input type="checkbox" name="utm"' . ((int) $v['utm'] ? ' checked' : '') . '> Add UTM tags (for store and website analytics)</label>
        <label class="check"><input type="checkbox" name="active"' . ((int) $v['active'] ? ' checked' : '') . '> Active</label>
        <div class="actions"><button class="primary">' . ($editing ? 'Save' : 'Create link') . '</button>
        <a href="' . h($editing ? url('/admin/link/' . $link['slug']) : url('/admin')) . '">Cancel</a></div>
      </form></section>');
}

function page_bulk(bool $post): void
{
    $v = ['prefix' => '', 'from' => 1, 'to' => 50, 'pad' => 3, 'url' => '', 'client' => '', 'title' => 'NFC tag {n}'];
    $error = '';
    if ($post) {
        $v = [
            'prefix' => strtolower(trim((string) ($_POST['prefix'] ?? ''))),
            'from'   => (int) ($_POST['from'] ?? 1),
            'to'     => (int) ($_POST['to'] ?? 1),
            'pad'    => max(0, min(6, (int) ($_POST['pad'] ?? 3))),
            'url'    => trim((string) ($_POST['url'] ?? '')),
            'client' => trim((string) ($_POST['client'] ?? '')),
            'title'  => trim((string) ($_POST['title'] ?? '')),
        ];
        if ($v['from'] < 0 || $v['to'] < $v['from'] || $v['to'] - $v['from'] >= 500) {
            $error = 'Choose a range of up to 500 numbers.';
        } elseif ($v['prefix'] !== '' && !preg_match('/^[a-z0-9][a-z0-9_-]{0,40}$/', $v['prefix'])) {
            $error = 'Prefix: letters, numbers, - or _.';
        } elseif (!valid_destination($v['url'])) {
            $error = 'Destination must be a full http:// or https:// URL, or left blank.';
        } else {
            $made = 0; $skipped = 0; $now = time();
            $ins = db()->prepare('INSERT OR IGNORE INTO links (slug, title, url, client, notes, utm, active, created_at, updated_at) VALUES (?,?,?,?,?,0,1,?,?)');
            db()->beginTransaction();
            for ($i = $v['from']; $i <= $v['to']; $i++) {
                $num = str_pad((string) $i, $v['pad'], '0', STR_PAD_LEFT);
                $slug = $v['prefix'] . $num;
                $ins->execute([$slug, str_replace('{n}', $num, $v['title']), $v['url'], $v['client'], '', $now, $now]);
                $ins->rowCount() ? $made++ : $skipped++;
            }
            db()->commit();
            flash("Created $made links" . ($skipped ? ", skipped $skipped that already existed" : '') . '. Until you set a destination, each one shows its tag number when scanned.');
            send_redirect(url('/admin'));
        }
    }
    $sample = h($v['prefix'] . str_pad((string) $v['from'], $v['pad'], '0', STR_PAD_LEFT));
    layout('Bulk create', '<section class="card narrow">
      <h1>Bulk create links</h1>
      <p class="muted">Makes a numbered run of links. Write the tags now and set each destination later; until then, scanning a tag shows a page with its number.</p>
      ' . ($error ? '<div class="flash err">' . h($error) . '</div>' : '') . '
      <form method="post">' . csrf_field() . '
        <div class="row">
          <label>Prefix <input name="prefix" value="' . h($v['prefix']) . '" placeholder="optional, e.g. acme-"></label>
          <label>From <input name="from" type="number" min="0" value="' . (int) $v['from'] . '"></label>
          <label>To <input name="to" type="number" min="0" value="' . (int) $v['to'] . '"></label>
          <label>Digits <input name="pad" type="number" min="0" max="6" value="' . (int) $v['pad'] . '"></label>
        </div>
        <small>First tag ID: <code>' . $sample . '</code>. Existing IDs are skipped.</small>
        <label>Destination (optional) <input name="url" type="url" value="' . h($v['url']) . '" placeholder="Leave blank to set each one later"></label>
        <label>Label <input name="title" value="' . h($v['title']) . '"><small>{n} becomes the number.</small></label>
        <label>Client <input name="client" value="' . h($v['client']) . '"></label>
        <div class="actions"><button class="primary">Create links</button></div>
      </form></section>');
}

function clear_scans(string $slug): never
{
    db()->prepare('DELETE FROM scans WHERE slug = ?')->execute([$slug]);
    flash('Scan history for ' . $slug . ' cleared.');
    send_redirect(url('/admin/link/' . $slug));
}

function delete_link(array $link): never
{
    db()->prepare('DELETE FROM links WHERE slug = ?')->execute([$link['slug']]);
    db()->prepare('UPDATE scans SET known = 0 WHERE slug = ?')->execute([$link['slug']]);
    flash('Link ' . $link['slug'] . ' deleted. Its scan history is kept.');
    send_redirect(url('/admin'));
}

/* ------------------------------------------------------------------ export */

function export_csv(): never
{
    $days = selected_period();
    $client = trim((string) ($_GET['client'] ?? ''));
    $raw = isset($_GET['raw']);
    $since = period_since($days);
    $links = [];
    foreach (db()->query('SELECT * FROM links') as $l) {
        $links[$l['slug']] = $l;
    }
    $name = 'bionik-links-' . ($client !== '' ? preg_replace('/[^a-z0-9-]/i', '', $client) . '-' : '') . ($days ?: 'all') . ($days ? 'd' : '') . ($raw ? '-raw' : '') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    $out = fopen('php://output', 'w');

    if ($raw) {
        fputcsv($out, ['time_local', 'tag', 'client', 'label', 'type', 'device']);
        $st = db()->prepare('SELECT * FROM scans WHERE ts >= ? ORDER BY ts');
        $st->execute([$since]);
        foreach ($st as $s) {
            $l = $links[$s['slug']] ?? null;
            if ($client !== '' && ($l['client'] ?? '') !== $client) {
                continue;
            }
            fputcsv($out, [local_time((int) $s['ts'], 'Y-m-d H:i:s'), $s['slug'], $l['client'] ?? '', $l['title'] ?? '(unknown tag)', $s['source'], $s['device']]);
        }
        exit;
    }

    fputcsv($out, ['tag', 'client', 'label', 'destination', 'active', 'scans', 'nfc', 'qr', 'ios', 'android', 'unique_visitor_days', 'last_scan_local']);
    $totals = scan_totals($since);
    $slugs = array_unique(array_merge(array_keys($links), array_keys($totals)));
    natsort($slugs);
    foreach ($slugs as $slug) {
        $l = $links[$slug] ?? null;
        $t = $totals[$slug] ?? [];
        if ($client !== '' && ($l['client'] ?? '') !== $client) {
            continue;
        }
        fputcsv($out, [
            $slug, $l['client'] ?? '', $l['title'] ?? '(unknown tag)', $l['url'] ?? '', $l ? ((int) $l['active'] ? 'yes' : 'no') : '',
            (int) ($t['total'] ?? 0), (int) ($t['nfc'] ?? 0), (int) ($t['qr'] ?? 0), (int) ($t['ios'] ?? 0), (int) ($t['android'] ?? 0),
            (int) ($t['uniq'] ?? 0), isset($t['last']) ? local_time((int) $t['last'], 'Y-m-d H:i') : '',
        ]);
    }
    exit;
}
