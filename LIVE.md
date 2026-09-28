# UA · Live und UserAgents

`live.php` ist die UA-Statistik. Der Link **UA** steht in `stats.php`. Die Seite nutzt die vorhandene Stats3-Anmeldung und das gemeinsame helle/dunkle Farbschema. **Live** öffnet die laufende Statistik, **UserAgents** (`live.php?view=useragents`) die ausführliche Auswertung nach Kampagnenname.

## Anzeigen und Zeiträume

- 24-Stunden-Liniendiagramm: Unique User und Impressionen in genau 24 aufeinanderfolgenden 60-Minuten-Intervallen. Die Intervalle beginnen am Start des rollenden 24-Stunden-Fensters. Die Stundenwerte sind zusätzlich als aufklappbare Tabelle erreichbar.
- Live-Puls: zurückliegende zwei Minuten in 5-Sekunden-Intervallen; einzelne Seitenaufrufe schlagen nach oben aus, der erste gespeicherte Seitenaufruf einer Besucherkennung nach unten. Bei sehr hohen Zahlen überlagern sich die Ausschläge; Zahlen und Intervall-Tooltips zeigen weiterhin die tatsächliche Anzahl.
- Runder roter Pushover-Knopf: ein Aufleuchten pro neu bestätigter Nachricht, auch wenn mehrere in einem Abruf eintreffen. Bereits beim Öffnen vorhandene Meldungen blinken nicht erneut. Klicken öffnet Mind. Bei reduzierter Bewegung bleibt die Textmeldung erhalten und die Blinkfolge entfällt.
- Fünf jüngste Seitenaufrufe mit vollständiger URL als Text, inklusive Protokoll, Port und Query. Auf dem Telefon dürfen lange URLs umbrechen.
- Kampagnentabelle nach Quelle **und** Name, mit Suche und zehn Kennzahlen in der gewünschten Reihenfolge.

„Tag“ bedeutet seit heute 00:00 Uhr Europe/Berlin; „Stunde“ die laufende Kalenderstunde; „24h“ die letzten 86.400 Sekunden. Sommer-/Winterzeit wird bei den Tages- und Stundengrenzen berücksichtigt. Der Zeitpunkt der Abfrage ist die exklusive Obergrenze; Ereignisse aus derselben Sekunde erscheinen spätestens beim nächsten Abruf.

Die Live-Anzeigen laden alle fünf Sekunden neu. Es laufen keine überlappenden Requests. Im Hintergrund und beim Pausieren stoppt die Abfrage; beim Fortsetzen wird neu geladen. Bei einem Verbindungsfehler bleiben die letzten Werte mit sichtbarem Zeitstempel und Fehlermeldung stehen. Bei abgelaufener Anmeldung stoppt das Polling und bietet einen Login-Link an.

## Zählung und Datenquellen

Impressionen sind `stat4_events.event_type = 'pageview'`, einschließlich erneuter Aufrufe. Technische Heartbeats, Klicks und erkannte Bots aus `stat4_sessions`/`stat4_visitors` werden ausgeschlossen. Besucher werden über unterschiedliche STAT4-Besucherkennungen innerhalb des jeweiligen Fensters gezählt. Für die neuen User im Puls wird geprüft, ob es schon einen früher gespeicherten Seitenaufruf derselben Kennung gibt.

Kampagnen stammen aus `stat4_sessions.utm_source` und `utm_campaign`. Die Sitzung darf vor dem betrachteten Fenster begonnen haben. Verschiedene Seiten sind Host und Pfad, ohne Query/Fragment; die Groß-/Kleinschreibung des Pfades bleibt relevant. User einer Kampagne sind keine addierbaren Anteile der Gesamt-User, weil derselbe User mehreren Kampagnen begegnen kann.

Pushover-Zahlen kommen aus den bestätigten Mind-Sendungen und aus `stat4_notifications` mit `status = 'sent'`. Teilnachrichten zählen einzeln. STAT4-Milestones aus Mind und STAT4 werden zusammengeführt; die importierten Mind-Historienkopien zählen nicht zusätzlich. Fehlende Versandtabellen erzeugen einen sichtbaren Hinweis zur unvollständigen Datenquelle. Die Anzeige löst keine Pushover-Sendung aus.

Für die Zuordnung einer STAT4-Sendung wird die letzte gespeicherte Pageview-Sitzung dieses Users vor dem Versand verwendet. Stats3-Sendungen verwenden die Kampagnenparameter der zugehörigen gespeicherten Seiten-URL. Fehlt eine belastbare Zuordnung, erscheinen sie in der eigenen Zeile **(nicht zuordenbar)**. Die verschiedenen Besucherhashes der beiden Tracker werden nicht miteinander gleichgesetzt.

## UserAgents je Kampagnenname

Der Bereich verwendet vorhandene `stat4_events.user_agent`, `browser` und `os` und die Kampagnennamen der Sitzung (`stat4_sessions.utm_campaign`). Wie in Live zählen ausschließlich Seitenaufrufe aus Sitzungen und Besucherkennungen ohne Bot-Markierung. Ein Vorkommen entspricht einem Seitenaufruf; Reloads erhöhen die Häufigkeit. Die Auswertung schreibt keine Daten, verändert keine Erkennung und führt keine Schema-Reparatur aus.

Zeiträume: letzte 24 Stunden (Standard), heute seit Berliner Mitternacht, letzte 7 oder 30 Tage sowie der gesamte gespeicherte Zeitraum. Die Daten werden beim Öffnen, bei Zeitraumwechsel und über **Aktualisieren** geladen. Der umfangreiche Bericht hat kein Fünf-Sekunden-Polling.

- Gruppierung ausschließlich nach dem Kampagnennamen, auch über mehrere Quellen. Quellen und deren Aufrufzahlen stehen beim Namen. Groß-/Kleinschreibung bleibt relevant; leere Namen erscheinen als **(ohne Kampagnenname)**.
- Pro Kampagne: Aufrufe, Unique User, vorhandene UA-Vorkommen, Anzahl unterschiedlicher UserAgents, deren Prozentanteil, Anteil des häufigsten UserAgents, Vielfalt-Score und fehlende UserAgents.
- Suchbare Übersicht, sortierbar nach Häufigkeit, Score auf- oder absteigend, Unknown-Anteil oder Name.
- Ein Klick auf den Namen öffnet vollständige UserAgent-Zeichenfolgen mit Häufigkeit, Anteil an der Kampagne, Unique User, Browser-/OS-Erkennung samt Häufigkeiten und letztem Vorkommen. Je Seite werden 50 Einträge geladen; alle weiteren sind über die Seitennavigation erreichbar.
- Die Detailansicht bietet eine Suche innerhalb der UserAgents und einen Filter für Aufrufe mit Unknown-Browser oder Unknown-Betriebssystem. Ihre Prozentanteile behalten sämtliche Kampagnenaufrufe als Nenner.
- Die Unknown-Übersicht weist Browser, Betriebssystem, beide zugleich und mindestens eines separat als Anzahl und Prozentanteil aus. Die Vereinigungsmenge zählt einen Aufruf nur einmal. Leere Werte, `Unknown` und `Unbekannt` gelten unabhängig von Groß-/Kleinschreibung als fehlende Erkennung.

### Vielfalt-Score

Leere UserAgent-Werte sowie die Platzhalter `Unknown` und `Unbekannt` werden separat erfasst und erhöhen weder die Anzahl unterschiedlicher UA noch den Score. Die übrigen vollständigen Zeichenfolgen werden nach Entfernen umgebender Leerzeichen exakt und unter Beachtung der Groß-/Kleinschreibung verglichen.

**Unterschiedliche UA %** = `100 × K / N`, wobei K die Anzahl verschiedener und N die Anzahl aller vorhandenen UserAgent-Vorkommen ist.

**Vielfalt-Score** = `100 × (1 − Σ nᵢ(nᵢ − 1) / (N(N − 1)))`, wobei nᵢ die Häufigkeit einer bestimmten UserAgent-Zeichenfolge ist. Das ist die Wahrscheinlichkeit, dass zwei verschiedene beobachtete Vorkommen unterschiedliche UserAgents haben. Häufige Wiederholungen gehen stärker in den Abzug ein.

| 100 vorhandene Vorkommen | Unterschiedliche UA | Anteil unterschiedlicher UA | Score |
| --- | ---: | ---: | ---: |
| alle identisch | 1 | 1 % | 0 % |
| 90 identisch, 10 jeweils andere | 11 | 11 % | 19,1 % |
| zwei UA mit je 50 Vorkommen | 2 | 2 % | 50,5 % |
| alle unterschiedlich | 100 | 100 % | 100 % |

Unter zwei vorhandenen Vorkommen gibt es keinen berechenbaren Score. Bei weniger als 20 wird die kleine Datenbasis beschriftet; die Schwelle ist ein Darstellungshinweis und verändert die Formel nicht. Der Score beschreibt die Verteilung von UserAgent-Zeichenfolgen. Browser-Versionen können verschiedene Zeichenfolgen erzeugen, und mehrere Geräte können denselben UserAgent verwenden.

Die Übersicht aggregiert Häufigkeiten in MySQL und lädt keine vollständige Liste sämtlicher UserAgents in den PHP-Speicher. Details werden nur für die ausgewählte Kampagne und Seite abgefragt. Beide API-Endpunkte (`data=useragents` und `data=useragent_details`) verwenden die bestehende Anmeldung und nicht cachebare Antworten. Alte, langsam eintreffende Detailantworten überschreiben keine neuere Kampagnenauswahl.

## Upload

Für die UserAgents-Erweiterung einer vorhandenen Live-Installation enthält **UA-useragents-upload.zip** diese aktuellen Dateien:

`live.php`, `live_data.php`, `live.css`, `live.js`, `useragents_data.php`, `useragents_view.php`, `useragents.js`, `useragents.css`, `pixl_server.php` und diese Dokumentation. Die vier UserAgents-Dateien sind neu. `pixl_server.php` erhält den Bereich beim Login-Rücksprung. Für diese Erweiterung ist keine Datenbankmigration erforderlich.

Das gemeinsame Update **Stats3-useragents-export-upload.zip** enthält außerdem die neue Export-Seite und ihre Navigation in `stats.php`; Umfang und 90-Tage-Backup sind in `EXPORT.md` beschrieben.

Für eine erste Installation der bisherigen Live-Funktion zusätzlich die folgenden ursprünglichen Dateien berücksichtigen:

Diese Dateien gemeinsam relativ zum Stats3-Verzeichnis hochladen:

1. `live.php`
2. `live_data.php`
3. `live.css`
4. `live.js`
5. `stats.php`
6. `pixl_server.php`
7. `stat4/db.php`
8. `stat4/collect.php`
9. `pixl_schema.sql`
10. `stat4/schema.sql`

Die Schemas ergänzen `stat4_events.page_url` für neue Installationen. Bei einer bestehenden Installation ergänzt der aktualisierte STAT4-Collector die nullable TEXT-Spalte beim nächsten Ereignis einmalig und speichert danach die bereits im bestehenden Payload übermittelte URL. Dafür braucht der vorhandene Datenbanknutzer wie bei den anderen Schemaergänzungen `ALTER`-Rechte. Der Bericht selbst liest nur und legt keine Tabellen oder Spalten an. JavaScript-Tracker und private Konfiguration brauchen für diese Ergänzung keine Änderung.

Alte Zeilen bleiben erhalten; unbekannte Protokolle und Ports werden nicht rückwirkend erfunden. Solche Zeilen erscheinen mit Host/Pfad und einem sichtbaren Hinweis, bis sie aus der Liste der letzten fünf Aufrufe fallen.

## Lokale Prüfung

`composer test-live` prüft Zeitgrenzen, URL-Verarbeitung und Login-Rücksprung ohne Datenbankzugriff. Für echte MySQL-/HTTP-Prüfungen `PIXL_LIVE_TEST_DSN` auf eine **leere, ausschließlich für Tests angelegte `pixl_setup_test_*`-Datenbank** setzen; optional `PIXL_LIVE_TEST_USER` und `PIXL_LIVE_TEST_PASSWORD`. Die Tests verwenden niemals die konfigurierte Besucher-Datenbank.

Mit `PIXL_LIVE_TEST_BROWSER=1` folgen Chromium-Prüfungen für Fünf-Sekunden-Polling, Blinkfolgen, Ausfälle, Authentifizierung, Suche, Textsicherheit, leere Daten, acht Bildschirmbreiten und Dark Mode. `CHROME_PATH`/`PLAYWRIGHT_MODULE` können die lokale Browserinstallation überschreiben, `PIXL_LIVE_ARTIFACTS` bestimmt den Screenshot-Ordner. Die Browser-Verhaltenstests nutzen markierte Testantworten; die Datenbankabfragen und der Collector werden zuvor separat über echtes MySQL und HTTP geprüft.

`composer test-useragents` prüft die Score-Formel, Zeiträume und Login-Zuordnung. Für MySQL-/HTTP-Tests `PIXL_USERAGENTS_TEST_DSN` auf eine eigene leere `pixl_setup_test_*`-Datenbank setzen, optional mit `PIXL_USERAGENTS_TEST_USER` und `PIXL_USERAGENTS_TEST_PASSWORD`. `PIXL_USERAGENTS_TEST_BROWSER=1` ergänzt Browserprüfungen mit echten lokalen API-Antworten für Score, Unknown, Kampagnenauswahl, Suche, Pagination, verspätete Antworten, Fehler, Anmeldung, Textsicherheit, acht Bildschirmbreiten und Dark Mode. Screenshots werden in `PIXL_USERAGENTS_ARTIFACTS` abgelegt. Die Vergleichsdaten enthalten ausschließlich künstliche Kampagnen und UserAgents.

Es werden keine echten Benachrichtigungen versendet. Lokale Prüfungen ersetzen weder den FTP-Upload noch eine Live-Prüfung auf dem Server.
