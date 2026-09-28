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

    // Only these query-free URLs trigger push messages and appear in pixl_stats.php.
    // Leave empty to include every URL.
    'stats_urls' => [
        'https://www.example.com/',
        '/landing-page/',
    ],

    // Local DB-IP Country Lite lookup. Keep trust_proxy_headers disabled when
    // Apache/PHP is reached directly; otherwise forwarded headers are spoofable.
    'geoip' => [
        'enabled' => true,
        'database_path' => 'data/geoip/dbip-country-lite.mmdb',
        'pushover_country' => true,
        'trust_proxy_headers' => false,
    ],

    // Pushover runs only in PHP. Never put these secrets into pixl6.js.
    'pushover' => [
        'enabled' => false,
        'token' => 'YOUR_PUSHOVER_APPLICATION_TOKEN',
        'user' => 'YOUR_PUSHOVER_USER_OR_GROUP_KEY',
        'sound' => 'gamelan',
        'priority' => 0,
        'timeout' => 8,
        'throttle_seconds' => 90,
        'max_messages_per_hour' => 10,
        'reading_score_only' => false,
    ],

    'captcha' => [
        'enabled' => false,
        'visitor_interval' => 100,
        'page_view_interval' => 0, // Unique pages per visitor, summed between phases; 0 disables this additional threshold.
        'landing_urls' => [], // One full HTTP(S) URL or /path per entry; empty allows every tracked page.
        'success_target' => 10,
        'max_duration_hours' => 4, // Maximum active phase duration; expiry restarts both waiting counters.
    ],

    // Optional: set the same value in pixl77.js CONFIG.SQL_PUBLIC_KEY.
    'public_key' => '',

    // Change this to a long random string before production use.
    'hash_salt' => 'CHANGE-ME-TO-A-LONG-RANDOM-SECRET',

    // Plain text is supported. A password_hash() value is also supported.
    'stats_password' => 'CHANGE-ME',
    'stats_cookie_name' => 'pixl_stats_login',
    'stats_auto_login_days' => 30,
];
