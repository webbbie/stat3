# Pixl SQL Tracker

Pixl SQL Tracker is a small self-hosted PHP/MySQL analytics suite with browser-side trackers, bot-aware server-side storage, and password-protected statistics dashboards.

It combines Stats3, STAT4, Mind, impression tracking, PPCMate attribution, browser/Web Push notifications, optional server-side Pushover messages, bot detection, and full User-Agent inspection. All active server-side data is stored in the same MySQL/MariaDB database configured in `pixl_config.php`.

## Features

- Drop-in `pixl77.js` MySQL tracker file
- PHP/MySQL collector endpoint
- Password-protected dashboard with auto-login cookie
- Browser notifications for new events
- Notification sound after browser permission is granted
- Full User-Agent display
- Server-side bot scoring and bot reason output
- Extra User-Agent text scan for the word `bot`
- Optional `noscript` tracking pixel for direct/no-JS/bot requests
- Per-module runtime table updates plus complete central schema and migration tooling
- One central MySQL database for Stats3, STAT4, Mind, Impressions, and PPCMate
- Previewable one-time import of existing SQLite and legacy STAT4 data
- Local DB-IP Country Lite lookup for IPv4 and IPv6
- No npm dependencies; one small Composer MMDB reader

## Project Files

```text
pixl77.js                Browser tracker for the PHP/MySQL collector
pixl_collect.php         Event collector and tracking pixel endpoint
pixl_pushover.php        Server-side Pushover transport
pixl_geoip.php           Shared local IPv4/IPv6 country lookup
pixl_server.php          Shared config, MySQL, auth, schema, bot detection
pixl_stats.php           Statistics dashboard and notification feed
pixl_setup_check.php     Password-protected IONOS/PHP/MySQL diagnostics
pixl_schema.sql          Complete central schema for fresh installations or manual setup
pixl_config.example.php  Example config for GitHub
pixl_config.ionos.example.php  IONOS-ready example config
demo.html                Minimal integration demo
docs/INSTALLATION.md     Detailed setup guide
docs/IONOS.md            IONOS-specific setup guide
docs/MYSQL-MIGRATION.md  Safe cutover of existing storage to central MySQL (German)
docs/API.md              Collector and dashboard endpoints
tools/update_geoip.php   Atomic monthly DB-IP Country Lite updater
tools/migrate_storage_to_mysql.php  Previewable legacy-data import into central MySQL
```

## Quick Start

These steps are for a new installation. For an existing installation, follow the [MySQL migration guide](docs/MYSQL-MIGRATION.md) before uploading or activating the changed collectors. Preserve the existing production configuration and secrets.

1. Upload the PHP and JS files to your PHP webspace.
2. Run `composer install --no-dev --optimize-autoloader` or upload the generated `vendor/` directory.
3. Copy `pixl_config.example.php` to `pixl_config.php`.
4. Edit database credentials, `site_id`, `allowed_hosts`, `hash_salt`, and `stats_password`.
5. Import `pixl_schema.sql` into the configured database and check `pixl_setup_check.php` before enabling tracking.
6. Download the local country database with `php tools/update_geoip.php`.
7. Include the tracker:

```html
<script src="https://example.com/stats3/pixl77.js" defer></script>
```

8. Open the dashboard:

```text
https://example.com/stats3/pixl_stats.php
```

`pixl_schema.sql` creates the complete central schema for a fresh installation; the migration tool applies that schema during an existing-installation import with `--commit`. Individual collectors and dashboards create or update only their own supported runtime tables, so opening them alone does not create every central table.

## Central MySQL Storage

`pixl_config.php` is the single source for the active MySQL/MariaDB host, database name, user, password, and character set. Stats3, STAT4, Mind, Impressions, and PPCMate all use that same database connection. Do not configure a separate active module database or another writable SQLite store.

The central database contains these table families:

- Stats3 and Web Push: `pixl_events`, `pixl_events_push_subscriptions`, `pixl_events_push_meta`
- STAT4: `stat4_visitors`, `stat4_sessions`, `stat4_events`, `stat4_notifications`
- Mind: `mind_geo_cache`, `mind_notifications`
- Impressions: `impressions`
- PPCMate: `ppcmate_attributions`, `ppcmate_conversions`
- Import audit and preserved legacy rows: `storage_migrations`, `legacy_sqlite_rows`

Use the [MySQL migration guide](docs/MYSQL-MIGRATION.md) for the complete backup, write-pause, deployment, preview, import, and verification order. The final STAT4 import requires `--commit --confirm-stat4-cutover`; the option confirms an established write pause and does not stop collectors itself. Both old writers and writers already routed to central MySQL must be paused before the final import.

`--skip-stat4` means the old STAT4 source is omitted, not migrated. Use it only when no old source exists or when its import is intentionally deferred. Include an old private PPCMate SQLite source explicitly with `--ppcmate-db`. Preserve all source backups and production secrets as described in the guide.

## IONOS Setup

For IONOS hosting, start with:

```text
docs/IONOS.md
```

Use `pixl_config.ionos.example.php` as the template for `pixl_config.php`. After upload, open:

```text
https://www.bayerchristian.de/stats3/pixl_setup_check.php
```

The setup check verifies PHP, PDO MySQL, the database connection, and whether the complete central schema is present.

## Production Embed

For production, `pixl77.js` can be hosted as a static JavaScript file on `www.inconsequential.org`:

```html
<script src="https://www.inconsequential.org/files/src/pixl77.js" defer></script>
```

No PHP or MySQL is needed on `www.inconsequential.org` for this file. It is only the static JS host.

The JavaScript runs inside the page where it is embedded, but it sends events to the PHP/MySQL collector on `www.bayerchristian.de`:

```text
https://www.bayerchristian.de/stats3/pixl_collect.php
```

Use a local file only for development, for example `./pixl77.js` in `demo.html`. The production version should be loaded from the static webspace URL above, not pasted inline.

If another domain embeds `https://www.inconsequential.org/files/src/pixl77.js`, add the page domain in both places:

- `ALLOWED_DOMAINS` inside `pixl77.js`
- `allowed_hosts` inside `pixl_config.php`

## Central Configuration for `count.js`

The shared Stats3/STAT4 `count.js` loads its public configuration from
`https://www.bayerchristian.de/stats3/configurator2.php` before starting either
tracker or the CAPTCHA check. Edit the `CONFIGURATION` array in `configurator2.php`
on the central PHP server. It contains the former JavaScript settings, plus the
integrated STAT4 endpoint, visitor lifetime and timer intervals. `KNOWN_RESOLUTIONS`
and a non-null `ACCEPTED_OS` are PHP arrays; the tracker converts them to JavaScript
Sets. The existing `configurator.php` continues to manage the private server settings.

`FINGERPRINT_EXCLUDE.ENABLED` controls the Stats3 exclusion. Its optional `RULES`
array contains alternatives; all populated fields within a rule must match. The
configured defaults exclude Germany (`DE`) with either an iPhone screen profile
of 390 x 844 CSS pixels (also in landscape) at pixel ratio 3, or a Macintosh desktop
with a reported screen resolution of exactly 2560 x 1440. The iPhone profile fits
the iPhone 16e but also other iPhones with identical screen values; browsers do not
provide a reliable iPhone model identifier. Country detection uses `VISITOR_COUNTRY`,
then `<html data-country>`, then the browser language's region as a fallback.
`Germany`, `Deutschland`, and `Germany (Deutschland)` are accepted as `DE`.
The original single-rule fields remain supported; empty rules exclude nobody.
STAT4 and CAPTCHA remain independent of this existing Stats3 filter. Both
`configurator2.php` and the updated `count.js` are needed for the new alternatives.

Upload `configurator2.php` to `/stats3/` on `www.bayerchristian.de` first, then upload
the updated `count.js` to every location where it is served, including
`/files/src/count.js` on `www.inconsequential.org`. External pages keep their existing
single script include and need no PHP files. Later setting changes require only an
update of the central `configurator2.php`; each page load requests fresh settings.
`captcha.php` and `captcha.js` continue to load from the directory of `SQL_ENDPOINT`.
Upload the accompanying `captcha.css` beside the central `captcha.js`. A strict
host CSP must permit this stylesheet origin in `style-src` (or `style-src-elem`
when present), or allow the nonce on the embedding script; the loader propagates
that nonce to the CAPTCHA script and stylesheet. Blocked or unavailable CSS
dismisses the gate without locking the page. `SwipeGateOptions.autoInit = false`
can defer a standalone gate until `SwipeGate.init()`; the shared tracker also
defers initialization while its page is suspended.

The browser console reports the CAPTCHA status after the server check, for example
`Captcha active — Prüfung für diesen Besucher erforderlich: nein.` The `active`
response field describes the global phase; `required` describes the current visitor.
Repeated polling prints another message only when the reported status changes.
An older PHP endpoint without `active` is reported as `status unknown` unless it
requires a challenge. Request and CAPTCHA script-load failures also produce a console
message. Upload `captcha.php`, `pixl_captcha.php`, and the updated `count.js` together
to enable the full diagnostic; no challenge token or visitor identifier is logged.

The endpoint returns public JSON with CORS for `ALLOWED_DOMAINS` and their subdomains.
It does not load `pixl_config.php`, connect to MySQL or expose private server settings.
Add new page domains to `ALLOWED_DOMAINS` here and to the server's `allowed_hosts` for
collector access. Never put passwords or private keys in this public configuration.

If the configuration is unavailable, invalid, blocked by CORS, or takes longer than
4.5 seconds, startup is skipped and a console warning is emitted. There is no embedded
configuration fallback in `count.js`. The page remains usable; tracking and the
CAPTCHA check depend on successfully loading the central settings first.
Measurements begin after that request completes; visits ending before configuration
arrives may not be recorded.

For another central server or a local test, override the configuration URL:

```html
<script src="/files/src/count.js"
        data-config-url="https://your-server.example/stats3/configurator2.php"
        defer></script>
```

Existing `data-stat4-endpoint` overrides still resolve relative to the embedding
script's URL. A Content Security Policy must allow the configuration endpoint in
`connect-src` as well as the existing collectors.

Local regression checks (no production requests):

```sh
php -l configurator2.php
node --check count.js
node tests/configurator2_http_test.js
node tests/count_runtime_test.js
node tests/captcha_client_test.js
```

## CAPTCHA thresholds and landing URLs

The protected `configurator.php` CAPTCHA section combines two global thresholds:
`visitor_interval` (new visitors passing without a challenge) and
`page_view_interval` ("Besucher Seiten Ansichten"). The second counter sums distinct
visitor/page combinations during the waiting phase across every allowed website.
A visitor viewing three different pages contributes three; a different visitor
viewing one of those pages contributes one more. Reloads, query parameters,
fragments and parallel tabs on the same page do not count again. The existing
24-hour inactivity rule also applies to page identities. Only page hashes are
stored in `pixl_captcha_page_views`.

The first N visitors still pass. From visitor N+1 onward, a new visitor can start
the phase only when the page threshold is also met, including the page just
opened. A known visitor can advance the page counter but remains exempt. Both
counters and their page deduplication records reset when the success target ends
the phase, its hours limit expires, or changed CAPTCHA settings start a fresh cycle. A page threshold of 0
keeps the previous visitor-only behavior.

"Maximale Captcha-Dauer (Stunden)" sets `captcha.max_duration_hours` to an integer
between 1 and 8760, defaulting to 4. The clock starts when the active challenge
phase begins; the preceding visitor/page counting period has no time limit.
Reloads, additional visitors and renewed ten-minute tickets do not extend the
phase. At the deadline the next CAPTCHA request closes the phase under the
existing database lock, saves its successes/failures/unresolved visitors as the
last phase and clears both waiting counters and the page deduplication records.
Existing `count.js` status polling dismisses an open challenge, normally within
15 seconds of the deadline; no cron job is needed. Late verifications cannot
add successes to the ended or next phase. The existing 24-hour known-visitor
exemption remains in effect. Reaching the success target can still end a phase
earlier, and the next active phase receives a new clock.

The schema updater automatically adds `pixl_captcha_state.phase_started_at`.
An active phase from an older installation keeps its tickets and gets its first
timestamp on the next CAPTCHA request, since its original start time is unknown.
The default four-hour option preserves the existing configuration revision.
The statistics summary reflects an expired phase immediately without writing
from the dashboard, including when no visitor has yet caused the stored reset.
For the hours-limit update, upload `configurator.php`, `pixl_captcha.php` and
`pixl_schema.sql` together. The Configurator saves the chosen hours to the existing
private configuration; replacing that configuration or updating tracker scripts
is unnecessary. Tests use synthetic timestamps and disposable databases.

"Captcha/Landing Urls" accepts one full HTTP(S) URL or absolute `/path` per line.
Full URLs match scheme, host, port and exact path; a path rule applies on every
already allowed host. Query parameters, fragments and default ports are ignored;
paths remain case-sensitive and trailing slashes remain significant. Empty means
all allowed pages. The landing list only restricts challenge display; other
tracked pages still contribute to the waiting counters. New visitors arriving
on another page during an active phase receive their challenge when they later
reach a landing page. No ticket or blocked-visitor count is created before that.
Status/failure/verification requests outside the landing list cannot require a
challenge or add failures/successes.

Deploy `configurator.php`, `captcha.php`, `pixl_captcha.php`, `pixl_server.php`,
`pixl_schema.sql` and `stats.php` together to the central Stats3 installation.
Existing `count.js` already submits the page URL. The CAPTCHA schema updater adds
the page table and counter automatically and preserves an existing phase when
the new options retain their defaults. The Configurator writes the selected
options to the existing private `pixl_config.php`. The dashboard shows page
progress and withholds its visitor-rate estimate while the page threshold is open.

Regression checks: `php tests/captcha_test.php`, `node tests/captcha_http_test.js`
and `node tests/configurator_captcha_browser_test.js`. Set `PIXL_CAPTCHA_TEST_DSN`
to a disposable `pixl_captcha_test_*` database; the HTTP/browser fixtures require
a Unix-socket DSN. The browser fixture also accepts `PLAYWRIGHT_MODULE`,
`BROWSER_EXECUTABLE` and `TEST_ARTIFACT_DIR` for the installed browser/runtime and
optional screenshots. Configurator saving is exercised only in a temporary copy.

## CAPTCHA statistics

The CAPTCHA section in `stats.php` shows current / last completed phase values for
successful checks, failed puzzle attempts and blocked visitors. Blocked visitors
are visitors who received a challenge without a confirmed success, including
abandoned or expired challenges. A success removes the visitor from the current
count. Reloads, ticket renewals and repeated puzzle misses do not add visitors;
the existing 24-hour inactivity rule still defines when a new visit starts.
This measures unresolved challenges, not visitors currently online or proof that
the browser still blocks access.

The last completed phase retains its count even after visitor records expire.
All CAPTCHA values are global and independent of dashboard date/country filters.
Upload `stats.php` and `pixl_captcha.php` together. The two nullable statistics
columns are added automatically while preserving existing tickets and counters;
`pixl_schema.sql` includes them for fresh installations. An older active phase or
missing historical count is shown as `–`; complete counts start with the next
phase. Tests: `php tests/captcha_test.php`, with `PIXL_CAPTCHA_TEST_DSN` pointing to
an isolated `pixl_captcha_test_*` database to include the MySQL scenarios.

## Optional No-JS Pixel

```html
<noscript>
  <img src="https://example.com/stats3/pixl_collect.php?pixel=1" width="1" height="1" alt="">
</noscript>
```

## Dashboard Notifications

Open `pixl_stats.php`, log in, then click `Browser-Notifikation aktivieren`.

The browser will ask for notification permission. After permission is granted, the dashboard polls the authenticated notification feed every 15 seconds and shows a native browser notification with a short sound for each new Pixl event. When new events are found, the visible statistics are refreshed without a full page reload.

The dashboard page must stay open for browser notifications and sound to run.

## Real Web Push Admin Notifications

Open `pixel_stats2.php`, log in with the same stats password, then click `Web Push fuer dieses Geraet aktivieren`.

This creates a real Push API subscription for the logged-in admin device only. The subscription is stored in MySQL, VAPID keys are generated automatically in the database, and `pixl_collect.php` sends a server-side Web Push notification to active admin subscriptions after each newly stored event.

Upload both files together:

```text
pixel_stats2.php
pixel_webpush_sw.js
```

Requirements for real Web Push:

- HTTPS on `https://www.bayerchristian.de`
- PHP OpenSSL extension
- Safari 16+ on macOS Ventura or newer, or another Push API capable browser
- Safari/macOS notification permission enabled for the site

## Server-Side Pushover

Open `configurator.php`, enter the Pushover Application Token and User/Group Key, select a sound and enable Pushover. `count.js` sends only structured browser measurements and event data to `pixl_collect.php`. PHP evaluates those values, builds the title and complete multi-line message, applies the configured URL filter, and sends the HTTPS request to Pushover. The secret keys and Pushover decision are never stored in JavaScript. Long reports are sent completely as numbered parts instead of being truncated at the transport limit.

The configured `Push- und Statistik-URLs` also control Pushover delivery. Messages are limited to the official Pushover title and message limits before sending.

The configured global Pushover throttle and hourly limit apply jointly to Stats3 and STAT4. The effective interval is the greater of `throttle_seconds` and `ceil(3600 / max_messages_per_hour)`; 0 disables the corresponding limit. Its timer starts after the last successful complete Pushover delivery. Further notifications from either collector during that interval are discarded and are not sent later.

The checkbox at the bottom right of the Pushover settings enables `pushover.reading_score_only` (off by default). It bypasses both limits and allows only stored Stats3 `LEAVE` events with `is_bot = 0` and `reading_score >= 1`. The score belongs to the current page visit, not a sum across the visitor's pages. Zero or missing scores and detected bots are suppressed. STAT4 milestone messages do not provide a ReadingScore and are also suppressed in this mode; manually requested test messages remain available. Disabling the checkbox restores the saved limits. Pushover activation and the configured URL filter still apply.

## Local IP-to-Country Database

Stats3 and STAT4 share `data/geoip/dbip-country-lite.mmdb`. The collector uses the complete visitor IP only in memory for the lookup; the lookup does not store that IP. Both IPv4 and IPv6 are supported. Update the database monthly:

```bash
php tools/update_geoip.php
```

The configurator controls whether local Geo-IP is enabled, whether its result is used in Pushover messages, and whether proxy headers may be trusted. Leave proxy-header trust disabled when Apache/PHP is directly reachable. With that safe default, `REMOTE_ADDR` is used and client-supplied `X-Forwarded-For` and Cloudflare headers are ignored.

DB-IP Country Lite is licensed under CC BY 4.0 and requires attribution: [IP Geolocation by DB-IP](https://db-ip.com).

## Requirements

- PHP 8.1 or newer
- MySQL or MariaDB
- PDO MySQL extension
- PDO SQLite extension for the one-time import of SQLite sources only
- OpenSSL extension for real Web Push
- cURL extension for Pushover
- Composer-installed `maxmind-db/reader` for local Geo-IP
- zlib for the monthly compressed DB-IP download
- HTTPS for browser notifications

## Security Notes

- Do not commit `pixl_config.php`.
- Change `hash_salt` before production use.
- Change `stats_password` before production use.
- Restrict `allowed_hosts` to your real domains.
- Use HTTPS.

## Development Checks

```bash
composer lint
```

Or run the GitHub Actions workflow after pushing the project.

## Counter corrections and regression tests

The September 11 counter release implements the central controls, protects form and fragment metadata, retains failed STAT4 deliveries, and fixes CAPTCHA/collector lifecycle contracts. See [the detailed correction and deployment report](docs/COUNT-JS-KORREKTUREN-2026-09-11.md) for setting semantics, complete upload files, finite retry limits, and reproducible tests. Run `composer test-client` and `composer test-http` for isolated client/configuration checks; database integration requires explicitly disposable test databases.
