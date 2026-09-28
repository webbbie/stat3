<?php
declare(strict_types=1);

/** Explicit allowlist: statistics only, without configuration or push credentials. */
function stats_export_sources(string $eventTable): array
{
    return [
        $eventTable => 'Pixl / Click Paths / DashboardX2',
        'stat4_visitors' => 'STAT4 / Besucher',
        'stat4_sessions' => 'STAT4 / Sitzungen / UTM',
        'stat4_events' => 'STAT4 / Live / UserAgents',
        'stat4_notifications' => 'STAT4 / Benachrichtigungen',
        'impressions' => 'Impressions / Banner',
        'ppcmate_attributions' => 'PPCMate / Zuordnungen',
        'ppcmate_conversions' => 'PPCMate / Conversions',
        'mind_notifications' => 'Mind / Benachrichtigungen',
        'mind_geo_cache' => 'Mind / MaxMind-Geodaten',
        'pixl_captcha_state' => 'Captcha / Aktuelle Phase',
        'pixl_captcha_stats' => 'Captcha / Aktuelle Auswertung',
        'pixl_captcha_visitors' => 'Captcha / Besucher',
        'pixl_captcha_page_views' => 'Captcha / Seitenansichten',
        'legacy_sqlite_rows' => 'Historische Statistikdaten',
    ];
}

function stats_export_identifier(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

/** The rolling window cannot be enlarged by dashboard/query filters. */
function stats_export_window(?int $now = null): array
{
    $now ??= time();
    return ['days' => 90, 'from' => gmdate('Y-m-d H:i:s', $now - 90 * 86400),
        'to' => gmdate('Y-m-d H:i:s', $now), 'from_unix' => $now - 90 * 86400, 'to_unix' => $now, 'timezone' => 'UTC'];
}

/** Neutralize spreadsheet formulas. JSON backups retain original values. */
function stats_export_cell(mixed $value, bool $numeric = false): string
{
    if ($value === null) return '';
    $text = (string)$value;
    if ($numeric && is_numeric($text)) return $text;
    if (preg_match('/\A(?:[\x00-\x20]*[=+@-]|[\t\r\n])/', $text)) return "'" . $text;
    return $text;
}

function stats_export_bounds(PDO $pdo, array $window, string $column, bool $unix = false): string
{
    $from = $unix ? (string)$window['from_unix'] : $pdo->quote($window['from']);
    $to = $unix ? (string)$window['to_unix'] : $pdo->quote($window['to']);
    return "($column >= $from AND $column <= $to)";
}

/** Inspect optional modules without installing or changing any table. */
function stats_export_catalog(PDO $pdo, string $eventTable, array $window): array
{
    $installed = $pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
    $installed = array_column(array_filter($installed, static fn(array $row): bool => $row[1] === 'BASE TABLE'), 0);
    if (!in_array($eventTable, $installed, true)) throw new RuntimeException('Die Statistik-Haupttabelle fehlt.');
    $dates = [$eventTable => ['created_at', false], 'stat4_visitors' => ['last_seen', false],
        'stat4_sessions' => ['last_seen', false], 'stat4_events' => ['occurred_at', false],
        'stat4_notifications' => ['created_at', false], 'impressions' => ['created_at', false],
        'ppcmate_attributions' => ['last_seen_at', true], 'ppcmate_conversions' => ['created_at', true],
        'mind_notifications' => ['sent_at', false], 'mind_geo_cache' => ['looked_up_at', false],
        'pixl_captcha_visitors' => ['last_seen', true], 'legacy_sqlite_rows' => ['source_created_at', false]];
    $catalog = [];
    foreach (stats_export_sources($eventTable) as $table => $label) {
        $source = ['table' => $table, 'label' => $label, 'available' => in_array($table, $installed, true),
            'columns' => [], 'primary_key' => [], 'excluded_columns' => [], 'date_column' => null,
            'unix' => false, 'kind' => 'events', 'note' => 'Alle Datensätze im Zeitraum, einschließlich Bots.', 'where' => '0=1'];
        if (!$source['available']) { $catalog[$table] = $source; continue; }
        foreach ($pdo->query('SHOW COLUMNS FROM ' . stats_export_identifier($table))->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $name = (string)$column['Field'];
            if ($table === 'pixl_captcha_visitors' && $name === 'token') { $source['excluded_columns'][] = $name; continue; }
            $source['columns'][$name] = $column;
            if ($column['Key'] === 'PRI') $source['primary_key'][] = $name;
        }
        if (isset($dates[$table])) {
            [$date, $unix] = $dates[$table];
            if (!isset($source['columns'][$date])) throw new RuntimeException('Zeitspalte fehlt: ' . $table);
            $source['date_column'] = $date;
            $source['unix'] = $unix;
            $source['where'] = stats_export_bounds($pdo, $window, 's.' . stats_export_identifier($date), $unix);
        } elseif (in_array($table, ['pixl_captcha_state', 'pixl_captcha_stats'], true)) {
            $source['kind'] = 'snapshot';
            $source['where'] = '1=1';
            $source['note'] = 'Aktueller Zählerstand; keine zeitlich aufteilbare 90-Tage-Summe.';
        }
        $catalog[$table] = $source;
    }
    $has = static fn(string $table): bool => $catalog[$table]['available'] ?? false;
    $eventBounds = stats_export_bounds($pdo, $window, 'e.occurred_at');
    if ($has('stat4_sessions') && $has('stat4_events')) {
        $catalog['stat4_sessions']['where'] .= " OR EXISTS (SELECT 1 FROM stat4_events e WHERE e.session_id=s.session_id AND $eventBounds)";
    }
    if ($has('stat4_visitors')) {
        if ($has('stat4_sessions')) {
            $sessions = str_replace('s.', 'ss.', $catalog['stat4_sessions']['where']);
            $catalog['stat4_visitors']['where'] .= " OR EXISTS (SELECT 1 FROM stat4_sessions ss WHERE ss.visitor_hash=s.visitor_hash AND ($sessions))";
        }
        if ($has('stat4_notifications')) {
            $bounds = stats_export_bounds($pdo, $window, 'n.created_at');
            $catalog['stat4_visitors']['where'] .= " OR EXISTS (SELECT 1 FROM stat4_notifications n WHERE n.visitor_hash=s.visitor_hash AND $bounds)";
        }
    }
    if ($has('mind_geo_cache') && $has('mind_notifications')) {
        $bounds = stats_export_bounds($pdo, $window, 'n.sent_at');
        $catalog['mind_geo_cache']['where'] .= " OR EXISTS (SELECT 1 FROM mind_notifications n WHERE n.geo_ip_hash=s.ip_hash AND $bounds)";
    }
    if ($has('ppcmate_attributions') && $has('ppcmate_conversions')) {
        $bounds = stats_export_bounds($pdo, $window, 'c.created_at', true);
        $catalog['ppcmate_attributions']['where'] .= " OR EXISTS (SELECT 1 FROM ppcmate_conversions c WHERE c.client_id=s.client_id AND $bounds)";
    }
    foreach (['stat4_visitors', 'stat4_sessions', 'mind_geo_cache', 'ppcmate_attributions'] as $table) {
        $catalog[$table]['kind'] = 'related';
        $catalog[$table]['note'] = 'Im Zeitraum aktualisiert oder zugehöriger Stammdatensatz; Beginn und Gesamtzähler können älter sein.';
    }
    if ($has('pixl_captcha_page_views')) {
        if ($has('pixl_captcha_visitors')) {
            $bounds = stats_export_bounds($pdo, $window, 'v.last_seen', true);
            $catalog['pixl_captcha_page_views']['where'] = "EXISTS (SELECT 1 FROM pixl_captcha_visitors v WHERE v.visitor_hash=s.visitor_hash AND $bounds)";
        }
        $catalog['pixl_captcha_page_views']['kind'] = 'related';
        $catalog['pixl_captcha_page_views']['note'] = 'Gespeicherte Seitenzuordnungen der CAPTCHA-Besucher im Zeitraum; ohne eigenen Zeitstempel.';
    }
    $catalog['legacy_sqlite_rows']['note'] = 'Nach ursprünglichem Ereignisdatum. Undatierte Altzeilen sind nicht im 90-Tage-Backup enthalten.';
    return $catalog;
}

/** Overview/download: a consistent read-only UTC snapshot, restoring the connection. */
function stats_export_read(PDO $pdo, string $eventTable, ?int $now, callable $read): mixed
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql' || $pdo->inTransaction()) {
        throw new RuntimeException('Der Export benötigt eine freie MySQL-Verbindung.');
    }
    $buffered = (bool)$pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
    $timezone = (string)$pdo->query('SELECT @@session.time_zone')->fetchColumn();
    try {
        $pdo->exec("SET time_zone = '+00:00'");
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        $pdo->beginTransaction();
        $window = stats_export_window($now);
        $catalog = stats_export_catalog($pdo, $eventTable, $window);
        $result = $read($catalog, $window);
        $pdo->commit();
        return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    } finally {
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
        $pdo->exec('SET time_zone = ' . $pdo->quote($timezone));
    }
}

function stats_export_select(array $source): string
{
    $quote = static fn(string $name): string => 's.' . stats_export_identifier($name);
    $columns = implode(',', array_map($quote, array_keys($source['columns'])));
    $order = $source['primary_key'] ? ' ORDER BY ' . implode(',', array_map($quote, $source['primary_key'])) : '';
    return 'SELECT ' . $columns . ' FROM ' . stats_export_identifier($source['table']) . ' s WHERE (' . $source['where'] . ')' . $order;
}

function stats_export_summary(PDO $pdo, array $catalog, array $window): array
{
    $sources = [];
    $earliest = null;
    $total = 0;
    foreach ($catalog as $table => $source) {
        $summary = array_intersect_key($source, array_flip(['table', 'label', 'available', 'kind', 'note', 'date_column', 'unix', 'excluded_columns']));
        $summary += ['rows' => 0, 'stored_rows' => 0, 'undated_rows' => 0, 'first' => null, 'last' => null,
            'column_count' => count($source['columns']), 'metrics' => []];
        if (!$source['available']) { $sources[$table] = $summary; continue; }
        $date = $source['date_column'] === null ? 'NULL' : 's.' . stats_export_identifier($source['date_column']);
        $stored = $pdo->query('SELECT COUNT(*) AS total, SUM(' . $date . ' IS NULL) AS undated FROM ' . stats_export_identifier($table) . ' s')->fetch();
        $summary['stored_rows'] = (int)$stored['total'];
        $summary['undated_rows'] = $source['date_column'] === null ? 0 : (int)$stored['undated'];
        $metricSql = [];
        $metricLabels = [];
        $add = static function (string $label, string $sql) use (&$metricSql, &$metricLabels): void {
            $key = 'metric_' . count($metricLabels); $metricLabels[$key] = $label; $metricSql[] = "$sql AS $key";
        };
        if (isset($source['columns']['visitor_hash'])) $add('Besucher-Hashes', "COUNT(DISTINCT NULLIF(s.visitor_hash,''))");
        if (isset($source['columns']['is_bot'])) $add('Bot-Datensätze', 'COALESCE(SUM(s.is_bot=1),0)');
        if (isset($source['columns']['event_type'])) {
            $add('Seitenaufrufe', "COALESCE(SUM(s.event_type='pageview'),0)");
            if ($table === 'stat4_events') $add('Klicks', "COALESCE(SUM(s.event_type='click'),0)");
            if ($table === 'impressions') $add('Banner-Impressionen', "COALESCE(SUM(s.event_type='ad'),0)");
        }
        foreach (['browser' => 'Browser Unknown / leer', 'os' => 'Betriebssystem Unknown / leer'] as $field => $label) {
            if (isset($source['columns'][$field])) $add($label, "COALESCE(SUM(LOWER(TRIM(COALESCE(s.$field,''))) IN ('','unknown','unbekannt')),0)");
        }
        if ($table === 'stat4_sessions') $add('Kampagnennamen', "COUNT(DISTINCT CAST(s.utm_campaign AS BINARY))");
        if ($table === 'stat4_notifications') $add('Erfolgreich gesendet', "COALESCE(SUM(s.status='sent'),0)");
        if ($table === 'ppcmate_conversions') $add('Erfolgreich gesendet', 'COALESCE(SUM(s.sent_at IS NOT NULL AND s.sent_at>0),0)');
        foreach (['waiting_visitors' => 'Wartende Besucher', 'waiting_page_views' => 'Seiten im Wartezyklus', 'successes' => 'Aktuelle Erfolge',
            'failures' => 'Aktuelle Fehler', 'blocked_visitors' => 'Aktuell blockiert', 'last_successes' => 'Letzte Phase: Erfolge',
            'last_failures' => 'Letzte Phase: Fehler'] as $field => $label) {
            if (isset($source['columns'][$field])) $add($label, 'SUM(s.' . stats_export_identifier($field) . ')');
        }
        $sql = 'SELECT COUNT(*) AS total, MIN(' . $date . ') AS first_date, MAX(' . $date . ') AS last_date'
            . ($metricSql ? ',' . implode(',', $metricSql) : '') . ' FROM ' . stats_export_identifier($table) . ' s WHERE (' . $source['where'] . ')';
        $row = $pdo->query($sql)->fetch();
        $summary['rows'] = (int)$row['total'];
        foreach (['first' => 'first_date', 'last' => 'last_date'] as $key => $field) {
            $summary[$key] = $row[$field] === null ? null : ($source['unix'] ? gmdate('Y-m-d H:i:s', (int)$row[$field]) : (string)$row[$field]);
        }
        foreach ($metricLabels as $key => $label) $summary['metrics'][$label] = $row[$key] === null ? null : (int)$row[$key];
        if ($source['kind'] === 'events' && $summary['first'] !== null && ($earliest === null || $summary['first'] < $earliest)) $earliest = $summary['first'];
        $total += $summary['rows'];
        $sources[$table] = $summary;
    }
    return ['window' => $window, 'first_event' => $earliest, 'total_rows' => $total, 'sources' => $sources];
}

/** Browsable raw records share exactly the download predicates. */
function stats_export_overview(PDO $pdo, string $eventTable, ?int $now = null, string $table = '', int $page = 1): array
{
    return stats_export_read($pdo, $eventTable, $now, static function (array $catalog, array $window) use ($pdo, $table, $page): array {
        if ($table !== '' && (!isset($catalog[$table]) || !$catalog[$table]['available'])) throw new InvalidArgumentException('Unbekannte Statistikquelle.');
        $data = stats_export_summary($pdo, $catalog, $window);
        $data['details'] = null;
        if ($table !== '') {
            $pages = max(1, (int)ceil($data['sources'][$table]['rows'] / 20));
            $page = max(1, min($pages, $page));
            $statement = $pdo->query(stats_export_select($catalog[$table]) . ' LIMIT 20 OFFSET ' . (($page - 1) * 20));
            $data['details'] = ['table' => $table, 'page' => $page, 'pages' => $pages, 'rows' => $statement->fetchAll(PDO::FETCH_ASSOC)];
        }
        return $data;
    });
}

function stats_export_write($stream, string $text): void
{
    $length = strlen($text);
    for ($written = 0; $written < $length; $written += $bytes) {
        $bytes = fwrite($stream, substr($text, $written));
        if ($bytes === false || $bytes === 0) throw new RuntimeException('Der Export konnte nicht vollständig geschrieben werden.');
    }
}

/** Disk-backed output must be complete before any download headers are sent. */
function stats_export_create(PDO $pdo, string $eventTable, string $format, ?int $now = null, ?array &$metadata = null)
{
    if (!in_array($format, ['csv', 'json'], true)) throw new InvalidArgumentException('Unbekanntes Exportformat.');
    $stream = tmpfile();
    if ($stream === false) throw new RuntimeException('Die Exportdatei konnte nicht vorbereitet werden.');
    try {
        stats_export_read($pdo, $eventTable, $now, static function (array $catalog, array $window) use ($pdo, $stream, $format, &$metadata): void {
            // Also safe inside the standalone HTML's application/json element.
            $json = static fn(mixed $value): string => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
            $header = ['Statistik', 'Tabelle'];
            $positions = [];
            if ($format === 'csv') {
                foreach ($catalog as $source) foreach ($source['columns'] as $name => $_) {
                    if (!array_key_exists($name, $positions)) { $positions[$name] = count($header); $header[] = $name; }
                }
                stats_export_write($stream, "\xEF\xBB\xBF");
                if (fputcsv($stream, array_map('stats_export_cell', $header), ';', '"', '', "\r\n") === false) throw new RuntimeException('CSV-Kopf konnte nicht geschrieben werden.');
            } else {
                $summary = stats_export_summary($pdo, $catalog, $window);
                $meta = ['format' => 'stats3-statistics-backup', 'version' => 1, 'created_at' => gmdate('c'), 'summary' => $summary,
                    'value_encoding' => 'Every non-null SQL value is a JSON string; SQL NULL remains JSON null.',
                    'scope' => '90 days of events plus related parent records and current undated CAPTCHA counters.',
                    'excluded' => ['Configuration, passwords, push credentials, active CAPTCHA tokens and undated legacy rows.'],
                    'restore_note' => 'Data archive, not an automatic restore. Use column/schema metadata to import into a separate empty database; never replay notification or conversion sending.'];
                $metadata = $meta;
                stats_export_write($stream, '{"meta":' . $json($meta) . ',"tables":[');
            }
            $firstTable = true;
            foreach ($catalog as $source) {
                if (!$source['available']) continue;
                if ($format === 'json') {
                    $schema = $pdo->query('SHOW CREATE TABLE ' . stats_export_identifier($source['table']))->fetch(PDO::FETCH_NUM)[1];
                    $info = ['name' => $source['table'], 'label' => $source['label'], 'kind' => $source['kind'], 'note' => $source['note'],
                        'columns' => array_values($source['columns']), 'primary_key' => $source['primary_key'],
                        'excluded_columns' => $source['excluded_columns'], 'create_table_sql' => $schema];
                    stats_export_write($stream, ($firstTable ? '' : ',') . substr($json($info), 0, -1) . ',"rows":[');
                    $firstTable = false;
                }
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
                $statement = $pdo->query(stats_export_select($source));
                $count = 0;
                try {
                    while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                        if ($format === 'json') {
                            foreach ($row as &$value) if ($value !== null) $value = (string)$value;
                            unset($value);
                            stats_export_write($stream, ($count ? ',' : '') . $json($row));
                        } else {
                            $record = array_fill(0, count($header), '');
                            $record[0] = $source['label']; $record[1] = stats_export_cell($source['table']);
                            foreach ($source['columns'] as $name => $column) {
                                $numeric = preg_match('/\A(?:tinyint|smallint|mediumint|int|integer|bigint|decimal|numeric|float|double|real|bit)\b/i', (string)$column['Type']) === 1;
                                $record[$positions[$name]] = stats_export_cell($row[$name], $numeric);
                            }
                            if (fputcsv($stream, $record, ';', '"', '', "\r\n") === false) throw new RuntimeException('CSV konnte nicht vollständig geschrieben werden.');
                        }
                        $count++;
                    }
                } finally { $statement->closeCursor(); $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true); }
                if ($format === 'json') stats_export_write($stream, '],"row_count":' . $count . '}');
            }
            if ($format === 'json') stats_export_write($stream, ']}');
        });
        if (!fflush($stream) || !rewind($stream)) throw new RuntimeException('Die Exportdatei konnte nicht abgeschlossen werden.');
        return $stream;
    } catch (Throwable $error) { fclose($stream); throw $error; }
}

function stats_export_create_csv(PDO $pdo, string $eventTable, ?int $now = null)
{
    return stats_export_create($pdo, $eventTable, 'csv', $now);
}

function stats_export_download(PDO $pdo, string $eventTable, string $format = 'csv', ?int $now = null): void
{
    @set_time_limit(0);
    $stream = stats_export_create($pdo, $eventTable, $format, $now);
    try {
        while (ob_get_level() > 0) ob_end_clean();
        @ini_set('zlib.output_compression', '0');
        header('Content-Type: ' . ($format === 'csv' ? 'text/csv' : 'application/json') . '; charset=UTF-8');
        $filename = $format === 'csv' ? 'export.csv' : 'stats3-backup-' . gmdate('Ymd-His', $now ?? time()) . '.json';
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . (string)fstat($stream)['size']);
        fpassthru($stream);
    } finally { fclose($stream); }
}

/** A self-contained HTML snapshot: all rows, inline assets, no remote dependencies. */
function stats_export_create_html(PDO $pdo, string $eventTable, ?int $now = null, string $initialTable = '', int $initialPage = 1, bool $offline = true)
{
    $metadata = null;
    $jsonStream = stats_export_create($pdo, $eventTable, 'json', $now, $metadata);
    $stream = null;
    $bufferLevel = ob_get_level();
    try {
        if ($initialTable !== '' && !($metadata['summary']['sources'][$initialTable]['available'] ?? false)) {
            throw new InvalidArgumentException('Unbekannte Statistikquelle.');
        }
        // Only the small presentation is buffered; the full dataset stays on disk.
        ob_start();
        require __DIR__ . '/export_view.php';
        $layout = ob_get_clean();
        $parts = explode('<!--STATS3_SNAPSHOT_DATA-->', $layout);
        if (count($parts) !== 2) throw new RuntimeException('Die HTML-Vorlage ist unvollständig.');
        $stream = tmpfile();
        if ($stream === false) throw new RuntimeException('Die HTML-Datei konnte nicht vorbereitet werden.');
        stats_export_write($stream, $parts[0]);
        if (stream_copy_to_stream($jsonStream, $stream) !== fstat($jsonStream)['size']) {
            throw new RuntimeException('Die HTML-Daten konnten nicht vollständig geschrieben werden.');
        }
        stats_export_write($stream, $parts[1]);
        if (!fflush($stream) || !rewind($stream)) throw new RuntimeException('Die HTML-Datei konnte nicht abgeschlossen werden.');
        return $stream;
    } catch (Throwable $error) {
        while (ob_get_level() > $bufferLevel) ob_end_clean();
        if (is_resource($stream)) fclose($stream);
        throw $error;
    } finally { fclose($jsonStream); }
}
