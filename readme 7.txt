Stats3 file and CHMOD recommendations
=====================================

Checked against the files currently inside:
/Users/christianbayer/Documents/meinpixelsql/stats3

Recommended for typical PHP hosting / IONOS.

General rules
-------------
755  Public directories
644  Public PHP, JavaScript, HTML, SQL, JSON, Markdown and text files
600  Private configuration and private analytics database files
700  Private writable data directories

If the PHP process runs as a different group, use 640 for private read-only
configuration, 660 for writable SQLite files and 770 for their data folder.
Never use chmod 777.

Directories
-----------
755  stats3/
755  stats3/.github/
755  stats3/.github/workflows/
755  stats3/docs/
755  stats3/examples/
755  stats3/stat/
755  stats3/stat/data/

Active MySQL production files
-----------------------------
644  stats3/index.html
644  stats3/pixl77.js
644  stats3/pixl_collect.php
600  stats3/pixl_config.php
644  stats3/pixl_server.php
644  stats3/stats.php
644  stats3/pixl_stats.php
644  stats3/stat/index.php
644  stats3/stat/dashboard.php
644  stats3/stat/dashboardx2.html
644  stats3/stat/checkthis.php
644  stats3/configurator.php
644  stats3/reset_stats.php

Optional active features
------------------------
644  stats3/pixel_stats2.php
644  stats3/pixel_webpush_sw.js
644  stats3/pixl_setup_check.php
644  stats3/stat/dashboardx2.php

Project, documentation and development files
--------------------------------------------
644  stats3/.editorconfig
644  stats3/.gitignore
644  stats3/.github/workflows/ci.yml
644  stats3/CHANGELOG.md
644  stats3/LICENSE
644  stats3/README.md
644  stats3/SECURITY.md
644  stats3/composer.json
644  stats3/demo.html
644  stats3/docs/API.md
644  stats3/docs/INSTALLATION.md
644  stats3/docs/IONOS.md
644  stats3/examples/bayerchristian-embed.html
644  stats3/pixl_config.example.php
644  stats3/pixl_schema.sql
644  stats3/readme 7.txt

Sensitive uploaded example
--------------------------
600  stats3/pixl_config.ionos.example.php

This file currently contains deployment-specific configuration. Prefer removing
it from the production server after copying the required values into
stats3/pixl_config.php. Do not commit real credentials to GitHub.

Legacy or backup files not required by the MySQL production setup
-----------------------------------------------------------------
644  stats3/pixl6.js
644  stats3/pixl_stats Kopie.php
644  stats3/pixl_stats Kopie 2.php
644  stats3/pixl_stats33.php
644  stats3/stat/dashboardx.php

Files to remove instead of deploying
------------------------------------
REMOVE  stats3/.DS_Store
REMOVE  stats3/stat/.DS_Store
REMOVE  stats3/stat/data/.DS_Store

Commands from the meinpixelsql parent folder
--------------------------------------------
find stats3 -type d -exec chmod 755 {} \;
find stats3 -type f -exec chmod 644 {} \;
chmod 600 stats3/pixl_config.php
chmod 600 stats3/pixl_config.ionos.example.php

Reset statistics on the server
------------------------------
Open the protected browser page:

https://www.bayerchristian.de/stats3/reset_stats.php

Or run this command from inside the stats3 folder through SSH or the server shell:

php reset_stats.php --confirm

The browser page resets statistics and can optionally remove push subscriptions.
The CLI command resets both tables. Schemas and Web Push configuration remain
intact.

Push and statistics URL filter
------------------------------
Open stats3/configurator.php and enter one full URL or root-relative path per
line under "Push- und Statistik-URLs". Query parameters and URL fragments are
ignored. Only matching events trigger push messages and appear in
stats3/pixl_stats.php. An empty list includes every URL.

Production URLs after the folder move
-------------------------------------
https://www.bayerchristian.de/stats3/pixl_collect.php
https://www.bayerchristian.de/stats3/stats.php
https://www.bayerchristian.de/stats3/pixl_stats.php
https://www.bayerchristian.de/stats3/configurator.php
https://www.bayerchristian.de/stats3/reset_stats.php
https://www.bayerchristian.de/stats3/stat/dashboard.php
https://www.bayerchristian.de/stats3/stat/dashboardx2.html
