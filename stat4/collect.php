<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/pushover.php';

stat4_origin_headers();
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') stat4_json(['ok' => false, 'error' => 'POST required'], 405);

$input = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($input)) stat4_json(['ok' => false, 'error' => 'Invalid JSON'], 400);

$cut = static fn(mixed $v, int $n): string => mb_substr(trim((string) $v), 0, $n);
$uuid = $cut($input['eventId'] ?? '', 36);
$sessionId = $cut($input['sessionId'] ?? '', 36);
$clientId = $cut($input['visitorId'] ?? '', 128);
$receivedAt = (int) floor(microtime(true) * 1000);
$occurredAt = array_key_exists('occurredAt', $input)
    ? filter_var($input['occurredAt'], FILTER_VALIDATE_INT)
    : $receivedAt;
// A queued snapshot lives for 15 minutes; allow five minutes of browser clock skew.
$invalidEventTime = $occurredAt === false || $occurredAt < $receivedAt - 1200000 || $occurredAt > $receivedAt + 300000;
$invalidVisitorWindow = false;
if (array_key_exists('visitorStarted', $input)) {
    $visitorStarted = filter_var($input['visitorStarted'], FILTER_VALIDATE_INT);
    $visitorAge = $visitorStarted === false || $occurredAt === false ? PHP_INT_MAX : $occurredAt - $visitorStarted;
    // Fünf Minuten Toleranz für eine leicht vorgehende Client-Uhr.
    $minimumAge = array_key_exists('occurredAt', $input) ? 0 : -300000;
    $invalidVisitorWindow = $visitorAge < $minimumAge || $visitorAge >= 86400000;
}
if (!preg_match('/^[a-f0-9-]{20,36}$/i', $uuid) || !preg_match('/^[a-f0-9-]{20,36}$/i', $sessionId) || $clientId === '' || $invalidVisitorWindow || $invalidEventTime) {
    stat4_json(['ok' => false, 'error' => 'Missing event, session or visitor id'], 422);
}

$eventType = in_array($input['type'] ?? '', ['pageview','click','heartbeat','leave'], true) ? $input['type'] : 'pageview';
$uaString = $cut($_SERVER['HTTP_USER_AGENT'] ?? ($input['userAgent'] ?? ''), 1024);
$ua = stat4_ua($uaString);
$visitorHash = stat4_hash($clientId);
$clientIp = stat4_client_ip();
$ipHash = stat4_hash($clientIp);
$ipPrefix = stat4_ip_prefix($clientIp);
$now = gmdate('Y-m-d H:i:s', (int) floor($occurredAt / 1000));
$pageUrl = stat4_page_url($input['url'] ?? '');
$url = parse_url($cut($input['url'] ?? '', 4096));
$hostname = $cut($url['host'] ?? '', 255);
$path = $cut(($url['path'] ?? '/') . (isset($url['query']) ? '?' . $url['query'] : ''), 2048);
$browserCountry = strtoupper($cut($input['country'] ?? '', 8));
if (!preg_match('/^[A-Z]{2}$/', $browserCountry) || $browserCountry === 'XX') {
    $browserCountry = stat4_infer_country((string) ($input['language'] ?? ''), (string) ($input['timezone'] ?? ''));
}
$proxyCountry = pixl_geoip_trust_proxy_headers()
    ? strtoupper($cut($_SERVER['HTTP_CF_IPCOUNTRY'] ?? '', 8))
    : '';
if (!preg_match('/^[A-Z]{2}$/', $proxyCountry) || $proxyCountry === 'XX') {
    $proxyCountry = '';
}
$localCountry = pixl_geoip_country_code($clientIp);
$country = $localCountry !== '' ? $localCountry : ($proxyCountry !== '' ? $proxyCountry : $browserCountry);
$level = max(0, min(100, (int) ($input['level'] ?? 0)));
$active = max(0, min(300, (int) ($input['activeSeconds'] ?? 0)));
$utm = is_array($input['utm'] ?? null) ? $input['utm'] : [];

$pdo = stat4_db();
$pushMilestone = 0;
try {
    stat4_ensure_ip_prefix_columns($pdo);
    stat4_ensure_page_url_column($pdo);
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("INSERT INTO stat4_visitors (visitor_hash, first_seen, last_seen, first_ip_hash, first_ip_prefix, last_ip_prefix, is_bot) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE first_seen=LEAST(first_seen,VALUES(first_seen)), last_seen=GREATEST(last_seen,VALUES(last_seen)), first_ip_prefix=IF(first_ip_prefix='', VALUES(first_ip_prefix), first_ip_prefix), last_ip_prefix=IF(VALUES(last_ip_prefix)<>'', VALUES(last_ip_prefix), last_ip_prefix), is_bot=GREATEST(is_bot, VALUES(is_bot))");
    $stmt->execute([$visitorHash, $now, $now, $ipHash, $ipPrefix, $ipPrefix, (int) $ua['bot']]);

    $stmt = $pdo->prepare('INSERT INTO stat4_sessions (session_id, visitor_hash, started_at, last_seen, referrer, utm_source, utm_medium, utm_campaign, utm_term, utm_content, is_bot) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE started_at=LEAST(started_at,VALUES(started_at)), last_seen=GREATEST(last_seen,VALUES(last_seen))');
    $stmt->execute([$sessionId, $visitorHash, $now, $now, $cut($input['referrer'] ?? '', 2048), $cut($utm['source'] ?? '',191), $cut($utm['medium'] ?? '',191), $cut($utm['campaign'] ?? '',191), $cut($utm['term'] ?? '',191), $cut($utm['content'] ?? '',191), (int) $ua['bot']]);

    $stmt = $pdo->prepare('INSERT IGNORE INTO stat4_events (event_uuid, session_id, visitor_hash, occurred_at, event_type, hostname, path, page_url, title, referrer, target, screen_size, inner_size, language, country, browser, browser_version, os, os_version, device, user_agent, level, active_seconds) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$uuid,$sessionId,$visitorHash,$now,$eventType,$hostname,$path,$pageUrl,$cut($input['title'] ?? '',512),$cut($input['referrer'] ?? '',2048),$cut($input['target'] ?? '',2048),$cut($input['screen'] ?? '',32),$cut($input['inner'] ?? '',32),$cut($input['language'] ?? '',32),$country,$ua['browser'],$ua['browserVersion'],$ua['os'],$ua['osVersion'],$ua['device'],$uaString,$level,$active]);
    $inserted = $stmt->rowCount() === 1;
    if ($inserted) {
        $page = 0;
        if ($eventType === 'pageview') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM stat4_events WHERE visitor_hash=? AND hostname=? AND SUBSTRING_INDEX(path,'?',1)=? AND event_type='pageview'");
            $stmt->execute([$visitorHash, $hostname, explode('?', $path, 2)[0]]);
            // Reloads und derselbe Pfad mit anderen Query-Strings zählen nicht erneut.
            $page = (int) $stmt->fetchColumn() === 1 ? 1 : 0;
        }
        $click = $eventType === 'click' ? 1 : 0;
        // MySQL evaluates assignments from left to right: these totals already include this event.
        $stmt = $pdo->prepare('UPDATE stat4_sessions SET last_seen=GREATEST(last_seen,?), pageviews=pageviews+?, clicks=clicks+?, active_seconds=active_seconds+?, max_level=GREATEST(max_level, ?), is_bounce=IF(pageviews > 1 OR clicks > 0 OR active_seconds >= 15,0,1) WHERE session_id=?');
        $stmt->execute([$now,$page,$click,$active,$level,$sessionId]);
        if ($eventType === 'pageview' && stat4_pushover_enabled()) {
            $stmt = $pdo->prepare("SELECT COUNT(DISTINCT CONCAT(LENGTH(hostname),':',hostname,SUBSTRING_INDEX(path,'?',1))) FROM stat4_events WHERE visitor_hash=? AND event_type='pageview'");
            $stmt->execute([$visitorHash]);
            $visitorPageviews = (int) $stmt->fetchColumn();
            if ($visitorPageviews > 0 && $visitorPageviews % 5 === 0) {
                try {
                    $stmt = $pdo->prepare("INSERT IGNORE INTO stat4_notifications (visitor_hash,pageview_milestone,status) VALUES (?,?,'pending')");
                    $stmt->execute([$visitorHash,$visitorPageviews]);
                    if ($stmt->rowCount() === 1) $pushMilestone = $visitorPageviews;
                } catch (Throwable $notificationError) {
                    // Tracking darf bei einer noch nicht migrierten Notification-Tabelle nicht ausfallen.
                    error_log('stat4 notification queue: ' . $notificationError->getMessage());
                }
            }
        }
    }
    $pdo->commit();
    if ($pushMilestone > 0) {
        try { stat4_pushover_send_milestone($pdo, $visitorHash, $sessionId, $pushMilestone); }
        catch (Throwable $pushError) { error_log('stat4 pushover: ' . $pushError->getMessage()); }
    }
    stat4_json(['ok' => true, 'stored' => $inserted]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('stat4 collect: ' . $e->getMessage());
    stat4_json(['ok' => false, 'error' => 'Storage failed'], 500);
}
