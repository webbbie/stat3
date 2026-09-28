# Stats3: statischer HTML-Export und Daten-Backup

`stats.php → Export` öffnet `export.php`. Die Seite erstellt einmalig einen vollständigen Datenbank-Snapshot für maximal 90 Tage und liefert ein eigenständiges HTML-Dokument. **Alle Rohdaten, Tabellenstrukturen, Styles und Skripte sind in diesem HTML enthalten.** Filter, Kennzahlen, Suche, Datensatzseiten und Downloads laufen danach im Browser ohne weitere Serveranfrage.

Die Webseite benötigt die vorhandene Stats3-Anmeldung. Zum Erstellen liest sie ausschließlich aus der zentral konfigurierten MySQL-Datenbank. Sie führt keine Schemaänderungen, Besucheraktionen, CAPTCHA-Aktionen oder Benachrichtigungs-/Conversion-Sendungen aus. Die heruntergeladene Datei benötigt weder Server noch Datenbank noch Anmeldung.

## HTML speichern und offline nutzen

**HTML-Bericht herunterladen** speichert `stats3-backup-YYYYMMDD-HHMMSS.html`. Diese eine Datei im Browser öffnen. Es sind keine zusätzlichen CSS-/JS-Dateien oder Internetverbindungen erforderlich. Auch **JSON-Backup herunterladen** und **Export** (CSV) funktionieren direkt aus der gespeicherten HTML-Datei.

Der HTML-Download behält die aktuelle Ansicht bei und enthält gleichzeitig immer **alle archivierten Daten**. Beispielsweise lässt sich ein bei „60 Minuten“ gespeicherter Bericht später offline wieder auf „3 Monate“ umschalten. Eine kürzere Filterauswahl entfernt keine Daten aus dem HTML-, JSON- oder CSV-Download.

Alternativ liefert `export.php?download=html` denselben vollständigen HTML-Bericht direkt als Download. JSON/CSV bleiben auch direkt über `export.php?download=json` und `export.php?download=csv` verfügbar. Alte Links zu `stats.php?export=csv` werden weitergeleitet.

## Statische Zeitfilter

Die sieben Filter stehen in dieser Reihenfolge bereit:

| Filter | Zeitraum vor dem gespeicherten Stand |
| --- | --- |
| 60 Minuten | 3.600 Sekunden |
| 24 Stunden | 24 Stunden |
| 7 Tage | 7 × 24 Stunden |
| 14 Tage | 14 × 24 Stunden |
| 1 Monat | 30 × 24 Stunden |
| 2 Monate | 60 × 24 Stunden |
| 3 Monate | 90 × 24 Stunden, Standardansicht |

Monate sind hier ausdrücklich 30/60/90 Tage, um die maximale Sicherungsdauer von 90 Tagen beizubehalten. Der Endzeitpunkt ist im Dokument gespeichert und hängt nicht vom heutigen Datum oder der Uhr des Geräts ab. Die Filter funktionieren daher auch Monate oder Jahre nach dem Download unverändert. Beide Randzeitpunkte sind enthalten.

Die Filter aktualisieren die Übersicht, Datensatzanzahlen, Kennzahlen je Quelle und die auswählbaren Rohdatensätze. 20 Datensätze werden gleichzeitig dargestellt; **alle weiteren Seiten sind bereits im HTML gespeichert**. Quellen lassen sich zusätzlich nach Namen suchen. Dark Mode und mobile Ansichten sind enthalten.

## Vollständiger Datenumfang

Die Übersicht enthält alle 15 unterstützten Quellen: die konfigurierte Pixl-Ereignistabelle, STAT4-Besucher/Sitzungen/Ereignisse/Benachrichtigungen, Impressions, PPCMate-Zuordnungen/Conversions, Mind-Nachrichten/MaxMind-Geodaten, vier CAPTCHA-Statistikquellen und datierte historische Importzeilen. Daraus stammen auch Click Paths, DashboardX2, UTM, Live und UserAgents. Fehlende optionale Module erscheinen als „Nicht installiert“.

- Ereignisdaten sind auf 90 × 24 Stunden begrenzt. Bei kürzerer Datenhistorie enthält das Archiv alle vorhandenen Ereignisse innerhalb dieses Fensters. Ältere und zukünftige Ereignisse bleiben außerhalb des Archivs; die Originaldaten werden nicht geändert.
- Alle Länder, Kampagnen, Ereignisarten und Bots im Zeitraum sind enthalten. Die Filter der Hauptstatistik verändern den Umfang nicht.
- Zugehörige STAT4-Sitzungen/Besucher, Mind-Geodaten und PPCMate-Zuordnungen bleiben erhalten, wenn sie im jeweiligen Anzeigezeitraum aktualisiert wurden oder zu einem ausgewählten Ereignis gehören. Solche Stammsätze können früher angelegt worden sein; ihre Gesamtzähler bleiben als gespeicherte Werte erhalten.
- CAPTCHA-Phase und CAPTCHA-Auswertung sind aktuelle, nicht zeitlich aufteilbare Sammelstände. Sie sind als **Aktueller Stand** gekennzeichnet und werden nicht als Summen des Zeitfilters ausgegeben. Seitenzuordnungen gehören zu den ausgewählten CAPTCHA-Besuchern.
- Historische Importzeilen verwenden `source_created_at`, nicht das Importdatum. Undatierte Altzeilen werden in der Übersicht gezählt, aber nicht gesichert.
- Zahlen verschiedener Systeme können dieselben Besuche beschreiben. Ihre Summe ist keine Zahl gemeinsam deduplizierter Besucher.

Die Darstellung verwendet Europe/Berlin, gespeicherte Zeitwerte UTC. Der Snapshot wird in einer Read-only-Transaktion mit Repeatable Read erzeugt. Ein Aufruf über `at` darf höchstens 24 Stunden zurückliegen; diese Abrufgrenze gilt **nicht** für die anschließend gespeicherte HTML-Datei.

## Backup-Formate

Das HTML enthält den vollständigen JSON-Datenbestand im Element `stats3Snapshot` sowie die direkt eingebettete Darstellung und Filterlogik. Besuchereingaben werden beim Einbetten gegen das Schließen des JSON-Script-Elements geschützt und bei der Darstellung ausschließlich als Text eingesetzt. Eine im HTML enthaltene Content Security Policy sperrt externe Ressourcen und Netzwerkanfragen.

**JSON** enthält `meta` mit Format `stats3-statistics-backup`, Version 1, Erstellungszeit, Zeitraum und Quellenübersicht. `tables` enthält Tabellenname, Bereich, Spaltenmetadaten, Primärschlüssel, `CREATE TABLE`-SQL, ausgelassene Spalten, vollständige `rows` und `row_count`.

Jeder SQL-Wert außer NULL ist eine JSON-Zeichenfolge; SQL NULL bleibt JSON `null`. Große IDs, Dezimalwerte, führende Nullen, vollständige Texte und JSON-Nutzdaten bleiben erhalten. Konfiguration, Passwörter, Push-Zugangsdaten und aktive CAPTCHA-Tokens sind ausgeschlossen; die ausgelassene Token-Spalte wird dokumentiert.

**CSV** verwendet UTF-8 mit BOM, Semikolon und gemeinsame Spalten einschließlich Statistik-/Tabellenname. Mögliche Tabellenformeln erhalten ein führendes Apostroph. NULL und leere Zeichenfolgen sind im CSV nicht unterscheidbar. Der offline erzeugte CSV-Download wird gegen den PHP-CSV-Export bytegenau geprüft.

Das Archiv ist ein Statistik-Backup. Eine automatische Wiederherstellung ist nicht eingebaut. Für einen Import die gespeicherten Strukturen und Fremdschlüssel beachten und eine separate leere Datenbank verwenden. Benachrichtigungen oder Conversions nicht erneut versenden. Die Daten aller 15 Quellen wurden mit künstlichen Testdaten bei aktiven Fremdschlüsselprüfungen wiederhergestellt.

## Dateien und Upload

Für diese Export-Erweiterung einer bestehenden Stats3-Installation gemeinsam hochladen:

`stats.php`, `stats_export.php`, `export.php`, `export_view.php`, `export_model.js`, `export.js`, `export.css`, `pixl_server.php`.

PHP bettet die drei lokalen CSS-/JS-Dateien bei der Erstellung ein; sie müssen daher auf dem Server vorhanden sein, werden beim Öffnen des fertigen Berichts aber nicht nachgeladen. `export_view.php` lässt sich nicht direkt aufrufen.

**Stats3-useragents-export-upload.zip** enthält die aktuellen Dateien für Export und Live/UserAgents, `LIVE.md`, diese Dokumentation und ein SHA-256-Manifest. Private Konfiguration und Besucherdaten gehören nicht zum Upload-Paket. Für eine Erstinstallation der älteren Live-Funktion siehe die zusätzlichen Collector-/Schema-Dateien in `LIVE.md`.

Der Server erstellt jeden Download vor der Ausgabe vollständig in einer privaten temporären Datei. JSON und HTML werden dabei gestreamt; der PHP-Speicherverbrauch wächst nicht mit der Gesamtdateigröße. Der Browser lädt für die Offline-Nutzung den vollständigen Datenbestand, stellt aber nur die gewählte Datensatzseite dar. Fehler liefern keine scheinbar vollständige Teildatei. Authentifizierte Antworten sind nicht cachebar.

## Tests

`composer test-export` prüft PHP-Grundfunktionen, `composer test-export-model` die lokale Filterlogik. Für die vollständige Prüfung `PIXL_EXPORT_TEST_DSN` auf eine leere, ausschließlich temporäre `pixl_setup_test_*`-Datenbank setzen, optional mit `PIXL_EXPORT_TEST_USER`/`PIXL_EXPORT_TEST_PASSWORD`. Die konfigurierte Besucherdatenbank wird nicht verwendet.

`PIXL_EXPORT_TEST_BROWSER=1` ergänzt die Browserprüfung. `BROWSER_EXECUTABLE`/`PLAYWRIGHT_MODULE` wählen die Browserinstallation; `TEST_ARTIFACT_DIR` den Screenshot-Ordner.

Geprüft werden sämtliche Filtergrenzen, SQL-/Offline-Kennzahlen, vollständige eingebettete Daten, ältere notwendige Stammsätze, fehlende Module, leere Daten, Werteerhaltung, Wiederherstellung, parallele Eingänge, unveränderte Ausgangsdaten, Fehlerbereinigung, UTC und Zugriffsschutz. CSV, JSON und HTML werden mit mehr als 32 MiB Ausgabe bei 16 MiB PHP-Speicher geprüft.

Die Browserprüfung öffnet den heruntergeladenen HTML-Bericht als lokale Datei bei abgeschaltetem Netzwerk, setzt die Uhr auf ein späteres Jahr und prüft alle sieben Filter, sämtliche Datensatzseiten, vollständige JSON-/CSV-Downloads, null HTTP-Anfragen, Script-Textschutz, acht Bildschirmbreiten und Dark Mode. Lokale Prüfungen ersetzen keinen FTP-Upload.
