# Pixl SQL Tracker

Pixl SQL Tracker is a small self-hosted PHP/MySQL analytics collector with a browser-side tracker, bot-aware server-side storage, and a password-protected statistics dashboard.

It replaces the older Pushover notification flow with SQL statistics, browser notifications, sound alerts, bot detection, and full User-Agent inspection.

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
- Automatic MySQL table creation and migration for new columns
- No Composer or npm dependencies

## Project Files

```text
pixl77.js                Browser tracker for the PHP/MySQL collector
pixl_collect.php         Event collector and tracking pixel endpoint
pixl_server.php          Shared config, MySQL, auth, schema, bot detection
pixl_stats.php           Statistics dashboard and notification feed
pixl_setup_check.php     Password-protected IONOS/PHP/MySQL diagnostics
pixl_schema.sql          Optional manual schema
pixl_config.example.php  Example config for GitHub
pixl_config.ionos.example.php  IONOS-ready example config
demo.html                Minimal integration demo
docs/INSTALLATION.md     Detailed setup guide
docs/IONOS.md            IONOS-specific setup guide
docs/API.md              Collector and dashboard endpoints
```

## Quick Start

1. Upload the PHP and JS files to your PHP webspace.
2. Copy `pixl_config.example.php` to `pixl_config.php`.
3. Edit database credentials, `site_id`, `allowed_hosts`, `hash_salt`, and `stats_password`.
4. Include the tracker:

```html
<script src="https://example.com/stats3/pixl77.js" defer></script>
```

5. Open the dashboard:

```text
https://example.com/stats3/pixl_stats.php
```

The database table is created automatically on the first collector or dashboard request.

## IONOS Setup

For IONOS hosting, start with:

```text
docs/IONOS.md
```

Use `pixl_config.ionos.example.php` as the template for `pixl_config.php`. After upload, open:

```text
https://www.bayerchristian.de/stats3/pixl_setup_check.php
```

The setup check verifies PHP, PDO MySQL, the database connection, and automatic table creation.

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

## Requirements

- PHP 7.4 or newer
- MySQL or MariaDB
- PDO MySQL extension
- OpenSSL extension for real Web Push
- HTTPS for browser notifications

## Security Notes

- Do not commit `pixl_config.php`.
- Change `hash_salt` before production use.
- Change `stats_password` before production use.
- Restrict `allowed_hosts` to your real domains.
- Use HTTPS.

## Development Checks

```bash
php -l pixl_server.php
php -l pixl_collect.php
php -l pixl_stats.php
php -l pixel_stats2.php
php -l pixl_setup_check.php
node --check pixel_webpush_sw.js
node --check pixl77.js
```

Or run the GitHub Actions workflow after pushing the project.
