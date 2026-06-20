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
    'minutes_per_visitor' => 0.0,
];
$since = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
    ->modify('-' . $days . ' days')
    ->format('Y-m-d H:i:s');
$lastThreeHours = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
    ->modify('-3 hours')
    ->format('Y-m-d H:i:s');

try {
    $pdo = pixl_pdo();
    pixl_ensure_schema($pdo);
    $table = pixl_table_name();
    $pageExpr = pixl_sql_page_expression();
    $metrics = [
        'users' => (int)stats_scalar(
            $pdo,
            "SELECT COUNT(*) FROM `$table` WHERE `created_at` >= :users_since",
            [':users_since' => $since]
        ),
        'hash' => (int)stats_scalar(
            $pdo,
            "SELECT COUNT(DISTINCT `visitor_hash`) FROM `$table`
             WHERE `created_at` >= :hash_since AND `visitor_hash` <> ''",
            [':hash_since' => $since]
        ),
        'ok' => (int)stats_scalar(
            $pdo,
            "SELECT COUNT(*) FROM `$table` WHERE `created_at` >= :ok_since AND `is_bot` = 0",
            [':ok_since' => $since]
        ),
        'url' => (int)stats_scalar(
            $pdo,
            "SELECT COUNT(DISTINCT $pageExpr) FROM `$table` WHERE `created_at` >= :url_since",
            [':url_since' => $since]
        ),
        'min' => round((float)stats_scalar(
            $pdo,
            "SELECT COALESCE(AVG(`events_per_minute`), 0)
             FROM (
               SELECT COUNT(*) AS `events_per_minute`
               FROM `$table`
               WHERE `created_at` >= :minute_since
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
               WHERE `created_at` >= :visitor_interval_since AND `visitor_hash` <> ''
               GROUP BY `visitor_hash`
             ) AS `unique_visitors`",
            [':visitor_interval_since' => $lastThreeHours]
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
    'embed' => 1,
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
    button, input { font: inherit; }
    :focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }

    .topbar { position: sticky; z-index: 10; top: 0; border-bottom: 1px solid var(--line); background: var(--surface); }
    .topbar-inner, main { width: min(1440px, 100%); margin-inline: auto; padding-inline: 20px; }
    .topbar-inner { min-height: 92px; display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 20px; align-items: center; padding-block: 14px; }
    h1, h2, p { margin: 0; }
    h1 { font-size: 28px; line-height: 1.15; }
    .main-since { margin-top: 4px; color: var(--muted); font-size: 12px; }
    .controls { display: flex; align-items: center; justify-content: flex-end; gap: 8px; flex-wrap: wrap; }
    .controls label { color: var(--ink); font-size: 12px; font-weight: 750; }
    .controls button { min-height: 36px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid var(--line); border-radius: 6px; padding: 7px 10px; color: var(--ink); background: var(--surface-alt); font-weight: 750; cursor: pointer; }
    .controls button { color: #ffffff; border-color: var(--accent); background: var(--accent); }
    .controls .secondary, .controls .theme-toggle { color: var(--ink); border-color: var(--line); background: var(--surface-alt); }
    .main-notify-status { max-width: 320px; color: var(--muted); font-size: 12px; }
    .main-mute { min-height: 36px; display: inline-flex; align-items: center; gap: 5px; border: 1px solid var(--line); border-radius: 6px; padding: 7px 10px; background: var(--surface-alt); }
    .main-mute input { width: 16px; height: 16px; margin: 0; accent-color: var(--accent); }

    main { padding-block: 22px 44px; }
    .notice { margin-bottom: 18px; border: 1px solid var(--danger); border-radius: 6px; padding: 12px 14px; color: var(--danger); background: var(--danger-soft); }
    .source-section { scroll-margin-top: 145px; }
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
      .controls button, .controls .main-mute { width: 100%; }
      .main-notify-status { grid-column: 1 / -1; max-width: none; }
      .section-heading { align-items: flex-start; flex-direction: column; gap: 3px; }
      .metric-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      iframe { min-height: 640px; }
    }
  </style>
</head>
<body>
  <header class="topbar">
    <div class="topbar-inner">
      <div>
        <h1>Pixl SQL Statistik</h1>
        <p class="main-since">Seit <?= stats_h($since) ?> UTC</p>
      </div>
      <div class="controls">
        <button id="statsNotificationButton" type="button">Browser-Notifikation aktivieren</button>
        <button class="secondary" id="statsNotificationOffButton" type="button">Browser-Notifikation ausschalten</button>
        <span class="main-notify-status" id="statsNotificationStatus">Aus</span>
        <label class="main-mute" for="statsNotificationMute">
          <input id="statsNotificationMute" type="checkbox">
          Ton aus
        </label>
        <button class="theme-toggle" id="statsThemeToggle" type="button" aria-pressed="false">Dark Mode</button>
      </div>
    </div>
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
        <article class="metric"><span class="key">Min</span><strong><?= stats_h(number_format($metrics['min'], 2, ',', '.')) ?></strong><p>Events pro aktiver Minute</p></article>
        <article class="metric"><span class="key">1 Besucher</span><strong><?= stats_h(number_format($metrics['minutes_per_visitor'], 2, ',', '.')) ?> Min</strong><p>Ø bis zum nächsten eindeutigen Besucher · letzte 3 Std.</p></article>
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
      const pixlFrame = document.getElementById("pixlStatsFrame");
      const themeToggle = document.getElementById("statsThemeToggle");
      const notificationButton = document.getElementById("statsNotificationButton");
      const notificationOffButton = document.getElementById("statsNotificationOffButton");
      const notificationStatus = document.getElementById("statsNotificationStatus");
      const notificationMute = document.getElementById("statsNotificationMute");

      function pixlControl(id) {
        try {
          return pixlFrame.contentDocument && pixlFrame.contentDocument.getElementById(id);
        } catch (error) {
          return null;
        }
      }

      function syncNotificationControls() {
        const childStatus = pixlControl("pixlNotificationStatus");
        const childOff = pixlControl("pixlNotificationOffButton");
        const childMute = pixlControl("pixlNotificationMute");
        if (childStatus) notificationStatus.textContent = childStatus.textContent || "Aus";
        if (childOff) notificationOffButton.disabled = childOff.disabled;
        if (childMute) notificationMute.checked = childMute.checked;
      }

      notificationButton.addEventListener("click", () => {
        const childButton = pixlControl("pixlNotificationButton");
        if (childButton) childButton.click();
      });
      notificationOffButton.addEventListener("click", () => {
        const childButton = pixlControl("pixlNotificationOffButton");
        if (childButton) childButton.click();
      });
      notificationMute.addEventListener("change", () => {
        const childMute = pixlControl("pixlNotificationMute");
        if (!childMute || !pixlFrame.contentWindow) return;
        childMute.checked = notificationMute.checked;
        childMute.dispatchEvent(new pixlFrame.contentWindow.Event("change", { bubbles: true }));
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
        frame.addEventListener("load", () => {
          observeFrame(frame);
          syncFrameTheme(frame, currentTheme());
          if (frame === pixlFrame) syncNotificationControls();
        });
      });
      themeToggle.addEventListener("click", () => {
        applyTheme(currentTheme() === "dark" ? "light" : "dark");
      });
      applyTheme(currentTheme(), false);
      window.setInterval(() => {
        frames.forEach(resizeFrame);
        syncNotificationControls();
      }, 1000);
    })();
  </script>
</body>
</html>
