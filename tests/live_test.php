<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/live_data.php';
require_once dirname(__DIR__) . '/stat4/db.php';
$checks = 0;
function live_assert(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException($message);
}
$spring = (new DateTimeImmutable('2026-03-29T12:30:00Z'))->getTimestamp();
$fall = (new DateTimeImmutable('2026-10-25T01:30:00Z'))->getTimestamp();
live_assert(gmdate('c', ua_windows($spring)['day']) === '2026-03-28T23:00:00+00:00', 'spring day starts at Berlin midnight before the DST change');
live_assert(gmdate('c', ua_windows($fall)['day']) === '2026-10-24T22:00:00+00:00', 'fall day starts before the DST change');
live_assert(gmdate('c', ua_windows($fall)['hour']) === '2026-10-25T01:00:00+00:00', 'repeated fall hour uses its correct UTC occurrence');
live_assert(ua_windows($fall)['24h'] === $fall - 86400, 'rolling 24h is always 86400 seconds');
live_assert(stat4_page_url('http://user:secret@example.test:8080/a?utm_source=A%26B#fragment') === 'http://example.test:8080/a?utm_source=A%26B', 'full URL preserves scheme, port and query but no credentials or fragment');
live_assert(stat4_page_url('javascript:alert(1)') === '' && stat4_page_url(['url']) === '', 'invalid URL inputs are rejected');
live_assert(ua_url_campaign('https://example.test/?utm_source=A%26B&utm_campaign=Summer+Sale') === ['A&B', 'Summer Sale'], 'campaign URL parameters decoded');
live_assert(ua_url_campaign('/?utm_source[]=evil&campaignname=Sale') === ['', 'Sale'], 'array parameters cannot become campaign strings');
live_assert(ua_campaign_key('a|b', 'c') !== ua_campaign_key('a', 'b|c'), 'campaign grouping keys cannot collide on separators');
live_assert(pixl_stats_safe_return_url('live.php') === 'live.php', 'UA works with the shared login return URL');
live_assert(pixl_stats_safe_return_url('https://evil.test/live.php') === '', 'shared login still rejects external redirects');
echo "PASS UA pure checks ($checks assertions)\n";
$dsn = getenv('PIXL_LIVE_TEST_DSN') ?: '';
if ($dsn === '') { echo "SKIP MySQL/HTTP: set PIXL_LIVE_TEST_DSN to an empty pixl_setup_test_* database\n"; exit; }
$pdo = new PDO($dsn, getenv('PIXL_LIVE_TEST_USER') ?: 'root', getenv('PIXL_LIVE_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
live_assert(str_starts_with($database, 'pixl_setup_test_'), 'only an isolated test database is allowed');
live_assert($pdo->query('SHOW TABLES')->fetchAll() === [], 'test database starts empty');
foreach (pixl_unified_schema_definitions(dirname(__DIR__) . '/pixl_schema.sql', 'pixl_events') as $sql) $pdo->exec($sql);
$now = (new DateTimeImmutable('2026-09-11T12:30:00Z'))->getTimestamp();
$w = ua_windows($now);
$visitor = static function (string $id, string $source, string $campaign, bool $visitorBot = false, bool $sessionBot = false) use ($pdo, $now): void {
    $stmt = $pdo->prepare('INSERT INTO stat4_visitors (visitor_hash,first_seen,last_seen,first_ip_hash,is_bot) VALUES (?,?,?,?,?)');
    $stmt->execute([$id, gmdate('Y-m-d H:i:s', $now - 90000), gmdate('Y-m-d H:i:s', $now), $id, (int)$visitorBot]);
    $stmt = $pdo->prepare('INSERT INTO stat4_sessions (session_id,visitor_hash,started_at,last_seen,utm_source,utm_campaign,is_bot) VALUES (?,?,?,?,?,?,?)');
    $stmt->execute([$id, $id, gmdate('Y-m-d H:i:s', $now - 90000), gmdate('Y-m-d H:i:s', $now), $source, $campaign, (int)$sessionBot]);
};
$event = static function (string $visitor, int $time, string $path, string $type = 'pageview', string $host = 'example.test') use ($pdo): void {
    $stmt = $pdo->prepare('INSERT INTO stat4_events (event_uuid,session_id,visitor_hash,occurred_at,event_type,hostname,path,page_url) VALUES (?,?,?,?,?,?,?,?)');
    $stmt->execute([bin2hex(random_bytes(16)), $visitor, $visitor, gmdate('Y-m-d H:i:s', $time), $type, $host, $path, 'http://' . $host . ':8080' . $path]);
};
foreach (['a', 'b', 'yesterday'] as $id) $visitor($id, 'alpha', 'Launch');
$visitor('c', 'beta', 'Launch'); $visitor('heartbeat-only', '', ''); $visitor('future', '', '');
$visitor('bot-visitor', 'alpha', 'Launch', true); $visitor('bot-session', 'alpha', 'Launch', false, true);
$event('a', $now - 86401, '/old'); $event('a', $now - 86400, '/boundary');
$event('a', $now - 3601, '/repeat?q=1'); $event('a', $now - 10, '/repeat?q=2');
$event('b', $now - 8, '/fresh?utm_source=alpha&utm_campaign=Launch'); $event('b', $now - 4, '/fresh?reload=1');
$event('c', $now - 3, '/fresh'); $event('yesterday', $now - 60000, '/yesterday');
$event('heartbeat-only', $now - 7, '/heartbeat', 'heartbeat'); $event('heartbeat-only', $now - 6, '/click', 'click');
$event('bot-visitor', $now - 2, '/bot'); $event('bot-session', $now - 1, '/bot2'); $event('future', $now, '/future');
$pdo->exec("INSERT INTO pixl_events (id,event_id,page_url) VALUES (33,'push-fixture','https://example.test/?utm_source=alpha&utm_campaign=Launch'), (34,'unknown-fixture','https://example.test/plain'), (35,'older-fixture','https://example.test/?utm_source=alpha&utm_campaign=Launch')");
$message = static function (string $source, string $id, int $part, string $visitor, int $time) use ($pdo): void {
    $stmt = $pdo->prepare('INSERT INTO mind_notifications (source,source_event_id,part_index,visitor_hash,sent_at,title,message) VALUES (?,?,?,?,?,?,?)');
    $stmt->execute([$source, $id, $part, $visitor, gmdate('Y-m-d H:i:s', $time), 'Fixture only', 'No notification was sent.']);
};
$message('stats3', '33', 1, 'stats3-hash', $now - 7); $message('stats3', '33', 2, 'stats3-hash', $now - 6);
$message('stats3', '34', 1, '', $now - 5); $message('stats3', '35', 1, '', $now - 60000);
$message('stat4', 'c:5', 1, 'c', $now - 2); $message('stat4-history', 'notification:1', 1, 'c', $now - 2);
$stmt = $pdo->prepare("INSERT INTO stat4_notifications (visitor_hash,pageview_milestone,status,sent_at) VALUES ('c',5,'sent',?),('a',5,'failed',?),('a',10,'pending',NULL)");
$stmt->execute([gmdate('Y-m-d H:i:s', $now - 2), gmdate('Y-m-d H:i:s', $now - 4)]);
$before = $pdo->query('SELECT * FROM stat4_events ORDER BY id')->fetchAll();
$data = ua_live_snapshot($pdo, $now);
live_assert($data['totals']['impressions_24h'] === 7 && $data['totals']['users_24h'] === 4, '24h counts include lower boundary and reloads, exclude future, bots, clicks and heartbeat');
live_assert($data['totals']['impressions_day'] === 5 && $data['totals']['users_day'] === 3, 'calendar day differs from rolling 24h');
live_assert($data['totals']['impressions_hour'] === 4 && $data['totals']['users_hour'] === 3, 'current clock hour counts actual events from older sessions');
live_assert($data['totals']['pages_24h'] === 4 && $data['totals']['pages_day'] === 2, 'different pages exclude query strings');
live_assert(count($data['hourly']) === 24 && array_sum(array_column($data['hourly'], 'impressions')) === 7, '24 hourly buckets cover every pageview once');
live_assert(array_sum(array_column($data['heartbeat'], 'users')) === 2 && array_sum(array_column($data['heartbeat'], 'impressions')) === 4, 'one downbeat per new visitor, one upbeat per pageview including reload');
live_assert(count($data['latest']) === 5 && $data['latest'][0]['url'] === 'http://example.test:8080/fresh', 'latest five retain full URL and use event time ordering');
live_assert($data['latest'][1]['url'] === 'http://example.test:8080/fresh?reload=1', 'latest URL keeps all query parameters');
live_assert($data['totals']['pushes_day'] === 4 && $data['totals']['pushes_24h'] === 5 && count($data['recent_pushes']) === 4, 'confirmed multipart sends count, failed/pending/history copies do not');
$groups = []; foreach ($data['campaigns'] as $row) $groups[$row['source']] = $row;
live_assert($groups['alpha']['users_24h'] === 3 && $groups['alpha']['impressions_24h'] === 6 && $groups['alpha']['pushes_24h'] === 3, 'campaign uses source AND name and keeps day/24h push attribution');
live_assert($groups['beta']['pushes_day'] === 1 && $groups['(nicht zuordenbar)']['pushes_day'] === 1, 'STAT4 session attribution and unknown Stats3 attribution stay distinct');
live_assert($before === $pdo->query('SELECT * FROM stat4_events ORDER BY id')->fetchAll(), 'dashboard snapshot never changes visitor events');
live_assert(ua_live_snapshot($pdo, $now) === $data, 'repeated snapshot is stable and read-only');
// A late commit with an earlier occurred_at is picked up on the very next snapshot.
$event('b', $now - 9, '/late');
$late = ua_live_snapshot($pdo, $now);
live_assert($late['totals']['impressions_24h'] === 8 && array_sum(array_column($late['heartbeat'], 'impressions')) === 5, 'late arrivals are not lost behind an ID cursor');
$pdo->exec('ALTER TABLE stat4_events DROP COLUMN page_url');
$legacy = ua_live_snapshot($pdo, $now);
live_assert(!$legacy['latest'][0]['complete_url'] && $legacy['latest'][0]['url'] === 'example.test/fresh' && count($legacy['warnings']) === 1, 'legacy schema stays readable without invented HTTPS URLs');
stat4_ensure_page_url_column($pdo); stat4_ensure_page_url_column($pdo);
live_assert((int)$pdo->query('SELECT COUNT(*) FROM stat4_events')->fetchColumn() === count($before) + 1, 'URL column upgrade is repeatable and preserves old events');
$pdo->exec("UPDATE stat4_events SET page_url=CONCAT('http://',hostname,':8080',path)");
$visitor('case-sensitive', 'Alpha', 'Launch');
$event('case-sensitive', $now - 20, '/Fresh');
$case = ua_live_snapshot($pdo, $now);
live_assert($case['totals']['pages_24h'] === 6, 'case-sensitive URL paths remain different pages');
live_assert(count(array_filter($case['campaigns'], static fn(array $row): bool => $row['source'] === 'Alpha' && $row['impressions_24h'] === 1)) === 1, 'campaign case remains consistent between SQL groups and PHP notification attribution');
$pdo->exec('RENAME TABLE mind_notifications TO fixture_mind_saved');
$partial = ua_live_snapshot($pdo, $now);
live_assert($partial['totals']['pushes_day'] === 1 && count($partial['warnings']) === 1, 'missing Mind table shows partial-source warning and retains confirmed STAT4 sends');
$pdo->exec('RENAME TABLE fixture_mind_saved TO mind_notifications');
echo "PASS UA MySQL ($checks assertions; real queries, interval boundaries, attribution, URL upgrade, late arrivals)\n";

$root = dirname(__DIR__);
$fixture = sys_get_temp_dir() . '/stats3-ua-http-' . bin2hex(random_bytes(6));
mkdir($fixture, 0700); mkdir($fixture . '/sessions', 0700); mkdir($fixture . '/stat4', 0700);
$db = ['database' => $database, 'user' => getenv('PIXL_LIVE_TEST_USER') ?: 'root', 'password' => getenv('PIXL_LIVE_TEST_PASSWORD') ?: '', 'charset' => 'utf8mb4'];
foreach (explode(';', substr($dsn, 6)) as $part) { [$key, $value] = array_pad(explode('=', $part, 2), 2, ''); if (in_array($key, ['host', 'port'], true)) $db[$key] = $value; if ($key === 'unix_socket') $db['socket'] = $value; }
foreach (['pixl_server.php', 'pixl_geoip.php', 'pixl_pushover.php', 'live.php', 'live_data.php', 'live.css', 'live.js', 'stat4/db.php', 'stat4/collect.php', 'stat4/pushover.php', 'stat4/config.php'] as $file) copy($root . '/' . $file, $fixture . '/' . $file);
$config = ['db' => $db, 'table' => 'pixl_events', 'stats_password' => 'ua-fixture-password', 'hash_salt' => str_repeat('ua-fixture-', 4), 'allowed_hosts' => ['example.test'], 'geoip' => ['enabled' => false], 'pushover' => ['enabled' => false]];
file_put_contents($fixture . '/pixl_config.php', '<?php return ' . var_export($config, true) . ';');
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr); $address = stream_socket_get_name($socket, false); fclose($socket);
$server = proc_open([PHP_BINARY, '-d', 'opcache.enable=0', '-d', 'session.save_path=' . $fixture . '/sessions', '-S', $address, '-t', $fixture], [0 => ['pipe', 'r'], 1 => ['file', $fixture . '/server.log', 'a'], 2 => ['file', $fixture . '/server.log', 'a']], $pipes);
live_assert(is_resource($server), 'local authenticated HTTP fixture starts'); fclose($pipes[0]);
function live_http(string $url, string $method = 'GET', string $body = '', string $cookie = '', string $contentType = 'application/x-www-form-urlencoded'): array {
    $context = stream_context_create(['http' => ['method' => $method, 'header' => "Content-Type: $contentType\r\nCookie: $cookie\r\nUser-Agent: Mozilla/5.0 Firefox/150.0", 'content' => $body, 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10]]);
    $result = file_get_contents($url, false, $context); preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $match);
    return ['status' => (int)($match[1] ?? 0), 'body' => $result, 'headers' => $http_response_header ?? []];
}
try {
    for ($i = 0; $i < 80; $i++) { $connection = @stream_socket_client('tcp://' . $address, $errno, $errstr, .1); if ($connection) { fclose($connection); break; } usleep(25000); }
    $base = 'http://' . $address;
    $unauthorized = live_http($base . '/live.php?data=1');
    live_assert($unauthorized['status'] === 401 && json_decode($unauthorized['body'], true)['ok'] === false, 'unauthenticated JSON returns 401 without analytics');
    $login = live_http($base . '/live.php'); live_assert(str_contains($login['body'], 'stats_password'), 'UA HTML uses shared password login');
    $loggedIn = live_http($base . '/live.php', 'POST', 'stats_password=ua-fixture-password');
    $cookie = ''; foreach ($loggedIn['headers'] as $header) if (preg_match('/^Set-Cookie: ([^;]+)/i', $header, $match)) $cookie = $match[1];
    live_assert($loggedIn['status'] === 302 && $cookie !== '', 'shared login sets authentication cookie');
    $page = live_http($base . '/live.php', 'GET', '', $cookie);
    live_assert($page['status'] === 200 && str_contains($page['body'], 'Campaignsource &amp; Campaignname') && str_contains($page['body'], 'href="live.php?view=useragents"'), 'authenticated page contains Live and the active UserAgents link');
    $json = live_http($base . '/live.php?data=1', 'GET', '', $cookie);
    live_assert($json['status'] === 200 && json_decode($json['body'], true)['ok'], 'authenticated JSON uses the actual database queries');
    live_assert(str_contains(strtolower(implode('\n', $json['headers'])), 'cache-control: no-store'), 'private live response is not cached');
    // A real collector request demonstrates that the next pageview stores its complete URL.
    $collector = live_http($base . '/stat4/collect.php', 'POST', json_encode(['eventId' => 'aabbccdd-1111-2222-3333-abcdefabcdef', 'sessionId' => 'aabbccdd-1111-2222-3333-abcdefabcdef', 'visitorId' => 'ua-http-fixture', 'type' => 'pageview', 'url' => 'http://example.test:8080/full?utm_source=http&utm_campaign=URL%20test', 'utm' => ['source' => 'http', 'campaign' => 'URL test']]), '', 'application/json');
    live_assert($collector['status'] === 200 && json_decode($collector['body'], true)['ok'], 'existing STAT4 collector still accepts a real pageview');
    live_assert($pdo->query("SELECT page_url FROM stat4_events WHERE event_uuid='aabbccdd-1111-2222-3333-abcdefabcdef'")->fetchColumn() === 'http://example.test:8080/full?utm_source=http&utm_campaign=URL%20test', 'collector writes full URL from unchanged client payload');
    if (getenv('PIXL_LIVE_TEST_BROWSER') === '1') {
        $output = getenv('PIXL_LIVE_ARTIFACTS') ?: $fixture;
        file_put_contents($fixture . '/snapshot.json', json_encode($data, JSON_THROW_ON_ERROR));
        $process = proc_open(['node', $root . '/tests/live_browser_test.js'], [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $browserPipes, $root, array_merge(getenv(), ['UA_TEST_BASE_URL' => $base, 'UA_TEST_COOKIE' => $cookie, 'UA_TEST_SNAPSHOT' => $fixture . '/snapshot.json', 'UA_TEST_ARTIFACTS' => $output]));
        fclose($browserPipes[0]); live_assert(proc_close($process) === 0, 'browser behavior and responsive rendering passed');
    }
    echo "PASS UA HTTP ($checks assertions; login, private JSON, full-URL collector)\n";
    echo "Fixture and server log: $fixture\n";
} finally { proc_terminate($server); proc_close($server); }
