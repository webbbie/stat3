<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
stat4_require_admin();
$config = require __DIR__ . '/config.php';
$title = htmlspecialchars($config['dashboard']['title'] ?? 'Website Statistik', ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="color-scheme" content="dark">
  <title><?= $title ?></title>
  <link rel="stylesheet" href="styles.css">
  <link rel="stylesheet" href="admin.css">
</head>
<body>
<header class="topbar">
  <div><p class="eyebrow">MYSQL ANALYTICS</p><h1><?= $title ?></h1></div>
  <div class="toolbar">
    <span id="status" class="status"><i></i> Wird geladen</span>
    <div class="range-filter" role="group" aria-label="Zeitraum auswählen">
      <button class="range-button" type="button" data-range="60min">60min</button>
      <button class="range-button" type="button" data-range="24h">24h</button>
      <button class="range-button active" type="button" data-range="1day">1day</button>
      <label class="days-filter"><span class="sr-only">Rollierender Zeitraum</span><select id="daysRange" aria-label="Letzte 1 bis 7 Tage"><option value="">Letzte 1–7 Tage</option><option value="last1d">Letzter 1 Tag</option><option value="last2d">Letzte 2 Tage</option><option value="last3d">Letzte 3 Tage</option><option value="last4d">Letzte 4 Tage</option><option value="last5d">Letzte 5 Tage</option><option value="last6d">Letzte 6 Tage</option><option value="last7d">Letzte 7 Tage</option></select></label>
    </div>
    <button id="refresh" type="button">Aktualisieren</button>
    <a class="header-link" href="configurator.php">Konfiguration</a>
    <a class="header-link" href="systemcheck.php">Systemcheck</a>
    <a class="header-link" href="reset.php">Reset</a>
    <a class="header-link" href="logout.php">Abmelden</a>
  </div>
</header>
<main>
  <section class="metric-grid five" id="primaryMetrics" aria-label="Hauptkennzahlen"></section>

  <section class="panel row-two"><div class="panel-head"><div><span class="row-number">02</span><h2>Verlauf im Zeitraum</h2></div><span>Besucherentwicklung</span></div><div class="trend-layout"><div class="chart tall"><canvas id="trendChart"></canvas></div><div class="mini-breakdowns" id="breakdowns"></div></div></section>

  <section class="panel"><div class="panel-head"><div><span class="row-number">03</span><h2>Technische Merkmale</h2></div><span>Besucher nach Umgebung</span></div><div class="detail-grid" id="details"></div></section>

  <section class="split-row"><article class="panel"><div class="panel-head"><div><span class="row-number">04</span><h2>Top Pfade</h2></div><span>Alle Aufrufe · Query-Strings entfernt</span></div><div class="table-wrap"><table><thead><tr><th>URL</th><th>Aufrufe</th></tr></thead><tbody id="paths"></tbody></table></div></article><article class="panel"><div class="panel-head"><div><span class="row-number">05</span><h2>Top 10 User-Agents</h2></div><span>Unique Besucher</span></div><ol class="ua-list" id="userAgents"></ol></article></section>

  <section class="panel"><div class="panel-head"><div><span class="row-number">06</span><h2>Unique Benutzer &amp; Interaktion</h2></div><span>Reloads und Doppelaufrufe entfernt</span></div><div class="metric-grid seven compact" id="sessionMetrics"></div></section>
  <section class="panel"><div class="panel-head"><div><span class="row-number">07</span><h2>Level Table</h2></div><span>Maximale Scrolltiefe pro Unique Besucher</span></div><div class="table-wrap"><table><thead><tr><th>Level</th><th>Unique Besucher</th><th>Ø aktiv</th></tr></thead><tbody id="levels"></tbody></table></div></section>
  <section class="panel"><div class="panel-head"><div><span class="row-number">08</span><h2>Campaign UTM Overview</h2></div><span>Unique Besucher</span></div><div class="table-wrap"><table><thead><tr><th>Kampagne</th><th>Source</th><th>Medium</th><th>Besucher</th><th>Klickende Besucher</th></tr></thead><tbody id="campaigns"></tbody></table></div></section>
  <section class="panel live-panel"><div class="panel-head"><div><span class="row-number">09</span><h2>Letzte 60 Minuten</h2></div><span class="live-label">LIVE</span></div><div class="metric-grid seven compact" id="liveMetrics"></div></section>
  <section class="panel"><div class="panel-head"><div><span class="row-number">10</span><h2>Besucher / Minute</h2></div><span>Letzte 60 Minuten</span></div><div class="chart"><canvas id="minuteChart"></canvas></div></section>
  <section class="panel"><div class="panel-head"><div><span class="row-number">11</span><h2>Besucher &amp; Akquise</h2></div></div><div class="chart-grid three"><div><h3>Besucher pro Tag</h3><canvas id="dailyChart"></canvas></div><div><h3>Top Referrer</h3><canvas id="referrerChart"></canvas></div><div><h3>Browser</h3><canvas id="browserChart"></canvas></div></div></section>
  <section class="panel"><div class="panel-head"><div><span class="row-number">12</span><h2>Technik-Diagramme</h2></div></div><div class="chart-grid three"><div><h3>Betriebssystem</h3><canvas id="osChart"></canvas></div><div><h3>Sprachen</h3><canvas id="languageChart"></canvas></div><div><h3>Bildschirmauflösungen</h3><canvas id="screenChart"></canvas></div></div></section>
  <section class="panel forecast-panel"><div class="panel-head"><div><span class="row-number">13</span><h2>Unique Benutzer Forecast</h2></div><span>Hochrechnung · Bots ausgeschlossen</span></div><div class="metric-grid three" id="forecastMetrics"></div></section>
</main>
<footer>STAT4 · datensparsame Besucher- und Ereignisstatistik · <a href="https://db-ip.com" rel="external">IP Geolocation by DB-IP</a></footer>
<script src="dashboard.js"></script>
</body>
</html>
