<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
stat4_logout();
header('Location: login.php');
exit;
