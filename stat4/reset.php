<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';
stat4_require_admin();
stat4_session_start();

$tables = [
    'stat4_events' => 'Ereignisse',
    'stat4_sessions' => 'Sessions',
    'stat4_visitors' => 'Besucher',
    'stat4_notifications' => 'Benachrichtigungen',
];
$deleteOrder = ['stat4_notifications', 'stat4_events', 'stat4_sessions', 'stat4_visitors'];
$errors = [];
$notice = (string) ($_SESSION['stat4_reset_flash'] ?? '');
unset($_SESSION['stat4_reset_flash']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $confirmed = (string) ($_POST['confirmation'] ?? '');
    if (!stat4_csrf_valid((string) ($_POST['csrf'] ?? ''))) {
        $errors[] = 'Die Sitzung ist abgelaufen. Bitte lade die Seite neu.';
    }
    if ($confirmed !== 'RESET') {
        $errors[] = 'Bitte gib zur Bestätigung exakt RESET ein.';
    }
    if (!isset($_POST['understood'])) {
        $errors[] = 'Bitte bestätige, dass die Statistikdaten unwiderruflich gelöscht werden.';
    }

    if (!$errors) {
        try {
            $pdo = stat4_db();
            $pdo->beginTransaction();
            $deleted = [];
            foreach ($deleteOrder as $table) {
                $deleted[$table] = $pdo->exec("DELETE FROM {$table}");
            }
            $pdo->commit();
            $_SESSION['stat4_reset_flash'] = sprintf(
                'Statistik zurückgesetzt: %d Ereignisse, %d Sessions, %d Besucher und %d Benachrichtigungen gelöscht.',
                (int) $deleted['stat4_events'],
                (int) $deleted['stat4_sessions'],
                (int) $deleted['stat4_visitors'],
                (int) $deleted['stat4_notifications']
            );
            header('Location: reset.php?reset=1');
            exit;
        } catch (Throwable $resetError) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
            error_log('stat4 reset: ' . $resetError->getMessage());
            $errors[] = 'Die Statistik konnte nicht zurückgesetzt werden. Datenbank und Tabellen prüfen.';
        }
    }
}

$counts = [];
try {
    $pdo = stat4_db();
    foreach ($tables as $table => $label) {
        $counts[$table] = (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }
} catch (Throwable $countError) {
    foreach ($tables as $table => $label) $counts[$table] = null;
    $errors[] = 'Die aktuellen Datensatzmengen konnten nicht geladen werden: ' . $countError->getMessage();
}

$h = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="color-scheme" content="dark">
  <title>STAT4 Statistik Reset</title>
  <link rel="stylesheet" href="styles.css">
  <link rel="stylesheet" href="admin.css">
</head>
<body class="admin-body">
<header class="admin-header">
  <div><p class="eyebrow">STAT4 MAINTENANCE</p><h1>Statistik Reset</h1></div>
  <nav><a href="index.php">Statistik</a><a href="configurator.php">Konfiguration</a><a href="systemcheck.php">Systemcheck</a><a href="logout.php">Abmelden</a></nav>
</header>
<main class="config-shell">
  <?php if ($notice): ?><div class="notice success"><?= $h($notice) ?></div><?php endif; ?>
  <?php if ($errors): ?><div class="notice error"><strong>Reset nicht ausgeführt:</strong><ul><?php foreach ($errors as $error): ?><li><?= $h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

  <section class="admin-card reset-card">
    <div class="section-title"><span>01</span><div><h2>Aktueller Datenbestand</h2><p>Diese Datensätze werden beim Reset vollständig gelöscht.</p></div></div>
    <div class="metric-grid reset-metrics">
      <?php foreach ($tables as $table => $label): ?>
        <article class="metric"><span><?= $h($label) ?></span><strong><?= $counts[$table] === null ? '–' : number_format($counts[$table], 0, ',', '.') ?></strong></article>
      <?php endforeach; ?>
    </div>
  </section>

  <form method="post" class="config-form reset-form" onsubmit="return confirm('Wirklich alle Statistikdaten unwiderruflich löschen?');">
    <input type="hidden" name="csrf" value="<?= $h(stat4_csrf_token()) ?>">
    <section class="admin-card danger-card">
      <div class="section-title"><span>02</span><div><h2>Alle Statistikdaten löschen</h2><p>Konfiguration, Datenbankzugang und Admin-Passwort bleiben erhalten.</p></div></div>
      <div class="notice error"><strong>Achtung:</strong> Besucher, Sessions, Ereignisse und Pushover-Meilensteine können nach dem Reset nicht wiederhergestellt werden.</div>
      <div class="form-grid">
        <label class="field wide"><span>Zur Bestätigung RESET eingeben</span><input name="confirmation" required autocomplete="off" spellcheck="false" placeholder="RESET"></label>
        <label class="check-field wide"><input type="checkbox" name="understood" value="1" required><span><b>Ich verstehe die Folgen</b><small>Alle Statistikdaten werden unwiderruflich gelöscht.</small></span></label>
      </div>
      <div class="form-actions reset-actions"><a class="secondary-button cancel-link" href="index.php">Abbrechen</a><button class="danger-button" type="submit">Statistik jetzt zurücksetzen</button></div>
    </section>
  </form>
</main>
</body>
</html>
