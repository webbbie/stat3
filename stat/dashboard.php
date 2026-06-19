<?php
/**
 * dashboard.php - MySQL version in the original dashboard.php layout style.
 *
 * Features:
 * - Tracking pixel endpoint (?action=track) writes through pixl_server.php
 * - JSON API endpoints (?action=api&type=...)
 * - Main dashboard and raw log viewer
 * - Shared Pixl stats authentication
 */
declare(strict_types=1);

require __DIR__ . '/../pixl_server.php';

pixl_apply_cors();
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

date_default_timezone_set('Europe/Berlin');

const DASH_DAYS_DEFAULT = 30;
const DASH_DAYS_MIN = 1;
const DASH_DAYS_MAX = 365;
const DASH_MAX_ROWS_STATS = 15000;
const DASH_MAX_ROWS_LOG = 2000;
const DASH_RECENT_LIMIT = 10;

function dash_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function dash_json_encode($value): string
{
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return is_string($json) ? $json : 'null';
}

function dash_days($value): int
{
    $days = (int)($value ?? DASH_DAYS_DEFAULT);
    return max(DASH_DAYS_MIN, min(DASH_DAYS_MAX, $days));
}

function dash_number($value): string
{
    return number_format((float)$value, 0, ',', '.');
}

function dash_decimal($value, int $places = 2): string
{
    return number_format((float)$value, $places, ',', '.');
}

function dash_short($value, int $max = 90): string
{
    $text = trim((string)$value);
    if ($text === '') {
        return '-';
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($text, 'UTF-8') > $max ? mb_substr($text, 0, max(1, $max - 1), 'UTF-8') . '...' : $text;
    }
    return strlen($text) > $max ? substr($text, 0, max(1, $max - 1)) . '...' : $text;
}

function dash_fetch_rows(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function dash_json(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store');
    echo dash_json_encode($data);
    exit;
}

function dash_pixel_response(): void
{
    header('Content-Type: image/gif');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    exit;
}

function dash_get_float(string $key): ?float
{
    $value = $_GET[$key] ?? null;
    if ($value === null || $value === '' || is_array($value) || !is_numeric($value)) {
        return null;
    }
    return (float)$value;
}

function dash_get_int(string $key): ?int
{
    $value = $_GET[$key] ?? null;
    if ($value === null || $value === '' || is_array($value) || !is_numeric($value)) {
        return null;
    }
    return max(0, (int)$value);
}

function dash_host_from_url(string $url): string
{
    $host = parse_url($url, PHP_URL_HOST);
    return is_string($host) ? $host : '';
}

function dash_path_from_url(string $url): string
{
    $path = parse_url($url, PHP_URL_PATH);
    return is_string($path) && $path !== '' ? $path : '/';
}

function dash_legacy_payload(): ?array
{
    $url = trim((string)($_GET['url'] ?? ''));
    if ($url === '') {
        return null;
    }

    $config = pixl_config();
    $referrer = (string)($_GET['referrer'] ?? ($_GET['ref'] ?? ($_SERVER['HTTP_REFERER'] ?? '')));
    $userAgent = (string)($_GET['user_agent'] ?? ($_GET['ua'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')));
    $host = trim((string)($_GET['host_domain'] ?? ''));
    if ($host === '') {
        $host = dash_host_from_url($url);
    }
    if ($host === '') {
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    }
    if ($host !== '' && !pixl_allowed_host($host)) {
        return null;
    }

    $screenW = dash_get_int('screen_width') ?? dash_get_int('screen_w');
    $screenH = dash_get_int('screen_height') ?? dash_get_int('screen_h');
    $screen = ($screenW && $screenH) ? $screenW . 'x' . $screenH : '';
    $score = dash_get_float('score');
    $v3Score = null;
    $readingScore = null;
    if ($score !== null) {
        $v3Score = max(0.0, min(1.0, $score > 1 ? $score / 100 : $score));
        $readingScore = (int)round($v3Score * 100);
    }
    $dwellMs = dash_get_int('dwell_ms');
    $sessionDuration = $dwellMs !== null ? (int)round($dwellMs / 1000) : null;
    $botParam = dash_get_int('bot');
    $humanParam = dash_get_int('human');
    $clientBot = $botParam === 1 || ($humanParam !== null && $humanParam === 0);

    return [
        'schema' => 'dashboard-legacy-mysql-v1',
        'source' => 'dashboard_pixel',
        'siteKey' => (string)($config['public_key'] ?? ''),
        'siteId' => (string)($config['site_id'] ?? ($host !== '' ? $host : 'pixl')),
        'eventId' => bin2hex(random_bytes(16)),
        'sentAt' => (string)($_GET['timestamp'] ?? gmdate('c')),
        'reason' => 'LEGACY_PIXEL',
        'title' => (string)($_GET['title'] ?? 'Dashboard Pixel'),
        'page' => [
            'hostname' => $host,
            'origin' => $host !== '' ? 'https://' . $host : '',
            'url' => $url,
            'path' => dash_path_from_url($url),
            'referrer' => $referrer,
        ],
        'context' => [
            'userAgent' => $userAgent,
            'browser' => (string)($_GET['browser'] ?? ''),
            'os' => (string)($_GET['os'] ?? ''),
            'device' => (string)($_GET['device'] ?? ''),
            'screen' => $screen,
            'knownResolution' => $screen !== '',
            'viewport' => (string)($_GET['viewport'] ?? ''),
            'screenCategory' => '',
            'language' => (string)($_GET['lang'] ?? ''),
            'country' => (string)($_GET['country'] ?? ($_SERVER['HTTP_CF_IPCOUNTRY'] ?? '')),
        ],
        'engagement' => [
            'sessionDuration' => $sessionDuration,
            'readingLabel' => $readingScore !== null ? 'legacy-score' : 'legacy-pixel',
            'readingSeconds' => $sessionDuration,
            'readingScore' => $readingScore,
            'v3UserScore' => $v3Score,
        ],
        'health' => [
            'renderStatus' => 'LEGACY_PIXEL',
            'consoleErrorCount' => 0,
            'dialogErrorCount' => 0,
        ],
        'bot' => [
            'isBot' => $clientBot,
            'category' => $clientBot ? 'legacy' : '',
            'name' => $clientBot ? 'Legacy Bot Flag' : '',
            'reasons' => $clientBot ? ['legacy-param'] : [],
        ],
        'message' => 'stat/dashboard.php?action=track compatibility pixel',
    ];
}

function dash_track(): void
{
    try {
        $payload = dash_legacy_payload();
        if ($payload !== null) {
            $pdo = pixl_pdo();
            pixl_ensure_schema($pdo);
            pixl_insert_event($pdo, $payload);
        }
    } catch (Throwable $e) {
        error_log('dashboard.php MySQL track failed: ' . $e->getMessage());
    }

    dash_pixel_response();
}

function dash_screen_parts(string $screen, string $viewport = ''): array
{
    $raw = $screen !== '' ? $screen : $viewport;
    if (preg_match('/(\d{2,5})\D+(\d{2,5})/', $raw, $matches)) {
        return [(int)$matches[1], (int)$matches[2]];
    }
    return [null, null];
}

function dash_mysql_rows(PDO $pdo, string $table, string $since, int $limit, bool $chronological = true): array
{
    $limit = max(1, min(50000, $limit));
    $pageExpr = pixl_sql_page_expression();
    $referrerExpr = pixl_sql_referrer_expression();
    $scoreExpr = "CASE
        WHEN `v3_user_score` IS NOT NULL THEN LEAST(1, GREATEST(0, `v3_user_score`))
        WHEN `reading_score` IS NOT NULL THEN LEAST(1, GREATEST(0, `reading_score` / 100))
        ELSE NULL
    END";

    $rows = dash_fetch_rows(
        $pdo,
        "SELECT `id`,
                `created_at` AS `datetime`,
                `created_at` AS `ts`,
                $pageExpr AS `url`,
                $referrerExpr AS `referrer`,
                `language` AS `lang`,
                `user_agent` AS `ua`,
                `hostname` AS `host_domain`,
                `screen`,
                `viewport`,
                `visitor_hash`,
                `ip_hash`,
                `is_bot` AS `bot`,
                0 AS `dc`,
                CASE WHEN `is_bot` = 1 THEN 0 ELSE 1 END AS `human`,
                $scoreExpr AS `score`,
                CASE WHEN `session_duration` IS NULL THEN NULL ELSE `session_duration` * 1000 END AS `dwell_ms`,
                0 AS `unique24h`,
                0 AS `visits_24h`,
                0 AS `visits_total`,
                `country` AS `reg`,
                `browser`,
                `os`,
                `device`,
                `source`,
                `reason`,
                `title`,
                `bot_score`,
                `bot_name`
         FROM `$table`
         WHERE `created_at` >= :since
         ORDER BY `id` DESC
         LIMIT " . $limit,
        [':since' => $since]
    );

    foreach ($rows as &$row) {
        [$screenW, $screenH] = dash_screen_parts((string)($row['screen'] ?? ''), (string)($row['viewport'] ?? ''));
        $row['screen_w'] = $screenW;
        $row['screen_h'] = $screenH;
        $row['ip'] = (string)($row['visitor_hash'] ?? '');
        $row['browser_label'] = trim((string)($row['browser'] ?? '')) ?: browser_from_ua((string)($row['ua'] ?? ''));
        $row['os_label'] = trim((string)($row['os'] ?? '')) ?: os_from_ua((string)($row['ua'] ?? ''));
    }
    unset($row);

    return $chronological ? array_reverse($rows) : $rows;
}

function is_bot(?string $ua): bool
{
    if (!$ua) {
        return false;
    }
    return (bool)preg_match('/bot|crawl|spider|slurp|mediapartners|facebook|twitter|whatsapp|telegram|preview/i', $ua);
}

function os_from_ua(?string $ua): string
{
    if (!$ua) {
        return 'Unknown';
    }
    $patterns = [
        'Windows NT 10' => 'Windows 10',
        'Windows NT 11' => 'Windows 11',
        'Windows NT 6.3' => 'Windows 8.1',
        'Windows NT 6.1' => 'Windows 7',
        'Mac OS X' => 'macOS',
        'Linux' => 'Linux',
        'Android' => 'Android',
        'iPhone' => 'iOS',
        'iPad' => 'iPadOS',
    ];
    foreach ($patterns as $pattern => $name) {
        if (stripos($ua, $pattern) !== false) {
            return $name;
        }
    }
    return 'Other';
}

function browser_from_ua(?string $ua): string
{
    if (!$ua) {
        return 'Unknown';
    }
    if (stripos($ua, 'Edg/') !== false || stripos($ua, 'Edge') !== false) {
        return 'Edge';
    }
    if (stripos($ua, 'OPR/') !== false || stripos($ua, 'Opera') !== false) {
        return 'Opera';
    }
    if (stripos($ua, 'Firefox') !== false) {
        return 'Firefox';
    }
    if (stripos($ua, 'Chrome') !== false && stripos($ua, 'Safari') !== false) {
        return 'Chrome';
    }
    if (stripos($ua, 'Safari') !== false) {
        return 'Safari';
    }
    if (stripos($ua, 'MSIE') !== false) {
        return 'IE';
    }
    if (stripos($ua, 'Trident') !== false) {
        return 'IE11';
    }
    return 'Other';
}

function group_by_interval(array $rows, string $format = 'Y-m-d H:i'): array
{
    $counts = [];
    foreach ($rows as $row) {
        $ts = $row['datetime'] ?? $row['ts'] ?? null;
        if (!$ts) {
            continue;
        }
        $time = strtotime((string)$ts);
        if ($time === false) {
            continue;
        }
        $key = date($format, $time);
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }
    ksort($counts);
    return $counts;
}

function top_counts(array $rows, string $key, int $limit = 10): array
{
    $counts = [];
    foreach ($rows as $row) {
        $value = trim((string)($row[$key] ?? ''));
        if ($value === '') {
            continue;
        }
        $counts[$value] = ($counts[$value] ?? 0) + 1;
    }
    arsort($counts);
    return array_slice($counts, 0, $limit, true);
}

function dash_normalize_host(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $host = parse_url($value, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        $host = parse_url('https://' . ltrim($value, '/'), PHP_URL_HOST);
    }
    if (!is_string($host) || $host === '') {
        return '';
    }

    $host = strtolower(rtrim($host, '.'));
    return strpos($host, 'www.') === 0 ? substr($host, 4) : $host;
}

function dash_hosts_match(string $left, string $right): bool
{
    $left = dash_normalize_host($left);
    $right = dash_normalize_host($right);
    if ($left === '' || $right === '') {
        return false;
    }
    if ($left === $right) {
        return true;
    }

    return substr($left, -strlen('.' . $right)) === '.' . $right
        || substr($right, -strlen('.' . $left)) === '.' . $left;
}

function dash_referrer_counts(array $rows, int $limit = 10): array
{
    $config = pixl_config();
    $siteHost = dash_normalize_host((string)($config['site_id'] ?? ''));
    $counts = [];

    foreach ($rows as $row) {
        $referrer = pixl_url_without_parameters($row['referrer'] ?? '');
        $isRelativePath = substr($referrer, 0, 1) === '/' && substr($referrer, 0, 2) !== '//';
        if ($referrer === '' || $isRelativePath) {
            continue;
        }

        $referrerHost = dash_normalize_host($referrer);
        $rowHost = dash_normalize_host((string)($row['host_domain'] ?? ''));
        if ($rowHost === '') {
            $rowHost = dash_normalize_host((string)($row['url'] ?? ''));
        }

        if ($referrerHost !== '' && (
            dash_hosts_match($referrerHost, $rowHost)
            || dash_hosts_match($referrerHost, $siteHost)
        )) {
            continue;
        }

        $counts[$referrer] = ($counts[$referrer] ?? 0) + 1;
    }

    arsort($counts);
    return array_slice($counts, 0, $limit, true);
}

function resolution_counts(array $rows, int $limit = 15): array
{
    $counts = [];
    foreach ($rows as $row) {
        $w = $row['screen_w'] ?? null;
        $h = $row['screen_h'] ?? null;
        if (!$w || !$h) {
            continue;
        }
        $resolution = (int)$w . 'x' . (int)$h;
        $counts[$resolution] = ($counts[$resolution] ?? 0) + 1;
    }
    arsort($counts);
    return array_slice($counts, 0, $limit, true);
}

function calculate_stats(array $rows): array
{
    $bots = 0;
    $humans = 0;
    $visitors = [];
    $pages = [];
    foreach ($rows as $row) {
        $ua = $row['ua'] ?? '';
        $isBot = (int)($row['bot'] ?? 0) === 1 || is_bot($ua);
        if ($isBot) {
            $bots++;
        } else {
            $humans++;
        }
        $visitor = (string)($row['visitor_hash'] ?? $row['ip'] ?? '');
        if ($visitor !== '') {
            $visitors[$visitor] = true;
        }
        $url = (string)($row['url'] ?? '');
        if ($url !== '') {
            $pages[$url] = true;
        }
    }
    $perMinute = group_by_interval($rows, 'Y-m-d H:i');
    return [
        'total_visits' => count($rows),
        'unique_ips' => count($visitors),
        'human_visits' => $humans,
        'bot_visits' => $bots,
        'unique_pages' => count($pages),
        'avg_per_minute' => count($perMinute) ? round(array_sum($perMinute) / count($perMinute), 2) : 0,
    ];
}

function dash_render_error(string $message, int $days): void
{
    ?>
    <!doctype html>
    <html lang="de">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width,initial-scale=1">
      <title>Analytics Dashboard · MySQL Fehler</title>
      <style>
        body{margin:0;min-height:100vh;display:grid;place-items:center;background:#0a0a0f;color:#f0f0f5;font-family:system-ui,-apple-system,Segoe UI,sans-serif}
        .box{width:min(620px,calc(100vw - 32px));padding:28px;border:1px solid rgba(255,255,255,.08);border-radius:16px;background:rgba(18,18,26,.8)}
        h1{margin:0 0 10px;font-size:22px}
        p{color:#8888a0}
        code{color:#00f5d4}
        a{color:#00f5d4}
      </style>
    </head>
    <body>
      <main class="box">
        <h1>MySQL Dashboard nicht verfuegbar</h1>
        <p><?= dash_h($message) ?></p>
        <p><a href="?days=<?= dash_h($days) ?>">Erneut laden</a> · <a href="../pixl_stats.php?days=<?= dash_h($days) ?>">Pixl Stats</a></p>
      </main>
    </body>
    </html>
    <?php
    exit;
}

$action = (string)($_GET['action'] ?? 'dashboard');

if ($action === 'track') {
    dash_track();
}

if ($action === 'logout') {
    pixl_clear_stats_auth_cookie();
    header('Location: dashboard.php', true, 302);
    exit;
}

pixl_require_stats_auth();

$days = dash_days($_GET['days'] ?? null);
$since = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
    ->modify('-' . $days . ' days')
    ->format('Y-m-d H:i:s');

try {
    $pdo = pixl_pdo();
    pixl_ensure_schema($pdo);
    $table = pixl_table_name();
} catch (Throwable $e) {
    if ($action === 'api') {
        dash_json(['ok' => false, 'error' => $e->getMessage()], 500);
    }
    dash_render_error($e->getMessage(), $days);
}

if ($action === 'api') {
    $type = (string)($_GET['type'] ?? 'stats');
    $limit = min((int)($_GET['limit'] ?? DASH_MAX_ROWS_STATS), DASH_MAX_ROWS_STATS);
    $rows = dash_mysql_rows($pdo, $table, $since, $limit);

    switch ($type) {
        case 'stats':
            dash_json(calculate_stats($rows));
        case 'all':
            dash_json($rows);
        case 'recent':
            $n = min((int)($_GET['limit'] ?? 10), 100);
            dash_json(array_slice(array_reverse($rows), 0, $n));
        case 'visits_per_minute':
            dash_json(group_by_interval($rows, 'Y-m-d H:i'));
        case 'visits_per_day':
            dash_json(group_by_interval($rows, 'Y-m-d'));
        case 'referrers':
            dash_json(dash_referrer_counts($rows));
        case 'pages':
            dash_json(top_counts($rows, 'url'));
        case 'browsers':
            dash_json(top_counts($rows, 'browser_label'));
        case 'os':
            dash_json(top_counts($rows, 'os_label'));
        case 'languages':
            dash_json(top_counts($rows, 'lang'));
        case 'resolutions':
            dash_json(resolution_counts($rows));
        default:
            dash_json(['error' => 'Unknown API type'], 400);
    }
}

if ($action === 'log') {
    $filter = (string)($_GET['filter'] ?? 'ALL');
    $limit = min((int)($_GET['limit'] ?? DASH_MAX_ROWS_LOG), DASH_MAX_ROWS_LOG);
    try {
        $rows = dash_mysql_rows($pdo, $table, $since, $limit, false);
    } catch (Throwable $e) {
        dash_render_error($e->getMessage(), $days);
    }
    ?>
    <!doctype html>
    <html lang="de">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width,initial-scale=1">
      <title>Besucher-Log · MySQL</title>
      <style>
        *{margin:0;padding:0;box-sizing:border-box;letter-spacing:0}
        body{font-family:'Segoe UI',system-ui,sans-serif;background:#0a0a0f;color:#f0f0f5;padding:20px}
        .header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:15px}
        h1{font-size:24px}
        .nav a{color:#00f5d4;text-decoration:none;margin-left:20px}
        .nav a:hover{text-decoration:underline}
        .filter{background:rgba(255,255,255,.05);padding:15px 20px;border-radius:10px;margin-bottom:20px}
        .filter a{color:#8888a0;text-decoration:none;margin-right:15px;padding:8px 16px;border-radius:6px;transition:all .2s}
        .filter a:hover,.filter a.active{background:rgba(0,245,212,.1);color:#00f5d4}
        .scroll{overflow:auto;border-radius:10px}
        table{width:100%;border-collapse:collapse;background:rgba(255,255,255,.02);min-width:980px}
        th,td{padding:12px 15px;text-align:left;border-bottom:1px solid rgba(255,255,255,.05);vertical-align:middle}
        th{background:rgba(255,255,255,.05);font-weight:600;color:#8888a0;font-size:12px;text-transform:uppercase}
        tr:hover td{background:rgba(255,255,255,.02)}
        a{color:#00f5d4;text-decoration:none}
        a:hover{text-decoration:underline}
        .url-cell{max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .badge{display:inline-block;padding:4px 10px;border-radius:4px;font-size:12px;font-weight:600}
        .badge-ok{background:rgba(0,245,212,.15);color:#00f5d4}
        .badge-bot{background:rgba(247,37,133,.15);color:#f72585}
        .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace}
        @media (max-width:700px){body{padding:12px}.filter span{float:none!important;display:block;margin-top:12px}.nav a{margin-left:0;margin-right:14px}}
      </style>
    </head>
    <body>
      <div class="header">
        <h1>Besucher-Log <span class="mono" style="color:#666">(MySQL)</span></h1>
        <nav class="nav">
          <a href="?days=<?= dash_h($days) ?>">Dashboard</a>
          <a href="../pixl_stats.php?days=<?= dash_h($days) ?>">Pixl Stats</a>
          <a href="?action=logout">Logout</a>
        </nav>
      </div>

      <div class="filter">
        <strong>Filter:</strong>
        <a href="?action=log&filter=ALL&days=<?= dash_h($days) ?>" class="<?= $filter === 'ALL' ? 'active' : '' ?>">Alle</a>
        <a href="?action=log&filter=OK&days=<?= dash_h($days) ?>" class="<?= $filter === 'OK' ? 'active' : '' ?>">Echte Besucher</a>
        <a href="?action=log&filter=BOT&days=<?= dash_h($days) ?>" class="<?= $filter === 'BOT' ? 'active' : '' ?>">Bots</a>
        <span class="mono" style="float:right;color:#666">DB: <?= dash_h($table) ?> · <?= dash_h($days) ?> Tage · Limit: <?= (int)$limit ?></span>
      </div>

      <div class="scroll">
        <table>
          <thead>
            <tr>
              <th>Zeit</th>
              <th>Visitor</th>
              <th>URL</th>
              <th>Referrer</th>
              <th>Sprache</th>
              <th>Browser</th>
              <th>Aufloesung</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $entry):
                $ua = $entry['ua'] ?? '';
                $isBot = (int)($entry['bot'] ?? 0) === 1 || is_bot($ua);
                $status = $isBot ? 'BOT' : 'OK';
                if ($filter !== 'ALL' && $filter !== $status) {
                    continue;
                }
                $resolution = '';
                if (!empty($entry['screen_w']) && !empty($entry['screen_h'])) {
                    $resolution = (int)$entry['screen_w'] . 'x' . (int)$entry['screen_h'];
                }
                $url = (string)($entry['url'] ?? '');
            ?>
            <tr>
              <td class="mono"><?= dash_h($entry['datetime'] ?? '') ?></td>
              <td class="mono" title="<?= dash_h($entry['visitor_hash'] ?? '') ?>"><?= dash_h(dash_short($entry['visitor_hash'] ?? '', 16)) ?></td>
              <td class="url-cell"><a href="<?= dash_h($url) ?>" target="_blank" rel="noopener"><?= dash_h($url) ?></a></td>
              <td class="url-cell"><?= dash_h($entry['referrer'] ?: 'direct') ?></td>
              <td class="mono"><?= dash_h($entry['lang'] ?? '') ?></td>
              <td><?= dash_h($entry['browser_label'] ?? '') ?></td>
              <td class="mono"><?= dash_h($resolution) ?></td>
              <td><span class="badge <?= $isBot ? 'badge-bot' : 'badge-ok' ?>"><?= $status ?></span></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </body>
    </html>
    <?php
    exit;
}

try {
    $rows = dash_mysql_rows($pdo, $table, $since, DASH_MAX_ROWS_STATS);
} catch (Throwable $e) {
    dash_render_error($e->getMessage(), $days);
}

$stats = calculate_stats($rows);
$visitsPerDay = group_by_interval($rows, 'Y-m-d');
$referrers = dash_referrer_counts($rows);
$languages = top_counts($rows, 'lang');
$pages = top_counts($rows, 'url');
$resolutions = resolution_counts($rows);
$browserStats = top_counts($rows, 'browser_label');
$osStats = top_counts($rows, 'os_label');
$recentVisitors = array_slice(array_reverse($rows), 0, DASH_RECENT_LIMIT);

?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Analytics Dashboard · MySQL</title>
  <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600&family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <style>
    :root {
      --bg-primary: #0a0a0f;
      --bg-card: rgba(18, 18, 26, 0.8);
      --glass-border: rgba(255, 255, 255, 0.08);
      --text-primary: #f0f0f5;
      --text-secondary: #8888a0;
      --accent-cyan: #00f5d4;
      --accent-magenta: #f72585;
      --accent-violet: #7b2cbf;
      --accent-orange: #ff6b35;
      --gradient-neon: linear-gradient(135deg, #00f5d4 0%, #7b2cbf 50%, #f72585 100%);
    }
    * { margin: 0; padding: 0; box-sizing: border-box; letter-spacing: 0; }
    body {
      font-family: 'Outfit', sans-serif;
      background: var(--bg-primary);
      color: var(--text-primary);
      min-height: 100vh;
    }
    .bg-pattern {
      position: fixed; inset: 0;
      pointer-events: none; z-index: 0;
      background:
        radial-gradient(ellipse at 20% 20%, rgba(0, 245, 212, 0.08) 0%, transparent 50%),
        radial-gradient(ellipse at 80% 80%, rgba(247, 37, 133, 0.08) 0%, transparent 50%);
    }
    .container {
      position: relative; z-index: 1;
      max-width: 1600px;
      margin: 0 auto;
      padding: 30px;
    }
    .header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 40px;
      flex-wrap: wrap;
      gap: 20px;
    }
    .logo {
      display: flex;
      align-items: center;
      gap: 15px;
    }
    .logo-icon {
      width: 45px; height: 45px;
      background: var(--gradient-neon);
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 22px;
      font-weight: 700;
      color: #0a0a0f;
    }
    .logo h1 {
      font-size: 24px;
      background: var(--gradient-neon);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }
    .logo span {
      display: block;
      font-size: 11px;
      color: var(--text-secondary);
      letter-spacing: 2px;
      text-transform: uppercase;
    }
    .header-nav {
      display: flex;
      align-items: center;
      gap: 14px;
      flex-wrap: wrap;
    }
    .header-nav a {
      color: var(--text-secondary);
      text-decoration: none;
      transition: color 0.2s;
    }
    .header-nav a:hover { color: var(--accent-cyan); }
    .btn {
      padding: 10px 20px;
      border-radius: 8px;
      border: none;
      cursor: pointer;
      font-family: 'Outfit', sans-serif;
      font-size: 14px;
      transition: all 0.2s;
      text-decoration: none;
      display: inline-block;
    }
    .btn-ghost {
      background: rgba(255,255,255,0.05);
      color: var(--text-primary);
      border: 1px solid var(--glass-border);
    }
    .range-form {
      display: inline-flex;
      gap: 8px;
      align-items: center;
      color: var(--text-secondary);
    }
    .range-form input {
      width: 78px;
      min-height: 38px;
      border: 1px solid var(--glass-border);
      border-radius: 8px;
      padding: 8px 10px;
      color: var(--text-primary);
      background: rgba(255,255,255,0.05);
      font: inherit;
    }
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 20px;
      margin-bottom: 30px;
    }
    .stat-card {
      background: var(--bg-card);
      border: 1px solid var(--glass-border);
      border-radius: 16px;
      padding: 24px;
      transition: transform 0.3s, border-color 0.3s;
    }
    .stat-card:hover {
      transform: translateY(-3px);
      border-color: rgba(0, 245, 212, 0.3);
    }
    .stat-icon { font-size: 24px; margin-bottom: 12px; }
    .stat-value {
      font-size: 32px;
      font-weight: 700;
      font-family: 'JetBrains Mono', monospace;
      margin-bottom: 5px;
      overflow-wrap: anywhere;
    }
    .stat-label {
      color: var(--text-secondary);
      font-size: 13px;
      text-transform: uppercase;
      letter-spacing: 1px;
    }
    .charts-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
      gap: 20px;
      margin-bottom: 30px;
    }
    .chart-card {
      background: var(--bg-card);
      border: 1px solid var(--glass-border);
      border-radius: 16px;
      padding: 24px;
    }
    .chart-title {
      font-size: 16px;
      font-weight: 600;
      margin-bottom: 20px;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .chart-container { position: relative; height: 250px; }
    .tables-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
      gap: 20px;
      margin-bottom: 30px;
    }
    .table-card {
      background: var(--bg-card);
      border: 1px solid var(--glass-border);
      border-radius: 16px;
      overflow: hidden;
    }
    .table-header {
      padding: 20px 24px;
      border-bottom: 1px solid var(--glass-border);
      font-weight: 600;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
    }
    .scroll { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; }
    th, td {
      padding: 12px 24px;
      text-align: left;
      border-bottom: 1px solid rgba(255,255,255,0.03);
      vertical-align: middle;
    }
    th {
      font-size: 11px;
      font-weight: 600;
      text-transform: uppercase;
      color: var(--text-secondary);
      white-space: nowrap;
    }
    tr:hover td { background: rgba(255,255,255,0.02); }
    .url-cell {
      max-width: 280px;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
    .url-cell a { color: var(--accent-cyan); text-decoration: none; }
    .url-cell a:hover { text-decoration: underline; }
    .count {
      font-family: 'JetBrains Mono', monospace;
      color: var(--accent-cyan);
      font-weight: 600;
    }
    .badge {
      display: inline-block;
      padding: 4px 10px;
      border-radius: 4px;
      font-size: 12px;
    }
    .badge-ok { background: rgba(0,245,212,0.15); color: var(--accent-cyan); }
    .badge-bot { background: rgba(247,37,133,0.15); color: var(--accent-magenta); }
    .small {
      color: rgba(240,240,245,0.55);
      font-size: 12px;
      font-family: 'JetBrains Mono', monospace;
    }
    @media (max-width: 768px) {
      .container { padding: 15px; }
      .header { flex-direction: column; align-items: flex-start; }
      .charts-grid, .tables-grid { grid-template-columns: 1fr; }
      .stat-value { font-size: 26px; }
      th, td { padding: 10px 14px; }
    }
  </style>
</head>
<body>
  <div class="bg-pattern"></div>
  <div class="container">
    <header class="header">
      <div class="logo">
        <div class="logo-icon">A</div>
        <div>
          <h1>Analytics</h1>
          <span>Dashboard · MySQL <?= dash_h($table) ?></span>
        </div>
      </div>
      <nav class="header-nav">
        <form class="range-form" method="get">
          <label for="days">Tage</label>
          <input id="days" name="days" value="<?= dash_h($days) ?>" inputmode="numeric">
          <button class="btn btn-ghost" type="submit">Apply</button>
        </form>
        <a href="?action=log&days=<?= dash_h($days) ?>">Log ansehen</a>
        <a href="../pixl_stats.php?days=<?= dash_h($days) ?>">Pixl Stats</a>
        <a href="?action=logout" class="btn btn-ghost">Logout</a>
      </nav>
    </header>

    <div class="stats-grid">
      <div class="stat-card"><div class="stat-icon">Users</div><div class="stat-value"><?= dash_number($stats['total_visits']) ?></div><div class="stat-label">Gesamte Besuche</div></div>
      <div class="stat-card"><div class="stat-icon">Hash</div><div class="stat-value"><?= dash_number($stats['unique_ips']) ?></div><div class="stat-label">Eindeutige Besucher</div></div>
      <div class="stat-card"><div class="stat-icon">OK</div><div class="stat-value"><?= dash_number($stats['human_visits']) ?></div><div class="stat-label">Echte Besucher</div></div>
      <div class="stat-card"><div class="stat-icon">Bot</div><div class="stat-value"><?= dash_number($stats['bot_visits']) ?></div><div class="stat-label">Bot-Besuche</div></div>
      <div class="stat-card"><div class="stat-icon">URL</div><div class="stat-value"><?= dash_number($stats['unique_pages']) ?></div><div class="stat-label">Verschiedene Seiten</div></div>
      <div class="stat-card"><div class="stat-icon">Min</div><div class="stat-value"><?= dash_h(dash_decimal($stats['avg_per_minute'])) ?></div><div class="stat-label">Durchschnitt/Min</div></div>
    </div>

    <div class="charts-grid">
      <div class="chart-card">
        <div class="chart-title">Besucher pro Tag</div>
        <div class="chart-container"><canvas id="visitsPerDayChart"></canvas></div>
      </div>
      <div class="chart-card">
        <div class="chart-title">Top Referrer</div>
        <div class="chart-container"><canvas id="referrerChart"></canvas></div>
      </div>
      <div class="chart-card">
        <div class="chart-title">Browser</div>
        <div class="chart-container"><canvas id="browserChart"></canvas></div>
      </div>
      <div class="chart-card">
        <div class="chart-title">Betriebssysteme</div>
        <div class="chart-container"><canvas id="osChart"></canvas></div>
      </div>
      <div class="chart-card">
        <div class="chart-title">Sprachen</div>
        <div class="chart-container"><canvas id="langChart"></canvas></div>
      </div>
      <div class="chart-card">
        <div class="chart-title">Bildschirmaufloesungen</div>
        <div class="chart-container"><canvas id="resChart"></canvas></div>
      </div>
    </div>

    <div class="tables-grid">
      <section class="table-card">
        <div class="table-header"><span>Top Seiten</span><span class="small"><?= dash_h($days) ?> Tage</span></div>
        <div class="scroll">
          <table>
            <thead><tr><th>URL</th><th>Count</th></tr></thead>
            <tbody>
              <?php foreach ($pages as $url => $count): ?>
                <tr><td class="url-cell"><a href="<?= dash_h($url) ?>" target="_blank" rel="noopener"><?= dash_h(dash_short($url, 80)) ?></a></td><td class="count"><?= dash_number($count) ?></td></tr>
              <?php endforeach; ?>
              <?php if (!$pages): ?><tr><td colspan="2" class="small">Keine Daten.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>

      <section class="table-card">
        <div class="table-header"><span>Top Referrer</span><span class="small">direct und eigene Domain ausgeblendet</span></div>
        <div class="scroll">
          <table>
            <thead><tr><th>Referrer</th><th>Count</th></tr></thead>
            <tbody>
              <?php foreach ($referrers as $referrer => $count): ?>
                <tr><td class="url-cell"><?= dash_h(dash_short($referrer, 80)) ?></td><td class="count"><?= dash_number($count) ?></td></tr>
              <?php endforeach; ?>
              <?php if (!$referrers): ?><tr><td colspan="2" class="small">Keine Daten.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>
    </div>

    <section class="table-card" style="margin-bottom:20px">
      <div class="table-header">
        <div>Letzte 10 Besucher</div>
        <div class="small">DB: <?= dash_h($table) ?> · Rows: <?= dash_number(count($rows)) ?></div>
      </div>
      <div class="scroll">
        <table>
          <thead>
            <tr>
              <th>Zeit</th>
              <th>Visitor</th>
              <th>URL</th>
              <th>Referrer</th>
              <th>Browser</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recentVisitors as $visitor):
                $ua = $visitor['ua'] ?? '';
                $isBot = (int)($visitor['bot'] ?? 0) === 1 || is_bot($ua);
                $url = (string)($visitor['url'] ?? '');
            ?>
            <tr>
              <td class="small"><?= dash_h($visitor['datetime'] ?? '') ?></td>
              <td class="small" title="<?= dash_h($visitor['visitor_hash'] ?? '') ?>"><?= dash_h(dash_short($visitor['visitor_hash'] ?? '', 16)) ?></td>
              <td class="url-cell"><a href="<?= dash_h($url) ?>" target="_blank" rel="noopener"><?= dash_h($url) ?></a></td>
              <td class="small"><?= dash_h($visitor['referrer'] ?: 'direct') ?></td>
              <td><?= dash_h($visitor['browser_label'] ?? '') ?></td>
              <td><span class="badge <?= $isBot ? 'badge-bot' : 'badge-ok' ?>"><?= $isBot ? 'BOT' : 'OK' ?></span></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$recentVisitors): ?>
              <tr><td colspan="6" class="small">Keine Daten in MySQL. Sende Events an deinen Pixl Collector.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div>

  <script>
    Chart.defaults.color = '#8888a0';
    Chart.defaults.borderColor = 'rgba(255, 255, 255, 0.05)';
    Chart.defaults.font.family = 'Outfit';

    const colors = {
      cyan: '#00f5d4',
      magenta: '#f72585',
      violet: '#7b2cbf',
      orange: '#ff6b35',
      blue: '#4361ee'
    };

    new Chart(document.getElementById('visitsPerDayChart'), {
      type: 'line',
      data: {
        labels: <?= dash_json_encode(array_keys($visitsPerDay)) ?>,
        datasets: [{
          label: 'Besucher',
          data: <?= dash_json_encode(array_values($visitsPerDay)) ?>,
          borderColor: colors.cyan,
          backgroundColor: 'rgba(0, 245, 212, 0.1)',
          fill: true,
          tension: 0.4,
          borderWidth: 2
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { x: { grid: { display: false } }, y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.03)' } } }
      }
    });

    new Chart(document.getElementById('referrerChart'), {
      type: 'doughnut',
      data: {
        labels: <?= dash_json_encode(array_keys($referrers)) ?>,
        datasets: [{ data: <?= dash_json_encode(array_values($referrers)) ?>, backgroundColor: [colors.cyan, colors.magenta, colors.violet, colors.orange, colors.blue, '#666'], borderWidth: 0 }]
      },
      options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right' } }, cutout: '60%' }
    });

    function horizontalBar(id, labels, values, color) {
      const numberFormat = new Intl.NumberFormat('de-DE');
      const realValues = values.map((value) => Math.max(0, Number(value) || 0));
      const visualValues = realValues.slice();

      if (visualValues.length > 1) {
        const largestOther = Math.max(...realValues.slice(1));
        if (largestOther > 0) {
          visualValues[0] = Math.min(visualValues[0], largestOther * 1.1);
        }
      }

      const displayLabels = labels.map((label, index) => (
        `${label} (${numberFormat.format(realValues[index] || 0)})`
      ));

      new Chart(document.getElementById(id), {
        type: 'bar',
        data: {
          labels: displayLabels,
          datasets: [{
            data: visualValues,
            realValues,
            backgroundColor: color,
            borderRadius: 4
          }]
        },
        options: {
          indexAxis: 'y',
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { display: false },
            tooltip: {
              callbacks: {
                label(context) {
                  const real = context.dataset.realValues[context.dataIndex] || 0;
                  return `Count: ${numberFormat.format(real)}`;
                }
              }
            }
          },
          scales: {
            x: { display: false },
            y: { grid: { display: false } }
          }
        }
      });
    }

    horizontalBar('browserChart', <?= dash_json_encode(array_keys($browserStats)) ?>, <?= dash_json_encode(array_values($browserStats)) ?>, colors.cyan);
    horizontalBar('osChart', <?= dash_json_encode(array_keys($osStats)) ?>, <?= dash_json_encode(array_values($osStats)) ?>, colors.magenta);
    horizontalBar('langChart', <?= dash_json_encode(array_keys($languages)) ?>, <?= dash_json_encode(array_values($languages)) ?>, colors.violet);
    horizontalBar('resChart', <?= dash_json_encode(array_keys($resolutions)) ?>, <?= dash_json_encode(array_values($resolutions)) ?>, colors.orange);
  </script>
</body>
</html>
