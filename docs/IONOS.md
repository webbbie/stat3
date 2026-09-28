# IONOS Setup

This project is designed to work well on an IONOS PHP webspace with an IONOS MySQL/MariaDB database.

Use PHP 8.1 or newer with PDO MySQL. The one-time SQLite import also requires PDO SQLite in CLI PHP. These installation steps are for a new deployment. For an existing deployment, complete the [MySQL migration procedure](MYSQL-MIGRATION.md) before enabling changed collectors; it includes backups, pausing old and newly routed writers, and preserving production configuration and secrets.

## Architecture

```text
Static tracker:
https://www.inconsequential.org/files/src/pixl77.js

PHP/MySQL collectors and dashboards:
https://www.bayerchristian.de/stats3/pixl_collect.php
https://www.bayerchristian.de/stats3/pixl_stats.php
https://www.bayerchristian.de/stats3/stat4/collect.php
https://www.bayerchristian.de/stat/impression.php
https://www.bayerchristian.de/stats3/ppcmate-postback.php
```

`www.inconsequential.org` only needs to host the static JavaScript file. PHP and MySQL are required only on `www.bayerchristian.de`. Stats3, STAT4, Mind, Impressions, and PPCMate all store their active data in the same IONOS MySQL/MariaDB database.

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
pixl_schema.sql
pixl_stats.php
pixl_setup_check.php
pixl_config.ionos.example.php
stat4/
mind/
stat/
ppcmate-postback.php
tools/migrate_storage_to_mysql.php
```

For a new installation only, copy:

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

This `db` block in `pixl_config.php` is the only active database configuration. STAT4, Mind, the impression collector, and PPCMate reuse it. Do not create another active database or a writable SQLite database for an individual module.

## 3. Run The Setup Check

For a new installation, import `pixl_schema.sql` into the configured database first. For an existing installation, complete the migration procedure below while collectors remain paused.

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
- presence of the complete central schema

If the schema check is OK, the central database connection and expected table names are present. The authenticated check creates missing module tables from `pixl_schema.sql` and verifies all 17 central tables, including CAPTCHA. Existing rows are preserved. Creating tables does not import or verify historical data. `pixl_schema.sql` contains the complete schema for Stats3, STAT4, Mind, Impressions, PPCMate, and migration bookkeeping.

## 4. Import Existing Data

Skip this step on a completely new installation. Use [MySQL migration](MYSQL-MIGRATION.md) for an existing installation. Follow its backup and write-pause order before deployment and final import. The real STAT4 import uses `--commit --confirm-stat4-cutover` only after old and newly routed writers have stopped. `--skip-stat4` omits the source; it does not migrate it. Supply an old private PPCMate SQLite source explicitly with `--ppcmate-db`.

Never delete the imported SQLite files. Keep them unchanged as offline/legacy backups. On an IONOS vServer, run the supplied rights script as root; it makes every SQLite backup root-owned with mode `600` and verifies that the PHP worker cannot write to it:

```bash
sudo bash ./stats3-ionos-apache-rechte.sh
```

## 5. Upload Static JavaScript To Inconsequential

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

## 6. Embed The Tracker

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

## 7. Open The Dashboard

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

### One module is empty

Confirm that the module uses the same `pixl_config.php` database as Stats3. Then run the migration preview again and check whether its old source is detected.

### Browser notifications do not work

Use HTTPS and keep `pixl_stats.php` open in the browser.
