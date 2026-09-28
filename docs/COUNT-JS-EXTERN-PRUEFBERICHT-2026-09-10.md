# Vollständige Prüfung von count.js als externes Skript

**Nachtrag vom 11. September 2026:** Die bestätigten Befunde wurden lokal korrigiert. Details, aktuelle Tests und Upload-Dateien stehen im [Korrekturbericht](COUNT-JS-KORREKTUREN-2026-09-11.md). Die folgenden Angaben dokumentieren weiterhin den ursprünglichen Prüfstand.

Stand: 10. September 2026. Geprüft wurden alle 1.497 Zeilen der aktuellen [count.js](/Users/christianbayer/Documents/zFTP/myystats3/stats3/count.js), einschließlich Konfigurations-Bootstrap, Stats3, des darin enthaltenen STAT4-Trackers und der CAPTCHA-Anbindung.

Die gewöhnliche externe Einbindung funktioniert. Die Datei ist trotzdem nicht fehlerfrei: Neben den bereits bekannten Datenschutz-, Speicher- und CAPTCHA-Problemen sind wirkungslose Konfigurationseinstellungen, ein Ausfall bei schreibgeschützter Konsole und weitere Fehler bei Messwerten und Fehlerbehandlung bestätigt. Die Befunde dieses Berichts beziehen sich auf den gemeinsamen `stats3/count.js`; Fehler, die ausschließlich im eigenständigen `stat4/count.js` liegen, werden ihm nicht zugeschrieben.

Die Anwendungsdateien wurden nicht geändert. Hilfstests und Testkonfigurationen liegen unter [stats3-count-external-audit-20260910](/private/tmp/stats3-count-external-audit-20260910). Geschrieben wurde zusätzlich dieser Bericht. Tracking-Anfragen gingen ausschließlich an lokale Testempfänger. Die einzige externe Kommunikation war der lesende Abruf der öffentlich ausgelieferten JavaScript- und Konfigurationsdateien.

## Live-Abgleich

| Öffentliches Ziel | Ergebnis |
| --- | --- |
| [Zentrale count.js](https://www.bayerchristian.de/stats3/count.js) | HTTP 200, 52.719 Bytes, bytegleich mit der lokalen Datei |
| [Extern ausgelieferte count.js](https://www.inconsequential.org/files/src/count.js) | HTTP 200, 52.719 Bytes, bytegleich mit der lokalen Datei |
| [Öffentliche Konfiguration](https://www.bayerchristian.de/stats3/configurator2.php) | HTTP 200; geparster JSON-Inhalt identisch mit der lokalen PHP-Ausgabe |

SHA-256 beider ausgelieferten Skripte und der lokalen Datei:

`90778ba32694bb28e9ae78a9fd10727c50addf8cc30de0ef2250178795137517`

Die Konfiguration antwortete auf `Origin: https://www.inconsequential.org` mit der passenden `Access-Control-Allow-Origin`-Freigabe sowie `Cache-Control: no-store` und `X-Content-Type-Options: nosniff`. Der früher beobachtete Zustand mit fehlender Konfiguration beziehungsweise abweichender externer Skriptversion besteht bei diesem Abruf nicht mehr. Die bestätigten Codefehler sind damit auch in den aktuell ausgelieferten Skriptdateien enthalten; dies ist kein Nachweis eines tatsächlich eingetretenen Datenschutzvorfalls oder einer bestimmten fehlerhaften Produktionszählung.

## Bestätigte Fehler und Datenübertragungen

**1. Passwort- und Textarea-Inhalte werden als Klickziel übertragen.**

Fundstelle: [count.js:1443](/Users/christianbayer/Documents/zFTP/myystats3/stats3/count.js:1443), insbesondere der Fallback auf `element.value` in Zeile 1448.

Ein gefülltes Passwortfeld oder eine Textarea ohne Name und ID wird beim Klick über seinen Inhalt beschrieben. In den echten externen Browser-Fixtures empfing der lokale STAT4-Collector sowohl `EXTERNAL_SYNTHETIC_PASSWORD` als auch `EXTERNAL_SYNTHETIC_TEXT` in `target`. Dies trat bei allen sechs geprüften normalen Einbindungsvarianten auf. Die Verarbeitung in [stat4/collect.php:66](/Users/christianbayer/Documents/zFTP/myystats3/stats3/stat4/collect.php:66) übernimmt `target` in die Datenbank.

Hohe Priorität. Formularelemente dürfen für diese Beschreibung keine Eingabewerte oder freien Texte liefern. Ein statischer, bewusst festgelegter Identifikator ist erforderlich.

**2. STAT4 und CAPTCHA übertragen den vollständigen URL-Fragmentteil.**

Fundstellen: [count.js:1082](/Users/christianbayer/Documents/zFTP/myystats3/stats3/count.js:1082), [count.js:1358](/Users/christianbayer/Documents/zFTP/myystats3/stats3/count.js:1358). Stats3 entfernt den Fragmentteil dagegen bereits in [count.js:811](/Users/christianbayer/Documents/zFTP/myystats3/stats3/count.js:811).

Bei der synthetischen Seitenadresse `https://www.inconsequential.org/page#access_token=SYNTHETIC_FRAGMENT_TOKEN` enthielten sowohl der STAT4-Seitenaufruf als auch die CAPTCHA-Anfrage den vollständigen Token im JSON-Body. Der Stats3-Seitenaufruf enthielt nur die Adresse ohne Fragment. Das ist relevant, wenn eine externe Website vertrauliche Daten im Fragment verwendet: Fragmente werden normalerweise nicht als Bestandteil eines HTTP-Seitenabrufs an den Server übertragen, hier aber ausdrücklich durch das Skript. Nachgewiesen ist die Übertragung; eine Speicherung des Fragmentwerts durch STAT4 wurde damit nicht behauptet.

**3. Zahlreiche zentrale Einstellungen haben keine funktionale Wirkung.**

Fundstellen: Validierung in [count.js:49](/Users/christianbayer/Documents/zFTP/myystats3/stats3/count.js:49), Ereignislogik ab [count.js:917](/Users/christianbayer/Documents/zFTP/myystats3/stats3/count.js:917), Transport ab [count.js:664](/Users/christianbayer/Documents/zFTP/myystats3/stats3/count.js:664).

Die Konfiguration bietet 68 einzelne Werte beziehungsweise Listen. Für 29 davon existiert nach der Strukturprüfung keine funktionale Verwendung. Zugriffe über übergebene Teilobjekte, insbesondere `ReadingTracker` und die Botmuster, wurden dabei berücksichtigt und nicht fälschlich als unbenutzt gezählt.

Konkrete Laufzeitnachweise:

| Einstellung | Beobachtetes Verhalten |
| --- | --- |
| `SQL.FINAL_SUMMARY_ONLY=true` | Weiterhin `VISIT`, `READ` und `LEAVE`; auch im echten Browser bestätigt |
| `SQL.FINAL_SUMMARY_REASON` geändert | Abschlussereignis bleibt `LEAVE` |
| `SQL.ONE_AUTO_NOTIFICATION_ONLY=true` | Weiterhin drei automatische Ereignisse |
| `FINGERPRINT_EXCLUDE.ENABLED=true`, Browser `chrome` | Passender Chrome-Besucher wird weiterhin von Stats3 erfasst |
| `SQL.NOTIFY_ON_HIDDEN=true` | Kein zusätzliches Stats3-Ereignis beim Verbergen |
| `SQL.RETRY_QUEUE_ENABLED=true`, maximale Queuegröße 10 | Nach einem fehlgeschlagenen Stats3-Fetch keine gespeicherte Queue und keine Wiederholung beim Online-Ereignis |

Außerdem ohne funktionale Umsetzung sind die drei `DEBUG`-Werte, die vier `READING`-Werte, Sitzungs-Cooldown und dessen Schlüssel, `SQL.INCLUDE_RENDER_ISSUES`, `SQL.TRACK_BOTS_IMMEDIATELY`, `DEVICE_DETECT.ENABLED` und die vier `RENDER_HEALTH`-Werte. `renderIssues` wird lediglich als leeres Array angelegt und weitergegeben; ein Render-Prüfer wird nicht ausgeführt. Die vollständige Liste steht in [configuration-usage-reviewed.json](/private/tmp/stats3-count-external-audit-20260910/configuration-usage-reviewed.json).

Damit ist das Skript über `configurator2.php` nur teilweise so steuerbar, wie es die vorhandenen Einstellungsnamen nahelegen. Bei einer Behebung müssen diese Funktionen entweder tatsächlich implementiert oder die nicht unterstützten Einstellungen ausdrücklich entfernt beziehungsweise als nicht wirksam gekennzeichnet werden.

**4. Eine schreibgeschützte Konsole verhindert den Stats3-Start.**

Fundstellen: [count.js:458](/Users/christianbayer/Documents/zFTP/myystats3/stats3/count.js:458), [count.js:830](/Users/christianbayer/Documents/zFTP/myystats3/stats3/count.js:830).

Wenn die einbindende Seite ihre Konsole etwa mit `Object.freeze(console)` schützt, wirft die Installation von `ConsoleSpy` beim Überschreiben von `console.log` einen TypeError. Die Stats3-Initialisierung bricht ab, bevor Lesetracking und Seitenereignisse eingerichtet sind. Im VM-Test und im echten Browser kam kein Stats3-Ereignis an, während STAT4 weiterhin seinen Seitenaufruf und sein Abschlussereignis sendete. Der Fehler wird abgefangen und ausgegeben; die Hostseite selbst stürzt im Test nicht ab. Die optionale Konsolenüberwachung muss unabhängig von der eigentlichen Zählung scheitern können.

**5. Fehlgeschlagene STAT4-Heartbeats verlieren die bereits verrechnete Aktivzeit.**

Fundstellen: [count.js:1384](/Users/christianbayer/Documents/zFTP/myystats3/stats3/count.js:1384), [count.js:1435](/Users/christianbayer/Documents/zFTP/myystats3/stats3/count.js:1435).

`send()` meldet bei Fetch sofort Erfolg. Eine spätere Ablehnung wird ignoriert; auch HTTP-Fehlerstatus werden nicht geprüft. `flush()` zieht die Sekunden bereits vom lokalen Zähler ab. VM-Reproduktion: Ein Heartbeat mit 30 Sekunden scheitert, nach weiteren 30 Sekunden werden nur die neuen 30 Sekunden gesendet. Die ersten 30 Sekunden werden nicht nachgeliefert. Im echten Browser wurden zusätzlich HTTP-503-Antworten auf Heartbeats beobachtet; der Tracker reagiert darauf nicht mit Wiederholung oder Wiederherstellung der Messwerte.

Eine erfolgreiche Beacon-Übergabe bestätigt ebenfalls nur die Übernahme durch den Browser, nicht die Speicherung im Backend. Der Stats3-Fallback verwendet bewusst `no-cors` und kann den serverseitigen Status damit nicht lesen. Dieser unterschiedliche Bestätigungsumfang muss bei einer zuverlässigen Übertragung berücksichtigt werden.

**6. Nach einem späteren Browser-Speicherfehler kann die Besucheridentität wechseln.**

Fundstelle: [count.js:1255](/Users/christianbayer/Documents/zFTP/myystats3/stats3/count.js:1255).

Der initiale Storage-Test kann erfolgreich sein, obwohl spätere Schreibzugriffe scheitern. Dann existiert zwar eine Ersatzkopie im Arbeitsspeicher, ein regulär zurückkehrender leerer oder veralteter Storage-Wert hat beim Lesen jedoch weiterhin Vorrang. Die bereits reproduzierte Folge sind neue Besucher- und Sitzungskennungen bei weiteren Übertragungen. Der Fehler steht unverändert im gemeinsamen Skript. Die vorangegangene Reproduktion und die Abgrenzung zu vollständig gesperrtem Storage stehen im [Gesamtprüfbericht, Befund 5](/Users/christianbayer/Documents/zFTP/myystats3/stats3/docs/PRUEFBERICHT-2026-09-10.md).

**7. Eine Besucherrotation kann vorherige Aktivzeit dem neuen Besucher zuschlagen.**

Fundstellen: [count.js:1291](/Users/christianbayer/Documents/zFTP/myystats3/stats3/count.js:1291), [count.js:1435](/Users/christianbayer/Documents/zFTP/myystats3/stats3/count.js:1435).

Die Besucher-/Sitzungsbindung wird im gemeinsamen Skript zwar korrekt erneuert. Noch nicht versendete Messwerte werden dabei aber nicht vom bisherigen Besucher getrennt. Reproduktion: Nach 15 Sekunden erneuert ein anderer Tab die gemeinsame Besucher-ID. Beim nächsten Heartbeat nach insgesamt 30 Sekunden erhält die neue Besucher-ID sämtliche 30 Sekunden, obwohl sie erst für die letzten 15 Sekunden galt. Das kann insbesondere an der 24-Stunden-Grenze auftreten. Vor dem Identitätswechsel müssen vorhandene Messwerte der bisherigen Identität zugeordnet oder ausdrücklich getrennt werden.

**8. Ein akzeptierter Nullwert für die Verzögerung wird stillschweigend ersetzt.**

Fundstellen: [count.js:56](/Users/christianbayer/Documents/zFTP/myystats3/stats3/count.js:56), [count.js:842](/Users/christianbayer/Documents/zFTP/myystats3/stats3/count.js:842).

Die Konfigurationsprüfung akzeptiert `VISIT_DELAY_MS=0`. Durch `value || 600` wird dieser Wert aber als fehlend behandelt: Im Test erfolgte kein unmittelbarer Aufruf, sondern einer nach 600 Millisekunden. Dieselbe Art von Fallback existiert bei weiteren Verzögerungen und Intervallen. Nullwerte sollten entweder die festgelegte Bedeutung haben oder eindeutig als ungültig abgewiesen werden.

**9. Reguläre JavaScript-Error-Objekte verlieren in der Fehleraufzeichnung ihre Meldung.**

Fundstelle: [count.js:479](/Users/christianbayer/Documents/zFTP/myystats3/stats3/count.js:479).

`console.error(new Error('SYNTHETIC_EXCEPTION_MESSAGE'))` wird durch `JSON.stringify(Error)` zu `{}`. Genau dieser inhaltslose Text erscheint anschließend in `health.consoleErrors`. Der Fehlerzähler steigt, aber die eigentliche Ursache fehlt. Für Error-Objekte müssen mindestens Name und Meldung gezielt behandelt werden. Außerdem ersetzt `ConsoleSpy` keine allgemeine Erfassung ungefangener Ausnahmen oder abgelehnter Promises.

**10. Die bekannten CAPTCHA- und PHP-Vertragsfehler bleiben bestehen.**

Der aktuelle Laufzeittest bestätigt erneut die nach `pagehide` noch mögliche Initialisierung einer verspätet beantworteten CAPTCHA-Anfrage ([count.js:1118](/Users/christianbayer/Documents/zFTP/myystats3/stats3/count.js:1118)).

Weiterhin zählt `count.js` Fehlversuche pro Seiteninstanz und sendet `attemptSource`, während [captcha.php:31](/Users/christianbayer/Documents/zFTP/myystats3/stats3/captcha.php:31) diese Kennung ignoriert und [pixl_captcha.php:205](/Users/christianbayer/Documents/zFTP/myystats3/stats3/pixl_captcha.php:205) kumulative Ticketwerte erwartet. Die bereits im selben Projektprüflauf über HTTP/MySQL bewiesene Unterzählung nach Reloads beziehungsweise in parallelen Tabs ist dadurch unverändert vorhanden.

Auch der zuvor bewiesene Verlust neuerer Abschlusswerte durch später eintreffende ältere Nachrichten bleibt im unveränderten Backend bestehen ([pixl_server.php:1471](/Users/christianbayer/Documents/zFTP/myystats3/stats3/pixl_server.php:1471)). Das ist ein serverseitiger Fehler im Vertrag mit dem externen Skript und keine zusätzliche Syntaxstörung in `count.js`.

## Verhalten und Voraussetzungen bei externer Einbindung

Die folgenden Punkte wurden geprüft, werden aber nicht pauschal als Programmfehler gewertet:

- **Klassische Einbindungen funktionieren:** Im Kopf, mit `async`, mit `defer`, am Seitenende, dynamisch per Script-Element und von einer dritten Origin. Die Datei wird dabei jeweils unverändert geladen. Das Skript verwendet die Adresse der besuchten Seite für die Messung und die zentrale Konfiguration für die Empfänger.
- **Doppelte Einbindung wird unterdrückt:** Im realen Browser entstanden keine doppelten Stats3-/STAT4-Initialereignisse. Attribute wie `data-config-url` bleiben über die asynchrone Konfigurationsanfrage erhalten; `data-stat4-endpoint` wurde ebenfalls geprüft.
- **CORS funktioniert mit passenden Serverantworten:** Die Konfigurationsantwort erlaubt die externe Origin. STAT4-Heartbeats mit `application/json` erzeugten in einer zusätzlichen Prüfung ohne Request-Interception echte OPTIONS-Preflights und anschließend POST-Anfragen. Der Browser verweigert eine Konfiguration ohne passende CORS-Freigabe, bevor die Tracker starten.
- **Konfigurationsausfälle beenden den Start bewusst:** Ungültiges JSON und eine Antwort später als 4,5 Sekunden führen zu keinem Tracking. Das entspricht der README. Ohne eingebettete Ersatzkonfiguration bleiben sehr kurze Besuche vor Abschluss dieser Anfrage gegebenenfalls unerfasst.
- **Eine strenge CSS-CSP beeinträchtigt die CAPTCHA-Anbindung:** Bei `style-src 'self'` und ausdrücklich erlaubtem zentralem Skript-/Verbindungsziel wird die von `captcha.js` erzeugte Inline-Formatierung blockiert. Der Overlay-Container war dann `position:static` statt `fixed`. Die Integration benötigt eine passende Nonce-/Stylesheet-Lösung oder eine ausdrücklich passende Host-CSP; nur `connect-src` zu erlauben reicht für das CAPTCHA nicht. Dies ist eine getestete Integrationsvoraussetzung und keine Behauptung, jede externe Seite habe diese CSP.
- **Iframe- und Browserfilter sind aufgeteilt:** `SQL.BLOCK_IFRAMES`, `EXCLUDE_CHROME` und `ACCEPTED_OS` sperren nicht automatisch den integrierten STAT4-Teil. Im Iframe-Test wurden Stats3 und CAPTCHA unterdrückt, STAT4 sendete dennoch. Die vorhandenen Tests berücksichtigen diese Trennung bereits; eine globale Sperre beider Tracker ist damit nicht implementiert.
- **Seitenwechsel ohne Neuladen werden nicht vollständig unterstützt:** Bei einer Änderung per History-API hält Stats3 an seinem anfangs aufgenommenen Seitenkontext fest. STAT4 verwendet später die neue URL, erzeugt für sie aber keinen eigenen Seitenaufruf. Im VM-Test gehörte der einzige `pageview` zur alten Route und `leave` zur neuen. Als Counter für normale HTML-Seiten ist der Ablauf geeignet; für eine SPA fehlt ein ausdrücklicher Routenwechsel-Vertrag.
- **Die Sitzungsgrenze basiert auf Seiteninitialisierungen:** `lastSeen` wird nicht laufend bei Aktivität aktualisiert. Die bereits geprüfte 31-Minuten-Nutzung kann daher auf der Folgeseite eine neue Stats3-Sitzung ergeben. Ob eine Inaktivitätsgrenze oder eine feste Seitengrenze gewollt ist, bleibt im Konfigurationsfeld unklar.
- **Der gemeinsame STAT4-Lebenszyklus enthält die Wiederaufnahme:** `pageshow.persisted`, die Erneuerung der Besucher-/Sitzungsbindung und die Absicherung navigierender Klicks sind im gemeinsamen `count.js` vorhanden. Die ausschließlich für `stat4/count.js` gefundenen fehlenden Implementierungen wurden nicht nochmals als Fehler dieser Datei gezählt.
- **Zentrale Bildschirmauflösungen steuern nicht alle Backend-Ausgaben:** `KNOWN_RESOLUTIONS` beeinflusst die Browser-Fingerprint-API. PHP berechnet die Einstufung für Speicherung und Nachrichten mit seiner eigenen Liste in [pixl_server.php:1239](/Users/christianbayer/Documents/zFTP/myystats3/stats3/pixl_server.php:1239). Eine Änderung allein in `configurator2.php` steuert deshalb nicht diese serverseitige Einstufung.

## Vollständigkeit der Quellprüfung

| Bereich | Gelesene Zeilen | Prüfung |
| --- | --- | --- |
| Bootstrap, Konfigurationsschema, Zeitlimit und Startschutz | 12–123 | Gesamter Ablauf und Fehlerszenarien |
| Hilfsfunktionen, Botmuster, Länder, Storage und Sitzung | 124–436 | Typen, Rückfallverhalten, Grenzen und Identität |
| Konsolenüberwachung | 438–488 | Einfluss auf Hostseite und Fehlerobjekte |
| Browser- und Geräteerkennung | 490–579 | Vorhandene UA-Regression und Kontextwerte |
| ReadingTracker | 581–659 | Aktivität, Begrenzung der Samples und CAPTCHA-Sperre |
| Stats3-Übertragung | 661–709 | Beacon, Fetch, Abbruch, Zeitlimit und Bestätigungsumfang |
| Stats3-Zustand und Payload | 711–1000 | VISIT/READ/LEAVE, BFCache, Grenzen und Konfiguration |
| Öffentliche API, CAPTCHA und DOM-Initialisierung | 1002–1203 | Reihenfolge, Fehlversuche, externe Ressourcen und Wiederaufnahme |
| Integrierter STAT4-Tracker | 1205–1497 | Storage, Identität, URL, UTM, Scrollen, Zeit, Transport und Ereignisse |

Die ausgelassenen Zeilen zwischen diesen Bereichen sind Leerzeilen beziehungsweise Abschnittstrenner. Auch der Dateikopf wurde geprüft. Diese Übersicht dokumentiert die vollständige Codelektüre; sie behauptet keine mathematisch vollständige Abdeckung jeder möglichen Kombination von Browserzuständen.

## Ausgeführte Tests

| Prüfung | Ergebnis |
| --- | --- |
| `node --check count.js` | Bestanden |
| Vorhandene vollständige VM-Suite | 76/78 Szenarien bestanden; ein tatsächlicher CAPTCHA-Lebenszyklusfehler und eine unpassende Erwartung zum frühen STAT4-Seitenaufruf |
| `tests/captcha_client_test.js` | Bestanden |
| Öffentliche PHP-Konfiguration über lokales HTTP | 35 Prüfungen bestanden |
| Ergänzende VM-Untersuchungen | 13 Szenarien ausgeführt; konkrete Ergebnisse einschließlich der absichtlich reproduzierten Fehler gespeichert |
| Reale externe Browser-Fixtures | 18 Szenarien mit getrennten Origins ausgeführt |
| Zusätzliche native CORS-Kontrolle ohne Request-Interception | 4 Szenarien; tatsächliche Preflights und verweigerter Konfigurationszugriff bestätigt |
| Reales externes CAPTCHA im Browser | `check` → absichtlich falscher Schiebeversuch → `fail` → passender Versuch → `verify`; Overlay nach bestätigter Lösung entfernt |
| Live-Dateiabgleich | Beide ausgelieferten Skripte identisch mit lokalem SHA-256; öffentliche Konfiguration inhaltlich identisch |

Die Browser-Fixtures verwendeten PHP 8.3.30, Node 22.22.0 und einen echten Headless-Edge-Browser. Die Testkonfiguration verkürzte gezielt die Zeitwerte für VISIT auf 100 ms, READ auf 1.000 ms, Heartbeat auf 1.000 ms und Tick auf 250 ms; der JavaScript-Quelltext blieb unverändert. Die originale VM-Suite prüfte weiterhin die ursprünglichen Werte. Für das reproduzierbare CAPTCHA wurde nur die Zufallsquelle im Testbrowser kontrolliert; die echte CAPTCHA-Implementierung und ihre Ereignishandler wurden ausgeführt.

Es wurden keine Live-Collector-Anfragen und keine Benachrichtigungen ausgelöst. Die Browser-Netzwerkprotokolle enthalten ausschließlich lokale Ziele. Safari- und Android-Erkennung wurden durch die vorhandenen UA-/VM-Fälle abgedeckt, nicht durch physische Geräte. Eine tatsächlich vom Browser vorgenommene BFCache-Rückkehr war bereits im vorangegangenen Headless-Test nicht verfügbar; die entsprechenden Zustandsprüfungen sind Ereignis-/VM-Prüfungen. Eine Produktionsdatenbankprüfung ist kein Teil dieser externen Skriptprüfung.

## Nachweise

- [Live-Abruf: zentrale Skriptdatei](/private/tmp/stats3-count-external-audit-20260910/central-script-result.json)
- [Live-Abruf: externe Skriptdatei](/private/tmp/stats3-count-external-audit-20260910/external-script-result.json)
- [Live-Konfiguration und Header](/private/tmp/stats3-count-external-audit-20260910/central-config.headers)
- [Vorhandene vollständige Laufzeitsuite](/private/tmp/stats3-count-external-audit-20260910/runtime.log)
- [Ergänzende VM-Ergebnisse](/private/tmp/stats3-count-external-audit-20260910/vm-probe-results.json)
- [18 externe Browser-Szenarien](/private/tmp/stats3-count-external-audit-20260910/browser-external-results.json)
- [Native CORS-Prüfung](/private/tmp/stats3-count-external-audit-20260910/browser-native-cors-results.json)
- [CAPTCHA-Schiebeprüfung über externe Einbindung](/private/tmp/stats3-count-external-audit-20260910/browser-captcha-results.json)
- [Vorangegangener Gesamtprüfbericht mit MySQL-Reproduktionen](/Users/christianbayer/Documents/zFTP/myystats3/stats3/docs/PRUEFBERICHT-2026-09-10.md)

Priorität für eine Behebung: zuerst die Übertragung von Formularwerten und vertraulichen URL-Bestandteilen, dann die wirkungslosen Steuerungen und die Datenverluste, anschließend die unabhängige Konsolenüberwachung, Zeit-/Identitätsgrenzen und die benötigten Integrationsvoraussetzungen. Die Anwendung ist mit diesem Bericht geprüft, noch nicht repariert.
