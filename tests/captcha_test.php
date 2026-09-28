<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/pixl_captcha.php';

$checks = 0;
function captcha_assert(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException($message);
}

$defaults = pixl_captcha_settings([]);
captcha_assert($defaults === ['enabled' => false, 'visitor_interval' => 100, 'success_target' => 10, 'revision' => '', 'page_view_interval' => 0, 'landing_urls' => [], 'max_duration_hours' => 4], 'defaults');
$bounded = pixl_captcha_settings(['captcha' => ['visitor_interval' => -1, 'success_target' => PHP_INT_MAX, 'page_view_interval' => PHP_INT_MAX]]);
captcha_assert($bounded['visitor_interval'] === 1 && $bounded['success_target'] === 10000 && $bounded['page_view_interval'] === 1000000, 'bounds');
captcha_assert(pixl_captcha_settings(['captcha' => ['page_view_interval' => -1]])['page_view_interval'] === 0, 'page threshold lower bound');
$legacyDefaults = ['enabled' => false, 'visitor_interval' => 100, 'success_target' => 10, 'revision' => ''];
captcha_assert(pixl_captcha_revision($defaults) === hash('sha256', json_encode($legacyDefaults)), 'new default settings preserve the legacy revision hash');
captcha_assert(pixl_captcha_settings(['captcha' => ['max_duration_hours' => 0]])['max_duration_hours'] === 1, 'duration lower bound');
captcha_assert(pixl_captcha_settings(['captcha' => ['max_duration_hours' => PHP_INT_MAX]])['max_duration_hours'] === 8760, 'duration upper bound');
captcha_assert(pixl_captcha_revision(array_replace($defaults, ['max_duration_hours' => 2])) !== pixl_captcha_revision($defaults), 'changing the duration changes the configuration revision');
foreach (['https://EXAMPLE.com:443/gate?a=1#p' => 'https://example.com/gate', 'http://example.com:80' => 'http://example.com/', '/gate?x=1#p' => '/gate', 'https://example.com:8443/Gate/' => 'https://example.com:8443/Gate/'] as $url => $expected) {
    captcha_assert(pixl_captcha_normalize_url($url) === $expected, 'URL normalization: ' . $url);
}
foreach (['javascript:alert(1)', 'ftp://example.com/gate', '//example.com/gate', 'https://user:pass@example.com/gate', 'https://example.com/a b', "https://example.com/a\nb", 'https://example.com/a\\b', [], null] as $url) {
    captcha_assert(pixl_captcha_normalize_url($url) === '', 'invalid landing URL rejected');
}
$landingSettings = pixl_captcha_settings(['captcha' => ['landing_urls' => ['https://EXAMPLE.com:443/gate?tracking=1', '/shared', 'https://example.com/gate']]]);
captcha_assert($landingSettings['landing_urls'] === ['https://example.com/gate', '/shared'], 'landing list is normalized and deduplicated');
foreach (['https://example.com/gate?utm_source=test#x', 'https://other.example/shared'] as $url) captcha_assert(pixl_captcha_landing_allowed($landingSettings, $url), 'listed landing matches');
foreach (['https://example.com/gate/child', 'https://example.com/Gate', 'https://example.com/gate/', 'https://other.example/gate', 'http://example.com/gate', 'https://example.com:8443/gate', '/shared', ''] as $url) captcha_assert(!pixl_captcha_landing_allowed($landingSettings, $url), 'unlisted landing does not match');
captcha_assert(pixl_captcha_landing_allowed($defaults, 'https://example.com/any'), 'empty list keeps every allowed page');
captcha_assert(!pixl_captcha_landing_allowed(pixl_captcha_settings(['captcha' => ['landing_urls' => ['invalid']]]), 'https://example.com/any'), 'invalid manual rule cannot allow every page');
captcha_assert(!pixl_captcha_request_allowed('https://example.com', 'https://other.example.com/page'), 'cross-origin URL must fail');
captcha_assert(!pixl_captcha_request_allowed('null', 'https://example.com'), 'opaque origin must fail');
captcha_assert(!pixl_captcha_request_allowed('https://example.com', 'http://example.com'), 'scheme mismatch must fail');
captcha_assert(!pixl_captcha_request_allowed('http://localhost:1234', 'http://localhost:5678/page'), 'port mismatch must fail');

foreach (['', 'page-source_123', str_repeat('a', 128)] as $source) captcha_assert(pixl_captcha_attempt_source_valid($source), 'valid or legacy attempt source');
foreach ([null, [], 1, "source\n", 'page source', str_repeat('a', 129)] as $source) captcha_assert(!pixl_captcha_attempt_source_valid($source), 'invalid attempt source rejected');

$dsn = getenv('PIXL_CAPTCHA_TEST_DSN') ?: '';
if ($dsn === '') {
    echo "PASS Captcha settings/origin checks ($checks assertions)\n";
    echo "SKIP MySQL scenarios: set PIXL_CAPTCHA_TEST_DSN to an isolated pixl_captcha_test_* database\n";
    exit;
}
$pdo = new PDO($dsn, getenv('PIXL_CAPTCHA_TEST_USER') ?: 'root', getenv('PIXL_CAPTCHA_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
// Never clear real Stats3 tables. An explicit disposable test database is mandatory.
captcha_assert(str_starts_with((string)$pdo->query('SELECT DATABASE()')->fetchColumn(), 'pixl_captcha_test_'), 'test database name must start with pixl_captcha_test_');
pixl_captcha_ensure_schema($pdo);

if (($argv[1] ?? '') === '--worker') {
    $settings = json_decode($argv[5], true, 512, JSON_THROW_ON_ERROR);
    echo json_encode(pixl_captcha_process($pdo, $settings, hash('sha256', $argv[3]), $argv[2], $argv[4], null, (int)($argv[6] ?? 0), $argv[7] ?? '', $argv[8] ?? ''), JSON_THROW_ON_ERROR);
    exit;
}

$reset = static function () use ($pdo): void {
    $pdo->exec('DELETE FROM pixl_captcha_page_views');
    $pdo->exec('DELETE FROM pixl_captcha_visitors');
    $pdo->exec('DELETE FROM pixl_captcha_stats');
    $pdo->exec('INSERT INTO pixl_captcha_stats (id) VALUES (1)');
    $pdo->exec('UPDATE pixl_captcha_state SET revision="",waiting_visitors=0,waiting_page_views=0,phase_id="",phase_started_at=0,successes=0,last_cleanup=0 WHERE id=1');
};
$state = static fn(): array => $pdo->query('SELECT * FROM pixl_captcha_state WHERE id=1')->fetch();
$settings = pixl_captcha_settings(['captcha' => ['enabled' => true, 'visitor_interval' => 2, 'success_target' => 2]]);
$now = time();
$run = static function (string $visitor, string $action = 'check', string $token = '', int $failedAttempts = 0, string $source = '', string $url = '') use ($pdo, &$settings, &$now): array {
    return pixl_captcha_process($pdo, $settings, hash('sha256', $visitor), $action, $token, $now, $failedAttempts, $source, $url);
};
$summary = static function () use ($pdo, &$settings, &$now): array {
    return pixl_captcha_summary($pdo, $settings, $now);
};
$reset();
captcha_assert($summary()['remaining_visitors'] === 3 && $summary()['estimated_seconds'] === null, 'initial countdown includes visitor N+1 and no invented estimate');
captcha_assert($summary()['last_successes'] === null && $summary()['last_failures'] === null, 'missing history is unknown, not zero');
captcha_assert($summary()['blocked_visitors'] === 0 && $summary()['last_blocked_visitors'] === null, 'no current blocks and unknown previous blocked visitors before the first phase');
captcha_assert(!$run('a')['required'] && (int)$state()['waiting_visitors'] === 1, 'first visitor passes');
captcha_assert($summary()['remaining_visitors'] === 2 && $summary()['estimated_seconds'] === null, 'one observation cannot produce an estimate');
captcha_assert(!$run('a')['required'] && (int)$state()['waiting_visitors'] === 1, 'reload/page change does not count');
$now += 60;
captcha_assert(!$run('b')['required'] && (int)$state()['waiting_visitors'] === 2, 'Nth visitor still passes');
$report = pixl_captcha_summary($pdo, $settings, $now);
captcha_assert($report['remaining_visitors'] === 1 && $report['estimated_seconds'] === 60, 'Nth visitor leaves one arrival at the observed one-minute rate');
captcha_assert(pixl_captcha_summary($pdo, $settings, $now + 60)['estimated_seconds'] === 120, 'estimate includes quiet time');
captcha_assert(!$run('a')['required'] && $state()['phase_id'] === '', 'returning visitor does not start phase');
$c = $run('c');
captcha_assert($c['required'] && preg_match('/^[a-f0-9]{64}$/', $c['token']) === 1, 'N+1 starts phase');
captcha_assert($c['active'] === true, 'selected visitor sees global active phase');
$knownVisitor = $run('a');
captcha_assert($knownVisitor['active'] === true && !$knownVisitor['required'], 'known visitor can see an active phase without a challenge');
captcha_assert($summary()['active'] && $summary()['remaining_visitors'] === 0 && $summary()['estimated_seconds'] === 0, 'active phase has no remaining wait');
captcha_assert($run('c')['token'] === $c['token'], 'reload reuses ticket');
captcha_assert($summary()['blocked_visitors'] === 1, 'first challenge counts once despite reloads and exempt returning visitors');
$d = $run('d');
captcha_assert($d['required'], 'second new visitor receives a challenge');
$additionalVisitor = $run('e');
captcha_assert($additionalVisitor['required'] && $additionalVisitor['active'] === true, 'new visitor beyond the success target also receives a challenge');
captcha_assert($run('e')['token'] === $additionalVisitor['token'], 'additional visitor keeps the same challenge on reload');
captcha_assert(!$run('b')['required'], 'visitor known before this phase remains exempt');
captcha_assert($summary()['blocked_visitors'] === 3, 'all three challenged visitors count, including visitors without a failed attempt');
captcha_assert($run('c', 'fail', $c['token'], 1)['required'] && $summary()['failures'] === 1, 'puzzle miss counts and keeps gate open');
$run('c', 'fail', $c['token'], 1);
captcha_assert($summary()['failures'] === 1, 'repeated failure report is idempotent');
$run('c', 'fail', $c['token'], 3);
$run('c', 'fail', $c['token'], 2);
captcha_assert($summary()['failures'] === 3 && $run('c')['failed_attempts'] === 3, 'out-of-order reports and reloads preserve failure count');
captcha_assert(!$run('d', 'fail', $c['token'], 9)['ok'] && $summary()['failures'] === 3, 'foreign token cannot add failures');
captcha_assert($summary()['blocked_visitors'] === 3, 'multiple puzzle misses, retries and invalid reports do not count additional visitors');
captcha_assert($run('c', 'verify', $c['token'])['verified'], 'first successful move');
$run('c', 'fail', $c['token'], 10);
captcha_assert($summary()['failures'] === 3, 'late failure after success cannot change counters');
captcha_assert((int)$state()['successes'] === 1, 'first move counted');
captcha_assert($run('c', 'verify', $c['token'])['verified'] && (int)$state()['successes'] === 1, 'duplicate acknowledgement is idempotent');
captcha_assert($summary()['blocked_visitors'] === 2, 'successful visitor is removed exactly once, including duplicate acknowledgements');
captcha_assert(!$run('c')['required'], 'one successful move per visitor per phase');
captcha_assert(!$run('d', 'verify', $c['token'])['ok'] && (int)$state()['successes'] === 1, 'ticket is bound to visitor');
captcha_assert($run('d', 'status', $d['token'])['required'], 'pending ticket stays active');
$completed = $run('d', 'verify', $d['token'], 2);
captcha_assert($completed['verified'] && $completed['active'] === false, 'last required move reports the newly completed phase as inactive');
captcha_assert($state()['phase_id'] === '' && (int)$state()['waiting_visitors'] === 0, 'phase ends and interval resets');
captcha_assert($summary()['last_successes'] === 2 && $summary()['last_failures'] === 5, 'completed phase keeps exact successes and failures');
captcha_assert($summary()['blocked_visitors'] === 0 && $summary()['last_blocked_visitors'] === 1, 'phase completion stores its one unresolved visitor and clears the current count');
captcha_assert($summary()['successes'] === 0 && $summary()['failures'] === 0 && $summary()['remaining_visitors'] === 3, 'new waiting cycle resets current counters only');
$released = $run('e', 'status', $additionalVisitor['token']);
captcha_assert(!$released['required'] && !$released['active'] && !$released['verified'], 'remaining challenge is released after the phase ends');
captcha_assert(!$run('e', 'verify', $additionalVisitor['token'])['verified'] && $summary()['last_successes'] === 2, 'late extra verification cannot add a success to the completed phase');
captcha_assert($summary()['last_blocked_visitors'] === 1, 'releasing a visitor or late verification cannot rewrite completed blocked history');
captcha_assert(!$run('a')['required'] && (int)$state()['waiting_visitors'] === 0, 'old visitor does not consume new interval');
captcha_assert(!$run('f')['required'] && !$run('g')['required'], 'next two new visitors pass');
$h = $run('h');
captcha_assert($h['required'], 'next phase starts');
captcha_assert($summary()['last_successes'] === 2 && $summary()['last_failures'] === 5, 'previous results survive the next phase');
captcha_assert($summary()['blocked_visitors'] === 1 && $summary()['last_blocked_visitors'] === 1, 'new phase has its own blocked count and preserves the previous result');
captcha_assert($run('c', 'verify', $c['token'])['verified'] && (int)$state()['successes'] === 0, 'old success cannot count in new phase');
$now += 601;
$run('h', 'fail', $h['token'], 7);
captcha_assert($summary()['failures'] === 0, 'expired ticket cannot report failed puzzle attempts');
captcha_assert(!$run('h', 'verify', $h['token'])['verified'], 'expired move does not count');
captcha_assert($summary()['blocked_visitors'] === 1, 'an expired challenge remains counted without confirmed success');
$i = $run('i');
captcha_assert($i['required'], 'new visitor receives a challenge after another visitor ticket expires');
$renewedH = $run('h');
captcha_assert($renewedH['required'] && $renewedH['token'] !== $h['token'], 'selected visitor renews an expired ticket while the phase is active');
captcha_assert($summary()['blocked_visitors'] === 2, 'renewing an expired ticket does not count the same visitor again');
$disabledReport = pixl_captcha_summary($pdo, array_replace($settings, ['enabled' => false]), $now);
captcha_assert($disabledReport['blocked_visitors'] === 0 && $disabledReport['last_blocked_visitors'] === 1, 'disabled gate has no current blocks and preserves completed history');
$before = $state();
captcha_assert(!pixl_captcha_process($pdo, $defaults, hash('sha256', 'off'), 'check')['required'] && $state() === $before, 'disabled mode leaves counters alone');
$settings['revision'] = 'changed-by-configurator';
$report = pixl_captcha_summary($pdo, $settings, $now);
captcha_assert(!$report['active'] && $report['remaining_visitors'] === 3 && $report['estimated_seconds'] === null, 'pending settings reset is reflected without changing gate state');
captcha_assert($report['last_failures'] === 5 && $report['successes'] === 0, 'settings reset keeps last completed results');
captcha_assert($report['blocked_visitors'] === 0 && $report['last_blocked_visitors'] === 1, 'pending settings reset hides old blocks without rewriting completed history');
captcha_assert(!$run('a')['required'] && (int)$state()['waiting_visitors'] === 1, 'settings change starts fresh cycle');
captcha_assert(!$run('i', 'status', $i['token'])['required'], 'settings change releases old gate');
captcha_assert($summary()['blocked_visitors'] === 0 && $summary()['last_blocked_visitors'] === 1, 'applied settings reset clears only the current blocked count');
$now += 86401;
captcha_assert(!$run('a')['required'] && (int)$state()['waiting_visitors'] === 2, 'visitor counts again after 24 hours inactivity');
$off = pixl_captcha_summary($pdo, $defaults, $now);
captcha_assert(!$off['enabled'] && $off['remaining_visitors'] === null && $off['estimated_seconds'] === null, 'disabled captcha has no countdown or estimate');

// Each tab/reload reports its own cumulative attempts, while legacy clients
// continue to use a separate high-water mark. Verification recovers lost fails.
$reset();
$settings['visitor_interval'] = 1;
$settings['success_target'] = 2;
$now = time();
$run('source-pass');
$sourceTicket = $run('source-visitor')['token'];
$run('source-visitor', 'fail', $sourceTicket, 1, 'tab-a');
captcha_assert($summary()['failures'] === 1, 'first source starts at one');
$run('source-visitor', 'fail', $sourceTicket, 1, 'tab-b');
captcha_assert($summary()['failures'] === 2, 'a parallel tab adds its own first failure');
$run('source-visitor', 'fail', $sourceTicket, 2, 'tab-b');
captcha_assert($summary()['failures'] === 3, 'parallel tab reports only its cumulative delta');
$run('source-visitor', 'fail', $sourceTicket, 1, 'tab-a');
$run('source-visitor', 'fail', $sourceTicket, 1, 'reloaded-page');
$run('source-visitor', 'fail', $sourceTicket, 3, 'tab-a');
$run('source-visitor', 'fail', $sourceTicket, 2, 'tab-a');
captcha_assert($summary()['failures'] === 6 && $run('source-visitor')['failed_attempts'] === 6, 'reloading and out-of-order reports preserve the sum of source maxima');
$run('source-visitor', 'verify', $sourceTicket, 2, 'reloaded-page');
captcha_assert($summary()['failures'] === 7 && (int)$state()['successes'] === 1, 'verification restores a lost report from its page source');
$run('source-visitor', 'verify', $sourceTicket, 3, 'reloaded-page');
captcha_assert($summary()['failures'] === 7 && (int)$state()['successes'] === 1, 'late retries cannot rewrite a verified ticket');
$sourceFinal = $run('source-final')['token'];
$run('source-final', 'verify', $sourceFinal, 1, 'final-page');
captcha_assert($summary()['last_failures'] === 8 && $summary()['last_successes'] === 2, 'phase history stores failures from every tab and final acknowledgements');
$reset();

// Upgrading a ticket with already measured legacy failures keeps that baseline.
$run('source-upgrade-pass');
$sourceLegacy = $run('source-upgrade')['token'];
$run('source-upgrade', 'fail', $sourceLegacy, 5);
$pdo->exec('ALTER TABLE pixl_captcha_visitors DROP COLUMN failed_attempt_sources');
pixl_captcha_ensure_schema($pdo);
pixl_captcha_ensure_schema($pdo);
captcha_assert($run('source-upgrade')['token'] === $sourceLegacy && $summary()['failures'] === 5, 'adding the source map preserves ticket and measured failures');
$run('source-upgrade', 'fail', $sourceLegacy, 1, 'new-client');
$run('source-upgrade', 'fail', $sourceLegacy, 6);
$run('source-upgrade', 'fail', $sourceLegacy, 6);
captcha_assert($summary()['failures'] === 7 && $run('source-upgrade')['failed_attempts'] === 7, 'legacy high-water mark and new page sources coexist without duplicate counting');
$now += 601;
$sourceRenewed = $run('source-upgrade')['token'];
$run('source-upgrade', 'fail', $sourceRenewed, 1, 'new-client');
captcha_assert($sourceRenewed !== $sourceLegacy && $run('source-upgrade')['failed_attempts'] === 1 && $summary()['failures'] === 8, 'ticket renewal clears source marks and retains already measured phase failures');
$reset();

// A ticket has a bounded source map; overflow is explicit and never accepts a
// verification while silently discarding its failures. Existing sources work.
$now = time();
$run('bounded-pass');
$boundedTicket = $run('bounded-visitor')['token'];
$sourceMap = [];
for ($sourceIndex = 0; $sourceIndex < 256; $sourceIndex++) $sourceMap['page:source-' . $sourceIndex] = 1;
$pdo->prepare('UPDATE pixl_captcha_visitors SET failed_attempts=256,failed_attempt_sources=? WHERE visitor_hash=?')
    ->execute([json_encode($sourceMap, JSON_THROW_ON_ERROR), hash('sha256', 'bounded-visitor')]);
$pdo->exec('UPDATE pixl_captcha_stats SET failures=256 WHERE id=1');
$overflow = $run('bounded-visitor', 'verify', $boundedTicket, 1, 'source-overflow');
captcha_assert(!$overflow['ok'] && $overflow['error'] === 'attempt_source_limit' && (int)$state()['successes'] === 0 && $summary()['failures'] === 256, 'source overflow neither truncates counts nor confirms verification');
$run('bounded-visitor', 'fail', $boundedTicket, 2, 'source-0');
captcha_assert($summary()['failures'] === 257, 'existing source remains usable at the source-map limit');
$invalidSource = $run('bounded-visitor', 'fail', $boundedTicket, 3, 'invalid source');
captcha_assert(!$invalidSource['ok'] && $summary()['failures'] === 257, 'invalid source cannot change failure state');
$reset();

// Actual MySQL row-lock races, using independent PHP connections/processes.
$reset();
$now = time(); // Workers use real time, not the previous inactivity scenario's clock.
$settings['visitor_interval'] = 3;
$settings['success_target'] = 2;
$parallel = static function (array $jobs) use (&$settings): array {
    $processes = [];
    foreach ($jobs as $job) {
        [$action, $visitor, $token] = $job;
        $attempts = $job[3] ?? 0;
        $source = $job[4] ?? '';
        $url = $job[5] ?? '';
        $process = proc_open([PHP_BINARY, __FILE__, '--worker', $action, $visitor, $token, json_encode($settings, JSON_THROW_ON_ERROR), (string)$attempts, $source, $url], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        captcha_assert(is_resource($process), 'start concurrent worker');
        fclose($pipes[0]);
        $processes[] = [$process, $pipes];
    }
    $results = [];
    foreach ($processes as [$process, $pipes]) {
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        captcha_assert(proc_close($process) === 0, 'concurrent worker failed: ' . $err);
        $results[] = json_decode($out, true, 512, JSON_THROW_ON_ERROR);
    }
    return $results;
};
$jobs = [];
for ($index = 0; $index < 12; $index++) $jobs[] = ['check', 'parallel-' . $index, ''];
$results = $parallel($jobs);
captcha_assert(count(array_filter($results, static fn(array $r): bool => $r['required'])) === 9, 'all nine new visitors after the three-person interval receive a gate concurrently');
captcha_assert((int)$state()['waiting_visitors'] === 3, 'exact waiting interval under concurrency');
captcha_assert(pixl_captcha_summary($pdo, $settings)['blocked_visitors'] === 9, 'concurrent arrivals count each challenged visitor exactly once');
foreach ($results as $index => $result) {
    $again = $run('parallel-' . $index);
    captcha_assert($again['required'] === $result['required'], 'reload preserves selection or known-visitor exemption');
    if ($result['required']) captcha_assert($again['token'] === $result['token'], 'parallel visitor retains the same ticket');
}
captcha_assert($summary()['blocked_visitors'] === 9, 'reloading all parallel visitors does not add blocks');
$parallelVisitorIndex = array_key_first(array_filter($results, static fn(array $result): bool => $result['required']));
$parallelVisitor = 'parallel-' . $parallelVisitorIndex;
$parallelToken = $results[$parallelVisitorIndex]['token'];
$parallel([
    ['fail', $parallelVisitor, $parallelToken, 1, 'concurrent-a'],
    ['fail', $parallelVisitor, $parallelToken, 1, 'concurrent-b'],
    ['fail', $parallelVisitor, $parallelToken, 2, 'concurrent-b'],
    ['fail', $parallelVisitor, $parallelToken, 1, 'concurrent-a'],
    ['fail', $parallelVisitor, $parallelToken, 4, 'concurrent-c'],
    ['fail', $parallelVisitor, $parallelToken, 2, 'concurrent-c'],
]);
captcha_assert($summary()['failures'] === 7 && $run($parallelVisitor)['failed_attempts'] === 7, 'parallel source reports serialize into exactly the sum of maxima');
$verifyJobs = [];
foreach ($results as $index => $result) {
    if (!$result['required']) continue;
    $verifyJobs[] = ['verify', 'parallel-' . $index, $result['token']];
    $verifyJobs[] = ['verify', 'parallel-' . $index, $result['token']];
}
$parallel($verifyJobs);
captcha_assert($state()['phase_id'] === '' && (int)$state()['waiting_visitors'] === 0, 'parallel duplicate successes end one phase exactly once');
captcha_assert((int)$pdo->query("SELECT COUNT(*) FROM pixl_captcha_visitors WHERE verified_phase<>''")->fetchColumn() === 2, 'only two successful visitors');
captcha_assert(pixl_captcha_summary($pdo, $settings)['last_successes'] === 2, 'concurrent completion is saved only once');
captcha_assert($summary()['last_failures'] === 7, 'concurrent phase completion preserves per-source failure totals');
captcha_assert(pixl_captcha_summary($pdo, $settings)['blocked_visitors'] === 0 && pixl_captcha_summary($pdo, $settings)['last_blocked_visitors'] === 7, 'concurrent duplicate successes save exactly seven unresolved visitors');
$reset();

// Visitor cleanup must not erase unsolved visits from a long-running phase.
$settings['visitor_interval'] = 1;
$settings['max_duration_hours'] = 48;
$now = time();
$run('cleanup-pass');
$run('cleanup-abandoned-a');
$run('cleanup-abandoned-b');
$now += 86401;
$cleanupNew = $run('cleanup-new');
captcha_assert((int)$pdo->query('SELECT COUNT(*) FROM pixl_captcha_visitors')->fetchColumn() === 1, 'inactive visitor rows were cleaned up');
captcha_assert($summary()['blocked_visitors'] === 3, 'cleanup preserves both abandoned visits and counts the new challenge');
$run('cleanup-new', 'verify', $cleanupNew['token']);
$cleanupFinal = $run('cleanup-final');
$run('cleanup-final', 'verify', $cleanupFinal['token']);
captcha_assert($summary()['last_blocked_visitors'] === 2, 'completed history preserves abandoned visitors whose rows have expired');
$settings['max_duration_hours'] = 4;
$reset();

// Upgrade the previous statistics schema during an active phase.
$run('upgrade-pass');
$upgradeTicket = $run('upgrade-challenge');
$upgradeState = $state();
$pdo->exec('ALTER TABLE pixl_captcha_stats DROP COLUMN blocked_visitors, DROP COLUMN last_blocked_visitors');
pixl_captcha_ensure_schema($pdo);
pixl_captcha_ensure_schema($pdo);
captcha_assert($state() === $upgradeState && $run('upgrade-challenge')['token'] === $upgradeTicket['token'], 'adding blocked counters twice preserves the active phase and visitor ticket');
captcha_assert($summary()['blocked_visitors'] === null && $summary()['last_blocked_visitors'] === null, 'an already active phase is unknown after upgrade, not an invented zero');
$run('upgrade-challenge', 'verify', $upgradeTicket['token']);
$upgradeFinal = $run('upgrade-final');
$run('upgrade-final', 'verify', $upgradeFinal['token']);
captcha_assert($summary()['blocked_visitors'] === 0 && $summary()['last_blocked_visitors'] === null, 'a partly measured phase cannot become a fabricated historical count');
$run('upgrade-next-pass');
$upgradeNext = $run('upgrade-next');
captcha_assert($summary()['blocked_visitors'] === 1, 'first full phase after upgrade starts exact visitor counts');
$run('upgrade-next', 'verify', $upgradeNext['token']);
$upgradeNextFinal = $run('upgrade-next-final');
$run('upgrade-next-final', 'verify', $upgradeNextFinal['token']);
captcha_assert($summary()['last_blocked_visitors'] === 0, 'fully successful phase has a measured zero, distinct from missing history');
$reset();

// Upgrade an installation with a partly successful phase and an open ticket.
$settings['visitor_interval'] = 1;
$run('legacy-a');
$legacyB = $run('legacy-b');
$run('legacy-b', 'verify', $legacyB['token']);
$legacyC = $run('legacy-c');
$legacyState = $state();
$pdo->exec('ALTER TABLE pixl_captcha_visitors DROP COLUMN failed_attempts');
$pdo->exec('DROP TABLE pixl_captcha_stats');
pixl_captcha_ensure_schema($pdo);
pixl_captcha_ensure_schema($pdo);
captcha_assert($state() === $legacyState && $run('legacy-c')['token'] === $legacyC['token'], 'schema upgrade preserves partial successes and open tickets and can run twice');
captcha_assert($summary()['blocked_visitors'] === null, 'recreated statistics cannot reconstruct an older active phase');
captcha_assert($run('legacy-c', 'verify', $legacyC['token'], 1)['verified'], 'existing ticket remains verifiable after upgrade');
captcha_assert($summary()['last_successes'] === 2 && $summary()['last_failures'] === 1, 'upgraded phase keeps old successes and new measured failures');
captcha_assert($summary()['last_blocked_visitors'] === null, 'legacy phase completion leaves its unmeasured blocked history unknown');
$reset();
// The page threshold is global, deduplicated per visitor, and combined with N.
$reset();
$settings = pixl_captcha_settings(['captcha' => ['enabled' => true, 'visitor_interval' => 2, 'page_view_interval' => 5, 'success_target' => 1, 'landing_urls' => ['https://example.com/gate', '/shared']]]);
$now = time();
$pageCheck = static fn(string $visitor, string $url): array => $run($visitor, 'check', '', 0, '', $url);
captcha_assert($summary()['remaining_page_views'] === 5 && $summary()['waiting_page_views'] === 0, 'new page countdown starts at the configured threshold');
captcha_assert(!$pageCheck('page-a', 'https://example.com/gate')['required'], 'first visitor passes on a landing page');
$pageCheck('page-a', 'https://EXAMPLE.com:443/gate?utm_source=x#part');
captcha_assert((int)$state()['waiting_page_views'] === 1 && (int)$state()['waiting_visitors'] === 1, 'reload and query/fragment variants count one visitor/page');
$pageCheck('page-a', 'https://example.com/article');
captcha_assert((int)$state()['waiting_page_views'] === 2 && (int)$state()['waiting_visitors'] === 1, 'another page from the same visitor adds only a page');
$now += 60;
$pageCheck('page-b', 'https://example.com/gate');
captcha_assert((int)$state()['waiting_page_views'] === 3 && $state()['phase_id'] === '', 'another visitor on the same page adds one combination');
captcha_assert($summary()['remaining_page_views'] === 2 && $summary()['estimated_seconds'] === null, 'visitor rate cannot predict the unfinished page threshold');
captcha_assert(!$pageCheck('page-c', 'https://example.com/gate')['required'] && $state()['phase_id'] === '', 'N+1 cannot start a phase when only the visitor threshold is met');
$pageCheck('page-a', 'https://example.com/another');
captcha_assert((int)$state()['waiting_page_views'] === 5 && $state()['phase_id'] === '', 'known visitor can finish the page threshold without losing its exemption');
captcha_assert($summary()['remaining_page_views'] === 0 && $summary()['estimated_seconds'] !== null, 'visitor estimate is available after pages are sufficient');
$deferred = $pageCheck('page-d', 'https://example.com/not-a-landing');
captcha_assert($deferred['active'] && !$deferred['required'] && !isset($deferred['token']), 'both thresholds activate phase but an unlisted page cannot show a gate');
captcha_assert($summary()['blocked_visitors'] === 0, 'an off-landing arrival is not counted as blocked');
$duringPhasePages = (int)$state()['waiting_page_views'];
$deferredTicket = $pageCheck('page-d', 'https://example.com/gate?campaign=1');
captcha_assert($deferredTicket['required'] && $summary()['blocked_visitors'] === 1, 'deferred new visitor receives a ticket on the later landing page');
captcha_assert(!$pageCheck('page-a', 'https://example.com/gate')['required'], 'visitor known before this phase remains exempt on a landing page');
captcha_assert(!$pageCheck('page-d', 'https://example.com/other')['required'], 'existing ticket cannot force a gate on other pages');
foreach (['status', 'fail', 'verify'] as $action) {
    $offLanding = $run('page-d', $action, $deferredTicket['token'], 1, 'off-landing', 'https://example.com/other');
    captcha_assert(!$offLanding['required'] && !$offLanding['verified'] && $offLanding['active'], 'off-landing ' . $action . ' neither displays nor acknowledges a challenge');
}
captcha_assert($summary()['failures'] === 0 && $summary()['successes'] === 0 && $summary()['blocked_visitors'] === 1, 'off-landing actions preserve ticket counters');
$otherLanding = $pageCheck('page-e', 'https://other.example/shared');
captcha_assert($otherLanding['required'] && $summary()['blocked_visitors'] === 2, 'second landing rule permits new visitors beyond the success target');
captcha_assert($pageCheck('page-d', 'https://example.com/gate')['token'] === $deferredTicket['token'], 'returning to the landing preserves the issued ticket');
captcha_assert((int)$state()['waiting_page_views'] === $duringPhasePages, 'active-phase pages do not count toward the next waiting cycle');
$done = $run('page-d', 'verify', $deferredTicket['token'], 0, '', 'https://example.com/gate');
captcha_assert($done['verified'] && !$done['active'], 'landing success finishes the phase');
captcha_assert((int)$state()['waiting_visitors'] === 0 && (int)$state()['waiting_page_views'] === 0 && (int)$pdo->query('SELECT COUNT(*) FROM pixl_captcha_page_views')->fetchColumn() === 0, 'completion resets both counters and page identities');
captcha_assert($summary()['last_blocked_visitors'] === 1, 'deferred handling preserves exact unresolved visitor history');
$pageCheck('page-a', 'https://example.com/gate');
captcha_assert((int)$state()['waiting_page_views'] === 1 && (int)$state()['waiting_visitors'] === 0, 'known visitors contribute fresh page combinations in the next cycle');
$now += 86401;
$pageCheck('page-a', 'https://example.com/gate');
captcha_assert((int)$state()['waiting_page_views'] === 2 && (int)$state()['waiting_visitors'] === 1, 'same page counts again only after visitor identity expires');
$settings['page_view_interval'] = 7;
captcha_assert($summary()['waiting_page_views'] === 0 && $summary()['remaining_page_views'] === 7, 'pending page-threshold change is reflected in the dashboard');
$pageCheck('page-a', 'https://example.com/gate');
captcha_assert((int)$state()['waiting_page_views'] === 1 && (int)$state()['waiting_visitors'] === 1, 'changed page threshold starts a clean cycle');
$settings['landing_urls'] = ['/new-landing'];
$pageCheck('page-a', 'https://example.com/article');
captcha_assert((int)$state()['waiting_page_views'] === 1 && (int)$state()['waiting_visitors'] === 1, 'changed landing list starts a clean cycle');

// Pages being sufficient never bypass the original N passing visitors.
$reset();
$now = time();
$settings = pixl_captcha_settings(['captcha' => ['enabled' => true, 'visitor_interval' => 2, 'page_view_interval' => 1, 'success_target' => 1]]);
$pageCheck('first-a', 'https://example.com/a');
$pageCheck('first-a', 'https://example.com/b');
captcha_assert($state()['phase_id'] === '' && (int)$state()['waiting_page_views'] === 2, 'page threshold alone cannot start a phase');
captcha_assert(!$pageCheck('first-b', 'https://example.com/a')['required'], 'Nth visitor remains exempt with sufficient pages');
captcha_assert($pageCheck('first-c', 'https://example.com/a')['required'], 'N+1 starts the phase once both thresholds are sufficient');

// The incoming page can satisfy the page threshold on the eligible new arrival.
$reset();
$settings['visitor_interval'] = 1;
$settings['page_view_interval'] = 2;
$pageCheck('boundary-a', 'https://example.com/a');
captcha_assert($pageCheck('boundary-b', 'https://example.com/a')['required'] && (int)$state()['waiting_page_views'] === 2, 'exact page threshold includes the eligible visitor current page');

// Real simultaneous requests cannot double count a visitor/page or start early.
$reset();
$settings['visitor_interval'] = 2;
$settings['page_view_interval'] = 5;
$samePageJobs = array_fill(0, 8, ['check', 'same-page', '', 0, '', 'https://example.com/a']);
$samePageResults = $parallel($samePageJobs);
captcha_assert(count(array_filter($samePageResults, static fn(array $r): bool => $r['required'])) === 0 && (int)$state()['waiting_page_views'] === 1 && (int)$state()['waiting_visitors'] === 1, 'parallel tabs count exactly one page and one visitor');
$reset();
$pageJobs = [];
for ($index = 0; $index < 10; $index++) $pageJobs[] = ['check', 'page-parallel-' . $index, '', 0, '', 'https://example.com/gate'];
$pageResults = $parallel($pageJobs);
captcha_assert(count(array_filter($pageResults, static fn(array $r): bool => $r['required'])) === 6, 'under concurrency exactly four visitors pass until page five starts the phase');
captcha_assert((int)$state()['waiting_visitors'] === 4 && (int)$state()['waiting_page_views'] === 5 && $summary()['blocked_visitors'] === 6, 'concurrent counters match the combined thresholds');

// Adding the page schema twice preserves an already running legacy phase.
$reset();
$settings = pixl_captcha_settings(['captcha' => ['enabled' => true, 'visitor_interval' => 1, 'success_target' => 1]]);
$run('upgrade-page-a');
$upgradePageTicket = $run('upgrade-page-b');
$pageUpgradeBefore = $state();
unset($pageUpgradeBefore['waiting_page_views']);
$pdo->exec('ALTER TABLE pixl_captcha_state DROP COLUMN waiting_page_views');
$pdo->exec('DROP TABLE pixl_captcha_page_views');
pixl_captcha_ensure_schema($pdo);
pixl_captcha_ensure_schema($pdo);
$pageUpgradeAfter = $state();
unset($pageUpgradeAfter['waiting_page_views']);
captcha_assert($pageUpgradeAfter === $pageUpgradeBefore && $run('upgrade-page-b')['token'] === $upgradePageTicket['token'], 'page schema upgrade is idempotent and keeps legacy phase and ticket');
captcha_assert($run('upgrade-page-b', 'verify', $upgradePageTicket['token'])['verified'], 'legacy ticket remains verifiable after page schema upgrade');

// The time limit belongs to the active phase, never the waiting period or a ticket.
$reset();
$settings = pixl_captcha_settings(['captcha' => ['enabled' => true, 'visitor_interval' => 2, 'page_view_interval' => 3, 'success_target' => 2]]);
$now = time();
$pageCheck('hours-a', 'https://example.com/landing');
$now += 5 * 3600;
$pageCheck('hours-b', 'https://example.com/landing');
captcha_assert((int)$state()['waiting_visitors'] === 2 && (int)$state()['phase_started_at'] === 0, 'five hours waiting do not reset the counters or start the phase clock');
$hoursTicket = $pageCheck('hours-c', 'https://example.com/landing');
$phaseStart = $now;
$deadline = $phaseStart + 4 * 3600;
captcha_assert($hoursTicket['required'] && (int)$state()['phase_started_at'] === $phaseStart && pixl_captcha_phase_deadline($state(), $settings) === $deadline, 'the first active phase receives the default four-hour deadline');
$run('hours-c', 'fail', $hoursTicket['token'], 2);
$hoursOther = $pageCheck('hours-d', 'https://example.com/landing');
$run('hours-d', 'verify', $hoursOther['token']);
$now = $deadline - 1;
$hoursRenewed = $pageCheck('hours-c', 'https://example.com/landing');
captcha_assert($hoursRenewed['required'] && $hoursRenewed['token'] !== $hoursTicket['token'] && (int)$state()['phase_started_at'] === $phaseStart, 'ticket renewal and reload one second before expiry do not extend the phase');
$run('hours-c', 'fail', $hoursRenewed['token'], 1);
$beforeExpiry = $state();
$now = $deadline;
$expiredReport = $summary();
captcha_assert(!$expiredReport['active'] && $expiredReport['remaining_visitors'] === 3 && $expiredReport['remaining_page_views'] === 3 && $expiredReport['waiting_page_views'] === 0, 'dashboard projects both waiting counters from zero exactly at the deadline');
captcha_assert($expiredReport['successes'] === 0 && $expiredReport['failures'] === 0 && $expiredReport['blocked_visitors'] === 0, 'expired phase has no current counters');
captcha_assert($expiredReport['last_successes'] === 1 && $expiredReport['last_failures'] === 3 && $expiredReport['last_blocked_visitors'] === 1 && $expiredReport['last_completed_at'] === $deadline, 'timeout preserves partial successes, failures and unresolved visitors as the ended phase');
captcha_assert($state() === $beforeExpiry, 'dashboard expiry projection is read-only');
$expiredStatus = $run('hours-c', 'status', $hoursRenewed['token']);
captcha_assert($expiredStatus['ok'] && !$expiredStatus['required'] && !$expiredStatus['active'], 'existing status poll releases the visible challenge at the phase deadline');
captcha_assert($state()['phase_id'] === '' && (int)$state()['phase_started_at'] === 0 && (int)$state()['waiting_visitors'] === 0 && (int)$state()['waiting_page_views'] === 0 && (int)$pdo->query('SELECT COUNT(*) FROM pixl_captcha_page_views')->fetchColumn() === 0, 'timeout clears both waiting counters, page identities and the active timestamp');
captcha_assert($summary() === $expiredReport, 'stored timeout result equals the previous read-only projection');
$run('hours-c', 'verify', $hoursRenewed['token'], 8);
$run('hours-c', 'fail', $hoursRenewed['token'], 9);
captcha_assert($summary()['last_failures'] === 3 && (int)$state()['successes'] === 0, 'late acknowledgements and failures cannot change ended phase results');
$now += 30;
$pageCheck('hours-c', 'https://example.com/landing');
captcha_assert((int)$state()['waiting_visitors'] === 0 && (int)$state()['waiting_page_views'] === 1, 'known visitors keep their exemption but contribute new page combinations after the reset');
$pageCheck('hours-e', 'https://example.com/landing');
$pageCheck('hours-f', 'https://example.com/landing');
$nextHoursTicket = $pageCheck('hours-g', 'https://example.com/landing');
captcha_assert($nextHoursTicket['required'] && (int)$state()['phase_started_at'] === $now, 'the next phase starts only after both thresholds with its own new deadline');
$nextHoursState = $state();
$run('hours-c', 'verify', $hoursRenewed['token'], 10);
captcha_assert($state() === $nextHoursState && $summary()['last_failures'] === 3, 'old phase tickets cannot alter the next active phase or its deadline');

// Every gate action must apply expiry before accepting attempts or successes.
foreach (['status', 'verify', 'fail', 'check'] as $expiryAction) {
    $reset();
    $settings = pixl_captcha_settings(['captcha' => ['enabled' => true, 'visitor_interval' => 1, 'success_target' => 2, 'max_duration_hours' => 1]]);
    $now = time();
    $run('boundary-pass');
    $run('boundary-ticket');
    $started = $now;
    $now += 3599;
    $renewed = $run('boundary-ticket');
    $run('boundary-ticket', 'fail', $renewed['token'], 3);
    $now++;
    $atLimit = $run('boundary-ticket', $expiryAction, $renewed['token'], 4);
    captcha_assert($atLimit['ok'] && !$atLimit['required'] && !$atLimit['verified'] && !$atLimit['active'], $expiryAction . ' releases an unexpired ticket when the one-hour phase limit is reached');
    captcha_assert($summary()['last_failures'] === 3 && $summary()['last_successes'] === 0 && $summary()['last_blocked_visitors'] === 1 && $summary()['last_completed_at'] === $started + 3600, $expiryAction . ' cannot add a last-second failure or success after the deadline');
}

// Normal completion and settings changes must not leave a timer for a future phase.
$reset();
$settings['success_target'] = 1;
$now = time();
$run('finish-pass');
$finishedTicket = $run('finish-ticket');
$oldDeadline = $now + 3600;
$now += 10;
$run('finish-ticket', 'verify', $finishedTicket['token']);
captcha_assert((int)$state()['phase_started_at'] === 0 && $state()['phase_id'] === '', 'success target clears the phase timer before the time limit');
$now = $oldDeadline;
$run('finish-next-pass');
$finishNext = $run('finish-next-ticket');
$now++;
captcha_assert($run('finish-next-ticket', 'status', $finishNext['token'])['required'] && (int)$state()['phase_started_at'] === $oldDeadline, 'the previous phase deadline cannot end a newly started phase');
$settings['max_duration_hours'] = 2;
captcha_assert(!$summary()['active'] && $summary()['last_successes'] === 1, 'pending duration change shows a fresh cycle and preserves history');
$run('finish-next-pass');
captcha_assert($state()['phase_id'] === '' && (int)$state()['phase_started_at'] === 0 && (int)$state()['waiting_visitors'] === 1, 'changed duration starts a clean waiting cycle');
captcha_assert(!$run('finish-next-ticket', 'status', $finishNext['token'])['required'], 'changed duration releases the previous ticket');

// Upgrade adds the timestamp without guessing the age of a running legacy phase.
$reset();
$now = time();
$run('clock-upgrade-pass');
$legacyClock = $run('clock-upgrade-ticket');
$clockBefore = $state();
unset($clockBefore['phase_started_at']);
$pdo->exec('ALTER TABLE pixl_captcha_state DROP COLUMN phase_started_at');
pixl_captcha_ensure_schema($pdo);
pixl_captcha_ensure_schema($pdo);
$clockAfter = $state();
unset($clockAfter['phase_started_at']);
captcha_assert($clockAfter === $clockBefore && (int)$state()['phase_started_at'] === 0, 'adding the timer twice preserves all existing counters and the running phase');
$now += 60;
captcha_assert($run('clock-upgrade-ticket', 'status', $legacyClock['token'])['required'] && (int)$state()['phase_started_at'] === $now, 'legacy ticket stays valid and receives one initial phase timestamp');
$clockStart = $now;
$now += 60;
$run('clock-upgrade-ticket');
captcha_assert((int)$state()['phase_started_at'] === $clockStart, 'later requests cannot extend the initialized legacy timer');
$now = $clockStart + 2 * 3600;
captcha_assert(!$run('clock-upgrade-ticket', 'status', $legacyClock['token'])['required'] && $state()['phase_id'] === '', 'upgraded phase obeys the configured two-hour limit');

// Concurrent expiry, old-ticket reports and new visitors perform one reset only.
$reset();
$settings = pixl_captcha_settings(['captcha' => ['enabled' => true, 'visitor_interval' => 3, 'success_target' => 2]]);
$now = time();
foreach (['race-pass-a', 'race-pass-b', 'race-pass-c'] as $name) $run($name);
$raceTicket = $run('race-old-ticket');
$run('race-old-ticket', 'fail', $raceTicket['token'], 2);
$oldPhase = $state()['phase_id'];
$pdo->prepare('UPDATE pixl_captcha_state SET phase_started_at=? WHERE id=1')->execute([$now - 4 * 3600]);
$expiryJobs = [['status', 'race-old-ticket', $raceTicket['token']], ['verify', 'race-old-ticket', $raceTicket['token'], 8]];
foreach (['race-new-a', 'race-new-b', 'race-new-c', 'race-new-d'] as $name) {
    $expiryJobs[] = ['check', $name, '', 0, '', 'https://example.com/landing'];
    $expiryJobs[] = ['check', $name, '', 0, '', 'https://example.com/landing'];
}
$parallel($expiryJobs);
$now = time();
captcha_assert((int)$state()['waiting_visitors'] === 3 && (int)$state()['waiting_page_views'] === 4 && $state()['phase_id'] !== '' && $state()['phase_id'] !== $oldPhase, 'concurrent expiry restarts the interval once and deduplicates new visitors and pages');
captcha_assert($summary()['last_successes'] === 0 && $summary()['last_failures'] === 2 && $summary()['last_blocked_visitors'] === 1 && $summary()['successes'] === 0 && $summary()['blocked_visitors'] === 1, 'concurrent expiry preserves history once and rejects old verification in the new phase');
captcha_assert((int)$state()['phase_started_at'] >= $now - 30, 'the concurrent next phase gets a fresh deadline');

echo "PASS Captcha MySQL checks ($checks assertions, including hour limits, schema upgrades, combined thresholds, landing URLs, concurrent visitors and duplicate successes; isolated test database)\n";
