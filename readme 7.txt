Stats3 central MySQL and CHMOD recommendations
================================================

Deployment reference; production migration must be verified on the server.

Central storage
---------------
Stats3, STAT4, Mind, Impressions and PPCMate use the same MySQL/MariaDB
database. The only active database connection is the db block in
stats3/pixl_config.php.

Active table families:
- pixl_events and pixl_events_push_*
- stat4_visitors, stat4_sessions, stat4_events, stat4_notifications
- mind_geo_cache, mind_notifications
- impressions
- ppcmate_attributions, ppcmate_conversions
- storage_migrations, legacy_sqlite_rows

Do not configure another active database or a writable SQLite database for an
individual module.

Existing data migration
-----------------------
Follow docs/MYSQL-MIGRATION.md for the complete cutover procedure. Back up
the source, target, and existing secret configuration first. Pause both old
writers and writers already routed to central MySQL before deploying the
changed collectors and performing the final import. Preserve production
credentials and existing salts during deployment.

Run the preview from the actual stats3 folder:

php tools/migrate_storage_to_mysql.php

The final STAT4 import requires --commit --confirm-stat4-cutover after the
write pause; this flag does not stop writers itself. --skip-stat4 omits the
old source and does not mean it was migrated. Use it only if there is no old
source or the import is intentionally deferred. Include an older private
PPCMate SQLite source explicitly with --ppcmate-db, as shown in the guide.

Requirements: PHP 8.1+, PDO MySQL, and PDO SQLite for SQLite imports. For a
new installation, import pixl_schema.sql. Individual runtime schema updates
do not initialize the complete schema. Check pixl_setup_check.php and data
completeness before resuming writers, then verify controlled new events.

Never delete or modify the source SQLite files after migration. They remain
unchanged backups, owned by root with mode 600, and must not be writable by
the PHP worker.

General rules
-------------
755  Public directories
644  Public PHP, JavaScript, HTML, SQL, JSON, Markdown and text files
600  Private configuration files
600  Root-owned legacy SQLite backup files; never PHP-writable
700  Private legacy SQLite backup directories
2775 Atomic-write configuration directories where the PHP worker must write

Never use chmod 777.

Directories
-----------
755  stats3/docs/
755  stats3/examples/
755  stats3/stat/
700  stats3/stat/data/
2775 stats3/        Required for atomic pixl_config.php updates
2775 stats3/stat4/  Required for atomic STAT4 configuration updates
2775 stats3/mind/   Required for atomic Mind configuration updates

Active central-MySQL production files
-------------------------------------
644  stats3/index.html
644  stats3/pixl77.js
644  stats3/count.js
644  stats3/pixl_collect.php
644  stats3/pixl_pushover.php
600  stats3/pixl_config.php
644  stats3/pixl_server.php
644  stats3/pixl_schema.sql
644  stats3/stats.php
644  stats3/pixl_stats.php
644  stats3/stat/index.php
644  stats3/stat/dashboard.php
644  stats3/stat/impression.php
644  stats3/stat/dashboardx2.html
644  stats3/stat/checkthis.php
644  stats3/stat4/*.php
644  stats3/stat4/schema.sql
644  stats3/mind/*.php
644  stats3/mind/schema.sql
644  stats3/ppcmate-postback.php
644  stats3/configurator.php
644  stats3/reset_stats.php
644  stats3/tools/migrate_storage_to_mysql.php

Optional active features
------------------------
644  stats3/pixel_stats2.php
644  stats3/pixel_webpush_sw.js
644  stats3/pixl_setup_check.php
644  stats3/stat/dashboardx2.php

Sensitive configuration
-----------------------
600  stats3/pixl_config.php
600  stats3/pixl_config.php.tmp, if present
600  stats3/pixl_config.ionos.example.php, if it contains deployment values
600  stats3/stat4/config.local.php
600  stats3/stat4/config.local.php.tmp, if present
600  stats3/mind/config.local.php
600  stats3/mind/config.local.php.tmp, if present

Legacy SQLite backups
---------------------
600 root:root  stats3/stat/main.sqlite
600 root:root  stats3/stat/tracker.sqlite
600 root:root  stats3/stat/data/human.sqlite
600 root:root  stats3/stat/data/impressions.sqlite
600 root:root  stats3/stat/data/impressions-old*.sqlite
600 root:root  stats3/stat/data/track.sqlite

These files are migration sources and unchanged backups only. No active PHP
collector or dashboard may write to them. stats3/stat/dashboardx.php is a
retired compatibility redirect to stats3/stat/index.php and does not open
SQLite.

Files to remove instead of deploying
------------------------------------
REMOVE  stats3/.DS_Store
REMOVE  stats3/stat/.DS_Store
REMOVE  stats3/stat/data/.DS_Store

Apply and verify rights on an Ubuntu/IONOS vServer
-------------------------------------------------
Upload either identical rights script into the stats3 folder, then run one:

sudo bash ./chmod.txt
sudo bash ./stats3-ionos-apache-rechte.sh

If worker detection is wrong, append the real worker user, for example:

sudo bash ./chmod.txt www-data

Both scripts set all legacy SQLite backups to root:root 600 and fail if the
selected PHP worker can still write to one of them.

Reset statistics on the server
------------------------------
Open the protected browser page:

https://www.bayerchristian.de/stats3/reset_stats.php

Or run this command from inside the stats3 folder through SSH or the server
shell:

php reset_stats.php --confirm

The browser page resets statistics and can optionally remove push
subscriptions. The CLI command resets the active MySQL tables; legacy SQLite
backups remain unchanged.

Production URLs
---------------
https://www.bayerchristian.de/stats3/pixl_collect.php
https://www.bayerchristian.de/stats3/stat4/collect.php
https://www.bayerchristian.de/stat/impression.php
https://www.bayerchristian.de/stats3/ppcmate-postback.php
https://www.bayerchristian.de/stats3/stats.php
https://www.bayerchristian.de/stats3/pixl_stats.php
https://www.bayerchristian.de/stats3/configurator.php
https://www.bayerchristian.de/stats3/reset_stats.php
https://www.bayerchristian.de/stats3/stat/index.php
