<?php
declare(strict_types=1);

/**
 * Pixl SQL example configuration.
 *
 * Copy this file to pixl_config.php on your webspace and edit all placeholders.
 * Do not commit your real pixl_config.php with database credentials.
 */
return [
    'db' => [
        // IONOS often uses a database host from the Control Center,
        // for example "db5012345678.hosting-data.io".
        // Use "localhost" only if IONOS explicitly shows localhost.
        'host' => 'db5012345678.hosting-data.io',
        'database' => 'YOUR_DATABASE_NAME',
        'user' => 'YOUR_DATABASE_USER',
        'password' => 'YOUR_DATABASE_PASSWORD',
        'charset' => 'utf8mb4',
        'timeout' => 8,
    ],

    'table' => 'pixl_events',
    'site_id' => 'example.com',

    'allowed_hosts' => [
        'www.bayerchristian.de',
        'bayerchristian.de',
        'www.inconsequential.org',
        'inconsequential.org',
        'example.com',
        'www.example.com',
        'localhost',
        '127.0.0.1',
    ],

    // Optional: set the same value in pixl6.js CONFIG.SQL_PUBLIC_KEY.
    'public_key' => '',

    // Change this to a long random string before production use.
    'hash_salt' => 'CHANGE-ME-TO-A-LONG-RANDOM-SECRET',

    // Plain text is supported. A password_hash() value is also supported.
    'stats_password' => 'CHANGE-ME',
    'stats_cookie_name' => 'pixl_stats_login',
    'stats_auto_login_days' => 30,
];
