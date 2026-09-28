<?php
declare(strict_types=1);

// Exercise the real shared dispatcher with isolated config/lock files and
// local callbacks. No live configuration, database or Pushover API is used.
$human = ['reason' => 'LEAVE', 'is_bot' => 0, 'reading_score' => 1];
$readingMode = ['reading_score_only' => true, 'throttle_seconds' => 86400, 'max_messages_per_hour' => 1];
$cases = [
    ['name' => 'default mode and hourly default', 'config' => [], 'interval' => 360, 'events' => [null, null], 'expected' => [true, false]],
    ['name' => 'global limit wins', 'config' => ['throttle_seconds' => 900, 'max_messages_per_hour' => 10], 'interval' => 900, 'age' => 500, 'events' => [$human], 'expected' => [false]],
    ['name' => 'hourly limit wins', 'config' => ['throttle_seconds' => 150, 'max_messages_per_hour' => 5], 'interval' => 720, 'age' => 300, 'events' => [$human], 'expected' => [false]],
    ['name' => 'hourly interval rounds up', 'config' => ['throttle_seconds' => 0, 'max_messages_per_hour' => 7], 'interval' => 515, 'age' => 505, 'events' => [$human], 'expected' => [false]],
    ['name' => 'expired shared interval', 'config' => ['throttle_seconds' => 90, 'max_messages_per_hour' => 10], 'interval' => 360, 'age' => 400, 'events' => [$human, null], 'expected' => [true, false]],
    ['name' => 'hourly disabled', 'config' => ['throttle_seconds' => 90, 'max_messages_per_hour' => 0], 'interval' => 90, 'age' => 100, 'events' => [null, $human], 'expected' => [true, false]],
    ['name' => 'both limits disabled', 'config' => ['throttle_seconds' => 0, 'max_messages_per_hour' => 0], 'interval' => 0, 'events' => [null, $human], 'expected' => [true, true]],
    ['name' => 'off does not filter zero scores', 'config' => ['reading_score_only' => false, 'throttle_seconds' => 0, 'max_messages_per_hour' => 0], 'interval' => 0, 'events' => [array_replace($human, ['reading_score' => 0])], 'expected' => [true]],
    ['name' => 'sender failure preserves clock', 'config' => ['throttle_seconds' => 90, 'max_messages_per_hour' => 0], 'interval' => 90, 'age' => 200, 'throw' => true, 'events' => [$human], 'expected' => ['exception']],
    ['name' => 'reading mode bypasses both limits repeatedly', 'config' => $readingMode, 'interval' => 86400, 'age' => 0, 'events' => [$human, $human, array_replace($human, ['reading_score' => '100', 'is_bot' => '0'])], 'expected' => [true, true, true]],
    ['name' => 'reading mode needs no lock file', 'config' => $readingMode, 'interval' => 86400, 'events' => [$human], 'expected' => [true]],
    ['name' => 'reading mode rejects missing STAT4 score', 'config' => $readingMode, 'interval' => 86400, 'events' => [null], 'expected' => [false]],
    ['name' => 'reading mode rejects bots', 'config' => $readingMode, 'interval' => 86400, 'events' => [array_replace($human, ['is_bot' => 1, 'reading_score' => 100])], 'expected' => [false]],
    ['name' => 'reading mode requires human classification', 'config' => $readingMode, 'interval' => 86400, 'events' => [['reason' => 'LEAVE', 'reading_score' => 100]], 'expected' => [false]],
    ['name' => 'reading mode requires LEAVE', 'config' => $readingMode, 'interval' => 86400, 'events' => [array_replace($human, ['reason' => 'VISIT']), array_replace($human, ['reason' => 'READ'])], 'expected' => [false, false]],
    ['name' => 'custom final label preserves terminal eligibility', 'config' => $readingMode, 'interval' => 86400, 'events' => [array_replace($human, ['reason' => 'FINAL', 'payload_json' => '{"events":{"reached":{"LEAVE":true}}}']), array_replace($human, ['reason' => 'FINAL', 'payload_json' => '{"events":{"reached":{"LEAVE":"false"}}}'])], 'expected' => [true, false]],
];
foreach ([0, -1, 0.99, null, '', 'invalid', false, [], INF, NAN] as $index => $score) {
    $cases[] = ['name' => 'reading mode rejects invalid/low score ' . $index, 'config' => $readingMode, 'interval' => 86400, 'events' => [array_replace($human, ['reading_score' => $score])], 'expected' => [false]];
}

function pushover_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

if (($argv[1] ?? '') === '--worker') {
    $case = $cases[(int)$argv[3]];
    require $argv[2] . '/pixl_pushover.php';
    $lock = pixl_pushover_throttle_file();
    try {
        pushover_test_assert(pixl_pushover_throttle_seconds() === $case['interval'], $case['name'] . ': wrong interval');
        $before = null;
        if (isset($case['age'])) {
            $before = (string)(time() - $case['age']);
            file_put_contents($lock, $before);
        }
        $calls = 0;
        foreach ($case['events'] as $index => $event) {
            $previousCalls = $calls;
            try {
                $actual = pixl_pushover_run_throttled(static function () use (&$calls, $case): void {
                    $calls++;
                    if (!empty($case['throw'])) {
                        throw new RuntimeException('fixture sender failure');
                    }
                }, $event);
            } catch (RuntimeException $error) {
                if ($error->getMessage() !== 'fixture sender failure') {
                    throw $error;
                }
                $actual = 'exception';
            }
            $expected = $case['expected'][$index];
            pushover_test_assert($actual === $expected, $case['name'] . ': wrong dispatch result');
            pushover_test_assert($calls - $previousCalls === ($expected === false ? 0 : 1), $case['name'] . ': wrong callback count');
        }
        $after = is_file($lock) ? file_get_contents($lock) : null;
        if (!empty($case['config']['reading_score_only']) || !empty($case['throw']) || $case['interval'] === 0) {
            pushover_test_assert($after === $before, $case['name'] . ': clock should be untouched');
        } elseif (in_array(true, $case['expected'], true)) {
            pushover_test_assert($after !== null && abs(time() - (int)$after) <= 5, $case['name'] . ': successful delivery must set clock');
        } else {
            pushover_test_assert($after === $before, $case['name'] . ': suppressed delivery changed clock');
        }

        // Check the real fetch query includes the persisted score and bot flag.
        if ((int)$argv[3] === 0) {
            $pdo = new PDO('sqlite::memory:');
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('CREATE TABLE pixl_events (id INTEGER PRIMARY KEY, created_at TEXT, title TEXT, message TEXT, reason TEXT, hostname TEXT, page_url TEXT, path TEXT, browser TEXT, os TEXT, country TEXT, language TEXT, visitor_hash TEXT, payload_json TEXT, reading_score INTEGER, is_bot INTEGER)');
            $pdo->exec("INSERT INTO pixl_events (id, reason, reading_score, is_bot) VALUES (1, 'LEAVE', 1, 0)");
            $row = pixl_pushover_fetch_event($pdo, 1);
            pushover_test_assert((int)$row['reading_score'] === 1 && (int)$row['is_bot'] === 0, 'fetch must retain score and bot status');
            pushover_test_assert(pixl_pushover_fetch_event($pdo, 999) === [], 'missing event must remain empty');
        }
    } finally {
        if (is_file($lock)) {
            unlink($lock);
        }
    }
    exit(0);
}

$fixture = sys_get_temp_dir() . '/stats3-pushover-test-' . bin2hex(random_bytes(8));
mkdir($fixture, 0700);
try {
    foreach (['pixl_pushover.php', 'pixl_server.php', 'pixl_geoip.php'] as $file) {
        copy(dirname(__DIR__) . '/' . $file, $fixture . '/' . $file);
    }
    foreach ($cases as $index => $case) {
        file_put_contents($fixture . '/pixl_config.php', '<?php return ' . var_export(['site_id' => 'test', 'pushover' => $case['config']], true) . ';');
        $process = proc_open([PHP_BINARY, __FILE__, '--worker', $fixture, (string)$index], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        pushover_test_assert(is_resource($process), 'could not launch isolated test');
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        pushover_test_assert(proc_close($process) === 0, $case['name'] . ': ' . $output);
    }
    echo 'PASS Pushover regression checks (' . count($cases) . " isolated scenarios; no live database or notifications)\n";
} finally {
    foreach (glob($fixture . '/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($fixture);
}
