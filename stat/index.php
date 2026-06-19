<?php
declare(strict_types=1);

require __DIR__ . '/../pixl_server.php';

pixl_require_stats_auth();

const STAT_INDEX_DAYS_MIN = 1;
const STAT_INDEX_DAYS_MAX = 365;
const STAT_INDEX_DAYS_DEFAULT = 30;
const STAT_INDEX_LIMIT = 8;

function stat_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function stat_days($value): int
{
    $days = (int)($value ?? STAT_INDEX_DAYS_DEFAULT);
    return max(STAT_INDEX_DAYS_MIN, min(STAT_INDEX_DAYS_MAX, $days));
}

function stat_rows(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function stat_value(PDO $pdo, string $sql, array $params = [])
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function stat_number($value): string
{
    return number_format((float)$value, 0, ',', '.');
}

function stat_percent(int $part, int $total): string
{
    if ($total <= 0) {
        return '0%';
    }
    return number_format(($part / $total) * 100, 1, ',', '.') . '%';
}

function stat_short($value, int $limit = 74): string
{
    $text = trim((string)$value);
    if ($text === '') {
        return '-';
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($text, 'UTF-8') > $limit ? mb_substr($text, 0, max(1, $limit - 1), 'UTF-8') . '...' : $text;
    }
    return strlen($text) > $limit ? substr($text, 0, max(1, $limit - 1)) . '...' : $text;
}

function stat_link(string $path, array $params = []): string
{
    $query = $params ? '?' . http_build_query($params) : '';
    return $path . $query;
}

function stat_metric(string $label, string $value, string $note = ''): string
{
    $noteHtml = $note !== '' ? '<span>' . stat_h($note) . '</span>' : '';
    return '<article class="metric"><p>' . stat_h($label) . '</p><strong>' . stat_h($value) . '</strong>' . $noteHtml . '</article>';
}

function stat_table_empty(int $columns, string $message): string
{
    return '<tr><td colspan="' . $columns . '" class="muted">' . stat_h($message) . '</td></tr>';
}

$days = stat_days($_GET['days'] ?? null);
$table = '';
$error = '';
$loaded = false;

$summary = [
    'events' => 0,
    'humans' => 0,
    'bots' => 0,
    'unique_visitors' => 0,
    'unique_pages' => 0,
    'today_events' => 0,
    'avg_reading' => 0,
    'avg_duration' => 0,
    'latest_event' => '',
];
$byDay = [];
$topPages = [];
$topReferrers = [];
$topBrowsers = [];
$recentEvents = [];

try {
    $pdo = pixl_pdo();
    pixl_ensure_schema($pdo);
    $table = pixl_table_name();

    $since = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->modify('-' . $days . ' days')
        ->format('Y-m-d H:i:s');
    $nowBerlin = new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
    $todayStartUtc = $nowBerlin->setTime(0, 0, 0)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $tomorrowStartUtc = $nowBerlin->setTime(0, 0, 0)->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

    $baseParams = [':since' => $since];
    $todayParams = [':start' => $todayStartUtc, ':end' => $tomorrowStartUtc];
    $pageExpr = pixl_sql_page_expression();
    $refExpr = pixl_sql_referrer_expression();

    $summary = [
        'events' => (int)stat_value($pdo, "SELECT COUNT(*) FROM `$table` WHERE `created_at` >= :since", $baseParams),
        'humans' => (int)stat_value($pdo, "SELECT COUNT(*) FROM `$table` WHERE `created_at` >= :since AND `is_bot` = 0", $baseParams),
        'bots' => (int)stat_value($pdo, "SELECT COUNT(*) FROM `$table` WHERE `created_at` >= :since AND `is_bot` = 1", $baseParams),
        'unique_visitors' => (int)stat_value($pdo, "SELECT COUNT(DISTINCT `visitor_hash`) FROM `$table` WHERE `created_at` >= :since AND `visitor_hash` <> ''", $baseParams),
        'unique_pages' => (int)stat_value($pdo, "SELECT COUNT(DISTINCT $pageExpr) FROM `$table` WHERE `created_at` >= :since AND $pageExpr <> ''", $baseParams),
        'today_events' => (int)stat_value($pdo, "SELECT COUNT(*) FROM `$table` WHERE `created_at` >= :start AND `created_at` < :end", $todayParams),
        'avg_reading' => (int)stat_value($pdo, "SELECT COALESCE(ROUND(AVG(`reading_score`)), 0) FROM `$table` WHERE `created_at` >= :since AND `reading_score` IS NOT NULL", $baseParams),
        'avg_duration' => (int)stat_value($pdo, "SELECT COALESCE(ROUND(AVG(`session_duration`)), 0) FROM `$table` WHERE `created_at` >= :since AND `session_duration` IS NOT NULL", $baseParams),
        'latest_event' => (string)(stat_value($pdo, "SELECT MAX(`created_at`) FROM `$table`") ?: ''),
    ];

    $byDay = stat_rows(
        $pdo,
        "SELECT DATE(`created_at`) AS label, COUNT(*) AS count, SUM(`is_bot`) AS bots
         FROM `$table`
         WHERE `created_at` >= :since
         GROUP BY DATE(`created_at`)
         ORDER BY label DESC
         LIMIT " . STAT_INDEX_LIMIT,
        $baseParams
    );

    $topPages = stat_rows(
        $pdo,
        "SELECT $pageExpr AS label, COUNT(*) AS count, SUM(`is_bot`) AS bots
         FROM `$table`
         WHERE `created_at` >= :since
         GROUP BY label
         ORDER BY count DESC
         LIMIT " . STAT_INDEX_LIMIT,
        $baseParams
    );

    $topReferrers = stat_rows(
        $pdo,
        "SELECT $refExpr AS label, COUNT(*) AS count
         FROM `$table`
         WHERE `created_at` >= :since
         GROUP BY label
         ORDER BY count DESC
         LIMIT " . STAT_INDEX_LIMIT,
        $baseParams
    );

    $topBrowsers = stat_rows(
        $pdo,
        "SELECT COALESCE(NULLIF(`browser`, ''), 'Unknown') AS label, COUNT(*) AS count
         FROM `$table`
         WHERE `created_at` >= :since
         GROUP BY label
         ORDER BY count DESC
         LIMIT " . STAT_INDEX_LIMIT,
        $baseParams
    );

    $recentEvents = stat_rows(
        $pdo,
        "SELECT `created_at`, `source`, `reason`, `title`, `hostname`, $pageExpr AS url,
                `browser`, `os`, `device`, `country`, `is_bot`, `bot_score`, `reading_score`
         FROM `$table`
         WHERE `created_at` >= :since
         ORDER BY `created_at` DESC
         LIMIT 18",
        $baseParams
    );

    $loaded = true;
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$dayMax = 1;
foreach ($byDay as $row) {
    $dayMax = max($dayMax, (int)$row['count']);
}

$links = [
    [
        'title' => 'Pixl SQL Statistik',
        'href' => stat_link('../pixl_stats.php', ['days' => $days]),
        'text' => 'Full MySQL dashboard with filters, notifications, bots, paths, user agents, and event log.',
    ],
    [
        'title' => 'DashboardX2',
        'href' => stat_link('dashboardx2.html', ['days' => $days]),
        'text' => 'Standalone HTML dashboard that reads MySQL stats from pixl_stats.php JSON.',
    ],
    [
        'title' => 'Checkthis Click Paths',
        'href' => stat_link('checkthis.php', ['days' => $days]),
        'text' => 'Level table, landing-page click path diagram, and UTM campaign overview from MySQL sessions.',
    ],
    [
        'title' => 'Dashboard.php MySQL',
        'href' => stat_link('dashboard.php', ['days' => $days]),
        'text' => 'Compatibility dashboard for /stats3/stat/dashboard.php, now backed by pixl_events in MySQL.',
    ],
    [
        'title' => 'Dashboard.php Log',
        'href' => stat_link('dashboard.php', ['action' => 'log', 'days' => $days]),
        'text' => 'Recent MySQL event log for the compatibility dashboard route.',
    ],
];
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#0f766e">
  <title>Stat Index</title>
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

    * {
      box-sizing: border-box;
      letter-spacing: 0;
    }

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

    h1,
    h2,
    h3,
    p {
      margin: 0;
    }

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

    input,
    button,
    .button {
      min-height: 2.25rem;
      border: 1px solid color-mix(in srgb, var(--line) 90%, var(--ink));
      border-radius: 6px;
      padding: 0.5rem 0.75rem;
      color: var(--ink);
      background: var(--panel);
      font: inherit;
      font-weight: 750;
    }

    input[type="number"] {
      width: 5.5rem;
    }

    button,
    .button {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      color: #fff;
      border-color: var(--accent);
      background: var(--accent);
      cursor: pointer;
      text-decoration: none;
    }

    button:hover,
    .button:hover {
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

    .quick-links,
    .metrics,
    .grid {
      display: grid;
      gap: 0.875rem;
    }

    .quick-links {
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      margin-bottom: 1.25rem;
    }

    .link-card,
    .metric,
    section {
      border: 1px solid color-mix(in srgb, var(--line) 82%, var(--accent));
      border-radius: 8px;
      background: color-mix(in srgb, var(--panel) 96%, var(--bg));
      box-shadow: 0 12px 30px rgba(18, 24, 20, 0.08);
    }

    .link-card {
      display: flex;
      min-height: 132px;
      flex-direction: column;
      justify-content: space-between;
      padding: 1rem;
      color: inherit;
      text-decoration: none;
    }

    .link-card:hover {
      border-color: color-mix(in srgb, var(--accent) 55%, var(--line));
      background: color-mix(in srgb, var(--accent) 8%, var(--panel));
    }

    .link-card h2 {
      font-size: 1rem;
      line-height: 1.25;
    }

    .link-card p {
      margin-top: 0.65rem;
      color: var(--muted);
      font-size: 0.875rem;
    }

    .link-card span {
      margin-top: 1rem;
      color: var(--accent);
      font-weight: 850;
    }

    .metrics {
      grid-template-columns: repeat(4, minmax(0, 1fr));
      margin-bottom: 1.25rem;
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

    .metric:nth-child(4n + 2)::before {
      background: var(--ok);
    }

    .metric:nth-child(4n + 3)::before {
      background: var(--bot);
    }

    .metric:nth-child(4n + 4)::before {
      background: var(--warn);
    }

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
    }

    section h2 {
      min-height: 3.1rem;
      display: flex;
      align-items: center;
      padding: 0.85rem 1rem;
      border-bottom: 1px solid var(--line);
      background: color-mix(in srgb, var(--panel) 90%, var(--bg));
      font-size: 0.95rem;
      font-weight: 850;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.875rem;
    }

    th,
    td {
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

    tr:last-child td {
      border-bottom: 0;
    }

    tbody tr:nth-child(even) {
      background: color-mix(in srgb, var(--panel) 96%, var(--bg));
    }

    tbody tr:hover {
      background: color-mix(in srgb, var(--accent) 8%, var(--panel));
    }

    .wide {
      margin-bottom: 1.25rem;
    }

    .scroll {
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
    }

    .bar {
      display: flex;
      align-items: center;
      min-width: 110px;
      width: min(100%, 280px);
      height: 8px;
      border-radius: 999px;
      background: color-mix(in srgb, var(--line) 72%, var(--panel));
      overflow: hidden;
    }

    .bar i {
      display: block;
      height: 100%;
      min-width: 2px;
      border-radius: inherit;
      background: linear-gradient(90deg, var(--accent), var(--ok));
    }

    .badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 1.5rem;
      padding: 0.125rem 0.5rem;
      border-radius: 6px;
      color: #fff;
      background: var(--ok);
      font-size: 0.75rem;
      font-weight: 850;
    }

    .badge.bot {
      background: var(--bot);
    }

    .muted {
      color: var(--muted);
    }

    .mono {
      font-family: ui-monospace, "SF Mono", Monaco, "Cascadia Code", monospace;
      font-size: 0.8125rem;
    }

    .event-table {
      min-width: 1060px;
    }

    .event-title {
      font-weight: 750;
    }

    .event-url {
      color: var(--accent);
      font-weight: 700;
    }

    :focus-visible {
      outline: 2px solid var(--accent);
      outline-offset: 2px;
    }

    @media (max-width: 980px) {
      .header-inner {
        grid-template-columns: 1fr;
      }

      form {
        width: 100%;
      }

      .metrics,
      .grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }

    @media (max-width: 640px) {
      header {
        position: static;
      }

      h1 {
        font-size: 1.55rem;
      }

      form,
      .metrics,
      .grid {
        grid-template-columns: 1fr;
      }

      input[type="number"],
      button,
      .button {
        width: 100%;
      }

      .metrics,
      .grid {
        display: grid;
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body>
  <header role="banner">
    <div class="header-inner">
      <div>
        <span class="eyebrow">MySQL Analytics</span>
        <h1>Stat Index</h1>
        <div class="meta">
          <?= $loaded ? 'Tabelle ' . stat_h($table) . ' · ' . stat_h((string)$days) . ' Tage · Letztes Event ' . stat_h($summary['latest_event'] !== '' ? $summary['latest_event'] : '-') : 'Pixl Statistik Uebersicht' ?>
        </div>
      </div>
      <form method="get" aria-label="Zeitraum">
        <label for="days">Tage</label>
        <input id="days" name="days" type="number" min="<?= STAT_INDEX_DAYS_MIN ?>" max="<?= STAT_INDEX_DAYS_MAX ?>" value="<?= stat_h($days) ?>">
        <button type="submit">Aktualisieren</button>
        <a class="button secondary" href="?logout=1">Logout</a>
      </form>
    </div>
  </header>

  <main role="main">
    <?php if ($error !== ''): ?>
      <div class="notice">MySQL Statistik nicht verfuegbar: <?= stat_h($error) ?></div>
    <?php endif; ?>

    <nav class="quick-links" aria-label="Statistik Dashboards">
      <?php foreach ($links as $link): ?>
        <a class="link-card" href="<?= stat_h($link['href']) ?>">
          <div>
            <h2><?= stat_h($link['title']) ?></h2>
            <p><?= stat_h($link['text']) ?></p>
          </div>
          <span>Oeffnen</span>
        </a>
      <?php endforeach; ?>
    </nav>

    <?php if ($loaded): ?>
      <div class="metrics" aria-label="MySQL Zusammenfassung">
        <?= stat_metric('Events', stat_number($summary['events']), 'im Zeitraum') ?>
        <?= stat_metric('Menschen', stat_number($summary['humans']), stat_percent($summary['humans'], $summary['events'])) ?>
        <?= stat_metric('Bots', stat_number($summary['bots']), stat_percent($summary['bots'], $summary['events'])) ?>
        <?= stat_metric('Heute', stat_number($summary['today_events']), 'Europe/Berlin') ?>
        <?= stat_metric('Besucher', stat_number($summary['unique_visitors']), 'unique visitor hashes') ?>
        <?= stat_metric('Seiten', stat_number($summary['unique_pages']), 'unique URLs/Pfade') ?>
        <?= stat_metric('Reading Score', stat_number($summary['avg_reading']), 'Durchschnitt') ?>
        <?= stat_metric('Sessiondauer', stat_number($summary['avg_duration']) . 's', 'Durchschnitt') ?>
      </div>

      <div class="grid">
        <section aria-label="Events pro Tag">
          <h2>Letzte Tage</h2>
          <table>
            <thead>
              <tr>
                <th>Tag</th>
                <th>Events</th>
                <th>Bots</th>
                <th>Verlauf</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($byDay as $row): ?>
                <tr>
                  <td class="mono"><?= stat_h($row['label']) ?></td>
                  <td><?= stat_h(stat_number($row['count'])) ?></td>
                  <td><?= stat_h(stat_number($row['bots'])) ?></td>
                  <td><span class="bar"><i style="width: <?= (int)round(((int)$row['count'] / $dayMax) * 100) ?>%"></i></span></td>
                </tr>
              <?php endforeach; ?>
              <?= $byDay === [] ? stat_table_empty(4, 'Noch keine Tagesdaten.') : '' ?>
            </tbody>
          </table>
        </section>

        <section aria-label="Top Browser">
          <h2>Top Browser</h2>
          <table>
            <thead>
              <tr>
                <th>Browser</th>
                <th>Events</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($topBrowsers as $row): ?>
                <tr>
                  <td><?= stat_h($row['label']) ?></td>
                  <td><?= stat_h(stat_number($row['count'])) ?></td>
                </tr>
              <?php endforeach; ?>
              <?= $topBrowsers === [] ? stat_table_empty(2, 'Noch keine Browserdaten.') : '' ?>
            </tbody>
          </table>
        </section>

        <section aria-label="Top Seiten">
          <h2>Top Seiten</h2>
          <table>
            <thead>
              <tr>
                <th>URL/Pfad</th>
                <th>Events</th>
                <th>Bots</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($topPages as $row): ?>
                <tr>
                  <td title="<?= stat_h($row['label']) ?>"><?= stat_h(stat_short($row['label'], 92)) ?></td>
                  <td><?= stat_h(stat_number($row['count'])) ?></td>
                  <td><?= stat_h(stat_number($row['bots'])) ?></td>
                </tr>
              <?php endforeach; ?>
              <?= $topPages === [] ? stat_table_empty(3, 'Noch keine Seitendaten.') : '' ?>
            </tbody>
          </table>
        </section>

        <section aria-label="Top Referrer">
          <h2>Top Referrer</h2>
          <table>
            <thead>
              <tr>
                <th>Referrer</th>
                <th>Events</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($topReferrers as $row): ?>
                <tr>
                  <td title="<?= stat_h($row['label']) ?>"><?= stat_h(stat_short($row['label'], 92)) ?></td>
                  <td><?= stat_h(stat_number($row['count'])) ?></td>
                </tr>
              <?php endforeach; ?>
              <?= $topReferrers === [] ? stat_table_empty(2, 'Noch keine Referrerdaten.') : '' ?>
            </tbody>
          </table>
        </section>
      </div>

      <section class="wide scroll" aria-label="Letzte Events">
        <h2>Letzte MySQL Events</h2>
        <table class="event-table">
          <thead>
            <tr>
              <th>Zeit</th>
              <th>Typ</th>
              <th>Quelle</th>
              <th>Event</th>
              <th>Titel</th>
              <th>URL</th>
              <th>Client</th>
              <th>Land</th>
              <th>Score</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recentEvents as $row): ?>
              <?php $isBot = (int)$row['is_bot'] === 1; ?>
              <tr>
                <td class="mono"><?= stat_h($row['created_at']) ?></td>
                <td><span class="badge <?= $isBot ? 'bot' : '' ?>"><?= $isBot ? 'Bot' : 'Human' ?></span></td>
                <td><?= stat_h($row['source']) ?></td>
                <td><?= stat_h($row['reason']) ?></td>
                <td class="event-title"><?= stat_h(stat_short($row['title'], 52)) ?></td>
                <td class="event-url" title="<?= stat_h($row['url']) ?>"><?= stat_h(stat_short($row['url'], 70)) ?></td>
                <td><?= stat_h(trim((string)$row['browser'] . ' / ' . (string)$row['os'] . ' / ' . (string)$row['device'], ' /')) ?></td>
                <td><?= stat_h($row['country'] !== '' ? $row['country'] : '-') ?></td>
                <td><?= stat_h($row['reading_score'] ?? '-') ?></td>
              </tr>
            <?php endforeach; ?>
            <?= $recentEvents === [] ? stat_table_empty(9, 'Noch keine Events im Zeitraum.') : '' ?>
          </tbody>
        </table>
      </section>
    <?php endif; ?>
  </main>
</body>
</html>
