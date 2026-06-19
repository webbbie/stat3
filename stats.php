<?php
declare(strict_types=1);

require_once __DIR__ . '/pixl_server.php';

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

$days = max(1, min(365, (int)($_GET['days'] ?? 30)));
$error = '';
$table = '';
$metrics = [
    'users' => 0,
    'hash' => 0,
    'ok' => 0,
    'url' => 0,
    'min' => 0.0,
];

try {
    $pdo = pixl_pdo();
    pixl_ensure_schema($pdo);
    $table = pixl_table_name();
    $since = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->modify('-' . $days . ' days')
        ->format('Y-m-d H:i:s');
    $pageExpr = pixl_sql_page_expression();
    $scope = pixl_sql_configured_stats_url_condition($pdo);
    $scopeAnd = $scope !== '' ? " AND $scope" : '';

    $metrics = [
        'users' => (int)stats_scalar(
            $pdo,
            "SELECT COUNT(*) FROM `$table` WHERE `created_at` >= :users_since$scopeAnd",
            [':users_since' => $since]
        ),
        'hash' => (int)stats_scalar(
            $pdo,
            "SELECT COUNT(DISTINCT `visitor_hash`) FROM `$table`
             WHERE `created_at` >= :hash_since AND `visitor_hash` <> ''$scopeAnd",
            [':hash_since' => $since]
        ),
        'ok' => (int)stats_scalar(
            $pdo,
            "SELECT COUNT(*) FROM `$table` WHERE `created_at` >= :ok_since AND `is_bot` = 0$scopeAnd",
            [':ok_since' => $since]
        ),
        'url' => (int)stats_scalar(
            $pdo,
            "SELECT COUNT(DISTINCT $pageExpr) FROM `$table` WHERE `created_at` >= :url_since$scopeAnd",
            [':url_since' => $since]
        ),
        'min' => round((float)stats_scalar(
            $pdo,
            "SELECT COALESCE(AVG(`events_per_minute`), 0)
             FROM (
               SELECT COUNT(*) AS `events_per_minute`
               FROM `$table`
               WHERE `created_at` >= :minute_since$scopeAnd
               GROUP BY DATE_FORMAT(`created_at`, '%Y-%m-%d %H:%i')
             ) AS `minute_counts`",
            [':minute_since' => $since]
        ), 2),
    ];
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}

$pixlQuery = http_build_query([
    'days' => $days,
    'embed' => 1,
    'hide_activity' => 1,
]);
$checkQuery = http_build_query([
    'days' => $days,
    'embed' => 1,
]);
$dashboardQuery = http_build_query([
    'days' => $days,
    'limit' => 25,
    'ar' => 1,
    'hide_recent' => 1,
]);
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light dark">
  <title>Stats3 Gesamtstatistik</title>
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

    @media (prefers-color-scheme: dark) {
      :root {
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
    }

    * { box-sizing: border-box; letter-spacing: 0; }
    html { scroll-behavior: smooth; }
    body { min-width: 320px; margin: 0; color: var(--ink); background: var(--bg); font: 14px/1.45 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
    a { color: var(--accent); text-underline-offset: 3px; }
    button, input { font: inherit; }
    :focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }

    .topbar { position: sticky; z-index: 10; top: 0; border-bottom: 1px solid var(--line); background: var(--surface); }
    .topbar-inner, main { width: min(1440px, 100%); margin-inline: auto; padding-inline: 20px; }
    .topbar-inner { min-height: 92px; display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 20px; align-items: center; padding-block: 14px; }
    .eyebrow { margin: 0 0 3px; color: var(--accent); font-size: 12px; font-weight: 800; text-transform: uppercase; }
    h1, h2, p { margin: 0; }
    h1 { font-size: 28px; line-height: 1.15; }
    .controls { display: flex; align-items: center; justify-content: flex-end; gap: 8px; flex-wrap: wrap; }
    .controls form { display: flex; align-items: center; gap: 6px; }
    .controls label { color: var(--muted); font-size: 12px; font-weight: 750; }
    .controls input { width: 72px; min-height: 36px; border: 1px solid var(--line); border-radius: 6px; padding: 6px 8px; color: var(--ink); background: var(--surface-alt); }
    .control-link, .controls button { min-height: 36px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid var(--line); border-radius: 6px; padding: 7px 10px; color: var(--ink); background: var(--surface-alt); font-weight: 750; text-decoration: none; cursor: pointer; }
    .controls button { color: #ffffff; border-color: var(--accent); background: var(--accent); }
    .section-nav { display: flex; gap: 5px; padding: 7px 20px; overflow-x: auto; border-top: 1px solid var(--line); background: var(--surface-alt); }
    .section-nav a { min-height: 32px; display: inline-flex; flex: 0 0 auto; align-items: center; border-radius: 5px; padding: 5px 9px; color: var(--muted); font-size: 12px; font-weight: 750; text-decoration: none; }
    .section-nav a:hover { color: var(--accent); background: var(--accent-soft); }

    main { padding-block: 22px 44px; }
    .notice { margin-bottom: 18px; border: 1px solid var(--danger); border-radius: 6px; padding: 12px 14px; color: var(--danger); background: var(--danger-soft); }
    .source-section { scroll-margin-top: 145px; }
    .source-section + .source-section { margin-top: 28px; padding-top: 26px; border-top: 1px solid var(--line); }
    .section-heading { display: flex; align-items: baseline; justify-content: space-between; gap: 16px; margin-bottom: 10px; }
    .section-heading h2 { font-size: 18px; }
    .section-heading span { color: var(--muted); font-size: 12px; }
    .frame-shell { overflow: hidden; border: 1px solid var(--line); border-radius: 8px; background: var(--surface); }
    iframe { display: block; width: 100%; min-height: 720px; border: 0; background: var(--surface); }

    .metric-grid { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 10px; }
    .metric { min-width: 0; border: 1px solid var(--line); border-radius: 7px; padding: 15px; background: var(--surface); }
    .metric .key { display: inline-flex; min-height: 24px; align-items: center; border-radius: 4px; padding: 3px 7px; color: var(--blue); background: var(--blue-soft); font-size: 11px; font-weight: 850; }
    .metric strong { display: block; margin-top: 12px; font-size: 27px; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
    .metric p { margin-top: 3px; color: var(--muted); font-size: 12px; }

    @media (max-width: 980px) {
      .topbar { position: static; }
      .topbar-inner { grid-template-columns: 1fr; }
      .controls { justify-content: flex-start; }
      .source-section { scroll-margin-top: 16px; }
      .metric-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    }

    @media (max-width: 600px) {
      .topbar-inner, main { padding-inline: 12px; }
      h1 { font-size: 24px; }
      .controls { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); width: 100%; }
      .controls form { grid-column: 1 / -1; display: grid; grid-template-columns: auto minmax(0, 1fr) auto; }
      .controls input { width: 100%; }
      .section-nav { padding-inline: 12px; }
      .section-heading { align-items: flex-start; flex-direction: column; gap: 3px; }
      .metric-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .metric:last-child { grid-column: 1 / -1; }
      iframe { min-height: 640px; }
    }
  </style>
</head>
<body>
  <header class="topbar">
    <div class="topbar-inner">
      <div>
        <p class="eyebrow">Stats3 MySQL</p>
        <h1>Gesamtstatistik</h1>
      </div>
      <div class="controls">
        <form method="get">
          <label for="days">Tage</label>
          <input id="days" name="days" type="number" min="1" max="365" value="<?= stats_h($days) ?>">
          <button type="submit">Anwenden</button>
        </form>
        <a class="control-link" href="configurator.php">Configurator</a>
        <a class="control-link" href="?logout=1">Abmelden</a>
      </div>
    </div>
    <nav class="section-nav" aria-label="Statistik Bereiche">
      <a href="#pixl">Pixl Stats</a>
      <a href="#click-paths">Click Paths</a>
      <a href="#users">Users</a>
      <a href="#dashboardx2">DashboardX2</a>
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
        <iframe class="source-frame" src="pixl_stats.php?<?= stats_h($pixlQuery) ?>" title="Pixl Stats" scrolling="no"></iframe>
      </div>
    </section>

    <section class="source-section" id="click-paths" aria-labelledby="click-title">
      <div class="section-heading">
        <h2 id="click-title">Click Paths und Campaigns</h2>
        <span>checkthis.php</span>
      </div>
      <div class="frame-shell">
        <iframe class="source-frame" src="stat/checkthis.php?<?= stats_h($checkQuery) ?>" title="Click Paths und Campaign Overview" scrolling="no"></iframe>
      </div>
    </section>

    <section class="source-section" id="users" aria-labelledby="users-title">
      <div class="section-heading">
        <h2 id="users-title">Users</h2>
        <span><?= $table !== '' ? stats_h($table) . ' · ' . stats_h($days) . ' Tage' : 'dashboard.php' ?></span>
      </div>
      <div class="metric-grid">
        <article class="metric"><span class="key">Users</span><strong><?= stats_h(number_format($metrics['users'], 0, ',', '.')) ?></strong><p>Gesamte Besuche</p></article>
        <article class="metric"><span class="key">Hash</span><strong><?= stats_h(number_format($metrics['hash'], 0, ',', '.')) ?></strong><p>Eindeutige Besucher</p></article>
        <article class="metric"><span class="key">OK</span><strong><?= stats_h(number_format($metrics['ok'], 0, ',', '.')) ?></strong><p>Echte Besucher</p></article>
        <article class="metric"><span class="key">URL</span><strong><?= stats_h(number_format($metrics['url'], 0, ',', '.')) ?></strong><p>Verschiedene Seiten</p></article>
        <article class="metric"><span class="key">Min</span><strong><?= stats_h(number_format($metrics['min'], 2, ',', '.')) ?></strong><p>Durchschnitt pro Minute</p></article>
      </div>
    </section>

    <section class="source-section" id="dashboardx2" aria-labelledby="dashboardx2-title">
      <div class="section-heading">
        <h2 id="dashboardx2-title">DashboardX2</h2>
        <span>ohne Top 25 · Letzte Zugriffe</span>
      </div>
      <div class="frame-shell">
        <iframe class="source-frame" src="stat/dashboardx2.html?<?= stats_h($dashboardQuery) ?>" title="DashboardX2" scrolling="no"></iframe>
      </div>
    </section>
  </main>

  <script>
    (() => {
      "use strict";

      const frames = Array.from(document.querySelectorAll(".source-frame"));

      function resizeFrame(frame) {
        try {
          const doc = frame.contentDocument;
          if (!doc || !doc.documentElement || !doc.body) return;
          const height = Math.max(
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

      frames.forEach((frame) => {
        frame.addEventListener("load", () => observeFrame(frame));
      });
      window.setInterval(() => frames.forEach(resizeFrame), 1500);
    })();
  </script>
</body>
</html>
