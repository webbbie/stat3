<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
stat4_require_admin();

$config = stat4_config();
$checks = [];
$add = static function (string $group, string $label, string $status, string $detail) use (&$checks): void {
    $checks[] = compact('group','label','status','detail');
};

$add('System', 'PHP-Version', version_compare(PHP_VERSION, '8.1.0', '>=') ? 'ok' : 'fail', PHP_VERSION . ' · benötigt mindestens 8.1');
foreach (['pdo'=>'PDO','pdo_mysql'=>'PDO MySQL','json'=>'JSON','mbstring'=>'mbstring','openssl'=>'OpenSSL'] as $extension=>$label) {
    $add('System', 'PHP-Erweiterung ' . $label, extension_loaded($extension) ? 'ok' : 'fail', extension_loaded($extension) ? 'geladen' : 'fehlt');
}
$curlAvailable = extension_loaded('curl');
$streamAvailable = (bool) ini_get('allow_url_fopen');
$add('System', 'HTTPS-Client', ($curlAvailable || $streamAvailable) ? 'ok' : 'fail', $curlAvailable ? 'cURL verfügbar' : ($streamAvailable ? 'PHP HTTPS-Streams verfügbar' : 'cURL und allow_url_fopen fehlen'));
$memory = ini_get('memory_limit') ?: 'unbegrenzt';
$add('System', 'PHP Speicherlimit', 'info', $memory);

$geoipStatus = pixl_geoip_database_status();
$add(
    'Geo-IP',
    'Lokale Ländererkennung',
    !$geoipStatus['enabled'] ? 'warn' : ($geoipStatus['database_ready'] && $geoipStatus['reader_ready'] ? 'ok' : 'fail'),
    !$geoipStatus['enabled']
        ? 'In der Stats3-Konfiguration deaktiviert'
        : ($geoipStatus['database_ready'] && $geoipStatus['reader_ready']
            ? number_format((int)$geoipStatus['size'], 0, ',', '.') . ' Bytes · IPv4 und IPv6'
            : 'MMDB-Datei oder PHP-Reader fehlt')
);
$add('Geo-IP', 'Pushover-Land', pixl_geoip_pushover_country_enabled() ? 'ok' : 'info', pixl_geoip_pushover_country_enabled() ? 'Lokales IP-Land wird verwendet' : 'Browser-Locale wird verwendet');
$add('Geo-IP', 'Proxy-Header', pixl_geoip_trust_proxy_headers() ? 'warn' : 'ok', pixl_geoip_trust_proxy_headers() ? 'Nur bei vertrauenswürdigem Reverse Proxy aktivieren' : 'Aus · REMOTE_ADDR ist maßgeblich');

$localConfig = __DIR__ . '/config.local.php';
$add('Dateien', 'STAT4-Einstellungen', is_file($localConfig) ? 'ok' : 'warn', is_file($localConfig) ? 'config.local.php vorhanden · alte DB-Werte darin werden ignoriert' : 'Noch keine lokalen STAT4-Einstellungen gespeichert');
$writable = is_file($localConfig) ? is_writable($localConfig) : is_writable(__DIR__);
$add('Dateien', 'Konfiguration beschreibbar', $writable ? 'ok' : 'fail', $writable ? 'Speichern ist möglich' : 'PHP benötigt Schreibrecht für den STAT4-Ordner');
$centralConfig = dirname(__DIR__) . '/pixl_config.php';
$centralConfigReady = is_file($centralConfig) && is_readable($centralConfig);
$add('Dateien', 'Zentrale Stats3-Konfiguration', $centralConfigReady ? 'ok' : 'fail', $centralConfigReady ? '../pixl_config.php wird für MySQL verwendet' : '../pixl_config.php fehlt oder ist nicht lesbar');
foreach (['count.js','collect.php','api.php','reset.php','schema.sql'] as $file) {
    $valid = is_file(__DIR__ . '/' . $file) && is_readable(__DIR__ . '/' . $file) && filesize(__DIR__ . '/' . $file) > 0;
    $add('Dateien', $file, $valid ? 'ok' : 'fail', $valid ? number_format((int) filesize(__DIR__ . '/' . $file), 0, ',', '.') . ' Bytes' : 'fehlt, ist leer oder nicht lesbar');
}

$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$requestHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
$localRequest = in_array($requestHost, ['127.0.0.1','localhost'], true) || str_starts_with($requestHost, '127.0.0.1:') || str_starts_with($requestHost, 'localhost:');
$add('Sicherheit', 'HTTPS', ($https || $localRequest) ? 'ok' : 'warn', $https ? 'Verbindung ist verschlüsselt' : ($localRequest ? 'Lokale Testverbindung' : 'Für Login und Cookies HTTPS aktivieren'));
$hashInfo = password_get_info((string) ($config['admin']['password_hash'] ?? ''));
$add('Sicherheit', 'Admin-Passwort', !empty($hashInfo['algo']) ? 'ok' : 'fail', !empty($hashInfo['algoName']) ? 'Hash: ' . $hashInfo['algoName'] : 'Kein gültiger Passwort-Hash');
$cookieName = (string) ($config['admin']['cookie_name'] ?? '');
$add('Sicherheit', 'Admin-Cookie', preg_match('/^[A-Za-z][A-Za-z0-9_-]{2,50}$/', $cookieName) ? 'ok' : 'fail', $cookieName ?: 'nicht konfiguriert');
try {
    stat4_hash_salt();
    $hashSaltSource = getenv('STAT4_HASH_SALT') !== false && getenv('STAT4_HASH_SALT') !== ''
        ? 'STAT4_HASH_SALT' : '../pixl_config.php';
    $add('Sicherheit', 'Besucher-Hash-Salt', 'ok', 'Individueller Salt aus ' . $hashSaltSource . ' wird verwendet');
} catch (Throwable $saltError) {
    $add('Sicherheit', 'Besucher-Hash-Salt', 'fail', $saltError->getMessage());
}

$requiredTables = ['stat4_visitors','stat4_sessions','stat4_events','stat4_notifications'];
try {
    $pdo = stat4_db();
    $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    $add('MySQL', 'Zentrale Stats3-Verbindung', 'ok', 'Datenbank ' . $database . ' · ' . $version);
    try {
        $unifiedSchema = pixl_ensure_unified_schema($pdo);
        $schemaDetail = 'Alle ' . count($unifiedSchema['tables']) . ' zentralen Tabellen vorhanden · Datenimport separat pruefen';
        if ($unifiedSchema['created']) $schemaDetail .= ' · neu angelegt: ' . implode(', ', $unifiedSchema['created']);
        $add('MySQL', 'Zentrales Schema', 'ok', $schemaDetail);
    } catch (Throwable $schemaError) {
        $add('MySQL', 'Zentrales Schema', 'fail', $schemaError->getMessage());
    }
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $tableRows = $pdo->prepare('SELECT TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    foreach ($requiredTables as $table) {
        $exists = in_array($table, $tables, true);
        if ($exists) { $tableRows->execute([$table]); $estimatedRows = (int) $tableRows->fetchColumn(); }
        $detail = $exists ? 'vorhanden · ca. ' . number_format($estimatedRows, 0, ',', '.') . ' Datensätze' : 'fehlt · schema.sql importieren';
        $add('MySQL', $table, $exists ? 'ok' : 'fail', $detail);
    }
    if (in_array('stat4_visitors', $tables, true)) {
        $visitorColumns = $pdo->query("SHOW COLUMNS FROM stat4_visitors")->fetchAll(PDO::FETCH_COLUMN);
        $prefixColumnsReady = in_array('first_ip_prefix', $visitorColumns, true) && in_array('last_ip_prefix', $visitorColumns, true);
        $add(
            'MySQL',
            'IP-Präfix (3 Segmente)',
            $prefixColumnsReady ? 'ok' : 'warn',
            $prefixColumnsReady ? 'first_ip_prefix und last_ip_prefix vorhanden' : 'Wird beim nächsten Tracking-Aufruf automatisch ergänzt'
        );
    }
    $charset = (string) $pdo->query('SELECT @@character_set_connection')->fetchColumn();
    $centralDb = pixl_config()['db'] ?? [];
    $configuredCharset = (string) ($centralDb['charset'] ?? 'utf8mb4');
    $add('MySQL', 'Zeichensatz', $charset === $configuredCharset ? 'ok' : 'warn', 'Verbindung: ' . $charset . ' · zentral konfiguriert: ' . $configuredCharset);
} catch (Throwable $databaseError) {
    $add('MySQL', 'Zentrale Stats3-Verbindung', 'fail', $databaseError->getMessage());
    foreach ($requiredTables as $table) $add('MySQL', $table, 'unknown', 'Ohne Datenbankverbindung nicht prüfbar');
}

$push = $config['pushover'] ?? [];
$hasToken = trim((string) ($push['application_token'] ?? '')) !== '';
$hasUser = trim((string) ($push['user_key'] ?? '')) !== '';
$pushReady = $hasToken && $hasUser && ($curlAvailable || $streamAvailable);
$add('Pushover', 'Application Token', $hasToken ? 'ok' : 'warn', $hasToken ? 'gespeichert' : 'nicht eingetragen');
$add('Pushover', 'User Key', $hasUser ? 'ok' : 'warn', $hasUser ? 'gespeichert' : 'nicht eingetragen');
$add('Pushover', 'Versandbereitschaft', $pushReady ? 'ok' : 'warn', $pushReady ? 'Bereit · Sound ' . (($push['sound'] ?? '') ?: 'Standard') . ' · Priorität ' . ($push['priority'] ?? 0) . ' · Timeout ' . ($push['timeout'] ?? 8) . 's' : 'Zugangsdaten und HTTPS-Client prüfen');

$counts = ['ok'=>0,'warn'=>0,'fail'=>0,'info'=>0,'unknown'=>0];
foreach ($checks as $check) $counts[$check['status']]++;
$overall = $counts['fail'] > 0 ? 'fail' : ($counts['warn'] > 0 ? 'warn' : 'ok');
$groups = [];
foreach ($checks as $check) $groups[$check['group']][] = $check;
$h = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="dark"><title>STAT4 Systemcheck</title><link rel="stylesheet" href="styles.css"><link rel="stylesheet" href="admin.css"></head>
<body class="admin-body"><header class="admin-header"><div><p class="eyebrow">STAT4 DIAGNOSTICS</p><h1>Systemcheck</h1></div><nav><a href="index.php">Statistik</a><a href="configurator.php">Konfiguration</a><a href="reset.php">Reset</a><a href="systemcheck.php">Erneut prüfen</a></nav></header>
<main class="config-shell"><section class="health-summary <?= $h($overall) ?>"><div><span class="health-dot"></span><div><strong><?= $overall==='ok'?'System bereit':($overall==='warn'?'System mit Hinweisen':'Handlungsbedarf') ?></strong><small><?= $counts['ok'] ?> erfolgreich · <?= $counts['warn'] ?> Hinweise · <?= $counts['fail'] ?> Fehler</small></div></div><time><?= $h(date('d.m.Y H:i:s')) ?></time></section>
<?php foreach ($groups as $group=>$items): ?><section class="admin-card check-card"><div class="section-title"><span><?= str_pad((string) (array_search($group, array_keys($groups), true)+1),2,'0',STR_PAD_LEFT) ?></span><div><h2><?= $h($group) ?></h2><p><?= count($items) ?> Prüfungen</p></div></div><div class="check-list">
<?php foreach ($items as $check): ?><article class="check-item <?= $h($check['status']) ?>"><span class="check-icon" aria-hidden="true"></span><div><strong><?= $h($check['label']) ?></strong><small><?= $h($check['detail']) ?></small></div><b><?= ['ok'=>'OK','warn'=>'Hinweis','fail'=>'Fehler','info'=>'Info','unknown'=>'Offen'][$check['status']] ?></b></article><?php endforeach; ?>
</div></section><?php endforeach; ?></main></body></html>
