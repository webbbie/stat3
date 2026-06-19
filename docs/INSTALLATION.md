# Installation

## 1. Upload Files

Upload these files to the same folder on your PHP webspace:

```text
pixl77.js
pixl_collect.php
pixl_server.php
pixl_stats.php
pixl_setup_check.php
pixl_config.example.php
```

For IONOS, prefer `pixl_config.ionos.example.php` and read `docs/IONOS.md`.

On the server, copy:

```text
pixl_config.example.php -> pixl_config.php
```

## 2. Configure MySQL

Edit `pixl_config.php`:

```php
'db' => [
    'host' => 'localhost',
    'database' => 'YOUR_DATABASE_NAME',
    'user' => 'YOUR_DATABASE_USER',
    'password' => 'YOUR_DATABASE_PASSWORD',
    'charset' => 'utf8mb4',
],
```

On IONOS, use the database host shown in the Control Center. It is often not `localhost`; it may look similar to `db5012345678.hosting-data.io`.

Set your site identity and allowed hosts:

```php
'site_id' => 'example.com',
'allowed_hosts' => [
    'example.com',
    'www.example.com',
],
```

Set a random salt and dashboard password:

```php
'hash_salt' => 'long-random-secret',
'stats_password' => 'your-dashboard-password',
```

`stats_password` may also be a `password_hash()` value.

## 3. Configure the JavaScript Endpoint

Open `pixl77.js`.

Set:

```js
SQL_ENDPOINT: "https://example.com/stats3/pixl_collect.php",
SQL_SITE_ID: "example.com",
```

If you use `SQL_PUBLIC_KEY`, set the same value in `pixl_config.php`.

## 4. Include the Tracker

Add this to your pages:

```html
<script src="https://example.com/stats3/pixl77.js" defer></script>
```

For your static `www.inconsequential.org` JavaScript setup, the production embed is:

```html
<script src="https://www.inconsequential.org/files/src/pixl77.js" defer></script>
```

This is an external static JavaScript file. It should be uploaded to `https://www.inconsequential.org/files/src/pixl77.js` and loaded from there. No PHP or MySQL is required on `www.inconsequential.org`.

The static JS file still sends all events to the PHP/MySQL collector configured inside `pixl77.js`:

```js
SQL_ENDPOINT: "https://www.bayerchristian.de/stats3/pixl_collect.php",
SQL_SITE_ID: "www.bayerchristian.de",
```

A local relative file such as `./pixl77.js` is useful only for development or demos.

Important: The JavaScript runs in the page that embeds it. If that page is on another domain, add the page domain to:

- `ALLOWED_DOMAINS` in `pixl77.js`
- `allowed_hosts` in `pixl_config.php`

Optional no-JS pixel:

```html
<noscript>
  <img src="https://example.com/stats3/pixl_collect.php?pixel=1" width="1" height="1" alt="">
</noscript>
```

## 5. Open the Dashboard

Go to:

```text
https://example.com/stats3/pixl_stats.php
```

Log in with `stats_password`.

The dashboard auto-creates and auto-upgrades the MySQL table.

Before opening the dashboard on IONOS, you can run:

```text
https://www.bayerchristian.de/stats3/pixl_setup_check.php
```

This checks PHP, PDO MySQL, the database connection, and table creation.

## 6. Enable Browser Notifications

In `pixl_stats.php`, click:

```text
Browser-Notifikation aktivieren
```

The browser permission prompt appears. After approval, the dashboard checks for new Pixl events every 15 seconds. New events create browser notifications with a short sound and refresh the visible statistics without a full page reload.

## Troubleshooting

- Blank dashboard: check `pixl_config.php` database credentials.
- No browser notifications: use HTTPS and keep `pixl_stats.php` open.
- No events: confirm `SQL_ENDPOINT` points to the uploaded collector.
- 403 collector response: check `allowed_hosts` and optional `public_key`.
- Bot detection missing: check whether the request has a User-Agent. Empty User-Agents still get scored.
