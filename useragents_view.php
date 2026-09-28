<?php
if (!isset($userAgentsView) || !$userAgentsView) { http_response_code(404); exit; }
?>
<main class="ua-report">
  <div class="page-heading">
    <div><p class="eyebrow">UA / USERAGENTS</p><h1>UserAgents deiner Kampagnen.</h1><p class="subtle">Häufigkeiten, Vielfalt und Unknown-Erkennung · Europe/Berlin</p></div>
    <div class="connection"><span id="connectionDot" class="status-dot" aria-hidden="true"></span><span id="connectionStatus" role="status">Auswertung wird geladen …</span><button id="refreshAgents" type="button">Aktualisieren</button></div>
  </div>
  <div id="errorNotice" class="notice error" role="alert" hidden></div>
  <noscript><p class="notice">Bitte JavaScript aktivieren, um UserAgents und Kampagnen auszuwerten.</p></noscript>
  <div class="ua-toolbar">
    <label>Zeitraum<select id="agentRange"><option value="24h">Letzte 24 Stunden</option><option value="1d">Heute</option><option value="7d">Letzte 7 Tage</option><option value="30d">Letzte 30 Tage</option><option value="all">Gesamter Zeitraum</option></select></label>
    <label class="ua-search">Kampagnen durchsuchen<input id="agentCampaignSearch" type="search" placeholder="Kampagnenname oder Quelle …"></label>
    <label>Sortieren nach<select id="agentSort"><option value="occurrences">Häufigkeit ↓</option><option value="score_desc">Vielfalt-Score ↓</option><option value="score_asc">Vielfalt-Score ↑</option><option value="unknown">Unknown-Anteil ↓</option><option value="name">Kampagnenname A–Z</option></select></label>
  </div>
  <section class="metrics" aria-label="UserAgent-Gesamtübersicht">
    <article><p>Kampagnennamen</p><strong id="agentTotalCampaigns">–</strong><small>über alle Quellen zusammengefasst</small></article>
    <article><p>UA-Vorkommen</p><strong id="agentTotalOccurrences">–</strong><small id="agentCoverage">erfasste UserAgents aus Seitenaufrufen</small></article>
    <article><p>Unterschiedliche UserAgents</p><strong id="agentTotalDistinct">–</strong><small>verschiedene vollständige Zeichenfolgen</small></article>
    <article><p>Browser / OS Unknown</p><strong id="agentTotalUnknown">–</strong><small>Aufrufe mit mindestens einem Unknown</small></article>
  </section>
  <section class="panel" aria-labelledby="agentCampaignTitle">
    <div class="panel-heading"><div><p class="eyebrow">VIELFALT JE KAMPAGNE</p><h2 id="agentCampaignTitle">Kampagnen im Vergleich</h2><p class="subtle">Hohe Wiederholungen senken den Score. Viele verschiedene UserAgents erhöhen ihn.</p></div><span id="agentCampaignCount" class="subtle"></span></div>
    <div class="table-scroll" tabindex="0" role="region" aria-label="UserAgent-Kennzahlen je Kampagne">
      <table class="ua-summary-table"><caption class="sr-only">Häufigkeit und Vielfalt der UserAgents nach Kampagnenname</caption><thead><tr><th scope="col">Kampagnenname / Quellen</th><th scope="col">Aufrufe</th><th scope="col">Unique User</th><th scope="col">UA-Vorkommen</th><th scope="col">Unterschiedliche UA</th><th scope="col">Unterschiedliche UA %</th><th scope="col">Häufigste UA %</th><th scope="col">Vielfalt-Score</th><th scope="col">UA fehlt</th></tr></thead><tbody id="agentCampaignRows"><tr><td colspan="9" class="empty-state">Kampagnen werden geladen …</td></tr></tbody></table>
    </div>
    <p class="table-note">Klicke auf einen Kampagnennamen für die vollständigen UserAgents. Die Prozentwerte und der Score verwenden ausschließlich vorhandene UserAgents. „UA fehlt“ wird separat gezählt.</p>
  </section>
  <section class="panel" aria-labelledby="agentUnknownTitle">
    <div class="panel-heading"><div><p class="eyebrow">ERKENNUNG</p><h2 id="agentUnknownTitle">Browser und Betriebssysteme: Unknown</h2><p class="subtle">Anzahl und Anteil an allen Seitenaufrufen der jeweiligen Kampagne</p></div></div>
    <div class="table-scroll" tabindex="0" role="region" aria-label="Unknown-Browser und Betriebssysteme nach Kampagne">
      <table class="ua-unknown-table"><caption class="sr-only">Unknown-Erkennung pro Kampagne; beide Unknown sind bereits in den Einzelwerten enthalten</caption><thead><tr><th scope="col">Kampagnenname</th><th scope="col">Aufrufe</th><th scope="col">Browser Unknown</th><th scope="col">Betriebssystem Unknown</th><th scope="col">Beide Unknown</th><th scope="col">Mindestens eines Unknown</th></tr></thead><tbody id="agentUnknownRows"><tr><td colspan="6" class="empty-state">Erkennung wird geladen …</td></tr></tbody></table>
    </div>
    <p class="table-note">Leere Werte sowie „Unknown“ und „Unbekannt“ gelten als nicht erkannt. „Beide Unknown“ ist in Browser und Betriebssystem enthalten. „Mindestens eines“ zählt jeden Aufruf einmal.</p>
  </section>
  <section id="agentDetails" class="panel" aria-labelledby="agentDetailTitle" hidden>
    <div class="panel-heading"><div><p class="eyebrow">VOLLSTÄNDIGE USERAGENTS</p><h2 id="agentDetailTitle">Kampagne auswählen</h2><p id="agentDetailSummary" class="subtle"></p></div></div>
    <form id="agentDetailFilters" class="ua-detail-filters">
      <label class="ua-search">UserAgent durchsuchen<input id="agentSearch" type="search" maxlength="200" placeholder="z. B. Safari, iPhone, Android …"></label>
      <label class="ua-checkbox"><input id="agentUnknownOnly" type="checkbox">Nur Aufrufe mit Browser / OS Unknown</label>
      <button type="submit">Filtern</button>
    </form>
    <div id="agentDetailError" class="notice error" role="alert" hidden></div>
    <div class="table-scroll ua-detail-scroll" tabindex="0" role="region" aria-label="Vollständige UserAgents mit Häufigkeiten und Erkennung">
      <table class="ua-detail-table"><caption class="sr-only">UserAgents nach Häufigkeit innerhalb der ausgewählten Kampagne</caption><thead><tr><th scope="col">UserAgent</th><th scope="col">Vorkommen</th><th scope="col">Anteil an Kampagne</th><th scope="col">Unique User</th><th scope="col">Browser</th><th scope="col">Betriebssystem</th><th scope="col">Zuletzt gesehen</th></tr></thead><tbody id="agentDetailRows"></tbody></table>
    </div>
    <div class="ua-pagination"><span id="agentDetailCount" class="subtle" role="status"></span><div><button id="agentPrev" type="button">Zurück</button><span id="agentPage"></span><button id="agentNext" type="button">Weiter</button></div></div>
    <p class="table-note">Ein Vorkommen entspricht einem Seitenaufruf. Wiederholungen zählen mit. Detailanteile beziehen sich auf alle Aufrufe der ausgewählten Kampagne; Such- und Unknown-Filter schränken die angezeigten Aufrufe ein. Browser/OS zeigen ihre gespeicherte Erkennung mit jeweiliger Häufigkeit.</p>
  </section>
  <details class="definitions ua-definitions"><summary>Berechnung des Vielfalt-Scores und der Prozentwerte</summary>
    <p><strong>Unterschiedliche UA %</strong> = Anzahl verschiedener UserAgent-Zeichenfolgen ÷ Anzahl aller vorhandenen UserAgent-Vorkommen × 100.</p>
    <p><strong>Vielfalt-Score</strong> = 100 × [1 − Σ nᵢ(nᵢ − 1) ÷ (N(N − 1))]. N ist die Anzahl vorhandener UserAgent-Vorkommen; nᵢ die Häufigkeit einer bestimmten Zeichenfolge. Der Score entspricht der Wahrscheinlichkeit, dass zwei verschiedene Vorkommen unterschiedliche UserAgents haben.</p>
    <p>100 identische UserAgents ergeben <strong>0 %</strong>. 90 identische und 10 jeweils andere ergeben <strong>19,1 %</strong>. 100 jeweils unterschiedliche ergeben <strong>100 %</strong>. Bei weniger als zwei vorhandenen Vorkommen wird kein Score berechnet. Kleine Datenmengen sind in der Tabelle erkennbar.</p>
    <p>Der Score misst die Vielfalt der gespeicherten UserAgents. Browser- und Versionsunterschiede zählen als unterschiedliche Zeichenfolgen. Ein UserAgent kann von vielen Besuchern verwendet werden; die Werte sind keine Anzahl physischer Geräte.</p>
    <p>Ausgewertet werden STAT4-Seitenaufrufe mit dem Kampagnennamen der Sitzung (utm_campaign). Gleichlautende Namen über mehrere Quellen werden zusammengefasst; Groß-/Kleinschreibung bleibt erhalten. Ohne Namen erscheint „(ohne Kampagnenname)“. Klicks, technische Heartbeats und erkannte Bots sind ausgeschlossen. Die Auswertung liest ausschließlich vorhandene Daten und wird beim Öffnen, bei Zeitraumwechsel oder über „Aktualisieren“ neu geladen.</p>
  </details>
  <footer class="page-footer"><span>UA · UserAgents</span><span id="lastUpdated">Noch nicht aktualisiert</span><a href="live.php">Zur Live-Statistik</a></footer>
</main>
