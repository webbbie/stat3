<?php
declare(strict_types=1);
require_once __DIR__ . '/pixl_server.php';
require_once __DIR__ . '/stats_export.php';
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
if (trim((string)(pixl_config()['stats_password'] ?? '')) === '') {
    http_response_code(403); header('Content-Type: text/plain; charset=UTF-8');
    exit("Export gesperrt: Zuerst ein Statistik-Passwort konfigurieren.\n");
}
pixl_require_stats_auth();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { http_response_code(405); header('Allow: GET'); exit; }
$download = $_GET['download'] ?? '';
$table = $_GET['table'] ?? '';
$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
$now = time();
$at = isset($_GET['at']) ? filter_var($_GET['at'], FILTER_VALIDATE_INT) : $now;
if (!is_string($download) || !in_array($download, ['', 'csv', 'json', 'html'], true) || !is_string($table)
    || strlen($table) > 80 || $page === false || $page < 1 || $at === false || $at > $now || $at < $now - 86400) {
    http_response_code(400); header('Content-Type: text/plain; charset=UTF-8');
    exit("Ungültiger Export-Aufruf. Bitte export.php neu öffnen.\n");
}
try {
    @set_time_limit(0);
    if (in_array($download, ['csv','json'], true)) { stats_export_download(pixl_pdo(), pixl_table_name(), $download, $at); exit; }
    $stream = stats_export_create_html(pixl_pdo(), pixl_table_name(), $at, $table, $page, $download === 'html');
    try {
        while (ob_get_level() > 0) ob_end_clean();
        @ini_set('zlib.output_compression', '0');
        header('Content-Type: text/html; charset=UTF-8');
        if ($download === 'html') header('Content-Disposition: attachment; filename="stats3-backup-' . gmdate('Ymd-His', $at) . '.html"');
        header('Content-Length: ' . (string)fstat($stream)['size']);
        fpassthru($stream);
    } finally { fclose($stream); }
} catch (Throwable $error) {
    $invalid = $error instanceof InvalidArgumentException;
    http_response_code($invalid ? 400 : 503);
    header('Content-Type: text/plain; charset=UTF-8');
    if (!$invalid) error_log('stats3 static export: ' . $error->getMessage());
    echo $invalid ? "Diese Statistikquelle ist nicht verfügbar.\n" : "Die Statistikdaten konnten nicht vollständig gelesen werden. Bitte später erneut versuchen.\n";
}
