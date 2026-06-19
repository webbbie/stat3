<?php
declare(strict_types=1);

require __DIR__ . '/../pixl_server.php';

pixl_require_stats_auth();

const CHECK_DAYS_MIN = 1;
const CHECK_DAYS_MAX = 365;
const CHECK_DAYS_DEFAULT = 30;
const CHECK_GAP_MINUTES_DEFAULT = 30;
const CHECK_ROW_LIMIT_DEFAULT = 12000;
const CHECK_ROW_LIMIT_MAX = 50000;
const CHECK_MAX_LEVEL_DEFAULT = 6;
const CHECK_TABLE_LIMIT = 80;
const CHECK_PATH_LIMIT = 14;
const CHECK_CAMPAIGN_LIMIT = 60;

function check_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function check_int($value, int $default, int $min, int $max): int
{
    if ($value === null || $value === '' || is_array($value) || !is_numeric($value)) {
        return $default;
    }
    return max($min, min($max, (int)$value));
}

function check_audience($value): string
{
    $value = (string)($value ?? 'humans');
    return in_array($value, ['humans', 'bots', 'all'], true) ? $value : 'humans';
}

function check_rows(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function check_number($value): string
{
    return number_format((float)$value, 0, ',', '.');
}

function check_percent(int $part, int $total): string
{
    if ($total <= 0) {
        return '0%';
    }
    return number_format(($part / $total) * 100, 1, ',', '.') . '%';
}

function check_short($value, int $max = 70): string
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

function check_level_name(int $level): string
{
    return $level === 0 ? 'Landing' : 'Click ' . $level;
}

function check_page_label(string $url, string $path): string
{
    $url = trim($url);
    $path = trim($path);
    if ($url !== '') {
        $host = parse_url($url, PHP_URL_HOST);
        $urlPath = parse_url($url, PHP_URL_PATH);
        $host = is_string($host) ? preg_replace('/^www\./i', '', $host) : '';
        $urlPath = is_string($urlPath) && $urlPath !== '' ? $urlPath : '/';
        if ($host !== '') {
            return $host . $urlPath;
        }
        return $urlPath;
    }
    return $path !== '' ? pixl_url_without_parameters($path) : '/';
}

function check_query_values(string $url): array
{
    $query = parse_url($url, PHP_URL_QUERY);
    if (!is_string($query) || $query === '') {
        return [];
    }
    $values = [];
    parse_str($query, $values);
    return array_change_key_case($values, CASE_LOWER);
}

function check_query_value(array $values, array $keys): string
{
    foreach ($keys as $key) {
        $key = strtolower($key);
        if (!array_key_exists($key, $values)) {
            continue;
        }
        $value = $values[$key];
        if (is_array($value)) {
            $value = implode(',', array_slice(array_map('strval', $value), 0, 3));
        }
        $value = trim((string)$value);
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

function check_referrer_host(string $referrer): string
{
    $host = parse_url($referrer, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        return $referrer !== '' ? 'unknown' : 'direct';
    }
    return preg_replace('/^www\./i', '', $host) ?: $host;
}

function check_campaign_from_url(string $url, string $referrer): array
{
    $q = check_query_values($url);
    $source = check_query_value($q, ['utm_source', 'source', 'network']);
    $medium = check_query_value($q, ['utm_medium', 'medium']);
    $campaign = check_query_value($q, ['utm_campaign', 'campaign', 'campaign_name']);
    $content = check_query_value($q, ['utm_content', 'content']);
    $term = check_query_value($q, ['utm_term', 'term']);
    $campaignId = check_query_value($q, ['campaign_id', 'cid']);
    $creativeId = check_query_value($q, ['creative_id', 'ad_id', 'banner']);
    $trackingId = check_query_value($q, ['tracking_id', 'tid']);
    $gclid = check_query_value($q, ['gclid']);
    $fbclid = check_query_value($q, ['fbclid']);
    $hasUtm = $source !== '' || $medium !== '' || $campaign !== '' || $content !== '' || $term !== '' || $campaignId !== '' || $creativeId !== '' || $trackingId !== '' || $gclid !== '' || $fbclid !== '';

    if ($source === '') {
        $source = $hasUtm ? '(unknown)' : check_referrer_host($referrer);
    }
    if ($medium === '') {
        $medium = $hasUtm ? '(none)' : ($referrer !== '' ? 'referral' : 'direct');
    }
    if ($campaign === '') {
        $campaign = $campaignId !== '' ? $campaignId : ($hasUtm ? '(unnamed)' : '(none)');
    }

    return [
        'source' => $source,
        'medium' => $medium,
        'campaign' => $campaign,
        'content' => $content !== '' ? $content : '-',
        'term' => $term !== '' ? $term : '-',
        'campaign_id' => $campaignId !== '' ? $campaignId : '-',
        'creative_id' => $creativeId !== '' ? $creativeId : '-',
        'tracking_id' => $trackingId !== '' ? $trackingId : '-',
        'gclid' => $gclid !== '' ? 'yes' : '-',
        'fbclid' => $fbclid !== '' ? 'yes' : '-',
        'has_utm' => $hasUtm,
    ];
}

function check_campaign_key(array $campaign): string
{
    return implode('|', [
        $campaign['source'],
        $campaign['medium'],
        $campaign['campaign'],
        $campaign['content'],
        $campaign['term'],
        $campaign['campaign_id'],
        $campaign['creative_id'],
        $campaign['tracking_id'],
    ]);
}

function check_sort_assoc_count(array &$rows, string $countKey = 'sessions'): void
{
    uasort($rows, static function (array $a, array $b) use ($countKey): int {
        return ((int)($b[$countKey] ?? 0)) <=> ((int)($a[$countKey] ?? 0));
    });
}

function check_table_empty(int $cols, string $message): string
{
    return '<tr><td colspan="' . $cols . '" class="muted">' . check_h($message) . '</td></tr>';
}

function check_metric(string $label, string $value, string $note = ''): string
{
    $noteHtml = $note !== '' ? '<span>' . check_h($note) . '</span>' : '';
    return '<article class="metric"><p>' . check_h($label) . '</p><strong>' . check_h($value) . '</strong>' . $noteHtml . '</article>';
}

$days = check_int($_GET['days'] ?? null, CHECK_DAYS_DEFAULT, CHECK_DAYS_MIN, CHECK_DAYS_MAX);
$gapMinutes = check_int($_GET['gap'] ?? null, CHECK_GAP_MINUTES_DEFAULT, 5, 240);
$rowLimit = check_int($_GET['limit'] ?? null, CHECK_ROW_LIMIT_DEFAULT, 1000, CHECK_ROW_LIMIT_MAX);
$maxLevel = check_int($_GET['levels'] ?? null, CHECK_MAX_LEVEL_DEFAULT, 2, 12);
$audience = check_audience($_GET['audience'] ?? null);

$error = '';
$table = '';
$events = [];
$sessions = [];
$levelSummary = [];
$levelPages = [];
$pathCounts = [];
$campaigns = [];
$topTransitions = [];
$uniqueVisitors = [];

try {
    $pdo = pixl_pdo();
    pixl_ensure_schema($pdo);
    $table = pixl_table_name();
    $since = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->modify('-' . $days . ' days')
        ->format('Y-m-d H:i:s');

    $where = '`created_at` >= :since AND `visitor_hash` <> \'\'';
    if ($audience === 'humans') {
        $where .= ' AND `is_bot` = 0';
    } elseif ($audience === 'bots') {
        $where .= ' AND `is_bot` = 1';
    }

    $events = check_rows(
        $pdo,
        "SELECT `id`, `created_at`, `visitor_hash`, `page_url`, `path`, `referrer`, `title`,
                `source`, `reason`, `is_bot`
         FROM `$table`
         WHERE $where
         ORDER BY `visitor_hash` ASC, `created_at` ASC, `id` ASC
         LIMIT " . $rowLimit,
        [':since' => $since]
    );

    $gapSeconds = $gapMinutes * 60;
    $current = null;
    $sessionNumber = 0;

    $flush = static function () use (&$current, &$sessions): void {
        if ($current !== null && $current['path'] !== []) {
            $sessions[] = $current;
        }
        $current = null;
    };

    foreach ($events as $row) {
        $visitor = (string)$row['visitor_hash'];
        $ts = strtotime((string)$row['created_at']);
        if ($ts === false) {
            continue;
        }
        $label = check_page_label((string)($row['page_url'] ?? ''), (string)($row['path'] ?? ''));
        $rawUrl = (string)($row['page_url'] ?? '');
        if ($rawUrl === '') {
            $rawUrl = (string)($row['path'] ?? '');
        }

        $newSession = $current === null
            || $current['visitor'] !== $visitor
            || ($ts - (int)$current['last_ts']) > $gapSeconds;

        if ($newSession) {
            $flush();
            $sessionNumber++;
            $current = [
                'id' => $visitor . '-' . $sessionNumber,
                'visitor' => $visitor,
                'started_at' => (string)$row['created_at'],
                'ended_at' => (string)$row['created_at'],
                'last_ts' => $ts,
                'events' => 0,
                'path' => [],
                'last_label' => '',
            ];
            $uniqueVisitors[$visitor] = true;
        }

        $current['events']++;
        $current['ended_at'] = (string)$row['created_at'];
        $current['last_ts'] = $ts;

        if ($current['last_label'] !== $label) {
            $current['path'][] = [
                'label' => $label,
                'url' => pixl_url_without_parameters($rawUrl),
                'campaign_url' => $rawUrl,
                'referrer' => pixl_url_without_parameters($row['referrer'] ?? ''),
                'campaign_referrer' => (string)($row['referrer'] ?? ''),
                'title' => (string)($row['title'] ?? ''),
                'source' => (string)($row['source'] ?? ''),
                'reason' => (string)($row['reason'] ?? ''),
                'created_at' => (string)$row['created_at'],
            ];
            $current['last_label'] = $label;
        }
    }
    $flush();

    foreach ($sessions as $session) {
        $path = array_slice($session['path'], 0, $maxLevel + 1);
        $depth = max(0, count($path) - 1);
        $signature = implode(' > ', array_map(static fn(array $node): string => $node['label'], $path));
        if ($signature !== '') {
            if (!isset($pathCounts[$signature])) {
                $pathCounts[$signature] = [
                    'path' => $path,
                    'sessions' => 0,
                    'events' => 0,
                    'depth_total' => 0,
                ];
            }
            $pathCounts[$signature]['sessions']++;
            $pathCounts[$signature]['events'] += (int)$session['events'];
            $pathCounts[$signature]['depth_total'] += $depth;
        }

        for ($level = 0; $level <= $maxLevel; $level++) {
            if (!isset($levelSummary[$level])) {
                $levelSummary[$level] = [
                    'level' => $level,
                    'name' => check_level_name($level),
                    'reached' => 0,
                    'continued' => 0,
                    'exited' => 0,
                    'top_page' => '-',
                    'top_count' => 0,
                ];
            }
            if (isset($path[$level])) {
                $label = $path[$level]['label'];
                $levelSummary[$level]['reached']++;
                if (isset($path[$level + 1])) {
                    $levelSummary[$level]['continued']++;
                } else {
                    $levelSummary[$level]['exited']++;
                }
                if (!isset($levelPages[$level][$label])) {
                    $levelPages[$level][$label] = [
                        'level' => $level,
                        'name' => check_level_name($level),
                        'page' => $label,
                        'sessions' => 0,
                        'exits' => 0,
                    ];
                }
                $levelPages[$level][$label]['sessions']++;
                if (!isset($path[$level + 1])) {
                    $levelPages[$level][$label]['exits']++;
                }
            }
        }

        for ($level = 0, $count = count($path) - 1; $level < $count; $level++) {
            $from = $path[$level]['label'];
            $to = $path[$level + 1]['label'];
            $key = $level . '|' . $from . '|' . $to;
            if (!isset($topTransitions[$key])) {
                $topTransitions[$key] = [
                    'level' => $level,
                    'from' => $from,
                    'to' => $to,
                    'sessions' => 0,
                ];
            }
            $topTransitions[$key]['sessions']++;
        }

        $landing = $session['path'][0] ?? null;
        if ($landing !== null) {
            $campaign = check_campaign_from_url((string)$landing['campaign_url'], (string)$landing['campaign_referrer']);
            $key = check_campaign_key($campaign);
            if (!isset($campaigns[$key])) {
                $campaigns[$key] = $campaign + [
                    'sessions' => 0,
                    'events' => 0,
                    'clicks' => 0,
                    'bounces' => 0,
                    'landing_pages' => [],
                ];
            }
            $campaigns[$key]['sessions']++;
            $campaigns[$key]['events'] += (int)$session['events'];
            $campaigns[$key]['clicks'] += $depth;
            if ($depth === 0) {
                $campaigns[$key]['bounces']++;
            }
            $landingLabel = $landing['label'];
            $campaigns[$key]['landing_pages'][$landingLabel] = ($campaigns[$key]['landing_pages'][$landingLabel] ?? 0) + 1;
        }
    }

    foreach ($levelPages as $level => &$pages) {
        check_sort_assoc_count($pages);
        $top = reset($pages);
        if (is_array($top)) {
            $levelSummary[$level]['top_page'] = $top['page'];
            $levelSummary[$level]['top_count'] = (int)$top['sessions'];
        }
    }
    unset($pages);

    check_sort_assoc_count($pathCounts);
    check_sort_assoc_count($campaigns);
    check_sort_assoc_count($topTransitions);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$sessionCount = count($sessions);
$eventCount = count($events);
$bounceCount = 0;
$clickTotal = 0;
foreach ($sessions as $session) {
    $depth = max(0, count($session['path']) - 1);
    $clickTotal += $depth;
    if ($depth === 0) {
        $bounceCount++;
    }
}
$avgClicks = $sessionCount > 0 ? number_format($clickTotal / $sessionCount, 2, ',', '.') : '0,00';
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#0f766e">
  <title>Click Path Check</title>
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
      --danger: #be123c;
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
    * { box-sizing: border-box; letter-spacing: 0; }
    body {
      min-width: 320px;
      margin: 0;
      color: var(--ink);
      background: linear-gradient(180deg, color-mix(in srgb, var(--accent) 10%, var(--bg)) 0, var(--bg) 360px);
      font: 14px / 1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", "Helvetica Neue", sans-serif;
    }
    header {
      position: sticky;
      top: 0;
      z-index: 20;
      padding: 1rem clamp(1rem, 4vw, 2.5rem);
      border-bottom: 1px solid color-mix(in srgb, var(--line) 82%, var(--accent));
      background: color-mix(in srgb, var(--panel) 93%, transparent);
      box-shadow: 0 14px 44px rgba(18, 24, 20, 0.1);
      backdrop-filter: blur(18px) saturate(1.2);
    }
    .header-inner,
    main {
      width: min(1480px, 100%);
      margin-inline: auto;
    }
    .header-inner {
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto;
      gap: 1rem;
      align-items: end;
    }
    .eyebrow {
      display: inline-flex;
      align-items: center;
      min-height: 1.5rem;
      margin-bottom: 0.35rem;
      padding: 0.18rem 0.5rem;
      border: 1px solid color-mix(in srgb, var(--accent) 32%, var(--line));
      border-radius: 6px;
      color: var(--accent);
      background: color-mix(in srgb, var(--accent) 9%, var(--panel));
      font-size: 0.75rem;
      font-weight: 800;
      text-transform: uppercase;
    }
    h1, h2, h3, p { margin: 0; }
    h1 {
      font-size: 2rem;
      line-height: 1.15;
      font-weight: 850;
    }
    .meta {
      margin-top: 0.35rem;
      color: var(--muted);
      font-weight: 650;
    }
    form {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 0.5rem;
      flex-wrap: wrap;
      padding: 0.4rem;
      border: 1px solid var(--line);
      border-radius: 8px;
      background: color-mix(in srgb, var(--panel) 86%, var(--bg));
    }
    label {
      color: var(--muted);
      font-size: 0.8125rem;
      font-weight: 750;
    }
    input, select, button, .button {
      min-height: 2.25rem;
      border: 1px solid color-mix(in srgb, var(--line) 90%, var(--ink));
      border-radius: 6px;
      padding: 0.5rem 0.75rem;
      color: var(--ink);
      background: var(--panel);
      font: inherit;
      font-weight: 750;
    }
    input[type="number"] { width: 5.5rem; }
    button, .button {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      color: #fff;
      border-color: var(--accent);
      background: var(--accent);
      cursor: pointer;
      text-decoration: none;
    }
    button:hover, .button:hover {
      background: color-mix(in srgb, var(--accent) 86%, black);
    }
    .button.secondary {
      color: var(--ink);
      border-color: var(--line);
      background: var(--panel);
    }
    .button.secondary:hover {
      border-color: color-mix(in srgb, var(--accent) 55%, var(--line));
      background: color-mix(in srgb, var(--accent) 9%, var(--panel));
    }
    main {
      padding: 1.25rem clamp(1rem, 3vw, 2rem) 2.5rem;
    }
    .notice {
      margin-bottom: 1rem;
      padding: 1rem;
      border: 1px solid color-mix(in srgb, var(--danger) 42%, var(--line));
      border-radius: 8px;
      color: var(--danger);
      background: color-mix(in srgb, var(--danger) 9%, var(--panel));
      font-weight: 750;
    }
    .metrics,
    .grid {
      display: grid;
      gap: 0.875rem;
    }
    .metrics {
      grid-template-columns: repeat(4, minmax(0, 1fr));
      margin-bottom: 1.25rem;
    }
    .metric,
    section,
    .path-row {
      border: 1px solid color-mix(in srgb, var(--line) 82%, var(--accent));
      border-radius: 8px;
      background: color-mix(in srgb, var(--panel) 96%, var(--bg));
      box-shadow: 0 12px 30px rgba(18, 24, 20, 0.08);
    }
    .metric {
      position: relative;
      min-height: 108px;
      overflow: hidden;
      padding: 1rem;
    }
    .metric::before {
      content: "";
      position: absolute;
      inset: 0 0 auto;
      height: 4px;
      background: var(--accent);
    }
    .metric:nth-child(4n + 2)::before { background: var(--ok); }
    .metric:nth-child(4n + 3)::before { background: var(--bot); }
    .metric:nth-child(4n + 4)::before { background: var(--warn); }
    .metric p {
      color: var(--muted);
      font-weight: 700;
    }
    .metric strong {
      display: block;
      margin-top: 0.75rem;
      font-size: 1.9rem;
      line-height: 1.1;
      font-weight: 850;
    }
    .metric span {
      display: block;
      margin-top: 0.45rem;
      color: var(--muted);
      font-size: 0.8125rem;
      font-weight: 650;
    }
    .grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
      align-items: start;
      margin-bottom: 1.25rem;
    }
    section {
      overflow: hidden;
      margin-bottom: 1.25rem;
    }
    section h2 {
      min-height: 3.1rem;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      padding: 0.85rem 1rem;
      border-bottom: 1px solid var(--line);
      background: color-mix(in srgb, var(--panel) 90%, var(--bg));
      font-size: 0.95rem;
      font-weight: 850;
    }
    .scroll {
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.875rem;
    }
    th, td {
      padding: 0.68rem 0.9rem;
      border-bottom: 1px solid var(--line);
      text-align: left;
      vertical-align: middle;
      overflow-wrap: anywhere;
    }
    th {
      color: var(--muted);
      background: color-mix(in srgb, var(--panel) 88%, var(--bg));
      font-size: 0.75rem;
      font-weight: 800;
      text-transform: uppercase;
    }
    tr:last-child td { border-bottom: 0; }
    tbody tr:nth-child(even) {
      background: color-mix(in srgb, var(--panel) 96%, var(--bg));
    }
    tbody tr:hover {
      background: color-mix(in srgb, var(--accent) 8%, var(--panel));
    }
    .path-diagram {
      display: grid;
      gap: 0.75rem;
      padding: 1rem;
    }
    .path-row {
      display: grid;
      grid-template-columns: 9rem minmax(0, 1fr);
      gap: 0.75rem;
      align-items: center;
      padding: 0.75rem;
      box-shadow: none;
    }
    .path-count {
      color: var(--muted);
      font-weight: 800;
    }
    .path-nodes {
      display: flex;
      gap: 0.5rem;
      align-items: stretch;
      overflow-x: auto;
      padding-bottom: 0.1rem;
    }
    .node {
      flex: 0 0 min(15rem, 58vw);
      padding: 0.65rem;
      border: 1px solid var(--line);
      border-radius: 8px;
      background: var(--panel);
    }
    .node-level {
      display: block;
      color: var(--accent);
      font-size: 0.75rem;
      font-weight: 850;
      text-transform: uppercase;
    }
    .node-page {
      display: block;
      margin-top: 0.25rem;
      font-weight: 750;
      overflow-wrap: anywhere;
    }
    .arrow {
      display: flex;
      align-items: center;
      color: var(--muted);
      font-weight: 850;
    }
    .badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 1.5rem;
      padding: 0.125rem 0.5rem;
      border-radius: 6px;
      color: #fff;
      background: var(--accent);
      font-size: 0.75rem;
      font-weight: 850;
    }
    .badge.warn { background: var(--warn); }
    .muted { color: var(--muted); }
    .mono {
      font-family: ui-monospace, "SF Mono", Monaco, "Cascadia Code", monospace;
      font-size: 0.8125rem;
    }
    .wide-table { min-width: 1180px; }
    :focus-visible {
      outline: 2px solid var(--accent);
      outline-offset: 2px;
    }
    @media (max-width: 1080px) {
      .header-inner { grid-template-columns: 1fr; }
      form { justify-content: flex-start; }
      .metrics, .grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 680px) {
      header { position: static; }
      h1 { font-size: 1.55rem; }
      form, .metrics, .grid { grid-template-columns: 1fr; }
      form { display: grid; width: 100%; }
      input[type="number"], select, button, .button { width: 100%; }
      .path-row { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <header role="banner">
    <div class="header-inner">
      <div>
        <span class="eyebrow">MySQL Click Paths</span>
        <h1>Checkthis</h1>
        <div class="meta">
          <?= $error === '' ? 'Tabelle ' . check_h($table) . ' · ' . check_h((string)$days) . ' Tage · Session gap ' . check_h((string)$gapMinutes) . ' min' : 'Click path analysis' ?>
        </div>
      </div>
      <form method="get" aria-label="Analyse Filter">
        <?php if (isset($_GET['embed']) && $_GET['embed'] === '1'): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
        <label for="days">Tage</label>
        <input id="days" name="days" type="number" min="<?= CHECK_DAYS_MIN ?>" max="<?= CHECK_DAYS_MAX ?>" value="<?= check_h($days) ?>">
        <label for="gap">Gap</label>
        <input id="gap" name="gap" type="number" min="5" max="240" value="<?= check_h($gapMinutes) ?>">
        <label for="levels">Level</label>
        <input id="levels" name="levels" type="number" min="2" max="12" value="<?= check_h($maxLevel) ?>">
        <label for="audience">Audience</label>
        <select id="audience" name="audience">
          <option value="humans"<?= $audience === 'humans' ? ' selected' : '' ?>>Humans</option>
          <option value="all"<?= $audience === 'all' ? ' selected' : '' ?>>All</option>
          <option value="bots"<?= $audience === 'bots' ? ' selected' : '' ?>>Bots</option>
        </select>
        <button type="submit">Aktualisieren</button>
        <a class="button secondary" href="index.php?days=<?= check_h($days) ?>">Index</a>
      </form>
    </div>
  </header>

  <main role="main">
    <?php if ($error !== ''): ?>
      <div class="notice">Click path analysis not available: <?= check_h($error) ?></div>
    <?php else: ?>
      <div class="metrics" aria-label="Session Zusammenfassung">
        <?= check_metric('Sessions', check_number($sessionCount), 'visitor_hash grouped') ?>
        <?= check_metric('Visitors', check_number(count($uniqueVisitors)), 'unique visitor hashes') ?>
        <?= check_metric('Events read', check_number($eventCount), 'limit ' . check_number($rowLimit)) ?>
        <?= check_metric('Bounce sessions', check_number($bounceCount), check_percent($bounceCount, $sessionCount)) ?>
        <?= check_metric('Total clicks', check_number($clickTotal), 'after landing') ?>
        <?= check_metric('Avg clicks', $avgClicks, 'per session') ?>
        <?= check_metric('Campaign rows', check_number(count($campaigns)), 'UTM groups') ?>
        <?= check_metric('Max level', (string)$maxLevel, 'Landing is level 0') ?>
      </div>

      <section aria-label="Level Table">
        <h2><span>Level Table</span><span class="muted">Landing = Level 0, Click 1 = Level 1</span></h2>
        <div class="scroll">
          <table>
            <thead>
              <tr>
                <th>Level</th>
                <th>Name</th>
                <th>Reached</th>
                <th>Top page</th>
                <th>Top count</th>
                <th>Exit here</th>
                <th>Continue</th>
                <th>Continue rate</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($levelSummary as $row): ?>
                <?php if ((int)$row['reached'] < 1) { continue; } ?>
                <tr>
                  <td><span class="badge"><?= check_h($row['level']) ?></span></td>
                  <td><?= check_h($row['name']) ?></td>
                  <td><?= check_h(check_number($row['reached'])) ?></td>
                  <td title="<?= check_h($row['top_page']) ?>"><?= check_h(check_short($row['top_page'], 86)) ?></td>
                  <td><?= check_h(check_number($row['top_count'])) ?></td>
                  <td><?= check_h(check_number($row['exited'])) ?></td>
                  <td><?= check_h(check_number($row['continued'])) ?></td>
                  <td><?= check_h(check_percent($row['continued'], $row['reached'])) ?></td>
                </tr>
              <?php endforeach; ?>
              <?= $levelSummary === [] ? check_table_empty(8, 'No session levels found.') : '' ?>
            </tbody>
          </table>
        </div>
      </section>

      <section aria-label="User click path diagram">
        <h2><span>User Click Path Diagram</span><span class="muted">Top <?= check_h((string)CHECK_PATH_LIMIT) ?> landing paths</span></h2>
        <div class="path-diagram">
          <?php $shownPaths = 0; ?>
          <?php foreach (array_slice($pathCounts, 0, CHECK_PATH_LIMIT, true) as $path): ?>
            <?php $shownPaths++; ?>
            <div class="path-row">
              <div class="path-count"><?= check_h(check_number($path['sessions'])) ?> sessions</div>
              <div class="path-nodes">
                <?php foreach ($path['path'] as $level => $node): ?>
                  <?php if ($level > 0): ?><span class="arrow">&rarr;</span><?php endif; ?>
                  <div class="node">
                    <span class="node-level"><?= check_h(check_level_name((int)$level)) ?></span>
                    <span class="node-page" title="<?= check_h($node['label']) ?>"><?= check_h(check_short($node['label'], 54)) ?></span>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endforeach; ?>
          <?php if ($shownPaths === 0): ?>
            <p class="muted">No click paths found for this filter.</p>
          <?php endif; ?>
        </div>
      </section>

      <div class="grid">
        <section aria-label="Detailed Level Pages">
          <h2>Level Pages</h2>
          <div class="scroll">
            <table>
              <thead>
                <tr>
                  <th>Level</th>
                  <th>Name</th>
                  <th>Page</th>
                  <th>Sessions</th>
                  <th>Exits</th>
                  <th>Exit rate</th>
                </tr>
              </thead>
              <tbody>
                <?php $levelPageRows = 0; ?>
                <?php foreach ($levelPages as $level => $pages): ?>
                  <?php foreach (array_slice($pages, 0, 10, true) as $row): ?>
                    <?php $levelPageRows++; ?>
                    <tr>
                      <td><span class="badge"><?= check_h($row['level']) ?></span></td>
                      <td><?= check_h($row['name']) ?></td>
                      <td title="<?= check_h($row['page']) ?>"><?= check_h(check_short($row['page'], 78)) ?></td>
                      <td><?= check_h(check_number($row['sessions'])) ?></td>
                      <td><?= check_h(check_number($row['exits'])) ?></td>
                      <td><?= check_h(check_percent($row['exits'], $row['sessions'])) ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endforeach; ?>
                <?= $levelPageRows === 0 ? check_table_empty(6, 'No level page rows found.') : '' ?>
              </tbody>
            </table>
          </div>
        </section>

        <section aria-label="Top Transitions">
          <h2>Top Transitions</h2>
          <div class="scroll">
            <table>
              <thead>
                <tr>
                  <th>From</th>
                  <th>To</th>
                  <th>Sessions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach (array_slice($topTransitions, 0, 28, true) as $row): ?>
                  <tr>
                    <td><span class="muted"><?= check_h(check_level_name((int)$row['level'])) ?></span><br><?= check_h(check_short($row['from'], 54)) ?></td>
                    <td><span class="muted"><?= check_h(check_level_name((int)$row['level'] + 1)) ?></span><br><?= check_h(check_short($row['to'], 54)) ?></td>
                    <td><?= check_h(check_number($row['sessions'])) ?></td>
                  </tr>
                <?php endforeach; ?>
                <?= $topTransitions === [] ? check_table_empty(3, 'No transitions found.') : '' ?>
              </tbody>
            </table>
          </div>
        </section>
      </div>

      <section aria-label="Campaign Overview">
        <h2><span>Campaign Overview</span><span class="muted">utm_source, utm_medium, utm_campaign, content, term</span></h2>
        <div class="scroll">
          <table class="wide-table">
            <thead>
              <tr>
                <th>Source</th>
                <th>Medium</th>
                <th>Campaign</th>
                <th>Content</th>
                <th>Term</th>
                <th>Campaign ID</th>
                <th>Creative ID</th>
                <th>Tracking ID</th>
                <th>UTM</th>
                <th>Sessions</th>
                <th>Clicks</th>
                <th>Bounces</th>
                <th>Top Landing</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach (array_slice($campaigns, 0, CHECK_CAMPAIGN_LIMIT, true) as $row): ?>
                <?php
                  arsort($row['landing_pages']);
                  $topLanding = (string)(array_key_first($row['landing_pages']) ?? '-');
                ?>
                <tr>
                  <td><?= check_h($row['source']) ?></td>
                  <td><?= check_h($row['medium']) ?></td>
                  <td><?= check_h($row['campaign']) ?></td>
                  <td><?= check_h($row['content']) ?></td>
                  <td><?= check_h($row['term']) ?></td>
                  <td><?= check_h($row['campaign_id']) ?></td>
                  <td><?= check_h($row['creative_id']) ?></td>
                  <td><?= check_h($row['tracking_id']) ?></td>
                  <td><span class="badge <?= $row['has_utm'] ? '' : 'warn' ?>"><?= $row['has_utm'] ? 'yes' : 'no' ?></span></td>
                  <td><?= check_h(check_number($row['sessions'])) ?></td>
                  <td><?= check_h(check_number($row['clicks'])) ?></td>
                  <td><?= check_h(check_number($row['bounces'])) ?></td>
                  <td title="<?= check_h($topLanding) ?>"><?= check_h(check_short($topLanding, 72)) ?></td>
                </tr>
              <?php endforeach; ?>
              <?= $campaigns === [] ? check_table_empty(13, 'No campaign rows found.') : '' ?>
            </tbody>
          </table>
        </div>
      </section>
    <?php endif; ?>
  </main>
</body>
</html>
