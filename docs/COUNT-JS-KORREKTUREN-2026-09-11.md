# Korrekturen am gemeinsamen externen Counter

Stand: 11. September 2026. Die bestätigten Fehler aus `PRUEFBERICHT-2026-09-10.md` und `COUNT-JS-EXTERN-PRUEFBERICHT-2026-09-10.md` wurden im lokalen Projekt behoben. Die beiden älteren Berichte bleiben als Dokumentation des vorherigen Zustands erhalten. Dies ist keine Aussage über bereits aktualisierte Dateien auf dem Produktionsserver.

## Behobene Fehler

| Bereich | Korrektur und Nachweis |
| --- | --- |
| Formularwerte | Gemeinsamer und eigenständiger STAT4-Counter lesen für Input, Select und Textarea ausschließlich statische Metadaten. Passwortfelder liefern nur `input:password`. Synthetische Eingabewerte wurden in VM und echtem externem Browser auf Nichtübertragung geprüft. |
| URL-Fragmente | Stats3, STAT4 und CAPTCHA entfernen Fragmente sowie URL-Zugangsdaten. Linkziele und Referrer werden entsprechend behandelt. Kampagnenparameter bleiben erhalten. |
| CAPTCHA-Zähler | PHP verarbeitet `attemptSource`. Kumulative Zähler verschiedener Seiten werden addiert; Wiederholungen derselben Quelle bleiben idempotent. A1/B1/B2 ergeben über den echten HTTP-Endpunkt 1/2/3. Verifizierung ergänzt verloren gegangene Fehlermeldungen. |
| CAPTCHA-Lebenszyklus | Verspätete Check-, Skript- und CSS-Antworten initialisieren keine gesperrte oder verlassene Seite. BFCache-Wiederaufnahme startet aufgeschobene Arbeit ausdrücklich. |
| CAPTCHA und CSP | Neue zentrale `captcha.css`; Nonce wird vom einbindenden Count-Skript an CAPTCHA-Skript und Stylesheet weitergegeben. Erst nach erfolgreichem CSS-Laden entsteht eine Scrollsperre. Blockierte oder ausgefallene Styles geben die Seite frei. |
| Browser-Speicher | Ein späterer Storage-Fehler schaltet STAT4 dauerhaft auf den Ersatz im Arbeitsspeicher um. Stale Werte verdrängen keine gültigen lokalen IDs mehr. |
| STAT4-Lebenszyklus | Eigenständiger Counter unterstützt BFCache, passende Besucher-/Sitzungsbindung, aktive Zeitreste bei Fokus-/Sichtbarkeitswechseln und gegen Navigation abgesicherte Klickübertragung. Beide Counter verwenden denselben geprüften Ablauf. |
| STAT4-Transport | Abgelehnte, mit HTTP-Fehler beantwortete oder nach zehn Sekunden unbeantwortete Fetch-Anfragen bleiben mit identischer Ereignis-ID wiederholbar. Aktivzeit-Snapshots behalten ihre Besucherkennung; Wechsel in einem anderen Tab und der Ablauf nach 24 Stunden vermischen keine Besucherwerte. |
| Ereignisreihenfolge | Stats3 serialisiert Updates derselben Zeile und vergleicht Ereignisstand sowie ursprünglichen Zeitstempel einschließlich Millisekunden. Ältere VISIT-Pakete löschen keine Abschlusswerte; identische LEAVE-Wiederholungen werden nicht nochmals zur Benachrichtigung weitergereicht. Neu gemessener Fortschritt nach BFCache bleibt erhalten. |
| Bounce | STAT4 bewertet die einmal erhöhten MySQL-Zähler. Ein erster Seitenaufruf und acht Aktivsekunden überschreiten die Schwellen nicht mehr versehentlich. Die separate besucherbezogene API-Berechnung bleibt bestehen. |
| Konsole | Schreibgeschützte Konsolenmethoden verhindern den Counterstart nicht. Error-Objekte behalten Name und Meldung; spätere Logger anderer Skripte werden beim Entfernen der Überwachung nicht überschrieben. |
| GIF-Pixel | Expliziter skalarer GET-Parameter `siteKey`; fehlende, falsche und als Array übergebene Schlüssel bleiben bei aktivierter Schlüsselprüfung abgewiesen. |
| Anmeldung | STAT4-Admin-Sessions sind an den aktuellen Passwort-Hash gebunden. Passwortwechsel widerrufen vorhandene Sessions und signierte alte Autologin-Cookies. |
| Anzeigen | Mobile Mind-Tabellen respektieren `hidden`. STAT4-Zeitraumwechsel laden sofort die letzte Auswahl; überholte Antworten können diese nicht zurücksetzen. |

## Öffentliche Konfiguration

Die 18 bestehenden Konfigurationsbereiche bleiben in `configurator2.php`. Die Versionskennung lautet `2.30-external-fixes`. Die bislang wirkungslosen Einstellungen sind angebunden; ihre Bedeutung ist jetzt ausdrücklich festgelegt:

| Einstellung | Wirkung |
| --- | --- |
| `DEBUG.ENABLED` | Schaltet Diagnosen für abgegebene Stats3-Ereignisse ein und aktiviert die beiden übrigen Debug-Schalter. |
| `DEBUG.FORCE_NOTIFY` | Bei aktivem Debug: übergeht Score-Prüfung, Ereignislimit und Cooldown. Die gewählten Ereignisarten und der Modus nur mit Abschlussmeldung bleiben maßgeblich. |
| `DEBUG.BYPASS_FILTERS` | Bei aktivem Debug: übergeht Chrome-, Betriebssystem- und Fingerprint-Filter. Freigegebene Domains und Frame-Regeln werden weiterhin geprüft. |
| `FINGERPRINT_EXCLUDE.*` | Bei aktiviertem Ausschluss müssen alle nichtleeren Felder ohne Beachtung der Groß-/Kleinschreibung passen. Ein komplett leerer Filter schließt niemanden aus. |
| `READING.MIN_SCORE`, `READING.THRESHOLDS.*` | READ erfordert ausreichend Samples und mindestens den größten Wert aus `READING.MIN_SCORE`, `THRESHOLDS.READ` und `SQL.MIN_SCORE_TO_NOTIFY`. `readingLevel` liefert LOW/READ/GOOD/EXCELLENT gemäß den Schwellen. Die vorhandene serverseitige ReadingScore-Normalisierung bleibt unverändert. |
| `SQL.FINAL_SUMMARY_ONLY`, `FINAL_SUMMARY_REASON` | Bei Aktivierung nur eine Abschlussübertragung pro normalem Seitenlebenszyklus, mit konfiguriertem Grund. Erreichte VISIT-/READ-Meilensteine bleiben in der Zusammenfassung enthalten. PHP erkennt den Abschluss über `events.reached.LEAVE`. |
| `SQL.ONE_AUTO_NOTIFICATION_ONLY` | Höchstens eine automatische Stats3-Übertragung je Dokument, auch nach BFCache-Rückkehr, sofern Debug-Force nicht aktiviert ist. Die bisherige serverseitige Beschränkung von Pushover auf Abschlussereignisse bleibt davon getrennt. |
| `SQL.NOTIFY_ON_HIDDEN` | Sendet beim Verbergen HIDDEN, ohne vorzeitig LEAVE zu markieren. Im Modus nur mit Abschlussmeldung bleibt HIDDEN unterdrückt. |
| `SQL.SESSION_COOLDOWN_MS`, `GLOBAL_COOLDOWN_KEY` | Mindestabstand automatischer Stats3-Übertragungen, bei verfügbarem Browser-Speicher auch über mehrere Seiten/Tabs derselben Origin. Ein durch Cooldown verschobenes READ wird später erneut geprüft. |
| `SQL.TRACK_BOTS_IMMEDIATELY` | Erkennt der Client einen Bot, entfällt die VISIT-Verzögerung; sonst gilt der konfigurierte Zeitwert. |
| `SQL.RETRY_QUEUE_*` | Optional gespeicherte, begrenzte Wiederholung von Stats3-Snapshots. Jeder Tab verwendet eigenen `sessionStorage`, damit andere Tabs keine Einträge überschreiben. Online-Ereignis, Wiederaufnahme und ein 15-Sekunden-Timer versuchen erneut. Schlüssel und gültige versionierte Einträge aus Legacy-Schlüsseln werden berücksichtigt; unversionierte alte Inhalte werden nicht ungeprüft übertragen. |
| `DEVICE_DETECT.ENABLED` | Steuert Browser-/OS-/Geräteerkennung im Client. Bei Abschaltung werden diese Werte Unknown; die unabhängige serverseitige User-Agent-Auswertung bleibt bestehen. |
| `RENDER_HEALTH.*`, `SQL.INCLUDE_RENDER_ISSUES` | Prüft fehlenden Dokumentkörper, fehlgeschlagene Bilder und das konfigurierte Konsolenfehlerlimit. Nach `MAX_FAILED_CHECKS` fehlerhaften Prüfungen endet das Polling; null deaktiviert diese Prüfungen. Einschluss in die Übertragung ist separat steuerbar. |
| `KNOWN_RESOLUTIONS` | Die konfigurierte Einstufung wird jetzt als boolescher Kontextwert an PHP weitergegeben. Alte Clients ohne diesen Wert erhalten die bisherige serverseitige Einstufung. |
| Nullwerte | VISIT-/READ-Verzögerung null bedeutet sofort. Wiederholungsintervalle, Fetch-Timeout, Sample-Fenster und Sitzungsdauer müssen positiv sein. Aktivierte Retry-Queue verlangt mindestens einen Platz. Die STAT4-Besucherlebensdauer darf die serverseitigen 24 Stunden nicht überschreiten. |

Die Stats3-Sitzungsdauer bezeichnet nun Inaktivität: laufende Interaktion aktualisiert `lastSeen` höchstens alle 15 Sekunden und erhält Seiten anderer Tabs. Eine nach Ablauf der Inaktivitätsgrenze erfolgende Wegnavigation belebt die alte Sitzung nicht wieder.

## Grenzen der Übertragung und Einbindung

- Ein erfolgreiches `sendBeacon` bestätigt nur die Annahme durch den Browser. Ohne aktivierte Stats3-Retry-Queue bleibt dessen bestehender Beacon-/No-CORS-Transport erhalten. Mit aktivierter Queue werden reguläre Wiederholungen per CORS-Fetch bestätigt; ein beim Verlassen angenommener Beacon bleibt bis zu einer später bestätigten Wiederholung gespeichert. Identische Ereignisse sind serverseitig geschützt.
- Die Stats3-Queue enthält höchstens den konfigurierten Wert, begrenzt auf 200 Snapshots und 24 Stunden. STAT4 hält höchstens 64 Pakete für 15 Minuten in der Tabsitzung vor. Ein volles, abgelaufenes, gesperrtes oder geschlossenes Browser-Speicherfach kann keine unbegrenzte Offline-Zustellung garantieren. STAT4 ordnet auch bei vollem Puffer Restwerte niemals einem anderen Besucher zu.
- Die automatische Zählung bleibt dokumentbezogen. History-API-Routenwechsel erhalten keinen eigenständigen virtuellen Seitenaufruf. STAT4 hält jetzt Seitenadresse und Kampagne des Dokuments zusammen, sodass ein LEAVE nicht versehentlich auf einer anderen Route landet.
- Stats3-Filter und integrierter STAT4-Teil behalten ihre vorhandene getrennte Zuständigkeit. Die Freigaben auf der externen Seite müssen Skript, Konfigurations-/Collector-Verbindungen und gegebenenfalls CAPTCHA-Styles erlauben.
- BFCache-Zustände wurden mit VM- und Browser-Ereignissen geprüft. Eine vom Browser tatsächlich gewählte BFCache-Wiederherstellung auf jeder Zielplattform sowie physische Safari-/Android-Geräte sind damit nicht nachgewiesen.

## Bereitstellung

Zuerst zentral nach `/stats3/` auf `www.bayerchristian.de`:

1. `pixl_server.php`, `pixl_collect.php`, `pixl_pushover.php`.
2. `pixl_captcha.php`, `captcha.php`, `captcha.js`, **`captcha.css`**, `pixl_schema.sql`.
3. `stat4/collect.php`, `stat4/auth.php`, `stat4/dashboard.js`, `stat4/count.js`, `mind/styles.css`.
4. `configurator2.php`, anschließend `count.js`.

Danach dieselbe korrigierte `count.js` an alle externen Auslieferungsorte, insbesondere `/files/src/count.js` auf `www.inconsequential.org`. Externe HTML-Seiten benötigen weiterhin nur ihre vorhandene Skripteinbindung; PHP und private Konfiguration bleiben zentral. Bei aktiviertem öffentlichem Schlüssel müssen bestehende GIF-Pixel-URLs ausdrücklich `&amp;siteKey=...` enthalten. CSP-Nonces müssen auf der Hostseite sowohl für das Skript als auch für Styles zugelassen sein; alternativ den zentralen Stylesheet-Ursprung erlauben.

Das CAPTCHA ergänzt bei Bedarf die nullable Spalte `failed_attempt_sources` in der bestehenden Besuchertabelle; dafür benötigt der zentrale DB-Benutzer wie bei bisherigen Schema-Ergänzungen ALTER-Rechte. Vorhandene Tickets, Phasen und Zähler bleiben erhalten. Es gibt weiterhin 17 Tabellen. Kein manueller Neuimport und kein Löschen vorhandener Tabellen ist erforderlich.

Alle Änderungen und Tests dieses Berichts sind lokal. Es erfolgten kein Upload, keine Änderung der Produktionsdatenbank und kein Versand echter Benachrichtigungen.

## Reproduzierbare Prüfung

Die Testskripte liegen dauerhaft unter `tests/`; temporäre Browser-/HTTP-Fixtures werden automatisch entfernt.

| Prüfung | Ergebnis des Korrekturlaufs |
| --- | --- |
| Gesamte Projektsyntax ohne Vendor | 56 PHP- und 17 JavaScript-Dateien ohne Syntaxfehler; Composer-Konfiguration gültig |
| Gemeinsame Count-VM-Suite | 109/109 Szenarien |
| STAT4-VM, eigenständig und integriert | Je 30 Prüfungen |
| STAT4-Auswahlrennen | 12 Prüfungen |
| Echtes externes Browser-Skript | 74 Assertions, drei Loopback-Ursprünge, ohne Request-Interception |
| CAPTCHA-CSP im echten Browser | Stylesheet-Origin, ausschließlich Nonce und blockierte Styles erfolgreich geprüft |
| CAPTCHA-PHP/MySQL | 209 Assertions einschließlich paralleler Quellen und Schema-Upgrades |
| Tatsächlicher CAPTCHA-HTTP/MySQL-Vertrag | A1/B1/B2, Reload, Wiederholung, Validierung und finale Wiederherstellung bestanden |
| Stats3/STAT4-Backend über HTTP/MySQL | 75 Assertions einschließlich sechs konkurrierender Schreiber und Passwortwechsel |
| Setup/MySQL | 22 Assertions, bestehende Daten und Struktur erhalten |
| ReadingScore | 444 Assertions |
| Pushover ohne Versand | 26 Szenarien |
| Öffentliche Konfiguration über HTTP | 35 Prüfungen |
| Storage-Migrationsregression | Alle fünf Prüfgruppen bestanden; keine MySQL-Schreibzugriffe |

SHA-256 der abschließend getesteten gemeinsamen `count.js`:

`d549a8513e9615837fcd24ca9bf7b033d8c00da119fbcf0de5cf59511c58a3e5`

`composer test-client`, `composer test-http`, `composer test-reading-score`, `composer test-pushover` und `composer test-storage` laufen ohne echte Datenerfassung. Die CI führt diese Regressionen sowie die Syntaxprüfungen aus. Für Browserprüfungen: `composer test-browser`; bei Bedarf vorhandenes Playwright über `PLAYWRIGHT_MODULE` und den Browser über `BROWSER_EXECUTABLE` angeben.

`composer test-backend` benötigt `PIXL_BACKEND_TEST_DSN` mit einer leeren, ausdrücklich entbehrlichen `pixl_setup_test_*`-Datenbank. `composer test-setup` verwendet separat `PIXL_SETUP_TEST_DSN` mit einer ebenfalls leeren Testdatenbank. `composer test-captcha` und `composer test-captcha-http` verwenden `PIXL_CAPTCHA_TEST_DSN` mit `pixl_captcha_test_*`; der HTTP-Test verlangt einen Unix-Socket. Diese Testdatenbanken dürfen keine aufzubewahrenden Daten enthalten. Alle beschriebenen Läufe verwendeten eine eigens angelegte temporäre Instanz mit deaktiviertem TCP-Netzwerk.
