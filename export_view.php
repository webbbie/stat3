<?php
declare(strict_types=1);
if (!isset($metadata, $offline, $initialTable, $initialPage)) { http_response_code(404); exit; }
$data = $metadata['summary'];
$sources = $data['sources'];
$at = $data['window']['to_unix'];
$h = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$n = static fn(?int $value): string => $value === null ? '–' : number_format($value, 0, ',', '.');
$date = static fn(?string $value): string => $value === null ? '–' : (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('d.m.Y. H:i:s T');
$asset = static function (string $name): string {
    $content = @file_get_contents(__DIR__ . '/' . $name);
    if ($content === false) throw new RuntimeException('HTML-Bestandteil fehlt: ' . $name);
    return $content;
};
$installed = count(array_filter($sources, static fn(array $s): bool => $s['available']));
$eventRows = array_sum(array_column(array_filter($sources, static fn(array $s): bool => $s['kind'] === 'events'), 'rows'));
?>
<!doctype html>
<html lang="de" id="top" data-offline="<?= $offline ? 'true' : 'false' ?>" data-initial-range="3m" data-initial-table="<?= $h($initialTable) ?>" data-initial-page="<?= max(1, $initialPage) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light dark">
  <meta http-equiv="Content-Security-Policy" content="default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data:; connect-src 'none'; base-uri 'none'; form-action 'none'">
  <title>Export · Stats3 HTML-Datenarchiv</title>
  <script>
    try { const t = localStorage.getItem('stats3_theme'); document.documentElement.dataset.theme = t === 'light' || t === 'dark' ? t : (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'); } catch (_) {}
  </script>
  <style><?= $asset('export.css') ?></style>
</head>
<body>
  <header class="topbar"><div class="topbar-inner">
    <a class="brand" href="#top">Stats3</a>
    <nav aria-label="Archivbereiche"><a href="#inventory-title">Datenquellen</a><a href="#statistics-title">Statistiken</a><a class="server-link" href="stats.php">Zur Statistik</a></nav>
    <button id="themeToggle" type="button" aria-pressed="false">Dark Mode</button>
  </div></header>
  <main>
    <div class="page-heading"><div><p class="eyebrow">STATISTIK / STATISCHES HTML-ARCHIV</p><h1>Export &amp; Daten-Backup</h1>
      <p class="muted">Alle Statistikdaten im HTML gespeichert · Filter und Details funktionieren offline.</p></div>
      <a class="button server-link" href="export.php">Neuen Stand laden</a>
    </div>
    <section class="backup-panel" aria-labelledby="backup-title">
      <div><h2 id="backup-title">Vollständiges HTML-Archiv</h2>
        <p>Gesicherter Zeitraum: <strong><?= $h($date($data['window']['from'])) ?></strong> bis <strong><?= $h($date($data['window']['to'])) ?></strong></p>
        <p class="muted">Alle <?= $n($data['total_rows']) ?> Datensätze der installierten Quellen sind in dieser Datei enthalten. Die Downloads behalten immer den vollständigen 90-Tage-Bestand.</p>
      </div>
      <div class="download-actions">
        <a id="htmlDownload" class="button primary" href="<?= $offline ? '#top' : 'export.php?download=html&amp;at=' . $at ?>" download>HTML-Bericht herunterladen</a>
        <a id="backupDownload" class="button" href="<?= $offline ? '#top' : 'export.php?download=json&amp;at=' . $at ?>" download>JSON-Backup herunterladen</a>
        <a id="csvDownload" class="button" href="<?= $offline ? '#top' : 'export.php?download=csv&amp;at=' . $at ?>" download="export.csv">Export</a>
        <span class="muted">Export = CSV für Tabellenprogramme</span>
      </div>
      <p class="backup-description">HTML enthält die vollständigen Rohdaten samt Tabellenstruktur, Gestaltung und Filterlogik in einer einzigen Datei. Nach dem Speichern im Browser öffnen. Auch JSON und CSV lassen sich dann direkt aus der Datei herunterladen.</p>
    </section>
    <section class="panel time-panel" aria-labelledby="filter-title">
      <h2 id="filter-title">Zeitraum anzeigen</h2>
      <div class="time-filters" role="group" aria-label="Statischer Zeitraumfilter">
        <?php foreach (['60min'=>'60 Minuten','24h'=>'24 Stunden','7d'=>'7 Tage','14d'=>'14 Tage','1m'=>'1 Monat','2m'=>'2 Monate','3m'=>'3 Monate'] as $value=>$label): ?>
        <button class="range-button" data-range="<?= $value ?>" type="button" aria-pressed="<?= $value === '3m' ? 'true' : 'false' ?>" disabled><?= $label ?></button>
        <?php endforeach; ?>
      </div>
      <p id="filterPeriod">Anzeige: <?= $h($date($data['window']['from'])) ?> bis <?= $h($date($data['window']['to'])) ?></p>
      <p class="muted">1 Monat = 30 Tage · 2 Monate = 60 Tage · 3 Monate = 90 Tage. Alle Zeiträume enden am gespeicherten Stand. Zugehörige Stammdaten und aktuelle CAPTCHA-Zähler bleiben entsprechend gekennzeichnet.</p>
      <p id="filterStatus" class="muted" role="status" aria-live="polite"></p>
    </section>
    <noscript><p class="notice">Die Übersicht und alle Archivdaten sind gespeichert. Für Offline-Filter, Datensatzansicht und Offline-Downloads bitte JavaScript aktivieren. Die HTML-Datei lässt sich auch über „Seite speichern unter“ sichern.</p></noscript>
    <section class="metrics" aria-label="Angezeigter Zeitraum">
      <article><span>Statistikquellen</span><strong id="sourceCount"><?= $installed ?> / <?= count($sources) ?></strong><small>Installierte Bereiche</small></article>
      <article><span>Ereignisdatensätze</span><strong id="eventCount"><?= $n($eventRows) ?></strong><small>Im angezeigten Zeitraum</small></article>
      <article><span>Stammdaten &amp; Zähler</span><strong id="relatedCount"><?= $n($data['total_rows'] - $eventRows) ?></strong><small>Zugehörige Daten und aktueller Stand</small></article>
      <article><span>Frühestes Ereignis</span><strong id="firstEvent" class="date-value"><?= $data['first_event'] === null ? 'Keine Daten' : $h(substr($date($data['first_event']),0,10)) ?></strong><small>Im angezeigten Zeitraum</small></article>
    </section>
    <section class="panel" aria-labelledby="inventory-title">
      <div class="panel-heading"><div><h2 id="inventory-title">Vollständige Quellenübersicht</h2><p class="muted">Alle Statistiken des gespeicherten Stands. Der Zeitfilter aktualisiert Anzahl, Kennzahlen und Datensätze.</p></div>
        <label class="search">Quelle suchen<input id="sourceSearch" type="search" placeholder="z. B. UTM, Mind, Captcha"></label></div>
      <div class="table-scroll" tabindex="0" role="region" aria-label="Statistikquellen mit Datensatzanzahlen">
        <table id="sourceTable"><thead><tr><th>Statistikquelle</th><th>Im Filter</th><th>Im HTML gesichert</th><th>Umfang</th><th>Datensätze</th></tr></thead><tbody>
        <?php foreach ($sources as $source): ?><tr data-source="<?= $h($source['label'] . ' ' . $source['table']) ?>" data-table="<?= $h($source['table']) ?>">
          <th scope="row"><?= $h($source['label']) ?><small><?= $h($source['table']) ?></small></th>
          <td class="selected-count"><?= $source['available'] ? $n($source['rows']) : '–' ?></td>
          <td><?= $source['available'] ? $n($source['rows']) : '–' ?></td>
          <td><span class="badge"><?= !$source['available'] ? 'Nicht installiert' : ['events'=>'Im Zeitraum','related'=>'Zugehörige Daten','snapshot'=>'Aktueller Stand'][$source['kind']] ?></span>
            <?php if ($source['undated_rows'] > 0): ?><small><?= $n($source['undated_rows']) ?> ohne Datum, nicht gesichert</small><?php endif; ?></td>
          <td><?php if ($source['available']): ?><a href="#datensaetze" data-open-table="<?= $h($source['table']) ?>" aria-label="<?= $h($source['label']) ?>: Datensätze ansehen">Ansehen</a><?php else: ?>–<?php endif; ?></td>
        </tr><?php endforeach; ?></tbody></table>
      </div>
      <p id="noSources" class="empty" hidden>Keine passende Statistikquelle gefunden.</p>
      <p class="table-note">Datensätze verschiedener Systeme können denselben Besuch beschreiben. Ihre Summe ist keine Anzahl eindeutiger Besucher.</p>
    </section>
    <section class="panel" id="datensaetze" aria-labelledby="records-title" hidden>
      <div class="panel-heading"><div><h2 id="records-title">Datensätze</h2><p id="recordsDescription" class="muted"></p></div></div>
      <nav class="pagination" aria-label="Datensatzseiten"><button id="previousPage" type="button">Zurück</button><span id="pageLabel"></span><button id="nextPage" type="button">Weiter</button></nav>
      <div class="records" id="records"></div>
      <p class="table-note">20 Datensätze pro Seite. Sämtliche Seiten sind bereits in dieser HTML-Datei enthalten und werden lokal angezeigt.</p>
    </section>
    <section aria-labelledby="statistics-title"><h2 class="section-title" id="statistics-title">Statistiken nach Bereich</h2><div class="source-cards">
      <?php foreach ($sources as $source): ?>
      <article class="source-card" data-source="<?= $h($source['label'] . ' ' . $source['table']) ?>" data-table="<?= $h($source['table']) ?>">
        <h3><?= $h($source['label']) ?></h3>
        <?php if (!$source['available']): ?><p class="muted">Dieser Bereich ist nicht installiert.</p><?php else: ?>
        <p class="source-total"><strong><?= $n($source['rows']) ?></strong> Datensätze im Filter</p>
        <dl class="source-metrics"><?php foreach ($source['metrics'] as $label=>$value): ?><div><dt><?= $h($label) ?></dt><dd><?= $n($value) ?></dd></div><?php endforeach; ?></dl>
        <p class="muted dates"><?php if ($source['first'] !== null): ?><?= $h($date($source['first'])) ?> bis <?= $h($date($source['last'])) ?><br>Zeitfeld: <?= $h($source['date_column']) ?><?php endif; ?></p>
        <p class="muted"><?= $h($source['note']) ?></p>
        <a class="details-link" href="#datensaetze" data-open-table="<?= $h($source['table']) ?>">Alle Datensätze ansehen</a>
        <?php endif; ?>
      </article><?php endforeach; ?>
    </div></section>
    <details class="panel definitions"><summary>Was ist in dieser Datei enthalten?</summary>
      <p>Alle archivierten Ereignisse innerhalb der letzten 90 × 24 Stunden vor dem gespeicherten Stand, einschließlich aller Länder, Kampagnen, Ereignisarten und Bots. Die sieben Zeitfilter ändern die angezeigte Auswahl. Jeder HTML-, JSON- und CSV-Download enthält weiterhin das gesamte Archiv.</p>
      <p>Stammdaten können früher angelegt worden sein, wenn sie zu eingeschlossenen Ereignissen gehören. Ihre gespeicherten Gesamtzähler und die aktuellen CAPTCHA-Sammelzähler sind keine zeitlich begrenzten Summen. Undatierte historische Importzeilen sind nicht im Archiv enthalten.</p>
      <p>Die HTML-Datei enthält Daten, Spaltenmetadaten und Tabellenstrukturen vollständig. JSON erhält Originalwerte, NULL, führende Nullen und vollständige JSON-Nutzdaten. CSV markiert mögliche Tabellenformeln als Text. Konfiguration, Passwörter, Push-Zugangsdaten und aktive CAPTCHA-Tokens sind ausgeschlossen.</p>
      <p>Alle Daten stammen aus einem konsistenten Datenbankstand. Der Bericht läuft anschließend ohne Datenbank, Serveranfragen oder externe Dateien. Eine automatische Wiederherstellung ist nicht Bestandteil des Archivs.</p>
    </details>
    <footer>Stats3 · Gespeicherter Stand <?= $h($date($data['window']['to'])) ?> · Zeitanzeige Europe/Berlin, Daten UTC</footer>
  </main>
  <script id="stats3Snapshot" type="application/json"><!--STATS3_SNAPSHOT_DATA--></script>
  <script><?= $asset('export_model.js') ?></script>
  <script><?= $asset('export.js') ?></script>
</body>
</html>
