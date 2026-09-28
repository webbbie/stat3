<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/tools/migrate_storage_to_mysql.php';

function storage_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function storage_test_throws(callable $action, string $message): void
{
    try {
        $action();
    } catch (RuntimeException) {
        return;
    }
    throw new RuntimeException($message);
}

function storage_test_cli(string $script, array $arguments): array
{
    $command = array_merge([PHP_BINARY, $script], $arguments);
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    storage_test_assert(is_resource($process), 'CLI test process could not start');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), (string) $stdout, (string) $stderr];
}

$directory = sys_get_temp_dir() . '/stats3-migration-test-' . bin2hex(random_bytes(8));
storage_test_assert(mkdir($directory, 0700), 'Could not create test directory');
$path = $directory . '/config.local.php';
$missing = $directory . '/missing.php';
$sourceConfig = ['db' => ['host' => '', 'database' => 'fixture'], 'admin' => ['cookie_name' => 'fixture-admin']];
$cliDirectory = $directory . '/tools';
$cliScript = $cliDirectory . '/migrate_storage_to_mysql.php';
$cliConfig = $directory . '/pixl_server.php';

try {
    storage_test_assert(migration_legacy_stat4_config($missing) === null, 'Optional missing source should be absent');
    storage_test_throws(
        static fn() => migration_legacy_stat4_config($missing, true),
        'Explicit missing source must fail'
    );

    foreach (['<?php return null;', '<?php return [];', '<?php return ["db" => "invalid"];'] as $invalid) {
        file_put_contents($path, $invalid);
        storage_test_throws(
            static fn() => migration_legacy_stat4_config($path, true),
            'Explicit invalid source must fail'
        );
    }
    echo "PASS explicit source validation\n";

    foreach ([0600, 0640, 0644] as $mode) {
        file_put_contents($path, '<?php return ' . var_export($sourceConfig, true) . ';');
        chmod($path, $mode);
        clearstatcache(true, $path);
        $before = stat($path);
        migration_remove_legacy_stat4_db_config($path);
        clearstatcache(true, $path);
        $after = stat($path);
        $saved = require $path;
        storage_test_assert($saved === ['admin' => $sourceConfig['admin']], 'Only legacy db block should be removed');
        foreach (['uid', 'gid', 'mode'] as $attribute) {
            storage_test_assert($before[$attribute] === $after[$attribute], 'Config file access changed: ' . $attribute);
        }
        $hash = hash_file('sha256', $path);
        migration_remove_legacy_stat4_db_config($path);
        storage_test_assert(hash_file('sha256', $path) === $hash, 'Config cleanup must be repeatable');
    }
    echo "PASS config cleanup preserves owner, group, mode and other settings\n";

    $replacement = '<?php return ["admin" => ["cookie_name" => "changed-concurrently"]];';
    file_put_contents($path, '<?php file_put_contents(__FILE__, ' . var_export($replacement, true)
        . '); return ' . var_export($sourceConfig, true) . ';');
    storage_test_throws(
        static fn() => migration_remove_legacy_stat4_db_config($path),
        'Concurrent config change must abort cleanup'
    );
    storage_test_assert(file_get_contents($path) === $replacement, 'Concurrent config change was overwritten');
    storage_test_assert(count(scandir($directory) ?: []) === 3, 'Temporary config leaked');
    echo "PASS concurrent config change leaves updated original intact\n";

    file_put_contents($path, '<?php return ' . var_export($sourceConfig, true) . ';');
    // Exercise the real CLI with fixture configuration and no production data.
    storage_test_assert(mkdir($cliDirectory, 0700), 'Could not create CLI fixture');
    storage_test_assert(copy(dirname(__DIR__) . '/tools/migrate_storage_to_mysql.php', $cliScript), 'Could not copy CLI');
    file_put_contents($cliConfig, <<<'PHP'
<?php
function pixl_config(): array { return ['db' => ['database' => 'fixture']]; }
function pixl_pdo(): PDO { throw new RuntimeException('Unexpected target database access'); }
PHP);
    foreach ([
        [['--commit', '--skip-archive', '--legacy-stat4-config=' . $missing], 'Quellkonfiguration fehlt'],
        [['--commit', '--skip-archive', '--confirm-stat4-cutover', '--legacy-stat4-config=' . $path], 'unvollständig'],
        [['--skip-stat4', '--legacy-stat4-config=' . $path], 'nicht kombiniert'],
    ] as [$arguments, $expected]) {
        [$status, $stdout, $stderr] = storage_test_cli($cliScript, $arguments);
        storage_test_assert($status !== 0 && str_contains($stderr, $expected), 'Expected CLI preflight failure missing');
        storage_test_assert(!str_contains($stdout, 'Schema bereit') && !str_contains($stdout, 'FERTIG'), 'Failed source reached import');
    }
    echo "PASS CLI source errors stop before target schema/import\n";

    foreach (['203.0.113.8', '2001:db8::8', 'fe80::8%en0'] as $address) {
        $result = migration_scrub_ip_literals('address=' . $address);
        storage_test_assert(!str_contains($result, $address), 'IP literal not scrubbed');
    }
    echo "PASS IPv4 and IPv6 literal sanitization\n";

    echo "All storage migration regression tests passed (no MySQL writes).\n";
} finally {
    if (is_file($path)) {
        unlink($path);
    }
    // Only files created inside this uniquely allocated fixture directory.
    foreach (glob($directory . '/config.local.php.*.tmp') ?: [] as $temporary) {
        unlink($temporary);
    }
    foreach ([$cliScript, $cliConfig] as $fixture) {
        if (is_file($fixture)) {
            unlink($fixture);
        }
    }
    if (is_dir($cliDirectory)) {
        rmdir($cliDirectory);
    }
    rmdir($directory);
}
