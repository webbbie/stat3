# STAT4 – PHP/MySQL-Webseitenstatistik

STAT4 besteht aus einem extern einbindbaren `count.js`, dem PHP-Collector `collect.php`, einer MySQL-Datenbank und dem Dashboard `index.php`.

## Installation

Bei einer bestehenden Installation zuerst die [MySQL-Migrationsanleitung](../docs/MYSQL-MIGRATION.md) befolgen. Vor der Umstellung Altquelle, Ziel und Konfiguration sichern sowie alte und bereits auf MySQL umgeleitete Writer anhalten. Der finale STAT4-Import verlangt `--commit --confirm-stat4-cutover`; `--skip-stat4` überspringt die Quelle und bedeutet keine abgeschlossene Migration. Die bisherigen Zugangsdaten und Salts beim Deployment erhalten.

Für eine Neuinstallation:

1. Die zentrale Stats3-MySQL-Verbindung in `../pixl_config.php` konfigurieren und das vollständige `../pixl_schema.sql` in genau diese Datenbank importieren. `schema.sql` enthält nur die STAT4-Tabellen.
2. Den Ordner innerhalb der Stats3-Installation auf einen PHP-8.1+-Webserver mit PDO MySQL laden und `configurator.php` öffnen. Dort den STAT4-Admin-Zugang und optional Pushover speichern. Die geschützten Prüfseiten `../pixl_setup_check.php` und `systemcheck.php` kontrollieren.
3. Auf den zu messenden Seiten kurz vor `</body>` einfügen:

```html
<script src="https://www.bayerchristian.de/stats3/stat4/count.js"
        data-endpoint="https://www.bayerchristian.de/stats3/stat4/collect.php"
        defer></script>
```

Ohne `data-endpoint` verwendet die vollständige, eigenständige `count.js` automatisch `collect.php` aus demselben Verzeichnis. Für Cross-Origin-Tracking können in `config.php` erlaubte Webseiten-Ursprünge eingetragen werden. Eine leere Liste erlaubt alle Ursprünge.

## Gespeicherte Daten

Besucher werden innerhalb eines festen Zeitfensters von 24 Stunden über eine zufällige Browser-ID wiedererkannt und als gesalzener SHA-256-Hash gespeichert. Nach exakt 24 Stunden erzeugt `count.js` automatisch eine neue Besucher- und Session-ID; das gilt auch für eine Seite, die länger geöffnet bleibt. Zur zusätzlichen Besucherzuordnung speichert STAT4 den ersten und den zuletzt gesehenen gekürzten IP-Präfix: bei IPv4 die ersten drei Segmente (zum Beispiel `192.168.10`), bei IPv6 die ersten drei Blöcke als `/48`-Präfix. Die vollständige IP-Adresse wird nicht gespeichert. Erfasst werden außerdem Seitenaufrufe, Klicks, aktive Zeit, Scroll-Level, Pfad, Referrer, UTM-Felder, Browser/OS/Device, Sprache und Bildschirmgrößen. Wenn kein verlässlicher Länder-Header vorhanden ist, wird das Land aus Sprachregion und Browser-Zeitzone abgeleitet. „Top Pfade“ zeigt vollständige URLs aus Host und Pfad und aggregiert alle Varianten ohne den Teil ab `?`; Rohdaten und Kampagnendaten bleiben vollständig erhalten.

Alle Dashboard-Auswertungen zählen Unique Besucher. Reloads, wiederholte Aufrufe desselben Pfads und Varianten desselben Pfads mit unterschiedlichen Query-Strings erhöhen diese Werte nicht. Die einzige Ausnahme ist „Top Pfade“: Dort wird jeder tatsächlich empfangene Seitenaufruf gezählt, aber alles ab `?` vor der Aggregation entfernt.

Der initiale Seitenaufruf wird navigationssicher per `sendBeacon` übertragen; falls Beacon nicht verfügbar ist, verwendet der Tracker `fetch` mit `keepalive`. So bleibt insbesondere der Aufruf einer kurz angezeigten Intro-Seite beim sofortigen Wechsel zur nächsten Seite erhalten.

Für den Produktivbetrieb sollte das Dashboard per Webserver-Passwort geschützt sein. STAT4 verwendet einen explizit gesetzten `STAT4_HASH_SALT`, andernfalls den zentralen `hash_salt` aus `../pixl_config.php`. Der Salt muss individuell und mindestens 24 Zeichen lang sein; ein öffentlicher Standardwert wird nicht mehr verwendet. Der Systemcheck prüft dieselbe Salt-Auswahl wie der Collector. Beim Wechsel vom früheren Standard-Salt erhalten künftige Besucher neue Hashes; gespeicherte Datensätze bleiben unverändert.

Die angemeldeten Setup- und Systemchecks legen fehlende zentrale Tabellen aus `../pixl_schema.sql` automatisch an, ohne Altdaten zu importieren. Auf direktem IONOS-Hosting ohne vorgeschalteten Reverse Proxy bleibt `geoip.trust_proxy_headers` in `../pixl_config.php` auf `false`; damit gilt ausschließlich `REMOTE_ADDR`.

STAT4 verwendet immer dieselbe zentrale MySQL-Verbindung wie Stats3 über `../pixl_config.php` und `pixl_pdo()`. Separate STAT4-Datenbankfelder und `STAT4_DB_*`-Umgebungsvariablen werden nicht mehr verwendet. Die eigenen Admin-, Pushover- und Dashboard-Einstellungen liegen weiterhin in `config.local.php`; diese Datei ist per `.htaccess` geschützt und wird von Git ignoriert.

## Zeitfilter

Der Header filtert sämtliche Dashboard-Bereiche gemeinsam. `60min` und `24h` sind rollierende Zeitfenster. `1day` beginnt um 00:00 Uhr in der Zeitzone Europe/Berlin. Das Dropdown „Letzte 1–7 Tage“ bietet getrennte rollierende Zeiträume von 24 bis 168 Stunden.

Die Forecast-Zeile am Ende rechnet ausschließlich Unique Besucher ohne Bots hoch: „pro Stunde“ aus der bisher verstrichenen laufenden Berliner Stunde, „pro 24 Stunden“ aus den letzten 60 Minuten × 24 und „Heute“ aus dem bisherigen Berliner Kalendertag bis zum Tagesende. Die Ausgangswerte stehen jeweils direkt unter der Prognose.

## Pushover

Im Konfigurations-Dashboard können Application Token, User Key, Sound, Priorität und Netzwerk-Timeout hinterlegt und mit einer Testnachricht geprüft werden. Nach der 5., 10., 15. und jeder weiteren fünften unterschiedlichen Seite eines eindeutigen Besuchers sendet der PHP-Collector eine Nachricht mit Seiten-, Besucher-, Geräte- und Interaktionsdaten. In „Letzte Pfade“ wird alles ab `?` ausgeblendet; Sprache und Land werden mit ausgeschriebenen deutschen Namen angezeigt. Zugangsschlüssel werden niemals an `count.js` ausgeliefert. Überschreitet der Inhalt die Pushover-Grenze, wird er vollständig als nummerierte Folge mehrerer Nachrichten verschickt.

Die globale Pushover-Sperrzeit und das Stundenlimit aus dem Stats3-Konfigurator gelten gemeinsam für Stats3 und STAT4; der längere Abstand entscheidet. Während dieser Zeit ausgelöste Nachrichten werden verworfen und nicht nachgeholt. Ist dort „Nur Benutzer mit ReadingScore ab 1 – ohne Limits“ aktiv, werden STAT4-Meilensteinnachrichten unterdrückt, weil STAT4 keinen ReadingScore liefert. Manuell ausgelöste Testnachrichten bleiben möglich.

Bei einem bestehenden Datenbestand `schema.sql` erneut importieren oder die Pushover-Konfiguration einmal speichern; dadurch wird `stat4_notifications` angelegt. Diese Tabelle verhindert doppelte Nachrichten für denselben Besucher-Meilenstein.

## Systemcheck

`systemcheck.php` ist nach der Admin-Anmeldung erreichbar. Die Seite prüft PHP-Version und Erweiterungen, HTTPS-Client, Schreibrechte und Projektdateien, Login-Sicherheit, individuellen Besucher-Hash-Salt, die zentrale Stats3-MySQL-Verbindung und die STAT4-Tabellen sowie die Pushover-Bereitschaft. Fehler und Hinweise werden getrennt angezeigt.

## Statistik Reset

Die geschützte Unterseite `reset.php` zeigt die aktuellen Mengen von Ereignissen, Sessions, Besuchern und Benachrichtigungen. Ein vollständiger Reset ist nur nach gültiger Admin-Anmeldung, CSRF-Prüfung, Bestätigungs-Checkbox, exakter Eingabe von `RESET` und zusätzlicher Browserbestätigung möglich. Gelöscht werden ausschließlich Statistikdaten; die zentrale Stats3-Datenbankkonfiguration und der STAT4-Admin-Zugang bleiben erhalten.
