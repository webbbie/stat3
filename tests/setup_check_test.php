<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/stat4/db.php';

$checks = 0;
function setup_assert(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException($message);
}
function setup_throws(callable $action, string $message): void
{
    try { $action(); } catch (RuntimeException $error) { setup_assert(true, $message); return; }
    setup_assert(false, $message);
}

$schemaPath = dirname(__DIR__) . '/pixl_schema.sql';
$definitions = pixl_unified_schema_definitions($schemaPath, 'pixl_events');
setup_assert(count($definitions) === 18, 'complete schema includes every module and CAPTCHA page views');
$custom = pixl_unified_schema_definitions($schemaPath, 'custom_events');
setup_assert(isset($custom['custom_events'], $custom['custom_events_push_subscriptions'], $custom['custom_events_push_meta'])
    && !isset($custom['pixl_events']), 'custom Stats3 table prefix is preserved');
setup_throws(static fn() => pixl_unified_schema_definitions($schemaPath, 'invalid`table'), 'invalid table name rejected');
$temporary = tempnam(sys_get_temp_dir(), 'stats3-setup-schema-');
try {
    foreach (['', file_get_contents($schemaPath) . "\nDROP TABLE pixl_events;\n", $definitions['pixl_events'] . ';'] as $invalid) {
        file_put_contents($temporary, $invalid);
        setup_throws(static fn() => pixl_unified_schema_definitions($temporary, 'pixl_events'), 'incomplete or non-create schema rejected before database access');
    }
} finally { unlink($temporary); }

$centralSalt = str_repeat('central-test-', 4);
$environmentSalt = str_repeat('environment-test-', 3);
setup_assert(stat4_hash_salt(['hash_salt' => $centralSalt], '') === $centralSalt, 'central salt replaces the old hard-coded default');
setup_assert(stat4_hash_salt(['hash_salt' => $centralSalt], $environmentSalt) === $environmentSalt, 'explicit STAT4 environment salt has priority');
setup_throws(static fn() => stat4_hash_salt([], ''), 'missing secret never uses a shared default');
setup_throws(static fn() => stat4_hash_salt(['hash_salt' => 'CHANGE-ME-TO-A-LONG-RANDOM-SECRET'], ''), 'example secret rejected');
setup_throws(static fn() => stat4_hash_salt(['hash_salt' => $centralSalt], 'please-change-stat4-salt'), 'explicit old default is not accepted as an individual secret');
$previousSalt = getenv('STAT4_HASH_SALT');
try {
    putenv('STAT4_HASH_SALT=' . $environmentSalt);
    setup_assert(stat4_hash('test-visitor') === hash('sha256', 'test-visitor|' . $environmentSalt), 'existing configured environment salt preserves visitor hashes');
} finally { putenv($previousSalt === false ? 'STAT4_HASH_SALT' : 'STAT4_HASH_SALT=' . $previousSalt); }
echo "PASS setup schema and salt checks ($checks assertions)\n";

$dsn = getenv('PIXL_SETUP_TEST_DSN') ?: '';
if ($dsn === '') {
    echo "SKIP MySQL scenarios: set PIXL_SETUP_TEST_DSN to an empty isolated pixl_setup_test_* database\n";
    exit;
}
$pdo = new PDO($dsn, getenv('PIXL_SETUP_TEST_USER') ?: 'root', getenv('PIXL_SETUP_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
setup_assert(str_starts_with($database, 'pixl_setup_test_'), 'an isolated test database is mandatory');
setup_assert($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) === [], 'test database must start empty');
setup_assert(pixl_table_name() === 'pixl_events', 'integration fixture uses the standard Stats3 table name');
$missing = ['stat4_visitors', 'stat4_sessions', 'stat4_events', 'stat4_notifications', 'impressions',
    'ppcmate_attributions', 'ppcmate_conversions', 'legacy_sqlite_rows', 'storage_migrations'];
foreach ($definitions as $name => $sql) if (!in_array($name, $missing, true)) $pdo->exec($sql);
$pdo->exec("ALTER TABLE pixl_events ADD COLUMN setup_preserved_marker VARCHAR(20) NOT NULL DEFAULT 'keep'");
$pdo->exec("INSERT INTO pixl_events (event_id) VALUES ('setup-existing-event')");
$pdo->exec("INSERT INTO pixl_captcha_state (id, revision, phase_id, successes) VALUES (1, 'existing-revision', 'existing-phase', 3)");
$eventBefore = $pdo->query('SELECT * FROM pixl_events')->fetchAll(PDO::FETCH_ASSOC);
$captchaBefore = $pdo->query('SELECT * FROM pixl_captcha_state')->fetchAll(PDO::FETCH_ASSOC);
$structureBefore = $pdo->query('SHOW CREATE TABLE pixl_events')->fetch(PDO::FETCH_NUM)[1];
$result = pixl_ensure_unified_schema($pdo);
setup_assert(count($result['created']) === 9 && !array_diff($missing, $result['created']), 'all nine reported missing tables are created');
setup_assert(count($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)) === 18, 'all central tables are present after repair');
setup_assert($eventBefore === $pdo->query('SELECT * FROM pixl_events')->fetchAll(PDO::FETCH_ASSOC), 'existing events stay identical');
setup_assert($captchaBefore === $pdo->query('SELECT * FROM pixl_captcha_state')->fetchAll(PDO::FETCH_ASSOC), 'active CAPTCHA phase stays identical');
setup_assert($structureBefore === $pdo->query('SHOW CREATE TABLE pixl_events')->fetch(PDO::FETCH_NUM)[1], 'existing table structure stays identical');
setup_assert(pixl_ensure_unified_schema($pdo)['created'] === [], 'second repair is a no-op');
setup_assert((int)$pdo->query('SELECT COUNT(*) FROM storage_migrations')->fetchColumn() === 0, 'schema repair cannot claim a legacy data import');
echo "PASS setup MySQL repair ($checks assertions; exact missing-table fixture, preserved data, idempotency)\n";
