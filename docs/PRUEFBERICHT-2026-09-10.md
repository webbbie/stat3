# Stats3-Prüfbericht vom 10. September 2026

**Nachtrag vom 11. September 2026:** Die bestätigten Befunde wurden lokal korrigiert. Details, aktuelle Tests und Upload-Dateien stehen im [Korrekturbericht](COUNT-JS-KORREKTUREN-2026-09-11.md). Die folgenden Angaben dokumentieren weiterhin den ursprünglichen Prüfstand.

Die Prüfung des lokalen Projekts hat mehrere reproduzierbare Fehler ergeben. Der dringendste Befund ist die mögliche Übertragung von Passwort- und anderen Formularinhalten als STAT4-Klickziel. Hinzu kommen Fehler bei CAPTCHA-Fehlversuchen, Ereignisreihenfolge, Absprungwerten, Browser-Speicher, Lebenszyklusereignissen und zwei Dashboard-Funktionen.

Prüfzeitraum: 9.–10. September 2026. Projekt: `/Users/christianbayer/Documents/zFTP/myystats3/stats3`. Die 91 zu Beginn erfassten Quell-, Konfigurations- und Dokumentationsdateien waren bei der Fortsetzung unverändert. Es wurden keine Anwendungsdateien repariert. Dieser Bericht dokumentiert die Befunde und die Grenzen der Prüfung.

Alle Datenbank-Schreibtests verwendeten eine eigens gestartete temporäre MySQL-Instanz mit gesperrtem Netzwerk-Port und ausschließlich Datenbanken mit `pixl_captcha_test_*` beziehungsweise `pixl_setup_test_*` als Namen. HTTP-Tests liefen auf Loopback mit synthetischen Daten und Testkonfigurationen. Es wurden keine Benachrichtigungen versendet und keine Produktivdaten verändert.

## Bestätigte Befunde

**1. Hohe Priorität: Formularwerte können als Klickziel übertragen werden.**

Fundstellen: [count.js:1443](/Users/christianbayer/Documents/zFTP/myystats3/stats3/count.js:1443), [stat4/count.js:221](/Users/christianbayer/Documents/zFTP/myystats3/stats3/stat4/count.js:221), Speicherung in [stat4/collect.php:66](/Users/christianbayer/Documents/zFTP/myystats3/stats3/stat4/collect.php:66).

`targetDescription()` verwendet nach `href`, `name` und `id` auch `element.value` beziehungsweise `textContent`. Ein Klick auf ein gefülltes `input[type=password]` ohne Name und ID überträgt dadurch den Feldinhalt in `target`. Das wurde im VM-Test und mit einem echten Passwortfeld in Headless Edge für beide Tracker bestätigt. Verwendet wurde nur ein synthetischer Testwert; sämtliche Tracking-Transporte wurden abgefangen. Auch andere namenlose Formularelemente können betroffen sein. Abhilfe: Formularelemente ausschließlich anhand unbedenklicher Metadaten beschreiben und niemals ihre Werte oder freien Texte übernehmen.

**2. CAPTCHA zählt Fehlversuche nach einem Reload oder in parallelen Tabs zu niedrig.**

Fundstellen: [count.js:1044](/Users/christianbayer/Documents/zFTP/myystats3/stats3/count.js:1044), [count.js:1082](/Users/christianbayer/Documents/zFTP/myystats3/stats3/count.js:1082), [captcha.php:31](/Users/christianbayer/Documents/zFTP/myystats3/stats3/captcha.php:31), [pixl_captcha.php:205](/Users/christianbayer/Documents/zFTP/myystats3/stats3/pixl_captcha.php:205).

Der Browser zählt `failedAttempts` pro Seiteninstanz und schickt zusätzlich `attemptSource`. PHP ignoriert diese Kennung und interpretiert den Wert weiterhin als kumulativen Zähler für das gesamte Besucherticket. Echte lokale HTTP-/MySQL-Reproduktion: Tab A meldet einen Fehlversuch, Tab B meldet einen und anschließend seinen zweiten Fehlversuch. Gespeicherte Folge: `1, 1, 2`; korrekt wären insgesamt `1, 2, 3`. Die vorhandenen getrennten Client- und PHP-Tests erkennen den widersprüchlichen Vertrag nicht. Abhilfe: Einen gemeinsamen, idempotenten Vertrag für Ticket und Seiteninstanz definieren und durchgehend anwenden.

**3. Die in STAT4 gespeicherte Absprungbewertung rechnet Inkremente doppelt.**

Fundstelle: [stat4/collect.php:80](/Users/christianbayer/Documents/zFTP/myystats3/stats3/stat4/collect.php:80). Sichtbare Verwendung unter anderem in [utm.php:206](/Users/christianbayer/Documents/zFTP/myystats3/stats3/utm.php:206).

Das UPDATE erhöht zunächst `pageviews`, `clicks` und `active_seconds`. Anschließend addiert die Berechnung von `is_bounce` dieselben Inkremente erneut auf die bereits erhöhten Werte. Mit dem unveränderten SQL in MySQL 8.4 reproduziert: Ein erster Seitenaufruf ohne Klick und ohne Aktivzeit ergibt `pageviews=1, is_bounce=0`. Auch acht Aktivsekunden werden bei der Schwelle von 15 Sekunden bereits als ausreichend behandelt. Betroffen sind die gespeicherten Sitzungswerte und Auswertungen, die diese verwenden, insbesondere UTM. Die gesondert berechnete Besucher-Bounce-Rate der STAT4-API darf damit nicht gleichgesetzt werden. Abhilfe: Die Absprungentscheidung auf den einmal aktualisierten Werten berechnen.

**4. Eine verspätete ältere Nachricht kann die Abschlussdaten einer Sitzung zurücksetzen.**

Fundstelle: [pixl_server.php:1471](/Users/christianbayer/Documents/zFTP/myystats3/stats3/pixl_server.php:1471).

Beim Upsert derselben `eventId` werden die Messwerte ohne Vergleich des Nachrichtenzeitpunkts oder Ereignisstands überschrieben. Echte HTTP-/MySQL-Reproduktion: Zuerst wird ein `LEAVE` mit Score 50 und Dauer 60 Sekunden gespeichert. Ein danach eintreffender, laut `sentAt` 30 Sekunden älterer `VISIT` wird ebenfalls akzeptiert und ändert die Zeile auf `VISIT`, Score 0 und Dauer 0. Verzögerte oder wiederholte Übertragungen können damit fertige Ergebnisse verschlechtern. Abhilfe: Ereignisreihenfolge serverseitig absichern; eine ältere Nachricht darf einen neueren Stand nicht ersetzen.

**5. Ein Speicherfehler kann pro Übertragung neue Besucher und Sitzungen erzeugen.**

Fundstellen: [count.js:1255](/Users/christianbayer/Documents/zFTP/myystats3/stats3/count.js:1255), [stat4/count.js:48](/Users/christianbayer/Documents/zFTP/myystats3/stats3/stat4/count.js:48).

Besteht der erste Browser-Speichertest, scheitern aber spätere `setItem()`-Aufrufe etwa am Speicherlimit, speichert der Tracker die Werte im Arbeitsspeicher. `getValue()` bevorzugt trotzdem weiter den leeren oder veralteten Storage-Wert, solange `getItem()` selbst nicht wirft. In beiden Trackern erzeugte der nächste Heartbeat neue `visitorId`- und `sessionId`-Werte. Abhilfe: Nach einem Schreibfehler konsistent auf die Werte im Arbeitsspeicher umschalten beziehungsweise diese als aktuell behandeln.

**6. Die mobile Mind-Suche zählt Treffer, blendet nicht passende Zeilen aber nicht aus.**

Fundstellen: [mind/app.js:12](/Users/christianbayer/Documents/zFTP/myystats3/stats3/mind/app.js:12), [mind/styles.css:1](/Users/christianbayer/Documents/zFTP/myystats3/stats3/mind/styles.css:1).

Die Suche setzt `row.hidden`. Die mobile CSS-Regel mit `display:block` für Tabellenzeilen übersteuert die normale Verbergung. Im echten Browser bei 390 Pixeln: Suche nach Alice zeigt den Zähler „1 angezeigt“, die Zeilen Alice und Bob bleiben jedoch jeweils 60 Pixel hoch und sichtbar. Bei 1280 Pixeln verschwindet Bob korrekt. Abhilfe: Den Hidden-Zustand auch innerhalb der mobilen Tabellenregeln verbindlich verbergen.

**7. Ein Zeitraumwechsel während einer laufenden STAT4-Anfrage geht verloren.**

Fundstelle: [stat4/dashboard.js:51](/Users/christianbayer/Documents/zFTP/myystats3/stats3/stat4/dashboard.js:51).

`setRange()` ändert die Auswahl, `load()` bricht während `loading` aber sofort ab. Die alte Antwort setzt anschließend ihren eigenen Zeitraum wieder als Auswahl. Mit dem tatsächlichen Funktions- und Ereigniscode reproduziert: Während `api.php?range=1day` noch läuft, wird `24h` gewählt. Es entsteht keine zweite Anfrage; nach der alten Antwort steht die Auswahl wieder auf `1day`. Abhilfe: Die zuletzt gewünschte Auswahl getrennt behandeln und veraltete Antworten verwerfen oder anschließend den gewünschten Zeitraum laden.

**8. Eine verspätete CAPTCHA-Antwort kann nach `pagehide` noch eine Challenge laden.**

Fundstellen: [count.js:1118](/Users/christianbayer/Documents/zFTP/myystats3/stats3/count.js:1118), [count.js:1163](/Users/christianbayer/Documents/zFTP/myystats3/stats3/count.js:1163).

`pagehide` setzt `suspended=true`, die Auflösung der ursprünglichen Check-Anfrage prüft aber nur `finished` und hängt weiterhin das CAPTCHA-Skript an. Der vorhandene Laufzeittest „CAPTCHA response arriving after pagehide cannot inject a challenge“ schlägt reproduzierbar fehl. Abhilfe: Auch die ausstehende Initialisierung an den Seitenzustand binden und eine Fortsetzung nach einer Rückkehr ausdrücklich koordinieren. Nachweis im simulierten Browser-Lebenszyklus; ein realer produktiver Navigationsfall wurde nicht behauptet.

**9. Der eigenständige STAT4-Tracker bleibt nach einer Wiederherstellung aus dem Browser-Seitencache gestoppt.**

Fundstelle: [stat4/count.js:247](/Users/christianbayer/Documents/zFTP/myystats3/stats3/stat4/count.js:247).

Der `pagehide`-Handler setzt `stopped=true`; ein entsprechender `pageshow.persisted`-Handler fehlt. Im VM-Test entstehen nach Wiederherstellung und 60 Sekunden keine weiteren Heartbeats; zwei Wegnavigationen erzeugen insgesamt nur ein `leave`. Zusätzlich mit ausdrücklich synthetisch ausgelösten `PageTransitionEvent`-Ereignissen im echten Browser-DOM bestätigt: Der gemeinsame Tracker setzt die Heartbeats fort, der eigenständige nicht. Die gewöhnliche Zurücknavigation im Headless-Test führte zu einem Reload und lieferte deshalb keinen Beweis für eine tatsächlich vom Browser vorgenommene BFCache-Wiederherstellung. Abhilfe: Suspendieren und Wiederherstellen im eigenständigen Tracker genauso vollständig behandeln wie im gemeinsamen Tracker.

**10. Der eigenständige STAT4-Tracker kann eine neue Besucher-ID an eine alte Sitzung binden.**

Fundstelle: [stat4/count.js:84](/Users/christianbayer/Documents/zFTP/myystats3/stats3/stat4/count.js:84).

Erneuert ein anderer Tab den Besucherwert im gemeinsamen `localStorage`, übernimmt `ensureIdentity()` die neue ID, verwendet aber weiterhin die alte ID aus dem tablokalen `sessionStorage`. Im VM-Test wurde genau diese Kombination übertragen. Die Sitzungszeile des Collectors behält beim Konflikt ihren ursprünglichen Besucher, während neue Events die neue Besucher-ID erhalten. Abhilfe: Die Sitzung an die Besucher-ID binden und bei deren Wechsel erneuern. Der gemeinsame `count.js` hat diese zusätzliche Bindung bereits.

**11. Der eigenständige STAT4-Tracker verliert Aktivzeit beim Wechsel in den Hintergrund.**

Fundstellen: [stat4/count.js:186](/Users/christianbayer/Documents/zFTP/myystats3/stats3/stat4/count.js:186), [stat4/count.js:243](/Users/christianbayer/Documents/zFTP/myystats3/stats3/stat4/count.js:243).

Beim `visibilitychange` ist `document.hidden` bereits wahr. Das von `flush()` aufgerufene `tick()` verwirft deshalb die zuvor sichtbare Zeit seit dem letzten Tick. Reproduktion: Nach 2,5 Sekunden sichtbarer Seite wird in den Hintergrund gewechselt; übertragen werden null Aktivsekunden. Abhilfe: Den bisherigen Sichtbarkeitszustand für das abgelaufene Intervall berücksichtigen und Zeitreste erhalten.

**12. Linkklicks im eigenständigen STAT4-Tracker verwenden keinen gegen Navigation abgesicherten Transport.**

Fundstellen: [stat4/count.js:236](/Users/christianbayer/Documents/zFTP/myystats3/stats3/stat4/count.js:236), [stat4/count.js:172](/Users/christianbayer/Documents/zFTP/myystats3/stats3/stat4/count.js:172).

Klicks werden mit `send(data, false)` als normaler Fetch und `keepalive:false` übertragen. Diese konkrete Transportwahl ist in VM und Browser bestätigt. Beim folgenden Seitenwechsel kann der Browser die Übertragung abbrechen; der tatsächliche Verlust eines bestimmten produktiven Klicks wurde nicht getestet. Abhilfe: Für navigierende Klicks Beacon beziehungsweise einen geeigneten Keepalive-Fallback verwenden.

**13. Der GIF-Zählpixel funktioniert mit aktiviertem Public Key nicht.**

Fundstellen: [pixl_collect.php:59](/Users/christianbayer/Documents/zFTP/myystats3/stats3/pixl_collect.php:59), [pixl_server.php:1345](/Users/christianbayer/Documents/zFTP/myystats3/stats3/pixl_server.php:1345).

`pixl_pixel_payload()` übernimmt keinen `siteKey`, während `pixl_insert_event()` bei konfiguriertem Schlüssel zwingend einen passenden Wert verlangt. Im lokalen HTTP-Test liefern sowohl der gewöhnliche Pixelaufruf als auch derselbe Aufruf mit korrektem `siteKey` in der Query HTTP 403 und `bad_site_key`. Mit deaktiviertem Schlüssel kommt HTTP 200 mit GIF. Abhilfe: Für den Pixelpfad einen ausdrücklich unterstützten Schlüsselvertrag umsetzen. Ein konfigurierter Schlüssel sollte dabei nicht stillschweigend umgangen werden.

**14. Ein geändertes STAT4-Admin-Passwort widerruft bestehende PHP-Anmeldesitzungen nicht.**

Fundstelle: [stat4/auth.php:34](/Users/christianbayer/Documents/zFTP/myystats3/stats3/stat4/auth.php:34).

Die Sitzung enthält ausschließlich das boolesche Merkmal `stat4_admin=true`. `stat4_is_admin()` prüft bei dieser Sitzung keine Bindung an den aktuellen Passwort-Hash. In einer isolierten Kopie mit dem echten Auth-Code: Anmeldung mit dem alten Passwort erfolgreich, Passwort geändert, neue Anfrage mit derselben Session weiterhin administrativ berechtigt. Ein ungültiger beziehungsweise fehlender Autologin-Cookie ändert daran nichts. Ein Passwortwechsel allein beendet also vorhandene Zugriffe nicht. Abhilfe: Anmeldesitzungen an eine Version oder einen Fingerabdruck des aktuellen Passwort-Hashs binden.

## Einordnung weiterer Beobachtungen

Der Stats3-Sitzungswert `lastSeen` wird nur beim Laden einer Seite gespeichert ([count.js:419](/Users/christianbayer/Documents/zFTP/myystats3/stats3/count.js:419)). Nach 31 Minuten fortlaufender Aktivität auf derselben Seite beginnt die nächste Seite eine neue Sitzung. Das Verhalten ist reproduziert. Ob die konfigurierte Grenze von 30 Minuten eine Grenze seit Seitenstart oder eine Inaktivitätsgrenze sein soll, ist im aktuellen Konfigurationsfeld nicht eindeutig erklärt. Daher wird dies separat als offene Semantik geführt.

Eine identische `LEAVE`-Wiederholung liefert erneut dieselbe positive Datensatz-ID, obwohl sich die Zeile nicht ändert. Ein daraus möglicher doppelter Benachrichtigungsversand wurde nicht durch einen echten Versand getestet und wird hier nicht als eigener abschließend bewiesener Versandfehler gezählt.

Der vorhandene Test „head script does not report full scroll depth before page content exists“ scheitert an der Erwartung, es dürfe überhaupt keinen frühen STAT4-Seitenaufruf geben. Der frühe Aufruf ist im aktuellen Code ausdrücklich vorgesehen; sein gemessener Scrollwert ist null. Dieser rote Test belegt deshalb keinen tatsächlichen falschen 100-Prozent-Scrollwert des gemeinsamen Trackers. Die Testannahme muss korrigiert werden.

## Testumfang und Ergebnisse

| Prüfung | Ergebnis |
| --- | --- |
| PHP-Syntax aller 55 Projekt-PHP-Dateien einschließlich Tests und Varianten, ohne Vendor | Bestanden unter PHP 8.3.30 |
| JavaScript-Syntax aller 12 Projekt-JavaScript-Dateien | Bestanden unter Node 22.22.0 |
| `composer validate --no-check-publish` | Bestanden |
| ReadingScore-Regression | 444 Assertions bestanden |
| Pushover-Regression ohne Versand | 25 isolierte Szenarien bestanden |
| Storage-Migration-Regression | Alle fünf ausgegebenen Prüfgruppen bestanden; keine MySQL-Schreibzugriffe |
| CAPTCHA-PHP gegen isoliertes MySQL | 173 Assertions bestanden, einschließlich paralleler Besucher und wiederholter Verifikationen |
| Setup gegen isoliertes MySQL | 22 Assertions bestanden, einschließlich Datenerhalt und wiederholbarer Schemaergänzung |
| CAPTCHA-Client | Bestanden |
| Öffentliche `configurator2.php`-HTTP-Prüfung | 35 Prüfungen bestanden |
| Gesamter vorhandener `count.js`-Laufzeittest | 76 von 78 bestanden; ein bestätigter CAPTCHA-Fehler und eine unpassende Erwartung zum frühen STAT4-Aufruf |
| Zusätzliche Tracker-Reproduktionen | Passwortfeld, Storage-Ausfall, eigenständiger Tracker und Sitzungsgrenzen geprüft |
| Echte lokale HTTP-/MySQL-Reproduktionen | CAPTCHA-Vertrag, alte Nachrichten und Pixel/Public-Key-Vertrag bestätigt |
| MySQL mit tatsächlichem STAT4-UPDATE | Falsche Absprungentscheidung bestätigt |
| Browser-Datenschutztest | Beide Tracker übernehmen den synthetischen Passwortinhalt; keine externen Tracking-Anfragen |
| Browser-Darstellung `stats.php` mit allen drei eingebetteten Seiten | Bei 320, 390, 844 und 1440 Pixeln ohne horizontalen Dokumentüberlauf und ohne abgeschnittene Frameinhalte |
| Zusätzlicher Browser-Smoke-Test bei 390 Pixeln | `pixl_stats.php`, `stat/checkthis.php`, `mind/index.php`, `configurator.php`, `comp.php`, `utm.php` ohne registrierte JavaScript-Ausnahme oder sichtbare PHP-Fehlermeldung |
| Mind-Suche bei 390 und 1280 Pixeln | Separater funktionaler Fehler nur in der mobilen Verbergung bestätigt |

Die Dashboard-Fixture enthielt acht synthetische Events von drei Besuchern. Die sichtbaren Hauptkennzahlen passten zur Fixture; die Höhen der eingebetteten Seiten wichen weniger als einen Pixel vom gemessenen Inhalt ab. Externe Ressourcen wurden in diesem Test absichtlich blockiert. Die tatsächliche Chart.js-Zeichnung über das CDN ist daher kein Bestandteil des bestandenen Darstellungsnachweises.

Die Prüfung belegt den lokalen Quellstand und die beschriebenen Testfälle. Sie bestätigt keinen Upload, keinen Zustand der produktiven Datenbank, keine tatsächliche Pushover-Zustellung und keinen vollständigen Ablauf in Safari oder auf physischen Mobilgeräten. Die vorhandenen Tests decken nicht alle oben dokumentierten Fehler ab; bestandene Teiltests sind deshalb keine allgemeine Fehlerfreiheitsbestätigung.

## Reproduktionsmaterial

Die ausführbaren Hilfstests, synthetischen Fixtures und Logs liegen im temporären Verzeichnis [stats3-audit-20260909](/private/tmp/stats3-audit-20260909). Die Projektdateien selbst blieben unverändert; nur dieser Prüfbericht wurde ergänzt.

- [Tracker im echten Browser](/private/tmp/stats3-audit-20260909/browser-tracker-results.json)
- [Synthetische Lebenszyklusereignisse im Browser](/private/tmp/stats3-audit-20260909/browser-tracker-synthetic-lifecycle-results.json)
- [Gemeinsamer Tracker: zusätzliche Reproduktionen](/private/tmp/stats3-audit-20260909/shared-tracker-reproductions.json)
- [Eigenständiger STAT4-Tracker: zusätzliche Reproduktionen](/private/tmp/stats3-audit-20260909/standalone-tracker-reproductions.json)
- [CAPTCHA über HTTP und MySQL](/private/tmp/stats3-audit-20260909/captcha-contract-result.json)
- [Backend über HTTP und MySQL](/private/tmp/stats3-audit-20260909/backend-e2e-results.json)
- [Darstellung und eingebettete Seiten](/private/tmp/stats3-audit-20260909/render-smoke-results.json)
- [Mobile Mind-Suche](/private/tmp/stats3-audit-20260909/mind-search-result.log)
- [Vorhandener Tracker-Gesamttest](/private/tmp/stats3-audit-20260909/count_runtime_test.log)

Empfohlene Reihenfolge für die Behebung: zuerst Formularinhalte, dann Datenverlust durch Ereignisreihenfolge und die fehlerhaften Zähler, anschließend Speicher-/Lebenszyklusprobleme, Auth-Sitzungen und die übrigen UI-/Pixelpfade. Jede Korrektur sollte mit der zugehörigen Reproduktion erneut geprüft werden.
