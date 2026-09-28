<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$checks = 0;
function backend_assert(bool $condition, string $message): void {
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException($message);
}
if (($argv[1] ?? '') === '--insert-worker') {
    require $argv[2] . '/pixl_server.php';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    echo pixl_insert_event(pixl_pdo(), json_decode($argv[3], true, 512, JSON_THROW_ON_ERROR));
    exit;
}
require $root . '/pixl_server.php';
$visit = ['reason' => 'VISIT', 'sentAt' => '2026-09-11T10:00:00.100Z', 'engagement' => ['sessionDuration' => 0]];
$leave = ['reason' => 'LEAVE', 'sentAt' => '2026-09-11T10:00:00.900Z', 'engagement' => ['sessionDuration' => 60, 'readingScore' => 50], 'events' => ['reached' => ['LEAVE' => true], 'seconds' => ['LEAVE' => 60]]];
backend_assert(pixl_event_snapshot_is_newer($leave, $visit), 'LEAVE in the same SQL second advances VISIT');
backend_assert(!pixl_event_snapshot_is_newer($visit, $leave), 'delayed VISIT cannot roll back LEAVE');
backend_assert(!pixl_event_snapshot_is_newer($leave, $leave), 'identical LEAVE is idempotent');
backend_assert(!pixl_event_snapshot_is_newer(array_replace($leave, ['sentAt' => '2026-09-11T10:00:00.800Z']), $leave), 'millisecond ordering rejects an older final snapshot');
backend_assert(pixl_event_snapshot_is_newer(array_replace($leave, ['sentAt' => '2026-09-11T10:00:00.901Z']), $leave), 'millisecond ordering accepts a newer final snapshot');
backend_assert(!pixl_event_snapshot_is_newer(array_replace($visit, ['sentAt' => '2026-09-11T10:05:00Z']), $leave), 'reload VISIT preserves completed row');
backend_assert(pixl_event_phase(['reason' => 'HIDDEN']) === 2, 'hidden checkpoint can advance a READ');
$resumedRead = ['reason' => 'READ', 'sentAt' => '2026-09-11T10:05:00Z', 'engagement' => ['sessionDuration' => 90, 'readingScore' => 75]];
backend_assert(pixl_event_snapshot_is_newer($resumedRead, $leave), 'resumed READ can contribute new measurements');
backend_assert(pixl_merge_event_progress($resumedRead, $leave)['reason'] === 'LEAVE', 'resumed READ cannot downgrade terminal metadata');
backend_assert(pixl_event_is_final(['reason' => 'CUSTOM', 'events' => ['reached' => ['LEAVE' => true]]]), 'custom final reason uses the terminal marker');
backend_assert(!pixl_event_is_final(['reason' => 'CUSTOM', 'events' => ['reached' => ['LEAVE' => 'false']]]), 'terminal marker must be boolean');
$merged = pixl_merge_event_progress(['reason' => 'LEAVE', 'engagement' => ['sessionDuration' => 5, 'readingScore' => 0]], $leave);
backend_assert($merged['engagement']['sessionDuration'] === 60 && pixl_notification_reading_score($merged)['score'] === 50, 'reload keeps maximum measured progress');
backend_assert(!in_array($root . '/pixl_config.php', get_included_files(), true), 'pure checks do not load private configuration');
echo "PASS backend snapshot checks ($checks assertions)\n";

$dsn = getenv('PIXL_BACKEND_TEST_DSN') ?: '';
if ($dsn === '') {
    echo "SKIP MySQL/HTTP scenarios: set PIXL_BACKEND_TEST_DSN to an isolated pixl_setup_test_* database\n";
    exit;
}
$pdo = new PDO($dsn, getenv('PIXL_BACKEND_TEST_USER') ?: 'root', getenv('PIXL_BACKEND_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
backend_assert(str_starts_with($database, 'pixl_setup_test_'), 'isolated test database prefix is mandatory');
backend_assert($pdo->query('SHOW TABLES')->fetchAll() === [], 'backend test database must start empty');
$db = ['database' => $database, 'user' => getenv('PIXL_BACKEND_TEST_USER') ?: 'root', 'password' => getenv('PIXL_BACKEND_TEST_PASSWORD') ?: '', 'charset' => 'utf8mb4'];
foreach (explode(';', substr($dsn, 6)) as $part) {
    [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
    if (in_array($key, ['host', 'port'], true)) $db[$key] = $value;
    if ($key === 'unix_socket') $db['socket'] = $value;
}
$fixture = sys_get_temp_dir() . '/stats3-backend-test-' . bin2hex(random_bytes(8));
mkdir($fixture, 0700);
mkdir($fixture . '/stat4', 0700);
mkdir($fixture . '/sessions', 0700);
$server = null;
function backend_request(string $url, ?array $payload = null, string $cookie = ''): array {
    $headers = ['User-Agent: Stats3RegressionFixture/1.0'];
    if ($payload !== null) $headers[] = 'Content-Type: application/json';
    if ($cookie !== '') $headers[] = 'Cookie: ' . $cookie;
    $context = stream_context_create(['http' => ['method' => $payload === null ? 'GET' : 'POST', 'header' => implode("\r\n", $headers), 'content' => $payload === null ? '' : json_encode($payload, JSON_THROW_ON_ERROR), 'ignore_errors' => true, 'timeout' => 8]]);
    $body = file_get_contents($url, false, $context);
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $match);
    return ['status' => (int)($match[1] ?? 0), 'body' => $body, 'headers' => $http_response_header ?? []];
}
try {
    foreach (['pixl_server.php', 'pixl_geoip.php', 'pixl_collect.php', 'pixl_schema.sql', 'stat4/collect.php', 'stat4/db.php', 'stat4/config.php', 'stat4/auth.php', 'stat4/pushover.php'] as $file) copy($root . '/' . $file, $fixture . '/' . $file);
    $config = ['db' => $db, 'public_key' => 'fixture-public-key', 'hash_salt' => str_repeat('fixture-salt-', 4), 'table' => 'pixl_events', 'site_id' => 'regression', 'allowed_hosts' => ['fixture.example', '127.0.0.1'], 'geoip' => ['enabled' => false]];
    file_put_contents($fixture . '/pixl_config.php', '<?php return ' . var_export($config, true) . ';');
    // Local callbacks record dispatch eligibility; no notification transport exists in this fixture.
    file_put_contents($fixture . '/pixl_pushover.php', '<?php function pixl_pushover_notify_event(PDO $pdo, int $id): void { file_put_contents(__DIR__ . "/dispatch.log", "pushover:$id\\n", FILE_APPEND | LOCK_EX); }');
    file_put_contents($fixture . '/pixel_stats2.php', '<?php function pixel_push_notify_event(PDO $pdo, int $id): void { file_put_contents(__DIR__ . "/dispatch.log", "webpush:$id\\n", FILE_APPEND | LOCK_EX); }');
    file_put_contents($fixture . '/health.php', '<?php echo "ready";');
    file_put_contents($fixture . '/auth-probe.php', <<<'CODE'
<?php
require __DIR__ . '/stat4/auth.php';
header('Content-Type: application/json');
$action = $_GET['action'] ?? 'check';
if ($action === 'login') echo json_encode(['ok' => stat4_login((string)($_GET['password'] ?? ''))]);
elseif ($action === 'legacy') { stat4_session_start(); $_SESSION['stat4_admin'] = true; unset($_SESSION['stat4_admin_version']); echo json_encode(['ok' => stat4_is_admin()]); }
else echo json_encode(['ok' => stat4_is_admin()]);
CODE);
    $authConfig = static function (string $password, bool $autologin = false) use ($fixture): void {
        $config = ['admin' => ['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'autologin' => $autologin]];
        file_put_contents($fixture . '/stat4/config.local.php', '<?php return ' . var_export($config, true) . ';');
    };
    $authConfig('old-fixture-password');
    foreach (pixl_unified_schema_definitions($root . '/pixl_schema.sql', 'pixl_events') as $sql) $pdo->exec($sql);
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    backend_assert(is_resource($socket), 'loopback fixture port available');
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $server = proc_open([PHP_BINARY, '-d', 'opcache.enable=0', '-d', 'session.save_path=' . $fixture . '/sessions', '-S', $address, '-t', $fixture], [0 => ['pipe', 'r'], 1 => ['file', $fixture . '/server.log', 'a'], 2 => ['file', $fixture . '/server.log', 'a']], $pipes);
    backend_assert(is_resource($server), 'local fixture server starts');
    fclose($pipes[0]);
    $base = 'http://' . $address;
    $ready = false;
    for ($i = 0; $i < 80; $i++) {
        $connection = @stream_socket_client('tcp://' . $address, $errorCode, $errorMessage, 0.1);
        if (is_resource($connection)) { fclose($connection); $ready = true; break; }
        usleep(25000);
    }
    backend_assert($ready, 'local fixture accepts requests');
    $row = static function (string $eventId) use ($pdo): array { $stmt = $pdo->prepare('SELECT * FROM pixl_events WHERE event_id=?'); $stmt->execute([$eventId]); return $stmt->fetch() ?: []; };
    $payload = static fn(string $reason, string $timestamp, int $duration, int $score): array => [
        'eventId' => 'backend-event-1', 'siteKey' => 'fixture-public-key', 'reason' => $reason, 'sentAt' => $timestamp,
        'page' => ['hostname' => 'fixture.example', 'url' => 'https://fixture.example/article', 'path' => '/article'],
        'context' => ['userAgent' => 'Mozilla/5.0 Firefox/150.0', 'screen' => '777x555', 'knownResolution' => true],
        'engagement' => ['sessionDuration' => $duration, 'readingScore' => $score],
        'events' => ['reached' => [$reason => true], 'seconds' => [$reason => $duration]],
    ];
    $visit = $payload('VISIT', '2026-09-11T10:00:00.100Z', 0, 0);
    $leave = $payload('LEAVE', '2026-09-11T10:00:00.900Z', 60, 50);
    backend_assert(backend_request($base . '/pixl_collect.php', $visit)['status'] === 200, 'initial VISIT accepted');
    $beforeReadDispatch = file_get_contents($fixture . '/dispatch.log');
    backend_assert(backend_request($base . '/pixl_collect.php', $payload('READ', '2026-09-11T10:00:00.500Z', 30, 25))['status'] === 200, 'advancing READ accepted');
    backend_assert($row('backend-event-1')['reason'] === 'READ' && file_get_contents($fixture . '/dispatch.log') === $beforeReadDispatch, 'advancing READ updates measurements without broadening notification policy');
    backend_assert(backend_request($base . '/pixl_collect.php', $leave)['status'] === 200, 'same-second LEAVE accepted');
    $saved = $row('backend-event-1');
    backend_assert($saved['reason'] === 'LEAVE' && (int)$saved['reading_score'] === 50 && (int)$saved['session_duration'] === 60, 'final values stored');
    backend_assert((int)$saved['known_resolution'] === 1 && str_contains($saved['message'], '777x555 (OKAY)'), 'central client resolution classification reaches backend output');
    $dispatch = file_get_contents($fixture . '/dispatch.log');
    backend_request($base . '/pixl_collect.php', $leave);
    backend_request($base . '/pixl_collect.php', $visit);
    backend_assert($row('backend-event-1') === $saved, 'late VISIT and duplicate LEAVE leave exact row unchanged');
    backend_assert(file_get_contents($fixture . '/dispatch.log') === $dispatch, 'duplicate and stale events never dispatch again');
    $reload = $payload('LEAVE', '2026-09-11T10:02:00.100Z', 5, 0);
    backend_request($base . '/pixl_collect.php', $reload);
    backend_assert((int)$row('backend-event-1')['reading_score'] === 50 && (int)$row('backend-event-1')['session_duration'] === 60, 'later reload summary preserves measured maxima');
    $resumed = $payload('FINAL', '2026-09-11T10:03:00.100Z', 90, 150);
    $resumed['events'] = ['reached' => ['LEAVE' => true], 'seconds' => ['LEAVE' => 90]];
    backend_request($base . '/pixl_collect.php', $resumed);
    backend_assert($row('backend-event-1')['reason'] === 'FINAL' && (int)$row('backend-event-1')['reading_score'] === 75 && (int)$row('backend-event-1')['session_duration'] === 90, 'BFCache summary advances and custom final label stays intact');
    $beforeResumeDispatch = file_get_contents($fixture . '/dispatch.log');
    backend_request($base . '/pixl_collect.php', $payload('READ', '2026-09-11T10:05:00.100Z', 120, 450));
    backend_assert($row('backend-event-1')['reason'] === 'FINAL' && (int)$row('backend-event-1')['reading_score'] === 90 && (int)$row('backend-event-1')['session_duration'] === 120, 'resumed READ adds progress while keeping terminal metadata');
    backend_assert(file_get_contents($fixture . '/dispatch.log') === $beforeResumeDispatch, 'resumed READ does not inherit final-summary notification eligibility');
    $before = (int)$pdo->query('SELECT COUNT(*) FROM pixl_events')->fetchColumn();
    foreach (['', '&siteKey=wrong', '&siteKey%5B%5D=fixture-public-key'] as $keyQuery) backend_assert(backend_request($base . '/pixl_collect.php?pixel=1&url=https%3A%2F%2Ffixture.example%2Fpixel' . $keyQuery)['status'] === 403, 'missing, wrong and array pixel keys are rejected');
    backend_assert((int)$pdo->query('SELECT COUNT(*) FROM pixl_events')->fetchColumn() === $before, 'invalid pixel keys never write');
    $pixel = backend_request($base . '/pixl_collect.php?pixel=1&url=https%3A%2F%2Ffixture.example%2Fpixel&siteKey=fixture-public-key');
    backend_assert($pixel['status'] === 200 && str_starts_with($pixel['body'], 'GIF89a'), 'valid public key returns transparent GIF');
    backend_assert((int)$pdo->query('SELECT COUNT(*) FROM pixl_events')->fetchColumn() === $before + 1, 'valid keyed pixel counted once');

    $concurrent = $payload('LEAVE', '2026-09-11T10:00:00.500Z', 30, 50);
    $concurrent['eventId'] = 'backend-concurrent-event';
    $workers = [];
    for ($i = 0; $i < 6; $i++) {
        $proc = proc_open([PHP_BINARY, __FILE__, '--insert-worker', $fixture, json_encode($concurrent, JSON_THROW_ON_ERROR)], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $workerPipes);
        fclose($workerPipes[0]);
        $workers[] = [$proc, $workerPipes];
    }
    $positive = 0;
    foreach ($workers as [$proc, $workerPipes]) {
        $output = stream_get_contents($workerPipes[1]); $errors = stream_get_contents($workerPipes[2]);
        fclose($workerPipes[1]); fclose($workerPipes[2]);
        backend_assert(proc_close($proc) === 0 && $errors === '', 'concurrent insert succeeds without error');
        if ((int)$output > 0) $positive++;
    }
    backend_assert($positive === 1, 'concurrent identical final summaries yield exactly one notification id');

    $cookie = '';
    $login = backend_request($base . '/auth-probe.php?action=login&password=old-fixture-password');
    foreach ($login['headers'] as $header) if (preg_match('/^Set-Cookie:\s*(PHPSESSID=[^;]+)/i', $header, $match)) $cookie = $match[1];
    backend_assert(json_decode($login['body'], true)['ok'] === true && $cookie !== '', 'valid password establishes PHP session');
    backend_assert(json_decode(backend_request($base . '/auth-probe.php', null, $cookie)['body'], true)['ok'] === true, 'matching password version authorizes next request');
    $authConfig('new-fixture-password');
    backend_assert(json_decode(backend_request($base . '/auth-probe.php', null, $cookie)['body'], true)['ok'] === false, 'password change immediately invalidates existing PHP session');
    backend_assert(json_decode(backend_request($base . '/auth-probe.php?action=legacy', null, $cookie)['body'], true)['ok'] === false, 'legacy unversioned admin boolean is not trusted');
    backend_assert(json_decode(backend_request($base . '/auth-probe.php?action=login&password=old-fixture-password', null, $cookie)['body'], true)['ok'] === false, 'old password cannot create a new session');
    $authConfig('cookie-fixture-password', true);
    $cookieLogin = backend_request($base . '/auth-probe.php?action=login&password=cookie-fixture-password');
    $rememberCookie = '';
    foreach ($cookieLogin['headers'] as $header) if (preg_match('/^Set-Cookie:\s*(stat4_admin=[^;]+)/i', $header, $match)) $rememberCookie = $match[1];
    backend_assert($rememberCookie !== '' && json_decode(backend_request($base . '/auth-probe.php', null, $rememberCookie)['body'], true)['ok'] === true, 'signed autologin cookie remains supported');
    $authConfig('rotated-cookie-password', true);
    backend_assert(json_decode(backend_request($base . '/auth-probe.php', null, $rememberCookie)['body'], true)['ok'] === false, 'password rotation also invalidates autologin cookie');

    $now = (int)floor(microtime(true) * 1000);
    $stat = ['eventId' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', 'sessionId' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', 'visitorId' => 'fixture-stat4-visitor', 'visitorStarted' => $now - 30000, 'occurredAt' => $now, 'url' => 'https://fixture.example/first', 'type' => 'pageview', 'activeSeconds' => 0];
    $session = static function (string $id) use ($pdo): array { $stmt = $pdo->prepare('SELECT * FROM stat4_sessions WHERE session_id=?'); $stmt->execute([$id]); return $stmt->fetch() ?: []; };
    $sendStat = static function (array $event) use ($base): array { $result = backend_request($base . '/stat4/collect.php', $event); backend_assert($result['status'] === 200, 'STAT4 request accepted: ' . $result['body']); return json_decode($result['body'], true); };
    $sendStat($stat);
    backend_assert((int)$session($stat['sessionId'])['pageviews'] === 1 && (int)$session($stat['sessionId'])['is_bounce'] === 1, 'one page without engagement stays a bounce');
    $heartbeat = array_replace($stat, ['eventId' => 'cccccccc-cccc-cccc-cccc-cccccccccccc', 'type' => 'heartbeat', 'activeSeconds' => 8]);
    $sendStat($heartbeat);
    backend_assert((int)$session($stat['sessionId'])['active_seconds'] === 8 && (int)$session($stat['sessionId'])['is_bounce'] === 1, 'eight seconds are not counted twice for bounce');
    backend_assert($sendStat($heartbeat)['stored'] === false && (int)$session($stat['sessionId'])['active_seconds'] === 8, 'retry UUID does not duplicate active seconds');
    $sendStat(array_replace($heartbeat, ['eventId' => 'dddddddd-dddd-dddd-dddd-dddddddddddd', 'activeSeconds' => 6]));
    backend_assert((int)$session($stat['sessionId'])['is_bounce'] === 1, 'fourteen seconds remain a bounce');
    $sendStat(array_replace($heartbeat, ['eventId' => 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee', 'activeSeconds' => 1]));
    backend_assert((int)$session($stat['sessionId'])['active_seconds'] === 15 && (int)$session($stat['sessionId'])['is_bounce'] === 0, 'fifteen seconds end the bounce');
    $other = array_replace($stat, ['eventId' => '11111111-1111-1111-1111-111111111111', 'sessionId' => '22222222-2222-2222-2222-222222222222', 'visitorId' => 'fixture-other-visitor']);
    $sendStat($other);
    $sendStat(array_replace($other, ['eventId' => '33333333-3333-3333-3333-333333333333', 'url' => 'https://fixture.example/second']));
    backend_assert((int)$session($other['sessionId'])['pageviews'] === 2 && (int)$session($other['sessionId'])['is_bounce'] === 0, 'two different pages end the bounce');
    $click = array_replace($other, ['eventId' => '44444444-4444-4444-4444-444444444444', 'sessionId' => '55555555-5555-5555-5555-555555555555', 'visitorId' => 'fixture-click-visitor', 'type' => 'click']);
    $sendStat($click);
    backend_assert((int)$session($click['sessionId'])['clicks'] === 1 && (int)$session($click['sessionId'])['is_bounce'] === 0, 'one click ends the bounce');
    $expired = array_replace($heartbeat, ['eventId' => '66666666-6666-6666-6666-666666666666', 'sessionId' => '77777777-7777-7777-7777-777777777777', 'visitorId' => 'fixture-expired-visitor', 'visitorStarted' => $now - 86400000 - 30000, 'occurredAt' => $now - 30001]);
    backend_assert($sendStat($expired)['stored'] === true, 'queued old-visitor event remains valid after identity expiry');
    backend_assert($sendStat($expired)['stored'] === false, 'queued old-visitor retry is idempotent');
    foreach ([['occurredAt' => $now - 1201000], ['occurredAt' => $now + 301000], ['occurredAt' => $now], ['occurredAt' => []]] as $invalid) backend_assert(backend_request($base . '/stat4/collect.php', array_replace($expired, $invalid))['status'] === 422, 'stale, future, after-expiry or malformed event time rejected');
    $legacy = $stat; unset($legacy['occurredAt']); $legacy['eventId'] = '88888888-8888-8888-8888-888888888888';
    backend_assert($sendStat($legacy)['stored'] === true, 'legacy clients without occurredAt remain supported');
    echo "PASS backend MySQL/HTTP regression ($checks assertions; isolated database, local callbacks, concurrent workers)\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname()); }
    rmdir($fixture);
}
