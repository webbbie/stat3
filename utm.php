<?php
declare(strict_types=1);

require_once __DIR__ . '/pixl_server.php';
require_once __DIR__ . '/stat4/db.php';

pixl_require_stats_auth();

const UTM_TOP_LIMIT = 25;
const UTM_RECENT_LIMIT = 100;
const UTM_MACRO_DEFINITIONS = [
    'campaign' => ['label' => 'Campaign ID', 'category' => 'IDs & Attribution', 'aliases' => ['campaign_id', 'cid', 'campaign']],
    'banner' => ['label' => 'Creative ID', 'category' => 'IDs & Attribution', 'aliases' => ['creative_id', 'creative', 'banner', 'ad']],
    'ad_id' => ['label' => 'Ad ID', 'category' => 'IDs & Attribution', 'aliases' => ['ad_id', 'adid']],
    'advertiser' => ['label' => 'Advertiser ID', 'category' => 'IDs & Attribution', 'aliases' => ['advertiser', 'advertiser_id']],
    'click_id' => ['label' => 'Click ID', 'category' => 'IDs & Attribution', 'aliases' => ['click_id', 'pm_clid']],
    'traffic_source' => ['label' => 'Publisher-Domain', 'category' => 'IDs & Attribution', 'aliases' => ['traffic_source', 'network', 'source']],
    'tracking_id' => ['label' => 'Tracking ID', 'category' => 'IDs & Attribution', 'aliases' => ['tracking_id', 'trackingid', 'tid', 'clickid']],
    'timestamp' => ['label' => 'Event Timestamp', 'category' => 'IDs & Attribution', 'aliases' => ['timestamp', 'event_timestamp']],
    'cost' => ['label' => 'Kostenwert (USD)', 'category' => 'IDs & Attribution', 'aliases' => ['cost', 'value']],
    'ssp' => ['label' => 'Traffic Source', 'category' => 'Traffic Source', 'aliases' => ['ssp']],
    'ssp_id' => ['label' => 'Traffic Source ID', 'category' => 'Traffic Source', 'aliases' => ['ssp_id']],
    'sub_ssp' => ['label' => 'Sub-Source / Sub-ID', 'category' => 'Traffic Source', 'aliases' => ['sub_ssp', 'subid']],
    'pub' => ['label' => 'Publisher ID', 'category' => 'Traffic Source', 'aliases' => ['pub', 'publisher_id']],
    'site_id' => ['label' => 'Site ID', 'category' => 'Traffic Source', 'aliases' => ['site_id']],
    'referrer_macro' => ['label' => 'Referrer Page', 'category' => 'Traffic Source', 'aliases' => ['ppc_referrer', 'macro_referrer', 'referrer', 'ref']],
    'referrer_domain' => ['label' => 'Referrer Domain', 'category' => 'Traffic Source', 'aliases' => ['referrer_domain', 'refdomain']],
    'country_macro' => ['label' => 'Country ISO3', 'category' => 'Device & Geo', 'aliases' => ['macro_country', 'country_iso3', 'country']],
    'ad_width' => ['label' => 'Creative Width', 'category' => 'Device & Geo', 'aliases' => ['ad_width']],
    'ad_height' => ['label' => 'Creative Height', 'category' => 'Device & Geo', 'aliases' => ['ad_height']],
    'click_url' => ['label' => 'Click Event URL', 'category' => 'URLs & Tracking', 'aliases' => ['click_url']],
    'click_url_enc' => ['label' => 'Click Event URL encoded', 'category' => 'URLs & Tracking', 'aliases' => ['click_url_enc']],
    'imp_url' => ['label' => 'Impression Event URL', 'category' => 'URLs & Tracking', 'aliases' => ['imp_url']],
    'imp_pixel' => ['label' => 'Impression Pixel', 'category' => 'URLs & Tracking', 'aliases' => ['imp_pixel', 'pixel']],
    'landing_page' => ['label' => 'Landing Page URL', 'category' => 'URLs & Tracking', 'aliases' => ['landing_page']],
    'landing_page_enc' => ['label' => 'Landing Page URL encoded', 'category' => 'URLs & Tracking', 'aliases' => ['landing_page_enc']],
    'third_url' => ['label' => 'Third-party Tracking URL', 'category' => 'URLs & Tracking', 'aliases' => ['third_url', 'third_party']],
    'native_title' => ['label' => 'Native Title', 'category' => 'Native & Video', 'aliases' => ['native_title', 'ad_title']],
    'description' => ['label' => 'Native Description', 'category' => 'Native & Video', 'aliases' => ['description', 'ad_description']],
    'vast_start' => ['label' => 'VAST Start', 'category' => 'Native & Video', 'aliases' => ['vast_start']],
    'vast_first_quartile' => ['label' => 'VAST First Quartile', 'category' => 'Native & Video', 'aliases' => ['vast_first_quartile']],
    'vast_midpoint' => ['label' => 'VAST Midpoint', 'category' => 'Native & Video', 'aliases' => ['vast_midpoint']],
    'vast_third_quartile' => ['label' => 'VAST Third Quartile', 'category' => 'Native & Video', 'aliases' => ['vast_third_quartile']],
    'vast_complete' => ['label' => 'VAST Complete', 'category' => 'Native & Video', 'aliases' => ['vast_complete']],
    'vast_version' => ['label' => 'VAST Version', 'category' => 'Native & Video', 'aliases' => ['vast_version']],
];

function utm_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function utm_number($value, int $decimals = 0): string
{
    return number_format((float)$value, $decimals, ',', '.');
}

function utm_percent($part, $total): string
{
    return (float)$total > 0
        ? utm_number((float)$part / (float)$total * 100, 1) . '%'
        : '0,0%';
}

function utm_seconds($seconds): string
{
    $seconds = max(0, (int)$seconds);
    if ($seconds < 60) return $seconds . ' s';
    $minutes = intdiv($seconds, 60);
    $rest = $seconds % 60;
    return $minutes . ':' . str_pad((string)$rest, 2, '0', STR_PAD_LEFT) . ' min';
}

function utm_label($value, string $fallback = 'Nicht gesetzt'): string
{
    $value = trim((string)$value);
    return $value !== '' ? $value : $fallback;
}

function utm_query_params(string $urlOrPath): array
{
    $query = parse_url($urlOrPath, PHP_URL_QUERY);
    if (!is_string($query) || $query === '') return [];
    $values = [];
    parse_str(html_entity_decode($query, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $values);
    return is_array($values) ? $values : [];
}

function utm_param(array $params, array $aliases): string
{
    foreach ($aliases as $alias) {
        $value = $params[$alias] ?? '';
        if (is_scalar($value) && trim((string)$value) !== '') {
            return mb_substr(trim((string)$value), 0, 2048);
        }
    }
    return '';
}

function utm_query_value(string $urlOrPath, string $key): string
{
    $value = utm_query_params($urlOrPath)[$key] ?? '';
    return is_scalar($value) ? mb_substr(trim((string)$value), 0, 512) : '';
}

function utm_cost_value(string $value): ?float
{
    $value = trim(str_replace(',', '.', $value));
    if ($value === '' || !is_numeric($value)) return null;
    $cost = (float)$value;
    return is_finite($cost) && $cost >= 0 ? $cost : null;
}

function utm_landing_path(string $urlOrPath): string
{
    $path = parse_url($urlOrPath, PHP_URL_PATH);
    return is_string($path) && $path !== '' ? $path : '/';
}

function utm_country(string $code): string
{
    $code = strtoupper(trim($code));
    if ($code === '') return 'Unbekannt';
    if (preg_match('/^[A-Z]{2}$/', $code) && class_exists('Locale')) {
        $name = trim((string)Locale::getDisplayRegion('und_' . $code, 'de'));
        if ($name !== '' && strtoupper($name) !== $code && strcasecmp($name, 'Unbekannte Region') !== 0) return $name;
    }
    $fallback = pixl_notification_country_name($code);
    return $fallback !== '' ? $fallback : $code;
}

function utm_add(array &$groups, string $key, array $row): void
{
    if (!isset($groups[$key])) {
        $groups[$key] = [
            'label' => $key,
            'sessions' => 0,
            'visitors' => [],
            'pageviews' => 0,
            'clicks' => 0,
            'clickers' => 0,
            'bounces' => 0,
            'active_seconds' => 0,
            'max_level_sum' => 0,
            'click_ids' => [],
        ];
    }
    $groups[$key]['sessions']++;
    if ($row['visitor_hash'] !== '') $groups[$key]['visitors'][$row['visitor_hash']] = true;
    $groups[$key]['pageviews'] += $row['pageviews'];
    $groups[$key]['clicks'] += $row['clicks'];
    $groups[$key]['clickers'] += $row['clicks'] > 0 ? 1 : 0;
    $groups[$key]['bounces'] += $row['is_bounce'] ? 1 : 0;
    $groups[$key]['active_seconds'] += $row['active_seconds'];
    $groups[$key]['max_level_sum'] += $row['max_level'];
    if ($row['pm_clid'] !== '') $groups[$key]['click_ids'][$row['pm_clid']] = true;
}

function utm_finish_groups(array $groups): array
{
    foreach ($groups as &$group) {
        $group['visitors'] = count($group['visitors']);
        $group['click_ids'] = count($group['click_ids']);
        $sessions = max(1, (int)$group['sessions']);
        $group['pages_per_session'] = $group['pageviews'] / $sessions;
        $group['avg_active'] = $group['active_seconds'] / $sessions;
        $group['avg_level'] = $group['max_level_sum'] / $sessions;
    }
    unset($group);
    usort($groups, static function (array $a, array $b): int {
        return ($b['sessions'] <=> $a['sessions']) ?: strcmp($a['label'], $b['label']);
    });
    return $groups;
}

function utm_top(array $rows, int $limit = UTM_TOP_LIMIT): array
{
    return array_slice($rows, 0, $limit);
}

function utm_query(array $changes = []): string
{
    global $days, $timeRange, $scope, $search;
    $params = ['days' => $days, 'range' => $timeRange, 'scope' => $scope];
    if ($search !== '') $params['q'] = $search;
    foreach ($changes as $key => $value) {
        if ($value === null || $value === '') unset($params[$key]);
        else $params[$key] = $value;
    }
    return '?' . http_build_query($params);
}

$days = max(1, min(365, (int)($_GET['days'] ?? 30)));
$timeFilter = pixl_stats_time_filter($_GET['range'] ?? '', $days);
$timeRange = $timeFilter['range'];
$scope = ($_GET['scope'] ?? 'all') === 'ppcmate' ? 'ppcmate' : 'all';
$search = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 120);
$error = '';
$rows = [];
$allUtmSessions = 0;

try {
    $pdo = stat4_db();
    $statement = $pdo->prepare(
        "SELECT s.session_id, s.visitor_hash, s.started_at, s.last_seen, s.pageviews, s.clicks,
                s.active_seconds, s.max_level, s.is_bounce, s.is_bot, s.referrer,
                s.utm_source, s.utm_medium, s.utm_campaign, s.utm_term, s.utm_content,
                COALESCE(landing.path, '') AS landing_url,
                COALESCE(landing.hostname, '') AS landing_host,
                COALESCE(landing.country, '') AS country,
                COALESCE(landing.browser, '') AS browser,
                COALESCE(landing.device, '') AS device,
                COALESCE(landing.language, '') AS language
         FROM stat4_sessions s
         LEFT JOIN (
             SELECT e.session_id, e.path, e.hostname, e.country, e.browser, e.device, e.language
             FROM stat4_events e
             INNER JOIN (
                 SELECT session_id, MIN(id) AS first_id
                 FROM stat4_events
                 WHERE event_type = 'pageview' AND occurred_at >= :event_since
                 GROUP BY session_id
             ) first_page ON first_page.first_id = e.id
         ) landing ON landing.session_id = s.session_id
         WHERE s.started_at >= :since
           AND s.is_bot = 0
           AND (s.utm_source <> '' OR s.utm_medium <> '' OR s.utm_campaign <> '' OR s.utm_term <> '' OR s.utm_content <> '' OR landing.path LIKE '%pm_clid=%' OR landing.path LIKE '%utm_id=%' OR landing.path LIKE '%tracking_id=%' OR landing.path LIKE '%trackingId=%' OR landing.path LIKE '%clickid=%')
         ORDER BY s.started_at DESC"
    );
    $statement->execute([':since' => $timeFilter['since'], ':event_since' => $timeFilter['since']]);
    while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
        $allUtmSessions++;
        $landingParams = utm_query_params((string)$row['landing_url']);
        $row['utm_id'] = utm_param($landingParams, ['utm_id']);
        $row['pm_clid'] = utm_param($landingParams, ['pm_clid', 'click_id']);
        $row['landing_path'] = utm_landing_path((string)$row['landing_url']);
        $row['pageviews'] = (int)$row['pageviews'];
        $row['clicks'] = (int)$row['clicks'];
        $row['active_seconds'] = (int)$row['active_seconds'];
        $row['max_level'] = (int)$row['max_level'];
        $row['is_bounce'] = (int)$row['is_bounce'] === 1;
        $row['macros'] = [];
        foreach (UTM_MACRO_DEFINITIONS as $macroKey => $definition) {
            $row['macros'][$macroKey] = utm_param($landingParams, $definition['aliases']);
        }
        // Bereits strukturiert gespeicherte UTM-Werte sind die verlaessliche
        // Rueckfallebene fuer die gleichbedeutenden PPCMate-Makros.
        $row['macros']['campaign'] = $row['macros']['campaign'] !== '' ? $row['macros']['campaign'] : $row['utm_id'];
        $row['macros']['banner'] = $row['macros']['banner'] !== '' ? $row['macros']['banner'] : (string)$row['utm_content'];
        $row['macros']['click_id'] = $row['macros']['click_id'] !== '' ? $row['macros']['click_id'] : $row['pm_clid'];
        $row['macros']['traffic_source'] = $row['macros']['traffic_source'] !== '' ? $row['macros']['traffic_source'] : (string)$row['utm_source'];

        $isPpcmate = strcasecmp((string)$row['utm_medium'], 'cpc') === 0
            || stripos((string)$row['utm_campaign'], 'ppcmate-') === 0
            || $row['pm_clid'] !== ''
            || $row['macros']['tracking_id'] !== '';
        if ($scope === 'ppcmate' && !$isPpcmate) continue;

        if ($search !== '') {
            $haystack = implode("\n", [
                $row['utm_source'], $row['utm_medium'], $row['utm_campaign'], $row['utm_id'],
                $row['utm_content'], $row['pm_clid'], $row['landing_host'], $row['landing_path'],
                $row['country'], $row['browser'], $row['device'], $row['referrer'],
                implode("\n", $row['macros']),
            ]);
            if (mb_stripos($haystack, $search) === false) continue;
        }
        $rows[] = $row;
    }
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}

$totals = [
    'sessions' => count($rows), 'visitors' => [], 'pageviews' => 0, 'clicks' => 0,
    'clickers' => 0, 'bounces' => 0, 'active_seconds' => 0, 'max_level_sum' => 0,
    'campaigns' => [], 'sources' => [], 'banners' => [], 'click_ids' => [], 'utm_ids' => [],
];
$groups = [
    'campaigns' => [], 'sources' => [], 'mediums' => [], 'banners' => [], 'utm_ids' => [],
    'click_ids' => [], 'landings' => [], 'countries' => [], 'browsers' => [], 'devices' => [],
    'languages' => [], 'referrers' => [], 'days' => [], 'hours' => [],
];
$campaignDetails = [];
$quality = ['complete' => 0, 'missing_source' => 0, 'missing_campaign' => 0, 'missing_id' => 0, 'missing_banner' => 0, 'missing_click_id' => 0, 'duplicate_click_id_sessions' => 0];
$clickIdFrequency = [];
$macroGroups = [];
$macroCoverage = [];
$costSummary = ['sessions' => 0, 'total' => 0.0, 'min' => null, 'max' => null];
foreach (UTM_MACRO_DEFINITIONS as $macroKey => $definition) {
    $macroGroups[$macroKey] = [];
    $macroCoverage[$macroKey] = ['present' => 0, 'unique' => [], 'category' => $definition['category'], 'label' => $definition['label']];
}

foreach ($rows as $row) {
    $source = utm_label($row['utm_source']);
    $medium = utm_label($row['utm_medium']);
    $campaign = utm_label($row['utm_campaign']);
    $utmId = utm_label($row['utm_id']);
    $banner = utm_label($row['utm_content']);
    $clickId = utm_label($row['pm_clid']);
    $landing = utm_label($row['landing_host'], '') . $row['landing_path'];
    $referrerHost = parse_url((string)$row['referrer'], PHP_URL_HOST);
    $referrer = is_string($referrerHost) && $referrerHost !== '' ? strtolower($referrerHost) : 'Direkt / unbekannt';
    $day = substr((string)$row['started_at'], 0, 10);
    $hour = substr((string)$row['started_at'], 11, 2) . ':00';

    if ($row['visitor_hash'] !== '') $totals['visitors'][$row['visitor_hash']] = true;
    $totals['pageviews'] += $row['pageviews'];
    $totals['clicks'] += $row['clicks'];
    $totals['clickers'] += $row['clicks'] > 0 ? 1 : 0;
    $totals['bounces'] += $row['is_bounce'] ? 1 : 0;
    $totals['active_seconds'] += $row['active_seconds'];
    $totals['max_level_sum'] += $row['max_level'];
    if ($row['utm_campaign'] !== '') $totals['campaigns'][$row['utm_campaign']] = true;
    if ($row['utm_source'] !== '') $totals['sources'][$row['utm_source']] = true;
    if ($row['utm_content'] !== '') $totals['banners'][$row['utm_content']] = true;
    if ($row['pm_clid'] !== '') {
        $totals['click_ids'][$row['pm_clid']] = true;
        $clickIdFrequency[$row['pm_clid']] = ($clickIdFrequency[$row['pm_clid']] ?? 0) + 1;
    }
    if ($row['utm_id'] !== '') $totals['utm_ids'][$row['utm_id']] = true;

    utm_add($groups['campaigns'], $campaign, $row);
    utm_add($groups['sources'], $source, $row);
    utm_add($groups['mediums'], $medium, $row);
    utm_add($groups['banners'], $banner, $row);
    utm_add($groups['utm_ids'], $utmId, $row);
    utm_add($groups['click_ids'], $clickId, $row);
    utm_add($groups['landings'], $landing, $row);
    utm_add($groups['countries'], utm_country((string)$row['country']), $row);
    utm_add($groups['browsers'], utm_label($row['browser'], 'Unbekannt'), $row);
    utm_add($groups['devices'], utm_label($row['device'], 'Unbekannt'), $row);
    utm_add($groups['languages'], utm_label($row['language'], 'Unbekannt'), $row);
    utm_add($groups['referrers'], $referrer, $row);
    utm_add($groups['days'], $day, $row);
    utm_add($groups['hours'], $hour, $row);

    foreach (UTM_MACRO_DEFINITIONS as $macroKey => $definition) {
        $macroValue = (string)($row['macros'][$macroKey] ?? '');
        if ($macroValue === '') continue;
        $macroCoverage[$macroKey]['present']++;
        $macroCoverage[$macroKey]['unique'][$macroValue] = true;
        utm_add($macroGroups[$macroKey], $macroValue, $row);
    }
    $cost = utm_cost_value((string)($row['macros']['cost'] ?? ''));
    if ($cost !== null) {
        $costSummary['sessions']++;
        $costSummary['total'] += $cost;
        $costSummary['min'] = $costSummary['min'] === null ? $cost : min($costSummary['min'], $cost);
        $costSummary['max'] = $costSummary['max'] === null ? $cost : max($costSummary['max'], $cost);
    }

    $campaignKey = $campaign . "\x1f" . $utmId;
    utm_add($campaignDetails, $campaignKey, $row);
    $campaignDetails[$campaignKey]['label'] = $campaign;
    $campaignDetails[$campaignKey]['utm_id'] = $utmId;
    $campaignDetails[$campaignKey]['sources'] ??= [];
    $campaignDetails[$campaignKey]['banners'] ??= [];
    $campaignDetails[$campaignKey]['sources'][$source] = true;
    $campaignDetails[$campaignKey]['banners'][$banner] = true;

    $missing = 0;
    foreach (['utm_source' => 'missing_source', 'utm_campaign' => 'missing_campaign', 'utm_id' => 'missing_id', 'utm_content' => 'missing_banner', 'pm_clid' => 'missing_click_id'] as $field => $qualityKey) {
        if ($row[$field] === '') { $quality[$qualityKey]++; $missing++; }
    }
    if ($missing === 0 && strcasecmp((string)$row['utm_medium'], 'cpc') === 0) $quality['complete']++;
}

foreach ($clickIdFrequency as $frequency) {
    if ($frequency > 1) $quality['duplicate_click_id_sessions'] += $frequency;
}

$totals['visitors'] = count($totals['visitors']);
foreach (['campaigns', 'sources', 'banners', 'click_ids', 'utm_ids'] as $key) $totals[$key] = count($totals[$key]);
foreach ($groups as $key => $group) $groups[$key] = utm_finish_groups($group);
foreach ($macroGroups as $key => $group) $macroGroups[$key] = utm_finish_groups($group);
foreach ($macroCoverage as &$coverage) $coverage['unique'] = count($coverage['unique']);
unset($coverage);
$campaignDetails = utm_finish_groups($campaignDetails);
usort($groups['days'], static fn(array $a, array $b): int => strcmp($a['label'], $b['label']));
usort($groups['hours'], static fn(array $a, array $b): int => strcmp($a['label'], $b['label']));

$sessions = max(1, $totals['sessions']);
$maxTrend = 1;
foreach ($groups['days'] as $dayRow) $maxTrend = max($maxTrend, $dayRow['sessions']);

function utm_breakdown_table(array $rows, string $firstHeading): void
{
    ?>
    <div class="scroll"><table><thead><tr><th><?= utm_h($firstHeading) ?></th><th>Sitzungen</th><th>Besucher</th><th>Klicker</th><th>Klicks</th><th>Seiten</th><th>Bounce</th><th>Ø aktiv</th><th>Ø Scroll</th></tr></thead><tbody>
    <?php foreach (utm_top($rows) as $row): ?>
      <tr><td class="main-cell"><?= utm_h($row['label']) ?></td><td class="num"><?= utm_number($row['sessions']) ?></td><td class="num"><?= utm_number($row['visitors']) ?></td><td class="num"><?= utm_percent($row['clickers'], $row['sessions']) ?></td><td class="num"><?= utm_number($row['clicks']) ?></td><td class="num"><?= utm_number($row['pages_per_session'], 2) ?></td><td class="num"><?= utm_percent($row['bounces'], $row['sessions']) ?></td><td class="num"><?= utm_seconds(round($row['avg_active'])) ?></td><td class="num"><?= utm_number($row['avg_level'], 1) ?>%</td></tr>
    <?php endforeach; ?>
    <?php if ($rows === []): ?><tr><td class="empty" colspan="9">Keine Daten im gewählten Bereich.</td></tr><?php endif; ?>
    </tbody></table></div>
    <?php if (count($rows) > UTM_TOP_LIMIT): ?><p class="table-note">Top <?= UTM_TOP_LIMIT ?> von <?= utm_number(count($rows)) ?> Werten.</p><?php endif; ?>
    <?php
}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light dark">
  <title>UTM & PPCMate Statistik</title>
  <style>
    :root{color-scheme:light dark;--bg:#eef2f4;--surface:#fff;--alt:#f6f9fa;--ink:#172126;--muted:#68777e;--line:#d5dfe3;--accent:#087f73;--accent2:#3568a8;--soft:#e2f4f0;--warn:#a76300;--warnsoft:#fff2d8;--danger:#a43f4e;--dangersoft:#f9e8eb}
    @media(prefers-color-scheme:dark){:root{--bg:#111719;--surface:#1c2327;--alt:#171d21;--ink:#edf3f4;--muted:#a6b2b7;--line:#354147;--accent:#54c8b8;--accent2:#82aee3;--soft:#203b37;--warn:#ffc46b;--warnsoft:#3b3020;--danger:#e497a3;--dangersoft:#42282e}}
    *{box-sizing:border-box}html{scroll-behavior:smooth}body{min-width:320px;margin:0;background:var(--bg);color:var(--ink);font:14px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}a{color:var(--accent);text-underline-offset:3px}h1,h2,h3,p{margin:0}.topbar{position:sticky;top:0;z-index:20;border-bottom:1px solid var(--line);background:color-mix(in srgb,var(--surface) 94%,transparent);backdrop-filter:blur(12px)}.topbar-inner,main{width:min(1580px,100%);margin:auto;padding-inline:20px}.topbar-inner{min-height:72px;display:flex;align-items:center;gap:18px;padding-block:10px}.title{flex:1 1 auto;min-width:230px}h1{font-size:26px;line-height:1.1}.subtitle{margin-top:4px;color:var(--muted);font-size:12px}.controls{display:flex;align-items:center;gap:7px;overflow-x:auto;white-space:nowrap}.button,.controls button,.controls input{min-height:36px;border:1px solid var(--line);border-radius:7px;padding:7px 10px;background:var(--alt);color:var(--ink);font:inherit;font-weight:750;text-decoration:none}.button.active,.controls button{border-color:var(--accent);background:var(--accent);color:#fff}.controls input{width:180px;font-weight:500}.controls form{display:flex;gap:6px}main{padding-block:22px 50px}.notice{margin-bottom:16px;border:1px solid var(--danger);border-radius:8px;padding:13px 15px;background:var(--dangersoft);color:var(--danger)}.formula{margin-bottom:18px;border:1px solid var(--line);border-radius:8px;padding:12px 15px;background:var(--surface);color:var(--muted);overflow-wrap:anywhere}.formula code{color:var(--ink)}.metrics{display:grid;grid-template-columns:repeat(8,minmax(0,1fr));gap:10px}.metric,section{border:1px solid var(--line);border-radius:9px;background:var(--surface)}.metric{min-width:0;padding:14px}.metric span{display:block;color:var(--muted);font-size:10px;font-weight:850;letter-spacing:.05em;text-transform:uppercase}.metric strong{display:block;margin-top:7px;font-size:24px;line-height:1.1;font-variant-numeric:tabular-nums;overflow-wrap:anywhere}.metric small{display:block;margin-top:6px;color:var(--muted)}.metric.accent{border-top:3px solid var(--accent)}.metric.blue{border-top:3px solid var(--accent2)}section{margin-top:20px;overflow:hidden}.section-head{display:flex;align-items:flex-end;justify-content:space-between;gap:15px;padding:14px 16px;border-bottom:1px solid var(--line);background:var(--alt)}h2{font-size:18px}.section-head p{margin-top:3px;color:var(--muted);font-size:12px}.section-tag{color:var(--muted);font-size:11px;font-weight:800;text-transform:uppercase}.two-col{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px}.two-col section{min-width:0}.scroll{overflow:auto}table{width:100%;border-collapse:collapse;font-size:13px}th,td{padding:9px 12px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}th{position:sticky;top:0;color:var(--muted);background:var(--alt);font-size:10px;text-transform:uppercase;white-space:nowrap}tbody tr:last-child td{border-bottom:0}tbody tr:hover td{background:var(--soft)}td.num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}.main-cell{max-width:360px;font-weight:700;overflow-wrap:anywhere}.code{max-width:340px;font:12px/1.35 ui-monospace,SFMono-Regular,Menlo,monospace;overflow-wrap:anywhere}.empty{padding:18px;color:var(--muted)}.table-note{padding:9px 12px;border-top:1px solid var(--line);background:var(--alt);color:var(--muted);font-size:12px}.campaign-table{max-height:680px}.quality-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;padding:12px}.quality{border:1px solid var(--line);border-radius:7px;padding:12px;background:var(--alt)}.quality span{display:block;color:var(--muted);font-size:11px}.quality strong{display:block;margin-top:5px;font-size:20px}.quality.good strong{color:var(--accent)}.quality.warn strong{color:var(--warn)}.trend{display:flex;align-items:flex-end;gap:5px;height:230px;padding:20px 15px 36px;overflow-x:auto}.trend-item{position:relative;flex:1 0 34px;height:100%;display:flex;align-items:flex-end}.trend-bar{width:100%;min-height:2px;border-radius:4px 4px 0 0;background:linear-gradient(180deg,var(--accent2),var(--accent))}.trend-item span{position:absolute;bottom:-23px;left:50%;transform:translateX(-50%) rotate(-35deg);transform-origin:center;white-space:nowrap;color:var(--muted);font-size:9px}.trend-item b{position:absolute;top:-18px;left:50%;transform:translateX(-50%);font-size:10px}.recent{max-height:720px}.pill{display:inline-block;border-radius:99px;padding:2px 7px;background:var(--soft);color:var(--accent);font-size:11px;font-weight:750}.muted{color:var(--muted)}.macro-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;padding:12px}.macro-card{border:1px solid var(--line);border-radius:8px;background:var(--alt);overflow:hidden}.macro-card summary{display:grid;grid-template-columns:1fr auto;gap:8px;padding:12px;cursor:pointer;list-style:none}.macro-card summary::-webkit-details-marker{display:none}.macro-card summary b{overflow-wrap:anywhere}.macro-card summary span{color:var(--muted);font-variant-numeric:tabular-nums}.macro-card .scroll{max-height:330px;border-top:1px solid var(--line)}.macro-category{grid-column:1/-1;padding:5px 2px 0;color:var(--muted);font-size:11px;font-weight:850;text-transform:uppercase}.privacy-note{padding:12px 15px;border-top:1px solid var(--line);background:var(--warnsoft);color:var(--warn)}
    @media(max-width:1200px){.metrics{grid-template-columns:repeat(4,minmax(0,1fr))}.quality-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.macro-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media(max-width:800px){.topbar-inner{align-items:flex-start;flex-direction:column}.controls{width:100%}.two-col{grid-template-columns:1fr}.metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.topbar-inner,main{padding-inline:12px}.quality-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.macro-grid{grid-template-columns:1fr}}
  </style>
</head>
<body>
<header class="topbar"><div class="topbar-inner">
  <div class="title"><h1>UTM & PPCMate</h1><p class="subtitle"><?= utm_h($timeFilter['label']) ?> · <?= $scope === 'ppcmate' ? 'PPCMate / CPC' : 'alle UTM-Sitzungen' ?> · Bots ausgeschlossen<?= $search !== '' ? ' · Suche: ' . utm_h($search) : '' ?></p></div>
  <nav class="controls" aria-label="Filter">
    <a class="button <?= $timeRange === '1d' ? 'active' : '' ?>" href="<?= utm_h(utm_query(['range'=>'1d'])) ?>">Heute</a>
    <a class="button <?= $timeRange === '24h' ? 'active' : '' ?>" href="<?= utm_h(utm_query(['range'=>'24h'])) ?>">24 h</a>
    <a class="button <?= $timeRange === 'last7d' ? 'active' : '' ?>" href="<?= utm_h(utm_query(['range'=>'last7d'])) ?>">7 Tage</a>
    <a class="button <?= $timeRange === '' && $days === 30 ? 'active' : '' ?>" href="<?= utm_h(utm_query(['range'=>null,'days'=>30])) ?>">30 Tage</a>
    <a class="button <?= $timeRange === '' && $days === 90 ? 'active' : '' ?>" href="<?= utm_h(utm_query(['range'=>null,'days'=>90])) ?>">90 Tage</a>
    <a class="button <?= $scope === 'ppcmate' ? 'active' : '' ?>" href="<?= utm_h(utm_query(['scope'=>$scope === 'ppcmate' ? 'all' : 'ppcmate'])) ?>"><?= $scope === 'ppcmate' ? 'PPCMate aktiv' : 'Alle UTM' ?></a>
    <form method="get"><input type="hidden" name="days" value="<?= $days ?>"><input type="hidden" name="range" value="<?= utm_h($timeRange) ?>"><input type="hidden" name="scope" value="<?= utm_h($scope) ?>"><input name="q" value="<?= utm_h($search) ?>" placeholder="Kampagne, Banner, Click-ID …" aria-label="UTM-Daten durchsuchen"><button type="submit">Suchen</button></form>
    <a class="button" href="stats.php">Gesamtstatistik</a>
  </nav>
</div></header>

<main>
  <?php if ($error !== ''): ?><div class="notice"><strong>Datenbankfehler:</strong> <?= utm_h($error) ?></div><?php endif; ?>
  <p class="formula"><strong>Empfohlener PPCMate-Attributionslink:</strong><br><code>?utm_source={TRAFFIC_SOURCE}&amp;utm_medium=cpc&amp;utm_campaign=ppcmate-{CAMPAIGN}&amp;utm_id={CAMPAIGN}&amp;campaign_id={CAMPAIGN}&amp;utm_content={BANNER}&amp;creative_id={BANNER}&amp;pm_clid={CLICK_ID}&amp;tracking_id={TRACKING_ID}&amp;ad_id={AD_ID}&amp;advertiser={ADVERTISER}&amp;timestamp={TIMESTAMP}&amp;cost={COST}&amp;ssp={SSP}&amp;ssp_id={SSP_ID}&amp;sub_ssp={SUB_SSP}&amp;pub={PUB}&amp;site_id={SITE_ID}&amp;referrer_domain={REFERRER_DOMAIN}&amp;country_iso3={COUNTRY}&amp;ad_width={AD_WIDTH}&amp;ad_height={AD_HEIGHT}</code><br><span><code>campaign_id</code> und <code>creative_id</code> halten die Auswertung kompatibel mit <code>stat/impression.php</code>. Bei Binom, Voluum, BeMob oder Keitaro kann alternativ <code>&amp;clickid={TRACKING_ID}</code> verwendet werden. Die zusätzlichen Makros werden aus der ersten Landing-URL gelesen.</span></p>

  <div class="metrics" aria-label="UTM Kennzahlen">
    <article class="metric accent"><span>Sitzungen</span><strong><?= utm_number($totals['sessions']) ?></strong><small><?= utm_number($allUtmSessions) ?> UTM gesamt vor Filtern</small></article>
    <article class="metric accent"><span>Besucher</span><strong><?= utm_number($totals['visitors']) ?></strong><small><?= utm_percent($totals['visitors'], $totals['sessions']) ?> eindeutig</small></article>
    <article class="metric blue"><span>Klicker</span><strong><?= utm_number($totals['clickers']) ?></strong><small><?= utm_percent($totals['clickers'], $totals['sessions']) ?> der Sitzungen</small></article>
    <article class="metric blue"><span>Klicks</span><strong><?= utm_number($totals['clicks']) ?></strong><small><?= utm_number($totals['clicks'] / $sessions, 2) ?> je Sitzung</small></article>
    <article class="metric"><span>Seitenaufrufe</span><strong><?= utm_number($totals['pageviews']) ?></strong><small><?= utm_number($totals['pageviews'] / $sessions, 2) ?> je Sitzung</small></article>
    <article class="metric"><span>Bounce Rate</span><strong><?= utm_percent($totals['bounces'], $totals['sessions']) ?></strong><small><?= utm_number($totals['bounces']) ?> Bounces</small></article>
    <article class="metric"><span>Ø Aktivzeit</span><strong><?= utm_seconds(round($totals['active_seconds'] / $sessions)) ?></strong><small><?= utm_seconds($totals['active_seconds']) ?> gesamt</small></article>
    <article class="metric"><span>Ø Scrolltiefe</span><strong><?= utm_number($totals['max_level_sum'] / $sessions, 1) ?>%</strong><small>Maximalwert je Sitzung</small></article>
    <article class="metric"><span>Kampagnen</span><strong><?= utm_number($totals['campaigns']) ?></strong><small>unterschiedliche Namen</small></article>
    <article class="metric"><span>Campaign IDs</span><strong><?= utm_number($totals['utm_ids']) ?></strong><small>aus utm_id</small></article>
    <article class="metric"><span>Quellen</span><strong><?= utm_number($totals['sources']) ?></strong><small>utm_source</small></article>
    <article class="metric"><span>Banner</span><strong><?= utm_number($totals['banners']) ?></strong><small>utm_content</small></article>
    <article class="metric"><span>Click IDs</span><strong><?= utm_number($totals['click_ids']) ?></strong><small>eindeutige pm_clid</small></article>
    <article class="metric"><span>Click-ID Quote</span><strong><?= utm_percent($totals['sessions'] - $quality['missing_click_id'], $totals['sessions']) ?></strong><small>mit pm_clid</small></article>
    <article class="metric"><span>Vollständig</span><strong><?= utm_percent($quality['complete'], $totals['sessions']) ?></strong><small>alle 6 Zielparameter + cpc</small></article>
    <article class="metric"><span>Ø Seiten/Klicker</span><strong><?= $totals['clickers'] > 0 ? utm_number($totals['pageviews'] / $totals['clickers'], 2) : '0,00' ?></strong><small>Seiten je klickender Sitzung</small></article>
    <article class="metric blue"><span>Kostenwerte USD</span><strong><?= utm_number($costSummary['total'], 4) ?></strong><small>Summe übermittelter COST/VALUE</small></article>
    <article class="metric blue"><span>Ø Kostenwert USD</span><strong><?= $costSummary['sessions'] > 0 ? utm_number($costSummary['total'] / $costSummary['sessions'], 4) : '0,0000' ?></strong><small><?= utm_number($costSummary['sessions']) ?> Sitzungen mit Wert</small></article>
  </div>

  <section><div class="section-head"><div><h2>Kampagnen-Leistung</h2><p>Kampagnenname und Campaign-ID mit Engagement- und Klicksignalen.</p></div><span class="section-tag">Top <?= UTM_TOP_LIMIT ?></span></div>
    <div class="scroll campaign-table"><table><thead><tr><th>Kampagne</th><th>utm_id</th><th>Sitzungen</th><th>Besucher</th><th>Click IDs</th><th>Klicker</th><th>Klicks</th><th>Seiten/Sitzung</th><th>Bounce</th><th>Ø aktiv</th><th>Ø Scroll</th></tr></thead><tbody>
    <?php foreach (utm_top($campaignDetails) as $row): ?><tr><td class="main-cell"><?= utm_h($row['label']) ?></td><td class="code"><?= utm_h($row['utm_id']) ?></td><td class="num"><?= utm_number($row['sessions']) ?></td><td class="num"><?= utm_number($row['visitors']) ?></td><td class="num"><?= utm_number($row['click_ids']) ?></td><td class="num"><?= utm_percent($row['clickers'],$row['sessions']) ?></td><td class="num"><?= utm_number($row['clicks']) ?></td><td class="num"><?= utm_number($row['pages_per_session'],2) ?></td><td class="num"><?= utm_percent($row['bounces'],$row['sessions']) ?></td><td class="num"><?= utm_seconds(round($row['avg_active'])) ?></td><td class="num"><?= utm_number($row['avg_level'],1) ?>%</td></tr><?php endforeach; ?>
    <?php if ($campaignDetails === []): ?><tr><td class="empty" colspan="11">Keine passenden Kampagnen gefunden.</td></tr><?php endif; ?>
    </tbody></table></div>
  </section>

  <section><div class="section-head"><div><h2>Sitzungen im Zeitverlauf</h2><p>Tägliche Verteilung der gefilterten UTM-Sitzungen.</p></div><span class="section-tag"><?= utm_number(count($groups['days'])) ?> Tage</span></div>
    <?php if ($groups['days'] === []): ?><p class="empty">Keine Zeitreihendaten.</p><?php else: ?><div class="trend"><?php foreach ($groups['days'] as $row): ?><div class="trend-item"><b><?= utm_number($row['sessions']) ?></b><i class="trend-bar" style="height:<?= max(2, round($row['sessions']/$maxTrend*100)) ?>%"></i><span><?= utm_h(substr($row['label'],5)) ?></span></div><?php endforeach; ?></div><?php endif; ?>
  </section>

  <div class="two-col">
    <section><div class="section-head"><div><h2>Traffic Sources</h2><p>utm_source</p></div></div><?php utm_breakdown_table($groups['sources'], 'Quelle'); ?></section>
    <section><div class="section-head"><div><h2>Medien</h2><p>utm_medium, im PPCMate-Link normalerweise cpc</p></div></div><?php utm_breakdown_table($groups['mediums'], 'Medium'); ?></section>
    <section><div class="section-head"><div><h2>Banner / Creatives</h2><p>utm_content = BANNER</p></div></div><?php utm_breakdown_table($groups['banners'], 'Banner'); ?></section>
    <section><div class="section-head"><div><h2>Campaign IDs</h2><p>utm_id = CAMPAIGN aus der Landing-URL</p></div></div><?php utm_breakdown_table($groups['utm_ids'], 'utm_id'); ?></section>
    <section><div class="section-head"><div><h2>Landingpages</h2><p>Hostname + Pfad, Query-Parameter zusammengefasst</p></div></div><?php utm_breakdown_table($groups['landings'], 'Landingpage'); ?></section>
    <section><div class="section-head"><div><h2>Referrer</h2><p>Herkunfts-Hostname der Sitzung</p></div></div><?php utm_breakdown_table($groups['referrers'], 'Referrer'); ?></section>
    <section><div class="section-head"><div><h2>Länder</h2><p>Geo-IP-/Browser-Land des ersten Seitenaufrufs</p></div></div><?php utm_breakdown_table($groups['countries'], 'Land'); ?></section>
    <section><div class="section-head"><div><h2>Browser</h2><p>Browser des ersten Seitenaufrufs</p></div></div><?php utm_breakdown_table($groups['browsers'], 'Browser'); ?></section>
    <section><div class="section-head"><div><h2>Geräte</h2><p>Geräteklasse des ersten Seitenaufrufs</p></div></div><?php utm_breakdown_table($groups['devices'], 'Gerät'); ?></section>
    <section><div class="section-head"><div><h2>Sprachen</h2><p>Browsersprache des ersten Seitenaufrufs</p></div></div><?php utm_breakdown_table($groups['languages'], 'Sprache'); ?></section>
    <section><div class="section-head"><div><h2>Uhrzeiten</h2><p>Startstunde in der gespeicherten Datenbankzeit</p></div></div><?php utm_breakdown_table($groups['hours'], 'Stunde'); ?></section>
    <section><div class="section-head"><div><h2>Click IDs</h2><p>pm_clid; zur Diagnose vollständig angezeigt</p></div></div><?php utm_breakdown_table($groups['click_ids'], 'pm_clid'); ?></section>
  </div>

  <section><div class="section-head"><div><h2>Parameter-Qualität</h2><p>Fehlende oder mehrfach verwendete PPCMate-Parameter erkennen.</p></div><span class="section-tag"><?= utm_number($totals['sessions']) ?> Sitzungen geprüft</span></div>
    <div class="quality-grid">
      <article class="quality good"><span>Vollständig</span><strong><?= utm_number($quality['complete']) ?></strong><small><?= utm_percent($quality['complete'],$totals['sessions']) ?></small></article>
      <article class="quality warn"><span>utm_source fehlt</span><strong><?= utm_number($quality['missing_source']) ?></strong><small><?= utm_percent($quality['missing_source'],$totals['sessions']) ?></small></article>
      <article class="quality warn"><span>utm_campaign fehlt</span><strong><?= utm_number($quality['missing_campaign']) ?></strong><small><?= utm_percent($quality['missing_campaign'],$totals['sessions']) ?></small></article>
      <article class="quality warn"><span>utm_id fehlt</span><strong><?= utm_number($quality['missing_id']) ?></strong><small><?= utm_percent($quality['missing_id'],$totals['sessions']) ?></small></article>
      <article class="quality warn"><span>utm_content fehlt</span><strong><?= utm_number($quality['missing_banner']) ?></strong><small><?= utm_percent($quality['missing_banner'],$totals['sessions']) ?></small></article>
      <article class="quality warn"><span>pm_clid fehlt</span><strong><?= utm_number($quality['missing_click_id']) ?></strong><small><?= utm_percent($quality['missing_click_id'],$totals['sessions']) ?></small></article>
      <article class="quality warn"><span>Click-ID mehrfach</span><strong><?= utm_number($quality['duplicate_click_id_sessions']) ?></strong><small>Sitzungen mit wiederverwendeter ID</small></article>
    </div>
  </section>

  <section><div class="section-head"><div><h2>PPCMate Tracking Macros</h2><p>Abdeckung und Top-Werte aller übermittelten Makros. Karten aufklappen, um einzelne Werte zu vergleichen.</p></div><span class="section-tag"><?= utm_number(count(UTM_MACRO_DEFINITIONS)) ?> Makros</span></div>
    <div class="macro-grid">
      <?php $lastMacroCategory = ''; foreach (UTM_MACRO_DEFINITIONS as $macroKey => $definition): ?>
        <?php if ($definition['category'] !== $lastMacroCategory): $lastMacroCategory = $definition['category']; ?><h3 class="macro-category"><?= utm_h($lastMacroCategory) ?></h3><?php endif; ?>
        <?php $coverage = $macroCoverage[$macroKey]; ?>
        <details class="macro-card">
          <summary><b><?= utm_h($definition['label']) ?></b><span><?= utm_number($coverage['present']) ?> / <?= utm_number($totals['sessions']) ?> · <?= utm_percent($coverage['present'], $totals['sessions']) ?><br><?= utm_number($coverage['unique']) ?> Werte</span></summary>
          <div class="scroll"><table><thead><tr><th>Wert</th><th>Sitzungen</th><th>Anteil</th><th>Klicks</th><th>Bounce</th></tr></thead><tbody>
          <?php foreach (utm_top($macroGroups[$macroKey], 10) as $macroRow): ?><tr><td class="code"><?= utm_h($macroRow['label']) ?></td><td class="num"><?= utm_number($macroRow['sessions']) ?></td><td class="num"><?= utm_percent($macroRow['sessions'], $coverage['present']) ?></td><td class="num"><?= utm_number($macroRow['clicks']) ?></td><td class="num"><?= utm_percent($macroRow['bounces'], $macroRow['sessions']) ?></td></tr><?php endforeach; ?>
          <?php if ($macroGroups[$macroKey] === []): ?><tr><td colspan="5" class="empty">Noch nicht im Landing-Link übermittelt.</td></tr><?php endif; ?>
          </tbody></table></div>
        </details>
      <?php endforeach; ?>
    </div>
    <p class="privacy-note"><strong>Datenschutz und URL-Länge:</strong> Das Makro <code>{IP}</code> wird absichtlich weder als URL-Parameter empfohlen noch in dieser Statistik ausgegeben. Betriebssystem, Gerät und Land werden bereits datensparsam vom vorhandenen STAT4-Tracker erfasst. Lange Event-, Impression-, Landing- und VAST-URLs sollten nicht gesammelt an den Landing-Link gehängt werden, weil STAT4 den gespeicherten Pfad begrenzt. Für Impression-/Ad-Tracking ist die vorhandene <a href="stat/impression.php">Impression-Statistik</a> vorgesehen; hier erscheinen solche URL-Makros nur, wenn sie ausdrücklich im Landing-Link übertragen wurden.</p>
  </section>

  <section><div class="section-head"><div><h2>Letzte UTM-Sitzungen</h2><p>Detailprüfung der letzten <?= UTM_RECENT_LIMIT ?> gefilterten Sitzungen.</p></div><span class="section-tag">Neueste zuerst</span></div>
    <div class="scroll recent"><table><thead><tr><th>Start</th><th>Source / Medium</th><th>Kampagne</th><th>utm_id</th><th>Banner</th><th>pm_clid</th><th>Tracking ID</th><th>Ad / Advertiser</th><th>SSP / Publisher</th><th>Kostenwert</th><th>Landingpage</th><th>Land / Gerät</th><th>Seiten</th><th>Klicks</th><th>Aktiv</th><th>Scroll</th><th>Status</th></tr></thead><tbody>
      <?php foreach (array_slice($rows,0,UTM_RECENT_LIMIT) as $row): ?><tr>
        <td class="num"><?= utm_h($row['started_at']) ?></td><td><?= utm_h(utm_label($row['utm_source'])) ?><br><span class="muted"><?= utm_h(utm_label($row['utm_medium'])) ?></span></td><td class="main-cell"><?= utm_h(utm_label($row['utm_campaign'])) ?></td><td class="code"><?= utm_h(utm_label($row['utm_id'])) ?></td><td class="code"><?= utm_h(utm_label($row['utm_content'])) ?></td><td class="code"><?= utm_h(utm_label($row['pm_clid'])) ?></td><td class="code"><?= utm_h(utm_label($row['macros']['tracking_id'])) ?></td><td class="code"><?= utm_h(utm_label($row['macros']['ad_id'])) ?><br><span class="muted"><?= utm_h(utm_label($row['macros']['advertiser'])) ?></span></td><td class="code"><?= utm_h(utm_label($row['macros']['ssp'])) ?><br><span class="muted"><?= utm_h(utm_label($row['macros']['pub'])) ?></span></td><td class="num"><?= utm_h(utm_label($row['macros']['cost'])) ?></td><td class="main-cell"><?= utm_h($row['landing_host'].$row['landing_path']) ?></td><td><?= utm_h(utm_country((string)$row['country'])) ?><br><span class="muted"><?= utm_h(utm_label($row['device'],'Unbekannt')) ?></span></td><td class="num"><?= utm_number($row['pageviews']) ?></td><td class="num"><?= utm_number($row['clicks']) ?></td><td class="num"><?= utm_seconds($row['active_seconds']) ?></td><td class="num"><?= utm_number($row['max_level']) ?>%</td><td><span class="pill"><?= $row['is_bounce'] ? 'Bounce' : 'Engagiert' ?></span></td>
      </tr><?php endforeach; ?>
      <?php if ($rows === []): ?><tr><td colspan="17" class="empty">Keine passenden UTM-Sitzungen.</td></tr><?php endif; ?>
    </tbody></table></div>
    <?php if (count($rows)>UTM_RECENT_LIMIT): ?><p class="table-note"><?= utm_number(UTM_RECENT_LIMIT) ?> von <?= utm_number(count($rows)) ?> Sitzungen im Detail; alle Daten fließen in die Auswertungen ein.</p><?php endif; ?>
  </section>
</main>
</body>
</html>
