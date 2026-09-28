<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/stats_export.php';
require_once dirname(__DIR__) . '/pixl_server.php';

$checks = 0;
function export_assert(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException($message);
}
foreach (['=1+1', '+SUM(A1:A2)', '-1+2', '@SUM(A1)', '  =HYPERLINK("x")', "\ttext", "\r=1", "\n=1"] as $value) {
    export_assert(stats_export_cell($value) === "'" . $value, 'formula-like text is neutralized');
}
foreach (['München; "Test"', "line one\nline two", '000123', 'normal', 'https://example.test/?a=1&b=2'] as $value) {
    export_assert(stats_export_cell($value) === $value, 'ordinary CSV data is preserved');
}
export_assert(stats_export_cell('-12.30', true) === '-12.30' && stats_export_cell(null) === '', 'numeric negatives and null export correctly');
export_assert(stats_export_identifier('a`b') === '`a``b`', 'SQL identifiers are quoted');
export_assert(isset(stats_export_sources('custom_export_events')['custom_export_events']) && !isset(stats_export_sources('custom_export_events')['pixl_events']), 'custom event table is respected');
export_assert(!isset(stats_export_sources('pixl_events')['pixl_events_push_meta']), 'push secrets are not an export source');

$now = (int)(getenv('PIXL_EXPORT_TEST_NOW') ?: time());
putenv('PIXL_EXPORT_TEST_NOW=' . $now);
$window = stats_export_window($now);
export_assert($window['to_unix'] - $window['from_unix'] === 90 * 86400, 'window is exactly 90 rolling days');
export_assert(pixl_stats_safe_return_url('export.php') === 'export.php' && pixl_stats_safe_return_url('https://evil.test/export.php') === '', 'export is an internal login destination only');
$dsn = getenv('PIXL_EXPORT_TEST_DSN') ?: '';
if ($dsn === '') { echo "PASS export pure checks ($checks assertions)\nSKIP MySQL/HTTP: set PIXL_EXPORT_TEST_DSN to an empty pixl_setup_test_* database\n"; exit; }
$user = getenv('PIXL_EXPORT_TEST_USER') ?: 'root';
$password = getenv('PIXL_EXPORT_TEST_PASSWORD') ?: '';
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
$pdo = new PDO($dsn, $user, $password, $options);
$database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
export_assert(str_starts_with($database, 'pixl_setup_test_'), 'only an explicitly disposable test database may be used');
$eventTable = 'custom_export_events';
$pdo->exec("SET time_zone = '+00:00'");
if (($argv[1] ?? '') === '--large-export') {
    foreach (['csv', 'json', 'html'] as $format) {
        $stream = $format === 'html' ? stats_export_create_html($pdo, $eventTable, $now) : stats_export_create($pdo, $eventTable, $format, $now);
        export_assert(fstat($stream)['size'] > 32 * 1024 * 1024, 'large export is complete on disk');
        if ($format === 'csv') {
            $count = 0;
            while (fgetcsv($stream, null, ';', '"', '') !== false) $count++;
            export_assert($count === 1064, 'large CSV retains all rows and its header');
        } elseif ($format === 'json') {
            fseek($stream, -2, SEEK_END);
            export_assert(fread($stream, 2) === ']}', 'large JSON is fully closed');
        } else {
            fseek($stream, -8, SEEK_END);
            export_assert(fread($stream, 8) === "</html>\n", 'large HTML is fully closed');
        }
        fclose($stream);
    }
    echo "PASS CSV, JSON and standalone HTML larger than 32 MiB with a 16 MiB PHP memory limit\n";
    exit;
}
export_assert($pdo->query('SHOW TABLES')->fetchAll() === [], 'test database starts empty');
foreach (pixl_unified_schema_definitions(dirname(__DIR__) . '/pixl_schema.sql', $eventTable) as $sql) $pdo->exec($sql);
$insert = static function (string $table, array $row) use ($pdo): void {
    $query = 'INSERT INTO ' . stats_export_identifier($table) . ' (' . implode(',', array_map('stats_export_identifier', array_keys($row))) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')';
    $pdo->prepare($query)->execute(array_values($row));
};
$hash = hash('sha256', 'export-visitor');
$longPayload = json_encode(['text' => str_repeat('Ö;"value"', 9000), 'nested' => [1, 2, "line\none"]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$recent = gmdate('Y-m-d H:i:s', $now - 600);
$insert($eventTable, ['event_id' => 'export-current-bot', 'created_at' => $recent, 'country' => 'DE', 'is_bot' => 1, 'title' => "München; \"Zitat\"\nZweite Zeile", 'payload_json' => $longPayload, 'v3_user_score' => '-12.30']);
$insert($eventTable, ['event_id' => 'export-current', 'created_at' => $recent, 'country' => 'US', 'title' => '=HYPERLINK("https://example.test")']);
$insert($eventTable, ['event_id' => 'boundary-start', 'created_at' => $window['from'], 'title' => '<img src=x onerror="window.exportXss=1"></script><script>window.exportXss=1</script>']);
$insert($eventTable, ['event_id' => 'boundary-end', 'created_at' => $window['to']]);
$insert($eventTable, ['event_id' => 'excluded-old-event', 'created_at' => '2020-01-01']);
$insert($eventTable, ['event_id' => 'excluded-before-boundary', 'created_at' => gmdate('Y-m-d H:i:s', $window['from_unix'] - 1)]);
$insert($eventTable, ['event_id' => 'excluded-future-event', 'created_at' => gmdate('Y-m-d H:i:s', $now + 600)]);
$ages = [];
foreach ([3600,86400,7*86400,14*86400,30*86400,60*86400] as $boundary) array_push($ages,$boundary-1,$boundary,$boundary+1);
array_push($ages,90*86400-1,90*86400);
for ($i=0; $i<45; $i++) $insert($eventTable, ['event_id' => 'page-' . $i, 'created_at' => gmdate('Y-m-d H:i:s', $now - ($ages[$i] ?? 600))]);
// Older parent records are required to interpret recent events.
$insert('stat4_visitors', ['visitor_hash' => $hash, 'first_seen' => '2020-01-01', 'last_seen' => '2020-01-02', 'first_ip_hash' => $hash]);
$insert('stat4_sessions', ['session_id' => 'export-session', 'visitor_hash' => $hash, 'started_at' => '2020-01-01', 'last_seen' => '2020-01-02', 'pageviews' => 7, 'utm_campaign' => 'Äpfel; "Sommer"']);
$insert('stat4_events', ['event_uuid' => 'export-stat4-event', 'session_id' => 'export-session', 'visitor_hash' => $hash, 'occurred_at' => $recent, 'event_type' => 'pageview', 'page_url' => 'https://example.test/page?utm_source=test&x=1']);
$insert('stat4_events', ['event_uuid' => 'excluded-old-stat4', 'session_id' => 'export-session', 'visitor_hash' => $hash, 'occurred_at' => '2020-01-01', 'event_type' => 'pageview']);
$insert('stat4_notifications', ['visitor_hash' => $hash, 'pageview_milestone' => 5, 'status' => 'sent', 'created_at' => $recent]);
$insert('impressions', ['created_at' => $recent, 'day' => substr($recent,0,10), 'page_title' => 'Banner test', 'event_type' => 'ad']);
$insert('ppcmate_attributions', ['client_id' => 'client-a', 'tracking_id' => '00001234', 'captured_at' => 1, 'last_seen_at' => 2, 'expires_at' => 3]);
$insert('ppcmate_conversions', ['dedupe_hash' => $hash, 'client_id' => 'client-a', 'tracking_id' => '00001234', 'event_key' => 'sale', 'value' => '12.50', 'created_at' => $now-600]);
$insert('mind_geo_cache', ['ip_hash' => $hash, 'country_name' => 'Deutschland', 'looked_up_at' => '2020-01-01', 'expires_at' => '2020-01-02', 'raw_json' => '{"city":"München"}']);
$insert('mind_notifications', ['source' => 'stats3', 'source_event_id' => '1', 'visitor_hash' => $hash, 'sent_at' => $recent, 'title' => 'Mind', 'message' => 'Export notification fixture', 'geo_ip_hash' => $hash]);
$insert('pixl_captcha_state', ['id' => 1, 'waiting_visitors' => 250, 'waiting_page_views' => 400, 'phase_id' => 'fixture-phase']);
$insert('pixl_captcha_stats', ['id' => 1, 'blocked_visitors' => 3, 'last_successes' => 10, 'last_failures' => 2]);
$insert('pixl_captcha_visitors', ['visitor_hash' => $hash, 'last_seen' => $now-600, 'token' => 'DO_NOT_EXPORT_CHALLENGE_SECRET', 'failed_attempts' => 2]);
$insert('pixl_captcha_page_views', ['visitor_hash' => $hash, 'page_hash' => hash('sha256', 'page')]);
$insert('legacy_sqlite_rows', ['source_file' => 'old.sqlite', 'source_table' => 'visits', 'source_rowid' => 'new-1', 'source_created_at' => $recent, 'row_json' => '{"old_title":"Historisch; importiert"}']);
$insert('legacy_sqlite_rows', ['source_file' => 'old.sqlite', 'source_table' => 'visits', 'source_rowid' => 'old-1', 'source_created_at' => '2020-01-01', 'row_json' => '{"marker":"excluded-legacy-old"}']);
$insert('legacy_sqlite_rows', ['source_file' => 'old.sqlite', 'source_table' => 'visits', 'source_rowid' => 'unknown-1', 'row_json' => '{"marker":"excluded-legacy-undated"}']);
$oldHash = hash('sha256', 'old-unrelated-visitor');
$insert('stat4_visitors', ['visitor_hash' => $oldHash, 'first_seen' => '2020-01-01', 'last_seen' => '2020-01-02', 'first_ip_hash' => $oldHash]);
$insert('pixl_captcha_visitors', ['visitor_hash' => $oldHash, 'last_seen' => 1]);
$insert('pixl_captcha_page_views', ['visitor_hash' => $oldHash, 'page_hash' => hash('sha256', 'old-page')]);
$insert($eventTable . '_push_meta', ['name' => 'vapid_private_key', 'value' => 'DO_NOT_EXPORT_PUSH_SECRET']);
$pdo->exec('CREATE TABLE unrelated_private (secret TEXT)');
$insert('unrelated_private', ['secret' => 'DO_NOT_EXPORT_UNRELATED_SECRET']);

$fingerprint = static function () use ($pdo): array {
    $rows = [];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $rows[$table] = hash('sha256', serialize($pdo->query('SELECT * FROM ' . stats_export_identifier($table))->fetchAll()));
    }
    return $rows;
};
$parse = static function ($stream): array {
    rewind($stream);
    export_assert(fread($stream, 3) === "\xEF\xBB\xBF", 'CSV starts with UTF-8 BOM');
    $header = fgetcsv($stream, null, ';', '"', '');
    $rows = [];
    while (($record = fgetcsv($stream, null, ';', '"', '')) !== false) {
        export_assert(count($record) === count($header), 'every row uses the same column count');
        $rows[] = array_combine($header, $record);
    }
    return [$header, $rows];
};
$before = $fingerprint();
$stream = stats_export_create_csv($pdo, $eventTable, $now);
[$header, $rows] = $parse($stream);
rewind($stream); $csv = stream_get_contents($stream); fclose($stream);
export_assert(count($rows) === 63 && count(array_unique(array_column($rows, 'Tabelle'))) === 15, 'all installed statistics sources and rows are present');
export_assert($rows[0]['event_id'] === 'export-current-bot' && $rows[0]['country'] === 'DE' && $rows[0]['is_bot'] === '1', 'recent events and German bots are included');
export_assert($rows[0]['title'] === "München; \"Zitat\"\nZweite Zeile" && $rows[0]['payload_json'] === $longPayload, 'quoting, line breaks, Unicode and long JSON values survive a CSV round trip');
export_assert($rows[0]['v3_user_score'] === '-12.30' && str_starts_with($rows[1]['title'], "'=HYPERLINK"), 'numeric values remain numeric and title formulas remain text');
export_assert(!in_array('token', $header, true) && !str_contains($csv, 'DO_NOT_EXPORT_'), 'challenge tokens, push credentials and unrelated tables are excluded');
export_assert($fingerprint() === $before && !$pdo->inTransaction() && $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY), 'export leaves every stored row and the connection unchanged');
export_assert(!str_contains($csv, 'excluded-') && !str_contains($csv, $oldHash), 'old, future, undated and unrelated records are excluded');
export_assert(str_contains($csv, 'boundary-start') && str_contains($csv, 'boundary-end'), 'both exact window boundaries are included');
$overview = stats_export_overview($pdo, $eventTable, $now, $eventTable, 999);
export_assert($overview['total_rows'] === 63 && $overview['sources'][$eventTable]['rows'] === 49 && $overview['sources'][$eventTable]['stored_rows'] === 52, 'overview counts all included and stored records');
export_assert($overview['details']['page'] === 3 && count($overview['details']['rows']) === 9, 'last detail page includes all remaining rows and clamps excessive pages');
export_assert($overview['sources']['legacy_sqlite_rows']['undated_rows'] === 1 && $overview['sources']['pixl_captcha_stats']['kind'] === 'snapshot', 'undated legacy records and cumulative snapshots are explicit');
export_assert($overview['sources']['stat4_visitors']['rows'] === 1 && $overview['sources']['stat4_sessions']['rows'] === 1 && $overview['sources']['mind_geo_cache']['rows'] === 1 && $overview['sources']['ppcmate_attributions']['rows'] === 1, 'older required parent records are retained');
$jsonStream = stats_export_create($pdo, $eventTable, 'json', $now);
$jsonText = stream_get_contents($jsonStream); fclose($jsonStream);
$backup = json_decode($jsonText, true, 512, JSON_THROW_ON_ERROR);
$backupTables = array_column($backup['tables'], null, 'name');
export_assert(count($backupTables) === 15 && $backup['meta']['summary']['total_rows'] === 63, 'JSON contains every source, metadata and matching row counts');
export_assert($backupTables[$eventTable]['rows'][0]['payload_json'] === $longPayload && $backupTables[$eventTable]['rows'][1]['title'] === '=HYPERLINK("https://example.test")', 'JSON retains full original text and formulas losslessly');
export_assert($backupTables[$eventTable]['rows'][0]['sent_at'] === null && $backupTables['ppcmate_attributions']['rows'][0]['tracking_id'] === '00001234', 'JSON preserves NULL and leading zeros');
export_assert(!str_contains($jsonText, 'DO_NOT_EXPORT_') && !str_contains($jsonText, 'excluded-legacy-undated'), 'JSON omits credentials and undated legacy data');
export_assert($backupTables['pixl_captcha_visitors']['excluded_columns'] === ['token'] && !array_key_exists('token', $backupTables['pixl_captcha_visitors']['rows'][0]), 'omitted challenge token is documented');
$htmlStream = stats_export_create_html($pdo, $eventTable, $now, $eventTable, 3);
$html = stream_get_contents($htmlStream); fclose($htmlStream);
export_assert(str_starts_with($html, '<!doctype html>') && str_contains($html, 'data-offline="true"'), 'standalone HTML is complete and marked offline');
export_assert(preg_match('~<script id="stats3Snapshot" type="application/json">(.*?)</script>~s', $html, $embedded) === 1, 'full archive is embedded in HTML');
$htmlBackup = json_decode($embedded[1], true, 512, JSON_THROW_ON_ERROR);
export_assert($htmlBackup['tables'] === $backup['tables'] && $htmlBackup['meta']['summary']['total_rows'] === 63, 'HTML contains every raw row and schema, independent of selected page');
export_assert(!preg_match('~<(?:script|link)[^>]+(?:src|href)=~i', $html) && str_contains($html, "connect-src 'none'"), 'HTML embeds scripts/styles and forbids network fetches');
export_assert(!str_contains($html, '<script>window.exportXss') && !str_contains($html, 'DO_NOT_EXPORT_'), 'script-closing payloads and credentials cannot escape into HTML');
foreach (['60 Minuten','24 Stunden','7 Tage','14 Tage','1 Monat','2 Monate','3 Monate'] as $label) export_assert(str_contains($html, '>' . $label . '</button>'), 'static filter exists: ' . $label);
$modelFixture = sys_get_temp_dir() . '/stats3-export-model-' . bin2hex(random_bytes(6)) . '.json';
file_put_contents($modelFixture, $jsonText);
file_put_contents($modelFixture . '.csv', $csv);
try {
    $process = proc_open(['node', dirname(__DIR__) . '/tests/export_model_test.js', $modelFixture, $modelFixture . '.csv'], [0=>['pipe','r'],1=>STDOUT,2=>STDERR], $modelPipes);
    fclose($modelPipes[0]); export_assert(proc_close($process) === 0, 'offline model matches SQL and every time boundary');
} finally { unlink($modelFixture); unlink($modelFixture . '.csv'); }
$pdo->exec("SET time_zone = '+02:00'");
$timezoneStream = stats_export_create_csv($pdo, $eventTable, $now);
export_assert(stream_get_contents($timezoneStream) === $csv && $pdo->query('SELECT @@session.time_zone')->fetchColumn() === '+02:00', 'UTC output is stable and caller timezone is restored');
fclose($timezoneStream); $pdo->exec("SET time_zone = '+00:00'");
$pdo->exec('RENAME TABLE impressions TO fixture_impressions_saved');
$missing = stats_export_overview($pdo, $eventTable, $now);
export_assert(!$missing['sources']['impressions']['available'] && $missing['total_rows'] === 62, 'missing optional module is visible and other statistics still work');
$pdo->exec('RENAME TABLE fixture_impressions_saved TO impressions');

// Prove that the lossless archive can recreate the statistical rows in a separate database.
$restoreDatabase = $database . '_restore';
$pdo->exec('CREATE DATABASE ' . stats_export_identifier($restoreDatabase) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$restoreDsn = preg_replace('/dbname=[^;]+/', 'dbname=' . $restoreDatabase, $dsn);
$restore = new PDO($restoreDsn, $user, $password, $options);
try {
    $restore->exec("SET time_zone = '+00:00'");
    $restore->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($backupTables as $source) $restore->exec($source['create_table_sql']);
    $restore->exec('SET FOREIGN_KEY_CHECKS=1');
    $restoreOrder = array_keys($backupTables);
    // Mind notifications depend on geodata; the other archive tables are already ordered.
    $restoreOrder = array_values(array_diff($restoreOrder, ['mind_geo_cache']));
    array_unshift($restoreOrder, 'mind_geo_cache');
    foreach ($restoreOrder as $tableName) {
        $source = $backupTables[$tableName];
        foreach ($source['rows'] as $row) {
            $columns = implode(',', array_map('stats_export_identifier', array_keys($row)));
            $restore->prepare('INSERT INTO ' . stats_export_identifier($tableName) . " ($columns) VALUES (" . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
        }
        $columns = implode(',', array_map('stats_export_identifier', array_column($source['columns'], 'Field')));
        $order = $source['primary_key'] ? ' ORDER BY ' . implode(',', array_map('stats_export_identifier', $source['primary_key'])) : '';
        $restoredRows = $restore->query('SELECT ' . $columns . ' FROM ' . stats_export_identifier($tableName) . $order)->fetchAll();
        foreach ($restoredRows as &$row) { foreach ($row as &$value) if ($value !== null) $value = (string)$value; unset($value); } unset($row);
        export_assert($restoredRows === $source['rows'] && count($restoredRows) === $source['row_count'], 'backup restores exact rows with foreign keys: ' . $tableName);
    }
    foreach (array_reverse($restoreOrder) as $tableName) $restore->exec('DELETE FROM ' . stats_export_identifier($tableName));
    $empty = stats_export_overview($restore, $eventTable, $now, $eventTable);
    export_assert($empty['total_rows'] === 0 && $empty['first_event'] === null && $empty['details']['rows'] === [], 'empty database produces a valid zero-data overview');
    $emptyStream = stats_export_create($restore, $eventTable, 'json', $now);
    $emptyBackup = json_decode(stream_get_contents($emptyStream), true, 512, JSON_THROW_ON_ERROR); fclose($emptyStream);
    export_assert(count($emptyBackup['tables']) === 15 && array_sum(array_column($emptyBackup['tables'], 'row_count')) === 0, 'empty JSON preserves all table schemas');
} finally { $restore = null; $pdo->exec('DROP DATABASE ' . stats_export_identifier($restoreDatabase)); }

// A second connection writes between source reads; the export keeps one snapshot.
class ExportObservedPDO extends PDO
{
    public $beforeQuery = null;
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if ($this->beforeQuery !== null) ($this->beforeQuery)($query);
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}
$observed = new ExportObservedPDO($dsn, $user, $password, $options);
$late = hash('sha256', 'late-visitor');
$observed->beforeQuery = static function (string $query) use ($insert, $eventTable, $late, $observed, $recent): void {
    if (!str_starts_with($query, 'SELECT ') || !str_contains($query, 'FROM `stat4_visitors`')) return;
    $observed->beforeQuery = null;
    $insert($eventTable, ['event_id' => 'export-late', 'created_at' => $recent]);
    $insert('stat4_visitors', ['visitor_hash' => $late, 'first_seen' => $recent, 'last_seen' => $recent, 'first_ip_hash' => $late]);
};
$stream = stats_export_create_csv($observed, $eventTable, $now);
[, $snapshotRows] = $parse($stream); fclose($stream);
export_assert(count($snapshotRows) === 63 && !in_array($late, array_column($snapshotRows, 'visitor_hash'), true), 'concurrent arrivals cannot create a mixed export snapshot');
$pdo->exec("DELETE FROM `$eventTable` WHERE event_id='export-late'");
$pdo->prepare('DELETE FROM stat4_visitors WHERE visitor_hash=?')->execute([$late]);
$observed->beforeQuery = static function (string $query): void { if (str_contains($query, 'FROM `stat4_events`')) throw new RuntimeException('simulated export read failure'); };
try { stats_export_create_csv($observed, $eventTable, $now); export_assert(false, 'mid-export read failure must throw'); }
catch (RuntimeException $error) { export_assert($error->getMessage() === 'simulated export read failure', 'mid-export failure is reported'); }
export_assert(!$observed->inTransaction() && $observed->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY), 'failure rolls back the read transaction and restores buffering');

// A buffered SELECT would exceed the child process memory limit on this fixture.
$digits = '(SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9)';
$pdo->exec("INSERT INTO `$eventTable` (event_id,payload_json,created_at) SELECT CONCAT('bulk-',a.n,b.n,c.n),REPEAT('x',35000),'$recent' FROM $digits a CROSS JOIN $digits b CROSS JOIN $digits c");
$process = proc_open([PHP_BINARY, '-d', 'memory_limit=16M', __FILE__, '--large-export'], [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
fclose($pipes[0]); export_assert(proc_close($process) === 0, 'large export passes with bounded memory');
$pdo->exec("DELETE FROM `$eventTable` WHERE event_id LIKE 'bulk-%'");
export_assert($fingerprint() === $before, 'all export checks preserve the original fixture rows');
echo "PASS export MySQL ($checks assertions; complete sources, custom table, CSV round trip, concurrent snapshot, bounded memory, unchanged data)\n";

$root = dirname(__DIR__);
$fixture = sys_get_temp_dir() . '/stats3-export-http-' . bin2hex(random_bytes(6));
mkdir($fixture, 0700);
foreach (['stats.php', 'stats_export.php', 'export.php', 'export_view.php', 'export.css', 'export.js', 'export_model.js', 'pixl_server.php', 'pixl_captcha.php', 'pixl_geoip.php', 'stats-mobile.css'] as $file) copy($root . '/' . $file, $fixture . '/' . $file);
$db = ['database' => $database, 'user' => $user, 'password' => $password, 'charset' => 'utf8mb4'];
foreach (explode(';', substr($dsn, 6)) as $part) { [$key, $value] = array_pad(explode('=', $part, 2), 2, ''); if (in_array($key, ['host', 'port'], true)) $db[$key] = $value; if ($key === 'unix_socket') $db['socket'] = $value; }
$config = ['db' => $db, 'table' => $eventTable, 'stats_password' => 'export-fixture-password', 'hash_salt' => str_repeat('export-fixture-', 3), 'geoip' => ['enabled' => false]];
$saveConfig = static function () use ($fixture, &$config): void { file_put_contents($fixture . '/pixl_config.php', '<?php return ' . var_export($config, true) . ';'); };
$saveConfig();
file_put_contents($fixture . '/router.php', <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (in_array($path, ['/pixl_stats.php','/stat/checkthis.php','/stat/dashboardx2.html'], true)) { echo '<!doctype html><title>Unrelated report placeholder</title>'; return; }
return false;
PHP);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr); $address = stream_socket_get_name($socket, false); fclose($socket);
$server = proc_open([PHP_BINARY, '-d', 'opcache.enable=0', '-S', $address, $fixture . '/router.php'], [0 => ['pipe', 'r'], 1 => ['file', $fixture . '/server.log', 'a'], 2 => ['file', $fixture . '/server.log', 'a']], $pipes, $fixture);
export_assert(is_resource($server), 'HTTP fixture starts'); fclose($pipes[0]);
function export_http(string $url, string $cookie = '', string $method = 'GET', string $content = ''): array
{
    $context = stream_context_create(['http' => ['method' => $method, 'content' => $content, 'header' => "Cookie: $cookie\r\nContent-Type: application/x-www-form-urlencoded\r\n", 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 15]]);
    $body = file_get_contents($url, false, $context); preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $match);
    return ['status' => (int)($match[1] ?? 0), 'body' => $body, 'headers' => implode("\n", $http_response_header)];
}
try {
    for ($i = 0; $i < 80; $i++) { $connection = @stream_socket_client('tcp://' . $address, $errno, $errstr, .1); if ($connection) { fclose($connection); break; } usleep(25000); }
    $base = 'http://' . $address;
    $cookie = 'pixl_stats_login=' . hash_hmac('sha256', 'pixl-stats|' . $config['stats_password'], $config['hash_salt']);
    $anonymous = export_http($base . '/export.php?download=json');
    export_assert(!str_contains($anonymous['headers'], 'attachment') && str_contains($anonymous['body'], 'stats_password') && !str_contains($anonymous['body'], 'export-current-bot'), 'anonymous request gets login without data');
    $login = export_http($base . '/export.php?download=json', '', 'POST', 'stats_password=export-fixture-password');
    export_assert($login['status'] === 302 && str_contains($login['headers'], 'Location: export.php'), 'login returns to the export overview');
    $beforeHttp = $fingerprint();
    $overview = export_http($base . '/export.php?at=' . $now, $cookie);
    export_assert($overview['status'] === 200 && str_contains($overview['body'], 'JSON-Backup herunterladen') && str_contains($overview['body'], 'Aktueller Stand'), 'overview presents both downloads and snapshot scope');
    $download = export_http($base . '/export.php?download=csv&at=' . $now . '&range=60min&days=1&exclude_germans=1', $cookie);
    export_assert($download['status'] === 200 && str_contains($download['headers'], 'filename="export.csv"') && str_contains($download['headers'], 'text/csv; charset=UTF-8'), 'authenticated CSV download');
    export_assert(str_contains($download['headers'], 'no-store') && str_contains($download['headers'], 'nosniff') && str_contains($download['headers'], 'Content-Length: ' . strlen($download['body'])), 'download has private headers and exact byte length');
    export_assert($download['body'] === $csv, 'dashboard filters cannot alter the 90-day CSV');
    $jsonHttp = export_http($base . '/export.php?download=json&at=' . $now . '&days=36500', $cookie);
    $decoded = json_decode($jsonHttp['body'], true, 512, JSON_THROW_ON_ERROR);
    export_assert($jsonHttp['status'] === 200 && str_contains($jsonHttp['headers'], 'application/json') && str_contains($jsonHttp['headers'], 'stats3-backup-') && $decoded['meta']['summary']['total_rows'] === 63, 'JSON backup includes all 90-day data');
    $legacy = export_http($base . '/stats.php?export=csv', $cookie);
    export_assert($legacy['status'] === 302 && str_contains($legacy['headers'], 'Location: export.php?download=csv'), 'old link redirects to the new export endpoint');
    $detailHttp = export_http($base . '/export.php?table=' . $eventTable . '&page=3&at=' . $now, $cookie);
    export_assert($detailHttp['status'] === 200 && str_contains($detailHttp['body'], 'data-initial-page="3"') && str_contains($detailHttp['body'], 'page-44'), 'selected initial page still receives all embedded data');
    $firstDetail = export_http($base . '/export.php?table=' . $eventTable . '&at=' . $now, $cookie);
    export_assert(str_contains($firstDetail['body'], '\\u003Cimg src=x') && !str_contains($firstDetail['body'], '<img src=x'), 'embedded visitor HTML cannot execute');
    $htmlHttp = export_http($base . '/export.php?download=html&at=' . $now, $cookie);
    export_assert($htmlHttp['status'] === 200 && str_contains($htmlHttp['headers'], 'text/html') && str_contains($htmlHttp['headers'], '.html"') && str_contains($htmlHttp['headers'], 'Content-Length: ' . strlen($htmlHttp['body'])), 'authenticated HTML download has exact content length and filename');
    export_assert(export_http($base . '/export_view.php')['status'] === 404, 'template cannot be opened directly');
    foreach (['download=sql', 'download[]=json', 'table=unrelated_private', 'table[]=x', 'page[]=1', 'page=-1', 'at=1', 'at=' . ($now+600)] as $bad) {
        export_assert(export_http($base . '/export.php?' . $bad, $cookie)['status'] === 400, 'invalid request rejected: ' . $bad);
    }
    export_assert($fingerprint() === $beforeHttp, 'overview and downloads do not modify statistics tables');
    export_assert(export_http($base . '/export.php?download=json', $cookie, 'HEAD')['status'] === 405, 'unsupported method cannot run the export');
    $pdo->exec("RENAME TABLE `$eventTable` TO fixture_events_saved");
    foreach (['', '?download=json', '?download=csv', '?download=html'] as $suffix) {
        $failed = export_http($base . '/export.php' . $suffix, $cookie);
        export_assert($failed['status'] === 503 && !str_contains($failed['headers'], 'attachment') && !str_contains($failed['body'], 'SQLSTATE'), 'failure produces no partial download or database details');
    }
    $pdo->exec("RENAME TABLE fixture_events_saved TO `$eventTable`");
    $config['stats_password'] = ''; $saveConfig();
    export_assert(export_http($base . '/export.php')['status'] === 403 && export_http($base . '/export.php?download=json')['status'] === 403, 'export and overview require a configured password');
    $config['stats_password'] = 'export-fixture-password'; $saveConfig();
    if (getenv('PIXL_EXPORT_TEST_BROWSER') === '1') {
        $process = proc_open(['node', $root . '/tests/stats_export_browser_test.js'], [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $browserPipes, $root,
            array_merge(getenv(), ['EXPORT_TEST_BASE_URL' => $base, 'EXPORT_TEST_COOKIE' => $cookie, 'EXPORT_TEST_NOW' => (string)$now]));
        fclose($browserPipes[0]); export_assert(proc_close($process) === 0, 'browser button download succeeds');
    }
    echo "PASS export HTTP ($checks assertions; authentication, full unfiltered download, private headers, failure handling)\n";
} finally {
    proc_terminate($server); proc_close($server);
    foreach (glob($fixture . '/*') ?: [] as $file) if (is_file($file)) unlink($file);
    rmdir($fixture);
}
