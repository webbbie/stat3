<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';

if (!stat4_admin_configured()) { header('Location: configurator.php'); exit; }
if (stat4_is_admin()) { header('Location: index.php'); exit; }
$error = '';
$next = (string) ($_GET['next'] ?? $_POST['next'] ?? 'index.php');
if (!in_array($next, ['index.php', 'configurator.php', 'systemcheck.php'], true)) $next = 'index.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!stat4_csrf_valid((string) ($_POST['csrf'] ?? ''))) $error = 'Die Sitzung ist abgelaufen. Bitte erneut versuchen.';
    elseif (stat4_login((string) ($_POST['password'] ?? ''))) { header('Location: ' . $next); exit; }
    else $error = 'Das Passwort ist nicht korrekt.';
}
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="dark"><title>STAT4 Anmeldung</title><link rel="stylesheet" href="styles.css"><link rel="stylesheet" href="admin.css"></head>
<body class="admin-body"><main class="auth-shell"><section class="admin-card login-card"><p class="eyebrow">MYSQL ANALYTICS</p><h1>Admin-Anmeldung</h1><p class="admin-intro">Melde dich an, um die Statistik und ihre Konfiguration zu öffnen.</p>
<?php if ($error): ?><div class="notice error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?= htmlspecialchars(stat4_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="next" value="<?= htmlspecialchars($next, ENT_QUOTES, 'UTF-8') ?>"><label class="field"><span>Passwort</span><input type="password" name="password" required autofocus autocomplete="current-password"></label><button class="primary-button" type="submit">Anmelden</button></form>
</section></main></body></html>
