<?php
declare(strict_types=1);

require_once __DIR__ . '/pixl_server.php';

function pixel_push_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function pixel_push_table_name(string $suffix): string
{
    $table = pixl_table_name() . '_' . $suffix;
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        throw new RuntimeException('Ungueltiger Push-Tabellenname.');
    }
    return $table;
}

function pixel_push_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function pixel_push_base64url_decode(string $value): string
{
    $value = strtr($value, '-_', '+/');
    $padding = strlen($value) % 4;
    if ($padding > 0) {
        $value .= str_repeat('=', 4 - $padding);
    }
    $decoded = base64_decode($value, true);
    if ($decoded === false) {
        throw new RuntimeException('Ungueltige base64url-Daten.');
    }
    return $decoded;
}

function pixel_push_ensure_schema(PDO $pdo): void
{
    $subscriptions = pixel_push_table_name('push_subscriptions');
    $meta = pixel_push_table_name('push_meta');

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS `$subscriptions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_seen_at` DATETIME NULL,
  `last_sent_at` DATETIME NULL,
  `last_error_at` DATETIME NULL,
  `endpoint_hash` CHAR(64) NOT NULL,
  `endpoint` TEXT NOT NULL,
  `p256dh` VARCHAR(255) NOT NULL DEFAULT '',
  `auth` VARCHAR(255) NOT NULL DEFAULT '',
  `content_encoding` VARCHAR(40) NOT NULL DEFAULT 'aes128gcm',
  `label` VARCHAR(120) NOT NULL DEFAULT '',
  `user_agent` TEXT NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `send_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `fail_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_status` SMALLINT UNSIGNED NULL,
  `last_error` TEXT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `endpoint_hash` (`endpoint_hash`),
  KEY `active_updated` (`active`, `updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS `$meta` (
  `name` VARCHAR(80) NOT NULL,
  `value` LONGTEXT NOT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
}

function pixel_push_generate_vapid_keypair(): array
{
    if (!extension_loaded('openssl')) {
        throw new RuntimeException('PHP OpenSSL ist fuer Web Push erforderlich.');
    }

    $key = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name' => 'prime256v1',
    ]);
    if (!$key) {
        throw new RuntimeException('VAPID-Key konnte nicht erzeugt werden.');
    }

    $details = openssl_pkey_get_details($key);
    if (!is_array($details) || empty($details['ec']['x']) || empty($details['ec']['y'])) {
        throw new RuntimeException('VAPID-Keydetails fehlen.');
    }

    $privatePem = '';
    if (!openssl_pkey_export($key, $privatePem)) {
        throw new RuntimeException('VAPID-Private-Key konnte nicht exportiert werden.');
    }

    $x = str_pad((string)$details['ec']['x'], 32, "\0", STR_PAD_LEFT);
    $y = str_pad((string)$details['ec']['y'], 32, "\0", STR_PAD_LEFT);

    return [
        'private_pem' => $privatePem,
        'public_key' => pixel_push_base64url_encode("\x04" . $x . $y),
    ];
}

function pixel_push_meta_set(PDO $pdo, string $name, string $value): void
{
    $meta = pixel_push_table_name('push_meta');
    $stmt = $pdo->prepare("INSERT INTO `$meta` (`name`, `value`) VALUES (:name, :value)
        ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)");
    $stmt->execute([':name' => $name, ':value' => $value]);
}

function pixel_push_meta_get(PDO $pdo, string $name): string
{
    $meta = pixel_push_table_name('push_meta');
    $stmt = $pdo->prepare("SELECT `value` FROM `$meta` WHERE `name` = :name");
    $stmt->execute([':name' => $name]);
    return (string)($stmt->fetchColumn() ?: '');
}

function pixel_push_vapid(PDO $pdo): array
{
    pixel_push_ensure_schema($pdo);

    $privatePem = pixel_push_meta_get($pdo, 'vapid_private_pem');
    $publicKey = pixel_push_meta_get($pdo, 'vapid_public_key');

    if ($privatePem === '' || $publicKey === '') {
        $generated = pixel_push_generate_vapid_keypair();
        $privatePem = $generated['private_pem'];
        $publicKey = $generated['public_key'];
        pixel_push_meta_set($pdo, 'vapid_private_pem', $privatePem);
        pixel_push_meta_set($pdo, 'vapid_public_key', $publicKey);
    }

    return [
        'private_pem' => $privatePem,
        'public_key' => $publicKey,
    ];
}

function pixel_push_subject(): string
{
    $config = pixl_config();
    $subject = trim((string)($config['web_push_subject'] ?? ''));
    if ($subject !== '') {
        return $subject;
    }

    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? 'www.bayerchristian.de'));
    $host = preg_replace('/:\d+$/', '', $host) ?: 'www.bayerchristian.de';
    return 'mailto:webpush@' . $host;
}

function pixel_push_origin_from_url(string $url): string
{
    $scheme = (string)(parse_url($url, PHP_URL_SCHEME) ?: 'https');
    $host = (string)(parse_url($url, PHP_URL_HOST) ?: '');
    $port = parse_url($url, PHP_URL_PORT);
    if ($host === '') {
        throw new RuntimeException('Push-Endpunkt ohne Host.');
    }
    $origin = $scheme . '://' . $host;
    if (is_int($port) && !(($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) {
        $origin .= ':' . $port;
    }
    return $origin;
}

function pixel_push_der_length(string $der, int &$offset): int
{
    $length = ord($der[$offset]);
    $offset++;
    if (($length & 0x80) === 0) {
        return $length;
    }

    $bytes = $length & 0x7f;
    $length = 0;
    for ($i = 0; $i < $bytes; $i++) {
        $length = ($length << 8) + ord($der[$offset]);
        $offset++;
    }
    return $length;
}

function pixel_push_der_signature_to_raw(string $der): string
{
    $offset = 0;
    if (ord($der[$offset]) !== 0x30) {
        throw new RuntimeException('Ungueltige ES256-Signatur.');
    }
    $offset++;
    pixel_push_der_length($der, $offset);

    if (ord($der[$offset]) !== 0x02) {
        throw new RuntimeException('Ungueltige ES256-Signatur R.');
    }
    $offset++;
    $rLength = pixel_push_der_length($der, $offset);
    $r = substr($der, $offset, $rLength);
    $offset += $rLength;

    if (ord($der[$offset]) !== 0x02) {
        throw new RuntimeException('Ungueltige ES256-Signatur S.');
    }
    $offset++;
    $sLength = pixel_push_der_length($der, $offset);
    $s = substr($der, $offset, $sLength);

    $r = str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT);
    $s = str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);

    return substr($r, -32) . substr($s, -32);
}

function pixel_push_vapid_jwt(string $endpoint, array $vapid): string
{
    $header = pixel_push_base64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $payload = pixel_push_base64url_encode(json_encode([
        'aud' => pixel_push_origin_from_url($endpoint),
        'exp' => time() + 12 * 3600,
        'sub' => pixel_push_subject(),
    ]));
    $unsigned = $header . '.' . $payload;

    $signature = '';
    if (!openssl_sign($unsigned, $signature, $vapid['private_pem'], OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('VAPID-JWT konnte nicht signiert werden.');
    }

    return $unsigned . '.' . pixel_push_base64url_encode(pixel_push_der_signature_to_raw($signature));
}

function pixel_push_public_point_to_pem(string $point): string
{
    if (strlen($point) !== 65 || ord($point[0]) !== 0x04) {
        throw new RuntimeException('Ungueltiger P-256 Public Key.');
    }
    $prefix = hex2bin('3059301306072A8648CE3D020106082A8648CE3D030107034200');
    if ($prefix === false) {
        throw new RuntimeException('Public-Key-Prefix fehlt.');
    }
    return "-----BEGIN PUBLIC KEY-----\n"
        . chunk_split(base64_encode($prefix . $point), 64, "\n")
        . "-----END PUBLIC KEY-----\n";
}

function pixel_push_encrypt_payload(string $payload, array $subscription): string
{
    $receiverPublic = pixel_push_base64url_decode((string)$subscription['p256dh']);
    $authSecret = pixel_push_base64url_decode((string)$subscription['auth']);
    if (strlen($authSecret) < 16) {
        throw new RuntimeException('Push-Auth-Secret ist zu kurz.');
    }

    $senderKey = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name' => 'prime256v1',
    ]);
    if (!$senderKey) {
        throw new RuntimeException('Ephemerer Push-Key konnte nicht erzeugt werden.');
    }

    $details = openssl_pkey_get_details($senderKey);
    if (!is_array($details) || empty($details['ec']['x']) || empty($details['ec']['y'])) {
        throw new RuntimeException('Ephemere Push-Keydetails fehlen.');
    }

    $senderPublic = "\x04"
        . str_pad((string)$details['ec']['x'], 32, "\0", STR_PAD_LEFT)
        . str_pad((string)$details['ec']['y'], 32, "\0", STR_PAD_LEFT);
    $receiverPem = pixel_push_public_point_to_pem($receiverPublic);
    $receiverKey = openssl_pkey_get_public($receiverPem);
    if (!$receiverKey) {
        throw new RuntimeException('Receiver Public Key konnte nicht gelesen werden.');
    }

    $sharedSecret = openssl_pkey_derive($receiverKey, $senderKey, 32);
    if ($sharedSecret === false || strlen($sharedSecret) === 0) {
        throw new RuntimeException('ECDH Shared Secret konnte nicht erzeugt werden.');
    }

    $keyInfo = "WebPush: info\0" . $receiverPublic . $senderPublic;
    $prkKey = hash_hmac('sha256', $sharedSecret, $authSecret, true);
    $ikm = hash_hmac('sha256', $keyInfo . "\x01", $prkKey, true);
    $salt = random_bytes(16);
    $prk = hash_hmac('sha256', $ikm, $salt, true);
    $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true), 0, 16);
    $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk, true), 0, 12);

    $plaintext = substr($payload, 0, 3900) . "\x02";
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($ciphertext === false || strlen($tag) !== 16) {
        throw new RuntimeException('Push-Payload konnte nicht verschluesselt werden.');
    }

    return $salt . pack('N', 4096) . chr(strlen($senderPublic)) . $senderPublic . $ciphertext . $tag;
}

function pixel_push_http_post(string $url, array $headers, string $body): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 4);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        $response = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        return ['status' => $status, 'body' => is_string($response) ? $response : '', 'error' => $error];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'ignore_errors' => true,
            'timeout' => 4,
        ],
    ]);
    $response = @file_get_contents($url, false, $context);
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $match)) {
        $status = (int)$match[1];
    }

    return [
        'status' => $status,
        'body' => is_string($response) ? $response : '',
        'error' => $response === false ? 'HTTP-Request fehlgeschlagen' : '',
    ];
}

function pixel_push_mark_result(PDO $pdo, int $id, int $status, string $error): void
{
    $subscriptions = pixel_push_table_name('push_subscriptions');
    $ok = $status >= 200 && $status < 300;
    $active = in_array($status, [404, 410], true) ? 0 : 1;

    $stmt = $pdo->prepare("UPDATE `$subscriptions`
        SET `active` = CASE WHEN :active_value = 0 THEN 0 ELSE `active` END,
            `send_count` = `send_count` + :sent_count,
            `fail_count` = `fail_count` + :failed_count,
            `last_sent_at` = COALESCE(:last_sent_value, `last_sent_at`),
            `last_error_at` = COALESCE(:last_error_value, `last_error_at`),
            `last_status` = :status_value,
            `last_error` = :error_value
        WHERE `id` = :id_value");
    $stmt->execute([
        ':active_value' => $active,
        ':sent_count' => $ok ? 1 : 0,
        ':failed_count' => $ok ? 0 : 1,
        ':last_sent_value' => $ok ? gmdate('Y-m-d H:i:s') : null,
        ':last_error_value' => $ok ? null : gmdate('Y-m-d H:i:s'),
        ':status_value' => $status > 0 ? $status : null,
        ':error_value' => pixl_string($error, 1000),
        ':id_value' => $id,
    ]);
}

function pixel_push_send_to_subscription(PDO $pdo, array $subscription, array $payload, array $vapid): array
{
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        throw new RuntimeException('Push-Payload ist nicht JSON-kodierbar.');
    }

    $body = pixel_push_encrypt_payload($json, $subscription);
    $jwt = pixel_push_vapid_jwt((string)$subscription['endpoint'], $vapid);
    $headers = [
        'TTL: 120',
        'Urgency: high',
        'Content-Type: application/octet-stream',
        'Content-Encoding: aes128gcm',
        'Authorization: vapid t=' . $jwt . ', k=' . $vapid['public_key'],
    ];
    $result = pixel_push_http_post((string)$subscription['endpoint'], $headers, $body);
    $status = (int)$result['status'];
    $error = trim((string)$result['error'] . ' ' . (string)$result['body']);

    pixel_push_mark_result($pdo, (int)$subscription['id'], $status, $error);

    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'error' => $error,
    ];
}

function pixel_push_absolute_url(string $path): string
{
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'www.bayerchristian.de');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $scheme = $https ? 'https' : 'https';
    return $scheme . '://' . $host . '/' . ltrim($path, '/');
}

function pixel_push_path_only(array $row): string
{
    $path = trim((string)($row['path'] ?? ''));
    if ($path === '') {
        return '/';
    }

    $parsed = parse_url($path, PHP_URL_PATH);
    if (is_string($parsed) && $parsed !== '') {
        $query = parse_url($path, PHP_URL_QUERY);
        $path = $parsed . (is_string($query) && $query !== '' ? '?' . $query : '');
    }

    return $path[0] === '/' ? $path : '/' . $path;
}

function pixel_push_client_line(array $row): string
{
    $parts = array_filter([
        trim((string)($row['browser'] ?? '')),
        trim((string)($row['os'] ?? '')),
        trim((string)($row['device'] ?? '')),
    ], static function (string $part): bool {
        return $part !== '';
    });

    return $parts ? implode(' / ', $parts) : 'Unbekannt';
}

function pixel_push_event_payload(array $row): array
{
    $id = (int)($row['id'] ?? 0);
    $body = 'Pixel: ' . pixel_push_client_line($row) . "\n" . pixel_push_path_only($row);

    return [
        'title' => 'Pixel #' . $id,
        'body' => pixl_string($body, 700),
        'tag' => 'pixl-event-' . $id,
        'url' => pixel_push_absolute_url('pixel_stats2.php'),
        'eventId' => $id,
        'createdAt' => (string)($row['created_at'] ?? ''),
    ];
}

function pixel_push_fetch_event(PDO $pdo, int $eventId): ?array
{
    $table = pixl_table_name();
    $stmt = $pdo->prepare("SELECT id, created_at, reason, title, hostname, path, browser, os, device,
            country, session_duration, is_bot, bot_score, bot_name
        FROM `$table`
        WHERE id = :id
        LIMIT 1");
    $stmt->execute([':id' => $eventId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function pixel_push_active_subscriptions(PDO $pdo): array
{
    $subscriptions = pixel_push_table_name('push_subscriptions');
    return $pdo->query("SELECT * FROM `$subscriptions` WHERE `active` = 1 ORDER BY `updated_at` DESC LIMIT 10")->fetchAll();
}

function pixel_push_send_payload_to_all(PDO $pdo, array $payload): array
{
    pixel_push_ensure_schema($pdo);
    $subscriptions = pixel_push_active_subscriptions($pdo);
    if (!$subscriptions) {
        return ['total' => 0, 'sent' => 0, 'failed' => 0, 'errors' => []];
    }

    $vapid = pixel_push_vapid($pdo);
    $sent = 0;
    $failed = 0;
    $errors = [];

    foreach ($subscriptions as $subscription) {
        try {
            $result = pixel_push_send_to_subscription($pdo, $subscription, $payload, $vapid);
            if ($result['ok']) {
                $sent++;
            } else {
                $failed++;
                $errors[] = [
                    'endpointHash' => substr((string)$subscription['endpoint_hash'], 0, 18),
                    'status' => $result['status'],
                    'error' => pixl_string($result['error'], 300),
                ];
            }
        } catch (Throwable $e) {
            $failed++;
            pixel_push_mark_result($pdo, (int)$subscription['id'], 0, $e->getMessage());
            $errors[] = [
                'endpointHash' => substr((string)$subscription['endpoint_hash'], 0, 18),
                'status' => 0,
                'error' => pixl_string($e->getMessage(), 300),
            ];
        }
    }

    return [
        'total' => count($subscriptions),
        'sent' => $sent,
        'failed' => $failed,
        'errors' => $errors,
        'firstError' => $errors ? (($errors[0]['status'] ? 'HTTP ' . $errors[0]['status'] . ': ' : '') . $errors[0]['error']) : '',
    ];
}

function pixel_push_notify_event(PDO $pdo, int $eventId): void
{
    if ($eventId <= 0 || !extension_loaded('openssl')) {
        return;
    }

    try {
        pixel_push_ensure_schema($pdo);
        $row = pixel_push_fetch_event($pdo, $eventId);
        if (!$row) {
            return;
        }
        pixel_push_send_payload_to_all($pdo, pixel_push_event_payload($row));
    } catch (Throwable $e) {
        error_log('pixel_push_notify_event failed: ' . $e->getMessage());
    }
}

function pixel_push_store_subscription(PDO $pdo, array $data): array
{
    $subscription = $data['subscription'] ?? $data;
    if (!is_array($subscription)) {
        throw new RuntimeException('Subscription fehlt.');
    }
    $endpoint = pixl_string($subscription['endpoint'] ?? '', 0);
    $keys = $subscription['keys'] ?? [];
    $p256dh = pixl_string(is_array($keys) ? ($keys['p256dh'] ?? '') : '', 255);
    $auth = pixl_string(is_array($keys) ? ($keys['auth'] ?? '') : '', 255);
    if ($endpoint === '' || $p256dh === '' || $auth === '') {
        throw new RuntimeException('Subscription ist unvollstaendig.');
    }

    $subscriptions = pixel_push_table_name('push_subscriptions');
    $endpointHash = pixl_hash($endpoint);
    $label = pixl_string($data['label'] ?? 'Admin-Geraet', 120);
    $userAgent = pixl_string($data['userAgent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0);

    $stmt = $pdo->prepare("INSERT INTO `$subscriptions`
        (`endpoint_hash`, `endpoint`, `p256dh`, `auth`, `content_encoding`, `label`, `user_agent`, `active`, `last_seen_at`)
        VALUES (:endpoint_hash, :endpoint, :p256dh, :auth, 'aes128gcm', :label, :user_agent, 1, UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE
            `endpoint` = VALUES(`endpoint`),
            `p256dh` = VALUES(`p256dh`),
            `auth` = VALUES(`auth`),
            `label` = VALUES(`label`),
            `user_agent` = VALUES(`user_agent`),
            `active` = 1,
            `last_seen_at` = UTC_TIMESTAMP()");
    $stmt->execute([
        ':endpoint_hash' => $endpointHash,
        ':endpoint' => $endpoint,
        ':p256dh' => $p256dh,
        ':auth' => $auth,
        ':label' => $label,
        ':user_agent' => $userAgent,
    ]);

    return ['endpointHash' => $endpointHash];
}

function pixel_push_deactivate_subscription(PDO $pdo, string $endpoint): void
{
    if ($endpoint === '') {
        return;
    }
    $subscriptions = pixel_push_table_name('push_subscriptions');
    $stmt = $pdo->prepare("UPDATE `$subscriptions` SET `active` = 0 WHERE `endpoint_hash` = :endpoint_hash");
    $stmt->execute([':endpoint_hash' => pixl_hash($endpoint)]);
}

function pixel_push_reset_subscriptions(PDO $pdo): int
{
    $subscriptions = pixel_push_table_name('push_subscriptions');
    return $pdo->exec("DELETE FROM `$subscriptions`") ?: 0;
}

function pixel_push_subscription_rows(PDO $pdo): array
{
    $subscriptions = pixel_push_table_name('push_subscriptions');
    return $pdo->query("SELECT id, created_at, updated_at, last_seen_at, last_sent_at, last_error_at,
            endpoint_hash, label, user_agent, active, send_count, fail_count, last_status, last_error
        FROM `$subscriptions`
        ORDER BY updated_at DESC
        LIMIT 20")->fetchAll();
}

function pixel_push_json_request(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = $raw !== '' ? json_decode($raw, true) : $_POST;
    return is_array($data) ? $data : [];
}

function pixel_push_diagnostics(PDO $pdo): array
{
    $subscriptions = [];
    try {
        $subscriptions = pixel_push_active_subscriptions($pdo);
    } catch (Throwable) {
        $subscriptions = [];
    }

    return [
        'php' => PHP_VERSION,
        'openssl' => extension_loaded('openssl') ? 'yes' : 'no',
        'curl' => function_exists('curl_init') ? 'yes' : 'no',
        'sodium' => extension_loaded('sodium') ? 'yes' : 'no',
        'derive' => function_exists('openssl_pkey_derive') ? 'yes' : 'no',
        'aes128gcm' => function_exists('openssl_get_cipher_methods') && in_array('aes-128-gcm', openssl_get_cipher_methods(), true) ? 'yes' : 'no',
        'activeSubscriptions' => count($subscriptions),
    ];
}

function pixel_push_handle_action(PDO $pdo): void
{
    $action = (string)($_GET['action'] ?? '');

    if ($action === 'public_key') {
        try {
            pixl_json_response(['ok' => true, 'publicKey' => pixel_push_vapid($pdo)['public_key']]);
        } catch (Throwable $e) {
            error_log('pixel push public_key failed: ' . $e->getMessage());
            pixl_json_response([
                'ok' => false,
                'error' => 'Public-Key-Fehler: ' . $e->getMessage(),
                'diagnostics' => pixel_push_diagnostics($pdo),
            ], 500);
        }
    }

    if ($action === 'diagnostics') {
        pixl_json_response(['ok' => true, 'diagnostics' => pixel_push_diagnostics($pdo)]);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }

    $data = pixel_push_json_request();
    if ($action === 'subscribe') {
        try {
            $stored = pixel_push_store_subscription($pdo, $data);
            pixl_json_response(['ok' => true] + $stored);
        } catch (Throwable $e) {
            pixl_json_response(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    if ($action === 'unsubscribe') {
        $endpoint = pixl_string($data['endpoint'] ?? '', 0);
        pixel_push_deactivate_subscription($pdo, $endpoint);
        pixl_json_response(['ok' => true]);
    }

    if ($action === 'reset_subscriptions') {
        try {
            $deleted = pixel_push_reset_subscriptions($pdo);
            pixl_json_response(['ok' => true, 'deleted' => $deleted]);
        } catch (Throwable $e) {
            pixl_json_response(['ok' => false, 'error' => 'Reset fehlgeschlagen: ' . $e->getMessage()], 500);
        }
    }

    if ($action === 'send_test') {
        try {
            $payload = [
                'title' => 'Pixl Web Push aktiv',
                'body' => 'Diese echte Web-Push-Nachricht kam serverseitig aus pixel_stats2.php.',
                'tag' => 'pixl-webpush-test-' . time(),
                'url' => pixel_push_absolute_url('pixel_stats2.php'),
                'eventId' => 0,
            ];
            $result = pixel_push_send_payload_to_all($pdo, $payload);
            if ((int)$result['total'] < 1) {
                pixl_json_response(['ok' => false, 'error' => 'Kein aktives Admin-Geraet abonniert. Bitte zuerst dieses Geraet aktivieren.'] + $result, 400);
            }
            if ((int)$result['failed'] > 0) {
                pixl_json_response(['ok' => false, 'error' => $result['firstError'] ?: 'Push-Versand fehlgeschlagen.'] + $result, 502);
            }
            pixl_json_response(['ok' => true] + $result);
        } catch (Throwable $e) {
            error_log('pixel push send_test failed: ' . $e->getMessage());
            pixl_json_response([
                'ok' => false,
                'error' => 'Serverfehler beim Push-Test: ' . $e->getMessage(),
                'diagnostics' => pixel_push_diagnostics($pdo),
            ], 500);
        }
    }
}

function pixel_push_latest_event(PDO $pdo): ?array
{
    $table = pixl_table_name();
    $row = $pdo->query("SELECT id, created_at, reason, title, hostname, path
        FROM `$table`
        ORDER BY id DESC
        LIMIT 1")->fetch();
    return is_array($row) ? $row : null;
}

function pixel_push_render_page(PDO $pdo): void
{
    $vapid = pixel_push_vapid($pdo);
    $rows = pixel_push_subscription_rows($pdo);
    $activeCount = 0;
    foreach ($rows as $row) {
        if ((int)$row['active'] === 1) {
            $activeCount++;
        }
    }
    $latest = pixel_push_latest_event($pdo);
    $openssl = extension_loaded('openssl') ? 'OK' : 'fehlt';
    $httpClient = function_exists('curl_init') ? 'cURL' : 'stream';
    $swUrl = 'pixel_webpush_sw.js';

    header('Content-Type: text/html; charset=utf-8');
    ?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Pixl Web Push Admin</title>
  <style>
    :root { --bg: #f6f7f9; --card: #fff; --ink: #17202a; --muted: #667085; --line: #d8dee8; --accent: #146c94; --ok: #18875f; --warn: #a84818; }
    * { box-sizing: border-box; }
    body { margin: 0; color: var(--ink); background: var(--bg); font: 13px/1.45 system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
    header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; padding: 18px 22px; border-bottom: 1px solid var(--line); background: var(--card); }
    h1, h2 { margin: 0; letter-spacing: 0; }
    h1 { font-size: 22px; }
    h2 { margin-bottom: 10px; font-size: 15px; }
    main { width: min(1180px, calc(100vw - 28px)); margin: 18px auto 36px; }
    section { margin-bottom: 16px; padding: 14px; border: 1px solid var(--line); border-radius: 8px; background: var(--card); }
    .toolbar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
    button, a.button { min-height: 34px; padding: 0 12px; border: 1px solid var(--accent); border-radius: 6px; color: #fff; background: var(--accent); font: inherit; font-weight: 800; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; }
    button.secondary, a.secondary { color: var(--accent); background: #fff; }
    button.danger { border-color: var(--warn); color: var(--warn); background: #fff; }
    button:disabled { opacity: .55; cursor: not-allowed; }
    .grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; }
    .metric { padding: 11px; border: 1px solid var(--line); border-radius: 8px; background: #fbfcfe; }
    .metric span { display: block; color: var(--muted); font-size: 11px; font-weight: 800; text-transform: uppercase; }
    .metric strong { display: block; margin-top: 4px; font-size: 20px; }
    .status { margin-top: 10px; color: var(--muted); font-weight: 700; }
    .status.ok { color: var(--ok); }
    .status.warn { color: var(--warn); }
    table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    th, td { padding: 7px 8px; border-bottom: 1px solid var(--line); text-align: left; vertical-align: top; }
    th { color: var(--muted); font-size: 11px; text-transform: uppercase; }
    td code { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font: 11px/1.35 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
    .badge { display: inline-flex; align-items: center; min-height: 20px; padding: 1px 7px; border-radius: 999px; color: #fff; background: var(--muted); font-size: 11px; font-weight: 800; }
    .badge.ok { background: var(--ok); }
    .badge.off { background: #98a2b3; }
    .muted { color: var(--muted); }
    .scroll { overflow-x: auto; }
    .scroll table { min-width: 980px; }
    @media (max-width: 820px) { header { display: block; } .toolbar { margin-top: 12px; } .grid { grid-template-columns: 1fr 1fr; } }
  </style>
</head>
<body>
  <header>
    <div>
      <h1>Pixl Web Push Admin</h1>
      <div class="muted">Echte Web-Push-Nachrichten nur an eingeloggte Admin-Geraete.</div>
    </div>
    <div class="toolbar">
      <a class="button secondary" href="pixl_stats.php">Statistik</a>
      <a class="button secondary" href="?logout=1">Logout</a>
    </div>
  </header>
  <main>
    <section>
      <h2>Dieses Geraet</h2>
      <div class="toolbar">
        <button id="pushEnable" type="button">Web Push fuer dieses Geraet aktivieren</button>
        <button id="pushTest" type="button" class="secondary">Test Push senden</button>
        <button id="pushDisable" type="button" class="secondary">Dieses Geraet deaktivieren</button>
        <button id="pushReset" type="button" class="danger">Admin Subscriptions resetten</button>
      </div>
      <div id="pushStatus" class="status">Bereit.</div>
    </section>

    <section>
      <div class="grid">
        <div class="metric"><span>Aktive Admin-Geraete</span><strong><?= pixel_push_h($activeCount) ?></strong></div>
        <div class="metric"><span>OpenSSL</span><strong><?= pixel_push_h($openssl) ?></strong></div>
        <div class="metric"><span>HTTP Client</span><strong><?= pixel_push_h($httpClient) ?></strong></div>
        <div class="metric"><span>Letztes Event</span><strong><?= $latest ? '#' . pixel_push_h($latest['id']) : '-' ?></strong></div>
      </div>
      <p class="status">VAPID Public Key: <code><?= pixel_push_h(substr($vapid['public_key'], 0, 28)) ?>...</code></p>
    </section>

    <section class="scroll">
      <h2>Admin Subscriptions</h2>
      <table>
        <thead>
          <tr>
            <th>Status</th><th>Label</th><th>Endpoint Hash</th><th>Gesehen</th><th>Gesendet</th><th>Fehler</th><th>UserAgent</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td><span class="badge <?= (int)$row['active'] === 1 ? 'ok' : 'off' ?>"><?= (int)$row['active'] === 1 ? 'Aktiv' : 'Aus' ?></span></td>
              <td><?= pixel_push_h($row['label']) ?></td>
              <td><code><?= pixel_push_h(substr((string)$row['endpoint_hash'], 0, 18)) ?></code></td>
              <td><?= pixel_push_h($row['last_seen_at'] ?: $row['updated_at']) ?></td>
              <td><?= pixel_push_h($row['send_count']) ?><?= $row['last_status'] ? ' / HTTP ' . pixel_push_h($row['last_status']) : '' ?></td>
              <td><?= pixel_push_h($row['fail_count']) ?><?= $row['last_error'] ? ' / ' . pixel_push_h(substr((string)$row['last_error'], 0, 80)) : '' ?></td>
              <td><code><?= pixel_push_h($row['user_agent']) ?></code></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?><tr><td colspan="7" class="muted">Noch keine Admin-Subscription gespeichert.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </section>
  </main>

  <script>
    (() => {
      "use strict";

      const swUrl = <?= json_encode($swUrl) ?>;
      const status = document.getElementById("pushStatus");
      const enableButton = document.getElementById("pushEnable");
      const testButton = document.getElementById("pushTest");
      const disableButton = document.getElementById("pushDisable");
      const resetButton = document.getElementById("pushReset");

      function setStatus(text, mode = "") {
        status.textContent = text;
        status.className = `status ${mode}`;
      }

      function urlBase64ToUint8Array(base64String) {
        const padding = "=".repeat((4 - base64String.length % 4) % 4);
        const base64 = (base64String + padding).replace(/-/g, "+").replace(/_/g, "/");
        const rawData = window.atob(base64);
        const output = new Uint8Array(rawData.length);
        for (let i = 0; i < rawData.length; i++) {
          output[i] = rawData.charCodeAt(i);
        }
        return output;
      }

      function actionUrl(action) {
        const url = new URL(window.location.href);
        url.search = "";
        url.searchParams.set("action", action);
        return url.toString();
      }

      function sameApplicationServerKey(subscription, nextKey) {
        const currentKey = subscription?.options?.applicationServerKey;
        if (!currentKey) return true;

        const current = new Uint8Array(currentKey);
        if (current.length !== nextKey.length) return false;
        for (let i = 0; i < current.length; i++) {
          if (current[i] !== nextKey[i]) return false;
        }
        return true;
      }

      async function jsonFetch(url, options = {}) {
        const response = await fetch(url, {
          credentials: "same-origin",
          headers: { "Content-Type": "application/json" },
          ...options
        });
        const text = await response.text();
        let data = {};
        try {
          data = text ? JSON.parse(text) : {};
        } catch {
          throw new Error(text || `HTTP ${response.status}`);
        }
        if (!response.ok || !data.ok) {
          const diagnostics = data.diagnostics
            ? ` | PHP ${data.diagnostics.php}, OpenSSL ${data.diagnostics.openssl}, ECDH ${data.diagnostics.derive}, AES-GCM ${data.diagnostics.aes128gcm}, aktive Geraete ${data.diagnostics.activeSubscriptions}`
            : "";
          throw new Error((data.error || `HTTP ${response.status}`) + diagnostics);
        }
        return data;
      }

      async function registration() {
        if (!("serviceWorker" in navigator) || !("PushManager" in window)) {
          throw new Error("Dieser Browser unterstuetzt Push API oder Service Worker nicht.");
        }
        if (!window.isSecureContext) {
          throw new Error("Web Push braucht HTTPS.");
        }
        const script = new URL(swUrl, window.location.href).toString();
        const scope = new URL("./", window.location.href).toString();
        return navigator.serviceWorker.register(script, { scope });
      }

      async function publicKey() {
        const data = await jsonFetch(actionUrl("public_key"));
        return data.publicKey;
      }

      async function currentSubscription() {
        const reg = await registration();
        return reg.pushManager.getSubscription();
      }

      async function subscribeThisDevice() {
        if (!("Notification" in window)) {
          throw new Error("Dieser Browser unterstuetzt Notifications nicht.");
        }

        const permission = await Notification.requestPermission();
        if (permission !== "granted") {
          throw new Error(permission === "denied" ? "Vom Browser blockiert." : "Nicht erlaubt.");
        }

        const reg = await registration();
        const key = urlBase64ToUint8Array(await publicKey());
        let subscription = await reg.pushManager.getSubscription();

        if (subscription && !sameApplicationServerKey(subscription, key)) {
          await subscription.unsubscribe();
          subscription = null;
        }

        if (!subscription) {
          subscription = await reg.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: key
          });
        }

        await jsonFetch(actionUrl("subscribe"), {
          method: "POST",
          body: JSON.stringify({
            subscription: subscription.toJSON(),
            label: navigator.platform || "Admin-Geraet",
            userAgent: navigator.userAgent
          })
        });

        return subscription;
      }

      async function enablePush() {
        enableButton.disabled = true;
        setStatus("Safari/Browser-Genehmigung wird angefragt...");
        try {
          await subscribeThisDevice();
          setStatus("Aktiv. Neue Pixl-Events werden serverseitig an dieses Geraet gepusht.", "ok");
        } catch (error) {
          setStatus(error.message || "Web Push konnte nicht aktiviert werden.", "warn");
        } finally {
          enableButton.disabled = false;
        }
      }

      async function sendTest() {
        testButton.disabled = true;
        setStatus("Geraet wird geprueft, dann wird der Test Push gesendet...");
        try {
          await subscribeThisDevice();
          const data = await jsonFetch(actionUrl("send_test"), { method: "POST", body: "{}" });
          setStatus(`Test gesendet: ${data.sent}/${data.total}, Fehler: ${data.failed}`, data.failed ? "warn" : "ok");
        } catch (error) {
          setStatus(error.message || "Test Push fehlgeschlagen.", "warn");
        } finally {
          testButton.disabled = false;
        }
      }

      async function disablePush() {
        disableButton.disabled = true;
        try {
          const subscription = await currentSubscription();
          if (subscription) {
            await jsonFetch(actionUrl("unsubscribe"), {
              method: "POST",
              body: JSON.stringify({ endpoint: subscription.endpoint })
            });
            await subscription.unsubscribe();
          }
          setStatus("Dieses Geraet ist deaktiviert.", "ok");
        } catch (error) {
          setStatus(error.message || "Deaktivieren fehlgeschlagen.", "warn");
        } finally {
          disableButton.disabled = false;
        }
      }

      async function resetSubscriptions() {
        if (!window.confirm("Alle gespeicherten Admin Subscriptions wirklich loeschen? Danach dieses Geraet neu aktivieren.")) {
          return;
        }

        resetButton.disabled = true;
        setStatus("Admin Subscriptions werden geloescht...");
        try {
          const subscription = await currentSubscription().catch(() => null);
          if (subscription) {
            await subscription.unsubscribe().catch(() => {});
          }
          const data = await jsonFetch(actionUrl("reset_subscriptions"), { method: "POST", body: "{}" });
          setStatus(`Admin Subscriptions geloescht: ${data.deleted}. Bitte dieses Geraet neu aktivieren.`, "ok");
        } catch (error) {
          setStatus(error.message || "Reset fehlgeschlagen.", "warn");
        } finally {
          resetButton.disabled = false;
        }
      }

      enableButton.addEventListener("click", enablePush);
      testButton.addEventListener("click", sendTest);
      disableButton.addEventListener("click", disablePush);
      resetButton.addEventListener("click", resetSubscriptions);

      currentSubscription()
        .then((subscription) => {
          setStatus(subscription ? "Dieses Geraet hat bereits eine lokale Push-Subscription." : "Noch nicht auf diesem Geraet aktiviert.", subscription ? "ok" : "");
        })
        .catch((error) => setStatus(error.message, "warn"));
    })();
  </script>
</body>
</html>
<?php
}

function pixel_push_handle_request(): void
{
    pixl_require_stats_auth();
    $pdo = pixl_pdo();
    pixl_ensure_schema($pdo);
    pixel_push_ensure_schema($pdo);
    pixel_push_handle_action($pdo);
    pixel_push_render_page($pdo);
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    pixel_push_handle_request();
}
