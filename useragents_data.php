<?php
declare(strict_types=1);
require_once __DIR__ . '/live_data.php';

// Read-only reports over the same human STAT4 pageviews used by Live.
function ua_agents_range(string $range, int $now): array
{
    $ranges = ['24h' => ['Letzte 24 Stunden', $now - 86400],
        '1d' => ['Heute seit 00:00 Uhr', ua_windows($now)['day']],
        '7d' => ['Letzte 7 Tage', $now - 7 * 86400],
        '30d' => ['Letzte 30 Tage', $now - 30 * 86400], 'all' => ['Gesamter Zeitraum', null]];
    $key = isset($ranges[$range]) ? $range : '24h';
    return ['key' => $key, 'label' => $ranges[$key][0], 'since' => $ranges[$key][1], 'until' => $now];
}

function ua_agents_percent(int $part, int $total): float
{
    return $total > 0 ? round(100 * $part / $total, 1) : 0.0;
}

function ua_agents_score(int $occurrences, float $samePairs): ?float
{
    // Probability that two different observations have different UserAgents.
    // n_i * (n_i - 1) penalizes frequent repetition; all-unique observations score 100.
    if ($occurrences < 2) return null;
    return round(max(0.0, min(100.0, 100 * (1 - $samePairs / $occurrences / ($occurrences - 1)))), 1);
}

function ua_agents_unknown_sql(string $column): string
{
    if (!in_array($column, ['browser', 'os', 'user_agent'], true)) throw new InvalidArgumentException('Invalid agent column');
    return "LOWER(TRIM(COALESCE(e.$column,''))) IN ('','unknown','unbekannt')";
}

function ua_agents_sql(array $range): array
{
    $from = "FROM stat4_events e JOIN stat4_sessions s ON s.session_id=e.session_id
        JOIN stat4_visitors v ON v.visitor_hash=e.visitor_hash";
    $where = "WHERE e.event_type='pageview' AND s.is_bot=0 AND v.is_bot=0 AND e.occurred_at < ?";
    $params = [gmdate('Y-m-d H:i:s', $range['until'])];
    if ($range['since'] !== null) { $where .= ' AND e.occurred_at >= ?'; $params[] = gmdate('Y-m-d H:i:s', $range['since']); }
    $agent = 'CAST(CASE WHEN ' . ua_agents_unknown_sql('user_agent') . " THEN '' ELSE TRIM(e.user_agent) END AS BINARY)";
    return [$from, $where, $params, $agent];
}

function ua_agents_read(PDO $pdo, callable $read): array
{
    $pdo->exec("SET time_zone = '+00:00'");
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('START TRANSACTION READ ONLY');
    try { $result = $read(); $pdo->commit(); return $result; }
    catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
}

function ua_agents_overview(PDO $pdo, int $now, string $rangeKey = '24h'): array
{
    $range = ua_agents_range($rangeKey, $now);
    return ua_agents_read($pdo, static function () use ($pdo, $now, $range): array {
        [$from, $where, $params, $agent] = ua_agents_sql($range);
        $browserUnknown = ua_agents_unknown_sql('browser'); $osUnknown = ua_agents_unknown_sql('os');
        $campaigns = [];
        $totals = ['occurrences' => 0, 'known_occurrences' => 0, 'missing_useragents' => 0,
            'distinct_useragents' => 0, 'browser_unknown' => 0, 'os_unknown' => 0, 'both_unknown' => 0, 'either_unknown' => 0];
        foreach (ua_query($pdo, "SELECT CAST(s.utm_campaign AS BINARY) AS campaign,COUNT(*) AS occurrences,
            COUNT(DISTINCT NULLIF(e.visitor_hash,'')) AS users,SUM($agent='') AS missing_useragents,
            SUM($browserUnknown) AS browser_unknown,SUM($osUnknown) AS os_unknown,
            SUM(($browserUnknown) AND ($osUnknown)) AS both_unknown,
            UNIX_TIMESTAMP(MAX(e.occurred_at)) AS last_seen $from $where GROUP BY CAST(s.utm_campaign AS BINARY)", $params) as $row) {
            $name = $row['campaign'];
            $row = array_map('intval', array_diff_key($row, ['campaign' => true]));
            $row += ['campaign' => $name, 'sources' => [], 'known_occurrences' => 0,
                'distinct_useragents' => 0, 'dominant_occurrences' => 0, '_same_pairs' => 0.0];
            $row['either_unknown'] = $row['browser_unknown'] + $row['os_unknown'] - $row['both_unknown'];
            $campaigns['c:' . $name] = $row;
        }
        // Aggregate within SQL so the overview does not load every full UserAgent into PHP memory.
        foreach (ua_query($pdo, "SELECT campaign,SUM(occurrences) AS known_occurrences,COUNT(*) AS distinct_useragents,
            MAX(occurrences) AS dominant_occurrences,SUM(occurrences*(occurrences-1)) AS same_pairs FROM (
                SELECT CAST(s.utm_campaign AS BINARY) AS campaign,$agent AS user_agent,COUNT(*) AS occurrences
                $from $where AND $agent<>'' GROUP BY CAST(s.utm_campaign AS BINARY),$agent
            ) frequencies GROUP BY campaign", $params) as $row) {
            $item = &$campaigns['c:' . $row['campaign']];
            foreach (['known_occurrences','distinct_useragents','dominant_occurrences'] as $key) $item[$key] = (int)$row[$key];
            $item['_same_pairs'] = (float)$row['same_pairs'];
            unset($item);
        }
        foreach (ua_query($pdo, "SELECT CAST(s.utm_campaign AS BINARY) AS campaign,CAST(s.utm_source AS BINARY) AS source,
            COUNT(*) AS occurrences $from $where GROUP BY CAST(s.utm_campaign AS BINARY),CAST(s.utm_source AS BINARY)
            ORDER BY occurrences DESC,source", $params) as $row) {
            $campaigns['c:' . $row['campaign']]['sources'][] = ['name' => $row['source'], 'occurrences' => (int)$row['occurrences']];
        }
        foreach ($campaigns as &$row) {
            $row['unique_percent'] = ua_agents_percent($row['distinct_useragents'], $row['known_occurrences']);
            $row['dominant_percent'] = ua_agents_percent($row['dominant_occurrences'], $row['known_occurrences']);
            $row['score'] = ua_agents_score($row['known_occurrences'], $row['_same_pairs']);
            unset($row['_same_pairs']);
            foreach (['browser_unknown','os_unknown','both_unknown','either_unknown','missing_useragents'] as $key) {
                $row[$key . '_percent'] = ua_agents_percent($row[$key], $row['occurrences']);
            }
            foreach ($totals as $key => $_) if ($key !== 'distinct_useragents') $totals[$key] += $row[$key];
        }
        unset($row);
        $totals['distinct_useragents'] = (int)ua_query($pdo, "SELECT COUNT(DISTINCT $agent) AS n $from $where AND $agent<>''", $params)[0]['n'];
        $totals['campaigns'] = count($campaigns);
        usort($campaigns, static fn(array $a, array $b): int => ($b['occurrences'] <=> $a['occurrences']) ?: strcmp($a['campaign'], $b['campaign']));
        return ['ok' => true, 'generated_at' => $now, 'range' => $range, 'totals' => $totals, 'campaigns' => $campaigns];
    });
}

function ua_agents_details(PDO $pdo, int $now, string $rangeKey, string $campaign, int $page = 1, string $search = '', bool $unknownOnly = false): array
{
    $range = ua_agents_range($rangeKey, $now);
    return ua_agents_read($pdo, static function () use ($pdo, $now, $range, $campaign, $page, $search, $unknownOnly): array {
        [$from, $where, $params, $agent] = ua_agents_sql($range);
        $where .= ' AND CAST(s.utm_campaign AS BINARY)=CAST(? AS BINARY)'; $params[] = $campaign;
        $campaignTotal = (int)ua_query($pdo, "SELECT COUNT(*) AS n $from $where", $params)[0]['n'];
        $browserUnknown = ua_agents_unknown_sql('browser'); $osUnknown = ua_agents_unknown_sql('os');
        if ($unknownOnly) $where .= " AND (($browserUnknown) OR ($osUnknown))";
        if ($search !== '') {
            $where .= " AND e.user_agent LIKE ? ESCAPE '!'";
            $params[] = '%' . str_replace(['!','%','_'], ['!!','!%','!_'], $search) . '%';
        }
        $counts = ua_query($pdo, "SELECT COUNT(*) AS occurrences,COUNT(DISTINCT $agent) AS distinct_rows $from $where", $params)[0];
        $totalRows = (int)$counts['distinct_rows']; $pageSize = 50;
        $pages = max(1, (int)ceil($totalRows / $pageSize)); $page = max(1, min($page, $pages)); $offset = ($page - 1) * $pageSize;
        $rows = ua_query($pdo, "SELECT $agent AS user_agent,COUNT(*) AS occurrences,
            COUNT(DISTINCT NULLIF(e.visitor_hash,'')) AS users,UNIX_TIMESTAMP(MAX(e.occurred_at)) AS last_seen,
            SUM($browserUnknown) AS browser_unknown,SUM($osUnknown) AS os_unknown
            $from $where GROUP BY $agent ORDER BY occurrences DESC,user_agent LIMIT $pageSize OFFSET $offset", $params);
        $byAgent = [];
        foreach ($rows as &$row) {
            foreach (['occurrences','users','last_seen','browser_unknown','os_unknown'] as $key) $row[$key] = (int)$row[$key];
            $row['percent'] = ua_agents_percent($row['occurrences'], $campaignTotal);
            $row['browsers'] = []; $row['operating_systems'] = [];
            $byAgent['ua:' . $row['user_agent']] = &$row;
        }
        unset($row);
        if ($rows) {
            $placeholders = implode(',', array_fill(0, count($rows), '?'));
            $browser = "CAST(CASE WHEN $browserUnknown THEN 'Unknown' ELSE TRIM(e.browser) END AS BINARY)";
            $os = "CAST(CASE WHEN $osUnknown THEN 'Unknown' ELSE TRIM(e.os) END AS BINARY)";
            foreach (ua_query($pdo, "SELECT $agent AS user_agent,$browser AS browser,$os AS os,COUNT(*) AS occurrences
                $from $where AND $agent IN ($placeholders) GROUP BY $agent,$browser,$os ORDER BY occurrences DESC,browser,os",
                array_merge($params, array_column($rows, 'user_agent'))) as $labels) {
                $item = &$byAgent['ua:' . $labels['user_agent']];
                foreach (['browsers' => 'browser', 'operating_systems' => 'os'] as $key => $field) {
                    $label = $labels[$field];
                    $item[$key]['l:' . $label] ??= ['name' => $label, 'occurrences' => 0];
                    $item[$key]['l:' . $label]['occurrences'] += (int)$labels['occurrences'];
                }
                unset($item);
            }
        }
        unset($byAgent);
        foreach ($rows as &$row) foreach (['browsers','operating_systems'] as $key) {
            $row[$key] = array_values($row[$key]);
            usort($row[$key], static fn(array $a, array $b): int => ($b['occurrences'] <=> $a['occurrences']) ?: strcmp($a['name'], $b['name']));
        }
        unset($row);
        return ['ok' => true, 'generated_at' => $now, 'range' => $range, 'campaign' => $campaign,
            'campaign_occurrences' => $campaignTotal, 'filtered_occurrences' => (int)$counts['occurrences'],
            'total_rows' => $totalRows, 'page' => $page, 'pages' => $pages, 'page_size' => $pageSize,
            'search' => $search, 'unknown_only' => $unknownOnly, 'rows' => $rows];
    });
}
