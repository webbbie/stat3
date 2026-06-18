<?php
declare(strict_types=1);

/**
 * Pixl SQL configuration
 *
 * Diese Datei auf dem PHP-Webspace anpassen und zusammen mit
 * pixl_collect.php, pixl_server.php und pixl_stats.php hochladen.
 */
return [
    'db' => [
        'host' => 'localhost',
        'database' => 'DATENBANKNAME',
        'user' => 'DATENBANKUSER',
        'password' => 'DATENBANKPASSWORT',
        'charset' => 'utf8mb4',
    ],

    'table' => 'pixl_events',
    'site_id' => 'www.bayerchristian.de',

    // Erlaubte Seiten/Hosts, von denen pixl6.js Events annehmen darf.
    'allowed_hosts' => [
        'bayerchristian.de',
        'www.bayerchristian.de',
        'inconsequential.org',
        'www.inconsequential.org',
        'localhost',
        '127.0.0.1',
    ],

    // Optional: in pixl6.js CONFIG.SQL_PUBLIC_KEY setzen und hier denselben Wert eintragen.
    // Leer lassen, wenn kein Public-Key-Check gewuenscht ist.
    'public_key' => '',

    // Wird nur fuer den anonymisierten visitor_hash genutzt. Bitte auf dem Server aendern.
    'hash_salt' => 'BITTE-AUF-DEM-SERVER-AENDERN',

    // Statistik-Login. Bitte auf dem Server aendern.
    // Nach erfolgreichem Login bleibt der Browser per signiertem Cookie angemeldet.
    'stats_password' => 'BITTE-AENDERN',
    'stats_cookie_name' => 'pixl_stats_login',
    'stats_auto_login_days' => 30,
];
