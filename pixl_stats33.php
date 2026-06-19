<?php
declare(strict_types=1);

require __DIR__ . '/pixl_server.php';

pixl_require_stats_auth();

$pdo = pixl_pdo();
pixl_ensure_schema($pdo);
$table = pixl_table_name();

// ============================================================================
// Configuration & Constants
// ============================================================================

const DAYS_MIN = 1;
const DAYS_MAX = 365;
const DAYS_DEFAULT = 30;
const BOT_FILTERS = ['all', 'bots', 'humans'];
const BOT_FILTER_DEFAULT = 'all';
const QUERY_LIMIT_PATHS = 20;
const QUERY_LIMIT_BREAKDOWNS = 10;
const QUERY_LIMIT_USER_AGENTS = 10;
const QUERY_LIMIT_BOTS = 40;
const QUERY_LIMIT_EVENTS = 120;
const QUERY_LIMIT_DAYS = 31;
const QUERY_LIMIT_DASHBOARDX2_PAGES = 10;
const QUERY_LIMIT_DASHBOARDX2_EVENTS = 10;
const DASHBOARDX2_RELATIVE_URL = 'stat/dashboardx2.html';

// ============================================================================
// HTML & Display Functions
// ============================================================================

/**
 * Escape HTML special characters safely
 */
function pixl_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Normalize bot/human labels
 */
function pixl_compact_label($value): string
{
    $label = trim((string)$value);
    $lower = strtolower($label);

    return match ($lower) {
        'human', 'human-like' => 'Human',
        'bot', 'unknown bot' => 'Bot',
        default => $label,
    };
}

/**
 * Format browser, OS, and device information
 */
function pixl_display_client($browser, $os, $device): string
{
    $browser = trim((string)$browser);
    $os = trim((string)$os);
    $device = trim((string)$device);

    // Avoid redundancy: don't show OS if it's already in browser
    if ($browser !== '' && $os !== '' && stripos($browser, $os) !== false) {
        $os = '';
    }

    // Normalize Android WebView
    if (strcasecmp($browser, 'android webview') === 0) {
        $browser = 'AndroidWV';
    }

    return implode(' / ', array_filter([$browser, $os, $device], static fn(string $part): bool => $part !== ''));
}

/**
 * Display language code as human-readable name
 */
function pixl_display_language($value): string
{
    $value = trim((string)$value);
    if (!preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/i', $value)) {
        return $value;
    }

    $locale = str_replace('-', '_', $value);
    if (class_exists('Locale')) {
        $name = Locale::getDisplayLanguage($locale, 'de');
        if (is_string($name) && $name !== '' && strtolower($name) !== strtolower($value)) {
            return mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
        }
    }

    $language = strtolower(substr($value, 0, 2));
    $fallback = [
        'ar' => 'Arabisch',
        'de' => 'Deutsch',
        'en' => 'Englisch',
        'es' => 'Spanisch',
        'fr' => 'Französisch',
        'it' => 'Italienisch',
        'nl' => 'Niederländisch',
        'pl' => 'Polnisch',
        'pt' => 'Portugiesisch',
        'ru' => 'Russisch',
        'tr' => 'Türkisch',
        'uk' => 'Ukrainisch',
        'zh' => 'Chinesisch',
    ];

    return $fallback[$language] ?? $value;
}

/**
 * Extract and display meaningful page titles
 */
function pixl_display_title($title): string
{
    $parts = preg_split('/\s+-\s+/', trim((string)$title)) ?: [];
    $hidden = ['visit' => true, 'okay' => true, 'noframe' => true];
    $visible = [];

    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '' || isset($hidden[strtolower($part)])) {
            continue;
        }
        $visible[] = pixl_display_language($part);
    }

    return $visible ? implode(' - ', $visible) : '—';
}

/**
 * Display country code as country name
 */
function pixl_display_country($country): string
{
    $country = trim((string)$country);
    if ($country === '') {
        return '—';
    }

    $code = strtoupper($country);
    if (!preg_match('/^[A-Z]{2}$/', $code)) {
        return $country;
    }

    if (class_exists('Locale')) {
        $name = Locale::getDisplayRegion('und_' . $code, 'de');
        if (is_string($name) && $name !== '' && strtoupper($name) !== $code) {
            return $name;
        }
    }

    $fallback = [
        'AD' => 'Andorra',          'AE' => 'Vereinigte Arabische Emirate',
        'AF' => 'Afghanistan',      'AL' => 'Albanien',
        'AM' => 'Armenien',         'AR' => 'Argentinien',
        'AT' => 'Österreich',       'AU' => 'Australien',
        'AZ' => 'Aserbaidschan',    'BA' => 'Bosnien und Herzegowina',
        'BE' => 'Belgien',          'BH' => 'Bahrain',
        'BG' => 'Bulgarien',        'BO' => 'Bolivien',
        'BR' => 'Brasilien',        'BY' => 'Belarus',
        'CA' => 'Kanada',           'CH' => 'Schweiz',
        'CL' => 'Chile',            'CN' => 'China',
        'CO' => 'Kolumbien',        'CV' => 'Kap Verde',
        'CZ' => 'Tschechien',       'DE' => 'Deutschland',
        'DK' => 'Dänemark',         'DZ' => 'Algerien',
        'EE' => 'Estland',          'EG' => 'Ägypten',
        'ES' => 'Spanien',          'FI' => 'Finnland',
        'FR' => 'Frankreich',       'GB' => 'Vereinigtes Königreich',
        'GE' => 'Georgien',         'GR' => 'Griechenland',
        'HK' => 'Hongkong',         'HR' => 'Kroatien',
        'HU' => 'Ungarn',           'ID' => 'Indonesien',
        'IE' => 'Irland',           'IL' => 'Israel',
        'IN' => 'Indien',           'IQ' => 'Irak',
        'IR' => 'Iran',             'IS' => 'Island',
        'IT' => 'Italien',          'JO' => 'Jordanien',
        'JP' => 'Japan',            'KR' => 'Südkorea',
        'KZ' => 'Kasachstan',       'LI' => 'Liechtenstein',
        'LY' => 'Libyen',           'LT' => 'Litauen',
        'LU' => 'Luxemburg',        'LV' => 'Lettland',
        'MA' => 'Marokko',          'MD' => 'Moldau',
        'ME' => 'Montenegro',       'MX' => 'Mexiko',
        'MY' => 'Malaysia',         'NL' => 'Niederlande',
        'NO' => 'Norwegen',         'NZ' => 'Neuseeland',
        'PE' => 'Peru',             'PH' => 'Philippinen',
        'PK' => 'Pakistan',         'PL' => 'Polen',
        'PT' => 'Portugal',         'RO' => 'Rumänien',
        'RS' => 'Serbien',          'RU' => 'Russland',
        'SE' => 'Schweden',         'SG' => 'Singapur',
        'SI' => 'Slowenien',        'SK' => 'Slowakei',
        'SY' => 'Syrien',           'TH' => 'Thailand',
        'TN' => 'Tunesien',         'TR' => 'Türkei',
        'TW' => 'Taiwan',           'UA' => 'Ukraine',
        'US' => 'Vereinigte Staaten', 'VN' => 'Vietnam',
        'YE' => 'Jemen',            'ZA' => 'Südafrika',
    ];

    return $fallback[$code] ?? $code;
}

/**
 * Format seconds as MM:SS
 */
function pixl_format_minutes_seconds(int $seconds): string
{
    $seconds = max(0, $seconds);
    $minutes = intdiv($seconds, 60);
    $secs = $seconds % 60;
    return $minutes . ':' . str_pad((string)$secs, 2, '0', STR_PAD_LEFT);
}

// ============================================================================
// Database Query Functions
// ============================================================================

/**
 * Fetch a single scalar value from the database
 */
function pixl_fetch_value(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

/**
 * Fetch multiple rows from the database
 */
function pixl_fetch_rows(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Fetch DashboardX2-style metrics from the same MySQL table as pixl_stats.php.
 */
function pixl_dashboardx2_summary(PDO $pdo, string $table, int $days): array
{
    $empty = [
        'ok' => false,
        'error' => '',
        'kpi' => [],
        'topPagesToday' => [],
        'recent' => [],
    ];

    try {
        $fetchColumn = static function (PDO $db, string $sql, array $params = []) {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchColumn();
        };
        $fetchRows = static function (PDO $db, string $sql, array $params = []): array {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        };

        $nowBerlin = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
        $todayStart = $nowBerlin->setTime(0, 0, 0);
        $tomorrowStart = $todayStart->modify('+1 day');
        $todayStartUtc = $todayStart->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $tomorrowStartUtc = $tomorrowStart->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $sinceUtc = $nowBerlin->modify('-' . $days . ' days')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $scoreExpr = "CASE
            WHEN `v3_user_score` IS NOT NULL THEN LEAST(1, GREATEST(0, `v3_user_score`))
            WHEN `reading_score` IS NOT NULL THEN LEAST(1, GREATEST(0, `reading_score` / 100))
            ELSE NULL
        END";
        $mlExpr = "CASE WHEN ($scoreExpr) >= 0.7 THEN 1 ELSE 0 END";
        $gExpr = "CASE
            WHEN ($scoreExpr) IS NULL THEN NULL
            ELSE LEAST(1, GREATEST(0, (0.75 * ($scoreExpr)) + (0.25 * ($mlExpr))))
        END";
        $pageExpr = "COALESCE(NULLIF(`page_url`, ''), NULLIF(`path`, ''), '/')";

        $todayParams = [':a' => $todayStartUtc, ':b' => $tomorrowStartUtc];
        $sinceParams = [':s' => $sinceUtc];

        $kpi = [
            'eventsToday' => (int)$fetchColumn($pdo, "SELECT COUNT(*) FROM `$table` WHERE `created_at` >= :a AND `created_at` < :b", $todayParams),
            'humansToday' => (int)$fetchColumn($pdo, "SELECT COUNT(*) FROM `$table` WHERE `created_at` >= :a AND `created_at` < :b AND `is_bot` = 0", $todayParams),
            'botsToday' => (int)$fetchColumn($pdo, "SELECT COUNT(*) FROM `$table` WHERE `created_at` >= :a AND `created_at` < :b AND `is_bot` = 1", $todayParams),
            'uniqueIpsToday' => (int)$fetchColumn($pdo, "SELECT COUNT(DISTINCT `visitor_hash`) FROM `$table` WHERE `created_at` >= :a AND `created_at` < :b AND `visitor_hash` <> ''", $todayParams),
            'pagesToday' => (int)$fetchColumn($pdo, "SELECT COUNT(DISTINCT $pageExpr) FROM `$table` WHERE `created_at` >= :a AND `created_at` < :b AND $pageExpr <> ''", $todayParams),
            'eventsTotal' => (int)$fetchColumn($pdo, "SELECT COUNT(*) FROM `$table`"),
            'humansTotal' => (int)$fetchColumn($pdo, "SELECT COUNT(*) FROM `$table` WHERE `is_bot` = 0"),
            'botsTotal' => (int)$fetchColumn($pdo, "SELECT COUNT(*) FROM `$table` WHERE `is_bot` = 1"),
            'avgScore' => (float)($fetchColumn($pdo, "SELECT COALESCE(AVG($scoreExpr), 0) FROM `$table`") ?: 0),
            'lastEvent' => (string)($fetchColumn($pdo, "SELECT MAX(`created_at`) FROM `$table`") ?: ''),
        ];

        $topPagesToday = $fetchRows(
            $pdo,
            "SELECT $pageExpr AS url, COUNT(*) AS count
             FROM `$table`
             WHERE `created_at` >= :a AND `created_at` < :b AND $pageExpr <> ''
             GROUP BY url
             ORDER BY count DESC
             LIMIT " . QUERY_LIMIT_DASHBOARDX2_PAGES,
            $todayParams
        );

        $recent = $fetchRows(
            $pdo,
            "SELECT `created_at`,
                    $pageExpr AS url,
                    `referrer`,
                    `language` AS lang,
                    `screen`,
                    `viewport`,
                    CASE WHEN `is_bot` = 1 THEN 0 ELSE 1 END AS human,
                    `is_bot` AS bot,
                    $scoreExpr AS score,
                    CASE
                        WHEN `session_duration` IS NULL THEN NULL
                        ELSE `session_duration` * 1000
                    END AS dwell_ms,
                    $gExpr AS g_score,
                    $mlExpr AS ml_score,
                    1 AS tracked,
                    `user_agent` AS ua
             FROM `$table`
             WHERE `created_at` >= :s
             ORDER BY `created_at` DESC
             LIMIT " . QUERY_LIMIT_DASHBOARDX2_EVENTS,
            $sinceParams
        );

        return [
            'ok' => true,
            'error' => '',
            'kpi' => $kpi,
            'topPagesToday' => $topPagesToday,
            'recent' => $recent,
        ];
    } catch (Throwable $e) {
        $empty['error'] = $e->getMessage();
        return $empty;
    }
}

function pixl_short_text($value, int $max = 80): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '—';
    }
    if (strlen($value) <= $max) {
        return $value;
    }
    return substr($value, 0, max(1, $max - 1)) . '…';
}

/**
 * Transform raw event row into API response format
 */
function pixl_stats_notify_event(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'createdAt' => (string)$row['created_at'],
        'createdTs' => (int)$row['created_ts'],
        'source' => (string)$row['source'],
        'reason' => (string)$row['reason'],
        'title' => (string)$row['title'],
        'hostname' => (string)$row['hostname'],
        'path' => (string)$row['path'],
        'browser' => (string)$row['browser'],
        'os' => (string)$row['os'],
        'device' => (string)$row['device'],
        'country' => pixl_display_country($row['country']),
        'isBot' => (int)$row['is_bot'] === 1,
        'botScore' => (int)$row['bot_score'],
        'botName' => (string)$row['bot_name'],
        'uaContainsBot' => (int)$row['ua_contains_bot'] === 1,
        'readingScore' => $row['reading_score'] !== null ? (int)$row['reading_score'] : null,
        'sessionDuration' => $row['session_duration'] !== null ? (int)$row['session_duration'] : null,
        'userAgent' => (string)$row['user_agent'],
    ];
}

// ============================================================================
// Input Validation & Sanitization
// ============================================================================

/**
 * Validate and return days parameter
 */
function pixl_validate_days($value): int
{
    $days = (int)($value ?? DAYS_DEFAULT);
    return max(DAYS_MIN, min(DAYS_MAX, $days));
}

/**
 * Validate and return bot filter parameter
 */
function pixl_validate_bot_filter($value): string
{
    $filter = (string)($value ?? BOT_FILTER_DEFAULT);
    return in_array($filter, BOT_FILTERS, true) ? $filter : BOT_FILTER_DEFAULT;
}

// ============================================================================
// Notification Feed Endpoint
// ============================================================================

if (isset($_GET['notify_feed'])) {
    $after = max(0, (int)($_GET['after'] ?? 0));
    
    $latestId = pixl_fetch_value($pdo, "SELECT COALESCE(MAX(id), 0) FROM `$table`");
    $latestEventTs = pixl_fetch_value($pdo, "SELECT COALESCE(UNIX_TIMESTAMP(MAX(created_at)), 0) FROM `$table`");
    
    $events = pixl_fetch_rows(
        $pdo,
        "SELECT id, created_at, UNIX_TIMESTAMP(created_at) AS created_ts,
                source, reason, title, hostname, path, browser, os,
                device, country, is_bot, bot_score, bot_name, reading_score,
                session_duration, user_agent,
                CASE WHEN LOWER(user_agent) LIKE '%bot%' THEN 1 ELSE 0 END AS ua_contains_bot
         FROM `$table`
         WHERE id > :after
         ORDER BY id ASC
         LIMIT 25",
        [':after' => $after]
    );

    pixl_json_response([
        'ok' => true,
        'latestId' => $latestId,
        'latestEventTs' => $latestEventTs,
        'events' => array_map('pixl_stats_notify_event', $events),
    ]);
}

// ============================================================================
// Dashboard Data Collection
// ============================================================================

$days = pixl_validate_days($_GET['days'] ?? null);
$botFilter = pixl_validate_bot_filter($_GET['bot'] ?? null);

$since = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
    ->modify('-' . $days . ' days')
    ->format('Y-m-d H:i:s');

$where = 'created_at >= :since';
$params = [':since' => $since];

match ($botFilter) {
    'bots' => $where .= ' AND is_bot = 1',
    'humans' => $where .= ' AND is_bot = 0',
    default => null
};

// Key metrics
$totalAll = pixl_fetch_value($pdo, "SELECT COUNT(*) FROM `$table` WHERE created_at >= :since", [':since' => $since]);
$latestEventId = pixl_fetch_value($pdo, "SELECT COALESCE(MAX(id), 0) FROM `$table`");
$latestEventTs = pixl_fetch_value($pdo, "SELECT COALESCE(UNIX_TIMESTAMP(MAX(created_at)), 0) FROM `$table`");
$botAll = pixl_fetch_value($pdo, "SELECT COUNT(*) FROM `$table` WHERE created_at >= :since AND is_bot = 1", [':since' => $since]);
$humanAll = max(0, $totalAll - $botAll);
$botPercent = $totalAll > 0 ? round(($botAll / $totalAll) * 100) : 0;

// Filtered metrics
$total = pixl_fetch_value($pdo, "SELECT COUNT(*) FROM `$table` WHERE $where", $params);
$uniqueVisitors = pixl_fetch_value($pdo, "SELECT COUNT(DISTINCT visitor_hash) FROM `$table` WHERE $where AND visitor_hash <> ''", $params);
$avgReading = pixl_fetch_value($pdo, "SELECT COALESCE(ROUND(AVG(reading_score)), 0) FROM `$table` WHERE $where AND reading_score IS NOT NULL", $params);
$avgDuration = pixl_fetch_value($pdo, "SELECT COALESCE(ROUND(AVG(session_duration)), 0) FROM `$table` WHERE $where AND session_duration IS NOT NULL", $params);
$avgMessageEvery = pixl_fetch_value(
    $pdo,
    "SELECT COALESCE(ROUND(
        CASE WHEN COUNT(*) > 1
          THEN (UNIX_TIMESTAMP(MAX(created_at)) - UNIX_TIMESTAMP(MIN(created_at))) / (COUNT(*) - 1)
          ELSE 0
        END
      ), 0)
     FROM `$table`
     WHERE $where",
    $params
);

// Data breakdowns
$byDay = pixl_fetch_rows(
    $pdo,
    "SELECT DATE(created_at) AS label, COUNT(*) AS count, SUM(is_bot) AS bots
     FROM `$table`
     WHERE $where
     GROUP BY DATE(created_at)
     ORDER BY label DESC
     LIMIT " . QUERY_LIMIT_DAYS,
    $params
);

$dayMax = 1;
foreach ($byDay as $row) {
    $dayMax = max($dayMax, (int)$row['count']);
}

$topPaths = pixl_fetch_rows(
    $pdo,
    "SELECT path AS label, COUNT(*) AS count, SUM(is_bot) AS bots, ROUND(AVG(reading_score)) AS avg_score
     FROM `$table`
     WHERE $where
     GROUP BY path
     ORDER BY count DESC
     LIMIT " . QUERY_LIMIT_PATHS,
    $params
);

$breakdowns = [
    'Quelle' => 'source',
    'Event' => 'reason',
    'Bot Kategorie' => 'bot_category',
    'Bot Name' => 'bot_name',
    'Browser' => 'browser',
    'OS' => 'os',
    'Device' => 'device',
    'Land' => 'country',
    'Reading' => 'reading_label',
];

$breakdownRows = [];
foreach ($breakdowns as $title => $column) {
    $breakdownRows[$title] = pixl_fetch_rows(
        $pdo,
        "SELECT `$column` AS label, COUNT(*) AS count, SUM(is_bot) AS bots
         FROM `$table`
         WHERE $where
         GROUP BY `$column`
         ORDER BY count DESC
         LIMIT " . QUERY_LIMIT_BREAKDOWNS,
        $params
    );
}

$topUserAgents = pixl_fetch_rows(
    $pdo,
    "SELECT user_agent AS label, COUNT(*) AS count, MAX(is_bot) AS is_bot,
            MAX(bot_score) AS bot_score, MAX(bot_category) AS bot_category,
            MAX(bot_name) AS bot_name,
            MAX(CASE WHEN LOWER(user_agent) LIKE '%bot%' THEN 1 ELSE 0 END) AS ua_contains_bot
     FROM `$table`
     WHERE $where AND user_agent <> ''
     GROUP BY user_agent
     ORDER BY count DESC
     LIMIT " . QUERY_LIMIT_USER_AGENTS,
    $params
);

$recentBots = pixl_fetch_rows(
    $pdo,
    "SELECT created_at, source, reason, hostname, path, bot_score, bot_category,
            bot_name, bot_reasons, user_agent, request_method, request_uri,
            CASE WHEN LOWER(user_agent) LIKE '%bot%' THEN 1 ELSE 0 END AS ua_contains_bot
     FROM `$table`
     WHERE created_at >= :since AND is_bot = 1
     ORDER BY created_at DESC
     LIMIT " . QUERY_LIMIT_BOTS,
    [':since' => $since]
);

$recent = pixl_fetch_rows(
    $pdo,
    "SELECT created_at, source, reason, title, hostname, path, browser, os, device, country,
            session_duration, reading_label, reading_seconds, reading_score,
            render_status, console_error_count, dialog_error_count,
            is_bot, bot_score, bot_category, bot_name, bot_reasons,
            user_agent, request_method, request_uri,
            CASE WHEN LOWER(user_agent) LIKE '%bot%' THEN 1 ELSE 0 END AS ua_contains_bot
     FROM `$table`
     WHERE $where
     ORDER BY created_at DESC
     LIMIT " . QUERY_LIMIT_EVENTS,
    $params
);

$dashboardx2 = pixl_dashboardx2_summary($pdo, $table, $days);
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Pixl SQL Statistik Dashboard für Website-Analysen">
  <meta name="theme-color" content="#0f766e">
  <title>Pixl SQL Statistik</title>
  <style>
    :root {
      color-scheme: light dark;
      --bg-light: #f5f7f2;
      --bg-dark: #181917;
      --panel-light: #ffffff;
      --panel-dark: #242621;
      --ink-light: #202522;
      --ink-dark: #f6f7f2;
      --muted-light: #65706a;
      --muted-dark: #a7afa8;
      --line-light: #dce3dc;
      --line-dark: #3d433d;
      --accent: #0f766e;
      --accent-soft: #dff5ef;
      --bot: #6d5bd0;
      --warn: #b45309;
      --ok: #16a34a;
      --focus: #0f766e;

      --bg: var(--bg-light);
      --panel: var(--panel-light);
      --ink: var(--ink-light);
      --muted: var(--muted-light);
      --line: var(--line-light);
    }

    @media (prefers-color-scheme: dark) {
      :root {
        --bg: var(--bg-dark);
        --panel: var(--panel-dark);
        --ink: var(--ink-dark);
        --muted: var(--muted-dark);
        --line: var(--line-dark);
      }
    }

    * {
      box-sizing: border-box;
    }

    html {
      scroll-behavior: smooth;
    }

    body {
      margin: 0;
      font: 14px / 1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", "Helvetica Neue", sans-serif;
      color: var(--ink);
      background: var(--bg);
      transition: background-color 200ms ease, color 200ms ease;
    }

    header {
      position: sticky;
      top: 0;
      z-index: 30;
      padding: 1rem clamp(1rem, 4vw, 2.5rem) 1rem;
      background: var(--panel);
      border-bottom: 1px solid var(--line);
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    }

    h1, h2 {
      margin: 0;
      line-height: 1.2;
      letter-spacing: 0;
    }

    h1 {
      font-size: 1.75rem;
      font-weight: 700;
      color: var(--accent);
    }

    h2 {
      font-size: 1rem;
      font-weight: 600;
      color: var(--ink);
    }

    main {
      width: min(1480px, 100%);
      margin: 0 auto;
      padding: 1.5rem clamp(1rem, 3vw, 2rem) 2.5rem;
    }

    .toolbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      flex-wrap: wrap;
      margin-top: 1rem;
      font-size: 0.875rem;
      color: var(--muted);
    }

    .toolbar form {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      flex-wrap: wrap;
    }

    .filter-stack {
      display: flex;
      flex-direction: column;
      align-items: flex-end;
      gap: 0.5rem;
    }

    .notify-tools {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      flex-wrap: wrap;
    }

    .notify-status {
      color: var(--muted);
      font-size: 0.8125rem;
      font-weight: 500;
      min-width: 160px;
    }

    .notify-mute {
      display: inline-flex;
      align-items: center;
      gap: 0.375rem;
      min-height: 2.25rem;
      padding: 0.5rem 0.75rem;
      color: var(--ink);
      font-size: 0.8125rem;
      font-weight: 600;
      white-space: nowrap;
      cursor: pointer;
      border-radius: 6px;
      transition: background-color 150ms ease;
    }

    .notify-mute:hover {
      background: var(--accent-soft);
    }

    .notify-mute input {
      width: 1rem;
      height: 1rem;
      margin: 0;
      padding: 0;
      accent-color: var(--accent);
      cursor: pointer;
    }

    input, select, button, .button {
      min-height: 2.25rem;
      border: 1px solid var(--line);
      border-radius: 6px;
      padding: 0.5rem 0.75rem;
      font: inherit;
      background: var(--panel);
      color: var(--ink);
      transition: all 150ms ease;
    }

    input:focus, select:focus {
      outline: none;
      border-color: var(--focus);
      box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.1);
    }

    button, .button {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      color: #fff;
      text-decoration: none;
      border-color: var(--accent);
      background: var(--accent);
      cursor: pointer;
      font-weight: 500;
      gap: 0.5rem;
    }

    button:hover, .button:hover {
      background: color-mix(in srgb, var(--accent) 90%, black);
    }

    button:active, .button:active {
      transform: scale(0.98);
    }

    button:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }

    .button.secondary {
      color: var(--ink);
      border-color: var(--line);
      background: var(--panel);
    }

    .button.secondary:hover {
      background: var(--accent-soft);
      border-color: var(--accent);
    }

    .button.notify-active,
    button.notify-active {
      border-color: var(--ok);
      background: var(--ok);
    }

    .button.notify-active:hover,
    button.notify-active:hover {
      background: color-mix(in srgb, var(--ok) 90%, black);
    }

    .stats {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 1rem;
      margin-bottom: 1.5rem;
    }

    .metric {
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: 8px;
      padding: 1.25rem;
      min-height: 110px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      transition: all 200ms ease;
    }

    .metric:hover {
      border-color: var(--accent);
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    }

    .metric span {
      display: block;
      color: var(--muted);
      font-size: 0.75rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0;
    }

    .metric strong {
      display: block;
      margin-top: 0.75rem;
      font-size: 1.875rem;
      line-height: 1.1;
      color: var(--accent);
    }

    .grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 1rem;
      margin-bottom: 1.5rem;
    }

    section {
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: 8px;
      overflow: hidden;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    }

    section h2 {
      padding: 1rem;
      border-bottom: 1px solid var(--line);
      background: color-mix(in srgb, var(--panel) 100%, var(--accent) 2%);
    }

    .section-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      padding: 1rem;
      border-bottom: 1px solid var(--line);
      background: color-mix(in srgb, var(--panel) 100%, var(--accent) 2%);
    }

    .section-head h2 {
      padding: 0;
      border: 0;
      background: transparent;
    }

    .dashboardx2-body {
      padding: 1rem;
    }

    .dashboardx2-body .stats {
      grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
      margin-bottom: 1rem;
    }

    .dashboardx2-columns {
      display: grid;
      grid-template-columns: minmax(0, 0.85fr) minmax(0, 1.15fr);
      gap: 1rem;
      align-items: start;
    }

    .dashboardx2-panel {
      min-width: 0;
    }

    .dashboardx2-panel h3 {
      margin: 0 0 0.75rem;
      font-size: 0.875rem;
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: 0;
    }

    .dashboardx2-note {
      margin: 0;
      padding: 1rem;
    }

    table {
      width: 100%;
      border-collapse: collapse;
    }

    th, td {
      padding: 0.75rem 1rem;
      border-bottom: 1px solid var(--line);
      text-align: left;
      vertical-align: middle;
      overflow-wrap: anywhere;
    }

    th {
      color: var(--muted);
      font-size: 0.75rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0;
      background: color-mix(in srgb, var(--panel) 100%, var(--accent) 3%);
      position: sticky;
      top: -1px;
      z-index: 10;
    }

    tr:last-child td {
      border-bottom: 0;
    }

    tbody tr {
      transition: background-color 150ms ease;
    }

    tbody tr:hover {
      background: color-mix(in srgb, var(--panel) 100%, var(--accent) 5%);
    }

    details summary {
      cursor: pointer;
      color: var(--accent);
      font-weight: 600;
      user-select: none;
      padding: 0.5rem;
      border-radius: 4px;
      transition: all 150ms ease;
    }

    details summary:hover {
      background: var(--accent-soft);
    }

    code {
      display: block;
      white-space: pre-wrap;
      margin-top: 0.25rem;
      padding: 0.5rem;
      border: 1px solid var(--line);
      border-radius: 6px;
      background: color-mix(in srgb, var(--panel) 100%, var(--accent) 3%);
      color: var(--ink);
      font: 0.75rem ui-monospace, "SF Mono", Monaco, "Cascadia Code", monospace;
      line-height: 1.4;
      overflow-x: auto;
    }

    .bar {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      min-width: 120px;
    }

    .bar i {
      display: block;
      height: 8px;
      min-width: 2px;
      border-radius: 999px;
      background: var(--accent);
      transition: width 300ms ease;
    }

    .badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 1.5rem;
      min-height: 1.5rem;
      padding: 0.125rem 0.5rem;
      border-radius: 999px;
      font-size: 0.75rem;
      font-weight: 700;
      background: var(--line);
      color: var(--muted);
    }

    .badge.bot {
      color: #fff;
      background: var(--bot);
    }

    .badge.human {
      color: #fff;
      background: var(--ok);
    }

    .bot-dot {
      width: 0.75rem;
      min-width: 0.75rem;
      height: 0.75rem;
      min-height: 0.75rem;
      padding: 0;
      vertical-align: middle;
    }

    .badge.ua-hit {
      color: #fff;
      background: var(--warn);
    }

    .event-log table {
      table-layout: fixed;
    }

    .event-log tbody tr.is-bot {
      background: color-mix(in srgb, var(--bot) 10%, transparent);
    }

    .event-log tbody tr.is-bot:hover {
      background: color-mix(in srgb, var(--bot) 15%, transparent);
    }

    .event-time {
      color: var(--muted);
      font: 0.75rem ui-monospace, "SF Mono", Monaco, monospace;
      white-space: nowrap;
    }

    .event-source,
    .event-number {
      white-space: nowrap;
    }

    .event-country {
      overflow-wrap: anywhere;
    }

    .event-title {
      font-weight: 600;
    }

    .event-path {
      color: var(--accent);
      font-weight: 500;
    }

    .event-client {
      color: var(--muted);
      font-size: 0.875rem;
    }

    .event-log th,
    .event-log td {
      white-space: nowrap;
      overflow-wrap: normal;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .event-log .event-country {
      white-space: normal;
      overflow-wrap: anywhere;
    }

    .event-col-source { width: clamp(40px, 4vw, 56px); }
    .event-col-bot { width: 34px; text-align: center; }
    .event-col-event { width: clamp(46px, 4.5vw, 68px); }
    .event-col-country { width: clamp(120px, 10vw, 170px); }
    .event-col-duration { width: 54px; text-align: right; }
    .event-col-score { width: 36px; text-align: right; }
    .event-col-status { width: clamp(54px, 5vw, 76px); }
    .event-col-textbot { width: 54px; text-align: center; }

    .event-status {
      display: inline-flex;
      align-items: center;
      max-width: 140px;
      min-height: 1.5rem;
      padding: 0.125rem 0.5rem;
      border-radius: 999px;
      color: var(--muted);
      background: var(--line);
      font-size: 0.75rem;
      font-weight: 600;
      text-overflow: ellipsis;
      white-space: nowrap;
      overflow: hidden;
    }

    .event-status.warn {
      color: #fff;
      background: var(--warn);
    }

    .ua-trigger {
      min-height: 1.5rem;
      padding: 0.25rem 0.5rem;
      border: 1px solid var(--line);
      border-radius: 6px;
      color: var(--accent);
      background: var(--panel);
      font-size: 0.75rem;
      font-weight: 700;
      white-space: nowrap;
      cursor: pointer;
      transition: all 150ms ease;
    }

    .ua-trigger:hover, .ua-trigger:focus {
      border-color: var(--accent);
      background: var(--accent-soft);
      outline: none;
    }

    .ua-overlay[hidden] {
      display: none;
    }

    .ua-overlay {
      position: fixed;
      top: 1rem;
      left: 1rem;
      width: min(980px, calc(100vw - 2rem));
      z-index: 50;
      pointer-events: none;
      animation: slideIn 300ms cubic-bezier(0.34, 1.56, 0.64, 1);
    }

    @keyframes slideIn {
      from {
        opacity: 0;
        transform: translateY(-10px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .ua-panel {
      width: 100%;
      padding: 1rem;
      border: 1px solid var(--line);
      border-radius: 8px;
      background: var(--panel);
      box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
      pointer-events: auto;
    }

    .ua-panel-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      margin-bottom: 1rem;
    }

    .ua-panel-head strong {
      font-size: 0.875rem;
      color: var(--ink);
      font-weight: 600;
    }

    .ua-close {
      min-height: 1.75rem;
      padding: 0.25rem 0.5rem;
      border: 1px solid var(--line);
      border-radius: 6px;
      color: var(--muted);
      background: var(--panel);
      font-size: 0.75rem;
      font-weight: 700;
      cursor: pointer;
      transition: all 150ms ease;
    }

    .ua-close:hover {
      background: var(--line);
      color: var(--ink);
    }

    .ua-panel code {
      display: block;
      max-height: 40vh;
      overflow: auto;
      padding: 0.75rem;
      border-radius: 6px;
      color: var(--ink);
      background: color-mix(in srgb, var(--panel) 100%, var(--accent) 3%);
      font: 0.8125rem ui-monospace, "SF Mono", Monaco, "Cascadia Code", monospace;
      white-space: pre-wrap;
      overflow-wrap: anywhere;
    }

    .ua-panel code + code {
      margin-top: 0.75rem;
      color: #fff;
      background: var(--warn);
    }

    .muted {
      color: var(--muted);
    }

    .warn {
      color: var(--warn);
      font-weight: 700;
    }

    .wide {
      margin-bottom: 1.5rem;
    }

    .scroll {
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
    }

    .empty-state {
      text-align: center;
      padding: 3rem 1rem;
      color: var(--muted);
    }

    .empty-state svg {
      width: 4rem;
      height: 4rem;
      margin: 0 auto 1rem;
      opacity: 0.3;
    }

    @media (max-width: 1080px) {
      .grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }

    @media (max-width: 768px) {
      header {
        padding: 1rem;
      }

      main {
        padding: 1rem;
      }

      .toolbar {
        flex-direction: column;
        align-items: stretch;
      }

      .toolbar form {
        flex-direction: column;
      }

      .filter-stack {
        align-items: stretch;
      }

      .stats {
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 0.75rem;
      }

      .grid {
        grid-template-columns: 1fr;
        gap: 0.75rem;
      }

      .dashboardx2-columns {
        grid-template-columns: 1fr;
      }

      th, td {
        padding: 0.5rem 0.75rem;
        font-size: 0.875rem;
      }

      .scroll {
        overflow-x: auto;
      }

      .ua-overlay {
        top: 0.5rem;
        left: 0.5rem;
        width: calc(100vw - 1rem);
      }

      .ua-panel {
        padding: 0.75rem;
      }
    }

    @media (max-width: 480px) {
      h1 {
        font-size: 1.5rem;
      }

      .stats {
        grid-template-columns: 1fr;
      }

      .metric {
        min-height: 90px;
        padding: 1rem;
      }

      .metric strong {
        font-size: 1.5rem;
      }

      .notify-tools {
        width: 100%;
        flex-direction: column;
      }

      button, .button {
        width: 100%;
      }
    }

    /* Accessibility improvements */
    :focus-visible {
      outline: 2px solid var(--focus);
      outline-offset: 2px;
    }

    button:focus-visible,
    .button:focus-visible,
    input:focus-visible,
    select:focus-visible {
      outline: 2px solid var(--focus);
      outline-offset: 0;
    }

    /* Print styles */
    @media print {
      body {
        background: white;
        color: black;
      }

      header, .notify-tools, .filter-stack, button, .button {
        display: none;
      }

      section {
        page-break-inside: avoid;
        border: 1px solid #ccc;
      }
    }
  </style>
</head>
<body>
  <header role="banner" class="dashboard-header">
    <div class="header-main">
      <div class="title-block">
        <span class="eyebrow">MySQL Analytics</span>
        <h1>Pixl SQL Statistik</h1>
        <div class="header-meta" aria-live="polite" aria-atomic="true">
          Tabelle <?= pixl_h($table) ?> · Seit <?= pixl_h($since) ?> UTC
        </div>
      </div>
      <div class="notify-tools">
        <button id="pixlNotificationButton" type="button" aria-label="Browser-Notifikation aktivieren">Browser-Notifikation aktivieren</button>
        <button id="pixlNotificationOffButton" type="button" class="secondary" aria-label="Browser-Notifikation ausschalten">Aus</button>
        <span id="pixlNotificationStatus" class="notify-status" aria-live="polite" aria-atomic="true">Aus</span>
      </div>
    </div>
    <div class="toolbar">
      <div class="filter-stack">
        <label class="notify-mute" for="pixlNotificationMute">
          <input id="pixlNotificationMute" type="checkbox" aria-label="Benachrichtigungston stummschalten">
          Ton aus
        </label>
        <form method="get" aria-label="Dashboard-Filter">
          <label for="days">Tage</label>
          <input id="days" name="days" type="number" min="<?= DAYS_MIN ?>" max="<?= DAYS_MAX ?>" value="<?= pixl_h($days) ?>" aria-label="Anzahl der Tage">
          <label for="bot">Filter</label>
          <select id="bot" name="bot" aria-label="Bot-Filter">
            <option value="all"<?= $botFilter === 'all' ? ' selected' : '' ?>>Alle</option>
            <option value="bots"<?= $botFilter === 'bots' ? ' selected' : '' ?>>Bots</option>
            <option value="humans"<?= $botFilter === 'humans' ? ' selected' : '' ?>>Menschen</option>
          </select>
          <button type="submit">Aktualisieren</button>
          <a class="button secondary" href="?logout=1">Logout</a>
        </form>
      </div>
    </div>
    <nav class="section-nav" aria-label="Dashboard-Bereiche">
      <a href="#dashboardx2">DashboardX2</a>
      <a href="#daily">Tage</a>
      <a href="#breakdowns">Breakdowns</a>
      <a href="#paths">Pfade</a>
      <a href="#agents">User-Agents</a>
      <a href="#bots">Bots</a>
      <a href="#events">Events</a>
    </nav>
  </header>
  <main role="main">
    <section class="stats" aria-label="Zusammenfassung">
      <article class="metric">
        <span>Events im Filter</span>
        <strong><?= pixl_h($total) ?></strong>
      </article>
      <article class="metric">
        <span>Besucher im Filter</span>
        <strong><?= pixl_h($uniqueVisitors) ?></strong>
      </article>
      <article class="metric">
        <span>Bots gesamt</span>
        <strong><?= pixl_h($botAll) ?></strong>
      </article>
      <article class="metric">
        <span>Botquote</span>
        <strong><?= pixl_h($botPercent) ?>%</strong>
      </article>
      <article class="metric">
        <span>Menschen gesamt</span>
        <strong><?= pixl_h($humanAll) ?></strong>
      </article>
      <article class="metric">
        <span>Reading Score Ø</span>
        <strong><?= pixl_h($avgReading) ?></strong>
      </article>
      <article class="metric">
        <span>Sessiondauer Ø</span>
        <strong><?= pixl_h($avgDuration) ?>s</strong>
      </article>
      <article class="metric">
        <span>Nachricht Ø alle</span>
        <strong><?= pixl_h(pixl_format_minutes_seconds($avgMessageEvery)) ?></strong>
      </article>
    </section>

    <section id="dashboardx2" class="wide dashboardx2-summary" aria-label="DashboardX2 Statistik">
      <div class="section-head">
        <h2>DashboardX2 · MySQL <?= pixl_h($table) ?></h2>
        <a class="button secondary" href="<?= pixl_h(DASHBOARDX2_RELATIVE_URL) ?>">DashboardX2 öffnen</a>
      </div>
      <?php if (!$dashboardx2['ok']): ?>
        <p class="dashboardx2-note muted">DashboardX2 MySQL-Statistik nicht verfügbar: <?= pixl_h($dashboardx2['error']) ?></p>
      <?php else: ?>
        <?php $x2Kpi = $dashboardx2['kpi']; ?>
        <div class="dashboardx2-body">
          <div class="stats" aria-label="DashboardX2 Zusammenfassung">
            <article class="metric">
              <span>Heute Events</span>
              <strong><?= pixl_h($x2Kpi['eventsToday'] ?? 0) ?></strong>
            </article>
            <article class="metric">
              <span>Heute Human</span>
              <strong><?= pixl_h($x2Kpi['humansToday'] ?? 0) ?></strong>
            </article>
            <article class="metric">
              <span>Heute Bots</span>
              <strong><?= pixl_h($x2Kpi['botsToday'] ?? 0) ?></strong>
            </article>
            <article class="metric">
              <span>Heute IPs</span>
              <strong><?= pixl_h($x2Kpi['uniqueIpsToday'] ?? 0) ?></strong>
            </article>
            <article class="metric">
              <span>Heute Seiten</span>
              <strong><?= pixl_h($x2Kpi['pagesToday'] ?? 0) ?></strong>
            </article>
            <article class="metric">
              <span>Gesamt Events</span>
              <strong><?= pixl_h($x2Kpi['eventsTotal'] ?? 0) ?></strong>
            </article>
            <article class="metric">
              <span>Score Ø</span>
              <strong><?= pixl_h(number_format((float)($x2Kpi['avgScore'] ?? 0), 2, ',', '.')) ?></strong>
            </article>
            <article class="metric">
              <span>Letztes Event</span>
              <strong><?= pixl_h($x2Kpi['lastEvent'] !== '' ? pixl_short_text($x2Kpi['lastEvent'], 16) : '—') ?></strong>
            </article>
          </div>

          <div class="dashboardx2-columns">
            <div class="dashboardx2-panel scroll">
              <h3>Top Seiten heute</h3>
              <table role="grid">
                <thead>
                  <tr>
                    <th scope="col">URL</th>
                    <th scope="col">Events</th>
                  </tr>
                </thead>
                <tbody>
                <?php if ($dashboardx2['topPagesToday']): ?>
                  <?php foreach ($dashboardx2['topPagesToday'] as $row): ?>
                    <tr>
                      <td title="<?= pixl_h($row['url']) ?>"><?= pixl_h(pixl_short_text($row['url'], 82)) ?></td>
                      <td><?= pixl_h($row['count']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr>
                    <td colspan="2" class="muted">Heute noch keine DashboardX2 Events.</td>
                  </tr>
                <?php endif; ?>
                </tbody>
              </table>
            </div>

            <div class="dashboardx2-panel scroll">
              <h3>Letzte DashboardX2 Events</h3>
              <table role="grid">
                <thead>
                  <tr>
                    <th scope="col">Zeit</th>
                    <th scope="col">Typ</th>
                    <th scope="col">Score</th>
                    <th scope="col">Dauer</th>
                    <th scope="col">URL</th>
                  </tr>
                </thead>
                <tbody>
                <?php if ($dashboardx2['recent']): ?>
                  <?php foreach ($dashboardx2['recent'] as $row): ?>
                    <?php
                      $isX2Bot = (int)($row['bot'] ?? 0) === 1;
                      $duration = is_numeric($row['dwell_ms'] ?? null) ? ((int)$row['dwell_ms']) / 1000 : null;
                    ?>
                    <tr>
                      <td class="event-time"><?= pixl_h($row['created_at']) ?></td>
                      <td><span class="badge <?= $isX2Bot ? 'bot' : 'human' ?>"><?= $isX2Bot ? 'Bot' : 'Human' ?></span></td>
                      <td class="event-number"><?= pixl_h(is_numeric($row['score'] ?? null) ? number_format((float)$row['score'], 2, ',', '.') : '—') ?></td>
                      <td class="event-number"><?= pixl_h($duration !== null ? number_format($duration, 0, ',', '.') . 's' : '—') ?></td>
                      <td title="<?= pixl_h($row['url']) ?>"><?= pixl_h(pixl_short_text($row['url'], 90)) ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr>
                    <td colspan="5" class="muted">Noch keine DashboardX2 Events im Zeitraum.</td>
                  </tr>
                <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </section>

    <section id="daily" class="wide" aria-label="Ereignisse pro Tag">
      <h2>Letzte Tage</h2>
      <table role="grid">
        <thead>
          <tr>
            <th scope="col">Tag</th>
            <th scope="col">Events</th>
            <th scope="col">Bots</th>
            <th scope="col">Verlauf</th>
          </tr>
        </thead>
        <tbody>
        <?php if ($byDay): ?>
          <?php foreach ($byDay as $row): ?>
            <tr>
              <td><?= pixl_h($row['label']) ?></td>
              <td><?= pixl_h($row['count']) ?></td>
              <td><?= pixl_h($row['bots']) ?></td>
              <td><span class="bar"><i style="width: <?= (int)round(((int)$row['count'] / $dayMax) * 100) ?>%" aria-hidden="true"></i></span></td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr>
            <td colspan="4" class="muted">Noch keine Daten.</td>
          </tr>
        <?php endif; ?>
        </tbody>
      </table>
    </section>

    <div id="breakdowns" class="grid" aria-label="Datenaufschlüsselungen">
      <?php foreach ($breakdowns as $title => $column): ?>
        <section>
          <h2><?= pixl_h($title) ?></h2>
          <table role="grid">
            <thead>
              <tr>
                <th scope="col">Wert</th>
                <th scope="col">Anzahl</th>
                <th scope="col">Bots</th>
              </tr>
            </thead>
            <tbody>
            <?php if ($breakdownRows[$title]): ?>
              <?php foreach ($breakdownRows[$title] as $row): ?>
                <tr>
                  <td><?= pixl_h($row['label'] !== '' ? ($title === 'Land' ? pixl_display_country($row['label']) : pixl_compact_label($row['label'])) : '—') ?></td>
                  <td><?= pixl_h($row['count']) ?></td>
                  <td><?= pixl_h($row['bots']) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="3" class="muted">Keine Daten.</td>
              </tr>
            <?php endif; ?>
            </tbody>
          </table>
        </section>
      <?php endforeach; ?>
    </div>

    <section id="paths" class="wide" aria-label="Top Seitenpfade">
      <h2>Top Pfade</h2>
      <table role="grid">
        <thead>
          <tr>
            <th scope="col">Pfad</th>
            <th scope="col">Events</th>
            <th scope="col">Bots</th>
            <th scope="col">Score Ø</th>
          </tr>
        </thead>
        <tbody>
        <?php if ($topPaths): ?>
          <?php foreach ($topPaths as $row): ?>
            <tr>
              <td><?= pixl_h($row['label'] !== '' ? $row['label'] : '/') ?></td>
              <td><?= pixl_h($row['count']) ?></td>
              <td><?= pixl_h($row['bots']) ?></td>
              <td><?= pixl_h($row['avg_score'] ?? 0) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr>
            <td colspan="4" class="muted">Noch keine Pfade.</td>
          </tr>
        <?php endif; ?>
        </tbody>
      </table>
    </section>

    <section id="agents" class="wide" aria-label="Top User-Agents">
      <h2>Top User-Agents</h2>
      <table role="grid">
        <thead>
          <tr>
            <th scope="col">User-Agent</th>
            <th scope="col">Text bot</th>
            <th scope="col">Anzahl</th>
            <th scope="col">Bot</th>
            <th scope="col">Score</th>
          </tr>
        </thead>
        <tbody>
        <?php if ($topUserAgents): ?>
          <?php foreach ($topUserAgents as $row): ?>
            <tr>
              <td><code><?= pixl_h($row['label']) ?></code></td>
              <td><?= (int)$row['ua_contains_bot'] === 1 ? '<span class="badge ua-hit" title="User-Agent enthält bot">✕</span>' : '<span class="muted">—</span>' ?></td>
              <td><?= pixl_h($row['count']) ?></td>
              <td>
                <span class="badge bot-dot <?= (int)$row['is_bot'] === 1 ? 'bot' : 'human' ?>" title="<?= (int)$row['is_bot'] === 1 ? 'Bot' : 'Human' ?>"></span>
              </td>
              <td><?= pixl_h($row['bot_score']) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr>
            <td colspan="5" class="muted">Noch keine User-Agents.</td>
          </tr>
        <?php endif; ?>
        </tbody>
      </table>
    </section>

    <section id="bots" class="wide scroll" aria-label="Letzte Bot-Aufrufe">
      <h2>Letzte Bot-Aufrufe</h2>
      <table role="grid">
        <thead>
          <tr>
            <th scope="col">Zeit</th>
            <th scope="col">Quelle</th>
            <th scope="col">Pfad</th>
            <th scope="col">Bot</th>
            <th scope="col">Score</th>
            <th scope="col">Gründe</th>
            <th scope="col">Text bot</th>
            <th scope="col">User-Agent</th>
          </tr>
        </thead>
        <tbody>
        <?php if ($recentBots): ?>
          <?php foreach ($recentBots as $row): ?>
            <tr>
              <td class="event-time"><?= pixl_h($row['created_at']) ?></td>
              <td><?= pixl_h($row['source'] . ' / ' . $row['reason']) ?></td>
              <td><?= pixl_h($row['hostname'] . $row['path']) ?></td>
              <td><span class="badge bot-dot bot" title="<?= pixl_h($row['bot_name'] ?: 'Bot') ?>"></span></td>
              <td><?= pixl_h($row['bot_score']) ?></td>
              <td><?= pixl_h($row['bot_reasons']) ?></td>
              <td><?= (int)$row['ua_contains_bot'] === 1 ? '<span class="badge ua-hit" title="User-Agent enthält bot">✕</span>' : '<span class="muted">—</span>' ?></td>
              <td><code><?= pixl_h($row['user_agent']) ?></code></td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr>
            <td colspan="8" class="muted">Keine Bot-Aufrufe im Zeitraum.</td>
          </tr>
        <?php endif; ?>
        </tbody>
      </table>
    </section>

    <section id="events" class="wide scroll event-log" aria-label="Letzte Events">
      <h2>Letzte Events</h2>
      <table role="grid">
        <thead>
          <tr>
            <th scope="col">Zeit</th>
            <th scope="col" class="event-col-source">Quelle</th>
            <th scope="col" class="event-col-bot">Bot</th>
            <th scope="col" class="event-col-event">Event</th>
            <th scope="col">Titel</th>
            <th scope="col">Pfad</th>
            <th scope="col">Client</th>
            <th scope="col" class="event-col-country">Land</th>
            <th scope="col" class="event-col-duration">Dauer</th>
            <th scope="col">Reading</th>
            <th scope="col" class="event-col-score">Score</th>
            <th scope="col" class="event-col-status">Status</th>
            <th scope="col" class="event-col-textbot">UA Bot</th>
            <th scope="col">Details</th>
          </tr>
        </thead>
        <tbody>
        <?php if ($recent): ?>
          <?php foreach ($recent as $row): ?>
            <?php $hasIssues = ((int)$row['console_error_count'] > 0 || (int)$row['dialog_error_count'] > 0 || $row['render_status'] !== 'OK'); ?>
            <tr class="<?= (int)$row['is_bot'] === 1 ? 'is-bot' : 'is-human' ?>">
              <td class="event-time"><?= pixl_h($row['created_at']) ?></td>
              <td class="event-source event-col-source"><?= pixl_h($row['source']) ?></td>
              <td class="event-col-bot" title="<?= (int)$row['is_bot'] === 1 ? 'Bot' : 'Human' ?>">
                <span class="badge bot-dot <?= (int)$row['is_bot'] === 1 ? 'bot' : 'human' ?>"></span>
              </td>
              <td class="event-source event-col-event"><?= pixl_h($row['reason']) ?></td>
              <td class="event-title"><?= pixl_h(pixl_display_title($row['title'])) ?></td>
              <td class="event-path"><?= pixl_h($row['path']) ?></td>
              <td class="event-client"><?= pixl_h(pixl_display_client($row['browser'], $row['os'], $row['device'])) ?></td>
              <td class="event-country event-col-country"><?= pixl_h(pixl_display_country($row['country'])) ?></td>
              <td class="event-number event-col-duration" style="text-align: right;"><?= pixl_h($row['session_duration']) ?>s</td>
              <td class="event-source"><?= pixl_h($row['reading_label']) ?><?= $row['reading_seconds'] !== null ? ' (' . pixl_h($row['reading_seconds']) . 's)' : '' ?></td>
              <td class="event-number event-col-score"><?= pixl_h($row['reading_score']) ?></td>
              <td class="event-col-status"><span class="event-status <?= $hasIssues ? 'warn' : '' ?>" title="<?= pixl_h($row['render_status']) ?>"><?= $hasIssues ? '⚠️ ERROR' : '✓ OK' ?></span></td>
              <td class="event-col-textbot"><?= (int)$row['ua_contains_bot'] === 1 ? '<span class="badge ua-hit" title="User-Agent enthält bot">✕</span>' : '<span class="muted">—</span>' ?></td>
              <td>
                <button
                  type="button"
                  class="ua-trigger"
                  data-user-agent="<?= pixl_h($row['user_agent']) ?>"
                  data-bot-reasons="<?= pixl_h($row['bot_reasons']) ?>"
                  aria-label="User-Agent und Bot-Gründe anzeigen"
                >UA</button>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr>
            <td colspan="14" class="muted">Noch keine Events im Zeitraum.</td>
          </tr>
        <?php endif; ?>
        </tbody>
      </table>
    </section>
  </main>

  <div id="uaOverlay" class="ua-overlay" hidden role="dialog" aria-label="User-Agent Details">
    <div class="ua-panel">
      <div class="ua-panel-head">
        <strong>User-Agent Informationen</strong>
        <button id="uaOverlayClose" class="ua-close" type="button" aria-label="Schließen">✕</button>
      </div>
      <code id="uaOverlayText" aria-label="User-Agent String"></code>
      <code id="uaOverlayReasons" hidden aria-label="Bot-Erkennungsgründe"></code>
    </div>
  </div>

  <script>
    (() => {
      "use strict";

      // Configuration
      const CONFIG = {
        pollIntervalMs: 15000,
        notificationDebounce: 300,
        storageKeys: {
          enabled: "pixlStatsBrowserNotificationsEnabled",
          muted: "pixlStatsBrowserNotificationsMuted",
          lastId: "pixlStatsBrowserNotificationsLastId",
          lastEventTs: "pixlStatsBrowserNotificationsLastEventTs"
        }
      };

      // DOM elements
      const DOM = {
        button: document.getElementById("pixlNotificationButton"),
        offButton: document.getElementById("pixlNotificationOffButton"),
        muteToggle: document.getElementById("pixlNotificationMute"),
        status: document.getElementById("pixlNotificationStatus"),
        uaOverlay: document.getElementById("uaOverlay"),
        uaOverlayText: document.getElementById("uaOverlayText"),
        uaOverlayReasons: document.getElementById("uaOverlayReasons"),
        uaOverlayClose: document.getElementById("uaOverlayClose")
      };

      // Verify all required DOM elements exist
      if (!Object.values(DOM).every(el => el)) {
        console.warn("Pixl: Required DOM elements missing");
        return;
      }

      // State management
      const state = {
        enabled: window.localStorage.getItem(CONFIG.storageKeys.enabled) === "1",
        muted: window.localStorage.getItem(CONFIG.storageKeys.muted) === "1",
        lastId: 0,
        lastEventTs: 0,
        initialLatestId: Number(<?= json_encode($latestEventId) ?>) || 0,
        initialLatestEventTs: Number(<?= json_encode($latestEventTs) ?>) || 0,
        timer: null,
        ageTimer: null,
        polling: false,
        refreshing: false,
        audioContext: null,
        audioUnlocked: false
      };

      // Initialize last IDs
      state.lastId = Math.max(
        Number(window.localStorage.getItem(CONFIG.storageKeys.lastId)) || 0,
        state.enabled ? 0 : state.initialLatestId
      );
      state.lastEventTs = Math.max(
        Number(window.localStorage.getItem(CONFIG.storageKeys.lastEventTs)) || 0,
        state.enabled ? 0 : state.initialLatestEventTs
      );

      // ====== Status & UI Functions ======

      function setStatus(text) {
        DOM.status.textContent = text;
      }

      function closeUserAgentOverlay() {
        DOM.uaOverlay.hidden = true;
      }

      function openUserAgentOverlay(trigger) {
        DOM.uaOverlayText.textContent = trigger.dataset.userAgent || "";
        DOM.uaOverlayReasons.textContent = trigger.dataset.botReasons || "";
        DOM.uaOverlayReasons.hidden = !trigger.dataset.botReasons;
        DOM.uaOverlay.hidden = false;

        // Position overlay
        const margin = 14;
        const rect = trigger.getBoundingClientRect();
        const panelWidth = Math.min(980, window.innerWidth - margin * 2);
        const left = Math.max(margin, Math.min(window.innerWidth - panelWidth - margin, rect.right - panelWidth));
        
        DOM.uaOverlay.style.width = `${panelWidth}px`;
        DOM.uaOverlay.style.left = `${Math.round(left)}px`;

        const panel = DOM.uaOverlay.querySelector(".ua-panel");
        const panelHeight = panel?.offsetHeight || 180;
        const top = Math.max(margin, Math.min(window.innerHeight - panelHeight - margin, rect.bottom + 78));
        DOM.uaOverlay.style.top = `${Math.round(top)}px`;
      }

      // ====== Event Listeners ======

      document.addEventListener("click", (event) => {
        const target = event.target;
        if (!(target instanceof Element)) return;

        const trigger = target.closest(".ua-trigger");
        if (trigger) {
          openUserAgentOverlay(trigger);
          return;
        }

        if (target === DOM.uaOverlay || target === DOM.uaOverlayClose || target.closest("#uaOverlayClose")) {
          closeUserAgentOverlay();
          return;
        }

        if (!DOM.uaOverlay.hidden && !target.closest(".ua-panel")) {
          closeUserAgentOverlay();
        }
      });

      document.addEventListener("keydown", (event) => {
        if (event.key === "Escape") {
          closeUserAgentOverlay();
        }
      });

      // ====== Storage Functions ======

      function saveLastId(value) {
        state.lastId = Math.max(state.lastId, Number(value) || 0);
        window.localStorage.setItem(CONFIG.storageKeys.lastId, String(state.lastId));
      }

      function saveLastEventTs(value) {
        state.lastEventTs = Math.max(state.lastEventTs, Number(value) || 0);
        window.localStorage.setItem(CONFIG.storageKeys.lastEventTs, String(state.lastEventTs));
      }

      function saveEventCheckpoint(event) {
        if (!event) return;
        saveLastId(event.id);
        saveLastEventTs(event.createdTs);
      }

      // ====== Time & Format Functions ======

      function lastEventAgeSeconds() {
        if (!state.lastEventTs) return null;
        return Math.max(0, Math.floor(Date.now() / 1000 - state.lastEventTs));
      }

      function formatEventAge(seconds) {
        if (seconds === null) return "?";
        if (seconds < 60) return `${seconds}s`;

        const minutes = Math.floor(seconds / 60);
        const remainingSeconds = seconds % 60;
        if (minutes < 60) {
          return `${minutes}m ${remainingSeconds}s`;
        }

        const hours = Math.floor(minutes / 60);
        const remainingMinutes = minutes % 60;
        return `${hours}h ${remainingMinutes}m`;
      }

      function activeStatusText(fallbackLatestId = 0) {
        const eventId = state.lastId || fallbackLatestId || state.initialLatestId;
        const age = lastEventAgeSeconds();
        const soundText = state.muted ? "ohne Ton" : "mit Ton";
        if (!eventId) {
          return `Aktiv ${soundText} alle 15s, noch kein Event`;
        }
        return `Aktiv ${soundText} alle 15s, letzter Event #${eventId}, vor ${formatEventAge(age)}`;
      }

      function setActiveStatus(fallbackLatestId = 0) {
        setStatus(activeStatusText(fallbackLatestId));
      }

      // ====== Preference Functions ======

      function setEnabled(value) {
        state.enabled = !!value;
        window.localStorage.setItem(CONFIG.storageKeys.enabled, state.enabled ? "1" : "0");
        DOM.button.classList.toggle("notify-active", state.enabled);
        DOM.offButton.disabled = !state.enabled;
        DOM.button.textContent = state.enabled
          ? "Browser-Notifikation aktiv"
          : "Browser-Notifikation aktivieren";
      }

      function setMuted(value) {
        state.muted = !!value;
        window.localStorage.setItem(CONFIG.storageKeys.muted, state.muted ? "1" : "0");
        DOM.muteToggle.checked = state.muted;
        if (state.enabled && supportsNotifications() && Notification.permission === "granted") {
          setActiveStatus();
        }
      }

      // ====== Capability Detection ======

      function supportsNotifications() {
        return "Notification" in window;
      }

      function getAudioContext() {
        const AudioContextCtor = window.AudioContext || window.webkitAudioContext;
        if (!AudioContextCtor) return null;
        if (!state.audioContext) {
          state.audioContext = new AudioContextCtor();
        }
        return state.audioContext;
      }

      // ====== Audio Functions ======

      async function unlockAudio() {
        const context = getAudioContext();
        if (!context) return false;

        try {
          if (context.state === "suspended") {
            await context.resume();
          }

          const gain = context.createGain();
          gain.gain.setValueAtTime(0.0001, context.currentTime);
          gain.connect(context.destination);

          const oscillator = context.createOscillator();
          oscillator.type = "sine";
          oscillator.frequency.setValueAtTime(880, context.currentTime);
          oscillator.connect(gain);
          oscillator.start(context.currentTime);
          oscillator.stop(context.currentTime + 0.01);

          state.audioUnlocked = true;
          return true;
        } catch (error) {
          console.warn("Audio unlock failed:", error);
          return false;
        }
      }

      function playNotificationSound() {
        if (state.muted) return;

        const context = getAudioContext();
        if (!context || (!state.audioUnlocked && context.state === "suspended")) {
          return;
        }

        try {
          const start = context.currentTime;
          const gain = context.createGain();
          gain.gain.setValueAtTime(0.0001, start);
          gain.gain.exponentialRampToValueAtTime(0.18, start + 0.015);
          gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.42);
          gain.connect(context.destination);

          const first = context.createOscillator();
          first.type = "sine";
          first.frequency.setValueAtTime(880, start);
          first.frequency.exponentialRampToValueAtTime(1175, start + 0.12);
          first.connect(gain);
          first.start(start);
          first.stop(start + 0.18);

          const second = context.createOscillator();
          second.type = "triangle";
          second.frequency.setValueAtTime(1320, start + 0.16);
          second.connect(gain);
          second.start(start + 0.16);
          second.stop(start + 0.42);
        } catch (error) {
          console.warn("Notification sound playback failed:", error);
        }
      }

      // ====== Permission & Notification Functions ======

      function requestPermission() {
        if (!supportsNotifications()) {
          return Promise.resolve("unsupported");
        }

        try {
          const result = Notification.requestPermission();
          if (result?.then) {
            return result;
          }
        } catch {
          // Fallback for older browsers
        }

        return new Promise((resolve) => {
          try {
            Notification.requestPermission(resolve);
          } catch {
            resolve("denied");
          }
        });
      }

      function feedUrl(after) {
        const url = new URL(window.location.href);
        url.search = "";
        url.searchParams.set("notify_feed", "1");
        url.searchParams.set("after", String(Math.max(0, Number(after) || 0)));
        url.searchParams.set("_", String(Date.now()));
        return url.toString();
      }

      async function fetchEvents(after) {
        try {
          const response = await fetch(feedUrl(after), {
            cache: "no-store",
            credentials: "same-origin"
          });
          if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
          }
          const data = await response.json();
          if (!data.ok) {
            throw new Error("Feed returned !ok");
          }
          return data;
        } catch (error) {
          console.error("Failed to fetch events:", error);
          throw error;
        }
      }

      async function refreshDashboard() {
        if (state.refreshing) return;
        state.refreshing = true;

        try {
          const url = new URL(window.location.href);
          url.searchParams.set("_refresh", String(Date.now()));
          const response = await fetch(url.toString(), {
            cache: "no-store",
            credentials: "same-origin"
          });
          if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
          }

          const html = await response.text();
          const doc = new DOMParser().parseFromString(html, "text/html");
          const nextMain = doc.querySelector("main");
          const currentMain = document.querySelector("main");
          if (nextMain && currentMain) {
            currentMain.innerHTML = nextMain.innerHTML;
          }
        } catch (error) {
          console.warn("Dashboard refresh failed:", error);
        } finally {
          state.refreshing = false;
        }
      }

      function eventBody(event) {
        const path = `${event.hostname || ""}${event.path || "/"}`;
        const client = [event.browser, event.os, event.device]
          .filter(Boolean)
          .join(" / ");
        const lines = [
          `${event.reason || "EVENT"} - ${path}`
        ];

        if (event.isBot) {
          lines.push(`Bot: ${event.botName || "erkannt"} (${event.botScore || 0})`);
        }
        if (client) {
          lines.push(client);
        }
        if (event.country) {
          lines.push(`Land: ${event.country}`);
        }
        if (event.readingScore !== null && event.readingScore !== undefined) {
          lines.push(`Reading Score: ${event.readingScore}`);
        }
        if (event.sessionDuration !== null && event.sessionDuration !== undefined) {
          lines.push(`Dauer: ${event.sessionDuration}s`);
        }

        return lines.join("\n");
      }

      function notifyEvent(event) {
        if (!supportsNotifications() || Notification.permission !== "granted") {
          return;
        }

        playNotificationSound();

        const title = event.title
          ? `Pixl: ${event.title}`
          : `Pixl: ${event.reason || "Event"}`;
        
        try {
          const notification = new Notification(title, {
            body: eventBody(event),
            tag: `pixl-event-${event.id}`,
            renotify: false,
            silent: false,
            data: {
              eventId: event.id,
              path: event.path || "/"
            }
          });

          notification.onclick = () => {
            window.focus();
            notification.close();
          };
        } catch (error) {
          console.error("Failed to create notification:", error);
        }
      }

      async function poll() {
        if (!state.enabled || !supportsNotifications() || Notification.permission !== "granted") {
          return;
        }
        if (state.polling) return;
        state.polling = true;

        try {
          const data = await fetchEvents(state.lastId);
          const events = Array.isArray(data.events) ? data.events : [];

          for (const event of events) {
            notifyEvent(event);
            saveEventCheckpoint(event);
          }

          if (events.length > 0) {
            await refreshDashboard();
          } else if (Number(data.latestEventTs) > 0 && Number(data.latestId) === state.lastId) {
            saveLastEventTs(data.latestEventTs);
          }

          setActiveStatus(Number(data.latestId) || 0);
        } catch (error) {
          console.error("Poll error:", error);
          setStatus("Aktiv, Feed aktuell nicht erreichbar");
        } finally {
          state.polling = false;
        }
      }

      function startPolling() {
        if (state.timer) return;
        poll();
        state.timer = window.setInterval(poll, CONFIG.pollIntervalMs);
        if (!state.ageTimer) {
          state.ageTimer = window.setInterval(() => {
            if (state.enabled && supportsNotifications() && Notification.permission === "granted") {
              setActiveStatus();
            }
          }, 1000);
        }
      }

      function stopPolling() {
        if (state.timer) {
          window.clearInterval(state.timer);
          state.timer = null;
        }
        if (state.ageTimer) {
          window.clearInterval(state.ageTimer);
          state.ageTimer = null;
        }
        state.polling = false;
      }

      function showTestNotification() {
        if (!supportsNotifications() || Notification.permission !== "granted") {
          return;
        }

        playNotificationSound();

        try {
          const test = new Notification("Pixl Browser-Notifikation aktiv", {
            body: state.muted
              ? "Neue Pixl-Events werden jetzt hier gemeldet - ohne lokalen Ton."
              : "Neue Pixl-Events werden jetzt hier gemeldet - mit Ton.",
            tag: "pixl-notification-ready",
            renotify: false
          });
          test.onclick = () => {
            window.focus();
            test.close();
          };
        } catch (error) {
          console.error("Failed to show test notification:", error);
        }
      }

      async function enableNotifications() {
        if (!supportsNotifications()) {
          setEnabled(false);
          setStatus("Dieser Browser unterstützt keine Notifications");
          return;
        }

        await unlockAudio();
        setStatus("Genehmigung wird angefragt...");
        const permission = await requestPermission();

        if (permission === "granted") {
          saveLastId(state.initialLatestId);
          saveLastEventTs(state.initialLatestEventTs);
          setEnabled(true);
          setActiveStatus();
          showTestNotification();
          startPolling();
          return;
        }

        setEnabled(false);
        setStatus(permission === "denied" ? "Vom Browser blockiert" : "Nicht erlaubt");
      }

      function disableNotifications() {
        stopPolling();
        setEnabled(false);
        saveLastId(state.initialLatestId);
        saveLastEventTs(state.initialLatestEventTs);
        setStatus("Aus");
      }

      // ====== Event Handlers ======

      DOM.button.addEventListener("click", enableNotifications);
      DOM.offButton.addEventListener("click", disableNotifications);
      DOM.muteToggle.addEventListener("change", () => {
        setMuted(DOM.muteToggle.checked);
      });

      setMuted(state.muted);

      if (!supportsNotifications()) {
        setEnabled(false);
        setStatus("Nicht unterstützt");
        return;
      }

      if (state.enabled && Notification.permission === "granted") {
        setEnabled(true);
        setActiveStatus(state.initialLatestId);
        startPolling();
      } else if (Notification.permission === "denied") {
        setEnabled(false);
        setStatus("Vom Browser blockiert");
      } else {
        setEnabled(false);
        saveLastId(state.initialLatestId);
        saveLastEventTs(state.initialLatestEventTs);
        setStatus("Aus");
      }
    })();
  </script>
</body>
</html>
