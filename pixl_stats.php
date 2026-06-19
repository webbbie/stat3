<?php
declare(strict_types=1);

require __DIR__ . '/pixl_server.php';

pixl_require_stats_auth();

$pdo = pixl_pdo();
pixl_ensure_schema($pdo);
$table = pixl_table_name();

function pixl_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function pixl_compact_label($value): string
{
    $label = trim((string)$value);
    $lower = strtolower($label);

    if ($lower === 'human' || $lower === 'human-like') {
        return 'Human';
    }

    if ($lower === 'bot' || $lower === 'unknown bot') {
        return 'Bot';
    }

    return $label;
}

function pixl_display_client($browser, $os, $device): string
{
    $browser = trim((string)$browser);
    $os = trim((string)$os);
    $device = trim((string)$device);

    if ($browser !== '' && $os !== '' && stripos($browser, $os) !== false) {
        $os = '';
    }
    if (strcasecmp($browser, 'android webview') === 0) {
        $browser = 'androidwv';
    }

    return implode(' / ', array_filter([$browser, $os, $device], static function (string $part): bool {
        return $part !== '';
    }));
}

function pixl_display_language($value): string
{
    $value = trim((string)$value);
    if (!preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/', $value)) {
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

    return $visible ? implode(' - ', $visible) : '-';
}

function pixl_display_country($country): string
{
    $country = trim((string)$country);
    if ($country === '') {
        return '-';
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
        'AD' => 'Andorra',
        'AE' => 'Vereinigte Arabische Emirate',
        'AF' => 'Afghanistan',
        'AL' => 'Albanien',
        'AM' => 'Armenien',
        'AR' => 'Argentinien',
        'AT' => 'Österreich',
        'AU' => 'Australien',
        'AZ' => 'Aserbaidschan',
        'BA' => 'Bosnien und Herzegowina',
        'BE' => 'Belgien',
        'BH' => 'Bahrain',
        'BG' => 'Bulgarien',
        'BO' => 'Bolivien',
        'BR' => 'Brasilien',
        'BY' => 'Belarus',
        'CA' => 'Kanada',
        'CH' => 'Schweiz',
        'CL' => 'Chile',
        'CN' => 'China',
        'CO' => 'Kolumbien',
        'CV' => 'Kap Verde',
        'CZ' => 'Tschechien',
        'DE' => 'Deutschland',
        'DK' => 'Dänemark',
        'DZ' => 'Algerien',
        'EE' => 'Estland',
        'EG' => 'Ägypten',
        'ES' => 'Spanien',
        'FI' => 'Finnland',
        'FR' => 'Frankreich',
        'GB' => 'Vereinigtes Königreich',
        'GE' => 'Georgien',
        'GR' => 'Griechenland',
        'HK' => 'Hongkong',
        'HR' => 'Kroatien',
        'HU' => 'Ungarn',
        'ID' => 'Indonesien',
        'IE' => 'Irland',
        'IL' => 'Israel',
        'IN' => 'Indien',
        'IQ' => 'Irak',
        'IR' => 'Iran',
        'IS' => 'Island',
        'IT' => 'Italien',
        'JO' => 'Jordanien',
        'JP' => 'Japan',
        'KR' => 'Südkorea',
        'KZ' => 'Kasachstan',
        'LI' => 'Liechtenstein',
        'LY' => 'Libyen',
        'LT' => 'Litauen',
        'LU' => 'Luxemburg',
        'LV' => 'Lettland',
        'MA' => 'Marokko',
        'MD' => 'Moldau',
        'ME' => 'Montenegro',
        'MX' => 'Mexiko',
        'MY' => 'Malaysia',
        'NL' => 'Niederlande',
        'NO' => 'Norwegen',
        'NZ' => 'Neuseeland',
        'PE' => 'Peru',
        'PH' => 'Philippinen',
        'PK' => 'Pakistan',
        'PL' => 'Polen',
        'PT' => 'Portugal',
        'RO' => 'Rumänien',
        'RS' => 'Serbien',
        'RU' => 'Russland',
        'SE' => 'Schweden',
        'SG' => 'Singapur',
        'SI' => 'Slowenien',
        'SK' => 'Slowakei',
        'SY' => 'Syrien',
        'TH' => 'Thailand',
        'TN' => 'Tunesien',
        'TR' => 'Türkei',
        'TW' => 'Taiwan',
        'UA' => 'Ukraine',
        'US' => 'Vereinigte Staaten',
        'VN' => 'Vietnam',
        'YE' => 'Jemen',
        'ZA' => 'Südafrika',
    ];

    return $fallback[$code] ?? $code;
}

function pixl_format_minutes_seconds(int $seconds): string
{
    $seconds = max(0, $seconds);
    return floor($seconds / 60) . ':' . str_pad((string)($seconds % 60), 2, '0', STR_PAD_LEFT);
}

function pixl_fetch_value(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function pixl_fetch_rows(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function pixl_dashboardx2_scalar(PDO $pdo, string $sql, array $params = [])
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function pixl_dashboardx2_chart(array $rows): array
{
    $labels = [];
    $values = [];
    foreach ($rows as $row) {
        $labels[] = (string)($row['label'] ?? '');
        $values[] = (int)($row['count'] ?? 0);
    }
    return ['labels' => $labels, 'values' => $values];
}

function pixl_dashboardx2_referrer_chart(array $rows): array
{
    $siteHost = strtolower(trim((string)(pixl_config()['site_id'] ?? '')));
    $siteHost = preg_replace('/^www\./', '', $siteHost) ?: $siteHost;
    $totals = [];

    foreach ($rows as $row) {
        $value = trim((string)($row['label'] ?? ''));
        if ($value === '') {
            continue;
        }

        $host = parse_url($value, PHP_URL_HOST);
        $label = is_string($host) && $host !== '' ? strtolower($host) : $value;
        $normalized = preg_replace('/^www\./', '', strtolower($label)) ?: strtolower($label);
        if ($siteHost !== '' && $normalized === $siteHost) {
            continue;
        }
        $totals[$label] = ($totals[$label] ?? 0) + (int)($row['count'] ?? 0);
    }

    arsort($totals);
    $totals = array_slice($totals, 0, 10, true);
    return [
        'labels' => array_keys($totals),
        'values' => array_values($totals),
    ];
}

function pixl_dashboardx2_feed(PDO $pdo, string $table, int $days, int $limit): array
{
    $berlin = new DateTimeZone('Europe/Berlin');
    $nowBerlin = new DateTimeImmutable('now', $berlin);
    $todayStart = $nowBerlin->setTime(0, 0);
    $todayEnd = $todayStart->modify('+1 day');
    $todayStartUtc = $todayStart->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $todayEndUtc = $todayEnd->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $sinceUtc = $nowBerlin->modify('-' . $days . ' days')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $last24Utc = $nowBerlin->modify('-24 hours')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $last60Utc = $nowBerlin->modify('-60 minutes')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $pageExpr = pixl_sql_page_expression();
    $scope = pixl_sql_configured_stats_url_condition($pdo);
    $scopeAnd = $scope !== '' ? " AND $scope" : '';
    $scopeWhere = $scope !== '' ? " WHERE $scope" : '';

    $todayParams = [':today_start' => $todayStartUtc, ':today_end' => $todayEndUtc];
    $kpi = [
        'real_visitors_today' => (int)pixl_dashboardx2_scalar(
            $pdo,
            "SELECT COUNT(DISTINCT `visitor_hash`) FROM `$table`
             WHERE `created_at` >= :today_start AND `created_at` < :today_end
               AND `is_bot` = 0 AND `visitor_hash` <> ''$scopeAnd",
            $todayParams
        ),
        'impressions_today' => (int)pixl_dashboardx2_scalar(
            $pdo,
            "SELECT COUNT(*) FROM `$table` WHERE `created_at` >= :today_start AND `created_at` < :today_end$scopeAnd",
            $todayParams
        ),
        'unique_ips_today' => (int)pixl_dashboardx2_scalar(
            $pdo,
            "SELECT COUNT(DISTINCT `visitor_hash`) FROM `$table`
             WHERE `created_at` >= :today_start AND `created_at` < :today_end AND `visitor_hash` <> ''$scopeAnd",
            $todayParams
        ),
        'bot_visits_today' => (int)pixl_dashboardx2_scalar(
            $pdo,
            "SELECT COUNT(*) FROM `$table`
             WHERE `created_at` >= :today_start AND `created_at` < :today_end AND `is_bot` = 1$scopeAnd",
            $todayParams
        ),
        'unique_pages_today' => (int)pixl_dashboardx2_scalar(
            $pdo,
            "SELECT COUNT(DISTINCT $pageExpr) FROM `$table`
             WHERE `created_at` >= :today_start AND `created_at` < :today_end$scopeAnd",
            $todayParams
        ),
        'avg_visitors_per_min' => round((float)pixl_dashboardx2_scalar(
            $pdo,
            "SELECT COUNT(DISTINCT `visitor_hash`) / 60 FROM `$table`
             WHERE `created_at` >= :last_60 AND `is_bot` = 0 AND `visitor_hash` <> ''$scopeAnd",
            [':last_60' => $last60Utc]
        ), 4),
        'real_visitors_total' => (int)pixl_dashboardx2_scalar(
            $pdo,
            "SELECT COUNT(DISTINCT `visitor_hash`) FROM `$table` WHERE `is_bot` = 0 AND `visitor_hash` <> ''$scopeAnd"
        ),
        'impressions_total' => (int)pixl_dashboardx2_scalar($pdo, "SELECT COUNT(*) FROM `$table`$scopeWhere"),
        'unique_ips_total' => (int)pixl_dashboardx2_scalar(
            $pdo,
            "SELECT COUNT(DISTINCT `visitor_hash`) FROM `$table` WHERE `visitor_hash` <> ''$scopeAnd"
        ),
        'bot_visits_total' => (int)pixl_dashboardx2_scalar($pdo, "SELECT COUNT(*) FROM `$table` WHERE `is_bot` = 1$scopeAnd"),
        'unique_pages_total' => (int)pixl_dashboardx2_scalar($pdo, "SELECT COUNT(DISTINCT $pageExpr) FROM `$table`$scopeWhere"),
        'avg_score_total' => round((float)pixl_dashboardx2_scalar(
            $pdo,
            "SELECT COALESCE(AVG(CASE
                WHEN `v3_user_score` IS NOT NULL THEN LEAST(1, GREATEST(0, `v3_user_score`))
                WHEN `reading_score` IS NOT NULL THEN LEAST(1, GREATEST(0, `reading_score` / 100))
                ELSE NULL END), 0) FROM `$table`$scopeWhere"
        ), 4),
    ];

    $visRows = pixl_fetch_rows(
        $pdo,
        "SELECT DATE(`created_at`) AS label,
                COUNT(DISTINCT CASE WHEN `is_bot` = 0 AND `visitor_hash` <> '' THEN `visitor_hash` END) AS count
         FROM `$table`
         WHERE `created_at` >= :chart_since$scopeAnd
         GROUP BY DATE(`created_at`)
         ORDER BY label ASC",
        [':chart_since' => $sinceUtc]
    );

    $referrerExpr = pixl_sql_referrer_expression();
    $referrerRows = pixl_fetch_rows(
        $pdo,
        "SELECT $referrerExpr AS label, COUNT(*) AS count
         FROM `$table`
         WHERE `created_at` >= :referrer_since AND `referrer` IS NOT NULL AND `referrer` <> ''$scopeAnd
         GROUP BY label
         ORDER BY count DESC
         LIMIT 30",
        [':referrer_since' => $last24Utc]
    );

    $charts = [
        'vis' => pixl_dashboardx2_chart($visRows),
        'ref' => pixl_dashboardx2_referrer_chart($referrerRows),
    ];

    foreach (['browser' => 'browser', 'os' => 'os', 'lang' => 'language'] as $key => $column) {
        $rows = pixl_fetch_rows(
            $pdo,
            "SELECT `$column` AS label, COUNT(*) AS count
             FROM `$table`
             WHERE `created_at` >= :breakdown_since AND `$column` <> ''$scopeAnd
             GROUP BY `$column`
             ORDER BY count DESC
             LIMIT 10",
            [':breakdown_since' => $last24Utc]
        );
        $charts[$key] = pixl_dashboardx2_chart($rows);
    }

    $resolutionRows = pixl_fetch_rows(
        $pdo,
        "SELECT COALESCE(NULLIF(`screen`, ''), NULLIF(`viewport`, ''), 'Unbekannt') AS label, COUNT(*) AS count
         FROM `$table`
         WHERE `created_at` >= :resolution_since$scopeAnd
         GROUP BY label
         ORDER BY count DESC
         LIMIT 10",
        [':resolution_since' => $last24Utc]
    );
    $charts['res'] = pixl_dashboardx2_chart($resolutionRows);

    $topPagesToday = pixl_fetch_rows(
        $pdo,
        "SELECT $pageExpr AS url, COUNT(*) AS count
         FROM `$table`
         WHERE `created_at` >= :top_today_start AND `created_at` < :top_today_end$scopeAnd
         GROUP BY url
         ORDER BY count DESC
         LIMIT 10",
        [':top_today_start' => $todayStartUtc, ':top_today_end' => $todayEndUtc]
    );

    $topPagesTotal = pixl_fetch_rows(
        $pdo,
         "SELECT $pageExpr AS url, COUNT(*) AS count
         FROM `$table`$scopeWhere
         GROUP BY url
         ORDER BY count DESC
         LIMIT 10"
    );

    $scoreExpr = "CASE
        WHEN e.`v3_user_score` IS NOT NULL THEN LEAST(1, GREATEST(0, e.`v3_user_score`))
        WHEN e.`reading_score` IS NOT NULL THEN LEAST(1, GREATEST(0, e.`reading_score` / 100))
        ELSE NULL END";
    $mlExpr = "CASE WHEN ($scoreExpr) >= 0.7 THEN 1 ELSE 0 END";
    $gExpr = "CASE WHEN ($scoreExpr) IS NULL THEN NULL
        ELSE LEAST(1, GREATEST(0, (0.75 * ($scoreExpr)) + (0.25 * ($mlExpr)))) END";
    $recentPageExpr = pixl_sql_page_expression('e');
    $uniquePageExpr = pixl_sql_page_expression('u');
    $recentReferrerExpr = pixl_sql_referrer_expression('e');
    $recentScope = pixl_sql_configured_stats_url_condition($pdo, 'e');
    $recentScopeAnd = $recentScope !== '' ? " AND $recentScope" : '';
    $visitsTotalScope = pixl_sql_configured_stats_url_condition($pdo, 'vt');
    $visitsTotalScopeAnd = $visitsTotalScope !== '' ? " AND $visitsTotalScope" : '';
    $uniqueScope = pixl_sql_configured_stats_url_condition($pdo, 'u');
    $uniqueScopeAnd = $uniqueScope !== '' ? " AND $uniqueScope" : '';
    $visits24Scope = pixl_sql_configured_stats_url_condition($pdo, 'v24');
    $visits24ScopeAnd = $visits24Scope !== '' ? " AND $visits24Scope" : '';

    $recent = pixl_fetch_rows(
        $pdo,
        "SELECT e.`created_at`, e.`visitor_hash`, e.`ip_hash`, e.`language` AS lang,
                $recentPageExpr AS url, $recentReferrerExpr AS referrer,
                CASE WHEN e.`is_bot` = 1 THEN 0 ELSE 1 END AS human,
                e.`is_bot` AS bot, $scoreExpr AS score,
                CASE WHEN e.`session_duration` IS NULL THEN NULL ELSE e.`session_duration` * 1000 END AS dwell_ms,
                0 AS dc,
                CASE WHEN e.`visitor_hash` = '' THEN 1 ELSE
                  (SELECT COUNT(*) FROM `$table` vt WHERE vt.`visitor_hash` = e.`visitor_hash`$visitsTotalScopeAnd) END AS visits_total,
                CASE WHEN e.`visitor_hash` = '' THEN 0 ELSE
                  (SELECT COUNT(DISTINCT $uniquePageExpr) FROM `$table` u
                   WHERE u.`visitor_hash` = e.`visitor_hash` AND u.`created_at` >= :unique_24_since$uniqueScopeAnd) END AS unique24h,
                CASE WHEN e.`visitor_hash` = '' THEN 0 ELSE
                  (SELECT COUNT(*) FROM `$table` v24
                   WHERE v24.`visitor_hash` = e.`visitor_hash` AND v24.`created_at` >= :visits_24_since$visits24ScopeAnd) END AS visits_24h,
                '' AS reg, $mlExpr AS ml_score, $gExpr AS g_score,
                CASE WHEN e.`is_bot` = 0 AND COALESCE(($scoreExpr), 0) >= 0.5 THEN 1 ELSE 0 END AS gate_ok,
                1 AS tracked, e.`user_agent` AS ua
         FROM `$table` e
         WHERE e.`created_at` >= :recent_since$recentScopeAnd
         ORDER BY e.`created_at` DESC
         LIMIT $limit",
        [
            ':unique_24_since' => $last24Utc,
            ':visits_24_since' => $last24Utc,
            ':recent_since' => $sinceUtc,
        ]
    );

    return [
        'ok' => true,
        'error' => '',
        'kpi' => $kpi,
        'charts' => $charts,
        'topPagesToday' => $topPagesToday,
        'topPagesTotal' => $topPagesTotal,
        'recent' => $recent,
    ];
}

if (isset($_GET['dashboardx2_json'])) {
    $dashboardDays = max(1, min(365, (int)($_GET['days'] ?? 30)));
    $dashboardLimit = max(1, min(500, (int)($_GET['limit'] ?? 25)));
    try {
        $dashboard = pixl_dashboardx2_feed($pdo, $table, $dashboardDays, $dashboardLimit);
        pixl_json_response([
            'ok' => true,
            'table' => $table,
            'days' => $dashboardDays,
            'limit' => $dashboardLimit,
            'generatedAt' => gmdate('c'),
            'dashboardx2' => $dashboard,
        ]);
    } catch (Throwable $error) {
        pixl_json_response([
            'ok' => false,
            'dashboardx2' => [
                'ok' => false,
                'error' => $error->getMessage(),
            ],
        ], 500);
    }
}

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

if (isset($_GET['notify_feed'])) {
    $after = max(0, (int)($_GET['after'] ?? 0));
    $notifyScope = pixl_sql_configured_stats_url_condition($pdo);
    $notifyWhere = $notifyScope !== '' ? " WHERE $notifyScope" : '';
    $latestId = pixl_fetch_value($pdo, "SELECT COALESCE(MAX(id), 0) FROM `$table`$notifyWhere");
    $latestEventTs = pixl_fetch_value($pdo, "SELECT COALESCE(UNIX_TIMESTAMP(MAX(created_at)), 0) FROM `$table`$notifyWhere");
    $events = pixl_fetch_rows(
        $pdo,
        "SELECT id, created_at, UNIX_TIMESTAMP(created_at) AS created_ts,
                source, reason, title, hostname, path, browser, os,
                device, country, is_bot, bot_score, bot_name, reading_score,
                session_duration, user_agent,
                CASE WHEN LOWER(user_agent) LIKE '%bot%' THEN 1 ELSE 0 END AS ua_contains_bot
         FROM `$table`
         WHERE id > :after" . ($notifyScope !== '' ? " AND $notifyScope" : '') . "
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

$days = max(1, min(365, (int)($_GET['days'] ?? 30)));
$botFilter = (string)($_GET['bot'] ?? 'all');
$hideActivity = isset($_GET['hide_activity']) && $_GET['hide_activity'] === '1';
if (!in_array($botFilter, ['all', 'bots', 'humans'], true)) {
    $botFilter = 'all';
}

$since = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
    ->modify('-' . $days . ' days')
    ->format('Y-m-d H:i:s');

$statsScope = pixl_sql_configured_stats_url_condition($pdo);
$where = 'created_at >= :since';
if ($statsScope !== '') {
    $where .= " AND $statsScope";
}
$params = [':since' => $since];
if ($botFilter === 'bots') {
    $where .= ' AND is_bot = 1';
} elseif ($botFilter === 'humans') {
    $where .= ' AND is_bot = 0';
}

$allWhere = 'created_at >= :since' . ($statsScope !== '' ? " AND $statsScope" : '');
$latestWhere = $statsScope !== '' ? " WHERE $statsScope" : '';
$totalAll = pixl_fetch_value($pdo, "SELECT COUNT(*) FROM `$table` WHERE $allWhere", [':since' => $since]);
$latestEventId = pixl_fetch_value($pdo, "SELECT COALESCE(MAX(id), 0) FROM `$table`$latestWhere");
$latestEventTs = pixl_fetch_value($pdo, "SELECT COALESCE(UNIX_TIMESTAMP(MAX(created_at)), 0) FROM `$table`$latestWhere");
$botAll = pixl_fetch_value($pdo, "SELECT COUNT(*) FROM `$table` WHERE $allWhere AND is_bot = 1", [':since' => $since]);
$humanAll = max(0, $totalAll - $botAll);
$botPercent = $totalAll > 0 ? round(($botAll / $totalAll) * 100) : 0;

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

$byDay = pixl_fetch_rows(
    $pdo,
    "SELECT DATE(created_at) AS label, COUNT(*) AS count, SUM(is_bot) AS bots
     FROM `$table`
     WHERE $where
     GROUP BY DATE(created_at)
     ORDER BY label DESC
     LIMIT 31",
    $params
);
$dayMax = 1;
foreach ($byDay as $row) {
    $dayMax = max($dayMax, (int)$row['count']);
}

$normalizedPathExpr = pixl_sql_path_expression();
$topPaths = pixl_fetch_rows(
    $pdo,
    "SELECT $normalizedPathExpr AS label, COUNT(*) AS count, SUM(is_bot) AS bots, ROUND(AVG(reading_score)) AS avg_score
     FROM `$table`
     WHERE $where
     GROUP BY label
     ORDER BY count DESC
     LIMIT 20",
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
         LIMIT 10",
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
     LIMIT 10",
    $params
);

$recentBots = pixl_fetch_rows(
    $pdo,
    "SELECT created_at, source, reason, hostname, $normalizedPathExpr AS path, bot_score, bot_category,
            bot_name, bot_reasons, user_agent, request_method, request_uri,
            CASE WHEN LOWER(user_agent) LIKE '%bot%' THEN 1 ELSE 0 END AS ua_contains_bot
     FROM `$table`
     WHERE $allWhere AND is_bot = 1
     ORDER BY created_at DESC
     LIMIT 40",
    [':since' => $since]
);

$recent = pixl_fetch_rows(
    $pdo,
    "SELECT created_at, source, reason, title, hostname, $normalizedPathExpr AS path, browser, os, device, country,
            session_duration, reading_label, reading_seconds, reading_score,
            render_status, console_error_count, dialog_error_count,
            is_bot, bot_score, bot_category, bot_name, bot_reasons,
            user_agent, request_method, request_uri,
            CASE WHEN LOWER(user_agent) LIKE '%bot%' THEN 1 ELSE 0 END AS ua_contains_bot
     FROM `$table`
     WHERE $where
     ORDER BY created_at DESC
     LIMIT 120",
    $params
);
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Pixl SQL Statistik</title>
  <style>
    :root {
      color-scheme: light;
      --bg: #f4f6f8;
      --panel: #ffffff;
      --ink: #17202a;
      --muted: #667085;
      --line: #d8dee8;
      --accent: #146c94;
      --accent-soft: #e8f4f8;
      --bot: #8a3ffc;
      --warn: #a84818;
      --ok: #1f7a4d;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font: 14px/1.45 system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      color: var(--ink);
      background: var(--bg);
    }
    header {
      padding: 22px clamp(16px, 4vw, 40px) 16px;
      background: var(--panel);
      border-bottom: 1px solid var(--line);
    }
    h1, h2 { margin: 0; line-height: 1.2; letter-spacing: 0; }
    h1 { font-size: 24px; }
    h2 { font-size: 16px; }
    main {
      width: min(1480px, 100%);
      margin: 0 auto;
      padding: 20px clamp(12px, 3vw, 28px) 42px;
    }
    .toolbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      flex-wrap: wrap;
      margin-top: 12px;
      color: var(--muted);
    }
    .toolbar form {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
    }
    .filter-stack {
      display: flex;
      flex-direction: column;
      align-items: flex-end;
      gap: 4px;
    }
    .notify-tools {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
    }
    .notify-status {
      color: var(--muted);
      font-size: 13px;
    }
    .notify-mute {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      min-height: 34px;
      color: #344054;
      font-size: 13px;
      font-weight: 700;
      white-space: nowrap;
    }
    .notify-mute input {
      width: 16px;
      height: 16px;
      min-height: 16px;
      margin: 0;
      padding: 0;
      accent-color: var(--accent);
    }
    input, select, button, .button {
      min-height: 36px;
      border: 1px solid var(--line);
      border-radius: 6px;
      padding: 0 10px;
      font: inherit;
      background: #fff;
    }
    button, .button {
      display: inline-flex;
      align-items: center;
      color: #fff;
      text-decoration: none;
      border-color: var(--accent);
      background: var(--accent);
      cursor: pointer;
    }
    .button.secondary {
      color: var(--ink);
      border-color: var(--line);
      background: #fff;
    }
    .button.notify-active,
    button.notify-active {
      border-color: var(--ok);
      background: var(--ok);
    }
    .stats {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
      gap: 12px;
      margin-bottom: 18px;
    }
    .metric, section {
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: 8px;
    }
    .metric {
      padding: 16px;
      min-height: 92px;
    }
    .metric span {
      display: block;
      color: var(--muted);
      font-size: 12px;
      text-transform: uppercase;
    }
    .metric strong {
      display: block;
      margin-top: 8px;
      font-size: 28px;
      line-height: 1;
    }
    .grid {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 12px;
      margin-bottom: 18px;
    }
    section {
      overflow: hidden;
    }
    section h2 {
      padding: 13px 14px;
      border-bottom: 1px solid var(--line);
      background: #fbfcfe;
    }
    table {
      width: 100%;
      border-collapse: collapse;
    }
    th, td {
      padding: 5px 8px;
      border-bottom: 1px solid var(--line);
      text-align: left;
      vertical-align: top;
      overflow-wrap: anywhere;
      line-height: 1.25;
    }
    th {
      color: var(--muted);
      font-size: 11px;
      font-weight: 700;
      background: #fbfcfe;
    }
    tr:last-child td { border-bottom: 0; }
    details summary {
      cursor: pointer;
      color: var(--accent);
      font-weight: 700;
      line-height: 1.2;
    }
    code {
      display: block;
      white-space: pre-wrap;
      margin-top: 3px;
      padding: 4px 6px;
      border: 1px solid var(--line);
      border-radius: 6px;
      background: #f8fafc;
      color: #26313d;
      font: 11px/1.25 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    }
    .bar {
      display: flex;
      align-items: center;
      gap: 8px;
      min-width: 120px;
    }
    .bar i {
      display: block;
      height: 8px;
      min-width: 2px;
      border-radius: 999px;
      background: var(--accent);
    }
    .badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 20px;
      min-height: 20px;
      padding: 1px 6px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 700;
      background: #eef2f6;
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
      width: 12px;
      min-width: 12px;
      height: 12px;
      min-height: 12px;
      padding: 0;
      vertical-align: middle;
    }
    .badge.ua-hit {
      color: #fff;
      background: var(--warn);
    }
    .event-log table {
      table-layout: fixed;
      min-width: 1480px;
    }
    .event-log th:first-child,
    .event-log .event-time {
      width: 152px;
      min-width: 152px;
    }
    .event-log tbody tr {
      transition: background 120ms ease;
    }
    .event-log tbody tr:hover {
      background: #f8fafc;
    }
    .event-log tbody tr.is-bot {
      background: #fbf8ff;
    }
    .event-log tbody tr.is-bot:hover {
      background: #f4edff;
    }
    .event-time {
      color: #344054;
      font: 11px/1.25 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
      white-space: nowrap;
    }
    .event-source,
    .event-number {
      white-space: nowrap;
    }
    .event-country {
      max-width: 116px;
      white-space: normal;
      overflow-wrap: anywhere;
      line-height: 1.25;
    }
    .event-title {
      font-weight: 700;
      color: #26313d;
    }
    .event-path {
      color: var(--accent);
      font-weight: 700;
      max-width: 210px;
    }
    .event-client {
      color: #344054;
      max-width: 180px;
    }
    .event-log th,
    .event-log td {
      white-space: nowrap;
      overflow-wrap: normal;
      vertical-align: middle;
    }
    .event-log td {
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .event-log .event-country {
      white-space: nowrap;
      overflow-wrap: normal;
      line-height: 1.25;
    }
    .event-log .event-title,
    .event-log .event-path,
    .event-log .event-client,
    .event-log .event-source {
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .event-col-source { width: clamp(40px, 4vw, 56px); }
    .event-col-bot { width: 34px; text-align: center; }
    .event-col-event { width: clamp(46px, 4.5vw, 68px); }
    .event-col-country { width: clamp(120px, 10vw, 170px); }
    .event-col-duration { width: 54px; text-align: right; }
    .event-col-score { width: 36px; text-align: right; }
    .event-col-status { width: clamp(54px, 5vw, 76px); }
    .event-col-textbot { width: 54px; text-align: center; }
    .event-log .event-col-status .event-status {
      max-width: 100%;
    }
    .event-status {
      display: inline-flex;
      align-items: center;
      max-width: 140px;
      min-height: 20px;
      padding: 1px 7px;
      border-radius: 999px;
      overflow: hidden;
      color: var(--muted);
      background: #eef2f6;
      font-size: 11px;
      font-weight: 700;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
    .event-status.warn {
      color: #fff;
      background: var(--warn);
    }
    .ua-trigger {
      min-height: 24px;
      padding: 3px 8px;
      border: 1px solid #d7dee8;
      border-radius: 6px;
      color: var(--accent);
      background: #fff;
      font-size: 11px;
      font-weight: 800;
      white-space: nowrap;
      cursor: pointer;
    }
    .ua-trigger:hover {
      border-color: var(--accent);
      background: #eef7ff;
    }
    .ua-overlay[hidden] {
      display: none;
    }
    .ua-overlay {
      position: fixed;
      top: 14px;
      left: 14px;
      width: min(980px, calc(100vw - 28px));
      z-index: 40;
      pointer-events: none;
    }
    .ua-panel {
      width: 100%;
      padding: 10px;
      border: 1px solid #ccd6e4;
      border-radius: 8px;
      background: #fff;
      box-shadow: 0 18px 48px rgba(15, 23, 42, 0.22);
      pointer-events: auto;
    }
    .ua-panel-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 8px;
    }
    .ua-panel-head strong {
      font-size: 13px;
      color: #17202a;
    }
    .ua-close {
      min-height: 26px;
      padding: 3px 8px;
      border: 1px solid #d7dee8;
      border-radius: 6px;
      color: #344054;
      background: #f8fafc;
      font-size: 11px;
      font-weight: 800;
      cursor: pointer;
    }
    .ua-close:hover {
      background: #eef2f6;
    }
    .ua-panel code {
      display: block;
      max-height: 34vh;
      overflow: auto;
      padding: 8px;
      border-radius: 6px;
      color: #17202a;
      background: #f8fafc;
      font: 12px/1.45 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
      white-space: pre-wrap;
      overflow-wrap: anywhere;
    }
    .ua-panel code + code {
      margin-top: 8px;
      color: var(--warn);
      background: #fff7ed;
    }
    .muted { color: var(--muted); }
    .warn { color: var(--warn); font-weight: 700; }
    .wide { margin-bottom: 18px; }
    .scroll { overflow-x: auto; }
    @media (max-width: 1080px) {
      .grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 720px) {
      .grid { grid-template-columns: 1fr; }
      th, td { padding: 5px 7px; }
      .scroll { overflow-x: auto; }
      .scroll table { min-width: 1080px; }
    }
  </style>
</head>
<body>
  <header>
    <h1>Pixl SQL Statistik</h1>
    <div class="toolbar">
      <div>Seit <?= pixl_h($since) ?> UTC</div>
      <div class="notify-tools">
        <button id="pixlNotificationButton" type="button">Browser-Notifikation aktivieren</button>
        <button id="pixlNotificationOffButton" type="button" class="secondary">Browser-Notifikation ausschalten</button>
        <span id="pixlNotificationStatus" class="notify-status">Aus</span>
      </div>
      <div class="filter-stack">
        <label class="notify-mute" for="pixlNotificationMute">
          <input id="pixlNotificationMute" type="checkbox">
          Ton aus
        </label>
        <form method="get">
          <?php if (isset($_GET['embed']) && $_GET['embed'] === '1'): ?><input type="hidden" name="embed" value="1"><?php endif; ?>
          <?php if ($hideActivity): ?><input type="hidden" name="hide_activity" value="1"><?php endif; ?>
          <label for="days">Tage</label>
          <input id="days" name="days" type="number" min="1" max="365" value="<?= pixl_h($days) ?>">
          <label for="bot">Filter</label>
          <select id="bot" name="bot">
            <option value="all"<?= $botFilter === 'all' ? ' selected' : '' ?>>Alle</option>
            <option value="bots"<?= $botFilter === 'bots' ? ' selected' : '' ?>>Bots</option>
            <option value="humans"<?= $botFilter === 'humans' ? ' selected' : '' ?>>Menschen</option>
          </select>
          <button type="submit">Aktualisieren</button>
          <a class="button secondary" href="?logout=1">Logout</a>
        </form>
      </div>
    </div>
  </header>
  <main>
    <div class="stats">
      <div class="metric"><span>Events im Filter</span><strong><?= pixl_h($total) ?></strong></div>
      <div class="metric"><span>Besucher im Filter</span><strong><?= pixl_h($uniqueVisitors) ?></strong></div>
      <div class="metric"><span>Bots gesamt</span><strong><?= pixl_h($botAll) ?></strong></div>
      <div class="metric"><span>Botquote</span><strong><?= pixl_h($botPercent) ?>%</strong></div>
      <div class="metric"><span>Menschen gesamt</span><strong><?= pixl_h($humanAll) ?></strong></div>
      <div class="metric"><span>Reading Score Ø</span><strong><?= pixl_h($avgReading) ?></strong></div>
      <div class="metric"><span>Sessiondauer Ø</span><strong><?= pixl_h($avgDuration) ?>s</strong></div>
      <div class="metric"><span>Nachricht Ø alle</span><strong><?= pixl_h(pixl_format_minutes_seconds($avgMessageEvery)) ?></strong></div>
    </div>

    <section class="wide">
      <h2>Letzte Tage</h2>
      <table>
        <thead><tr><th>Tag</th><th>Events</th><th>Bots</th><th>Verlauf</th></tr></thead>
        <tbody>
        <?php foreach ($byDay as $row): ?>
          <tr>
            <td><?= pixl_h($row['label']) ?></td>
            <td><?= pixl_h($row['count']) ?></td>
            <td><?= pixl_h($row['bots']) ?></td>
            <td><span class="bar"><i style="width: <?= (int)round(((int)$row['count'] / $dayMax) * 100) ?>%"></i></span></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$byDay): ?><tr><td colspan="4" class="muted">Noch keine Daten.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </section>

    <div class="grid">
      <?php foreach ($breakdownRows as $title => $rows): ?>
        <section>
          <h2><?= pixl_h($title) ?></h2>
          <table>
            <thead><tr><th>Wert</th><th>Anzahl</th><th>Bots</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
              <tr>
                <td><?= pixl_h($row['label'] !== '' ? ($title === 'Land' ? pixl_display_country($row['label']) : pixl_compact_label($row['label'])) : 'Unknown') ?></td>
                <td><?= pixl_h($row['count']) ?></td>
                <td><?= pixl_h($row['bots']) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="3" class="muted">Keine Daten.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </section>
      <?php endforeach; ?>
    </div>

    <section class="wide">
      <h2>Top Pfade</h2>
      <table>
        <thead><tr><th>Pfad</th><th>Events</th><th>Bots</th><th>Score Ø</th></tr></thead>
        <tbody>
        <?php foreach ($topPaths as $row): ?>
          <tr>
            <td><?= pixl_h($row['label'] !== '' ? $row['label'] : '/') ?></td>
            <td><?= pixl_h($row['count']) ?></td>
            <td><?= pixl_h($row['bots']) ?></td>
            <td><?= pixl_h($row['avg_score'] ?? 0) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$topPaths): ?><tr><td colspan="4" class="muted">Noch keine Pfade.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </section>

    <section class="wide">
      <h2>Top User-Agents</h2>
      <table>
        <thead><tr><th>User-Agent</th><th>Text bot</th><th>Anzahl</th><th>Bot</th><th>Score</th></tr></thead>
        <tbody>
        <?php foreach ($topUserAgents as $row): ?>
          <tr>
            <td><code><?= pixl_h($row['label']) ?></code></td>
            <td><?= (int)$row['ua_contains_bot'] === 1 ? '<span class="badge ua-hit" title="User-Agent enthaelt bot">&#10005;</span>' : '<span class="muted">-</span>' ?></td>
            <td><?= pixl_h($row['count']) ?></td>
            <td>
              <span class="badge bot-dot <?= (int)$row['is_bot'] === 1 ? 'bot' : 'human' ?>" title="<?= (int)$row['is_bot'] === 1 ? 'Bot' : 'Human' ?>"></span>
            </td>
            <td><?= pixl_h($row['bot_score']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$topUserAgents): ?><tr><td colspan="5" class="muted">Noch keine User-Agents.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </section>

    <?php if (!$hideActivity): ?>
    <section class="wide scroll">
      <h2>Letzte Bot-Aufrufe</h2>
      <table>
        <thead>
          <tr>
            <th>Zeit</th><th>Quelle</th><th>Pfad</th><th>Bot</th><th>Score</th><th>Gruende</th><th>Text bot</th><th>User-Agent</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($recentBots as $row): ?>
          <tr>
            <td><?= pixl_h($row['created_at']) ?></td>
            <td><?= pixl_h($row['source'] . ' / ' . $row['reason']) ?></td>
            <td><?= pixl_h($row['hostname'] . $row['path']) ?></td>
            <td><span class="badge bot-dot bot" title="<?= pixl_h($row['bot_name'] ?: 'Bot') ?>"></span></td>
            <td><?= pixl_h($row['bot_score']) ?></td>
            <td><?= pixl_h($row['bot_reasons']) ?></td>
            <td><?= (int)$row['ua_contains_bot'] === 1 ? '<span class="badge ua-hit" title="User-Agent enthaelt bot">&#10005;</span>' : '<span class="muted">-</span>' ?></td>
            <td><code><?= pixl_h($row['user_agent']) ?></code></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$recentBots): ?><tr><td colspan="8" class="muted">Keine Bot-Aufrufe im Zeitraum.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </section>

    <section class="wide scroll event-log">
      <h2>Letzte Events</h2>
      <table>
        <thead>
          <tr>
            <th>Zeit</th><th class="event-col-source">Quelle</th><th class="event-col-bot">Bot</th><th class="event-col-event">Event</th><th>Titel</th><th>Pfad</th>
            <th>Client</th><th class="event-col-country">Land</th><th class="event-col-duration">Dauer</th><th>Reading</th><th class="event-col-score">Score</th><th class="event-col-status">Status</th><th class="event-col-textbot">Text bot</th><th>UserAgent</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($recent as $row): ?>
          <?php $hasIssues = ((int)$row['console_error_count'] > 0 || (int)$row['dialog_error_count'] > 0 || $row['render_status'] !== 'OK'); ?>
          <tr class="<?= (int)$row['is_bot'] === 1 ? 'is-bot' : 'is-human' ?>">
            <td class="event-time"><?= pixl_h($row['created_at']) ?></td>
            <td class="event-source event-col-source"><?= pixl_h($row['source']) ?></td>
            <td class="event-col-bot">
              <span class="badge bot-dot <?= (int)$row['is_bot'] === 1 ? 'bot' : 'human' ?>" title="<?= (int)$row['is_bot'] === 1 ? 'Bot' : 'Human' ?>"></span>
            </td>
            <td class="event-source event-col-event"><?= pixl_h($row['reason']) ?></td>
            <td class="event-title"><?= pixl_h(pixl_display_title($row['title'])) ?></td>
            <td class="event-path"><?= pixl_h($row['path']) ?></td>
            <td class="event-client"><?= pixl_h(pixl_display_client($row['browser'], $row['os'], $row['device'])) ?></td>
            <td class="event-country event-col-country"><?= pixl_h(pixl_display_country($row['country'])) ?></td>
            <td class="event-number event-col-duration"><?= pixl_h($row['session_duration']) ?>s</td>
            <td class="event-source"><?= pixl_h($row['reading_label']) ?><?= $row['reading_seconds'] !== null ? ' (' . pixl_h($row['reading_seconds']) . 's)' : '' ?></td>
            <td class="event-number event-col-score"><?= pixl_h($row['reading_score']) ?></td>
            <td class="event-col-status"><span class="event-status <?= $hasIssues ? 'warn' : '' ?>" title="<?= pixl_h($row['render_status']) ?>"><?= $hasIssues ? 'ERROR' : 'OK' ?></span></td>
            <td class="event-col-textbot"><?= (int)$row['ua_contains_bot'] === 1 ? '<span class="badge ua-hit" title="User-Agent enthaelt bot">&#10005;</span>' : '<span class="muted">-</span>' ?></td>
            <td>
              <button
                type="button"
                class="ua-trigger"
                data-user-agent="<?= pixl_h($row['user_agent']) ?>"
                data-bot-reasons="<?= pixl_h($row['bot_reasons']) ?>"
              >UserAgent</button>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$recent): ?><tr><td colspan="14" class="muted">Noch keine Events im Zeitraum.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </section>
    <?php endif; ?>
  </main>
  <div id="uaOverlay" class="ua-overlay" hidden>
    <div class="ua-panel" role="dialog" aria-label="UserAgent">
      <div class="ua-panel-head">
        <strong>UserAgent</strong>
        <button id="uaOverlayClose" class="ua-close" type="button">X</button>
      </div>
      <code id="uaOverlayText"></code>
      <code id="uaOverlayReasons" hidden></code>
    </div>
  </div>
  <script>
    (() => {
      "use strict";

      const initialLatestId = Number(<?= json_encode($latestEventId) ?>) || 0;
      const initialLatestEventTs = Number(<?= json_encode($latestEventTs) ?>) || 0;
      const enabledKey = "pixlStatsBrowserNotificationsEnabled";
      const mutedKey = "pixlStatsBrowserNotificationsMuted";
      const lastIdKey = "pixlStatsBrowserNotificationsLastId";
      const lastEventTsKey = "pixlStatsBrowserNotificationsLastEventTs";
      const pollIntervalMs = 15000;
      const button = document.getElementById("pixlNotificationButton");
      const offButton = document.getElementById("pixlNotificationOffButton");
      const muteToggle = document.getElementById("pixlNotificationMute");
      const status = document.getElementById("pixlNotificationStatus");
      const uaOverlay = document.getElementById("uaOverlay");
      const uaOverlayText = document.getElementById("uaOverlayText");
      const uaOverlayReasons = document.getElementById("uaOverlayReasons");
      const uaOverlayClose = document.getElementById("uaOverlayClose");

      if (!button || !offButton || !status || !muteToggle) return;

      let enabled = window.localStorage.getItem(enabledKey) === "1";
      let muted = window.localStorage.getItem(mutedKey) === "1";
      let lastId = Math.max(
        Number(window.localStorage.getItem(lastIdKey)) || 0,
        enabled ? 0 : initialLatestId
      );
      let lastEventTs = Math.max(
        Number(window.localStorage.getItem(lastEventTsKey)) || 0,
        enabled ? 0 : initialLatestEventTs
      );
      let timer = null;
      let ageTimer = null;
      let polling = false;
      let refreshingDashboard = false;
      let audioContext = null;
      let audioUnlocked = false;

      function setStatus(text) {
        status.textContent = text;
      }

      function closeUserAgentOverlay() {
        if (!uaOverlay) return;
        uaOverlay.hidden = true;
      }

      function openUserAgentOverlay(trigger) {
        if (!uaOverlay || !uaOverlayText || !uaOverlayReasons) return;

        uaOverlayText.textContent = trigger.dataset.userAgent || "";
        uaOverlayReasons.textContent = trigger.dataset.botReasons || "";
        uaOverlayReasons.hidden = !trigger.dataset.botReasons;
        uaOverlay.hidden = false;

        const margin = 14;
        const rowOffset = 78;
        const rect = trigger.getBoundingClientRect();
        const panelWidth = Math.min(980, window.innerWidth - margin * 2);
        const left = Math.max(
          margin,
          Math.min(window.innerWidth - panelWidth - margin, rect.right - panelWidth)
        );
        uaOverlay.style.width = `${panelWidth}px`;
        uaOverlay.style.left = `${Math.round(left)}px`;
        uaOverlay.style.top = `${Math.round(rect.bottom + rowOffset)}px`;

        const panel = uaOverlay.querySelector(".ua-panel");
        const panelHeight = panel ? panel.offsetHeight : 180;
        const maxTop = Math.max(margin, window.innerHeight - panelHeight - margin);
        const top = Math.max(margin, Math.min(maxTop, rect.bottom + rowOffset));
        uaOverlay.style.top = `${Math.round(top)}px`;
      }

      document.addEventListener("click", (event) => {
        const target = event.target instanceof Element ? event.target : null;
        if (!target) return;

        const trigger = target.closest(".ua-trigger");
        if (trigger) {
          openUserAgentOverlay(trigger);
          return;
        }

        if (target === uaOverlay || target === uaOverlayClose || target.closest("#uaOverlayClose")) {
          closeUserAgentOverlay();
          return;
        }

        if (uaOverlay && !uaOverlay.hidden && !target.closest(".ua-panel")) {
          closeUserAgentOverlay();
        }
      });

      document.addEventListener("keydown", (event) => {
        if (event.key === "Escape") {
          closeUserAgentOverlay();
        }
      });

      function saveLastId(value) {
        lastId = Math.max(lastId, Number(value) || 0);
        window.localStorage.setItem(lastIdKey, String(lastId));
      }

      function saveLastEventTs(value) {
        lastEventTs = Math.max(lastEventTs, Number(value) || 0);
        window.localStorage.setItem(lastEventTsKey, String(lastEventTs));
      }

      function saveEventCheckpoint(event) {
        if (!event) return;
        saveLastId(event.id);
        saveLastEventTs(event.createdTs);
      }

      function lastEventAgeSeconds() {
        if (!lastEventTs) return null;
        return Math.max(0, Math.floor(Date.now() / 1000 - lastEventTs));
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
        const eventId = lastId || Number(fallbackLatestId) || initialLatestId;
        const age = lastEventAgeSeconds();
        const soundText = muted ? "ohne Ton" : "mit Ton";
        if (!eventId) {
          return `Aktiv ${soundText} alle 15s, noch kein Event`;
        }
        return `Aktiv ${soundText} alle 15s, letzter Event #${eventId}, vor ${formatEventAge(age)}`;
      }

      function setActiveStatus(fallbackLatestId = 0) {
        setStatus(activeStatusText(fallbackLatestId));
      }

      function setEnabled(value) {
        enabled = !!value;
        window.localStorage.setItem(enabledKey, enabled ? "1" : "0");
        button.classList.toggle("notify-active", enabled);
        offButton.disabled = !enabled;
        button.textContent = enabled
          ? "Browser-Notifikation aktiv"
          : "Browser-Notifikation aktivieren";
      }

      function setMuted(value) {
        muted = !!value;
        window.localStorage.setItem(mutedKey, muted ? "1" : "0");
        muteToggle.checked = muted;
        if (enabled && supportsNotifications() && Notification.permission === "granted") {
          setActiveStatus();
        }
      }

      function supportsNotifications() {
        return "Notification" in window;
      }

      function getAudioContext() {
        const AudioContextCtor = window.AudioContext || window.webkitAudioContext;
        if (!AudioContextCtor) {
          return null;
        }
        if (!audioContext) {
          audioContext = new AudioContextCtor();
        }
        return audioContext;
      }

      async function unlockAudio() {
        const context = getAudioContext();
        if (!context) {
          return false;
        }

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

          audioUnlocked = true;
          return true;
        } catch {
          audioUnlocked = false;
          return false;
        }
      }

      function playNotificationSound() {
        if (muted) {
          return;
        }

        const context = getAudioContext();
        if (!context || (!audioUnlocked && context.state === "suspended")) {
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
        } catch {
          // Notification still works if audio is blocked by the browser.
        }
      }

      function requestPermission() {
        if (!supportsNotifications()) {
          return Promise.resolve("unsupported");
        }

        try {
          const result = Notification.requestPermission();
          if (result && typeof result.then === "function") {
            return result;
          }
        } catch {
          // Older browsers may require the callback signature.
        }

        return new Promise((resolve) => {
          Notification.requestPermission(resolve);
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
        const response = await fetch(feedUrl(after), {
          cache: "no-store",
          credentials: "same-origin"
        });
        if (!response.ok) {
          throw new Error(`feed_${response.status}`);
        }
        return response.json();
      }

      async function refreshDashboard() {
        if (refreshingDashboard) return;
        refreshingDashboard = true;

        try {
          const url = new URL(window.location.href);
          url.searchParams.set("_refresh", String(Date.now()));
          const response = await fetch(url.toString(), {
            cache: "no-store",
            credentials: "same-origin"
          });
          if (!response.ok) {
            throw new Error(`refresh_${response.status}`);
          }

          const html = await response.text();
          const doc = new DOMParser().parseFromString(html, "text/html");
          const nextMain = doc.querySelector("main");
          const currentMain = document.querySelector("main");
          if (nextMain && currentMain) {
            currentMain.innerHTML = nextMain.innerHTML;
          }
        } catch {
          // Notifications are more important than a live table refresh.
        } finally {
          refreshingDashboard = false;
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
      }

      async function poll() {
        if (!enabled || !supportsNotifications() || Notification.permission !== "granted") {
          return;
        }
        if (polling) return;
        polling = true;

        try {
          const data = await fetchEvents(lastId);
          const events = Array.isArray(data.events) ? data.events : [];

          for (const event of events) {
            notifyEvent(event);
            saveEventCheckpoint(event);
          }

          if (events.length > 0) {
            refreshDashboard();
          } else if (Number(data.latestEventTs) > 0 && Number(data.latestId) === lastId) {
            saveLastEventTs(data.latestEventTs);
          }

          setActiveStatus(Number(data.latestId) || 0);
        } catch {
          setStatus("Aktiv, Feed aktuell nicht erreichbar");
        } finally {
          polling = false;
        }
      }

      function startPolling() {
        if (timer) return;
        poll();
        timer = window.setInterval(poll, pollIntervalMs);
        if (!ageTimer) {
          ageTimer = window.setInterval(() => {
            if (enabled && supportsNotifications() && Notification.permission === "granted") {
              setActiveStatus();
            }
          }, 1000);
        }
      }

      function stopPolling() {
        if (timer) {
          window.clearInterval(timer);
          timer = null;
        }
        if (ageTimer) {
          window.clearInterval(ageTimer);
          ageTimer = null;
        }
        polling = false;
      }

      function showTestNotification() {
        if (!supportsNotifications() || Notification.permission !== "granted") {
          return;
        }

        playNotificationSound();

        const test = new Notification("Pixl Browser-Notifikation aktiv", {
          body: muted
            ? "Neue Pixl-Events werden jetzt hier gemeldet - ohne lokalen Ton."
            : "Neue Pixl-Events werden jetzt hier gemeldet - mit Ton.",
          tag: "pixl-notification-ready",
          renotify: false
        });
        test.onclick = () => {
          window.focus();
          test.close();
        };
      }

      async function enableNotifications() {
        if (!supportsNotifications()) {
          setEnabled(false);
          setStatus("Dieser Browser unterstützt keine Notifications");
          return;
        }

        await unlockAudio();
        setStatus("Genehmigung wird angefragt");
        const permission = await requestPermission();

        if (permission === "granted") {
          saveLastId(initialLatestId);
          saveLastEventTs(initialLatestEventTs);
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
        saveLastId(initialLatestId);
        saveLastEventTs(initialLatestEventTs);
        setStatus("Aus");
      }

      button.addEventListener("click", enableNotifications);
      offButton.addEventListener("click", disableNotifications);
      muteToggle.addEventListener("change", () => {
        setMuted(muteToggle.checked);
      });
      setMuted(muted);

      if (!supportsNotifications()) {
        setEnabled(false);
        setStatus("Nicht unterstützt");
        return;
      }

      if (enabled && Notification.permission === "granted") {
        setEnabled(true);
        setActiveStatus(initialLatestId);
        startPolling();
      } else if (Notification.permission === "denied") {
        setEnabled(false);
        setStatus("Vom Browser blockiert");
      } else {
        setEnabled(false);
        saveLastId(initialLatestId);
        saveLastEventTs(initialLatestEventTs);
        setStatus("Aus");
      }
    })();
  </script>
</body>
</html>
