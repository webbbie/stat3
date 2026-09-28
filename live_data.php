<?php
declare(strict_types=1);

// Read-only UA reporting. No collector, schema repair or notification sender runs here.
function ua_query(PDO $pdo, string $sql, array $params = []): array
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function ua_windows(int $now): array
{
    $local = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone('Europe/Berlin'));
    return [
        'day' => $local->setTime(0, 0)->getTimestamp(),
        // Epoch subtraction preserves the correct occurrence of the repeated DST hour.
        'hour' => $now - (int)$local->format('i') * 60 - (int)$local->format('s'),
        '24h' => $now - 86400,
    ];
}

function ua_campaign_key(string $source, string $campaign): string
{
    return json_encode([$source, $campaign], JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
}

function ua_campaign(string $source, string $campaign): array
{
    return [
        'source' => $source, 'campaign' => $campaign,
        'users_day' => 0, 'impressions_day' => 0, 'users_hour' => 0, 'impressions_hour' => 0,
        'pages_day' => 0, 'pushes_day' => 0, 'users_24h' => 0, 'impressions_24h' => 0,
        'pages_24h' => 0, 'pushes_24h' => 0,
    ];
}

function ua_url_campaign(string $url): array
{
    $query = parse_url($url, PHP_URL_QUERY);
    $params = [];
    if (is_string($query)) parse_str($query, $params);
    $pick = static function (array $keys) use ($params): string {
        foreach ($keys as $key) {
            if (isset($params[$key]) && is_string($params[$key]) && trim($params[$key]) !== '') return trim($params[$key]);
        }
        return '';
    };
    return [$pick(['utm_source', 'campaignsource', 'network', 'source']), $pick(['utm_campaign', 'campaignname', 'campaign', 'campaign_id', 'cid'])];
}

function ua_notification_rows(PDO $pdo, string $table, int $since, int $until, array &$warnings): array
{
    $params = [gmdate('Y-m-d H:i:s', $since), gmdate('Y-m-d H:i:s', $until)];
    $messages = [];
    try {
        // Mind records only successful API confirmations; each multipart row is one sent message.
        // Ignore imported STAT4 copies: the original notification is merged below by milestone.
        $rows = ua_query($pdo, "SELECT n.source,n.source_event_id,n.part_index,n.visitor_hash,n.sent_at,
                COALESCE(NULLIF(e.page_url,''), n.url) AS attribution_url
            FROM mind_notifications n
            LEFT JOIN `$table` e ON n.source='stats3' AND e.id=CAST(n.source_event_id AS UNSIGNED)
            WHERE n.sent_at >= ? AND n.sent_at < ? AND n.source <> 'stat4-history'", $params);
        foreach ($rows as $row) {
            $key = $row['source'] . ':' . $row['source_event_id'] . ':' . $row['part_index'];
            $messages[$key] = $row + ['key' => $key];
        }
    } catch (PDOException $error) {
        if ((int)($error->errorInfo[1] ?? 0) !== 1146) throw $error;
        $warnings[] = 'Das Mind-Versandprotokoll fehlt. Pushover-Zahlen enthalten derzeit nur bestätigte STAT4-Sendungen.';
    }
    try {
        foreach (ua_query($pdo, "SELECT visitor_hash,pageview_milestone,sent_at FROM stat4_notifications
            WHERE status='sent' AND sent_at >= ? AND sent_at < ?", $params) as $row) {
            $key = 'stat4:' . $row['visitor_hash'] . ':' . $row['pageview_milestone'] . ':1';
            $messages[$key] ??= $row + ['key' => $key, 'source' => 'stat4', 'attribution_url' => ''];
        }
    } catch (PDOException $error) {
        if ((int)($error->errorInfo[1] ?? 0) !== 1146) throw $error;
        $warnings[] = 'Das STAT4-Versandprotokoll fehlt. Pushover-Zahlen stammen derzeit nur aus Mind.';
    }
    $session = $pdo->prepare("SELECT s.utm_source,s.utm_campaign FROM stat4_events e
        JOIN stat4_sessions s ON s.session_id=e.session_id
        WHERE e.visitor_hash=? AND e.event_type='pageview' AND e.occurred_at <= ?
        ORDER BY e.occurred_at DESC,e.id DESC LIMIT 1");
    foreach ($messages as &$row) {
        $row['timestamp'] = (new DateTimeImmutable($row['sent_at'], new DateTimeZone('UTC')))->getTimestamp();
        $attribution = false;
        if ($row['source'] === 'stat4') {
            $session->execute([$row['visitor_hash'], $row['sent_at']]);
            $attribution = $session->fetch(PDO::FETCH_ASSOC);
        }
        if ($attribution) {
            $row['campaign_source'] = $attribution['utm_source'];
            $row['campaign_name'] = $attribution['utm_campaign'];
        } else {
            [$source, $campaign] = ua_url_campaign((string)$row['attribution_url']);
            // A Stats3 hash is not a STAT4 visitor ID. Do not invent a cross-tracker match.
            $row['campaign_source'] = $source !== '' || $campaign !== '' ? $source : '(nicht zuordenbar)';
            $row['campaign_name'] = $source !== '' || $campaign !== '' ? $campaign : '(nicht zuordenbar)';
        }
    }
    unset($row);
    return array_values($messages);
}

function ua_live_snapshot(PDO $pdo, int $now, string $table = 'pixl_events'): array
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) throw new InvalidArgumentException('Invalid event table');
    $windows = ua_windows($now);
    $since = min($windows['day'], $windows['24h']);
    $untilSql = gmdate('Y-m-d H:i:s', $now);
    $startSql = gmdate('Y-m-d H:i:s', $since);
    $warnings = [];
    $pdo->exec("SET time_zone = '+00:00'");
    $hasUrl = (bool)$pdo->query("SHOW COLUMNS FROM stat4_events LIKE 'page_url'")->fetch();
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('START TRANSACTION READ ONLY');
    try {
        $from = "FROM stat4_events e JOIN stat4_sessions s ON s.session_id=e.session_id
            JOIN stat4_visitors v ON v.visitor_hash=e.visitor_hash";
        $human = "e.event_type='pageview' AND s.is_bot=0 AND v.is_bot=0";
        $page = "CAST(CONCAT(LENGTH(e.hostname),':',LOWER(e.hostname),SUBSTRING_INDEX(SUBSTRING_INDEX(e.path,'?',1),'#',1)) AS BINARY)";
        $measures = [];
        $measureParams = [];
        foreach ($windows as $key => $start) {
            $date = gmdate('Y-m-d H:i:s', $start);
            $measures[] = "COUNT(DISTINCT CASE WHEN e.occurred_at >= ? THEN NULLIF(e.visitor_hash,'') END) AS users_$key";
            $measures[] = "COALESCE(SUM(e.occurred_at >= ?),0) AS impressions_$key";
            $measures[] = "COUNT(DISTINCT CASE WHEN e.occurred_at >= ? THEN $page END) AS pages_$key";
            array_push($measureParams, $date, $date, $date);
        }
        $metricsSql = implode(',', $measures);
        $filter = " $from WHERE $human AND e.occurred_at >= ? AND e.occurred_at < ?";
        $params = array_merge($measureParams, [$startSql, $untilSql]);
        $totals = array_map('intval', ua_query($pdo, "SELECT $metricsSql $filter", $params)[0]);
        $campaigns = [];
        foreach (ua_query($pdo, "SELECT CAST(s.utm_source AS BINARY) AS utm_source,CAST(s.utm_campaign AS BINARY) AS utm_campaign,$metricsSql $filter
            GROUP BY CAST(s.utm_source AS BINARY),CAST(s.utm_campaign AS BINARY)", $params) as $row) {
            $group = ua_campaign($row['utm_source'], $row['utm_campaign']);
            foreach ($group as $key => $value) if (array_key_exists($key, $row)) $group[$key] = (int)$row[$key];
            $campaigns[ua_campaign_key($row['utm_source'], $row['utm_campaign'])] = $group;
        }
        // Exactly 24 consecutive 60-minute intervals, covering the rolling 24 hours.
        $hourly = [];
        for ($i = 0; $i < 24; $i++) {
            $hourly[$i] = ['start' => $windows['24h'] + $i * 3600, 'end' => $windows['24h'] + ($i + 1) * 3600, 'users' => 0, 'impressions' => 0];
        }
        foreach (ua_query($pdo, "SELECT FLOOR((UNIX_TIMESTAMP(e.occurred_at)-?)/3600) AS bucket,
            COUNT(DISTINCT NULLIF(e.visitor_hash,'')) AS users,COUNT(*) AS impressions
            $from WHERE $human AND e.occurred_at >= ? AND e.occurred_at < ? GROUP BY bucket",
            [$windows['24h'], gmdate('Y-m-d H:i:s', $windows['24h']), $untilSql]) as $row) {
            $index = (int)$row['bucket'];
            if (isset($hourly[$index])) {
                $hourly[$index]['users'] = (int)$row['users'];
                $hourly[$index]['impressions'] = (int)$row['impressions'];
            }
        }
        // Read the whole recent trace again: retries and late commits cannot fall through an ID cursor.
        $pulseStart = intdiv($now - 120, 5) * 5;
        $heartbeat = [];
        for ($stamp = $pulseStart; $stamp < $now; $stamp += 5) {
            $heartbeat[$stamp] = ['start' => $stamp, 'users' => 0, 'impressions' => 0, 'pushes' => 0];
        }
        foreach (ua_query($pdo, "SELECT FLOOR(UNIX_TIMESTAMP(e.occurred_at)/5)*5 AS bucket,COUNT(*) AS impressions,
            SUM(NOT EXISTS (SELECT 1 FROM stat4_events earlier WHERE earlier.visitor_hash=e.visitor_hash
                AND earlier.event_type='pageview' AND earlier.id<e.id)) AS users
            $from WHERE $human AND e.occurred_at >= ? AND e.occurred_at < ? GROUP BY bucket",
            [gmdate('Y-m-d H:i:s', $pulseStart), $untilSql]) as $row) {
            $stamp = (int)$row['bucket'];
            if (isset($heartbeat[$stamp])) {
                $heartbeat[$stamp]['users'] = (int)$row['users'];
                $heartbeat[$stamp]['impressions'] = (int)$row['impressions'];
            }
        }
        $urlColumn = $hasUrl ? 'e.page_url' : 'NULL';
        $latest = ua_query($pdo, "SELECT e.id,UNIX_TIMESTAMP(e.occurred_at) AS timestamp,
            $urlColumn AS url,e.hostname,e.path $from WHERE $human AND e.occurred_at < ?
            ORDER BY e.occurred_at DESC,e.id DESC LIMIT 5", [$untilSql]);
        $legacyUrls = false;
        foreach ($latest as &$row) {
            $row['id'] = (string)$row['id'];
            $row['timestamp'] = (int)$row['timestamp'];
            $row['complete_url'] = is_string($row['url']) && preg_match('~^https?://~i', $row['url']) === 1;
            if (!$row['complete_url']) {
                // Historical records do not establish a scheme or port. Display only known data.
                $row['url'] = $row['hostname'] . $row['path'];
                $legacyUrls = true;
            }
            unset($row['hostname'], $row['path']);
        }
        unset($row);
        if ($legacyUrls || !$hasUrl) $warnings[] = 'Ältere Seitenaufrufe enthalten nur Host und Pfad. Vollständige URLs werden mit dem aktualisierten STAT4-Collector ab dem nächsten Aufruf gespeichert.';
        $messages = ua_notification_rows($pdo, $table, $since, $now, $warnings);
        $totals['pushes_day'] = $totals['pushes_24h'] = 0;
        $recentPushes = [];
        foreach ($messages as $message) {
            $key = ua_campaign_key($message['campaign_source'], $message['campaign_name']);
            $campaigns[$key] ??= ua_campaign($message['campaign_source'], $message['campaign_name']);
            foreach (['day', '24h'] as $window) {
                if ($message['timestamp'] >= $windows[$window]) {
                    $totals['pushes_' . $window]++;
                    $campaigns[$key]['pushes_' . $window]++;
                }
            }
            $bucket = intdiv($message['timestamp'], 5) * 5;
            if (isset($heartbeat[$bucket])) {
                $heartbeat[$bucket]['pushes']++;
                // Opaque IDs suffice for detecting new confirmed sends in the browser.
                $recentPushes[] = ['id' => hash('sha256', $message['key']), 'timestamp' => $message['timestamp']];
            }
        }
        $pdo->commit();
        $campaigns = array_values($campaigns);
        usort($campaigns, static fn(array $a, array $b): int => $b['impressions_24h'] <=> $a['impressions_24h']
            ?: strcmp($a['source'], $b['source']) ?: strcmp($a['campaign'], $b['campaign']));
        return ['ok' => true, 'generated_at' => $now, 'timezone' => 'Europe/Berlin', 'refresh_seconds' => 5,
            'windows' => $windows, 'totals' => $totals, 'hourly' => $hourly, 'heartbeat' => array_values($heartbeat),
            'recent_pushes' => $recentPushes, 'latest' => $latest, 'campaigns' => $campaigns, 'warnings' => $warnings];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
