<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/pixl_server.php';

function migration_usage(): void
{
    echo <<<'TXT'
Stats3 storage migration

Preview only (default):
  php tools/migrate_storage_to_mysql.php

Import into the MySQL database configured in pixl_config.php:
  php tools/migrate_storage_to_mysql.php --commit

Options:
  --commit                         Create schemas and import data.
  --skip-stat4                     Do not import the former STAT4 database.
  --skip-archive                   Do not archive old non-active SQLite rows.
  --legacy-stat4-config=/path      Former STAT4 config.local.php source.
  --confirm-stat4-cutover          Confirm the former STAT4 writer is stopped.
  --ppcmate-db=/path               Former private ppcmate.sqlite source.
  --help                           Show this help.

The source SQLite files are opened read-only and are never deleted.
TXT;
    echo PHP_EOL;
}

function migration_fail(string $message, int $status = 1): never
{
    fwrite(STDERR, 'FEHLER: ' . $message . PHP_EOL);
    exit($status);
}

function migration_relative_path(string $path, string $root): string
{
    $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/') . '/';
    $normalizedPath = str_replace('\\', '/', $path);
    if (str_starts_with($normalizedPath, $normalizedRoot)) {
        return substr($normalizedPath, strlen($normalizedRoot));
    }
    return basename($path);
}

function migration_identifier(string $identifier): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
        throw new RuntimeException('Ungültiger SQL-Bezeichner: ' . $identifier);
    }
    return '`' . $identifier . '`';
}

function migration_sqlite_identifier(string $identifier): string
{
    return '"' . str_replace('"', '""', $identifier) . '"';
}

function migration_open_sqlite(string $path): PDO
{
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('SQLite-Quelle fehlt oder ist nicht lesbar: ' . $path);
    }
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('PDO_SQLite ist für den einmaligen Import erforderlich.');
    }

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    if (defined('PDO::SQLITE_ATTR_OPEN_FLAGS') && defined('SQLITE3_OPEN_READONLY')) {
        $options[constant('PDO::SQLITE_ATTR_OPEN_FLAGS')] = constant('SQLITE3_OPEN_READONLY');
    }

    $sqlite = new PDO('sqlite:' . $path, null, null, $options);
    $sqlite->exec('PRAGMA query_only = ON');
    $integrity = (string) $sqlite->query('PRAGMA quick_check')->fetchColumn();
    if (strtolower($integrity) !== 'ok') {
        throw new RuntimeException('SQLite quick_check fehlgeschlagen: ' . $path . ' (' . $integrity . ')');
    }
    return $sqlite;
}

/** @return list<string> */
function migration_sqlite_tables(PDO $sqlite): array
{
    $statement = $sqlite->query(
        "SELECT name FROM sqlite_master "
        . "WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
    );
    $tables = [];
    while (($name = $statement->fetchColumn()) !== false) {
        $name = (string) $name;
        if ($name !== 'lost_and_found') {
            $tables[] = $name;
        }
    }
    return $tables;
}

function migration_sqlite_count(PDO $sqlite, string $table): int
{
    return (int) $sqlite->query(
        'SELECT COUNT(*) FROM ' . migration_sqlite_identifier($table)
    )->fetchColumn();
}

function migration_sqlite_snapshot_hash(string $path): string
{
    $context = hash_init('sha256');
    foreach ([$path, $path . '-wal'] as $candidate) {
        if (!is_file($candidate)) {
            continue;
        }
        hash_update($context, basename($candidate) . "\0" . (string) filesize($candidate) . "\0");
        if (!hash_update_file($context, $candidate)) {
            throw new RuntimeException('SQLite-Prüfsumme fehlgeschlagen: ' . $candidate);
        }
    }
    return hash_final($context);
}

/** @return list<string> */
function migration_sqlite_primary_key_columns(PDO $sqlite, string $table): array
{
    $keys = [];
    foreach ($sqlite->query('PRAGMA table_info(' . migration_sqlite_identifier($table) . ')') as $column) {
        $position = (int) ($column['pk'] ?? 0);
        if ($position > 0) {
            $keys[$position] = (string) $column['name'];
        }
    }
    ksort($keys, SORT_NUMERIC);
    return array_values($keys);
}

/** @param list<string> $primaryKeyColumns */
function migration_archive_row_key(array $row, string $rowId, array $primaryKeyColumns): string
{
    if ($primaryKeyColumns === []) {
        return 'rowid:' . $rowId;
    }
    $key = [];
    foreach ($primaryKeyColumns as $column) {
        if (!array_key_exists($column, $row)) {
            return 'rowid:' . $rowId;
        }
        $key[$column] = $row[$column];
    }
    $json = json_encode($key, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($json)) {
        throw new RuntimeException('SQLite-Primärschlüssel konnte nicht kodiert werden.');
    }
    return 'pk:' . hash('sha256', $json);
}

/** @param list<string> $primaryKeyColumns */
function migration_upgrade_archive_row_keys(
    PDO $mysql,
    string $sourceFile,
    string $sourceTable,
    array $primaryKeyColumns
): void {
    $select = $mysql->prepare(
        'SELECT `id`,`source_rowid`,`row_json` FROM `legacy_sqlite_rows` '
        . 'WHERE `source_file`=? AND `source_table`=?'
    );
    $select->execute([$sourceFile, $sourceTable]);
    $update = $mysql->prepare('UPDATE `legacy_sqlite_rows` SET `source_rowid`=? WHERE `id`=?');
    while ($stored = $select->fetch()) {
        $oldKey = (string) $stored['source_rowid'];
        if (str_starts_with($oldKey, 'pk:') || str_starts_with($oldKey, 'rowid:')) {
            continue;
        }
        $row = json_decode((string) $stored['row_json'], true);
        if (!is_array($row)) {
            throw new RuntimeException('Ungültiges bestehendes Legacy-JSON für ' . $sourceFile . '/' . $sourceTable);
        }
        $newKey = migration_archive_row_key($row, $oldKey, $primaryKeyColumns);
        $update->execute([$newKey, (int) $stored['id']]);
    }
}

function migration_normalize_datetime(mixed $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    try {
        return (new DateTimeImmutable($value))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return null;
    }
}

/** @param array<string, mixed> $row */
function migration_source_datetime(array $row): ?string
{
    foreach (['created_at', 'received_at', 'ts', 'last_seen_at', 'last_seen'] as $key) {
        if (array_key_exists($key, $row)) {
            $date = migration_normalize_datetime($row[$key]);
            if ($date !== null) {
                return $date;
            }
        }
    }
    return null;
}

function migration_is_ip_key(string $key): bool
{
    return preg_match('/(?:^|_)(?:ip|ip_address)$/i', $key) === 1
        && !str_ends_with(strtolower($key), '_hash');
}

function migration_scrub_ip_literals(string $value): string
{
    $value = preg_replace_callback(
        '/(?<![0-9.])(?:[0-9]{1,3}\.){3}[0-9]{1,3}(?![0-9.])/',
        static fn(array $match): string => filter_var($match[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            ? '[IP entfernt]'
            : $match[0],
        $value
    ) ?? $value;

    return preg_replace_callback(
        '/(?<![0-9A-Fa-f:])([0-9A-Fa-f:]*:[0-9A-Fa-f:]+(?:%[A-Za-z0-9_.-]+)?)(?![0-9A-Fa-f:])/',
        static function (array $match): string {
            $candidate = $match[1];
            $address = explode('%', $candidate, 2)[0];
            return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
                ? '[IP entfernt]'
                : $candidate;
        },
        $value
    ) ?? $value;
}

/** @param array<string, mixed>|list<mixed> $value */
function migration_sanitize_array(array $value, array $knownIps): array
{
    $sanitized = [];
    foreach ($value as $key => $item) {
        $keyString = (string) $key;
        if (!is_int($key) && migration_is_ip_key($keyString)) {
            $hashKey = $keyString . '_hash';
            $sanitized[$hashKey] = trim((string) $item) === '' ? '' : pixl_hash((string) $item);
            continue;
        }
        if (is_array($item)) {
            $sanitized[$key] = migration_sanitize_array($item, $knownIps);
            continue;
        }
        if (is_string($item)) {
            $decoded = null;
            if (str_ends_with(strtolower($keyString), 'payload_json')) {
                $decoded = json_decode($item, true);
            }
            if (is_array($decoded)) {
                $sanitized[$key] = json_encode(
                    migration_sanitize_array($decoded, $knownIps),
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
                );
                continue;
            }
            if ($knownIps !== []) {
                $item = str_replace($knownIps, '[IP entfernt]', $item);
            }
            $item = migration_scrub_ip_literals($item);
        }
        $sanitized[$key] = $item;
    }
    return $sanitized;
}

/** @param array<string, mixed> $row */
function migration_sanitize_legacy_row(array $row): array
{
    $knownIps = [];
    $collect = static function (mixed $value, string $key = '') use (&$collect, &$knownIps): void {
        if (is_array($value)) {
            foreach ($value as $childKey => $childValue) {
                $collect($childValue, (string) $childKey);
            }
            return;
        }
        $candidate = trim((string) $value);
        if (migration_is_ip_key($key) && filter_var($candidate, FILTER_VALIDATE_IP)) {
            $knownIps[$candidate] = $candidate;
        }
        if (is_string($value) && str_ends_with(strtolower($key), 'payload_json')) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $collect($decoded);
            }
        }
    };
    $collect($row);
    return migration_sanitize_array($row, array_values($knownIps));
}

function migration_run_schema_file(PDO $mysql, string $path): void
{
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        throw new RuntimeException('Schema kann nicht gelesen werden: ' . $path);
    }

    $statement = '';
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($statement === '' && ($trimmed === '' || str_starts_with($trimmed, '--'))) {
            continue;
        }
        $statement .= $line . "\n";
        if (str_ends_with(rtrim($line), ';')) {
            $mysql->exec(rtrim(trim($statement), ';'));
            $statement = '';
        }
    }
    if (trim($statement) !== '') {
        $mysql->exec($statement);
    }
}

function migration_ensure_column(PDO $mysql, string $table, string $column, string $definition): void
{
    $query = $mysql->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table AND COLUMN_NAME=:column'
    );
    $query->execute([':table' => $table, ':column' => $column]);
    if ((int) $query->fetchColumn() === 0) {
        $mysql->exec(
            'ALTER TABLE ' . migration_identifier($table)
            . ' ADD COLUMN ' . migration_identifier($column) . ' ' . $definition
        );
    }
}

function migration_ensure_index(PDO $mysql, string $table, string $index, string $definition): void
{
    $query = $mysql->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS '
        . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table AND INDEX_NAME=:index_name'
    );
    $query->execute([':table' => $table, ':index_name' => $index]);
    if ((int) $query->fetchColumn() === 0) {
        $mysql->exec('ALTER TABLE ' . migration_identifier($table) . ' ADD ' . $definition);
    }
}

function migration_ensure_schema(PDO $mysql, string $root): void
{
    migration_run_schema_file($mysql, $root . '/pixl_schema.sql');
    pixl_ensure_schema($mysql);
    migration_ensure_column($mysql, 'impressions', 'legacy_source', 'VARCHAR(191) NULL');
    migration_ensure_column($mysql, 'impressions', 'legacy_id', 'BIGINT UNSIGNED NULL');
    migration_ensure_index(
        $mysql,
        'impressions',
        'uq_imp_legacy',
        'UNIQUE KEY `uq_imp_legacy` (`legacy_source`, `legacy_id`)'
    );
    migration_ensure_column($mysql, 'ppcmate_conversions', 'legacy_source', 'VARCHAR(191) NULL');
    migration_ensure_column($mysql, 'ppcmate_conversions', 'legacy_id', 'BIGINT UNSIGNED NULL');
    migration_ensure_index(
        $mysql,
        'ppcmate_conversions',
        'uq_ppcmate_conversion_legacy',
        'UNIQUE KEY `uq_ppcmate_conversion_legacy` (`legacy_source`, `legacy_id`)'
    );
    migration_assert_unique_index($mysql, 'impressions', 'uq_imp_legacy', ['legacy_source', 'legacy_id']);
    migration_assert_unique_index(
        $mysql,
        'ppcmate_conversions',
        'uq_ppcmate_conversions_dedupe_hash',
        ['dedupe_hash']
    );
    migration_assert_unique_index(
        $mysql,
        'ppcmate_conversions',
        'uq_ppcmate_conversion_legacy',
        ['legacy_source', 'legacy_id']
    );
}

/** @param list<string> $expectedColumns */
function migration_assert_unique_index(
    PDO $mysql,
    string $table,
    string $index,
    array $expectedColumns
): void {
    $query = $mysql->prepare(
        'SELECT COLUMN_NAME, NON_UNIQUE FROM information_schema.STATISTICS '
        . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=? ORDER BY SEQ_IN_INDEX'
    );
    $query->execute([$table, $index]);
    $rows = $query->fetchAll();
    $actualColumns = array_map(static fn(array $row): string => (string) $row['COLUMN_NAME'], $rows);
    $isUnique = $rows !== [] && array_reduce(
        $rows,
        static fn(bool $unique, array $row): bool => $unique && (int) $row['NON_UNIQUE'] === 0,
        true
    );
    if (!$isUnique || $actualColumns !== $expectedColumns) {
        throw new RuntimeException(
            'Kritischer UNIQUE-Index ist nicht kanonisch: ' . $table . '.' . $index
            . ' (erwartet: ' . implode(', ', $expectedColumns) . ')'
        );
    }
}

function migration_record(
    PDO $mysql,
    string $key,
    string $kind,
    string $location,
    string $table,
    string $hash,
    int $sourceRows,
    int $importedRows,
    array $details = []
): void {
    $statement = $mysql->prepare(
        'INSERT INTO storage_migrations '
        . '(migration_key,source_kind,source_location,source_table,source_sha256,source_rows,imported_rows,completed_at,details_json) '
        . 'VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP(),?) '
        . 'ON DUPLICATE KEY UPDATE source_sha256=VALUES(source_sha256),source_rows=VALUES(source_rows),'
        . 'imported_rows=VALUES(imported_rows),completed_at=VALUES(completed_at),details_json=VALUES(details_json)'
    );
    $statement->execute([
        $key,
        $kind,
        $location,
        $table,
        $hash,
        $sourceRows,
        $importedRows,
        json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
}

function migration_import_impressions(PDO $mysql, string $path, string $label): int
{
    $sourceHash = migration_sqlite_snapshot_hash($path);
    $sqlite = migration_open_sqlite($path);
    $sqlite->beginTransaction();
    if (!in_array('impressions', migration_sqlite_tables($sqlite), true)) {
        throw new RuntimeException('Tabelle impressions fehlt in ' . $path);
    }

    $columns = [
        'created_at', 'day', 'event_type', 'network', 'host', 'page_url', 'page_path',
        'page_title', 'referrer', 'referrer_host', 'language', 'screen_width', 'screen_height',
        'viewport_width', 'viewport_height', 'dpr', 'visitor_hash', 'session_hash', 'ua_hash',
        'browser', 'os', 'device', 'is_bot', 'campaign', 'campaign_id', 'creative_id',
        'advertiser_id', 'tracking_id', 'impression_pixel', 'third_party_url',
        'third_party_script_url', 'bidder_url', 'country', 'ssp', 'ad_width', 'ad_height',
    ];
    $sourceColumns = [];
    foreach ($sqlite->query('PRAGMA table_info("impressions")') as $column) {
        $sourceColumns[(string) $column['name']] = true;
    }
    foreach ($columns as $column) {
        if (!isset($sourceColumns[$column])) {
            throw new RuntimeException('Spalte ' . $column . ' fehlt in ' . $path);
        }
    }

    $quotedColumns = array_map('migration_identifier', $columns);
    $placeholders = implode(',', array_fill(0, count($columns) + 2, '?'));
    $insert = $mysql->prepare(
        'INSERT INTO `impressions` (`legacy_source`,`legacy_id`,' . implode(',', $quotedColumns) . ') '
        . 'VALUES (' . $placeholders . ') '
        . 'ON DUPLICATE KEY UPDATE `legacy_id`=VALUES(`legacy_id`)'
    );
    $select = $sqlite->query(
        'SELECT "id",' . implode(',', array_map('migration_sqlite_identifier', $columns))
        . ' FROM "impressions" ORDER BY "id"'
    );

    $sourceRows = 0;
    $mysql->beginTransaction();
    try {
        while ($row = $select->fetch()) {
            $values = [$label, (int) $row['id']];
            foreach ($columns as $column) {
                $values[] = $row[$column];
            }
            $insert->execute($values);
            $sourceRows++;
        }
        if (!hash_equals($sourceHash, migration_sqlite_snapshot_hash($path))) {
            throw new RuntimeException('SQLite-Quelle wurde während des Imports verändert: ' . $path);
        }
        $mysql->commit();
        $sqlite->commit();
    } catch (Throwable $error) {
        if ($mysql->inTransaction()) {
            $mysql->rollBack();
        }
        if ($sqlite->inTransaction()) {
            $sqlite->rollBack();
        }
        throw $error;
    }

    $count = $mysql->prepare('SELECT COUNT(*) FROM `impressions` WHERE `legacy_source`=?');
    $count->execute([$label]);
    $importedRows = (int) $count->fetchColumn();
    migration_record(
        $mysql,
        'sqlite-impressions:' . hash('sha256', $label),
        'sqlite-active',
        $label,
        'impressions',
        $sourceHash,
        $sourceRows,
        $importedRows
    );
    return $importedRows;
}

function migration_archive_sqlite(PDO $mysql, string $path, string $label): int
{
    $sourceHash = migration_sqlite_snapshot_hash($path);
    $sqlite = migration_open_sqlite($path);
    $sqlite->beginTransaction();
    $insert = $mysql->prepare(
        'INSERT INTO `legacy_sqlite_rows` '
        . '(`source_file`,`source_table`,`source_rowid`,`source_created_at`,`row_json`) '
        . 'VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE '
        . '`source_created_at`=VALUES(`source_created_at`),`row_json`=VALUES(`row_json`)'
    );
    $total = 0;
    $mysql->beginTransaction();
    try {
        foreach (migration_sqlite_tables($sqlite) as $table) {
            $sourceRows = migration_sqlite_count($sqlite, $table);
            $primaryKeyColumns = migration_sqlite_primary_key_columns($sqlite, $table);
            $select = $sqlite->query(
                'SELECT rowid AS "__migration_rowid", * FROM ' . migration_sqlite_identifier($table)
            );
            $processed = 0;
            migration_upgrade_archive_row_keys($mysql, $label, $table, $primaryKeyColumns);
            while ($row = $select->fetch()) {
                $sqliteRowId = (string) $row['__migration_rowid'];
                unset($row['__migration_rowid']);
                $sourceRowId = migration_archive_row_key($row, $sqliteRowId, $primaryKeyColumns);
                $sourceCreatedAt = migration_source_datetime($row);
                $sanitized = migration_sanitize_legacy_row($row);
                $json = json_encode(
                    $sanitized,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
                );
                if (!is_string($json)) {
                    throw new RuntimeException('JSON-Kodierung fehlgeschlagen: ' . $label . '/' . $table);
                }
                $insert->execute([$label, $table, $sourceRowId, $sourceCreatedAt, $json]);
                $processed++;
            }
            $count = $mysql->prepare(
                'SELECT COUNT(*) FROM `legacy_sqlite_rows` WHERE `source_file`=? AND `source_table`=?'
            );
            $count->execute([$label, $table]);
            $importedRows = (int) $count->fetchColumn();
            migration_record(
                $mysql,
                'sqlite-archive:' . hash('sha256', $label . "\0" . $table),
                'sqlite-archive',
                $label,
                $table,
                $sourceHash,
                $sourceRows,
                $importedRows,
                [
                    'ip_fields' => 'HMAC-SHA-256; raw IPv4 and IPv6 values removed',
                    'row_identity' => $primaryKeyColumns === [] ? 'SQLite rowid' : implode(',', $primaryKeyColumns),
                ]
            );
            $total += $processed;
        }
        if (!hash_equals($sourceHash, migration_sqlite_snapshot_hash($path))) {
            throw new RuntimeException('SQLite-Quelle wurde während des Imports verändert: ' . $path);
        }
        $mysql->commit();
        $sqlite->commit();
    } catch (Throwable $error) {
        if ($mysql->inTransaction()) {
            $mysql->rollBack();
        }
        if ($sqlite->inTransaction()) {
            $sqlite->rollBack();
        }
        throw $error;
    }
    return $total;
}

function migration_import_ppcmate(PDO $mysql, string $path, string $label): int
{
    $sourceHash = migration_sqlite_snapshot_hash($path);
    $sqlite = migration_open_sqlite($path);
    $sqlite->beginTransaction();
    $tables = migration_sqlite_tables($sqlite);
    foreach (['ppcmate_attributions', 'ppcmate_conversions'] as $required) {
        if (!in_array($required, $tables, true)) {
            throw new RuntimeException('Tabelle ' . $required . ' fehlt in ' . $path);
        }
    }

    $attributionColumns = [
        'client_id', 'tracking_id', 'cost', 'campaign', 'zone', 'ssp', 'geo',
        'landing_url', 'captured_at', 'last_seen_at', 'expires_at',
    ];
    $conversionColumns = [
        'dedupe_hash', 'client_id', 'tracking_id', 'event_key', 'value',
        'created_at', 'sent_at', 'http_status', 'response',
    ];
    $mysql->beginTransaction();
    try {
        $attrSql = 'INSERT INTO `ppcmate_attributions` ('
            . implode(',', array_map('migration_identifier', $attributionColumns)) . ') VALUES ('
            . implode(',', array_fill(0, count($attributionColumns), '?')) . ') ON DUPLICATE KEY UPDATE '
            . '`tracking_id`=IF(VALUES(`last_seen_at`)>`last_seen_at`,VALUES(`tracking_id`),`tracking_id`),'
            . '`cost`=IF(VALUES(`last_seen_at`)>`last_seen_at`,VALUES(`cost`),`cost`),'
            . '`campaign`=IF(VALUES(`last_seen_at`)>`last_seen_at`,VALUES(`campaign`),`campaign`),'
            . '`zone`=IF(VALUES(`last_seen_at`)>`last_seen_at`,VALUES(`zone`),`zone`),'
            . '`ssp`=IF(VALUES(`last_seen_at`)>`last_seen_at`,VALUES(`ssp`),`ssp`),'
            . '`geo`=IF(VALUES(`last_seen_at`)>`last_seen_at`,VALUES(`geo`),`geo`),'
            . '`landing_url`=IF(VALUES(`last_seen_at`)>`last_seen_at`,VALUES(`landing_url`),`landing_url`),'
            . '`captured_at`=IF(VALUES(`last_seen_at`)>`last_seen_at`,VALUES(`captured_at`),`captured_at`),'
            . '`expires_at`=GREATEST(`expires_at`,VALUES(`expires_at`)),'
            . '`last_seen_at`=GREATEST(`last_seen_at`,VALUES(`last_seen_at`))';
        $attrInsert = $mysql->prepare($attrSql);
        $attrSelect = $sqlite->query(
            'SELECT ' . implode(',', array_map('migration_sqlite_identifier', $attributionColumns))
            . ' FROM "ppcmate_attributions"'
        );
        $attributionRows = 0;
        while ($row = $attrSelect->fetch()) {
            $attrInsert->execute(array_map(static fn(string $column): mixed => $row[$column], $attributionColumns));
            $attributionRows++;
        }

        $conversionTargetColumns = array_merge(['legacy_source', 'legacy_id'], $conversionColumns);
        $conversionSql = 'INSERT INTO `ppcmate_conversions` ('
            . implode(',', array_map('migration_identifier', $conversionTargetColumns)) . ') VALUES ('
            . implode(',', array_fill(0, count($conversionTargetColumns), '?')) . ') ON DUPLICATE KEY UPDATE '
            . '`dedupe_hash`=`dedupe_hash`';
        $conversionInsert = $mysql->prepare($conversionSql);
        $conversionSelect = $sqlite->query(
            'SELECT "id",' . implode(',', array_map('migration_sqlite_identifier', $conversionColumns))
            . ' FROM "ppcmate_conversions"'
        );
        $conversionRows = 0;
        while ($row = $conversionSelect->fetch()) {
            $conversionInsert->execute(array_merge(
                [$label, (int) $row['id']],
                array_map(static fn(string $column): mixed => $row[$column], $conversionColumns)
            ));
            $conversionRows++;
        }
        if (!hash_equals($sourceHash, migration_sqlite_snapshot_hash($path))) {
            throw new RuntimeException('SQLite-Quelle wurde während des Imports verändert: ' . $path);
        }
        $mysql->commit();
        $sqlite->commit();
    } catch (Throwable $error) {
        if ($mysql->inTransaction()) {
            $mysql->rollBack();
        }
        if ($sqlite->inTransaction()) {
            $sqlite->rollBack();
        }
        throw $error;
    }

    migration_record(
        $mysql,
        'sqlite-ppcmate:' . hash('sha256', $label),
        'sqlite-active',
        $label,
        'ppcmate_attributions,ppcmate_conversions',
        $sourceHash,
        $attributionRows + $conversionRows,
        $attributionRows + $conversionRows
    );
    return $attributionRows + $conversionRows;
}

/** @return array<string, mixed>|null */
function migration_legacy_stat4_config(string $path, bool $required = false): ?array
{
    if (!is_file($path)) {
        if ($required) {
            throw new RuntimeException('Angegebene STAT4-Quellkonfiguration fehlt: ' . $path);
        }
        return null;
    }
    if (!is_readable($path)) {
        throw new RuntimeException('STAT4-Quellkonfiguration ist nicht lesbar: ' . $path);
    }
    $config = require $path;
    if (!is_array($config)) {
        throw new RuntimeException('STAT4-Quellkonfiguration muss ein Array zurückgeben: ' . $path);
    }
    if (!array_key_exists('db', $config)) {
        if ($required) {
            throw new RuntimeException('Angegebene STAT4-Quellkonfiguration enthält keine DB-Verbindung: ' . $path);
        }
        return null;
    }
    if (!is_array($config['db'])) {
        throw new RuntimeException('Ungültige STAT4-DB-Konfiguration: ' . $path);
    }
    return $config['db'];
}

function migration_stat4_source_pdo(array $db): PDO
{
    $host = trim((string) ($db['host'] ?? ''));
    $database = trim((string) ($db['database'] ?? ''));
    $charset = trim((string) ($db['charset'] ?? 'utf8mb4')) ?: 'utf8mb4';
    if ($host === '' || $database === '') {
        throw new RuntimeException('Alte STAT4-Datenbankkonfiguration ist unvollständig.');
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $charset)) {
        throw new RuntimeException('Ungültiger STAT4-Zeichensatz.');
    }
    if (str_starts_with($host, '/')) {
        $dsn = 'mysql:unix_socket=' . $host . ';dbname=' . $database . ';charset=' . $charset;
    } else {
        $dsn = 'mysql:host=' . $host . ';dbname=' . $database . ';charset=' . $charset;
        if (!empty($db['port'])) {
            $dsn .= ';port=' . (int) $db['port'];
        }
    }
    return new PDO($dsn, (string) ($db['user'] ?? ''), (string) ($db['password'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => max(1, (int) ($db['timeout'] ?? 8)),
    ]);
}

function migration_mysql_server_uuid(PDO $mysql): string
{
    try {
        return trim((string) $mysql->query('SELECT @@server_uuid')->fetchColumn());
    } catch (Throwable) {
        return '';
    }
}

/** @return array<string, int> */
function migration_preview_stat4(array $legacyDb): array
{
    $source = migration_stat4_source_pdo($legacyDb);
    $counts = [];
    $source->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $source->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
    try {
        foreach (['stat4_visitors', 'stat4_sessions', 'stat4_events', 'stat4_notifications'] as $table) {
            $counts[$table] = (int) $source->query(
                'SELECT COUNT(*) FROM ' . migration_identifier($table)
            )->fetchColumn();
        }
        $source->commit();
    } catch (Throwable $error) {
        if ($source->inTransaction()) {
            $source->rollBack();
        }
        throw $error;
    }
    return $counts;
}

function migration_remove_legacy_stat4_db_config(string $path): void
{
    if (!is_file($path)) {
        return;
    }
    if (is_link($path)) {
        throw new RuntimeException('STAT4-Konfiguration ist ein Symlink; automatische Bereinigung abgebrochen.');
    }
    $originalStat = stat($path);
    $originalHash = hash_file('sha256', $path);
    if ($originalStat === false || !is_string($originalHash)) {
        throw new RuntimeException('STAT4-Dateirechte oder Prüfsumme konnten nicht gelesen werden.');
    }
    $config = require $path;
    if (!is_array($config) || !array_key_exists('db', $config)) {
        return;
    }
    unset($config['db']);
    $content = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
    // Create privately beside the original so rename stays atomic. Preserve the
    // PHP worker's access even when the CLI is run as another user (e.g. root).
    $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
    $previousUmask = umask(0077);
    try {
        $handle = @fopen($temporary, 'x');
    } finally {
        umask($previousUmask);
    }
    if ($handle === false) {
        throw new RuntimeException('Temporäre STAT4-Konfiguration konnte nicht angelegt werden.');
    }
    try {
        $written = fwrite($handle, $content);
        if ($written !== strlen($content) || !fflush($handle)) {
            throw new RuntimeException('Alte STAT4-DB-Konfiguration konnte nicht bereinigt werden.');
        }
        fclose($handle);
        $handle = null;
        if (
            (fileowner($temporary) !== $originalStat['uid'] && !@chown($temporary, $originalStat['uid']))
            || (filegroup($temporary) !== $originalStat['gid'] && !@chgrp($temporary, $originalStat['gid']))
            || !@chmod($temporary, $originalStat['mode'] & 0777)
        ) {
            throw new RuntimeException('STAT4-Dateirechte konnten nicht erhalten werden; Original bleibt bestehen.');
        }
        clearstatcache(true, $path);
        $currentHash = hash_file('sha256', $path);
        $currentStat = stat($path);
        if (
            !is_string($currentHash) || !hash_equals($originalHash, $currentHash)
            || $currentStat === false || $currentStat['ino'] !== $originalStat['ino']
            || $currentStat['uid'] !== $originalStat['uid'] || $currentStat['gid'] !== $originalStat['gid']
            || $currentStat['mode'] !== $originalStat['mode']
        ) {
            throw new RuntimeException('STAT4-Konfiguration wurde parallel verändert; Original bleibt bestehen.');
        }
        if (!rename($temporary, $path)) {
            throw new RuntimeException('Bereinigte STAT4-Konfiguration konnte nicht aktiviert werden.');
        }
    } finally {
        if (is_resource($handle)) {
            fclose($handle);
        }
        if (is_file($temporary)) {
            @unlink($temporary);
        }
    }
}

/** @return list<string> */
function migration_mysql_columns(PDO $mysql, string $table): array
{
    $columns = [];
    foreach ($mysql->query('SHOW COLUMNS FROM ' . migration_identifier($table)) as $row) {
        $columns[] = (string) $row['Field'];
    }
    return $columns;
}

function migration_import_stat4(PDO $target, array $legacyDb, string $sourceLabel): int
{
    $source = migration_stat4_source_pdo($legacyDb);
    $targetDatabase = (string) $target->query('SELECT DATABASE()')->fetchColumn();
    $sourceDatabase = (string) $source->query('SELECT DATABASE()')->fetchColumn();
    $targetServerUuid = migration_mysql_server_uuid($target);
    $sourceServerUuid = migration_mysql_server_uuid($source);
    if (
        $targetDatabase === $sourceDatabase
        && $targetServerUuid !== ''
        && hash_equals($targetServerUuid, $sourceServerUuid)
    ) {
        echo "STAT4 ist bereits in der zentralen Datenbank; kein Kopieren nötig.\n";
        return 0;
    }

    $tables = [
        'stat4_visitors' => ['visitor_hash'],
        'stat4_sessions' => ['session_id'],
        'stat4_events' => ['event_uuid'],
        'stat4_notifications' => ['visitor_hash', 'pageview_milestone'],
    ];
    $total = 0;
    $source->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $source->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
    $target->beginTransaction();
    try {
        foreach ($tables as $table => $keys) {
            $sourceColumns = migration_mysql_columns($source, $table);
            $targetColumns = migration_mysql_columns($target, $table);
            $columns = array_values(array_intersect($sourceColumns, $targetColumns));
            if (in_array($table, ['stat4_events', 'stat4_notifications'], true)) {
                $columns = array_values(array_filter($columns, static fn(string $column): bool => $column !== 'id'));
            }
            if ($columns === []) {
                throw new RuntimeException('Keine gemeinsamen Spalten für ' . $table);
            }
            $insert = $target->prepare(
                'INSERT INTO ' . migration_identifier($table) . ' ('
                . implode(',', array_map('migration_identifier', $columns)) . ') VALUES ('
                . implode(',', array_fill(0, count($columns), '?')) . ') ON DUPLICATE KEY UPDATE '
                . migration_identifier($keys[0]) . '=' . migration_identifier($keys[0])
            );
            $select = $source->query(
                'SELECT ' . implode(',', array_map('migration_identifier', $columns))
                . ' FROM ' . migration_identifier($table)
            );
            $sourceRows = 0;
            while ($row = $select->fetch()) {
                $insert->execute(array_map(static fn(string $column): mixed => $row[$column], $columns));
                $sourceRows++;
            }
            migration_record(
                $target,
                'mysql-stat4:' . hash('sha256', $sourceLabel . "\0" . $table),
                'mysql-legacy',
                $sourceLabel,
                $table,
                '',
                $sourceRows,
                $sourceRows,
                ['source_database' => $sourceDatabase, 'target_database' => $targetDatabase]
            );
            $total += $sourceRows;
        }
        $target->commit();
        $source->commit();
    } catch (Throwable $error) {
        if ($target->inTransaction()) {
            $target->rollBack();
        }
        if ($source->inTransaction()) {
            $source->rollBack();
        }
        throw $error;
    }
    return $total;
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) {
    return;
}

$options = getopt('', [
    'commit',
    'skip-stat4',
    'skip-archive',
    'legacy-stat4-config:',
    'confirm-stat4-cutover',
    'ppcmate-db:',
    'help',
]);
if ($options === false) {
    migration_fail('Optionen konnten nicht gelesen werden.');
}
if (isset($options['help'])) {
    migration_usage();
    exit(0);
}

$root = dirname(__DIR__);
$commit = isset($options['commit']);
$skipStat4 = isset($options['skip-stat4']);
$skipArchive = isset($options['skip-archive']);
$confirmStat4Cutover = isset($options['confirm-stat4-cutover']);
if ($skipStat4 && isset($options['legacy-stat4-config'])) {
    migration_fail('--skip-stat4 und --legacy-stat4-config dürfen nicht kombiniert werden.');
}
$stat4ConfigPath = isset($options['legacy-stat4-config'])
    ? (string) $options['legacy-stat4-config']
    : $root . '/stat4/config.local.php';
$ppcmatePath = isset($options['ppcmate-db']) ? (string) $options['ppcmate-db'] : '';
$impressionPath = $root . '/stat/data/impressions.sqlite';
$archivePaths = [
    $root . '/stat/main.sqlite',
    $root . '/stat/tracker.sqlite',
    $root . '/stat/data/human.sqlite',
    $root . '/stat/data/track.sqlite',
    $root . '/stat/data/impressions-old.sqlite',
    $root . '/stat/data/impressions-old2.sqlite',
    $root . '/stat/data/impressions-old4.sqlite',
];

try {
    $config = pixl_config();
    $targetName = (string) ($config['db']['database'] ?? '');
    echo ($commit ? 'IMPORT' : 'VORSCHAU') . ': zentrale MySQL-Datenbank `' . $targetName . '`' . PHP_EOL;

    if (is_file($impressionPath)) {
        $impressionSqlite = migration_open_sqlite($impressionPath);
        echo '- Impressions-Altquelle: ' . migration_sqlite_count($impressionSqlite, 'impressions')
            . ' Zeilen aus ' . migration_relative_path($impressionPath, $root) . PHP_EOL;
    } else {
        echo '- Impressions-Altquelle: keine SQLite-Datei vorhanden' . PHP_EOL;
    }

    if (!$skipArchive) {
        foreach ($archivePaths as $archivePath) {
            if (!is_file($archivePath)) {
                echo '- Legacy-Quelle fehlt, übersprungen: ' . migration_relative_path($archivePath, $root) . PHP_EOL;
                continue;
            }
            $sqlite = migration_open_sqlite($archivePath);
            $parts = [];
            foreach (migration_sqlite_tables($sqlite) as $table) {
                $parts[] = $table . '=' . migration_sqlite_count($sqlite, $table);
            }
            echo '- Legacy-Archiv: ' . migration_relative_path($archivePath, $root)
                . ' (' . implode(', ', $parts) . ')' . PHP_EOL;
        }
    }

    if ($ppcmatePath !== '') {
        $ppcmateSqlite = migration_open_sqlite($ppcmatePath);
        echo '- PPCMate: attributions=' . migration_sqlite_count($ppcmateSqlite, 'ppcmate_attributions')
            . ', conversions=' . migration_sqlite_count($ppcmateSqlite, 'ppcmate_conversions') . PHP_EOL;
    } else {
        echo '- PPCMate: keine alte SQLite-Quelldatei angegeben' . PHP_EOL;
    }

    $legacyStat4 = $skipStat4 ? null : migration_legacy_stat4_config(
        $stat4ConfigPath,
        isset($options['legacy-stat4-config'])
    );
    if ($skipStat4) {
        echo '- STAT4-Altdatenbank: ausdrücklich übersprungen' . PHP_EOL;
    } elseif ($legacyStat4 === null) {
        echo '- STAT4-Altdatenbank: keine alte DB-Konfiguration gefunden' . PHP_EOL;
    } else {
        $stat4Counts = migration_preview_stat4($legacyStat4);
        $parts = [];
        foreach ($stat4Counts as $table => $count) {
            $parts[] = $table . '=' . $count;
        }
        echo '- STAT4-Altdatenbank: `' . (string) ($legacyStat4['database'] ?? '') . '` erreichbar ('
            . implode(', ', $parts) . ')' . PHP_EOL;
    }

    if ($commit && $legacyStat4 !== null && !$confirmStat4Cutover) {
        throw new RuntimeException(
            'STAT4-Altquelle erkannt. Alten STAT4-Collector zuerst anhalten und den Lauf '
            . 'mit --confirm-stat4-cutover wiederholen.'
        );
    }

    if (!$commit) {
        echo PHP_EOL . 'Keine Statistik- oder Konfigurationsdaten wurden verändert; das MySQL-Ziel wurde nicht geprüft.' . PHP_EOL;
        echo 'Mit --commit wird importiert; bei vorhandener STAT4-Altquelle zusätzlich --confirm-stat4-cutover nach Schreibpause.' . PHP_EOL;
        exit(0);
    }

    $mysql = pixl_pdo();
    if ($mysql->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        throw new RuntimeException('Das Ziel ist keine MySQL/MariaDB-Verbindung.');
    }
    migration_ensure_schema($mysql, $root);
    $database = (string) $mysql->query('SELECT DATABASE()')->fetchColumn();
    echo PHP_EOL . 'Schema bereit in `' . $database . '`.' . PHP_EOL;

    if (is_file($impressionPath)) {
        $impressionLabel = migration_relative_path($impressionPath, $root);
        $impressionRows = migration_import_impressions($mysql, $impressionPath, $impressionLabel);
        echo '- Impressions in MySQL: ' . $impressionRows . PHP_EOL;
    }

    if (!$skipArchive) {
        foreach ($archivePaths as $archivePath) {
            if (!is_file($archivePath)) {
                continue;
            }
            $label = migration_relative_path($archivePath, $root);
            $archived = migration_archive_sqlite($mysql, $archivePath, $label);
            echo '- Legacy-Zeilen archiviert: ' . $label . ' = ' . $archived . PHP_EOL;
        }
    }

    if ($ppcmatePath !== '') {
        $ppcmateRows = migration_import_ppcmate($mysql, $ppcmatePath, basename($ppcmatePath));
        echo '- PPCMate-Zeilen importiert: ' . $ppcmateRows . PHP_EOL;
    }

    if (!$skipStat4 && $legacyStat4 !== null) {
        $stat4Rows = migration_import_stat4($mysql, $legacyStat4, basename($stat4ConfigPath));
        echo '- STAT4-Zeilen importiert: ' . $stat4Rows . PHP_EOL;
        migration_remove_legacy_stat4_db_config($stat4ConfigPath);
        echo '- Alte separate STAT4-Datenbankfelder aus config.local.php entfernt' . PHP_EOL;
    }

    echo PHP_EOL . 'FERTIG: Alle aktiven Speicherziele verwenden dieselbe MySQL-Datenbank.' . PHP_EOL;
    if ($skipStat4) {
        echo 'STAT4-Altdaten wurden in diesem Lauf ausdrücklich nicht importiert.' . PHP_EOL;
    }
    if ($ppcmatePath === '') {
        echo 'Eine ehemalige PPCMate-SQLite-Datenbank wurde in diesem Lauf nicht importiert.' . PHP_EOL;
    }
    echo 'Die SQLite-Quelldateien wurden nicht verändert und bleiben als Sicherungen erhalten.' . PHP_EOL;
} catch (Throwable $error) {
    migration_fail($error->getMessage());
}
