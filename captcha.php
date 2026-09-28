<?php
declare(strict_types=1);

require_once __DIR__ . '/pixl_captcha.php';

pixl_apply_cors();
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    pixl_json_response(['ok' => false, 'error' => 'POST required'], 405);
}
$raw = file_get_contents('php://input', false, null, 0, 8193) ?: '';
$input = strlen($raw) <= 8192 ? json_decode($raw, true) : null;
if (!is_array($input)) {
    pixl_json_response(['ok' => false, 'error' => 'invalid_json'], 400);
}
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
$url = is_string($input['url'] ?? null) ? $input['url'] : '';
if (!pixl_captcha_request_allowed($origin, $url)) {
    pixl_json_response(['ok' => false, 'error' => 'origin_not_allowed'], 403);
}
$publicKey = (string)(pixl_config()['public_key'] ?? '');
if ($publicKey !== '' && (!is_string($input['siteKey'] ?? null) || !hash_equals($publicKey, $input['siteKey']))) {
    pixl_json_response(['ok' => false, 'error' => 'bad_site_key'], 403);
}
$action = is_string($input['action'] ?? null) ? $input['action'] : '';
$token = is_string($input['token'] ?? null) ? $input['token'] : '';
$failedAttempts = $input['failedAttempts'] ?? 0;
$attemptSource = array_key_exists('attemptSource', $input) ? $input['attemptSource'] : '';
if (!in_array($action, ['check', 'status', 'verify', 'fail'], true)
    || ($action !== 'check' && !preg_match('/\A[a-f0-9]{64}\z/D', $token))
    || !is_int($failedAttempts) || $failedAttempts < 0 || $failedAttempts > 10000
    || !pixl_captcha_attempt_source_valid($attemptSource)
    || ($action === 'fail' && $failedAttempts === 0)) {
    pixl_json_response(['ok' => false, 'error' => 'invalid_request'], 422);
}
$settings = pixl_captcha_settings();
if (!$settings['enabled']) {
    pixl_json_response(['ok' => true, 'required' => false, 'verified' => false, 'active' => false]);
}
try {
    $pdo = pixl_pdo();
    pixl_captcha_ensure_schema($pdo);
    $visitor = pixl_hash(pixl_remote_ip() . '|' . (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $result = pixl_captcha_process($pdo, $settings, $visitor, $action, $token, null, $failedAttempts, $attemptSource, $url);
    pixl_json_response($result, !empty($result['ok']) ? 200 : 422);
} catch (Throwable $error) {
    error_log('stats3 captcha: ' . $error->getMessage());
    pixl_json_response(['ok' => false, 'error' => 'captcha_unavailable'], 503);
}
