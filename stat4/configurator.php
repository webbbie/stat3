<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
require __DIR__ . '/pushover.php';
stat4_session_start();

$configured = stat4_admin_configured();
if ($configured) stat4_require_admin();
$config = stat4_config();
$errors = [];
$notice = (string) ($_SESSION['stat4_flash'] ?? (isset($_GET['saved']) ? 'Konfiguration wurde gespeichert.' : ''));
unset($_SESSION['stat4_flash']);

$values = [
    'cookie_name' => (string) $config['admin']['cookie_name'],
    'autologin' => !empty($config['admin']['autologin']),
    'pushover_sound' => (string) ($config['pushover']['sound'] ?? ''),
    'pushover_priority' => (int) ($config['pushover']['priority'] ?? 0),
    'pushover_timeout' => (int) ($config['pushover']['timeout'] ?? 8),
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $values = [
        'cookie_name' => trim((string) ($_POST['cookie_name'] ?? '')),
        'autologin' => isset($_POST['autologin']),
        'pushover_sound' => (string) ($_POST['pushover_sound'] ?? ''),
        'pushover_priority' => (int) ($_POST['pushover_priority'] ?? 0),
        'pushover_timeout' => (int) ($_POST['pushover_timeout'] ?? 8),
    ];
    $adminPassword = (string) ($_POST['admin_password'] ?? '');
    $adminRepeat = (string) ($_POST['admin_password_repeat'] ?? '');
    $pushTokenInput = trim((string) ($_POST['pushover_application_token'] ?? ''));
    $pushUserInput = trim((string) ($_POST['pushover_user_key'] ?? ''));
    $pushToken = $pushTokenInput !== '' ? $pushTokenInput : (string) ($config['pushover']['application_token'] ?? '');
    $pushUser = $pushUserInput !== '' ? $pushUserInput : (string) ($config['pushover']['user_key'] ?? '');
    if (!stat4_csrf_valid((string) ($_POST['csrf'] ?? ''))) $errors[] = 'Die Sitzung ist abgelaufen. Bitte erneut versuchen.';
    if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]{2,50}$/', $values['cookie_name'])) $errors[] = 'Cookie Name: 3–51 Zeichen, beginnend mit einem Buchstaben.';
    if (!$configured && strlen($adminPassword) < 8) $errors[] = 'Das Admin-Passwort muss mindestens 8 Zeichen lang sein.';
    if ($adminPassword !== '' && strlen($adminPassword) < 8) $errors[] = 'Das neue Admin-Passwort muss mindestens 8 Zeichen lang sein.';
    if ($adminPassword !== $adminRepeat) $errors[] = 'Passwort und Wiederholung stimmen nicht überein.';
    if (!in_array($values['pushover_priority'], [-2,-1,0,1,2], true)) $errors[] = 'Ungültige Pushover-Priorität.';
    if ($values['pushover_timeout'] < 1 || $values['pushover_timeout'] > 60) $errors[] = 'Pushover Timeout muss zwischen 1 und 60 Sekunden liegen.';

    $action = (string) ($_POST['action'] ?? 'save');
    if (!$errors && $action === 'test_pushover') {
        $result = stat4_pushover_post([
            'title'=>'STAT4 Testnachricht',
            'message'=>'Die Pushover-Konfiguration funktioniert. Künftige Nachrichten werden nach jeweils 5 gelesenen Seiten ausgelöst.',
        ], ['application_token'=>$pushToken,'user_key'=>$pushUser,'sound'=>$values['pushover_sound'],'priority'=>$values['pushover_priority'],'timeout'=>$values['pushover_timeout']]);
        if ($result['ok']) {
            $notice = 'Pushover-Testnachricht wurde angenommen.';
            try {
                require_once dirname(__DIR__) . '/mind/bootstrap.php';
                mind_record_success([
                    'source'=>'stat4-test', 'source_event_id'=>bin2hex(random_bytes(16)),
                    'pushover_request'=>$result['request'] ?? '', 'title'=>'STAT4 Testnachricht',
                    'message'=>'Die Pushover-Konfiguration funktioniert. Künftige Nachrichten werden nach jeweils 5 gelesenen Seiten ausgelöst.',
                    'ip'=>stat4_client_ip(),
                ]);
            } catch (Throwable $mindError) {
                error_log('mind stat4 test logging failed: ' . $mindError->getMessage());
            }
        } else $errors[] = 'Pushover-Test fehlgeschlagen: ' . ($result['error'] ?? 'Unbekannter Fehler');
    } elseif (!$errors) {
        $passwordHash = $adminPassword !== '' ? password_hash($adminPassword, PASSWORD_DEFAULT) : (string) $config['admin']['password_hash'];
        $stored = $config;
        unset($stored['db']);
        $target = __DIR__ . '/config.local.php';
        try {
            // Laufzeitwerte enthalten keine alte DB-Verbindung. Bis zur Migration
            // muessen deren Zugangsdaten in der lokalen Datei erhalten bleiben.
            if (file_exists($target) || is_link($target)) {
                if (!is_file($target) || !is_readable($target)) {
                    throw new RuntimeException('Lokale Konfiguration ist nicht lesbar.');
                }
                $localStored = (static function (string $file): mixed {
                    return @require $file;
                })($target);
                if (!is_array($localStored)
                    || (array_key_exists('db', $localStored) && !is_array($localStored['db']))) {
                    throw new RuntimeException('Lokale Konfiguration ist ungueltig.');
                }
                if (array_key_exists('db', $localStored)) {
                    $stored['db'] = $localStored['db'];
                }
            }
        } catch (Throwable $storageError) {
            $errors[] = 'Die vorhandene Konfigurationsdatei konnte nicht sicher gelesen werden. Es wurde nichts gespeichert.';
        }
        if (!$errors) {
            $stored['admin'] = ['cookie_name'=>$values['cookie_name'],'autologin'=>$values['autologin'],'password_hash'=>$passwordHash];
            $stored['pushover'] = ['application_token'=>$pushToken,'user_key'=>$pushUser,'sound'=>$values['pushover_sound'],'priority'=>$values['pushover_priority'],'timeout'=>$values['pushover_timeout']];
            $content = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($stored, true) . ";\n";
            $temporary = $target . '.tmp';
            if (file_put_contents($temporary, $content, LOCK_EX) === false || !rename($temporary, $target)) {
                $errors[] = 'Die Konfigurationsdatei konnte nicht geschrieben werden.';
            } else {
                @chmod($target, 0600);
                stat4_session_start(); $_SESSION['stat4_admin'] = true;
                $_SESSION['stat4_flash'] = 'Konfiguration wurde gespeichert.';
                if ($pushToken !== '' && $pushUser !== '') {
                    try {
                        stat4_pushover_ensure_table(stat4_db());
                        $_SESSION['stat4_flash'] .= ' Pushover-Meilensteintabelle ist bereit.';
                    } catch (Throwable $migrationError) {
                        $_SESSION['stat4_flash'] .= ' Hinweis: Pushover-Tabelle konnte noch nicht angelegt werden; schema.sql importieren.';
                    }
                }
                header('Location: configurator.php?saved=1'); exit;
            }
        }
    }
}
$h = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="dark"><title>STAT4 Konfiguration</title><link rel="stylesheet" href="styles.css"><link rel="stylesheet" href="admin.css"></head>
<body class="admin-body"><header class="admin-header"><div><p class="eyebrow">STAT4 SETUP</p><h1>Konfigurations-Dashboard</h1></div><?php if ($configured): ?><nav><a href="index.php">Statistik</a><a href="systemcheck.php">Systemcheck</a><a href="reset.php">Reset</a><a href="logout.php">Abmelden</a></nav><?php endif; ?></header><main class="config-shell">
<?php if (!$configured): ?><div class="notice setup">Erstsetup: Lege jetzt den STAT4-Admin-Zugang fest.</div><?php endif; ?>
<div class="notice setup">Datenbank: STAT4 verwendet automatisch die zentrale MySQL-Verbindung aus der Stats3-Konfiguration.</div>
<?php if ($notice): ?><div class="notice success"><?= $h($notice) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="notice error"><strong>Bitte prüfen:</strong><ul><?php foreach($errors as $error): ?><li><?= $h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form method="post" class="config-form"><input type="hidden" name="csrf" value="<?= $h(stat4_csrf_token()) ?>">
<section class="admin-card"><div class="section-title"><span>01</span><div><h2>Admin-Zugang</h2><p>Schutz für Statistik und Konfiguration</p></div></div><div class="form-grid">
<label class="field"><span>Cookie Name</span><input name="cookie_name" value="<?= $h($values['cookie_name']) ?>" required autocomplete="off"></label>
<label class="check-field"><input type="checkbox" name="autologin" value="1" <?= $values['autologin']?'checked':'' ?>><span><b>Autologin</b><small>Signiertes Login-Cookie für 30 Tage</small></span></label>
<label class="field"><span><?= $configured ? 'Neues Passwort' : 'Passwort' ?></span><input type="password" name="admin_password" <?= $configured?'':'required' ?> minlength="8" autocomplete="new-password" placeholder="<?= $configured?'Leer lassen zum Beibehalten':'' ?>"></label>
<label class="field"><span>Passwort wiederholen</span><input type="password" name="admin_password_repeat" <?= $configured?'':'required' ?> minlength="8" autocomplete="new-password"></label>
</div></section>
<section class="admin-card"><div class="section-title"><span>02</span><div><h2>Pushover Messages</h2><p>Nachricht nach jeweils fünf gelesenen Seiten eines Besuchers</p></div></div><div class="form-grid">
<label class="field"><span>Application Token</span><input type="password" name="pushover_application_token" autocomplete="new-password" placeholder="<?= !empty($config['pushover']['application_token'])?'Gespeichert – leer lassen zum Beibehalten':'Pushover Application Token' ?>"></label>
<label class="field"><span>User Key</span><input type="password" name="pushover_user_key" autocomplete="new-password" placeholder="<?= !empty($config['pushover']['user_key'])?'Gespeichert – leer lassen zum Beibehalten':'Pushover User oder Group Key' ?>"></label>
<label class="field"><span>Sound</span><select name="pushover_sound"><option value="">Gerätestandard</option><?php foreach(['pushover'=>'Pushover','bike'=>'Bike','bugle'=>'Bugle','cashregister'=>'Cash Register','classical'=>'Classical','cosmic'=>'Cosmic','falling'=>'Falling','gamelan'=>'Gamelan','incoming'=>'Incoming','intermission'=>'Intermission','magic'=>'Magic','mechanical'=>'Mechanical','pianobar'=>'Piano Bar','siren'=>'Siren','spacealarm'=>'Space Alarm','tugboat'=>'Tug Boat','alien'=>'Alien','climb'=>'Climb','persistent'=>'Persistent','echo'=>'Echo','updown'=>'Up Down','vibrate'=>'Nur Vibration','none'=>'Lautlos'] as $soundKey=>$soundLabel): ?><option value="<?= $h($soundKey) ?>" <?= $values['pushover_sound']===$soundKey?'selected':'' ?>><?= $h($soundLabel) ?></option><?php endforeach; ?></select></label>
<label class="field"><span>Priorität</span><select name="pushover_priority"><option value="-2" <?= $values['pushover_priority']===-2?'selected':'' ?>>-2 · Niedrigste</option><option value="-1" <?= $values['pushover_priority']===-1?'selected':'' ?>>-1 · Niedrig</option><option value="0" <?= $values['pushover_priority']===0?'selected':'' ?>>0 · Normal</option><option value="1" <?= $values['pushover_priority']===1?'selected':'' ?>>1 · Hoch</option><option value="2" <?= $values['pushover_priority']===2?'selected':'' ?>>2 · Notfall</option></select></label>
<label class="field"><span>Timeout (Sekunden)</span><input type="number" name="pushover_timeout" min="1" max="60" value="<?= $h($values['pushover_timeout']) ?>" required></label>
<div class="field-help"><b>Auslösung</b><span>Eine Nachricht bei Seite 5, 10, 15 usw. pro Besucher. Priorität 2 wiederholt 60 Minuten lang alle 60 Sekunden bis zur Bestätigung.</span></div>
</div></section>
<div class="form-actions"><button class="secondary-button" type="submit" name="action" value="test_pushover">Pushover testen</button><button class="primary-button" type="submit" name="action" value="save">Konfiguration speichern</button></div>
</form></main></body></html>
