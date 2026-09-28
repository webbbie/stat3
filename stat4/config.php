<?php
declare(strict_types=1);

$defaults = [
    'admin' => [
        'cookie_name' => 'stat4_admin',
        'autologin' => true,
        'password_hash' => '',
    ],
    'pushover' => [
        'application_token' => '',
        'user_key' => '',
        'sound' => '',
        'priority' => 0,
        'timeout' => 8,
    ],
    'dashboard' => [
        'title' => 'Website Statistik',
        'timezone' => 'Europe/Berlin',
        'allowed_origins' => [],
    ],
];

$localFile = __DIR__ . '/config.local.php';
$local = is_file($localFile) ? require $localFile : [];
if (!is_array($local)) {
    $local = [];
}

// Alte STAT4-Datenbankwerte werden bewusst ignoriert. Die Verbindung kommt
// zentral aus ../pixl_config.php ueber pixl_pdo().
unset($local['db']);

return array_replace_recursive($defaults, $local);
