<?php
declare(strict_types=1);

require __DIR__ . '/pixl_server.php';

pixl_apply_cors();

function pixl_maybe_send_webpush(PDO $pdo, int $eventId): void
{
    if ($eventId <= 0) {
        return;
    }

    $pushPath = __DIR__ . '/pixel_stats2.php';
    if (!is_file($pushPath)) {
        return;
    }

    try {
        require_once $pushPath;
        if (function_exists('pixel_push_notify_event')) {
            pixel_push_notify_event($pdo, $eventId);
        }
    } catch (Throwable $e) {
        error_log('pixl web push failed: ' . $e->getMessage());
    }
}

function pixl_pixel_payload(string $source): array
{
    $url = (string)($_GET['url'] ?? '');
    $urlHost = $url !== '' ? parse_url($url, PHP_URL_HOST) : null;
    $urlPath = $url !== '' ? parse_url($url, PHP_URL_PATH) : null;
    $referrer = (string)($_GET['ref'] ?? ($_SERVER['HTTP_REFERER'] ?? ''));
    $refHost = $referrer !== '' ? parse_url($referrer, PHP_URL_HOST) : null;
    $refPath = $referrer !== '' ? parse_url($referrer, PHP_URL_PATH) : null;
    $host = is_string($urlHost) && $urlHost !== ''
        ? $urlHost
        : (is_string($refHost) && $refHost !== '' ? $refHost : (string)($_SERVER['HTTP_HOST'] ?? ''));
    $path = (string)($_GET['path'] ?? ($urlPath ?: ($refPath ?: '/')));
    $pageUrl = $url !== '' ? $url : $referrer;

    $config = pixl_config();

    return [
        'schema' => 'pixl-server-v1',
        'source' => $source,
        'eventId' => bin2hex(random_bytes(16)),
        'sentAt' => gmdate('c'),
        'siteId' => (string)($config['site_id'] ?? ($host !== '' ? $host : 'pixl')),
        'reason' => $source === 'pixel' ? 'PIXEL' : 'DIRECT',
        'title' => $source === 'pixel' ? 'Server Pixel' : 'Direct Collector Request',
        'page' => [
            'hostname' => $host,
            'origin' => $host !== '' ? 'https://' . $host : '',
            'url' => $pageUrl,
            'path' => $path !== '' ? $path : '/',
            'referrer' => $referrer,
        ],
        'context' => [
            'userAgent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'browser' => 'Unknown',
            'os' => 'Unknown',
            'device' => 'Unknown',
            'screen' => '',
            'knownResolution' => false,
            'viewport' => '',
            'screenCategory' => '',
            'language' => (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''),
            'country' => (string)($_SERVER['HTTP_CF_IPCOUNTRY'] ?? ''),
            'timezone' => '',
        ],
        'engagement' => [
            'sessionDuration' => null,
            'readingLabel' => $source === 'pixel' ? 'PIXEL' : 'DIRECT',
            'readingSeconds' => null,
            'readingScore' => null,
            'scoreTrail' => '',
            'bestReadScore' => 0,
            'bestReadDuration' => 0,
            'v3UserScore' => null,
        ],
        'health' => [
            'renderStatus' => 'SERVER',
            'renderIssues' => [],
            'consoleErrorCount' => 0,
            'consoleErrors' => [],
            'dialogErrorCount' => 0,
            'dialogEvents' => [],
        ],
        'events' => [
            'reached' => [],
            'seconds' => [],
            'trail' => [$source],
        ],
        'flags' => [
            'inFrame' => false,
            'webdriver' => false,
        ],
    ];
}

function pixl_transparent_pixel_response(): void
{
    header('Content-Type: image/gif');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo base64_decode('R0lGODlhAQABAPAAAP///wAAACH5BAAAAAAALAAAAAABAAEAAAICRAEAOw==');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $source = isset($_GET['pixel']) ? 'pixel' : 'direct';
    try {
        $pdo = pixl_pdo();
        pixl_ensure_schema($pdo);
        $eventId = pixl_insert_event($pdo, pixl_pixel_payload($source));
        pixl_maybe_send_webpush($pdo, $eventId);
    } catch (Throwable $e) {
        error_log('pixl_collect GET failed: ' . $e->getMessage());
    }

    if ($source === 'pixel') {
        pixl_transparent_pixel_response();
    }

    pixl_json_response(['ok' => true, 'source' => $source]);
}

if ($method !== 'POST') {
    pixl_json_response(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

$raw = file_get_contents('php://input') ?: '';
$payload = null;

if (isset($_POST['payload']) && is_string($_POST['payload'])) {
    $payload = json_decode($_POST['payload'], true);
} elseif ($raw !== '') {
    $payload = json_decode($raw, true);
}

if (!is_array($payload)) {
    pixl_json_response(['ok' => false, 'error' => 'bad_json'], 400);
}

try {
    $pdo = pixl_pdo();
    pixl_ensure_schema($pdo);
    $eventId = pixl_insert_event($pdo, $payload);
    pixl_maybe_send_webpush($pdo, $eventId);
    pixl_json_response(['ok' => true]);
} catch (Throwable $e) {
    error_log('pixl_collect failed: ' . $e->getMessage());
    pixl_json_response(['ok' => false, 'error' => 'server_error'], 500);
}
