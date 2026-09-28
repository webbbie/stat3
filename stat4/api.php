<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
stat4_require_admin(true);

try {
    $pdo = stat4_db();
    $range = (string) ($_GET['range'] ?? '1day');
    // Alte Links bleiben gueltig; die sichtbaren Filter verwenden die neuen eindeutigen Namen.
    if ($range === '60m') $range = '60min';
    if ($range === '1d') $range = '1day';
    $rollingDays = [];
    for ($day = 1; $day <= 7; $day++) $rollingDays['last' . $day . 'd'] = $day;
    if ($range === '1day') {
        $berlin = new DateTimeZone('Europe/Berlin');
        $since = (new DateTimeImmutable('today', $berlin))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } elseif ($range === '60min') {
        $since = gmdate('Y-m-d H:i:s', time() - 3600);
    } elseif ($range === '24h') {
        $since = gmdate('Y-m-d H:i:s', time() - 86400);
    } elseif (isset($rollingDays[$range])) {
        $since = gmdate('Y-m-d H:i:s', time() - ($rollingDays[$range] * 86400));
    } else {
        $range = '1day';
        $berlin = new DateTimeZone('Europe/Berlin');
        $since = (new DateTimeImmutable('today', $berlin))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
    $q = static function (string $sql, array $params = []) use ($pdo): array {
        $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchAll();
    };
    $one = static function (string $sql, array $params = []) use ($pdo): array {
        $s = $pdo->prepare($sql); $s->execute($params); return $s->fetch() ?: [];
    };
    $breakdown = static function (string $column, string $since, int $limit = 8) use ($q): array {
        $allowed = ['browser','os','device','country','screen_size','inner_size','language'];
        if (!in_array($column, $allowed, true)) return [];
        return $q("SELECT COALESCE(NULLIF($column,''),'Unbekannt') label, COUNT(DISTINCT visitor_hash) value FROM stat4_events WHERE occurred_at >= ? AND event_type='pageview' GROUP BY label ORDER BY value DESC LIMIT $limit", [$since]);
    };

    $visitorActivitySql = "SELECT
            e.visitor_hash,
            MAX(v.is_bot) is_bot,
            COUNT(DISTINCT CASE WHEN e.event_type='pageview' THEN CONCAT(LENGTH(e.hostname),':',e.hostname,SUBSTRING_INDEX(e.path,'?',1)) END) unique_pages,
            COUNT(DISTINCT CASE WHEN e.event_type='click' THEN NULLIF(e.target,'') END) unique_clicks,
            MAX(e.level) max_level,
            SUM(e.active_seconds) active_seconds
        FROM stat4_events e
        JOIN stat4_visitors v ON v.visitor_hash=e.visitor_hash
        WHERE e.occurred_at>=?
        GROUP BY e.visitor_hash";
    $metrics = $one("SELECT
            COUNT(*) visitors,
            COALESCE(SUM(is_bot=1),0) bots,
            COALESCE(SUM(unique_pages=1),0) bounce_visitors,
            COALESCE(SUM(unique_pages),0) unique_pages,
            COALESCE(SUM(unique_pages>=4),0) visitors4
        FROM ($visitorActivitySql) visitor_activity
        WHERE unique_pages>0", [$since]);
    $visitors = (int) ($metrics['visitors'] ?? 0);
    $metrics['bounce_rate'] = $visitors ? round((int) $metrics['bounce_visitors'] / $visitors * 100, 1) : 0;
    $metrics['pages_per_visitor'] = $visitors ? round((int) $metrics['unique_pages'] / $visitors, 2) : 0;

    $bucket = in_array($range, ['24h','1day','last1d'], true) ? '%Y-%m-%d %H:00' : '%Y-%m-%d';
    if ($range === '60min') $bucket = '%Y-%m-%d %H:%i';
    $trend = $q("SELECT DATE_FORMAT(occurred_at,'$bucket') label, COUNT(DISTINCT visitor_hash) visitors FROM stat4_events WHERE occurred_at>=? AND event_type='pageview' GROUP BY label ORDER BY label", [$since]);
    // Einzige Ausnahme von der Unique-Zählung: Top Pfade zeigt alle echten Aufrufe.
    $pathRows = $q("SELECT hostname, SUBSTRING_INDEX(path,'?',1) path, COUNT(*) pageviews FROM stat4_events WHERE occurred_at>=? AND event_type='pageview' GROUP BY hostname, SUBSTRING_INDEX(path,'?',1)", [$since]);
    $pathGroups = [];
    foreach ($pathRows as $pathRow) {
        $basePath = (string) $pathRow['path'];
        if ($basePath === '') $basePath = '/';
        if ($basePath[0] !== '/') $basePath = '/' . $basePath;
        $hostname = strtolower(trim((string) $pathRow['hostname']));
        $topUrl = $hostname !== '' ? 'https://' . $hostname . $basePath : $basePath;
        $pathGroups[$topUrl] ??= ['label'=>$topUrl,'pageviews'=>0];
        $pathGroups[$topUrl]['pageviews'] += (int) $pathRow['pageviews'];
    }
    $paths = array_values($pathGroups);
    usort($paths, static fn(array $a, array $b): int => $b['pageviews'] <=> $a['pageviews']);
    $paths = array_slice($paths, 0, 12);
    $uas = $q("SELECT user_agent label, COUNT(DISTINCT visitor_hash) value FROM stat4_events WHERE occurred_at>=? AND event_type='pageview' GROUP BY user_agent ORDER BY value DESC LIMIT 10", [$since]);
    $sessionSummary = $one("SELECT
            COUNT(*) visitors,
            COALESCE(SUM(unique_pages=1),0) single_page_visitors,
            COALESCE(SUM(unique_pages>1),0) multipage_visitors,
            COALESCE(SUM(unique_clicks>0),0) clicking_visitors,
            COALESCE(SUM(unique_pages>=4),0) visitors4,
            ROUND(COALESCE(AVG(unique_pages),0),2) avg_unique_pages,
            COALESCE(MAX(max_level),0) max_level
        FROM ($visitorActivitySql) visitor_activity
        WHERE unique_pages>0", [$since]);
    $levels = $q("SELECT
            CONCAT(FLOOR(max_level/10)*10,'–',LEAST(100,FLOOR(max_level/10)*10+9),'%') label,
            COUNT(*) visitors,
            ROUND(AVG(active_seconds),1) avg_seconds
        FROM ($visitorActivitySql) visitor_activity
        WHERE unique_pages>0
        GROUP BY FLOOR(max_level/10)
        ORDER BY FLOOR(max_level/10) DESC", [$since]);
    $campaigns = $q("SELECT
            COALESCE(NULLIF(utm_campaign,''),'Ohne Kampagne') campaign,
            COALESCE(NULLIF(utm_source,''),'Direkt') source,
            COALESCE(NULLIF(utm_medium,''),'–') medium,
            COUNT(DISTINCT visitor_hash) visitors,
            COUNT(DISTINCT CASE WHEN clicks>0 THEN visitor_hash END) clicking_visitors
        FROM stat4_sessions
        WHERE started_at>=?
        GROUP BY campaign,source,medium
        ORDER BY visitors DESC
        LIMIT 12", [$since]);

    $since60 = gmdate('Y-m-d H:i:s', time() - 3600);
    $live = $one("SELECT
            COUNT(DISTINCT e.visitor_hash) users,
            COUNT(DISTINCT CASE WHEN e.event_type='pageview' THEN e.visitor_hash END) pageview_users,
            COUNT(DISTINCT CASE WHEN e.event_type='click' THEN e.visitor_hash END) click_users,
            COUNT(DISTINCT CASE WHEN e.active_seconds>0 THEN e.visitor_hash END) active_users,
            COUNT(DISTINCT CASE WHEN s.is_bot=1 THEN e.visitor_hash END) bot_users
        FROM stat4_events e
        JOIN stat4_sessions s ON s.session_id=e.session_id
        WHERE e.occurred_at>=?", [$since60]);
    $averageMinute = $one("SELECT ROUND(COALESCE(AVG(visitors),0),2) average_users
        FROM (
            SELECT COUNT(DISTINCT visitor_hash) visitors
            FROM stat4_events
            WHERE occurred_at>=?
            GROUP BY DATE_FORMAT(occurred_at,'%Y-%m-%d %H:%i')
        ) minute_users", [$since60]);
    $live['avg_users_per_active_minute'] = (float) ($averageMinute['average_users'] ?? 0);
    $gaps = $q("SELECT first_seen FROM stat4_visitors WHERE first_seen>=? ORDER BY first_seen", [$since60]);
    $gapTotal=0; $gapCount=0; $previous=null;
    foreach ($gaps as $row) { $current=strtotime($row['first_seen']); if($previous!==null){$gapTotal+=max(0,$current-$previous);$gapCount++;}$previous=$current; }
    $live['avg_minutes_to_next_visitor'] = $gapCount ? round($gapTotal/$gapCount/60,2) : 0;
    $perMinute = $q("SELECT DATE_FORMAT(occurred_at,'%H:%i') label, COUNT(DISTINCT visitor_hash) visitors FROM stat4_events WHERE occurred_at>=? AND event_type='pageview' GROUP BY DATE_FORMAT(occurred_at,'%Y-%m-%d %H:%i') ORDER BY MIN(occurred_at)", [$since60]);
    $referrers = $q("SELECT CASE WHEN referrer='' THEN 'Direkt' ELSE COALESCE(NULLIF(SUBSTRING_INDEX(SUBSTRING_INDEX(referrer,'/',3),'//',-1),''),'Direkt') END label, COUNT(DISTINCT visitor_hash) value FROM stat4_sessions WHERE started_at>=? GROUP BY label ORDER BY value DESC LIMIT 8", [$since]);
    $browserVersions = $q("SELECT CONCAT(COALESCE(NULLIF(browser,''),'Unbekannt'),' ',SUBSTRING_INDEX(browser_version,'.',1)) label, COUNT(DISTINCT visitor_hash) value FROM stat4_events WHERE occurred_at>=? AND event_type='pageview' GROUP BY label ORDER BY value DESC LIMIT 10", [$since]);
    $osVersions = $q("SELECT CONCAT(COALESCE(NULLIF(os,''),'Unbekannt'),' ',SUBSTRING_INDEX(os_version,'.',1)) label, COUNT(DISTINCT visitor_hash) value FROM stat4_events WHERE occurred_at>=? AND event_type='pageview' GROUP BY label ORDER BY value DESC LIMIT 10", [$since]);

    $utc = new DateTimeZone('UTC');
    $berlin = new DateTimeZone('Europe/Berlin');
    $nowUtc = new DateTimeImmutable('now', $utc);
    $nowBerlin = $nowUtc->setTimezone($berlin);
    $hourStartUtc = $nowBerlin->setTime((int) $nowBerlin->format('H'), 0)->setTimezone($utc);
    $todayStartBerlin = $nowBerlin->setTime(0, 0);
    $todayStartUtc = $todayStartBerlin->setTimezone($utc);
    $tomorrowStartUtc = $todayStartBerlin->modify('+1 day')->setTimezone($utc);
    $elapsedHourSeconds = max(60, $nowUtc->getTimestamp() - $hourStartUtc->getTimestamp());
    $elapsedTodaySeconds = max(60, $nowUtc->getTimestamp() - $todayStartUtc->getTimestamp());
    $todayLengthSeconds = $tomorrowStartUtc->getTimestamp() - $todayStartUtc->getTimestamp();
    $uniqueHumanCount = static function (string $start) use ($one): int {
        $row = $one("SELECT COUNT(DISTINCT e.visitor_hash) visitors
            FROM stat4_events e
            JOIN stat4_visitors v ON v.visitor_hash=e.visitor_hash
            WHERE e.occurred_at>=? AND e.event_type='pageview' AND v.is_bot=0", [$start]);
        return (int) ($row['visitors'] ?? 0);
    };
    $currentHourUsers = $uniqueHumanCount($hourStartUtc->format('Y-m-d H:i:s'));
    $rollingHourUsers = $uniqueHumanCount(gmdate('Y-m-d H:i:s', time() - 3600));
    $todayUsers = $uniqueHumanCount($todayStartUtc->format('Y-m-d H:i:s'));
    $forecast = [
        'per_hour' => (int) round($currentHourUsers * 3600 / $elapsedHourSeconds),
        'per_24h' => $rollingHourUsers * 24,
        'today' => (int) round($todayUsers * $todayLengthSeconds / $elapsedTodaySeconds),
        'current_hour_users' => $currentHourUsers,
        'rolling_hour_users' => $rollingHourUsers,
        'today_users' => $todayUsers,
    ];

    stat4_json([
        'ok'=>true,'range'=>$range,'generated_at'=>gmdate(DATE_ATOM),'metrics'=>$metrics,'trend'=>$trend,
        'breakdowns'=>['browser'=>$breakdown('browser',$since),'os'=>$breakdown('os',$since),'device'=>$breakdown('device',$since),'country'=>$breakdown('country',$since)],
        'details'=>['screen'=>$breakdown('screen_size',$since,10),'inner'=>$breakdown('inner_size',$since,10),'language'=>$breakdown('language',$since,10),'country'=>$breakdown('country',$since,10),'browser_version'=>$browserVersions,'os_version'=>$osVersions],
        'paths'=>$paths,'user_agents'=>$uas,'session_summary'=>$sessionSummary,'levels'=>$levels,'campaigns'=>$campaigns,
        'live'=>$live,'per_minute'=>$perMinute,'forecast'=>$forecast,
        'charts'=>['daily'=>$trend,'referrer'=>$referrers,'browser'=>$breakdown('browser',$since,8),'os'=>$breakdown('os',$since,8),'language'=>$breakdown('language',$since,8),'screen'=>$breakdown('screen_size',$since,8)]
    ]);
} catch (Throwable $e) {
    error_log('stat4 api: ' . $e->getMessage());
    stat4_json(['ok'=>false,'error'=>'Statistik konnte nicht geladen werden. Datenbank und schema.sql prüfen.'],500);
}
