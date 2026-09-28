<?php
declare(strict_types=1);

require_once __DIR__ . '/pixl_server.php';

function pixl_pushover_config(): array
{
    $config = pixl_config()['pushover'] ?? [];
    return is_array($config) ? $config : [];
}

function pixl_pushover_enabled(): bool
{
    $config = pixl_pushover_config();
    return !empty($config['enabled'])
        && trim((string)($config['token'] ?? '')) !== ''
        && trim((string)($config['user'] ?? '')) !== '';
}

function pixl_pushover_throttle_seconds(): int
{
    $config = pixl_pushover_config();
    $globalSeconds = max(0, min(86400, (int)($config['throttle_seconds'] ?? 90)));
    $maxPerHour = max(0, min(3600, (int)($config['max_messages_per_hour'] ?? 10)));
    $hourlySeconds = $maxPerHour > 0 ? (int)ceil(3600 / $maxPerHour) : 0;
    return max($globalSeconds, $hourlySeconds);
}

function pixl_pushover_reading_score_only(): bool
{
    return !empty(pixl_pushover_config()['reading_score_only']);
}

function pixl_pushover_throttle_file(): string
{
    $config = pixl_config();
    $siteId = trim((string)($config['site_id'] ?? 'stats3'));
    $key = hash('sha256', __DIR__ . '|' . $siteId);
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'pixl-pushover-' . $key . '.lock';
}

function pixl_pushover_run_throttled(callable $sender, ?array $event = null): bool
{
    if (pixl_pushover_reading_score_only()) {
        // Use the saved, normalized score. No score (including STAT4) cannot
        // qualify; never infer reading activity from a message or page count.
        $score = $event['reading_score'] ?? null;
        if ($event === null || !pixl_pushover_event_enabled($event)
            || !isset($event['is_bot']) || (int)$event['is_bot'] !== 0
            || !is_numeric($score) || !is_finite((float)$score) || (float)$score < 1.0) {
            return false;
        }
        $sender();
        return true;
    }

    $throttleSeconds = pixl_pushover_throttle_seconds();
    if ($throttleSeconds === 0) {
        $sender();
        return true;
    }

    $handle = fopen(pixl_pushover_throttle_file(), 'c+');
    if ($handle === false) {
        throw new RuntimeException('Die Pushover-Sperrdatei konnte nicht geoeffnet werden.');
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Die Pushover-Sperre konnte nicht aktiviert werden.');
        }

        rewind($handle);
        $lastSentAt = (int)trim((string)stream_get_contents($handle));
        if ($lastSentAt > 0 && (time() - $lastSentAt) < $throttleSeconds) {
            return false;
        }

        $sender();
        rewind($handle);
        if (!ftruncate($handle, 0) || fwrite($handle, (string)time()) === false) {
            throw new RuntimeException('Die Pushover-Sperrzeit konnte nicht gespeichert werden.');
        }
        fflush($handle);
        return true;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function pixl_pushover_limit(string $value, int $maxLength): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength, 'UTF-8');
    }
    if (function_exists('iconv_substr')) {
        $limited = iconv_substr($value, 0, $maxLength, 'UTF-8');
        if (is_string($limited)) {
            return $limited;
        }
    }
    return substr($value, 0, $maxLength);
}

function pixl_pushover_message_chunks(string $value, int $maxLength = 1024): array
{
    $value = trim($value);
    if ($value === '') {
        return [''];
    }

    $chunks = [];
    while ($value !== '') {
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($value, 'UTF-8') <= $maxLength) {
                $chunks[] = $value;
                break;
            }
            $chunks[] = mb_substr($value, 0, $maxLength, 'UTF-8');
            $value = mb_substr($value, $maxLength, null, 'UTF-8');
            continue;
        }

        if (strlen($value) <= $maxLength) {
            $chunks[] = $value;
            break;
        }
        $chunks[] = substr($value, 0, $maxLength);
        $value = substr($value, $maxLength);
    }

    return $chunks;
}

function pixl_pushover_fetch_event(PDO $pdo, int $eventId): array
{
    if ($eventId <= 0) {
        return [];
    }

    $table = pixl_table_name();
    $stmt = $pdo->prepare(
        "SELECT `id`, `created_at`, `title`, `message`, `reason`, `hostname`, `page_url`, `path`,
                `browser`, `os`, `country`, `language`, `visitor_hash`, `payload_json`, `reading_score`, `is_bot`
         FROM `$table` WHERE `id` = :event_id LIMIT 1"
    );
    $stmt->execute([':event_id' => $eventId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : [];
}

function pixl_pushover_event_enabled(array $row): bool
{
    // Die Browserdatei liefert nur Messdaten. Ob Pushover gesendet wird,
    // entscheidet das Backend anhand des serverseitig gespeicherten Ereignisses.
    $payload = json_decode((string)($row['payload_json'] ?? ''), true);
    return pixl_event_is_final([
        'reason' => $row['reason'] ?? '',
        'events' => is_array($payload) ? ($payload['events'] ?? []) : [],
    ]);
}

function pixl_pushover_message_from_event(array $row): array
{
    $title = trim((string)($row['title'] ?? ''));
    $message = trim((string)($row['message'] ?? ''));
    $path = trim((string)($row['path'] ?? ''));
    $pageUrl = trim((string)($row['page_url'] ?? ''));

    if ($title === '') {
        $title = 'Pixl Stats ' . trim((string)($row['reason'] ?? 'Event'));
    }
    if ($message === '') {
        $message = 'Path: ' . ($path !== '' ? $path : '/');
    }

    $result = [
        'title' => pixl_pushover_limit($title, 250),
        'message' => $message,
    ];
    if ($pageUrl !== '' && filter_var($pageUrl, FILTER_VALIDATE_URL)) {
        $result['url'] = pixl_pushover_limit($pageUrl, 512);
        $result['url_title'] = 'Seite oeffnen';
    }
    return $result;
}

function pixl_pushover_send(array $message): array
{
    if (!pixl_pushover_enabled()) {
        return ['sent' => false, 'reason' => 'disabled'];
    }
    if (!extension_loaded('curl')) {
        throw new RuntimeException('PHP-cURL ist fuer Pushover erforderlich.');
    }

    $config = pixl_pushover_config();
    $fields = [
        'token' => trim((string)$config['token']),
        'user' => trim((string)$config['user']),
        'title' => pixl_pushover_limit((string)($message['title'] ?? 'Pixl Stats'), 250),
        'message' => pixl_pushover_limit((string)($message['message'] ?? ''), 1024),
        'priority' => max(-2, min(1, (int)($config['priority'] ?? 0))),
    ];
    foreach (['url', 'url_title'] as $key) {
        if (isset($message[$key]) && $message[$key] !== '') {
            $fields[$key] = $message[$key];
        }
    }
    $sound = trim((string)($config['sound'] ?? ''));
    if ($sound !== '') {
        $fields['sound'] = $sound;
    }

    $handle = curl_init('https://api.pushover.net/1/messages.json');
    if ($handle === false) {
        throw new RuntimeException('Pushover-Verbindung konnte nicht initialisiert werden.');
    }

    $timeout = max(2, min(30, (int)($config['timeout'] ?? 8)));
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $body = curl_exec($handle);
    $curlError = curl_error($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);

    if (!is_string($body)) {
        throw new RuntimeException('Pushover-Netzwerkfehler: ' . ($curlError !== '' ? $curlError : 'unbekannt'));
    }
    $response = json_decode($body, true);
    if ($status < 200 || $status >= 300 || !is_array($response) || (int)($response['status'] ?? 0) !== 1) {
        $errors = is_array($response['errors'] ?? null) ? implode('; ', array_map('strval', $response['errors'])) : '';
        throw new RuntimeException('Pushover HTTP ' . $status . ($errors !== '' ? ': ' . $errors : ''));
    }

    return [
        'sent' => true,
        'request' => (string)($response['request'] ?? ''),
    ];
}

function pixl_pushover_notify_event(PDO $pdo, int $eventId): void
{
    if ($eventId <= 0 || !pixl_pushover_enabled()) {
        return;
    }

    $row = pixl_pushover_fetch_event($pdo, $eventId);
    if (!$row || !pixl_pushover_event_enabled($row) || !pixl_event_matches_configured_stats_url($row)) {
        return;
    }

    $notification = pixl_pushover_message_from_event($row);
    $chunks = pixl_pushover_message_chunks((string)$notification['message']);
    $chunkCount = count($chunks);

    pixl_pushover_run_throttled(static function () use ($chunks, $chunkCount, $notification, $row): void {
        foreach ($chunks as $index => $chunk) {
            $part = $notification;
            $part['message'] = $chunk;
            if ($chunkCount > 1) {
                $part['title'] = (string)$notification['title'] . ' (' . ($index + 1) . '/' . $chunkCount . ')';
            }
            $result = pixl_pushover_send($part);
            $mindPath = __DIR__ . '/mind/bootstrap.php';
            if (!empty($result['sent']) && is_file($mindPath)) {
                try {
                    require_once $mindPath;
                    mind_record_pixl_success($row, $part, $result, $index + 1, $chunkCount);
                } catch (Throwable $mindError) {
                    error_log('mind stats3 logging failed: ' . $mindError->getMessage());
                }
            }
        }
    }, $row);
}
