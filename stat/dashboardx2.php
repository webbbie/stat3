<?php
declare(strict_types=1);

$target = 'dashboardx2.html';
$query = (string)($_SERVER['QUERY_STRING'] ?? '');
if ($query !== '') {
    $target .= '?' . $query;
}

header('Location: ' . $target, true, 302);
exit;
