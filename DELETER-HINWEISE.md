# Leere Ersatzdateien für GitHub

Der Ordner `deleter` enthält 72 Dateien mit jeweils exakt **0 Byte**. Die Namen
und Unterordner entsprechen den vorhandenen Dateien dieses lokalen Stats3-Ordners.
Es wurden keine Originalinhalte in das Paket kopiert. Die Originaldateien bleiben
lokal unverändert. Stand: 28.09.2026.

## In GitHub anwenden

1. Öffne im Repository die Ebene, in der bereits `stats.php` und `pixl_config.php`
   liegen. Falls das Repository einen Unterordner `stats3` hat, öffne diesen zuerst.
2. Wähle **Add file → Upload files**. Öffne lokal `deleter` und ziehe dessen
   **Inhalt einschließlich der Unterordner** in den Upload. Der äußere Ordner
   `deleter` gehört nicht in den Zielpfad. Auf dem Mac kannst du versteckte Dateien
   wie `.DS_Store` mit **Cmd + Shift + .** einblenden.
3. Kontrolliere die Zielpfade vor dem Commit. Beispielsweise muss
   `deleter/mind/config.local.php` lokal im Repository zu `mind/config.local.php`
   werden. `deleter/mind/config.local.php` als GitHub-Ziel würde nur eine zusätzliche
   Datei anlegen und die vorhandene Konfiguration erhalten.
4. Prüfe, dass die vorgesehenen Dateien tatsächlich als leere Ersatzdateien
   aufgenommen wurden, und speichere die Änderungen mit einem Commit.

GitHub beschreibt den Upload unter
[Datei zu einem Repository hinzufügen](https://docs.github.com/en/repositories/working-with-files/managing-files/adding-a-file-to-a-repository).
Ein Upload mit dem zusätzlichen Zielpräfix `deleter/` überschreibt die Originale
nicht. Das Paket wurde lokal vorbereitet; es wurde nichts zu GitHub hochgeladen.

Wenn der Browser leere Dateien beim Upload auslässt, kopiere den Inhalt von
`deleter` in einen separaten lokalen Git-Checkout des GitHub-Repositories, ersetze
nur die gleichnamigen Dateien und committe/pushe diese Änderungen beispielsweise
mit GitHub Desktop. Verwende dafür eine separate Repository-Kopie, damit deine
funktionierende lokale Stats3-Installation erhalten bleibt.

## Was geleert wird

Enthalten sind aktuelle und alte Konfigurationen, persönliche Einstellungen in
Programmdateien, lokale SQLite-Daten samt WAL-/SHM-Dateien und beschädigten
Sicherungen, GeoIP-Daten und Cache-Metadaten, Upload-Pakete sowie Unterlagen,
Tests und Paketmetadaten mit persönlichen Domains oder lokalen Pfadangaben.

Auch Programmdateien mit eingebetteten Einstellungen werden vollständig geleert,
zum Beispiel `count.js`, `pixl77.js`, `pixel_stats2.php`, `ppcmate-postback.php`
und `stat/impression.php`. Dasselbe gilt für die unten aufgeführten Vorlagen,
Dokumente und Paketmetadaten. Die Ersatzdateien ergeben deshalb keine lauffähige
Stats3-Installation. **Dieses Paket ist zum Leeren der GitHub-Dateien bestimmt;
kopiere es nicht über deine produktive Website oder deinen FTP-Datenbestand.**

Die Dateiauswahl bezieht sich auf den aktuell vorhandenen lokalen Ordner. Nur auf
GitHub vorhandene zusätzliche Dateien, andere Branches oder externe MySQL-Daten
wurden nicht eingesehen und können durch dieses Paket nicht vollständig erfasst
werden. Diese Anleitung liegt absichtlich außerhalb von `deleter`, damit alle
Dateien innerhalb des Ersatzordners inhaltslos bleiben.

## Bereits veröffentlichte Inhalte

Leere Ersatzdateien entfernen die Inhalte aus dem neuen Dateistand. Frühere
Commits können die ursprünglichen Inhalte weiterhin enthalten. Auch andere
Branches, Tags, Forks, Kopien und separat veröffentlichte Release-Dateien werden
nicht durch dieses Paket bereinigt. Bereits öffentlich gewordene Passwörter,
Tokens und API-Schlüssel müssen gesperrt bzw. ersetzt werden. Eine Bereinigung
der Git-Historie ist ein zusätzlicher Vorgang; siehe
[GitHub: sensible Daten aus einem Repository entfernen](https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/removing-sensitive-data-from-a-repository).

## Vollständige Dateiliste

Alle folgenden Pfade sind relativ zur ursprünglichen Stats3-Ebene. Im Paket
steht jeweils `deleter/` davor. Jede aufgeführte Ersatzdatei hat 0 Byte.

### Konfigurationsdateien (10)

- `configurator2.php`
- `mind/config.example.php`
- `mind/config.local.php`
- `mind/config.php`
- `pixl_config.example.php`
- `pixl_config.ionos.example.php`
- `pixl_config.php`
- `pixl_config_trash.php`
- `stat4/config.local.php`
- `stat4/config.php`

### Datenbanken, Begleitdateien und Daten-Caches (31)

- `data/geoip/dbip-country-lite.json`
- `data/geoip/dbip-country-lite.mmdb`
- `stat/data/human.sqlite`
- `stat/data/human.sqlite-shm`
- `stat/data/human.sqlite-wal`
- `stat/data/impressions-old.sqlite`
- `stat/data/impressions-old.sqlite-shm`
- `stat/data/impressions-old.sqlite-wal`
- `stat/data/impressions-old2.sqlite`
- `stat/data/impressions-old2.sqlite-shm`
- `stat/data/impressions-old2.sqlite-wal`
- `stat/data/impressions-old4.sqlite`
- `stat/data/impressions.sqlite`
- `stat/data/impressions.sqlite-shm`
- `stat/data/impressions.sqlite-wal`
- `stat/data/recovery-backups-2026-07-22-1745/impressions-old4.sqlite.corrupt`
- `stat/data/recovery-backups-2026-07-22-1745/impressions-old4.sqlite.corrupt-shm`
- `stat/data/recovery-backups-2026-07-22-1745/impressions-old4.sqlite.corrupt-wal`
- `stat/data/recovery-backups-2026-07-22-1745/impressions.sqlite.corrupt`
- `stat/data/recovery-backups-2026-07-22-1745/impressions.sqlite.corrupt-shm`
- `stat/data/recovery-backups-2026-07-22-1745/impressions.sqlite.corrupt-wal`
- `stat/data/recovery-backups-2026-07-22-1745/track.sqlite.corrupt`
- `stat/data/recovery-backups-2026-07-22-1745/track.sqlite.corrupt-shm`
- `stat/data/recovery-backups-2026-07-22-1745/track.sqlite.corrupt-wal`
- `stat/data/track.sqlite`
- `stat/main.sqlite`
- `stat/main.sqlite-shm`
- `stat/main.sqlite-wal`
- `stat/tracker.sqlite`
- `stat/tracker.sqlite-shm`
- `stat/tracker.sqlite-wal`

### Datenpakete, Manifeste und Finder-Metadaten (5)

- `.DS_Store`
- `Stats3-useragents-export-manifest.sha256`
- `Stats3-useragents-export-upload.zip`
- `UA-live-upload.zip`
- `UA-useragents-upload.zip`

### Programm- und Beispieldateien mit eingebetteten persoenlichen Einstellungen (10)

- `count.js`
- `examples/bayerchristian-embed.html`
- `index.html`
- `pixel_stats2.php`
- `pixl6.js`
- `pixl77.js`
- `ppcmate-postback.php`
- `ppcmate-tracker.js`
- `stat/impression.php`
- `stat4/count.js`

### Dokumentation und Berichte mit persoenlichen Angaben (9)

- `README.md`
- `docs/COUNT-JS-EXTERN-PRUEFBERICHT-2026-09-10.md`
- `docs/COUNT-JS-KORREKTUREN-2026-09-11.md`
- `docs/INSTALLATION.md`
- `docs/IONOS.md`
- `docs/PRUEFBERICHT-2026-09-10.md`
- `readme 7.txt`
- `stat/README-impression.md`
- `stat4/README.md`

### Persoenliche Paketmetadaten (2)

- `composer.json`
- `vendor/composer/installed.php`

### Tests mit persoenlichen URLs oder lokalen Pfaden (5)

- `tests/configurator2_http_test.js`
- `tests/count_external_browser_test.js`
- `tests/count_runtime_test.js`
- `tests/live_browser_test.js`
- `tests/useragents_browser_test.js`
