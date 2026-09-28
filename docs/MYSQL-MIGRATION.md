# Bestehende Speicher nach MySQL migrieren

Stats3, STAT4, Mind, Impressions und PPCMate verwenden im aktuellen Code dieselbe MySQL/MariaDB-Verbindung aus `pixl_config.php`. Die Umstellung der laufenden Installation und der Import der Altdaten müssen zusammen geplant werden. Das Hochladen neuer Collector-Dateien kann bereits das Schreibziel ändern.

Die folgenden Befehle im tatsächlichen `stats3`-Verzeichnis auf dem Server ausführen. Absolute Quellpfade in den Beispielen sind ausdrücklich Platzhalter und müssen ersetzt werden. PHP 8.1+, PDO MySQL und für SQLite-Quellen zusätzlich PDO SQLite müssen auch im verwendeten CLI-PHP verfügbar sein.

## 1. Bestand und Sicherungen vorbereiten

- Die produktive zentrale Verbindung aus `pixl_config.php`, die frühere STAT4-Verbindung aus `stat4/config.local.php` und den tatsächlichen privaten PPCMate-SQLite-Pfad feststellen. Die Quelle muss lesbar und das MySQL-Ziel erreichbar sein; Zugangsdaten nicht in Befehlszeilen oder Protokolle kopieren.
- Vor Änderungen konsistente Sicherungen der zentralen Zieldatenbank, der alten STAT4-Datenbank und aller SQLite-Quellen erstellen. Dafür die Backup-Funktion des Hosters oder ein geeignetes Datenbank-Backup verwenden. Bei einer laufenden SQLite-Datenbank die SQLite-Backup-Funktion verwenden; eine Einzelkopie der `.sqlite`-Datei kann bereits gespeicherte, noch nicht in die Hauptdatei übertragene WAL-Daten auslassen.
- Den bisherigen Code und die privaten Konfigurationen sichern, insbesondere `pixl_config.php`, `stat4/config.local.php`, `mind/config.local.php` sowie bisherige PPCMate-Einstellungen. Sicherungen geschützt außerhalb des öffentlichen Webzugriffs aufbewahren und ihre Lesbarkeit bzw. Wiederherstellbarkeit prüfen.
- Die konfigurierten produktiven Passwörter, Hash-Salts, Site-/Host-Einstellungen und Pushover-Schlüssel erhalten. Beispielkonfigurationen dürfen bestehende produktive Dateien nicht überschreiben.

Der Import liest standardmäßig `stat/data/impressions.sqlite` für aktive Impressions sowie bekannte alte SQLite-Dateien unter `stat/` und `stat/data/` für `legacy_sqlite_rows`. Dieses Legacy-Archiv ist kein zusätzlicher aktiver Statistikdatenbestand. PPCMate wird nur mit einer ausdrücklich angegebenen Quelle importiert.

## 2. Schreibpause einrichten und Code bereitstellen

Vor dem finalen Import alle betroffenen Schreibzugriffe anhalten: alte Collector-Versionen, SQLite-Writer, den alten STAT4-Collector und auch bereits auf die zentrale MySQL-Datenbank umgeleitete Collector-Versionen. Dazu gehören Stats3, STAT4, Mind, Impressions, PPCMate-Postbacks, zeitgesteuerte Jobs und schreibende Verwaltungsaktionen. Bereits laufende Requests auslaufen lassen. Eine Pause nur des alten STAT4-Collectors reicht nicht aus.

Die Pause serverseitig an den tatsächlichen Endpunkten einrichten. Ein neues JavaScript allein hält bereits geöffnete Seiten nicht an. Während der Pause können Tracking-Ereignisse ausfallen; gegebenenfalls vorhandene Postback-Wiederholungen nach der Umstellung kontrollieren. Die Importoption `--confirm-stat4-cutover` stoppt keine Requests und prüft die Pause nicht selbst.

Unter dieser Pause die abschließenden Quell-, Ziel- und Konfigurationssicherungen erstellen. Danach den zusammengehörigen neuen Code einschließlich `pixl_schema.sql` und `tools/migrate_storage_to_mysql.php` bereitstellen. Die zentrale Verbindung in der vorhandenen `pixl_config.php` auf das beabsichtigte Ziel prüfen; Secrets und übrige Einstellungen erhalten. `stat4/config.local.php` mit den alten DB-Feldern bis zum Import aufbewahren. Der neue STAT4-Laufzeitcode ignoriert diese DB-Felder bereits und verwendet zentral MySQL.

Die Pause über Deployment und Import hinweg beibehalten. Bei separat veröffentlichten URLs wie `/stat/impression.php` prüfen, dass deren tatsächliche Dateizuordnung die gemeinsamen Dateien der Stats3-Installation erreicht. Die Module erwarten `pixl_server.php` relativ zum Stats3-Hauptverzeichnis.

## 3. Vorschau mit den tatsächlichen Quellen

Standardquellen anzeigen, ohne Daten zu importieren:

```bash
php tools/migrate_storage_to_mysql.php
```

Eine private PPCMate-Quelle ausdrücklich angeben; denselben Pfad beim finalen Import verwenden:

```bash
php tools/migrate_storage_to_mysql.php --ppcmate-db=/ABSOLUTER/PRIVATER/PFAD/ppcmate.sqlite
```

Falls die frühere STAT4-Konfiguration an einem anderen geschützten Ort liegt, in Vorschau und Import `--legacy-stat4-config=/ABSOLUTER/PRIVATER/PFAD/config.local.php` ergänzen. Diese Datei muss das bisherige PHP-Konfigurationsarray mit `db` enthalten; eine ausdrücklich angegebene fehlende oder ungültige Konfiguration führt zum Abbruch. Vorher separat sichern: Nach erfolgreichem STAT4-Import entfernt das Werkzeug den `db`-Block aus genau dieser Konfigurationsdatei; die übrigen Einstellungen bleiben erhalten. Eine unverändert aufzubewahrende Sicherung deshalb nur über eine eigene Arbeitskopie als Konfigurationsquelle verwenden.

Quellen und angezeigte Zeilenzahlen kontrollieren. Die Vorschau prüft den Zugriff auf eine erkannte STAT4-Altquelle und zählt deren Tabellenzeilen ausschließlich lesend; dieselbe Vorprüfung erfolgt beim Import vor Änderungen am Ziel. Die Vorschau prüft jedoch keine Verbindung zum MySQL-Ziel und ist kein Beleg für einen abgeschlossenen Import. Fehlende Quellen nicht als leere oder bereits migrierte Datenbestände behandeln.

## 4. Finalen Import ausführen

Erst nach korrekter Vorschau, geprüften Sicherungen und eingerichteter Schreibpause die tatsächliche Umstellung bestätigen:

```bash
php tools/migrate_storage_to_mysql.php --commit --confirm-stat4-cutover
```

Mit vorhandener PPCMate-Altquelle:

```bash
php tools/migrate_storage_to_mysql.php --commit --confirm-stat4-cutover --ppcmate-db=/ABSOLUTER/PRIVATER/PFAD/ppcmate.sqlite
```

`--commit` erstellt bzw. ergänzt das zentrale Schema und importiert Daten. `--confirm-stat4-cutover` bestätigt die zuvor eingerichtete Pause für den STAT4-Import. Alte STAT4-Zeilen mit bereits vorhandenen eindeutigen Schlüsseln werden nicht als laufende Synchronisation aktualisiert. Deshalb den Import nicht als Ersatz für eine Schreibpause oder als Abgleich zweier weiterhin aktiver Datenbanken verwenden.

Nur wenn keine STAT4-Altquelle existiert oder ihr Import bewusst aufgeschoben wird, stattdessen `--skip-stat4` in Vorschau und Import verwenden:

```bash
php tools/migrate_storage_to_mysql.php --skip-stat4
php tools/migrate_storage_to_mysql.php --commit --skip-stat4
```

Auch dabei müssen die betroffenen SQLite- und Ziel-Writer pausiert sein. `--skip-stat4` bedeutet ausdrücklich **nicht**, dass STAT4-Altdaten migriert wurden. Bei aufgeschobenem Import darf der STAT4-Cutover nicht als abgeschlossen gelten; die neue Collector-Version nutzt bereits das zentrale Ziel. Eine fehlgeschlagene STAT4-Verbindung nicht durch diese Option als Erfolg ausgeben. Ein früherer PPCMate-Datenbestand fehlt ebenfalls, wenn `--ppcmate-db` nicht angegeben wurde.

Bei einem Fehler die Pause beibehalten und die konkrete Ursache beheben. Frühere Importteile können bereits abgeschlossen sein; der gesamte Lauf ist keine einzelne Transaktion. Vor einem erneuten Lauf anhand des Protokolls und der Tabelle `storage_migrations` feststellen, welche Teile importiert wurden. Keine Altquelle löschen und keinen automatischen Rückwechsel auf alte Writer durchführen.

## 5. Prüfen und Betrieb wieder aufnehmen

- In `pixl_setup_check.php` anmelden und PHP, PDO MySQL, zentrale Verbindung und vollständige Tabellenliste prüfen. Der angemeldete Check legt fehlende zentrale Tabellen aus `pixl_schema.sql` automatisch mit `CREATE TABLE IF NOT EXISTS` an und prüft danach alle 17 Tabellen, einschließlich Captcha. Vorhandene Daten bleiben erhalten; dies importiert keine Altdaten und bestätigt keine Datenvollständigkeit. Derselbe Strukturabgleich läuft im STAT4-Systemcheck.
- In `storage_migrations` die tatsächlich bearbeiteten Quellen und Tabellen kontrollieren. Den Bestand anhand der Quell-Sicherungen, Datumsbereiche und eindeutigen Schlüssel mit dem Ziel vergleichen. Angezeigte Importzahlen sind keine garantierte Anzahl neu eingefügter Zeilen. Bei STAT4 die vier Tabellen `stat4_visitors`, `stat4_sessions`, `stat4_events` und `stat4_notifications` prüfen; Impressions und gegebenenfalls PPCMate ebenfalls vergleichen.
- Die Dashboards und den STAT4-`systemcheck.php` kontrollieren. Erst nach erfolgreicher Prüfung die vorgesehenen neuen Writer wieder freigeben. Kontrollierte neue Ereignisse pro verwendetem Modul auslösen und ihre Speicherung im zentralen Ziel prüfen; danach PHP-/Webserver-Protokolle auf Fehler kontrollieren.
- Die alten Datenbanken, SQLite-Dateien und Konfigurationssicherungen aufbewahren. SQLite-Quellen unverändert und für PHP nicht schreibbar halten. Auf dem Ubuntu/IONOS-vServer setzt das vorhandene Rechte-Skript SQLite-Backups auf `root:root` und Modus `600`; der Importprozess benötigt weiterhin passende Leserechte. Auf Shared Hosting entsprechend geschützte, nicht öffentlich erreichbare Sicherungen verwenden.

## Stand der lokalen Prüfung

Am 5. September 2026 sind in der lokalen zentralen Datenbank `website_db` weiterhin 9.013 Impressions, 98.980 Legacy-Archivzeilen und 11 Migrationsnachweise vorhanden. Wiederholte Importläufe vom 4. September erzeugten keine Duplikate; die acht SQLite-Hauptdateien blieben unverändert. Alle vier aktiven Collector-Schreibwege wurden mit HTTP-Antwort und anschließendem MySQL-Nachweis geprüft; ihre sechs Testzeilen wurden wieder entfernt. Ein separater STAT4-Testimport bestätigte außerdem, dass neuere Zieldaten erhalten bleiben.

Der neue lesende STAT4-Vorlauf wurde mit der erreichbaren zentralen lokalen Datenbank geprüft. Die tatsächliche alte STAT4-Verbindung wird weiterhin mit `Access denied` verweigert und bricht nun bereits vor dem Anlegen des Zielschemas oder Importieren von Daten ab. Deren vollständiger Import und die produktive Umstellung sind daher noch offen. Dafür müssen die tatsächlichen Serverzugänge und Quellen nach diesem Ablauf verwendet werden.

Die zusätzlichen Regressionstests lassen sich ohne MySQL-Schreibzugriffe ausführen:

```bash
composer test-storage
```

Sie prüfen fehlerhafte Quellangaben, den Erhalt von Besitzer/Gruppe/Dateimodus beim Bereinigen der STAT4-Konfiguration, parallele Konfigurationsänderungen und die IP-Bereinigung. Sie laufen ebenfalls in der CI. Das Speichern im STAT4-Konfigurator erhält noch vorhandene alte DB-Zugangsdaten bis zum erfolgreichen Import; die aktive Laufzeitverbindung bleibt zentral.
