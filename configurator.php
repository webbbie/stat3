<?php
declare(strict_types=1);

require_once __DIR__ . '/pixl_server.php';

$initialConfig = pixl_config();
if (trim((string)($initialConfig['stats_password'] ?? '')) === '') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Configurator gesperrt: Zuerst ein Statistik-Passwort in pixl_config.php setzen.\n");
}

pixl_require_stats_auth();

function configurator_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function configurator_post(string $key): string
{
    return trim((string)($_POST[$key] ?? ''));
}

function configurator_secret_status(string $value): string
{
    return $value === '' ? 'Nicht gesetzt' : 'Gesetzt';
}

function configurator_parse_hosts(string $input, array &$errors): array
{
    $hosts = [];
    $entries = preg_split('/[\r\n,]+/', $input) ?: [];

    foreach ($entries as $entry) {
        $host = strtolower(rtrim(trim($entry), '.'));
        if ($host === '') {
            continue;
        }

        if (strpos($host, '://') !== false) {
            $parsed = parse_url($host, PHP_URL_HOST);
            $host = is_string($parsed) ? strtolower(rtrim($parsed, '.')) : '';
        }

        $validName = preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host);
        if (!$validName && filter_var($host, FILTER_VALIDATE_IP) === false) {
            $errors[] = 'Ungueltiger erlaubter Host: ' . $entry;
            continue;
        }

        $hosts[] = $host;
    }

    return array_values(array_unique($hosts));
}

function configurator_parse_stats_urls(string $input, array &$errors): array
{
    $urls = [];
    $entries = preg_split('/[\r\n]+/', $input) ?: [];

    foreach ($entries as $entry) {
        $entry = trim($entry);
        if ($entry === '') {
            continue;
        }

        $normalized = pixl_normalize_configured_url($entry);
        if ($normalized === '') {
            $errors[] = 'Ungueltige Statistik-URL: ' . $entry . ' (volle http(s)-URL oder Pfad mit / verwenden)';
            continue;
        }
        $urls[] = $normalized;
    }

    return array_values(array_unique($urls));
}

function configurator_write_config(string $path, array $config): void
{
    $contents = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
    $temporary = $path . '.tmp';

    if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
        throw new RuntimeException('Die temporaere Konfigurationsdatei konnte nicht geschrieben werden.');
    }

    if (!chmod($temporary, 0600)) {
        @unlink($temporary);
        throw new RuntimeException('CHMOD 600 konnte nicht gesetzt werden.');
    }

    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('pixl_config.php konnte nicht ersetzt werden.');
    }
}

function configurator_set_auth_cookie(array $oldConfig, array $newConfig): void
{
    $oldName = (string)($oldConfig['stats_cookie_name'] ?? 'pixl_stats_login');
    $newName = (string)($newConfig['stats_cookie_name'] ?? 'pixl_stats_login');
    $newPassword = (string)($newConfig['stats_password'] ?? '');
    $newSalt = (string)($newConfig['hash_salt'] ?? '');
    $days = max(1, min(365, (int)($newConfig['stats_auto_login_days'] ?? 30)));
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    if ($oldName !== $newName && preg_match('/^[A-Za-z0-9_\-]+$/', $oldName)) {
        setcookie($oldName, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    $token = hash_hmac('sha256', 'pixl-stats|' . $newPassword, $newSalt !== '' ? $newSalt : 'pixl');
    setcookie($newName, $token, [
        'expires' => time() + ($days * 86400),
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

session_name('pixl_configurator_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

if (empty($_SESSION['configurator_csrf'])) {
    $_SESSION['configurator_csrf'] = bin2hex(random_bytes(32));
}

$configPath = __DIR__ . '/pixl_config.php';
$config = pixl_config();
$db = is_array($config['db'] ?? null) ? $config['db'] : [];
$errors = [];
$saved = isset($_GET['saved']) && $_GET['saved'] === '1';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['save_config'])) {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)$_SESSION['configurator_csrf'], $token)) {
        $errors[] = 'Die Sitzung ist abgelaufen. Bitte die Seite neu laden.';
    }

    $newConfig = $config;
    $newDb = $db;

    $newDb['host'] = configurator_post('db_host');
    $newDb['database'] = configurator_post('db_database');
    $newDb['user'] = configurator_post('db_user');
    $newDb['charset'] = configurator_post('db_charset');
    $newDb['timeout'] = (int)configurator_post('db_timeout');

    $dbPassword = (string)($_POST['db_password'] ?? '');
    if ($dbPassword !== '') {
        $newDb['password'] = $dbPassword;
    }

    $dbPort = configurator_post('db_port');
    if ($dbPort === '') {
        unset($newDb['port']);
    } else {
        $newDb['port'] = (int)$dbPort;
    }

    $dbSocket = configurator_post('db_socket');
    if ($dbSocket === '') {
        unset($newDb['socket']);
    } else {
        $newDb['socket'] = $dbSocket;
    }

    if ($newDb['host'] === '' && empty($newDb['socket'])) {
        $errors[] = 'Datenbank-Host oder Socket fehlt.';
    }
    if ($newDb['database'] === '') {
        $errors[] = 'Datenbankname fehlt.';
    }
    if ($newDb['user'] === '') {
        $errors[] = 'Datenbankbenutzer fehlt.';
    }
    if ((string)($newDb['password'] ?? '') === '') {
        $errors[] = 'Datenbankpasswort fehlt.';
    }
    if (!in_array($newDb['charset'], ['utf8mb4', 'utf8'], true)) {
        $errors[] = 'Nicht unterstuetzter Datenbank-Zeichensatz.';
    }
    if ($newDb['timeout'] < 1 || $newDb['timeout'] > 60) {
        $errors[] = 'Datenbank-Timeout muss zwischen 1 und 60 Sekunden liegen.';
    }
    if (isset($newDb['port']) && ($newDb['port'] < 1 || $newDb['port'] > 65535)) {
        $errors[] = 'Ungueltiger Datenbank-Port.';
    }

    $table = configurator_post('table');
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        $errors[] = 'Der Tabellenname darf nur Buchstaben, Zahlen und Unterstriche enthalten.';
    }

    $siteId = configurator_post('site_id');
    if ($siteId === '' || strlen($siteId) > 100) {
        $errors[] = 'Site-ID fehlt oder ist laenger als 100 Zeichen.';
    }

    $allowedHosts = configurator_parse_hosts((string)($_POST['allowed_hosts'] ?? ''), $errors);
    if (!$allowedHosts) {
        $errors[] = 'Mindestens ein erlaubter Host ist erforderlich.';
    }
    $statsUrls = configurator_parse_stats_urls((string)($_POST['stats_urls'] ?? ''), $errors);

    $cookieName = configurator_post('stats_cookie_name');
    if (!preg_match('/^[A-Za-z0-9_\-]+$/', $cookieName)) {
        $errors[] = 'Der Cookie-Name darf nur Buchstaben, Zahlen, Bindestriche und Unterstriche enthalten.';
    }

    $autoLoginDays = (int)configurator_post('stats_auto_login_days');
    if ($autoLoginDays < 1 || $autoLoginDays > 365) {
        $errors[] = 'Autologin muss zwischen 1 und 365 Tagen liegen.';
    }

    $hashSalt = (string)($_POST['hash_salt'] ?? '');
    if ($hashSalt !== '') {
        if (strlen($hashSalt) < 24) {
            $errors[] = 'Ein neues Hash-Salz muss mindestens 24 Zeichen lang sein.';
        } else {
            $newConfig['hash_salt'] = $hashSalt;
        }
    }

    $statsPassword = (string)($_POST['stats_password_new'] ?? '');
    $statsPasswordConfirm = (string)($_POST['stats_password_confirm'] ?? '');
    if ($statsPassword !== '' || $statsPasswordConfirm !== '') {
        if (strlen($statsPassword) < 8) {
            $errors[] = 'Ein neues Statistik-Passwort muss mindestens 8 Zeichen lang sein.';
        } elseif (!hash_equals($statsPassword, $statsPasswordConfirm)) {
            $errors[] = 'Die neuen Statistik-Passwoerter stimmen nicht ueberein.';
        } else {
            $hash = password_hash($statsPassword, PASSWORD_DEFAULT);
            if (!is_string($hash)) {
                $errors[] = 'Das Statistik-Passwort konnte nicht gehasht werden.';
            } else {
                $newConfig['stats_password'] = $hash;
            }
        }
    }

    $newConfig['db'] = $newDb;
    $newConfig['table'] = $table;
    $newConfig['site_id'] = $siteId;
    $newConfig['allowed_hosts'] = $allowedHosts;
    $newConfig['stats_urls'] = $statsUrls;
    $newConfig['public_key'] = configurator_post('public_key');
    $newConfig['stats_cookie_name'] = $cookieName;
    $newConfig['stats_auto_login_days'] = $autoLoginDays;

    if (!$errors) {
        try {
            configurator_write_config($configPath, $newConfig);
            configurator_set_auth_cookie($config, $newConfig);
            $_SESSION['configurator_csrf'] = bin2hex(random_bytes(32));
            header('Location: configurator.php?saved=1');
            exit;
        } catch (Throwable $error) {
            $errors[] = $error->getMessage();
        }
    }

    $config = $newConfig;
    $db = $newDb;
}

$allowedHostsValue = implode("\n", array_map('strval', is_array($config['allowed_hosts'] ?? null) ? $config['allowed_hosts'] : []));
$statsUrlsValue = implode("\n", array_map('strval', is_array($config['stats_urls'] ?? null) ? $config['stats_urls'] : []));
$configWritable = is_writable($configPath) && is_writable(__DIR__);
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light dark">
  <title>Stats3 Configurator</title>
  <style>
    :root {
      color-scheme: light dark;
      --bg: #f2f5f6;
      --surface: #ffffff;
      --surface-alt: #f8fafb;
      --ink: #172126;
      --muted: #66747c;
      --line: #d8e0e4;
      --accent: #087f73;
      --accent-hover: #06665d;
      --accent-soft: #e2f4f0;
      --danger: #a43f4e;
      --danger-soft: #f9e8eb;
      --success: #18724f;
      --success-soft: #e5f5ed;
    }

    @media (prefers-color-scheme: dark) {
      :root {
        --bg: #151a1d;
        --surface: #1e2529;
        --surface-alt: #192025;
        --ink: #eef3f4;
        --muted: #a6b1b6;
        --line: #354047;
        --accent: #54c8b8;
        --accent-hover: #75d8ca;
        --accent-soft: #203b37;
        --danger: #e497a3;
        --danger-soft: #42282e;
        --success: #74d3a9;
        --success-soft: #203a30;
      }
    }

    * { box-sizing: border-box; letter-spacing: 0; }
    body { min-width: 320px; margin: 0; color: var(--ink); background: var(--bg); font: 14px/1.45 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
    a { color: var(--accent); text-underline-offset: 3px; }
    button, input, select, textarea { font: inherit; }
    button, input, select, textarea { border-radius: 6px; }
    :focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }

    .topbar { border-bottom: 1px solid var(--line); background: var(--surface); }
    .topbar-inner, main { width: min(1120px, 100%); margin-inline: auto; padding-inline: 24px; }
    .topbar-inner { min-height: 92px; display: flex; align-items: center; justify-content: space-between; gap: 20px; }
    .eyebrow { margin: 0 0 3px; color: var(--accent); font-size: 12px; font-weight: 800; text-transform: uppercase; }
    h1, h2, p { margin: 0; }
    h1 { font-size: 28px; line-height: 1.15; }
    .top-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }
    .top-actions a { min-height: 36px; display: inline-flex; align-items: center; border: 1px solid var(--line); padding: 7px 10px; color: var(--ink); background: var(--surface-alt); font-weight: 700; text-decoration: none; }
    .top-actions a:hover { border-color: var(--accent); color: var(--accent); }

    main { padding-block: 22px 40px; }
    .notice { margin-bottom: 16px; border: 1px solid var(--line); border-left-width: 4px; padding: 12px 14px; background: var(--surface); }
    .notice.success { border-left-color: var(--success); color: var(--success); background: var(--success-soft); }
    .notice.error { border-left-color: var(--danger); color: var(--danger); background: var(--danger-soft); }
    .notice ul { margin: 0; padding-left: 20px; }

    .config-form { overflow: hidden; border: 1px solid var(--line); border-radius: 8px; background: var(--surface); }
    .form-status { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 12px 16px; border-bottom: 1px solid var(--line); background: var(--surface-alt); }
    .form-status strong { font-size: 13px; }
    .status { display: inline-flex; align-items: center; min-height: 24px; padding: 3px 7px; border-radius: 4px; color: var(--success); background: var(--success-soft); font-size: 11px; font-weight: 800; }
    .status.bad { color: var(--danger); background: var(--danger-soft); }

    .settings-section { display: grid; grid-template-columns: 220px minmax(0, 1fr); gap: 28px; padding: 24px 16px; }
    .settings-section + .settings-section { border-top: 1px solid var(--line); }
    .section-title h2 { font-size: 16px; }
    .section-title p { margin-top: 5px; color: var(--muted); font-size: 12px; }
    .fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
    .field { min-width: 0; display: grid; gap: 6px; }
    .field.full { grid-column: 1 / -1; }
    label { color: var(--muted); font-size: 12px; font-weight: 750; }
    input, select, textarea { width: 100%; min-height: 40px; border: 1px solid var(--line); padding: 8px 10px; color: var(--ink); background: var(--surface); }
    textarea { min-height: 132px; resize: vertical; font-family: ui-monospace, "SFMono-Regular", Consolas, monospace; line-height: 1.5; }
    input[type="number"] { font-variant-numeric: tabular-nums; }
    .secret-state { color: var(--muted); font-size: 11px; font-weight: 700; }

    .form-actions { position: sticky; bottom: 0; display: flex; align-items: center; justify-content: flex-end; gap: 10px; padding: 12px 16px; border-top: 1px solid var(--line); background: var(--surface-alt); }
    .save-button { min-height: 40px; border: 1px solid var(--accent); padding: 8px 16px; color: #ffffff; background: var(--accent); font-weight: 800; cursor: pointer; }
    .save-button:hover { border-color: var(--accent-hover); background: var(--accent-hover); }
    .save-button:disabled { cursor: not-allowed; opacity: .55; }

    @media (max-width: 760px) {
      .topbar-inner { align-items: flex-start; flex-direction: column; padding-block: 16px; }
      .top-actions { justify-content: flex-start; }
      .settings-section { grid-template-columns: 1fr; gap: 16px; }
    }

    @media (max-width: 520px) {
      .topbar-inner, main { padding-inline: 12px; }
      h1 { font-size: 24px; }
      .top-actions { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); width: 100%; }
      .top-actions a { justify-content: center; text-align: center; }
      .fields { grid-template-columns: 1fr; }
      .field.full { grid-column: auto; }
      .settings-section { padding-inline: 12px; }
      .form-status { align-items: flex-start; flex-direction: column; }
      .form-actions { justify-content: stretch; }
      .save-button { width: 100%; }
    }
  </style>
</head>
<body>
  <header class="topbar">
    <div class="topbar-inner">
      <div>
        <p class="eyebrow">Stats3 Admin</p>
        <h1>Configurator</h1>
      </div>
      <nav class="top-actions" aria-label="Admin Navigation">
        <a href="stats.php">Gesamtstatistik</a>
        <a href="pixl_stats.php">Statistik</a>
        <a href="pixl_setup_check.php">Systemcheck</a>
        <a href="reset_stats.php">Reset</a>
        <a href="index.html">Dateien</a>
        <a href="?logout=1">Abmelden</a>
      </nav>
    </div>
  </header>

  <main>
    <?php if ($saved): ?>
      <div class="notice success" role="status">Konfiguration gespeichert.</div>
    <?php endif; ?>

    <?php if ($errors): ?>
      <div class="notice error" role="alert">
        <ul>
          <?php foreach ($errors as $error): ?>
            <li><?= configurator_h($error) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form class="config-form" method="post" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= configurator_h($_SESSION['configurator_csrf']) ?>">
      <input type="hidden" name="save_config" value="1">

      <div class="form-status">
        <strong>stats3/pixl_config.php</strong>
        <span class="status<?= $configWritable ? '' : ' bad' ?>"><?= $configWritable ? 'Schreibbar' : 'Nicht schreibbar' ?></span>
      </div>

      <section class="settings-section" aria-labelledby="database-title">
        <div class="section-title">
          <h2 id="database-title">MySQL</h2>
          <p>Datenbankverbindung und Speichertabelle</p>
        </div>
        <div class="fields">
          <div class="field">
            <label for="db_host">Host</label>
            <input id="db_host" name="db_host" value="<?= configurator_h($db['host'] ?? '') ?>" required>
          </div>
          <div class="field">
            <label for="db_port">Port</label>
            <input id="db_port" name="db_port" type="number" min="1" max="65535" value="<?= configurator_h($db['port'] ?? '') ?>" placeholder="Standard">
          </div>
          <div class="field">
            <label for="db_database">Datenbank</label>
            <input id="db_database" name="db_database" value="<?= configurator_h($db['database'] ?? '') ?>" required>
          </div>
          <div class="field">
            <label for="db_user">Benutzer</label>
            <input id="db_user" name="db_user" value="<?= configurator_h($db['user'] ?? '') ?>" autocomplete="username" required>
          </div>
          <div class="field">
            <label for="db_password">Passwort</label>
            <input id="db_password" name="db_password" type="password" autocomplete="new-password" placeholder="Unveraendert lassen">
            <span class="secret-state"><?= configurator_h(configurator_secret_status((string)($db['password'] ?? ''))) ?></span>
          </div>
          <div class="field">
            <label for="db_charset">Zeichensatz</label>
            <select id="db_charset" name="db_charset">
              <option value="utf8mb4"<?= ($db['charset'] ?? 'utf8mb4') === 'utf8mb4' ? ' selected' : '' ?>>utf8mb4</option>
              <option value="utf8"<?= ($db['charset'] ?? '') === 'utf8' ? ' selected' : '' ?>>utf8</option>
            </select>
          </div>
          <div class="field">
            <label for="db_timeout">Timeout in Sekunden</label>
            <input id="db_timeout" name="db_timeout" type="number" min="1" max="60" value="<?= configurator_h($db['timeout'] ?? 8) ?>" required>
          </div>
          <div class="field">
            <label for="db_socket">Unix-Socket</label>
            <input id="db_socket" name="db_socket" value="<?= configurator_h($db['socket'] ?? '') ?>" placeholder="Optional">
          </div>
          <div class="field full">
            <label for="table">Tabellenname</label>
            <input id="table" name="table" value="<?= configurator_h($config['table'] ?? 'pixl_events') ?>" pattern="[A-Za-z0-9_]+" required>
          </div>
        </div>
      </section>

      <section class="settings-section" aria-labelledby="tracking-title">
        <div class="section-title">
          <h2 id="tracking-title">Tracking</h2>
          <p>Website, Freigaben und Collector-Schluessel</p>
        </div>
        <div class="fields">
          <div class="field">
            <label for="site_id">Site-ID</label>
            <input id="site_id" name="site_id" maxlength="100" value="<?= configurator_h($config['site_id'] ?? '') ?>" required>
          </div>
          <div class="field">
            <label for="public_key">Public Key</label>
            <input id="public_key" name="public_key" value="<?= configurator_h($config['public_key'] ?? '') ?>">
          </div>
          <div class="field full">
            <label for="allowed_hosts">Erlaubte Hosts, einer pro Zeile</label>
            <textarea id="allowed_hosts" name="allowed_hosts" required><?= configurator_h($allowedHostsValue) ?></textarea>
          </div>
          <div class="field full">
            <label for="stats_urls">Push- und Statistik-URLs, eine pro Zeile</label>
            <textarea id="stats_urls" name="stats_urls" placeholder="https://www.example.de/seite/&#10;/weitere-seite/"><?= configurator_h($statsUrlsValue) ?></textarea>
            <span class="secret-state">Nur diese URLs senden Push-Meldungen und erscheinen in pixl_stats.php. Leer bedeutet alle URLs.</span>
          </div>
          <div class="field full">
            <label for="hash_salt">Neues Hash-Salz</label>
            <input id="hash_salt" name="hash_salt" type="password" minlength="24" autocomplete="new-password" placeholder="Unveraendert lassen">
            <span class="secret-state"><?= configurator_h(configurator_secret_status((string)($config['hash_salt'] ?? ''))) ?></span>
          </div>
        </div>
      </section>

      <section class="settings-section" aria-labelledby="access-title">
        <div class="section-title">
          <h2 id="access-title">Admin-Zugang</h2>
          <p>Statistik-Login und Browser-Sitzung</p>
        </div>
        <div class="fields">
          <div class="field">
            <label for="stats_cookie_name">Cookie-Name</label>
            <input id="stats_cookie_name" name="stats_cookie_name" value="<?= configurator_h($config['stats_cookie_name'] ?? 'pixl_stats_login') ?>" pattern="[A-Za-z0-9_-]+" required>
          </div>
          <div class="field">
            <label for="stats_auto_login_days">Autologin in Tagen</label>
            <input id="stats_auto_login_days" name="stats_auto_login_days" type="number" min="1" max="365" value="<?= configurator_h($config['stats_auto_login_days'] ?? 30) ?>" required>
          </div>
          <div class="field">
            <label for="stats_password_new">Neues Statistik-Passwort</label>
            <input id="stats_password_new" name="stats_password_new" type="password" minlength="8" autocomplete="new-password" placeholder="Unveraendert lassen">
            <span class="secret-state"><?= configurator_h(configurator_secret_status((string)($config['stats_password'] ?? ''))) ?></span>
          </div>
          <div class="field">
            <label for="stats_password_confirm">Passwort wiederholen</label>
            <input id="stats_password_confirm" name="stats_password_confirm" type="password" minlength="8" autocomplete="new-password">
          </div>
        </div>
      </section>

      <div class="form-actions">
        <button class="save-button" type="submit"<?= $configWritable ? '' : ' disabled' ?>>Konfiguration speichern</button>
      </div>
    </form>
  </main>
</body>
</html>
