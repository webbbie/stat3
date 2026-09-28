<?php
declare(strict_types=1);

require __DIR__ . '/pixl_server.php';

pixl_require_stats_auth();

const COMP_SESSION_GAP_SECONDS = 1800;
const COMP_TOP_LIMIT = 25;
const COMP_ENGLISH_COUNTRY_LIMIT = 25;
const COMP_ALL_COUNTRY_LIMIT = 30;
const COMP_MIN_SESSIONS = 2;

function comp_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function comp_number($value): string
{
    return number_format((float)$value, 0, ',', '.');
}

function comp_percent(int $part, int $total): string
{
    if ($total <= 0) {
        return '0,0%';
    }
    return number_format(($part / $total) * 100, 1, ',', '.') . '%';
}

function comp_country_label(string $value): string
{
    $value = strtoupper(trim($value));
    if ($value === '') {
        return 'Unknown';
    }
    if (preg_match('/^[A-Z]{2}$/', $value) && class_exists('Locale')) {
        $name = Locale::getDisplayRegion('und_' . $value, 'de');
        if (is_string($name) && $name !== '' && strtoupper($name) !== $value) {
            return $name . ' (' . $value . ')';
        }
    }
    return $value;
}

function comp_country_name(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $code = strtoupper($value);
    if (!preg_match('/^[A-Z]{2}$/', $code)) {
        return $value;
    }
    if (class_exists('Locale')) {
        $name = trim((string)Locale::getDisplayRegion('und_' . $code, 'de'));
        if ($name !== '' && strtoupper($name) !== $code && strcasecmp($name, 'Unbekannte Region') !== 0) {
            return $name;
        }
    }
    $fallback = pixl_notification_country_name($code);
    return strcasecmp($fallback, $code) !== 0 ? $fallback : '';
}

function comp_language_name(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return 'Unknown';
    }
    $locale = str_replace('-', '_', $value);
    if (class_exists('Locale')) {
        $name = Locale::getDisplayLanguage($locale, 'de');
        if (is_string($name) && $name !== '' && strcasecmp($name, $value) !== 0) {
            return function_exists('mb_convert_case')
                ? mb_convert_case($name, MB_CASE_TITLE, 'UTF-8')
                : ucfirst($name);
        }
    }

    $baseCode = strtolower((string)(preg_split('/[-_]/', $value, 2)[0] ?? $value));
    $fallback = [
        'ar' => 'Arabisch',
        'de' => 'Deutsch',
        'en' => 'Englisch',
        'es' => 'Spanisch',
        'fr' => 'Französisch',
        'it' => 'Italienisch',
        'ja' => 'Japanisch',
        'nl' => 'Niederländisch',
        'pl' => 'Polnisch',
        'pt' => 'Portugiesisch',
        'ru' => 'Russisch',
        'tr' => 'Türkisch',
        'uk' => 'Ukrainisch',
        'zh' => 'Chinesisch',
    ];
    return $fallback[$baseCode] ?? $value;
}

function comp_language_label(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return 'Unknown';
    }
    $name = comp_language_name($value);
    if (strcasecmp($name, $value) !== 0) {
        return $name . ' (' . $value . ')';
    }
    return $value;
}

function comp_resolution(string $screen, string $viewport): string
{
    $raw = trim($screen) !== '' ? trim($screen) : trim($viewport);
    if (preg_match('/(\d{2,5})\D+(\d{2,5})/', $raw, $matches)) {
        return (int)$matches[1] . 'x' . (int)$matches[2];
    }
    return $raw !== '' ? $raw : 'Unknown';
}

function comp_browser_group(string $value): string
{
    $value = trim($value);
    if ($value === '' || strcasecmp($value, 'Unknown') === 0) {
        return 'Unknown';
    }
    return preg_match('/(?:chrome|crios)/i', $value) ? 'Chrome' : '';
}

function comp_sort_rows(array &$rows, array $keys = ['sessions']): void
{
    usort($rows, static function (array $left, array $right) use ($keys): int {
        foreach ($keys as $key) {
            $comparison = ((int)($right[$key] ?? 0)) <=> ((int)($left[$key] ?? 0));
            if ($comparison !== 0) {
                return $comparison;
            }
        }
        return strcmp(implode('|', $left), implode('|', $right));
    });
}

function comp_add_count(array &$target, string $key, int $amount = 1): void
{
    $target[$key] = ($target[$key] ?? 0) + $amount;
}

function comp_top_rows(array $rows, bool $top25Only): array
{
    return $top25Only ? array_slice($rows, 0, COMP_TOP_LIMIT, true) : $rows;
}

function comp_minimum_rows(array $rows): array
{
    return array_values(array_filter(
        $rows,
        static fn(array $row): bool => (int)($row['sessions'] ?? 0) >= COMP_MIN_SESSIONS
    ));
}

function comp_sort_bounce_rate(array &$rows): void
{
    usort($rows, static function (array $left, array $right): int {
        $leftSessions = max(1, (int)($left['sessions'] ?? 0));
        $rightSessions = max(1, (int)($right['sessions'] ?? 0));
        $leftBounces = (int)($left['bounces'] ?? 0);
        $rightBounces = (int)($right['bounces'] ?? 0);

        // Brueche ueber Kreuz vergleichen, damit die Sortierung nicht durch
        // gerundete Prozentwerte beeinflusst wird.
        $rateComparison = ($rightBounces * $leftSessions) <=> ($leftBounces * $rightSessions);
        if ($rateComparison !== 0) {
            return $rateComparison;
        }

        $sessionComparison = $rightSessions <=> $leftSessions;
        if ($sessionComparison !== 0) {
            return $sessionComparison;
        }

        return strcmp(implode('|', $left), implode('|', $right));
    });
}

$days = max(1, min(365, (int)($_GET['days'] ?? 30)));
$timeFilter = pixl_stats_time_filter($_GET['range'] ?? '', $days);
$timeRange = $timeFilter['range'];
$excludeGermans = isset($_GET['exclude_germans']) && $_GET['exclude_germans'] === '1';
$top25Only = !isset($_GET['top25']) || $_GET['top25'] !== '0';

$error = '';
$scopeNote = '';
$eventCount = 0;
$sessionCount = 0;
$bounceCount = 0;
$countries = [];
$languages = [];
$languageCountries = [];
$tripleCounts = [];
$countryBounce = [];
$countryLanguageBounce = [];
$countryBrowser = [];
$maxPeriodCountryCounts = [];

$finishSession = static function (?array $session) use (
    &$sessionCount,
    &$bounceCount,
    &$countries,
    &$languages,
    &$languageCountries,
    &$tripleCounts,
    &$countryBounce,
    &$countryLanguageBounce,
    &$countryBrowser
): void {
    if ($session === null) {
        return;
    }

    $country = $session['country'];
    $language = $session['language'];
    $resolution = $session['resolution'];
    $browser = $session['browser'];
    $isBounce = count($session['pages']) <= 1;

    $sessionCount++;
    $bounceCount += $isBounce ? 1 : 0;
    $countries[$country] = true;
    $languages[$language] = true;
    if (!isset($languageCountries[$language])) {
        $languageCountries[$language] = [];
    }
    comp_add_count($languageCountries[$language], $country);

    $tripleKey = $country . "\x1f" . $language . "\x1f" . $resolution;
    if (!isset($tripleCounts[$tripleKey])) {
        $tripleCounts[$tripleKey] = [
            'country' => $country,
            'language' => $language,
            'resolution' => $resolution,
            'sessions' => 0,
        ];
    }
    $tripleCounts[$tripleKey]['sessions']++;

    if (!isset($countryBounce[$country])) {
        $countryBounce[$country] = ['country' => $country, 'sessions' => 0, 'bounces' => 0];
    }
    $countryBounce[$country]['sessions']++;
    $countryBounce[$country]['bounces'] += $isBounce ? 1 : 0;

    $countryLanguageKey = $country . "\x1f" . $language;
    if (!isset($countryLanguageBounce[$countryLanguageKey])) {
        $countryLanguageBounce[$countryLanguageKey] = [
            'country' => $country,
            'language' => $language,
            'sessions' => 0,
            'bounces' => 0,
        ];
    }
    $countryLanguageBounce[$countryLanguageKey]['sessions']++;
    $countryLanguageBounce[$countryLanguageKey]['bounces'] += $isBounce ? 1 : 0;

    if ($browser !== '') {
        $browserKey = $country . "\x1f" . $browser;
        if (!isset($countryBrowser[$browserKey])) {
            $countryBrowser[$browserKey] = [
                'country' => $country,
                'browser' => $browser,
                'sessions' => 0,
            ];
        }
        $countryBrowser[$browserKey]['sessions']++;
    }
};

try {
    $pdo = pixl_pdo();
    pixl_ensure_schema($pdo);
    $table = pixl_table_name();
    $pageExpr = pixl_sql_page_expression();
    $conditions = [
        '`created_at` >= :since',
        '`visitor_hash` <> \'\'',
        '`is_bot` = 0',
    ];
    $statsScope = pixl_sql_configured_stats_url_condition($pdo);
    if ($statsScope !== '') {
        $conditions[] = $statsScope;
        $scopeNote = 'Nur konfigurierte stats_urls';
    } else {
        $scopeNote = 'Alle URLs';
    }
    if ($excludeGermans) {
        $conditions[] = pixl_sql_exclude_german_country_condition();
    }

    // Die Rubrik "Land" aus der Pixl-SQL-Statistik, aber ueber den dort
    // maximal waehlbaren Zeitraum von 365 Tagen. Wie in der Pixl-Statistik
    // werden hier Ereignisse (einschliesslich Bots) nach Land gezaehlt.
    $maxPeriodFilter = pixl_stats_time_filter('', 365);
    $maxPeriodConditions = ['`created_at` >= :max_period_since'];
    if ($statsScope !== '') {
        $maxPeriodConditions[] = $statsScope;
    }
    if ($excludeGermans) {
        $maxPeriodConditions[] = pixl_sql_exclude_german_country_condition();
    }
    $maxPeriodStatement = $pdo->prepare(
        "SELECT `country`, COUNT(*) AS `events`
         FROM `$table`
         WHERE " . implode(' AND ', $maxPeriodConditions) . "
           AND TRIM(`country`) <> ''
         GROUP BY `country`
         ORDER BY `events` DESC"
    );
    $maxPeriodStatement->execute([':max_period_since' => $maxPeriodFilter['since']]);
    while ($row = $maxPeriodStatement->fetch(PDO::FETCH_ASSOC)) {
        $countryName = comp_country_name((string)$row['country']);
        if ($countryName === '' || strcasecmp($countryName, 'Unknown') === 0) {
            continue;
        }
        comp_add_count($maxPeriodCountryCounts, $countryName, (int)$row['events']);
    }
    arsort($maxPeriodCountryCounts);

    $statement = $pdo->prepare(
        "SELECT `id`, `created_at`, `visitor_hash`, `country`, `language`, `screen`, `viewport`, `browser`,
                $pageExpr AS `page`
         FROM `$table`
         WHERE " . implode(' AND ', $conditions) . "
         ORDER BY `visitor_hash` ASC, `created_at` ASC, `id` ASC"
    );
    $statement->execute([':since' => $timeFilter['since']]);

    $current = null;
    while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
        $timestamp = strtotime((string)$row['created_at']);
        if ($timestamp === false) {
            continue;
        }
        $eventCount++;
        $visitor = (string)$row['visitor_hash'];
        $startsNew = $current === null
            || $current['visitor'] !== $visitor
            || ($timestamp - $current['last_timestamp']) > COMP_SESSION_GAP_SECONDS;

        if ($startsNew) {
            $finishSession($current);
            $country = strtoupper(trim((string)$row['country'])) ?: 'Unknown';
            $language = trim((string)$row['language']) ?: 'Unknown';
            $current = [
                'visitor' => $visitor,
                'last_timestamp' => $timestamp,
                'country' => $country,
                'language' => $language,
                'resolution' => comp_resolution((string)$row['screen'], (string)$row['viewport']),
                'browser' => comp_browser_group((string)$row['browser']),
                'pages' => [],
            ];
        }

        $current['last_timestamp'] = $timestamp;
        $current['pages'][(string)$row['page']] = true;
    }
    $finishSession($current);
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}

$rawTripleRows = array_values($tripleCounts);
$tripleAggregated = [];
foreach ($rawTripleRows as $row) {
    $country = (string)$row['country'];
    $language = comp_language_name((string)$row['language']);
    $resolution = (string)$row['resolution'];
    $key = $country . "\x1f" . $language . "\x1f" . $resolution;
    if (!isset($tripleAggregated[$key])) {
        $tripleAggregated[$key] = [
            'country' => $country,
            'language' => $language,
            'resolution' => $resolution,
            'sessions' => 0,
        ];
    }
    $tripleAggregated[$key]['sessions'] += (int)$row['sessions'];
}
$tripleRows = comp_minimum_rows(array_values($tripleAggregated));
$countryBounceAggregated = [];
foreach ($countryBounce as $row) {
    $countryName = comp_country_name((string)$row['country']);
    if ($countryName === '' || strcasecmp($countryName, 'Unknown') === 0) {
        continue;
    }
    if (!isset($countryBounceAggregated[$countryName])) {
        $countryBounceAggregated[$countryName] = [
            'country' => $countryName,
            'sessions' => 0,
            'bounces' => 0,
        ];
    }
    $countryBounceAggregated[$countryName]['sessions'] += (int)$row['sessions'];
    $countryBounceAggregated[$countryName]['bounces'] += (int)$row['bounces'];
}
$countryBounceRows = comp_minimum_rows(array_values($countryBounceAggregated));
$countryLanguageBounceAggregated = [];
foreach ($countryLanguageBounce as $row) {
    $country = (string)$row['country'];
    $language = comp_language_name((string)$row['language']);
    $key = $country . "\x1f" . $language;
    if (!isset($countryLanguageBounceAggregated[$key])) {
        $countryLanguageBounceAggregated[$key] = [
            'country' => $country,
            'language' => $language,
            'sessions' => 0,
            'bounces' => 0,
        ];
    }
    $countryLanguageBounceAggregated[$key]['sessions'] += (int)$row['sessions'];
    $countryLanguageBounceAggregated[$key]['bounces'] += (int)$row['bounces'];
}
$countryLanguageBounceRows = comp_minimum_rows(array_values($countryLanguageBounceAggregated));
$countryBrowserRows = comp_minimum_rows(array_values($countryBrowser));
comp_sort_rows($tripleRows);
comp_sort_bounce_rate($countryBounceRows);
comp_sort_bounce_rate($countryLanguageBounceRows);
comp_sort_rows($countryBrowserRows);

$englishCountryMap = [];
foreach ($languageCountries as $language => $countryList) {
    if (stripos((string)$language, 'en') === false) {
        continue;
    }
    foreach ($countryList as $country => $sessions) {
        $country = (string)$country;
        if (!isset($englishCountryMap[$country])) {
            $englishCountryMap[$country] = [
                'country' => $country,
                'languages' => [],
                'sessions' => 0,
            ];
        }
        $englishCountryMap[$country]['languages'][(string)$language] = (int)$sessions;
        $englishCountryMap[$country]['sessions'] += (int)$sessions;
    }
}
$englishCountryRows = array_values($englishCountryMap);
foreach ($englishCountryRows as &$row) {
    $row['languages'] = array_filter(
        $row['languages'],
        static fn(int $sessions): bool => $sessions >= COMP_MIN_SESSIONS
    );
    arsort($row['languages']);
    $row['sessions'] = array_sum($row['languages']);
}
unset($row);
$englishCountryRows = comp_minimum_rows($englishCountryRows);
comp_sort_rows($englishCountryRows);
$englishSessionCount = array_sum(array_column($englishCountryRows, 'sessions'));

$globalBounceByCountry = [];
foreach ($countryBounceRows as $row) {
    $globalBounceByCountry[(string)$row['country']] = $row;
}
$eligibleEnglishCountries = [];
foreach ($englishCountryRows as $row) {
    $countryCode = strtoupper(trim((string)$row['country']));
    $countryName = comp_country_name($countryCode);
    if ($countryName === '') {
        continue;
    }
    $globalBounce = $globalBounceByCountry[$countryName] ?? null;
    if (is_array($globalBounce)
        && (int)$globalBounce['sessions'] > 0
        && (int)$globalBounce['bounces'] === (int)$globalBounce['sessions']) {
        continue;
    }
    $eligibleEnglishCountries[$countryCode] = $countryName;
}
$topEnglishCountries = array_slice($eligibleEnglishCountries, 0, COMP_ENGLISH_COUNTRY_LIMIT, true);
$topEnglishCountryNames = array_values($topEnglishCountries);

// Neue Top-30-Kommaliste: zuerst die englische Top 25 des gewaehlten
// Zeitraums, danach die staerksten Land-Eintraege aus maximal 365 Tagen.
// Der ausgeschriebene Ländername ist der Deduplizierungsschluessel.
$topAllCountries = [];
foreach ($topEnglishCountryNames as $countryName) {
    $countryKey = function_exists('mb_strtolower')
        ? mb_strtolower($countryName, 'UTF-8')
        : strtolower($countryName);
    $topAllCountries[$countryKey] = $countryName;
}
foreach ($maxPeriodCountryCounts as $countryName => $events) {
    if (count($topAllCountries) >= COMP_ALL_COUNTRY_LIMIT) {
        break;
    }
    $countryKey = function_exists('mb_strtolower')
        ? mb_strtolower((string)$countryName, 'UTF-8')
        : strtolower((string)$countryName);
    if (!isset($topAllCountries[$countryKey])) {
        $topAllCountries[$countryKey] = (string)$countryName;
    }
}
$topAllCountryNames = array_slice(array_values($topAllCountries), 0, COMP_ALL_COUNTRY_LIMIT);

$englishTripleMap = [];
foreach ($rawTripleRows as $row) {
    if (stripos((string)$row['language'], 'en') === false) {
        continue;
    }
    $country = (string)$row['country'];
    if (!isset($englishTripleMap[$country])) {
        $englishTripleMap[$country] = [
            'country' => $country,
            'pairs' => [],
            'sessions' => 0,
        ];
    }
    $englishTripleMap[$country]['pairs'][] = [
        'language' => (string)$row['language'],
        'resolution' => (string)$row['resolution'],
        'sessions' => (int)$row['sessions'],
    ];
    $englishTripleMap[$country]['sessions'] += (int)$row['sessions'];
}
$englishTripleRows = array_values($englishTripleMap);
foreach ($englishTripleRows as &$row) {
    $row['pairs'] = comp_minimum_rows($row['pairs']);
    comp_sort_rows($row['pairs']);
    $row['sessions'] = array_sum(array_column($row['pairs'], 'sessions'));
}
unset($row);
$englishTripleRows = comp_minimum_rows($englishTripleRows);
comp_sort_rows($englishTripleRows);

$countryBrowserComparison = [];
foreach ($countryBrowserRows as $row) {
    $country = (string)$row['country'];
    if (!isset($countryBrowserComparison[$country])) {
        $countryBrowserComparison[$country] = [
            'country' => $country,
            'Chrome' => 0,
            'Unknown' => 0,
            'sessions' => 0,
        ];
    }
    $browser = (string)$row['browser'];
    $countryBrowserComparison[$country][$browser] = (int)$row['sessions'];
    $countryBrowserComparison[$country]['sessions'] += (int)$row['sessions'];
}
$countryBrowserComparison = array_values($countryBrowserComparison);
$countryBrowserComparison = comp_minimum_rows($countryBrowserComparison);
comp_sort_rows($countryBrowserComparison);

uksort($languageCountries, static function (string $left, string $right) use ($languageCountries): int {
    return array_sum($languageCountries[$right]) <=> array_sum($languageCountries[$left]);
});
foreach ($languageCountries as &$countryList) {
    arsort($countryList);
}
unset($countryList);

$aggregatedLanguageCountries = [];
foreach ($languageCountries as $language => $countryList) {
    $languageName = comp_language_name((string)$language);
    if (!isset($aggregatedLanguageCountries[$languageName])) {
        $aggregatedLanguageCountries[$languageName] = [];
    }
    foreach ($countryList as $country => $sessions) {
        comp_add_count($aggregatedLanguageCountries[$languageName], (string)$country, (int)$sessions);
    }
}
uksort($aggregatedLanguageCountries, static function (string $left, string $right) use ($aggregatedLanguageCountries): int {
    return array_sum($aggregatedLanguageCountries[$right]) <=> array_sum($aggregatedLanguageCountries[$left]);
});
foreach ($aggregatedLanguageCountries as &$countryList) {
    $countryList = array_filter(
        $countryList,
        static fn(int $sessions): bool => $sessions >= COMP_MIN_SESSIONS
    );
    arsort($countryList);
}
unset($countryList);
$aggregatedLanguageCountries = array_filter(
    $aggregatedLanguageCountries,
    static fn(array $countryList): bool => $countryList !== []
);

$visibleEnglishCountryRows = comp_top_rows($englishCountryRows, $top25Only);
$visibleEnglishTripleRows = comp_top_rows($englishTripleRows, $top25Only);
$visibleLanguageCountries = comp_top_rows($aggregatedLanguageCountries, $top25Only);
$visibleTripleRows = comp_top_rows($tripleRows, $top25Only);
$visibleCountryBounceRows = comp_top_rows($countryBounceRows, $top25Only);
$visibleCountryLanguageBounceRows = comp_top_rows($countryLanguageBounceRows, $top25Only);
$visibleCountryBrowserComparison = comp_top_rows($countryBrowserComparison, $top25Only);

function comp_query(array $changes = []): string
{
    global $days, $timeRange, $excludeGermans, $top25Only;
    $params = [
        'days' => $days,
        'range' => $timeRange,
        'exclude_germans' => $excludeGermans ? 1 : 0,
        'top25' => $top25Only ? 1 : 0,
    ];
    foreach ($changes as $key => $value) {
        $params[$key] = $value;
    }
    return '?' . http_build_query($params);
}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light dark">
  <title>Stats3 Vergleiche</title>
  <style>
    :root { color-scheme: light dark; --bg:#eef2f4; --surface:#fff; --surface-alt:#f7f9fa; --ink:#172126; --muted:#66747c; --line:#d6dfe3; --accent:#087f73; --accent-soft:#e2f4f0; --danger:#a43f4e; --danger-soft:#f9e8eb; }
    @media (prefers-color-scheme: dark) { :root { --bg:#121719; --surface:#1c2327; --surface-alt:#171d21; --ink:#eef3f4; --muted:#a6b1b6; --line:#354047; --accent:#54c8b8; --accent-soft:#203b37; --danger:#e497a3; --danger-soft:#42282e; } }
    * { box-sizing:border-box; }
    body { min-width:320px; margin:0; color:var(--ink); background:var(--bg); font:14px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; }
    a { color:var(--accent); text-underline-offset:3px; }
    h1,h2,p { margin:0; }
    .topbar { position:sticky; z-index:10; top:0; border-bottom:1px solid var(--line); background:var(--surface); }
    .topbar-inner, main { width:min(1440px,100%); margin:auto; padding-inline:20px; }
    .topbar-inner { min-height:68px; display:flex; align-items:center; gap:16px; padding-block:10px; overflow-x:auto; }
    .title { flex:1 0 auto; white-space:nowrap; }
    h1 { font-size:27px; line-height:1.15; }
    .subtitle { margin-top:3px; color:var(--muted); font-size:12px; }
    .controls { display:flex; align-items:center; gap:7px; white-space:nowrap; }
    .controls a, .controls button, .controls select { min-height:36px; border:1px solid var(--line); border-radius:6px; padding:7px 10px; color:var(--ink); background:var(--surface-alt); font:inherit; font-weight:750; text-decoration:none; cursor:pointer; }
    .controls a.active, .controls button.primary { color:#fff; border-color:var(--accent); background:var(--accent); }
    main { padding-block:22px 44px; }
    .notice { margin-bottom:18px; border:1px solid var(--danger); border-radius:7px; padding:12px 14px; color:var(--danger); background:var(--danger-soft); }
    .metrics { display:grid; grid-template-columns:repeat(5,minmax(0,1fr)); gap:10px; }
    .metric, section { border:1px solid var(--line); border-radius:8px; background:var(--surface); }
    .metric { padding:14px; }
    .metric span { color:var(--muted); font-size:11px; font-weight:800; text-transform:uppercase; }
    .metric strong { display:block; margin-top:8px; font-size:26px; font-variant-numeric:tabular-nums; }
    section { margin-top:20px; overflow:hidden; }
    .section-head { padding:14px 16px; border-bottom:1px solid var(--line); background:var(--surface-alt); }
    h2 { font-size:18px; }
    .section-head p { margin-top:3px; color:var(--muted); font-size:12px; }
    .language-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:10px; padding:12px; }
    .language-card { min-width:0; border:1px solid var(--line); border-radius:7px; padding:12px; background:var(--surface-alt); }
    .language-card h3 { margin:0 0 9px; font-size:14px; }
    .country-list { display:flex; flex-wrap:wrap; gap:6px; }
    .chip { display:inline-flex; gap:5px; border-radius:999px; padding:4px 8px; background:var(--accent-soft); font-size:12px; }
    .chip strong { color:var(--accent); font-variant-numeric:tabular-nums; }
    .scroll { overflow-x:auto; }
    table { width:100%; border-collapse:collapse; font-size:13px; }
    th,td { padding:9px 12px; border-bottom:1px solid var(--line); text-align:left; vertical-align:top; }
    th { color:var(--muted); background:var(--surface-alt); font-size:11px; text-transform:uppercase; white-space:nowrap; }
    tbody tr:last-child td { border-bottom:0; }
    td.number { text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
    .rate { min-width:170px; }
    .bar { display:inline-block; width:100px; height:7px; margin-right:8px; overflow:hidden; border-radius:99px; background:var(--line); vertical-align:middle; }
    .bar i { display:block; height:100%; background:var(--accent); }
    .empty { padding:18px; color:var(--muted); }
    .table-note { padding:10px 12px; border-top:1px solid var(--line); color:var(--muted); background:var(--surface-alt); font-size:12px; }
    .top-country-list { margin-top:0; }
    .top-country-list + .metrics, .combined-country-list + .metrics { margin-top:20px; }
    .comma-list { padding:15px 16px; line-height:1.8; }
    @media (max-width:900px) { .metrics { grid-template-columns:repeat(3,minmax(0,1fr)); } .language-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } }
    @media (max-width:600px) { .topbar-inner,main { padding-inline:12px; } h1 { font-size:23px; } .metrics { grid-template-columns:repeat(2,minmax(0,1fr)); } .language-grid { grid-template-columns:1fr; } }
  </style>
</head>
<body>
  <header class="topbar">
    <div class="topbar-inner">
      <div class="title">
        <h1>Vergleichsstatistik</h1>
        <p class="subtitle"><?= comp_h($timeFilter['label']) ?> · <?= comp_h($scopeNote) ?> · menschliche Sitzungen · mindestens <?= COMP_MIN_SESSIONS ?> Sitzungen je Eintrag</p>
      </div>
      <nav class="controls" aria-label="Zeitraum und Navigation">
        <a class="<?= $timeRange === '1d' ? 'active' : '' ?>" href="<?= comp_h(comp_query(['range' => '1d'])) ?>">Heute</a>
        <a class="<?= $timeRange === '24h' ? 'active' : '' ?>" href="<?= comp_h(comp_query(['range' => '24h'])) ?>">24 h</a>
        <a class="<?= $timeRange === 'last7d' ? 'active' : '' ?>" href="<?= comp_h(comp_query(['range' => 'last7d'])) ?>">7 Tage</a>
        <a class="<?= $timeRange === '' && $days === 30 ? 'active' : '' ?>" href="<?= comp_h(comp_query(['range' => '', 'days' => 30])) ?>">30 Tage</a>
        <a class="<?= $top25Only ? 'active' : '' ?>" href="<?= comp_h(comp_query(['top25' => $top25Only ? 0 : 1])) ?>" aria-pressed="<?= $top25Only ? 'true' : 'false' ?>"><?= $top25Only ? 'Top 25 aktiv' : 'Top 25 anzeigen' ?></a>
        <a href="<?= comp_h(comp_query(['exclude_germans' => $excludeGermans ? 0 : 1])) ?>"><?= $excludeGermans ? 'Deutschland zeigen' : 'Deutschland ausblenden' ?></a>
        <a class="active" href="stats.php">Gesamtstatistik</a>
      </nav>
    </div>
  </header>

  <main>
    <?php if ($error !== ''): ?><div class="notice"><strong>Datenbankfehler:</strong> <?= comp_h($error) ?></div><?php endif; ?>

    <section class="top-country-list">
      <div class="section-head"><h2>Top 25 Länder mit den meisten englischsprachigen Sitzungen</h2><p>Quelle ist „Länder · Sprache enthält: en“; Länder mit exakt 100% globaler Bounce Rate sind ausgeschlossen.</p></div>
      <?php if ($topEnglishCountryNames === []): ?>
        <p class="empty">Keine passenden Länder im gewählten Zeitraum.</p>
      <?php else: ?>
        <p class="comma-list"><?= comp_h(implode(', ', $topEnglishCountryNames)) ?></p>
      <?php endif; ?>
    </section>

    <section class="combined-country-list">
      <div class="section-head"><h2>Top 30 Alles</h2><p>Zuerst die Top 25 Länder mit den meisten englischsprachigen Sitzungen, danach Land-Einträge aus der Pixl-SQL-Statistik über den maximalen Zeitraum von 365 Tagen; jedes Land erscheint nur einmal.</p></div>
      <?php if ($topAllCountryNames === []): ?>
        <p class="empty">Keine passenden Länder verfügbar.</p>
      <?php else: ?>
        <p class="comma-list"><?= comp_h(implode(', ', $topAllCountryNames)) ?></p>
        <?php if (count($topAllCountryNames) < COMP_ALL_COUNTRY_LIMIT): ?><p class="table-note"><?= comp_number(count($topAllCountryNames)) ?> von maximal <?= COMP_ALL_COUNTRY_LIMIT ?> Ländern verfügbar.</p><?php endif; ?>
      <?php endif; ?>
    </section>

    <div class="metrics" aria-label="Zusammenfassung">
      <article class="metric"><span>Ereignisse</span><strong><?= comp_number($eventCount) ?></strong></article>
      <article class="metric"><span>Sitzungen</span><strong><?= comp_number($sessionCount) ?></strong></article>
      <article class="metric"><span>Länder</span><strong><?= comp_number(count($countries)) ?></strong></article>
      <article class="metric"><span>Sprachen</span><strong><?= comp_number(count($languages)) ?></strong></article>
      <article class="metric"><span>Bounce Rate</span><strong><?= comp_h(comp_percent($bounceCount, $sessionCount)) ?></strong></article>
    </div>

    <section>
      <div class="section-head"><h2>Länder · Sprache enthält: en</h2><p>Jedes Land nur einmal; zugehörige Sprachwerte werden als Paare/Liste zusammengefasst.</p></div>
      <div class="scroll"><table><thead><tr><th>Land</th><th>Sprachpaare</th><th>Sitzungen</th><th>Anteil an en</th></tr></thead><tbody>
        <?php foreach ($visibleEnglishCountryRows as $row): ?>
          <tr>
            <td><?= comp_h(comp_country_label($row['country'])) ?></td>
            <td><div class="country-list"><?php foreach ($row['languages'] as $language => $sessions): ?><span class="chip"><?= comp_h($language) ?> <strong><?= comp_number($sessions) ?></strong></span><?php endforeach; ?></div></td>
            <td class="number"><?= comp_number($row['sessions']) ?></td>
            <td class="number"><?= comp_h(comp_percent((int)$row['sessions'], $englishSessionCount)) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($englishCountryRows === []): ?><tr><td colspan="4" class="empty">Keine Sitzungen mit einem Sprachwert vorhanden, der en enthält.</td></tr><?php endif; ?>
      </tbody></table></div>
      <?php if ($top25Only && count($englishCountryRows) > COMP_TOP_LIMIT): ?><p class="table-note">Top 25 von <?= comp_number(count($englishCountryRows)) ?> Ländern. Über den Button oben können alle angezeigt werden.</p><?php endif; ?>
    </section>

    <section>
      <div class="section-head"><h2>Land · Sprache · Bildschirmauflösung enthält: en</h2><p>Jedes Land nur einmal; Sprache und Bildschirmauflösung werden als Paare zusammengefasst.</p></div>
      <div class="scroll"><table><thead><tr><th>Land</th><th>Sprach- und Auflösungspaare</th><th>Sitzungen</th><th>Anteil an en</th></tr></thead><tbody>
        <?php foreach ($visibleEnglishTripleRows as $row): ?>
          <tr>
            <td><?= comp_h(comp_country_label($row['country'])) ?></td>
            <td><div class="country-list"><?php foreach ($row['pairs'] as $pair): ?><span class="chip"><?= comp_h($pair['language']) ?> · <?= comp_h($pair['resolution']) ?> <strong><?= comp_number($pair['sessions']) ?></strong></span><?php endforeach; ?></div></td>
            <td class="number"><?= comp_number($row['sessions']) ?></td>
            <td class="number"><?= comp_h(comp_percent((int)$row['sessions'], $englishSessionCount)) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($englishTripleRows === []): ?><tr><td colspan="4" class="empty">Keine Auflösungen mit einem Sprachwert vorhanden, der en enthält.</td></tr><?php endif; ?>
      </tbody></table></div>
      <?php if ($top25Only && count($englishTripleRows) > COMP_TOP_LIMIT): ?><p class="table-note">Top 25 von <?= comp_number(count($englishTripleRows)) ?> Ländern. Über den Button oben können alle angezeigt werden.</p><?php endif; ?>
    </section>

    <section>
      <div class="section-head"><h2>Sprachen → Länder (Mehrfachliste)</h2><p>Sprachvarianten werden zu Namen wie Englisch, Arabisch oder Französisch aggregiert; je Sprache werden die Länder gezeigt.</p></div>
      <?php if ($visibleLanguageCountries === []): ?>
        <p class="empty">Keine Sitzungen im gewählten Zeitraum.</p>
      <?php else: ?>
        <div class="language-grid">
          <?php foreach ($visibleLanguageCountries as $language => $countryList): ?>
            <article class="language-card">
              <h3><?= comp_h($language) ?> · <?= comp_number(array_sum($countryList)) ?></h3>
              <div class="country-list">
                <?php foreach (comp_top_rows($countryList, $top25Only) as $country => $count): ?>
                  <span class="chip"><?= comp_h(comp_country_label($country)) ?> <strong><?= comp_number($count) ?></strong></span>
                <?php endforeach; ?>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section>
      <div class="section-head"><h2>Land · Sprache · Bildschirmauflösung</h2><p>Sprachvarianten werden zu Namen wie Englisch, Arabisch oder Französisch aggregiert und nach Sitzungszahl sortiert.</p></div>
      <div class="scroll"><table><thead><tr><th>Land</th><th>Sprache</th><th>Bildschirmauflösung</th><th>Sitzungen</th><th>Anteil</th></tr></thead><tbody>
        <?php foreach ($visibleTripleRows as $row): ?>
          <tr><td><?= comp_h(comp_country_label($row['country'])) ?></td><td><?= comp_h($row['language']) ?></td><td><?= comp_h($row['resolution']) ?></td><td class="number"><?= comp_number($row['sessions']) ?></td><td class="number"><?= comp_h(comp_percent((int)$row['sessions'], $sessionCount)) ?></td></tr>
        <?php endforeach; ?>
        <?php if ($tripleRows === []): ?><tr><td colspan="5" class="empty">Keine Daten vorhanden.</td></tr><?php endif; ?>
      </tbody></table></div>
      <?php if ($top25Only && count($tripleRows) > COMP_TOP_LIMIT): ?><p class="table-note">Top 25 von <?= comp_number(count($tripleRows)) ?> Kombinationen. Über den Button oben können alle angezeigt werden.</p><?php endif; ?>
    </section>

    <section>
      <div class="section-head"><h2><?= $top25Only ? 'Top 25 ' : '' ?>Bounce Rate nach Land</h2><p>Ausgeschriebene Ländernamen, keine doppelten Länder und kein Unknown; nach Bounce Rate absteigend.</p></div>
      <div class="scroll"><table><thead><tr><th>Rang</th><th>Land</th><th>Sitzungen</th><th>Bounces</th><th>Bounce Rate</th></tr></thead><tbody>
        <?php foreach ($visibleCountryBounceRows as $index => $row): $rate = $row['sessions'] > 0 ? ($row['bounces'] / $row['sessions']) * 100 : 0; ?>
          <tr><td class="number"><?= comp_number($index + 1) ?></td><td><?= comp_h($row['country']) ?></td><td class="number"><?= comp_number($row['sessions']) ?></td><td class="number"><?= comp_number($row['bounces']) ?></td><td class="number rate"><span class="bar"><i style="width:<?= comp_h(number_format($rate, 2, '.', '')) ?>%"></i></span><?= comp_h(comp_percent((int)$row['bounces'], (int)$row['sessions'])) ?></td></tr>
        <?php endforeach; ?>
        <?php if ($countryBounceRows === []): ?><tr><td colspan="5" class="empty">Keine Daten vorhanden.</td></tr><?php endif; ?>
      </tbody></table></div>
    </section>

    <section>
      <div class="section-head"><h2><?= $top25Only ? 'Top 25 ' : '' ?>Bounce Rate nach Land und Sprache</h2><p>Nach Bounce Rate absteigend; Sprachvarianten werden zu Namen wie Englisch, Arabisch oder Französisch aggregiert.</p></div>
      <div class="scroll"><table><thead><tr><th>Land</th><th>Sprache</th><th>Sitzungen</th><th>Bounces</th><th>Bounce Rate</th></tr></thead><tbody>
        <?php foreach ($visibleCountryLanguageBounceRows as $row): $rate = $row['sessions'] > 0 ? ($row['bounces'] / $row['sessions']) * 100 : 0; ?>
          <tr><td><?= comp_h(comp_country_label($row['country'])) ?></td><td><?= comp_h(comp_language_label($row['language'])) ?></td><td class="number"><?= comp_number($row['sessions']) ?></td><td class="number"><?= comp_number($row['bounces']) ?></td><td class="number rate"><span class="bar"><i style="width:<?= comp_h(number_format($rate, 2, '.', '')) ?>%"></i></span><?= comp_h(comp_percent((int)$row['bounces'], (int)$row['sessions'])) ?></td></tr>
        <?php endforeach; ?>
        <?php if ($countryLanguageBounceRows === []): ?><tr><td colspan="5" class="empty">Keine Daten vorhanden.</td></tr><?php endif; ?>
      </tbody></table></div>
    </section>

    <section>
      <div class="section-head"><h2>Land → Browser: Chrome und Unknown</h2><p>Andere Browser werden in diesem Vergleich bewusst nicht aufgeführt.</p></div>
      <div class="scroll"><table><thead><tr><th>Land</th><th>Chrome</th><th>Unknown</th><th>Chrome-Anteil</th><th>Zusammen</th></tr></thead><tbody>
        <?php foreach ($visibleCountryBrowserComparison as $row): ?>
          <tr><td><?= comp_h(comp_country_label($row['country'])) ?></td><td class="number"><?= comp_number($row['Chrome']) ?></td><td class="number"><?= comp_number($row['Unknown']) ?></td><td class="number"><?= comp_h(comp_percent((int)$row['Chrome'], (int)$row['sessions'])) ?></td><td class="number"><?= comp_number($row['sessions']) ?></td></tr>
        <?php endforeach; ?>
        <?php if ($countryBrowserComparison === []): ?><tr><td colspan="5" class="empty">Keine Chrome- oder Unknown-Daten vorhanden.</td></tr><?php endif; ?>
      </tbody></table></div>
    </section>
  </main>
</body>
</html>
