# IONOS Setup

This project is designed to work well on an IONOS PHP webspace with an IONOS MySQL/MariaDB database.

## Architecture

```text
Static tracker:
https://www.inconsequential.org/files/src/pixl77.js

PHP/MySQL collector and dashboard:
https://www.bayerchristian.de/stats3/pixl_collect.php
https://www.bayerchristian.de/stats3/pixl_stats.php
```

`www.inconsequential.org` only needs to host the static JavaScript file. PHP and MySQL are required only on `www.bayerchristian.de`.

## 1. Create Or Find The IONOS Database

In the IONOS Control Center, open the hosting package for `bayerchristian.de` and find the MySQL database area.

Create a MySQL database if needed. IONOS will show values similar to:

```text
Database name: dbs12345678
User name:     dbo12345678
Host name:     db5012345678.hosting-data.io
Password:      your chosen database password
```

The exact host name matters. Do not assume `localhost` unless IONOS explicitly shows it.

## 2. Upload PHP Files To Bayerchristian

Upload these files to the PHP webspace for `www.bayerchristian.de`:

```text
pixl_collect.php
pixl_server.php
pixl_stats.php
pixl_setup_check.php
pixl_config.ionos.example.php
```

Then copy:

```text
pixl_config.ionos.example.php -> pixl_config.php
```

Edit `pixl_config.php`:

```php
'db' => [
    'host' => 'db5012345678.hosting-data.io',
    'database' => 'dbs12345678',
    'user' => 'dbo12345678',
    'password' => 'YOUR_IONOS_DATABASE_PASSWORD',
    'charset' => 'utf8mb4',
    'timeout' => 8,
],
```

Also set:

```php
'hash_salt' => 'a-long-random-secret',
'stats_password' => 'your-dashboard-password',
```

## 3. Run The Setup Check

Open:

```text
https://www.bayerchristian.de/stats3/pixl_setup_check.php
```

Log in with `stats_password`.

The check verifies:

- PHP version
- PDO extension
- PDO MySQL driver
- database host/name/user values
- MySQL connection
- automatic schema/table creation

If the schema check is OK, the database side is ready.

## 4. Upload Static JavaScript To Inconsequential

Upload only this file to the static location:

```text
pixl77.js -> https://www.inconsequential.org/files/src/pixl77.js
```

No PHP or SQL is needed there.

Inside `pixl77.js`, keep:

```js
SQL_ENDPOINT: "https://www.bayerchristian.de/stats3/pixl_collect.php",
SQL_SITE_ID: "www.bayerchristian.de",
```

## 5. Embed The Tracker

On pages you want to track:

```html
<script src="https://www.inconsequential.org/files/src/pixl77.js" defer></script>
```

Optional no-JS pixel:

```html
<noscript>
  <img src="https://www.bayerchristian.de/stats3/pixl_collect.php?pixel=1" width="1" height="1" alt="">
</noscript>
```

## 6. Open The Dashboard

```text
https://www.bayerchristian.de/stats3/pixl_stats.php
```

Enable browser notifications in the dashboard if desired. The dashboard checks for new entries every 15 seconds and plays a short sound for each new browser notification.

## Common IONOS Problems

### SQLSTATE[HY000] [2002]

Usually the database host is wrong. Copy the host from the IONOS database details. It may look like `db5012345678.hosting-data.io`.

### Access denied for user

The database user or password is wrong. On IONOS the database name and username are often different values.

### Table creation fails

Make sure the MySQL user belongs to the selected database and has permission to create tables.

### Browser notifications do not work

Use HTTPS and keep `pixl_stats.php` open in the browser.
