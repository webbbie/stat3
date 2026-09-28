<?php
declare(strict_types=1);

require_once __DIR__ . '/pixl_server.php';
require_once __DIR__ . '/pixl_captcha.php';

// Keep old bookmarks working; export.php owns authentication and the 90-day limit.
if (($_GET['export'] ?? null) === 'csv') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        http_response_code(405);
        header('Allow: GET');
        exit;
    }
    header('Cache-Control: no-store');
    header('Location: export.php?download=csv', true, 302);
    exit;
}
pixl_require_stats_auth();

function stats_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function stats_scalar(PDO $pdo, string $sql, array $params = [])
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return $statement->fetchColumn();
}

function stats_captcha_duration(?int $seconds): string
{
    if ($seconds === null) return 'Noch keine Schätzung';
    if ($seconds < 60) return 'ca. ' . max(1, $seconds) . ' Sek.';
    if ($seconds < 3600) return 'ca. ' . number_format($seconds / 60, 1, ',', '.') . ' Min.';
    if ($seconds < 86400) return 'ca. ' . number_format($seconds / 3600, 1, ',', '.') . ' Std.';
    return 'ca. ' . number_format($seconds / 86400, 1, ',', '.') . ' Tage';
}

function stats_captcha_date(int $timestamp): string
{
    return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('d.m.Y. H:i T');
}

$days = max(1, min(365, (int)($_GET['days'] ?? 30)));
$timeFilter = pixl_stats_time_filter($_GET['range'] ?? '1d', $days);
$timeRange = $timeFilter['range'];
$excludeGermans = isset($_GET['exclude_germans']) && $_GET['exclude_germans'] === '1';
$countryCondition = $excludeGermans ? pixl_sql_exclude_german_country_condition() : '';
$countryClause = $countryCondition !== '' ? " AND $countryCondition" : '';
$error = '';
$table = '';
$metrics = [
    'users' => 0,
    'hash' => 0,
    'ok' => 0,
    'url' => 0,
    'min' => 0.0,
    'minutes_per_visitor' => 0.0,
];
$since = $timeFilter['since'];

try {
    $pdo = pixl_pdo();
    pixl_ensure_schema($pdo);
    $table = pixl_table_name();
    $pageExpr = pixl_sql_page_expression();
    $metrics = [
        'users' => (int)stats_scalar(
            $pdo,
            "SELECT COUNT(*) FROM `$table` WHERE `created_at` >= :users_since$countryClause",
            [':users_since' => $since]
        ),
        'hash' => (int)stats_scalar(
            $pdo,
            "SELECT COUNT(DISTINCT `visitor_hash`) FROM `$table`
             WHERE `created_at` >= :hash_since AND `visitor_hash` <> ''$countryClause",
            [':hash_since' => $since]
        ),
        'ok' => (int)stats_scalar(
            $pdo,
            "SELECT COUNT(*) FROM `$table` WHERE `created_at` >= :ok_since AND `is_bot` = 0$countryClause",
            [':ok_since' => $since]
        ),
        'url' => (int)stats_scalar(
            $pdo,
            "SELECT COUNT(DISTINCT $pageExpr) FROM `$table` WHERE `created_at` >= :url_since$countryClause",
            [':url_since' => $since]
        ),
        'min' => round((float)stats_scalar(
            $pdo,
            "SELECT COALESCE(AVG(`events_per_minute`), 0)
             FROM (
               SELECT COUNT(*) AS `events_per_minute`
               FROM `$table`
               WHERE `created_at` >= :minute_since$countryClause
               GROUP BY DATE_FORMAT(`created_at`, '%Y-%m-%d %H:%i')
             ) AS `minute_counts`",
            [':minute_since' => $since]
        ), 2),
        'minutes_per_visitor' => round((float)stats_scalar(
            $pdo,
            "SELECT COALESCE(
               CASE WHEN COUNT(*) > 1
                 THEN (UNIX_TIMESTAMP(MAX(`first_seen`)) - UNIX_TIMESTAMP(MIN(`first_seen`)))
                      / (COUNT(*) - 1) / 60
                 ELSE 0
               END,
               0
             )
             FROM (
               SELECT `visitor_hash`, MIN(`created_at`) AS `first_seen`
               FROM `$table`
               WHERE `created_at` >= :visitor_interval_since AND `visitor_hash` <> ''$countryClause
               GROUP BY `visitor_hash`
             ) AS `unique_visitors`",
            [':visitor_interval_since' => $since]
        ), 2),
    ];
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}

$captchaSettings = pixl_captcha_settings();
$captchaStats = null;
$captchaError = '';
try {
    $captchaPdo = pixl_pdo();
    pixl_captcha_ensure_schema($captchaPdo);
    $captchaStats = pixl_captcha_summary($captchaPdo, $captchaSettings);
} catch (Throwable $exception) {
    error_log('stats3 captcha statistics: ' . $exception->getMessage());
    $captchaError = 'Captcha-Auswertung derzeit nicht verfügbar.';
}

$pixlQuery = http_build_query([
    'days' => $days,
    'range' => $timeRange,
    'embed' => 1,
    'hide_activity' => 1,
    'exclude_germans' => $excludeGermans ? 1 : 0,
]);
$checkQuery = http_build_query([
    'days' => $days,
    'range' => $timeRange,
    'embed' => 1,
    'exclude_germans' => $excludeGermans ? 1 : 0,
]);
$dashboardQuery = http_build_query([
    'days' => $days,
    'range' => $timeRange,
    'limit' => 25,
    'ar' => 1,
    'embed' => 1,
    'hide_recent' => 1,
    'exclude_germans' => $excludeGermans ? 1 : 0,
]);
?>
<!doctype html>
<html lang="de" data-stats-mobile="page">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light dark">
  <title>Stats3 Gesamtstatistik</title>
  <script>
    (() => {
      try {
        const saved = localStorage.getItem("stats3_theme");
        const preferred = window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches
          ? "dark"
          : "light";
        document.documentElement.setAttribute("data-theme", saved === "dark" || saved === "light" ? saved : preferred);
      } catch (error) {
        document.documentElement.setAttribute("data-theme", "light");
      }
    })();
  </script>
  <style>
    :root {
      color-scheme: light dark;
      --bg: #eef2f4;
      --surface: #ffffff;
      --surface-alt: #f7f9fa;
      --ink: #172126;
      --muted: #66747c;
      --line: #d6dfe3;
      --accent: #087f73;
      --accent-soft: #e2f4f0;
      --blue: #3568a8;
      --blue-soft: #e7eef8;
      --danger: #a43f4e;
      --danger-soft: #f9e8eb;
    }

    :root[data-theme="dark"] {
      color-scheme: dark;
      --bg: #121719;
      --surface: #1c2327;
      --surface-alt: #171d21;
      --ink: #eef3f4;
      --muted: #a6b1b6;
      --line: #354047;
      --accent: #54c8b8;
      --accent-soft: #203b37;
      --blue: #82aee3;
      --blue-soft: #24364b;
      --danger: #e497a3;
      --danger-soft: #42282e;
    }

    * { box-sizing: border-box; letter-spacing: 0; }
    html { scroll-behavior: smooth; }
    body { min-width: 320px; margin: 0; color: var(--ink); background: var(--bg); font: 14px/1.45 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
    a { color: var(--accent); text-underline-offset: 3px; }
    button, input, select { font: inherit; }
    :focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }

    .topbar { position: sticky; z-index: 10; top: 0; border-bottom: 1px solid var(--line); background: var(--surface); }
    .topbar-inner, .section-nav, main { width: min(1440px, 100%); margin-inline: auto; padding-inline: 20px; }
    .topbar-inner { min-height: 68px; display: flex; align-items: center; gap: 20px; overflow-x: auto; padding-block: 10px; -webkit-overflow-scrolling: touch; }
    .topbar-title { min-width: max-content; display: flex; flex: 1 0 auto; align-items: baseline; gap: 12px; white-space: nowrap; }
    h1, h2, p { margin: 0; }
    h1 { font-size: 28px; line-height: 1.15; }
    .main-since { color: var(--muted); font-size: 12px; }
    .controls { display: flex; flex: 0 0 auto; align-items: center; justify-content: flex-end; gap: 8px; flex-wrap: nowrap; }
    .time-filters { display: flex; align-items: center; gap: 6px; white-space: nowrap; }
    .time-filter { min-height: 36px; display: inline-flex; align-items: center; gap: 6px; border: 1px solid var(--line); border-radius: 6px; padding: 7px 9px; color: var(--ink); background: var(--surface-alt); font-weight: 750; cursor: pointer; }
    .time-filter:has(input:checked) { color: #ffffff; border-color: var(--accent); background: var(--accent); }
    .time-filter input { width: 15px; height: 15px; margin: 0; accent-color: var(--accent); }
    .days-range-select { min-height: 36px; border: 1px solid var(--line); border-radius: 6px; padding: 7px 30px 7px 9px; color: var(--ink); background: var(--surface-alt); font-weight: 750; cursor: pointer; }
    .days-range-select.is-active { color: #ffffff; border-color: var(--accent); background-color: var(--accent); }
    .controls button, .controls .action-button { min-height: 36px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid var(--line); border-radius: 6px; padding: 7px 10px; color: var(--ink); background: var(--surface-alt); font-weight: 750; white-space: nowrap; cursor: pointer; }
    .controls button, .controls .action-button { color: #ffffff; border-color: var(--accent); background: var(--accent); }
    .controls .action-button { text-decoration: none; }
    .controls .secondary, .controls .theme-toggle { color: var(--ink); border-color: var(--line); background: var(--surface-alt); }
    .controls .germans-toggle[aria-pressed="true"] { color: var(--danger); border-color: var(--danger); background: var(--danger-soft); }

    .section-nav { padding-block: 0 10px; }
    .section-nav ul { display: flex; flex-wrap: wrap; gap: 6px; margin: 0; padding: 0; list-style: none; }
    .section-nav a { display: flex; align-items: center; min-height: 40px; padding: 8px 12px; border: 1px solid var(--line); border-radius: 6px; color: var(--ink); background: var(--surface-alt); font-weight: 700; text-decoration: none; }
    .section-nav a:hover, .section-nav a:focus-visible { color: var(--accent); border-color: var(--accent); background: var(--accent-soft); }

    main { padding-block: 22px 44px; }
    .notice { margin-bottom: 18px; border: 1px solid var(--danger); border-radius: 6px; padding: 12px 14px; color: var(--danger); background: var(--danger-soft); }
    .source-section { scroll-margin-top: calc(var(--stats-header-height, 250px) + 16px); }
    .source-section + .source-section { margin-top: 28px; padding-top: 26px; border-top: 1px solid var(--line); }
    .section-heading { display: flex; align-items: baseline; justify-content: space-between; gap: 16px; margin-bottom: 10px; }
    .section-heading h2 { font-size: 18px; }
    .section-heading span { color: var(--muted); font-size: 12px; }
    .frame-shell { overflow: hidden; border: 1px solid var(--line); border-radius: 8px; background: var(--surface); }
    iframe { display: block; width: 100%; min-height: 720px; border: 0; background: var(--surface); }

    .metric-grid { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 10px; }
    .metric { min-width: 0; border: 1px solid var(--line); border-radius: 7px; padding: 15px; background: var(--surface); }
    .metric .key { display: inline-flex; min-height: 24px; align-items: center; border-radius: 4px; padding: 3px 7px; color: var(--blue); background: var(--blue-soft); font-size: 11px; font-weight: 850; }
    .metric strong { display: block; margin-top: 12px; font-size: 27px; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
    .metric p { margin-top: 3px; color: var(--muted); font-size: 12px; }

    .summary-board { overflow: hidden; border: 1px solid var(--line); border-radius: 9px; background: var(--surface); }
    .summary-subboard + .summary-subboard { border-top: 3px solid var(--bg); }
    .summary-subboard-header { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; padding: 14px 16px; border-bottom: 1px solid var(--line); background: var(--surface-alt); }
    .summary-subboard-header h3 { margin: 0; font-size: 16px; letter-spacing: .06em; }
    .summary-subboard-header span { color: var(--muted); font-size: 12px; }
    .summary-row { padding: 14px 16px; }
    .summary-row + .summary-row { border-top: 1px solid var(--line); }
    .summary-loading { margin: 0; padding: 12px; color: var(--muted); background: var(--surface-alt); }
    .summary-metrics, .summary-kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; }
    .captcha-metrics { grid-template-columns: repeat(5, minmax(0, 1fr)); }
    .summary-board .stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; }
    .summary-board .stats .metric, .summary-board .card.kpi { min-width: 0; border: 1px solid var(--line); border-radius: 7px; padding: 14px; background: var(--surface-alt); }
    .summary-board .stats .metric span, .summary-board .card.kpi .k { color: var(--muted); font-size: 11px; font-weight: 800; text-transform: uppercase; }
    .summary-board .stats .metric strong, .summary-board .card.kpi .v { display: block; margin-top: 8px; color: var(--ink); font-size: 25px; font-weight: 800; font-variant-numeric: tabular-nums; }
    .summary-board .card.kpi .d { margin-top: 3px; color: var(--muted); font-size: 11px; }
    .summary-board section { margin: 0; }
    .summary-board section h2 { margin: 0 0 9px; font-size: 15px; }
    .summary-board table { width: 100%; border-collapse: collapse; font-size: 12px; }
    .summary-board th, .summary-board td { padding: 7px 9px; border-bottom: 1px solid var(--line); text-align: left; vertical-align: top; }
    .summary-board th { color: var(--muted); background: var(--surface-alt); font-weight: 800; }
    .summary-board .bar { display: block; min-width: 100px; height: 7px; overflow: hidden; border-radius: 99px; background: var(--line); }
    .summary-board .bar i { display: block; height: 100%; background: var(--accent); }
    .summary-board .summary-six-scroll, .summary-board .scroll { overflow-x: auto; }
    .summary-board .summary-six-grid { display: grid; grid-template-columns: repeat(6, minmax(185px, 1fr)); gap: 10px; min-width: 1140px; }
    .summary-board .summary-six-grid section { overflow: hidden; border: 1px solid var(--line); border-radius: 7px; }
    .summary-board .summary-six-grid h2 { padding: 9px; margin: 0; background: var(--surface-alt); }
    .summary-board .badge { display: inline-flex; min-width: 24px; justify-content: center; border-radius: 5px; padding: 2px 6px; color: #fff; background: var(--accent); font-weight: 800; }
    .summary-charts { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
    .summary-charts .chart { min-width: 0; border: 1px solid var(--line); border-radius: 7px; padding: 12px; background: var(--surface-alt); }
    .summary-charts .chart-container { position: relative; height: 240px; }
    .summary-charts canvas { width: 100% !important; height: 100% !important; }

    @media (max-width: 980px) {
      .metric-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
      .summary-metrics, .summary-kpis, .summary-board .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .summary-charts { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }

    @media (max-width: 600px) {
      .topbar-inner, .section-nav, main { padding-inline: 12px; }
      .section-nav a { min-height: 44px; }
      h1 { font-size: 24px; }
      .section-heading { align-items: flex-start; flex-direction: column; gap: 3px; }
      .metric-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .summary-metrics, .summary-kpis, .summary-board .stats, .summary-charts { grid-template-columns: 1fr; }
      iframe { min-height: 640px; }
    }

    @media (prefers-reduced-motion: reduce) {
      html { scroll-behavior: auto; }
    }
  </style>
  <link rel="stylesheet" href="stats-mobile.css">
</head>
<body>
  <header class="topbar">
    <div class="topbar-inner">
      <div class="topbar-title">
        <h1>Pixl SQL Statistik</h1>
        <p class="main-since"><?= stats_h($timeFilter['label']) ?> · seit <?= stats_h($since) ?> UTC</p>
      </div>
      <div class="controls">
        <div class="time-filters" role="group" aria-label="Statistik nach Zeit filtern">
          <label class="time-filter"><input class="stats-time-filter" type="checkbox" value="60min"<?= $timeRange === '60min' ? ' checked' : '' ?>>60min</label>
          <label class="time-filter"><input class="stats-time-filter" type="checkbox" value="24h"<?= $timeRange === '24h' ? ' checked' : '' ?>>24h</label>
          <label class="time-filter"><input class="stats-time-filter" type="checkbox" value="1d"<?= $timeRange === '1d' ? ' checked' : '' ?>>1day</label>
          <select class="days-range-select<?= preg_match('/^last[1-7]d$/', $timeRange) === 1 ? ' is-active' : '' ?>" id="statsDaysRange" aria-label="Letzte 1 bis 7 Tage anzeigen">
            <option value="">Last 1–7 days</option>
            <?php for ($rangeDays = 1; $rangeDays <= 7; $rangeDays++): ?>
              <option value="last<?= $rangeDays ?>d"<?= $timeRange === 'last' . $rangeDays . 'd' ? ' selected' : '' ?>>Last <?= $rangeDays ?> day<?= $rangeDays === 1 ? '' : 's' ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <a class="action-button" href="comp.php" title="Länder, Sprachen, Auflösungen, Bounce Rate und Browser vergleichen">Vergleiche</a>
        <a class="action-button" href="utm.php" title="UTM-, PPCMate-, Kampagnen-, Banner- und Click-ID-Statistik">UTM</a>
        <a class="action-button" href="live.php" title="UA: Live-Traffic, Seitenaufrufe und Kampagnen">UA</a>
        <a class="action-button" href="mind/index.php" title="Erfolgreiche Pushover-Nachrichten und MaxMind-Daten">Mind</a>
        <a class="action-button" href="stat4/index.php" title="STAT4 Beta oeffnen">Beta</a>
        <a class="action-button" href="export.php" title="Statistikübersicht und Daten-Backup für maximal 90 Tage">Export</a>
        <button class="secondary germans-toggle" id="statsGermansToggle" type="button" aria-pressed="<?= $excludeGermans ? 'true' : 'false' ?>" title="<?= $excludeGermans ? 'Deutsche Besucher sind ausgeblendet' : 'Deutsche Besucher ausblenden' ?>">Germans</button>
        <button class="theme-toggle" id="statsThemeToggle" type="button" aria-pressed="false">Dark Mode</button>
      </div>
    </div>
    <nav class="section-nav" aria-label="Statistiken auf dieser Seite">
      <ul>
        <li><a href="#pixl">Pixl Stats</a></li>
        <li><a href="#click-paths">Click Paths und Campaigns</a></li>
        <li><a href="#users">Users</a></li>
        <li><a href="#dashboardx2">DashboardX2</a></li>
        <li><a href="#summary-board">Summary Board</a></li>
        <li><a href="#captcha">Captcha-Auswertung</a></li>
      </ul>
    </nav>
  </header>

  <main>
    <?php if ($error !== ''): ?>
      <div class="notice" role="alert">MySQL: <?= stats_h($error) ?></div>
    <?php endif; ?>

    <section class="source-section" id="pixl" aria-labelledby="pixl-title">
      <div class="section-heading">
        <h2 id="pixl-title">Pixl Stats</h2>
        <span>ohne Letzte Bot-Aufrufe und Letzte Events</span>
      </div>
      <div class="frame-shell">
        <iframe class="source-frame" id="pixlStatsFrame" src="pixl_stats.php?<?= stats_h($pixlQuery) ?>" title="Pixl Stats" scrolling="no"></iframe>
      </div>
    </section>

    <section class="source-section" id="click-paths" aria-labelledby="click-title">
      <div class="section-heading">
        <h2 id="click-title">Click Paths und Campaigns</h2>
        <span>checkthis.php</span>
      </div>
      <div class="frame-shell">
        <iframe class="source-frame" id="checkStatsFrame" src="stat/checkthis.php?<?= stats_h($checkQuery) ?>" title="Click Paths und Campaign Overview" scrolling="no"></iframe>
      </div>
    </section>

    <section class="source-section" id="users" aria-labelledby="users-title">
      <div class="section-heading">
        <h2 id="users-title">Users</h2>
        <span><?= $table !== '' ? stats_h($table) . ' · ' . stats_h($timeFilter['label']) : 'dashboard.php' ?></span>
      </div>
      <div class="metric-grid">
        <article class="metric"><span class="key">Users</span><strong><?= stats_h(number_format($metrics['users'], 0, ',', '.')) ?></strong><p>Besuche im Zeitraum</p></article>
        <article class="metric"><span class="key">Hash</span><strong><?= stats_h(number_format($metrics['hash'], 0, ',', '.')) ?></strong><p>Eindeutige Besucher</p></article>
        <article class="metric"><span class="key">OK</span><strong><?= stats_h(number_format($metrics['ok'], 0, ',', '.')) ?></strong><p>Echte Besucher</p></article>
        <article class="metric"><span class="key">URL</span><strong><?= stats_h(number_format($metrics['url'], 0, ',', '.')) ?></strong><p>Verschiedene Seiten</p></article>
        <article class="metric"><span class="key">Min</span><strong><?= stats_h(number_format($metrics['min'], 2, ',', '.')) ?></strong><p>Events pro aktiver Minute</p></article>
        <article class="metric"><span class="key">1 Besucher</span><strong><?= stats_h(number_format($metrics['minutes_per_visitor'], 2, ',', '.')) ?> Min</strong><p>Ø bis zum nächsten eindeutigen Besucher · <?= stats_h($timeFilter['label']) ?></p></article>
      </div>
    </section>

    <section class="source-section" id="dashboardx2" aria-labelledby="dashboardx2-title">
      <div class="section-heading">
        <h2 id="dashboardx2-title">DashboardX2</h2>
        <span>ohne Top 25 · Letzte Zugriffe</span>
      </div>
      <div class="frame-shell">
        <iframe class="source-frame" id="dashboardX2Frame" src="stat/dashboardx2.html?<?= stats_h($dashboardQuery) ?>" title="DashboardX2" scrolling="no"></iframe>
      </div>
    </section>

    <section class="source-section" id="summary-board" aria-labelledby="summary-board-title">
      <div class="section-heading">
        <h2 id="summary-board-title">Summary Board</h2>
        <span><?= stats_h($timeFilter['label']) ?></span>
      </div>
      <div class="summary-board">
        <article class="summary-subboard" aria-labelledby="reader-board-title">
          <div class="summary-subboard-header"><h3 id="reader-board-title">READER</h3><span>Leser und Geräte</span></div>
          <div class="summary-row" id="summaryReaderMetrics"><p class="summary-loading">READER-Kennzahlen werden geladen …</p></div>
          <div class="summary-row" id="summaryReaderTimeline1"><p class="summary-loading">Verlauf im Zeitraum wird geladen …</p></div>
          <div class="summary-row" id="summaryReaderTimeline2"><p class="summary-loading">Verlauf im Zeitraum wird geladen …</p></div>
          <div class="summary-row" id="summaryReaderDevices"><p class="summary-loading">Geräteübersicht wird geladen …</p></div>
        </article>

        <article class="summary-subboard" aria-labelledby="level-board-title">
          <div class="summary-subboard-header"><h3 id="level-board-title">Level</h3><span>Level Table</span></div>
          <div class="summary-row" id="summaryLevelTable"><p class="summary-loading">Level Table wird geladen …</p></div>
        </article>

        <article class="summary-subboard" aria-labelledby="users-board-title">
          <div class="summary-subboard-header"><h3 id="users-board-title">Users</h3><span>Hauptwerte</span></div>
          <div class="summary-row">
            <div class="summary-metrics">
              <article class="metric"><span class="key">Users</span><strong><?= stats_h(number_format($metrics['users'], 0, ',', '.')) ?></strong><p>Besuche im Zeitraum</p></article>
              <article class="metric"><span class="key">URL</span><strong><?= stats_h(number_format($metrics['url'], 0, ',', '.')) ?></strong><p>Verschiedene Seiten</p></article>
              <article class="metric"><span class="key">Min</span><strong><?= stats_h(number_format($metrics['min'], 2, ',', '.')) ?></strong><p>Events pro aktiver Minute</p></article>
              <article class="metric"><span class="key">1 Besucher</span><strong><?= stats_h(number_format($metrics['minutes_per_visitor'], 2, ',', '.')) ?> Min</strong><p>Ø bis zum nächsten Besucher</p></article>
            </div>
          </div>
        </article>

        <article class="summary-subboard" aria-labelledby="overall-board-title">
          <div class="summary-subboard-header"><h3 id="overall-board-title">Overall</h3><span>DashboardX2</span></div>
          <div class="summary-row" id="summaryOverallKpis"><p class="summary-loading">Overall-Kennzahlen werden geladen …</p></div>
          <div class="summary-row" id="summaryOverallCharts"><p class="summary-loading">Diagramme werden geladen …</p></div>
        </article>
      </div>
    </section>
    <section class="source-section" id="captcha" aria-labelledby="captcha-title">
      <div class="section-heading">
        <h2 id="captcha-title">Captcha-Auswertung</h2>
        <span><?= !$captchaSettings['enabled'] ? 'Deaktiviert' : ($captchaStats === null ? 'Status nicht verfügbar' : ($captchaStats['active'] ? 'Prüfphase aktiv' : 'Wartephase')) ?> · alle Websites und Länder · unabhängig vom Zeitfilter</span>
      </div>
      <?php if ($captchaError !== ''): ?>
        <div class="notice" role="alert"><?= stats_h($captchaError) ?></div>
      <?php else: ?>
        <div class="summary-metrics captcha-metrics">
          <article class="metric">
            <span class="key">Impressionen bis zur nächsten Captcha-Prüfung</span>
            <strong><?= $captchaStats['remaining_visitors'] === null ? 'Deaktiviert' : stats_h(number_format($captchaStats['remaining_visitors'], 0, ',', '.')) ?></strong>
            <p><?= $captchaStats['active'] ? 'Die aktuelle Captcha-Prüfphase läuft bereits.' : 'Gezählt werden neue Besucher; Seitenwechsel und Neuladen zählen nicht erneut.' ?></p>
            <?php if ($captchaSettings['page_view_interval'] > 0): ?>
              <p>Besucher Seiten Ansichten: <b><?= stats_h(number_format($captchaStats['waiting_page_views'], 0, ',', '.')) ?> / <?= stats_h(number_format($captchaSettings['page_view_interval'], 0, ',', '.')) ?></b>.<?php if ($captchaStats['remaining_page_views'] !== null): ?> Insgesamt noch erforderlich: <?= stats_h(number_format($captchaStats['remaining_page_views'], 0, ',', '.')) ?>.<?php endif; ?></p>
            <?php endif; ?>
          </article>
          <article class="metric">
            <span class="key">Ungefähre Zeit bis zur nächsten Captcha-Prüfung</span>
            <strong><?= !$captchaStats['enabled'] ? 'Deaktiviert' : ($captchaStats['active'] ? 'Jetzt aktiv' : stats_h(stats_captcha_duration($captchaStats['estimated_seconds']))) ?></strong>
            <p><?php if ($captchaStats['remaining_page_views'] > 0): ?>Der Seiten-Schwellwert ist noch offen. Aus der Besucherzahl allein lässt sich die Wartezeit deshalb noch nicht schätzen.<?php elseif ($captchaStats['sample_visitors'] >= 2): ?>Schätzung aus <?= stats_h(number_format($captchaStats['sample_visitors'], 0, ',', '.')) ?> neuen Besuchern seit <?= stats_h(stats_captcha_date($captchaStats['sample_started_at'])) ?>, einschließlich Leerlaufzeiten.<?php else: ?>Für die Schätzung werden mindestens zwei neue Besucher mit zeitlichem Abstand benötigt.<?php endif; ?></p>
          </article>
          <article class="metric">
            <span class="key">Jetzige / letzte erfolgreiche Captcha-Prüfungen</span>
            <strong><?= stats_h(number_format($captchaStats['successes'], 0, ',', '.')) ?> / <?= $captchaStats['last_successes'] === null ? '–' : stats_h(number_format($captchaStats['last_successes'], 0, ',', '.')) ?></strong>
            <p>Aktuelle / letzte abgeschlossene Prüfphase · Ziel: <?= stats_h(number_format($captchaSettings['success_target'], 0, ',', '.')) ?> Erfolge pro Phase.</p>
          </article>
          <article class="metric">
            <span class="key">Jetzige / letzte erfolglose Captcha-Prüfungen</span>
            <strong><?= stats_h(number_format($captchaStats['failures'], 0, ',', '.')) ?> / <?= $captchaStats['last_failures'] === null ? '–' : stats_h(number_format($captchaStats['last_failures'], 0, ',', '.')) ?></strong>
            <p>Aktuelle / letzte abgeschlossene Prüfphase · gemeldete Fehlversuche beim Verschieben des Puzzleteils.</p>
          </article>
          <article class="metric">
            <span class="key">Jetzige / letzte Besucher, die von der Captcha blockiert wurden</span>
            <strong><?= $captchaStats['blocked_visitors'] === null ? '–' : stats_h(number_format($captchaStats['blocked_visitors'], 0, ',', '.')) ?> / <?= $captchaStats['last_blocked_visitors'] === null ? '–' : stats_h(number_format($captchaStats['last_blocked_visitors'], 0, ',', '.')) ?></strong>
            <p>Aktuelle / letzte abgeschlossene Prüfphase · Besucher mit Captcha ohne bestätigten Erfolg, einschließlich abgebrochener oder abgelaufener Prüfungen. Neuladen und mehrere Fehlversuche zählen nicht zusätzlich.</p>
          </article>
        </div>
        <?php if ($captchaStats['blocked_visitors'] === null || $captchaStats['last_blocked_visitors'] === null): ?>
          <p class="summary-loading">„–“ bei blockierten Besuchern bedeutet: noch keine vollständig erfasste Prüfphase. Ältere Werte sind nicht nachträglich verfügbar.</p>
        <?php endif; ?>
        <p class="summary-loading">Intervall: <?= stats_h(number_format($captchaSettings['visitor_interval'], 0, ',', '.')) ?> neue Besucher ohne Captcha<?php if ($captchaSettings['page_view_interval'] > 0): ?> und mindestens <?= stats_h(number_format($captchaSettings['page_view_interval'], 0, ',', '.')) ?> unterschiedliche Besucher-Seiten-Kombinationen<?php endif; ?>. Ein weiterer neuer Besucher startet die Prüfphase, sobald beide Schwellwerte erreicht sind.<?php if ($captchaSettings['landing_urls'] !== []): ?> Das Captcha erscheint nur auf den konfigurierten Landing-Seiten; alle erlaubten Seiten zählen mit.<?php endif; ?> Abgebrochene Besuche und Netzwerkfehler zählen nicht als Fehlversuch.<?php if ($captchaStats['tracking_started_at'] > 0): ?> Fehlversuche erfasst seit <?= stats_h(stats_captcha_date($captchaStats['tracking_started_at'])) ?>; ältere Fehlversuche sind nicht verfügbar.<?php else: ?> Die Erfassung beginnt mit der nächsten aktivierten Captcha-Anfrage.<?php endif; ?><?php if ($captchaStats['last_completed_at'] > 0): ?> Letzte Phase beendet: <?= stats_h(stats_captcha_date($captchaStats['last_completed_at'])) ?>.<?php else: ?> Noch keine abgeschlossene Phase erfasst.<?php endif; ?></p>
      <?php endif; ?>
    </section>
    <p class="summary-loading"><a href="https://db-ip.com" rel="external">IP Geolocation by DB-IP</a></p>
  </main>

  <script>
    (() => {
      "use strict";

      const frames = Array.from(document.querySelectorAll(".source-frame"));
      const mobileLayout = window.matchMedia("(max-width: 720px), (max-width: 980px) and (pointer: coarse)");
      const themeToggle = document.getElementById("statsThemeToggle");
      const germansToggle = document.getElementById("statsGermansToggle");
      const timeFilters = Array.from(document.querySelectorAll(".stats-time-filter"));
      const daysRange = document.getElementById("statsDaysRange");

      // Wrapped menu rows must not cover the headings after an anchor jump.
      const topbar = document.querySelector(".topbar");
      const syncAnchorOffset = () => {
        document.documentElement.style.setProperty("--stats-header-height", Math.ceil(topbar.getBoundingClientRect().height) + "px");
      };
      syncAnchorOffset();
      if ("ResizeObserver" in window) {
        new ResizeObserver(syncAnchorOffset).observe(topbar);
      } else {
        window.addEventListener("resize", syncAnchorOffset);
      }

      timeFilters.forEach((filter) => {
        filter.addEventListener("change", () => {
          const url = new URL(window.location.href);
          if (filter.checked) {
            url.searchParams.set("range", filter.value);
          } else {
            url.searchParams.delete("range");
          }
          window.location.assign(url.toString());
        });
      });

      daysRange.addEventListener("change", () => {
        const url = new URL(window.location.href);
        if (daysRange.value) {
          url.searchParams.set("range", daysRange.value);
        } else {
          url.searchParams.delete("range");
        }
        window.location.assign(url.toString());
      });

      germansToggle.addEventListener("click", () => {
        const url = new URL(window.location.href);
        if (germansToggle.getAttribute("aria-pressed") === "true") {
          url.searchParams.delete("exclude_germans");
        } else {
          url.searchParams.set("exclude_germans", "1");
        }
        window.location.assign(url.toString());
      });

      function currentTheme() {
        return document.documentElement.getAttribute("data-theme") === "dark" ? "dark" : "light";
      }

      function syncFrameTheme(frame, theme) {
        try {
          if (frame.contentDocument && frame.contentDocument.documentElement) {
            frame.contentDocument.documentElement.setAttribute("data-theme", theme);
          }
          if (frame.contentWindow) {
            frame.contentWindow.postMessage({ type: "stats3-theme", theme }, location.origin);
          }
        } catch (error) {}
      }

      function applyTheme(theme, persist = true) {
        const normalized = theme === "dark" ? "dark" : "light";
        document.documentElement.setAttribute("data-theme", normalized);
        themeToggle.textContent = normalized === "dark" ? "Light Mode" : "Dark Mode";
        themeToggle.setAttribute("aria-pressed", normalized === "dark" ? "true" : "false");
        if (persist) {
          try {
            localStorage.setItem("stats3_theme", normalized);
          } catch (error) {}
        }
        frames.forEach((frame) => syncFrameTheme(frame, normalized));
      }

      function resizeFrame(frame) {
        try {
          const doc = frame.contentDocument;
          if (!doc || !doc.documentElement || !doc.body) return;
          // Content height lets mobile frames shrink after rotation or a data refresh.
          const height = mobileLayout.matches
            ? Math.ceil(doc.body.getBoundingClientRect().height)
            : Math.max(
              doc.documentElement.scrollHeight,
              doc.documentElement.offsetHeight,
              doc.body.scrollHeight,
              doc.body.offsetHeight
            );
          if (height > 0) frame.style.height = height + "px";
        } catch (error) {}
      }

      function observeFrame(frame) {
        resizeFrame(frame);
      }

      function cleanSummaryClone(node) {
        node.querySelectorAll("[id]").forEach((element) => element.removeAttribute("id"));
        node.removeAttribute("id");
        return node;
      }

      function replaceSummary(targetId, content) {
        const target = document.getElementById(targetId);
        if (!target || !content) return;
        target.replaceChildren(content);
      }

      function syncReaderSummary() {
        const frame = document.getElementById("pixlStatsFrame");
        const doc = frame && frame.contentDocument;
        if (!doc) return;

        const wantedMetrics = new Set([
          "Nachricht Ø alle",
          "Bounce Rate",
          "Seiten pro Besucher",
          "Besucher mit 4+ Seiten"
        ]);
        const metricGrid = document.createElement("div");
        metricGrid.className = "stats";
        doc.querySelectorAll(".stats .metric").forEach((metric) => {
          const label = metric.querySelector("span");
          if (label && wantedMetrics.has(label.textContent.trim())) {
            const metricClone = cleanSummaryClone(metric.cloneNode(true));
            const clonedLabel = metricClone.querySelector("span");
            if (clonedLabel && label.textContent.trim() === "Nachricht Ø alle") {
              clonedLabel.textContent = "NACHRICHT Ø ALLE";
            }
            metricGrid.appendChild(metricClone);
          }
        });
        if (metricGrid.children.length) replaceSummary("summaryReaderMetrics", metricGrid);

        const timeline = doc.querySelector("main > section.wide");
        if (timeline) {
          replaceSummary("summaryReaderTimeline1", cleanSummaryClone(timeline.cloneNode(true)));
          replaceSummary("summaryReaderTimeline2", cleanSummaryClone(timeline.cloneNode(true)));
        }

        const devices = doc.querySelector(".summary-six-scroll");
        if (devices) replaceSummary("summaryReaderDevices", cleanSummaryClone(devices.cloneNode(true)));
      }

      function syncLevelSummary() {
        const frame = document.getElementById("checkStatsFrame");
        const doc = frame && frame.contentDocument;
        const levelTable = doc && doc.querySelector('section[aria-label="Level Table"]');
        if (levelTable) replaceSummary("summaryLevelTable", cleanSummaryClone(levelTable.cloneNode(true)));
      }

      function syncOverallSummary() {
        const frame = document.getElementById("dashboardX2Frame");
        const doc = frame && frame.contentDocument;
        if (!doc) return;

        const wantedKpis = [
          "Echte Besucher",
          "Seiten Impressionen",
          "Verschiedene Seiten",
          "Besucher/Minute"
        ];
        const kpiGrid = document.createElement("div");
        kpiGrid.className = "summary-kpis";
        doc.querySelectorAll("#kpiToday .card.kpi").forEach((card) => {
          const label = card.querySelector(".k");
          const text = label ? label.textContent.trim() : "";
          if (wantedKpis.some((wanted) => text.startsWith(wanted))) {
            const cardClone = cleanSummaryClone(card.cloneNode(true));
            const clonedLabel = cardClone.querySelector(".k");
            if (clonedLabel) clonedLabel.textContent = text.replace(/\s*\(Zeitraum\)\s*$/, "");
            kpiGrid.appendChild(cardClone);
          }
        });
        if (kpiGrid.children.length) replaceSummary("summaryOverallKpis", kpiGrid);

        const sourceCharts = Array.from(doc.querySelectorAll(".charts .chart"));
        if (!sourceCharts.length) return;
        const chartGrid = document.createElement("div");
        chartGrid.className = "summary-charts";
        sourceCharts.forEach((sourceChart) => {
          const chartClone = cleanSummaryClone(sourceChart.cloneNode(true));
          const sourceCanvas = sourceChart.querySelector("canvas");
          const targetCanvas = chartClone.querySelector("canvas");
          const chartTitle = chartClone.querySelector("h2");
          if (chartTitle) chartTitle.textContent = chartTitle.textContent.replace(/\s*\(Zeitraum\)\s*$/, "");
          chartGrid.appendChild(chartClone);
          if (!sourceCanvas || !targetCanvas || sourceCanvas.width < 1 || sourceCanvas.height < 1) return;
          targetCanvas.width = sourceCanvas.width;
          targetCanvas.height = sourceCanvas.height;
          const context = targetCanvas.getContext("2d");
          if (context) context.drawImage(sourceCanvas, 0, 0);
        });
        replaceSummary("summaryOverallCharts", chartGrid);
      }

      function syncSummaryBoard() {
        try { syncReaderSummary(); } catch (error) {}
        try { syncLevelSummary(); } catch (error) {}
        try { syncOverallSummary(); } catch (error) {}
      }

      frames.forEach((frame) => {
        frame.addEventListener("load", () => {
          observeFrame(frame);
          syncFrameTheme(frame, currentTheme());
          window.setTimeout(syncSummaryBoard, 250);
          window.setTimeout(syncSummaryBoard, 1200);
        });
      });
      themeToggle.addEventListener("click", () => {
        applyTheme(currentTheme() === "dark" ? "light" : "dark");
      });
      applyTheme(currentTheme(), false);
      window.setInterval(() => {
        frames.forEach(resizeFrame);
      }, 1000);
      window.setInterval(syncSummaryBoard, 10000);
    })();
  </script>
</body>
</html>
