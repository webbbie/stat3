<?php
declare(strict_types=1);

/**
 * IONOS example configuration.
 *
 * Copy this file to pixl_config.php on the PHP webspace at
 * www.bayerchristian.de and replace all placeholder values with the
 * MySQL data shown in the IONOS Control Center.
 */
return [
    'db' => [
        // IONOS usually shows a host similar to db5012345678.hosting-data.io.
        // Do not guess this value; copy it from the IONOS database details.
        'host' => '127.0.0.1',

        // IONOS database name/user examples often look like dbs12345678
        // and dbo12345678, but your exact values may differ.
        'database' => 'website_db',
        'user' => 'website_user',
        'password' => 'itallinside0z',
        'charset' => 'utf8mb4',
        'timeout' => 8,
    ],

    'table' => 'pixl_events',
    'site_id' => 'www.bayerchristian.de',

    'allowed_hosts' => [
        'www.bayerchristian.de',
        'bayerchristian.de',
        'www.inconsequential.org',
        'inconsequential.org',
    ],

    // Optional. Leave empty unless you also set CONFIG.SQL_PUBLIC_KEY in pixl77.js.
    'public_key' => '',

    // Generate a long random value and keep it private.
    'hash_salt' => 'flfgldhlbfghyeokw8790521',

    // Dashboard password. Plain text works; password_hash() output also works.
    'stats_password' => 'myadmno',
    'stats_cookie_name' => 'pixl_stats_login',
    'stats_auto_login_days' => 5,
];
