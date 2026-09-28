<?php
declare(strict_types=1);
require_once __DIR__ . '/pixl_server.php';

header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
$dataKind = $_GET['data'] ?? '';
if (in_array($dataKind, ['1', 'useragents', 'useragent_details'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $password = (string)(pixl_config()['stats_password'] ?? '');
        $cookie = (string)($_COOKIE[pixl_stats_cookie_name()] ?? '');
        if ($password !== '' && ($cookie === '' || !hash_equals(pixl_stats_auth_token(), $cookie))) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'Die Anmeldung ist abgelaufen. Bitte UA neu öffnen und anmelden.']);
            exit;
        }
        if ($dataKind === '1') {
            require_once __DIR__ . '/live_data.php';
            $data = ua_live_snapshot(pixl_pdo(), time(), pixl_table_name());
        } else {
            require_once __DIR__ . '/useragents_data.php';
            $range = is_string($_GET['range'] ?? null) ? $_GET['range'] : '24h';
            $now = time();
            if ($dataKind === 'useragents') {
                $data = ua_agents_overview(pixl_pdo(), $now, $range);
            } else {
                if (!isset($_GET['campaign']) || !is_string($_GET['campaign']) || mb_strlen($_GET['campaign']) > 191
                    || (isset($_GET['q']) && (!is_string($_GET['q']) || mb_strlen($_GET['q']) > 200))) {
                    http_response_code(400);
                    echo json_encode(['ok' => false, 'error' => 'Ungültiger Kampagnenname oder Suchtext.']);
                    exit;
                }
                $at = filter_var($_GET['at'] ?? null, FILTER_VALIDATE_INT);
                if ($at !== false && $at !== null && $at <= $now && $at >= $now - 86400) $now = $at;
                $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
                $data = ua_agents_details(pixl_pdo(), $now, $range, $_GET['campaign'], $page === false ? 1 : $page,
                    trim($_GET['q'] ?? ''), ($_GET['unknown'] ?? '') === '1');
            }
        }
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        error_log('stats3 UA live: ' . $error->getMessage());
        http_response_code(503);
        echo json_encode(['ok' => false, 'error' => 'UA-Daten sind derzeit nicht erreichbar. Bitte Datenbank und STAT4-Einrichtung prüfen.']);
    }
    exit;
}
pixl_require_stats_auth();
$userAgentsView = ($_GET['view'] ?? '') === 'useragents';
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light dark">
  <title>UA · <?= $userAgentsView ? 'UserAgents' : 'Live' ?></title>
  <script>
    try {
      const saved = localStorage.getItem('stats3_theme');
      document.documentElement.dataset.theme = saved === 'dark' || saved === 'light' ? saved : (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    } catch (_) { document.documentElement.dataset.theme = 'light'; }
  </script>
  <link rel="stylesheet" href="live.css">
  <?php if ($userAgentsView): ?><link rel="stylesheet" href="useragents.css"><?php endif; ?>
  <script src="<?= $userAgentsView ? 'useragents.js' : 'live.js' ?>" defer></script>
</head>
<body>
  <header class="topbar">
    <div class="topbar-inner">
      <div class="brand"><a href="live.php" aria-label="UA Live Startseite">UA</a><span>Statistik</span></div>
      <nav class="areas" aria-label="UA Bereiche">
        <a href="live.php"<?= !$userAgentsView ? ' aria-current="page"' : '' ?>><span class="live-dot" aria-hidden="true"></span>Live</a>
        <a href="live.php?view=useragents"<?= $userAgentsView ? ' aria-current="page"' : '' ?>>UserAgents</a>
      </nav>
      <div class="header-actions"><a href="stats.php">Stats3</a><button id="themeToggle" type="button" aria-pressed="false">Dark Mode</button></div>
    </div>
  </header>
  <?php if ($userAgentsView): require __DIR__ . '/useragents_view.php'; else: ?>
  <main>
    <div class="page-heading">
      <div><p class="eyebrow">UA / LIVE</p><h1>Dein Traffic. Im Moment.</h1><p class="subtle">Besucher, Seitenaufrufe und Kampagnen · Europe/Berlin</p></div>
      <div class="connection"><span id="connectionDot" class="status-dot" aria-hidden="true"></span><span id="connectionStatus" role="status">Live-Daten werden geladen …</span><button id="pauseButton" type="button" aria-pressed="false">Pausieren</button></div>
    </div>
    <div id="errorNotice" class="notice error" role="alert" hidden></div>
    <div id="warningNotice" class="notice" hidden></div>
    <noscript><p class="notice">Bitte JavaScript aktivieren, um die Live-Statistik und die Diagramme anzuzeigen.</p></noscript>
    <section class="metrics" aria-label="Letzte 24 Stunden">
      <article><p><span class="legend-dot users"></span>Unique User <span>24h</span></p><strong id="totalUsers">–</strong><small>unterschiedliche Besucher</small></article>
      <article><p><span class="legend-dot impressions"></span>Impressionen <span>24h</span></p><strong id="totalImpressions">–</strong><small>alle Seitenaufrufe</small></article>
      <article><p>Verschiedene Seiten <span>24h</span></p><strong id="totalPages">–</strong><small>Host + Pfad ohne URL-Parameter</small></article>
      <article><p><span class="legend-dot pushes"></span>Pushover <span>24h</span></p><strong id="totalPushes">–</strong><small>bestätigte Nachrichten</small></article>
    </section>
    <section class="panel" aria-labelledby="historyTitle">
      <div class="panel-heading"><div><p class="eyebrow">VERLAUF</p><h2 id="historyTitle">Die letzten 24 Stunden</h2><p id="historyRange" class="subtle">24 Intervalle mit jeweils 60 Minuten</p></div><div class="legend"><span><i class="legend-dot users"></i>Unique User</span><span><i class="legend-dot impressions"></i>Impressionen</span></div></div>
      <div class="history-chart chart-shell"><svg id="historyChart" viewBox="0 0 1120 250" role="img" aria-label="Stündlicher Verlauf wird geladen"></svg><p id="historyEmpty" class="chart-empty" hidden>Noch keine Seitenaufrufe in den letzten 24 Stunden.</p></div>
      <details class="hourly-details"><summary>Stundenwerte als Tabelle</summary><div class="table-scroll"><table><caption class="sr-only">24 Stundenintervalle, Berliner Zeit</caption><thead><tr><th scope="col">Zeitraum</th><th scope="col">Unique User</th><th scope="col">Impressionen</th></tr></thead><tbody id="hourlyRows"></tbody></table></div></details>
    </section>
    <section class="panel traffic-panel" aria-labelledby="trafficTitle">
      <div class="panel-heading"><div><p class="eyebrow">LIVE TRAFFIC</p><h2 id="trafficTitle">Der Puls deiner Seite</h2><p class="subtle">Letzte 2 Minuten · ein Ausschlag pro Ereignis · Aktualisierung alle 5 Sekunden</p></div><div class="push-control"><button id="pushLight" type="button" class="push-light" aria-label="Pushover: noch keine Daten" title="Bestätigte Pushover-Sendungen; klicken öffnet Mind"><span aria-hidden="true"></span></button><div><strong id="pushToday">–</strong><span>Pushover heute</span></div></div></div>
      <div class="traffic-legend"><span class="impressions-text">↑ Seitenaufruf</span><span class="users-text">↓ Neuer User</span><span id="pulseCounts" class="subtle">Warte auf Daten …</span></div>
      <div class="heartbeat-chart chart-shell"><svg id="heartbeatChart" viewBox="0 0 1120 190" role="img" aria-label="Live-Traffic wird geladen"></svg></div>
      <div class="traffic-footer"><span>−2 Minuten</span><span id="pushStatus">Pushover leuchtet bei neu bestätigten Sendungen auf.</span><span>Jetzt</span></div>
    </section>
    <section class="panel" aria-labelledby="latestTitle">
      <div class="panel-heading"><div><p class="eyebrow">SEITENAUFRUFE</p><h2 id="latestTitle">Die letzten 5 Impressionen</h2></div><span class="subtle">Aktualisierung alle 5 Sekunden</span></div>
      <ol id="latestList" class="latest-list"><li class="empty-state">Seitenaufrufe werden geladen …</li></ol>
    </section>
    <section class="panel campaigns-panel" aria-labelledby="campaignTitle">
      <div class="panel-heading"><div><p class="eyebrow">KAMPAGNEN</p><h2 id="campaignTitle">Campaignsource &amp; Campaignname</h2><p class="subtle">Heute seit 00:00 Uhr · laufende Stunde · zurückliegende 24 Stunden</p></div><label class="search"><span class="sr-only">Kampagnen durchsuchen</span><input id="campaignSearch" type="search" placeholder="Quelle oder Kampagne suchen …"></label></div>
      <p id="campaignCount" class="subtle campaign-count">Kampagnen werden geladen …</p>
      <div class="table-scroll campaign-scroll" tabindex="0" role="region" aria-label="Kampagnenkennzahlen, bei Bedarf horizontal scrollen">
        <table class="campaign-table"><caption class="sr-only">Besucher, Impressionen, verschiedene Seiten und Pushover-Sendungen pro Quelle und Kampagne</caption><thead><tr>
          <th scope="col">Campaignsource</th><th scope="col">Campaignname</th>
          <th scope="col">User<span>pro Tag</span></th><th scope="col">Impressionen<span>pro Tag</span></th>
          <th scope="col">User<span>pro Stunde</span></th><th scope="col">Impressionen<span>pro Stunde</span></th>
          <th scope="col">Verschiedene Seiten<span>pro Tag</span></th><th scope="col">Pushover gesendet<span>pro Tag</span></th>
          <th scope="col">User<span>pro 24h</span></th><th scope="col">Impressionen<span>pro 24h</span></th>
          <th scope="col">Verschiedene Seiten<span>pro 24h</span></th><th scope="col">Pushover gesendet<span>pro 24h</span></th>
        </tr></thead><tbody id="campaignRows"><tr><td colspan="12" class="empty-state">Live-Daten werden geladen …</td></tr></tbody></table>
      </div>
      <p class="table-note">User werden je Zeitraum und Kampagne einmal gezählt. Ein User kann mehrere Kampagnen besuchen; die Summe der Kampagnen-User kann deshalb höher sein als die Gesamtzahl.</p>
    </section>
    <details class="definitions"><summary>So werden die Werte gezählt</summary><p>Impressionen sind einzelne STAT4-Seitenaufrufe, einschließlich erneuter Aufrufe. Klicks, technische Heartbeats und erkannte Bots werden nicht mitgezählt. Unique User sind unterschiedliche Besucherkennungen. Der Ausschlag nach unten markiert den ersten gespeicherten Seitenaufruf einer Besucherkennung.</p><p>Die Kampagnenzuordnung der Seitenaufrufe stammt aus Quelle und Name der Sitzung (utm_source / utm_campaign). Verschiedene Seiten werden über Host und Pfad ohne Query oder Fragment unterschieden. Pushover zählt bestätigte Nachrichten aus Stats3 und STAT4; Teilnachrichten zählen einzeln, importierte Kopien werden nicht doppelt gezählt. Fehlt eine belastbare Kampagnenzuordnung, steht die Sendung unter „nicht zuordenbar“.</p></details>
    <footer class="page-footer"><span>UA · Live</span><span id="lastUpdated">Noch nicht aktualisiert</span><a href="stats.php">Zur Gesamtstatistik</a></footer>
  </main>
  <?php endif; ?>
</body>
</html>
