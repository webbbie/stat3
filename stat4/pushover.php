<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once dirname(__DIR__) . '/pixl_pushover.php';

function stat4_pushover_enabled(): bool
{
    $push = stat4_config()['pushover'] ?? [];
    return trim((string) ($push['application_token'] ?? '')) !== ''
        && trim((string) ($push['user_key'] ?? '')) !== '';
}

function stat4_pushover_ensure_table(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS stat4_notifications (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      visitor_hash CHAR(64) NOT NULL,
      pageview_milestone INT UNSIGNED NOT NULL,
      status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
      response_text VARCHAR(1000) NOT NULL DEFAULT '',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      sent_at DATETIME NULL,
      UNIQUE KEY uq_stat4_notification_milestone (visitor_hash, pageview_milestone),
      INDEX idx_stat4_notification_status (status, created_at),
      CONSTRAINT fk_stat4_notification_visitor FOREIGN KEY (visitor_hash) REFERENCES stat4_visitors(visitor_hash) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function stat4_pushover_message_chunks(string $message, int $limit = 1000): array
{
    if ($message === '') return [''];
    $chunks = [];
    while (mb_strlen($message) > $limit) {
        $part = mb_substr($message, 0, $limit);
        $break = mb_strrpos($part, "\n");
        if ($break !== false && $break > (int) ($limit * .55)) $part = mb_substr($part, 0, $break);
        $chunks[] = $part;
        $message = ltrim(mb_substr($message, mb_strlen($part)));
    }
    if ($message !== '') $chunks[] = $message;
    return $chunks;
}

function stat4_pushover_post(array $payload, ?array $settings = null): array
{
    $chunks = stat4_pushover_message_chunks((string) ($payload['message'] ?? ''));
    $results = [];
    foreach ($chunks as $index => $chunk) {
        $part = $payload; $part['message'] = $chunk;
        if (count($chunks) > 1) $part['title'] = ($payload['title'] ?? 'STAT4 Besucher') . ' (' . ($index + 1) . '/' . count($chunks) . ')';
        $result = stat4_pushover_post_single($part, $settings);
        $results[] = $result;
        if (!$result['ok']) return ['ok'=>false,'error'=>$result['error'] ?? 'Teilnachricht fehlgeschlagen.','parts'=>$results];
    }
    return ['ok'=>true,'parts'=>$results,'request'=>$results[count($results)-1]['request'] ?? null];
}

function stat4_pushover_post_single(array $payload, ?array $settings = null): array
{
    $settings ??= stat4_config()['pushover'] ?? [];
    $token = trim((string) ($settings['application_token'] ?? ''));
    $user = trim((string) ($settings['user_key'] ?? ''));
    if ($token === '' || $user === '') return ['ok'=>false,'error'=>'Application Token oder User Key fehlt.'];
    $priority = max(-2, min(2, (int) ($settings['priority'] ?? 0)));
    $data = [
        'token'=>$token, 'user'=>$user,
        'title'=>mb_substr((string) ($payload['title'] ?? 'STAT4 Besucher'), 0, 250),
        'message'=>mb_substr((string) ($payload['message'] ?? ''), 0, 1024),
        'priority'=>$priority,
    ];
    $sound = trim((string) ($settings['sound'] ?? ''));
    if ($sound !== '') $data['sound'] = $sound;
    if (!empty($payload['url'])) { $data['url'] = mb_substr((string) $payload['url'], 0, 512); $data['url_title'] = 'Seite öffnen'; }
    // Pushover verlangt bei Notfall-Prioritaet retry und expire.
    if ($priority === 2) { $data['retry'] = 60; $data['expire'] = 3600; }
    $timeout = max(1, min(60, (int) ($settings['timeout'] ?? 8)));
    $body = http_build_query($data, '', '&', PHP_QUERY_RFC3986);
    if (function_exists('curl_init')) {
        $ch = curl_init('https://api.pushover.net/1/messages.json');
        curl_setopt_array($ch, [CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>$timeout,CURLOPT_TIMEOUT=>$timeout,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded']]);
        $response = curl_exec($ch); $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $curlError = curl_error($ch); curl_close($ch);
        if ($response === false) return ['ok'=>false,'error'=>$curlError ?: 'Pushover-Verbindung fehlgeschlagen.'];
    } else {
        $context = stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/x-www-form-urlencoded\r\n",'content'=>$body,'timeout'=>$timeout,'ignore_errors'=>true]]);
        $response = @file_get_contents('https://api.pushover.net/1/messages.json', false, $context);
        $http = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int) $m[1] : 0;
        if ($response === false) return ['ok'=>false,'error'=>'Pushover-Verbindung fehlgeschlagen.'];
    }
    $decoded = json_decode((string) $response, true);
    $ok = $http === 200 && is_array($decoded) && (int) ($decoded['status'] ?? 0) === 1;
    return ['ok'=>$ok,'http'=>$http,'request'=>$decoded['request'] ?? null,'error'=>$ok ? null : implode(' ', (array) ($decoded['errors'] ?? ['Pushover hat die Nachricht abgelehnt.']))];
}

function stat4_pushover_visit_payload(PDO $pdo, string $visitorHash, string $sessionId, int $pageviews): array
{
    $stmt = $pdo->prepare("SELECT path, hostname, title, referrer, screen_size, inner_size, language, country, browser, browser_version, os, os_version, device, occurred_at FROM stat4_events WHERE visitor_hash=? AND event_type='pageview' ORDER BY occurred_at DESC LIMIT 50");
    $stmt->execute([$visitorHash]);
    $pages = [];
    $seenPaths = [];
    foreach ($stmt->fetchAll() as $page) {
        $normalizedPath = explode('?', (string) $page['path'], 2)[0] ?: '/';
        $pathKey = strtolower((string) $page['hostname']) . "\n" . $normalizedPath;
        if (isset($seenPaths[$pathKey])) continue;
        $seenPaths[$pathKey] = true;
        $page['path'] = $normalizedPath;
        $pages[] = $page;
        if (count($pages) === 3) break;
    }
    $stmt = $pdo->prepare('SELECT clicks, active_seconds, max_level, started_at FROM stat4_sessions WHERE session_id=?');
    $stmt->execute([$sessionId]); $session = $stmt->fetch() ?: [];
    $latest = $pages[0] ?? [];
    $paths = implode(' → ', array_reverse(array_map(static function (array $row): string {
        $path = explode('?', (string) $row['path'], 2)[0];
        return $path !== '' ? $path : '/';
    }, $pages)));
    $url = !empty($latest['hostname']) ? 'https://' . $latest['hostname'] . ($latest['path'] ?? '/') : '';
    $pushoverCountry = (string)($latest['country'] ?? '');
    if (!pixl_geoip_pushover_country_enabled()) {
        $pushoverCountry = stat4_infer_country((string)($latest['language'] ?? ''), '');
    }
    $lines = [
        "Seiten gelesen: {$pageviews}",
        'Letzte Pfade: ' . ($paths ?: '–'),
        'Besucher: ' . substr($visitorHash, 0, 12),
        'Session: ' . substr($sessionId, 0, 12),
        'Browser: ' . trim(($latest['browser'] ?? '–') . ' ' . ($latest['browser_version'] ?? '')),
        'OS: ' . trim(($latest['os'] ?? '–') . ' ' . ($latest['os_version'] ?? '')),
        'Device: ' . ($latest['device'] ?? '–'),
        'Screen / innen: ' . ($latest['screen_size'] ?? '–') . ' / ' . ($latest['inner_size'] ?? '–'),
        'Sprache: ' . stat4_language_name((string) ($latest['language'] ?? '')),
        'Land: ' . stat4_country_name($pushoverCountry),
        'Klicks / aktiv: ' . ($session['clicks'] ?? 0) . ' / ' . ($session['active_seconds'] ?? 0) . 's',
        'Max Level: ' . ($session['max_level'] ?? 0) . '%',
        'Referrer: ' . ($latest['referrer'] ?: 'Direkt'),
        'Zeit: ' . ($latest['occurred_at'] ?? gmdate('Y-m-d H:i:s')) . ' UTC',
    ];
    return ['title'=>"STAT4 · {$pageviews} Seiten · " . ($latest['hostname'] ?? 'Besucher'),'message'=>implode("\n", $lines),'url'=>$url];
}

function stat4_pushover_send_milestone(PDO $pdo, string $visitorHash, string $sessionId, int $pageviews): void
{
    if (!stat4_pushover_enabled()) return;
    $result = null;
    $payload = stat4_pushover_visit_payload($pdo, $visitorHash, $sessionId, $pageviews);
    try {
        $dispatched = pixl_pushover_run_throttled(static function () use ($payload, &$result): void {
            $result = stat4_pushover_post($payload);
            if (empty($result['ok'])) {
                throw new RuntimeException((string) ($result['error'] ?? 'STAT4-Pushover-Versand fehlgeschlagen.'));
            }
        });
        if (!$dispatched) {
            $result = [
                'ok' => false,
                'suppressed' => true,
                'error' => pixl_pushover_reading_score_only()
                    ? 'Durch den ReadingScore-Filter unterdrueckt: STAT4 liefert keinen ReadingScore; keine Nachholung.'
                    : 'Durch die gemeinsame Pushover-Sperrzeit oder das Stundenlimit unterdrueckt; keine Nachholung.',
            ];
        }
    } catch (Throwable $pushError) {
        $result ??= ['ok' => false, 'error' => $pushError->getMessage()];
    }
    if (!empty($result['ok'])) {
        try {
            require_once dirname(__DIR__) . '/mind/bootstrap.php';
            $stmt = $pdo->prepare("SELECT hostname,path,browser,os,country,language FROM stat4_events WHERE visitor_hash=? AND event_type='pageview' ORDER BY occurred_at DESC LIMIT 1");
            $stmt->execute([$visitorHash]);
            $latest = $stmt->fetch() ?: [];
            mind_record_success([
                'source'=>'stat4', 'source_event_id'=>$visitorHash . ':' . $pageviews,
                'visitor_hash'=>$visitorHash, 'pushover_request'=>$result['request'] ?? '',
                'title'=>$payload['title'] ?? '', 'message'=>$payload['message'] ?? '', 'url'=>$payload['url'] ?? '',
                'hostname'=>$latest['hostname'] ?? '', 'path'=>$latest['path'] ?? '',
                'browser'=>$latest['browser'] ?? '', 'os'=>$latest['os'] ?? '', 'country'=>$latest['country'] ?? '',
                'language'=>$latest['language'] ?? '', 'ip'=>stat4_client_ip(),
            ]);
        } catch (Throwable $mindError) {
            error_log('mind stat4 logging failed: ' . $mindError->getMessage());
        }
    }
    $stmt = $pdo->prepare('UPDATE stat4_notifications SET status=?, response_text=?, sent_at=IF(?,UTC_TIMESTAMP(),sent_at) WHERE visitor_hash=? AND pageview_milestone=?');
    $stmt->execute([$result['ok']?'sent':'failed', mb_substr(json_encode($result, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),0,1000), $result['ok']?1:0, $visitorHash, $pageviews]);
}
