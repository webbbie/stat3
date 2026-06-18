<?php
declare(strict_types=1);

require __DIR__ . '/pixl_server.php';

pixl_require_stats_auth();

function pixl_check_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function pixl_check_row(string $label, bool $ok, string $detail): array
{
    return [
        'label' => $label,
        'ok' => $ok,
        'detail' => $detail,
    ];
}

$rows = [];
$config = pixl_config();
$db = $config['db'] ?? [];
$table = pixl_table_name();
$pdoDrivers = extension_loaded('pdo') ? PDO::getAvailableDrivers() : [];

$rows[] = pixl_check_row('PHP Version', version_compare(PHP_VERSION, '7.4.0', '>='), PHP_VERSION);
$rows[] = pixl_check_row('PDO Extension', extension_loaded('pdo'), extension_loaded('pdo') ? 'loaded' : 'missing');
$rows[] = pixl_check_row('PDO MySQL Driver', in_array('mysql', $pdoDrivers, true), $pdoDrivers ? implode(', ', $pdoDrivers) : 'no PDO drivers available');
$rows[] = pixl_check_row('Config File', is_file(__DIR__ . '/pixl_config.php'), 'pixl_config.php');
$rows[] = pixl_check_row('Database Host', trim((string)($db['host'] ?? '')) !== '', (string)($db['host'] ?? 'missing'));
$rows[] = pixl_check_row('Database Name', trim((string)($db['database'] ?? '')) !== '', (string)($db['database'] ?? 'missing'));
$rows[] = pixl_check_row('Database User', trim((string)($db['user'] ?? '')) !== '', (string)($db['user'] ?? 'missing'));
$rows[] = pixl_check_row('Stats Password', trim((string)($config['stats_password'] ?? '')) !== '', trim((string)($config['stats_password'] ?? '')) !== '' ? 'set' : 'missing');
$rows[] = pixl_check_row('Hash Salt', strlen((string)($config['hash_salt'] ?? '')) >= 24, 'length ' . strlen((string)($config['hash_salt'] ?? '')));

$dbOk = false;
$dbDetail = '';
$schemaOk = false;
$schemaDetail = '';

try {
    $pdo = pixl_pdo();
    $dbOk = true;
    $version = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
    $dbDetail = 'connected, MySQL/MariaDB ' . $version;

    try {
        pixl_ensure_schema($pdo);
        $count = (int)$pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
        $schemaOk = true;
        $schemaDetail = 'table `' . $table . '` ok, rows ' . $count;
    } catch (Throwable $e) {
        $schemaDetail = $e->getMessage();
    }
} catch (Throwable $e) {
    $dbDetail = $e->getMessage();
}

$rows[] = pixl_check_row('Database Connection', $dbOk, $dbDetail);
$rows[] = pixl_check_row('Schema/Table', $schemaOk, $schemaDetail);

$allOk = true;
foreach ($rows as $row) {
    if (!$row['ok']) {
        $allOk = false;
        break;
    }
}
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Pixl IONOS Setup Check</title>
  <style>
    body { margin: 0; font: 14px/1.45 system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color: #17202a; background: #f4f6f8; }
    main { width: min(960px, calc(100vw - 32px)); margin: 32px auto; }
    h1 { margin: 0 0 16px; font-size: 24px; letter-spacing: 0; }
    .summary { margin-bottom: 16px; padding: 14px 16px; border: 1px solid <?= $allOk ? '#b7dfca' : '#efc5b5' ?>; border-radius: 8px; background: <?= $allOk ? '#edf8f2' : '#fff3ed' ?>; }
    table { width: 100%; border-collapse: collapse; overflow: hidden; border: 1px solid #d8dee8; border-radius: 8px; background: #fff; }
    th, td { padding: 10px 12px; border-bottom: 1px solid #d8dee8; text-align: left; vertical-align: top; }
    th { color: #667085; background: #fbfcfe; font-size: 12px; text-transform: uppercase; }
    tr:last-child td { border-bottom: 0; }
    .ok { color: #1f7a4d; font-weight: 700; }
    .fail { color: #a84818; font-weight: 700; }
    code { white-space: pre-wrap; overflow-wrap: anywhere; }
  </style>
</head>
<body>
  <main>
    <h1>Pixl IONOS Setup Check</h1>
    <div class="summary">
      <?= $allOk ? 'Alles sieht gut aus.' : 'Ein oder mehrere Punkte brauchen noch Aufmerksamkeit.' ?>
    </div>
    <table>
      <thead>
        <tr><th>Check</th><th>Status</th><th>Details</th></tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row): ?>
          <tr>
            <td><?= pixl_check_h($row['label']) ?></td>
            <td class="<?= $row['ok'] ? 'ok' : 'fail' ?>"><?= $row['ok'] ? 'OK' : 'Fehler' ?></td>
            <td><code><?= pixl_check_h($row['detail']) ?></code></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </main>
</body>
</html>
