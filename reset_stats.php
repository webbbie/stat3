<?php
declare(strict_types=1);

require_once __DIR__ . '/pixl_server.php';

function pixl_reset_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function pixl_reset_table_exists(PDO $pdo, string $table): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables '
        . 'WHERE table_schema = DATABASE() AND table_name = :table_name'
    );
    $statement->execute(['table_name' => $table]);
    return (int)$statement->fetchColumn() > 0;
}

function pixl_reset_table_count(PDO $pdo, string $table): int
{
    return (int)$pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
}

function pixl_reset_tables(PDO $pdo, array $tables): array
{
    $results = [];

    foreach ($tables as $label => $table) {
        if (!is_string($table) || !preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            throw new RuntimeException('Ungueltiger Tabellenname.');
        }

        if (!pixl_reset_table_exists($pdo, $table)) {
            $results[] = [
                'label' => (string)$label,
                'table' => $table,
                'deleted' => 0,
                'missing' => true,
            ];
            continue;
        }

        $count = pixl_reset_table_count($pdo, $table);
        $pdo->exec("DELETE FROM `$table`");
        try {
            $pdo->exec("ALTER TABLE `$table` AUTO_INCREMENT = 1");
        } catch (Throwable $error) {
            // Row counts are reset even when the hosting account cannot alter tables.
        }
        $results[] = [
            'label' => (string)$label,
            'table' => $table,
            'deleted' => $count,
            'missing' => false,
        ];
    }

    return $results;
}

function pixl_reset_status(PDO $pdo, string $label, string $table): array
{
    $exists = pixl_reset_table_exists($pdo, $table);
    return [
        'label' => $label,
        'table' => $table,
        'exists' => $exists,
        'count' => $exists ? pixl_reset_table_count($pdo, $table) : 0,
    ];
}

$baseTable = pixl_table_name();
$subscriptionTable = $baseTable . '_push_subscriptions';

if (PHP_SAPI === 'cli') {
    if (($argv[1] ?? '') !== '--confirm') {
        fwrite(STDERR, "Usage: php reset_stats.php --confirm\n");
        exit(1);
    }

    try {
        $results = pixl_reset_tables(pixl_pdo(), [
            'Statistics' => $baseTable,
            'Push subscriptions' => $subscriptionTable,
        ]);

        foreach ($results as $result) {
            if ($result['missing']) {
                echo $result['table'] . ": not present\n";
            } else {
                echo $result['table'] . ': deleted ' . $result['deleted'] . " rows\n";
            }
        }

        echo "Statistics and push subscriptions reset. MySQL schemas and push configuration preserved.\n";
        exit;
    } catch (Throwable $error) {
        fwrite(STDERR, "Reset failed: " . $error->getMessage() . "\n");
        exit(2);
    }
}

$initialConfig = pixl_config();
if (trim((string)($initialConfig['stats_password'] ?? '')) === '') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Reset gesperrt: Zuerst ein Statistik-Passwort in pixl_config.php setzen.\n");
}

pixl_require_stats_auth();

session_name('pixl_reset_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

if (empty($_SESSION['pixl_reset_csrf'])) {
    $_SESSION['pixl_reset_csrf'] = bin2hex(random_bytes(32));
}

$errors = [];
$flash = is_array($_SESSION['pixl_reset_flash'] ?? null) ? $_SESSION['pixl_reset_flash'] : [];
unset($_SESSION['pixl_reset_flash']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['reset_statistics'])) {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)$_SESSION['pixl_reset_csrf'], $token)) {
        $errors[] = 'Die Sitzung ist abgelaufen. Bitte die Seite neu laden.';
    }

    if ((string)($_POST['confirm_reset'] ?? '') !== 'yes') {
        $errors[] = 'Die Bestaetigung zum Zuruecksetzen fehlt.';
    }

    if (!$errors) {
        try {
            $tables = ['Statistik-Ereignisse' => $baseTable];
            if (isset($_POST['reset_push_subscriptions'])) {
                $tables['Push-Abonnements'] = $subscriptionTable;
            }

            $_SESSION['pixl_reset_flash'] = pixl_reset_tables(pixl_pdo(), $tables);
            $_SESSION['pixl_reset_csrf'] = bin2hex(random_bytes(32));
            header('Location: reset_stats.php?reset=done');
            exit;
        } catch (Throwable $error) {
            $errors[] = $error->getMessage();
        }
    }
}

$connectionError = '';
$statuses = [];
try {
    $pdo = pixl_pdo();
    $statuses[] = pixl_reset_status($pdo, 'Statistik-Ereignisse', $baseTable);
    $statuses[] = pixl_reset_status($pdo, 'Push-Abonnements', $subscriptionTable);
} catch (Throwable $error) {
    $connectionError = $error->getMessage();
}

$canReset = $connectionError === '' && !empty($statuses[0]['exists']);
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light dark">
  <title>Stats3 Statistik Reset</title>
  <style>
    :root {
      color-scheme: light dark;
      --bg: #f3f5f7;
      --surface: #ffffff;
      --surface-alt: #f8fafb;
      --ink: #172126;
      --muted: #66747c;
      --line: #d9e0e4;
      --accent: #087f73;
      --accent-soft: #e2f4f0;
      --danger: #a43f4e;
      --danger-hover: #883340;
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
        --accent-soft: #203b37;
        --danger: #e497a3;
        --danger-hover: #efb0ba;
        --danger-soft: #42282e;
        --success: #74d3a9;
        --success-soft: #203a30;
      }
    }

    * { box-sizing: border-box; letter-spacing: 0; }
    body { min-width: 320px; margin: 0; color: var(--ink); background: var(--bg); font: 14px/1.45 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
    a { color: var(--accent); text-underline-offset: 3px; }
    button, input { font: inherit; }
    :focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }

    .topbar { border-bottom: 1px solid var(--line); background: var(--surface); }
    .topbar-inner, main { width: min(920px, 100%); margin-inline: auto; padding-inline: 24px; }
    .topbar-inner { min-height: 92px; display: flex; align-items: center; justify-content: space-between; gap: 20px; }
    .eyebrow { margin: 0 0 3px; color: var(--danger); font-size: 12px; font-weight: 800; text-transform: uppercase; }
    h1, h2, p { margin: 0; }
    h1 { font-size: 28px; line-height: 1.15; }
    .top-actions { display: flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }
    .top-actions a { min-height: 36px; display: inline-flex; align-items: center; border: 1px solid var(--line); border-radius: 6px; padding: 7px 10px; color: var(--ink); background: var(--surface-alt); font-weight: 700; text-decoration: none; }
    .top-actions a:hover { border-color: var(--accent); color: var(--accent); }

    main { padding-block: 24px 40px; }
    .notice { margin-bottom: 16px; border: 1px solid var(--line); border-left-width: 4px; border-radius: 6px; padding: 12px 14px; background: var(--surface); }
    .notice.success { border-left-color: var(--success); color: var(--success); background: var(--success-soft); }
    .notice.error { border-left-color: var(--danger); color: var(--danger); background: var(--danger-soft); }
    .notice ul { margin: 0; padding-left: 20px; }

    .reset-panel { overflow: hidden; border: 1px solid var(--line); border-radius: 8px; background: var(--surface); }
    .panel-header { padding: 18px; border-bottom: 1px solid var(--line); background: var(--surface-alt); }
    .panel-header h2 { font-size: 17px; }
    .panel-header p { margin-top: 5px; color: var(--muted); }
    .status-list { margin: 0; }
    .status-row { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 16px; align-items: center; padding: 14px 18px; border-bottom: 1px solid var(--line); }
    .status-row dt { font-weight: 750; }
    .status-row dt code { display: block; margin-top: 3px; color: var(--muted); font-size: 11px; font-weight: 500; overflow-wrap: anywhere; }
    .status-row dd { margin: 0; font-size: 20px; font-weight: 800; font-variant-numeric: tabular-nums; }
    .status-row dd.missing { color: var(--muted); font-size: 12px; }

    .reset-form { padding: 18px; }
    .option { display: flex; gap: 10px; align-items: flex-start; padding-block: 9px; color: var(--ink); font-weight: 700; cursor: pointer; }
    .option input { width: 18px; height: 18px; flex: 0 0 auto; margin: 1px 0 0; accent-color: var(--danger); }
    .option span { min-width: 0; }
    .option small { display: block; margin-top: 2px; color: var(--muted); font-weight: 500; }
    .confirm { margin-top: 10px; padding-top: 14px; border-top: 1px solid var(--line); }
    .reset-button { width: 100%; min-height: 44px; margin-top: 16px; border: 1px solid var(--danger); border-radius: 6px; padding: 9px 14px; color: #ffffff; background: var(--danger); font-weight: 800; cursor: pointer; }
    .reset-button:hover { border-color: var(--danger-hover); background: var(--danger-hover); }
    .reset-button:disabled { cursor: not-allowed; opacity: .55; }

    @media (max-width: 680px) {
      .topbar-inner { align-items: flex-start; flex-direction: column; padding-block: 16px; }
      .top-actions { justify-content: flex-start; }
    }

    @media (max-width: 520px) {
      .topbar-inner, main { padding-inline: 12px; }
      h1 { font-size: 24px; }
      .top-actions { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); width: 100%; }
      .top-actions a { justify-content: center; text-align: center; }
      .status-row { padding-inline: 12px; }
      .reset-form { padding: 14px 12px; }
    }
  </style>
</head>
<body>
  <header class="topbar">
    <div class="topbar-inner">
      <div>
        <p class="eyebrow">Stats3 Admin</p>
        <h1>Statistik Reset</h1>
      </div>
      <nav class="top-actions" aria-label="Admin Navigation">
        <a href="pixl_stats.php">Statistik</a>
        <a href="configurator.php">Configurator</a>
        <a href="pixl_setup_check.php">Systemcheck</a>
        <a href="?logout=1">Abmelden</a>
      </nav>
    </div>
  </header>

  <main>
    <?php if ($flash): ?>
      <div class="notice success" role="status">
        <ul>
          <?php foreach ($flash as $result): ?>
            <li>
              <?= pixl_reset_h($result['label']) ?>:
              <?= !empty($result['missing']) ? 'Tabelle nicht vorhanden' : pixl_reset_h($result['deleted']) . ' Datensaetze geloescht' ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <?php if ($errors): ?>
      <div class="notice error" role="alert">
        <ul>
          <?php foreach ($errors as $error): ?>
            <li><?= pixl_reset_h($error) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <?php if ($connectionError !== ''): ?>
      <div class="notice error" role="alert">
        <strong>MySQL nicht verfuegbar</strong><br>
        <?= pixl_reset_h($connectionError) ?>
      </div>
    <?php endif; ?>

    <section class="reset-panel" aria-labelledby="reset-title">
      <div class="panel-header">
        <h2 id="reset-title">Gespeicherte Daten</h2>
        <p>Tabellen bleiben bestehen. Die Aktion kann nicht rueckgaengig gemacht werden.</p>
      </div>

      <dl class="status-list">
        <?php foreach ($statuses as $status): ?>
          <div class="status-row">
            <dt>
              <?= pixl_reset_h($status['label']) ?>
              <code><?= pixl_reset_h($status['table']) ?></code>
            </dt>
            <dd<?= $status['exists'] ? '' : ' class="missing"' ?>>
              <?= $status['exists'] ? pixl_reset_h($status['count']) : 'Nicht vorhanden' ?>
            </dd>
          </div>
        <?php endforeach; ?>
      </dl>

      <form class="reset-form" method="post">
        <input type="hidden" name="csrf_token" value="<?= pixl_reset_h($_SESSION['pixl_reset_csrf']) ?>">
        <input type="hidden" name="reset_statistics" value="1">

        <label class="option">
          <input type="checkbox" checked disabled>
          <span>Statistik-Ereignisse<small>Besuche, Klickpfade, Kampagnen und Auswertungen</small></span>
        </label>

        <label class="option">
          <input type="checkbox" name="reset_push_subscriptions" value="1">
          <span>Push-Abonnements ebenfalls loeschen<small>Web-Push-Konfiguration und Schluessel bleiben erhalten</small></span>
        </label>

        <label class="option confirm">
          <input type="checkbox" name="confirm_reset" value="yes" required>
          <span>Ich bestaetige das unwiderrufliche Zuruecksetzen.</span>
        </label>

        <button class="reset-button" type="submit"<?= $canReset ? '' : ' disabled' ?>>Statistiken jetzt zuruecksetzen</button>
      </form>
    </section>
  </main>
</body>
</html>
