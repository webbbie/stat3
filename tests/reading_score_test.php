<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Pure input/output checks: no config, database or notification transport calls.
require dirname(__DIR__) . '/pixl_server.php';

set_error_handler(static function (int $severity, string $message, string $file, int $line): void {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$checks = 0;
function reading_test_assert(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function reading_test_case(string $name, array $engagement, ?int $score, ?float $raw, ?string $label = null): array
{
    $result = pixl_notification_reading_score(['engagement' => $engagement]);
    reading_test_assert($result['score'] === $score, $name . ': unexpected score');
    reading_test_assert($result['raw'] === $raw, $name . ': unexpected raw score');
    reading_test_assert($result['method'] === 'activity-v1', $name . ': missing method version');
    if ($label !== null) {
        reading_test_assert($result['label'] === $label, $name . ': unexpected label');
    }
    return $result;
}

foreach ([
    [0, 0, 'No activity'],
    [10, 17, 'Low'],
    [25, 33, 'Moderate'],
    [50, 50, 'High'],
    [100, 67, 'High'],
    [150, 75, 'Very high'],
    [450, 90, 'Very high'],
    [50000, 100, 'Very high'],
] as [$raw, $score, $label]) {
    reading_test_case('normalization ' . $raw, ['readingScore' => $raw], $score, (float)$raw, $label);
}

reading_test_case('numeric string', ['readingScore' => '25'], 33, 25.0);
reading_test_case('negative legacy score', ['readingScore' => -20], 0, 0.0, 'No activity');
reading_test_case('huge legacy score', ['readingScore' => PHP_FLOAT_MAX], 100, 50000.0);
reading_test_case('missing score', [], null, null, 'no measurement');
reading_test_case('explicit null score', ['readingScore' => null], null, null);
reading_test_case('empty samples override legacy score', ['readingSamples' => [], 'readingScore' => 450], 0, 0.0);
reading_test_case('samples override legacy score', ['readingSamples' => [10, 15], 'readingScore' => 450], 33, 25.0);
reading_test_case('last fifty samples', ['readingSamples' => array_merge([1000], array_fill(0, 50, 1))], 50, 50.0);
reading_test_case('individual sample cap', ['readingSamples' => [PHP_FLOAT_MAX]], 95, 1000.0);
reading_test_case('total sample cap', ['readingSamples' => array_fill(0, 60, PHP_FLOAT_MAX)], 100, 50000.0);
reading_test_case('sample sanitization', [
    'readingSamples' => [-5, 1, 2.5, '3.5', [], 'invalid', INF, NAN, 2000],
], 95, 1007.0);
reading_test_case('invalid samples use legacy fallback', [
    'readingSamples' => [null, false, [], 'invalid', INF, NAN, '1e309'],
    'readingScore' => 50,
], 50, 50.0);
reading_test_case('invalid recent samples do not revive older samples', [
    'readingSamples' => array_merge([1000], array_fill(0, 50, 'invalid')),
    'readingScore' => 25,
], 33, 25.0);
reading_test_case('malformed sample container uses fallback', [
    'readingSamples' => 'invalid', 'readingScore' => 50,
], 50, 50.0);
reading_test_case('invalid-only samples remain unknown', ['readingSamples' => ['invalid', INF, NAN]], null, null);

foreach ([null, false, true, '', 'invalid', [], INF, -INF, NAN, '1e309'] as $index => $invalid) {
    reading_test_case('invalid legacy value ' . $index, ['readingScore' => $invalid], null, null);
}

$messagePayload = pixl_expand_message_payload([
    'message' => "SessionDuration: 60s\nReadingScore: 37 [1.0,2.0]\n",
]);
reading_test_case('legacy text expansion', $messagePayload['engagement'], 43, 37.0);
reading_test_case('structured legacy value beats message fallback', pixl_expand_message_payload([
    'message' => 'ReadingScore: 450', 'engagement' => ['readingScore' => 25],
])['engagement'], 33, 25.0);

foreach ([[0, 0], [0.5, 0], [1.2, 2], [15, 25], [30, 50], [45, 75], [60, 100], [PHP_FLOAT_MAX, 100]] as [$duration, $score]) {
    reading_test_case('duration ceiling ' . $duration, [
        'readingScore' => 50000, 'sessionDuration' => $duration,
    ], $score, 50000.0);
}
reading_test_case('elapsed time does not create activity', ['readingSamples' => [], 'sessionDuration' => 3600], 0, 0.0);
reading_test_case('zero duration does not create a measurement', ['sessionDuration' => 0], null, null);
reading_test_case('measured activity at zero duration', ['readingScore' => 50, 'sessionDuration' => 0], 0, 50.0, 'Low');
reading_test_case('numeric duration string', ['readingScore' => 50000, 'sessionDuration' => '15'], 25, 50000.0);
foreach ([null, -1, false, true, '', 'invalid', [], INF, -INF, NAN, '1e309'] as $index => $duration) {
    reading_test_case('unknown duration has no ceiling ' . $index, [
        'readingScore' => 450, 'sessionDuration' => $duration,
    ], 90, 450.0);
}

foreach ([
    [],
    ['readingScore' => 0],
    ['readingScore' => 50],
    ['readingScore' => 450, 'sessionDuration' => 15],
    ['readingSamples' => [], 'readingScore' => 450],
    ['readingSamples' => [15, 35], 'sessionDuration' => 45],
] as $index => $engagement) {
    $first = pixl_notification_reading_score(['engagement' => $engagement]);
    $saved = array_merge($engagement, [
        'readingScore' => $first['score'],
        'readingScoreRaw' => $first['raw'],
        'readingScoreMethod' => $first['method'],
    ]);
    reading_test_assert(pixl_notification_reading_score(['engagement' => $saved]) === $first, 'saved score changed: ' . $index);
    unset($saved['readingSamples']);
    reading_test_assert(pixl_notification_reading_score(['engagement' => $saved]) === $first, 'saved raw fallback changed: ' . $index);
}
reading_test_case('current raw zero beats normalized score', [
    'readingScoreMethod' => 'activity-v1', 'readingScoreRaw' => 0, 'readingScore' => 90,
], 0, 0.0);
reading_test_case('current invalid raw is not renormalized', [
    'readingScoreMethod' => 'activity-v1', 'readingScoreRaw' => 'invalid', 'readingScore' => 90,
], null, null);
reading_test_case('samples beat saved raw', [
    'readingSamples' => [50], 'readingScoreMethod' => 'activity-v1', 'readingScoreRaw' => 450, 'readingScore' => 90,
], 50, 50.0);
reading_test_case('unrecognized method ignores raw metadata', [
    'readingScoreMethod' => 'other', 'readingScoreRaw' => 450, 'readingScore' => 50,
], 50, 50.0);

$durations = [0, 0.5, 1, 15, 30, 45, 60, 3600, PHP_FLOAT_MAX];
$rawScores = [-1000, 0, 1, 5, 10, 25, 50, 100, 150, 450, 1000, 50000, PHP_FLOAT_MAX];
foreach ($durations as $duration) {
    $previous = -1;
    foreach ($rawScores as $raw) {
        $score = pixl_notification_reading_score(['engagement' => ['readingScore' => $raw, 'sessionDuration' => $duration]])['score'];
        reading_test_assert(is_int($score) && $score >= $previous && $score <= 100, 'score range or activity monotonicity failed');
        $previous = $score;
    }
}
foreach ($rawScores as $raw) {
    $previous = -1;
    foreach ($durations as $duration) {
        $score = pixl_notification_reading_score(['engagement' => ['readingScore' => $raw, 'sessionDuration' => $duration]])['score'];
        reading_test_assert($score >= $previous, 'duration monotonicity failed');
        $previous = $score;
    }
}

reading_test_assert(!in_array(dirname(__DIR__) . '/pixl_config.php', get_included_files(), true), 'test loaded production config');
restore_error_handler();
echo "PASS ReadingScore regression checks ($checks assertions; no database or notifications)\n";
