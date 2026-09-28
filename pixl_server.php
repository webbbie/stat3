<?php
declare(strict_types=1);

function pixl_stats_time_filter($rangeValue, int $days = 30): array
{
    $range = is_string($rangeValue) ? $rangeValue : '';
    if ($range === '60s' || $range === '60m') {
        $range = '60min';
    }
    $days = max(1, min(365, $days));
    $ranges = [
        '60min' => ['modifier' => '-60 minutes', 'label' => 'letzte 60 Minuten'],
        '24h' => ['modifier' => '-24 hours', 'label' => 'letzte 24 Stunden'],
        '1d' => ['modifier' => '', 'label' => 'heute seit 00:00 Uhr'],
    ];
    for ($rangeDays = 1; $rangeDays <= 7; $rangeDays++) {
        $ranges['last' . $rangeDays . 'd'] = [
            'modifier' => '-' . $rangeDays . ' days',
            'label' => $rangeDays === 1 ? 'letzter 1 Tag' : 'letzte ' . $rangeDays . ' Tage',
        ];
    }

    if (!isset($ranges[$range])) {
        $range = '';
    }

    $label = $range === '' ? $days . ' Tage' : $ranges[$range]['label'];
    if ($range === '1d') {
        $since = (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))
            ->setTime(0, 0, 0)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    } else {
        $modifier = $range === '' ? '-' . $days . ' days' : $ranges[$range]['modifier'];
        $since = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify($modifier)
            ->format('Y-m-d H:i:s');
    }

    return [
        'range' => $range,
        'days' => $days,
        'since' => $since,
        'label' => $label,
    ];
}

function pixl_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $path = __DIR__ . '/pixl_config.php';
    if (!is_file($path)) {
        throw new RuntimeException('pixl_config.php fehlt.');
    }

    $config = require $path;
    if (!is_array($config)) {
        throw new RuntimeException('pixl_config.php muss ein Array zurueckgeben.');
    }

    return $config;
}

require_once __DIR__ . '/pixl_geoip.php';

function pixl_table_name(): string
{
    $config = pixl_config();
    $table = (string)($config['table'] ?? 'pixl_events');
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        throw new RuntimeException('Ungueltiger Tabellenname.');
    }
    return $table;
}

function pixl_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = pixl_config();
    $db = $config['db'] ?? [];
    $charset = (string)($db['charset'] ?? 'utf8mb4');
    $dsn = pixl_mysql_dsn($db, $charset);

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => (int)($db['timeout'] ?? 8),
    ];
    if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
        $options[PDO::MYSQL_ATTR_INIT_COMMAND] = 'SET NAMES ' . $charset . ' COLLATE utf8mb4_unicode_ci';
    }

    $pdo = new PDO($dsn, (string)($db['user'] ?? ''), (string)($db['password'] ?? ''), $options);

    return $pdo;
}

function pixl_mysql_dsn(array $db, string $charset): string
{
    if (!empty($db['socket'])) {
        return sprintf(
            'mysql:unix_socket=%s;dbname=%s;charset=%s',
            (string)$db['socket'],
            (string)($db['database'] ?? ''),
            $charset
        );
    }

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        (string)($db['host'] ?? 'localhost'),
        (string)($db['database'] ?? ''),
        $charset
    );

    if (!empty($db['port'])) {
        $dsn .= ';port=' . (int)$db['port'];
    }

    return $dsn;
}

function pixl_sql_page_expression(string $alias = ''): string
{
    if ($alias !== '' && !preg_match('/^[A-Za-z0-9_]+$/', $alias)) {
        throw new RuntimeException('Ungueltiger SQL-Alias.');
    }

    $prefix = $alias !== '' ? '`' . $alias . '`.' : '';
    $raw = "COALESCE(NULLIF({$prefix}`page_url`, ''), NULLIF({$prefix}`path`, ''), '/')";
    return "COALESCE(NULLIF(SUBSTRING_INDEX(SUBSTRING_INDEX($raw, '?', 1), '#', 1), ''), '/')";
}

function pixl_sql_path_expression(string $alias = ''): string
{
    if ($alias !== '' && !preg_match('/^[A-Za-z0-9_]+$/', $alias)) {
        throw new RuntimeException('Ungueltiger SQL-Alias.');
    }

    $prefix = $alias !== '' ? '`' . $alias . '`.' : '';
    return "COALESCE(NULLIF(SUBSTRING_INDEX(SUBSTRING_INDEX({$prefix}`path`, '?', 1), '#', 1), ''), '/')";
}

function pixl_sql_referrer_expression(string $alias = ''): string
{
    if ($alias !== '' && !preg_match('/^[A-Za-z0-9_]+$/', $alias)) {
        throw new RuntimeException('Ungueltiger SQL-Alias.');
    }

    $prefix = $alias !== '' ? '`' . $alias . '`.' : '';
    return "COALESCE(NULLIF(SUBSTRING_INDEX(SUBSTRING_INDEX({$prefix}`referrer`, '?', 1), '#', 1), ''), 'direct')";
}

function pixl_sql_exclude_german_country_condition(string $alias = ''): string
{
    if ($alias !== '' && !preg_match('/^[A-Za-z0-9_]+$/', $alias)) {
        throw new RuntimeException('Ungueltiger SQL-Alias.');
    }

    $prefix = $alias !== '' ? '`' . $alias . '`.' : '';
    return "COALESCE(UPPER(TRIM({$prefix}`country`)), '') NOT IN ('DE', 'DEU', 'GER', 'GERMANY', 'DEUTSCHLAND')";
}

function pixl_visual_bar_cap(array $values, float $maxRatio = 1.1): float
{
    $normalized = [];
    foreach ($values as $value) {
        $normalized[] = max(0.0, (float)$value);
    }
    rsort($normalized, SORT_NUMERIC);

    $largest = $normalized[0] ?? 0.0;
    $second = $normalized[1] ?? 0.0;
    if ($second > 0.0) {
        $largest = min($largest, $second * max(1.0, $maxRatio));
    }

    return max(1.0, $largest);
}

function pixl_url_without_parameters($value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    $value = explode('?', $value, 2)[0];
    return explode('#', $value, 2)[0];
}

function pixl_normalize_configured_url($value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    if ($value[0] === '/') {
        $path = parse_url($value, PHP_URL_PATH);
        return is_string($path) && $path !== '' ? $path : '/';
    }

    $parts = parse_url($value);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
        return '';
    }

    $scheme = strtolower((string)$parts['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) {
        return '';
    }

    $host = strtolower(rtrim((string)$parts['host'], '.'));
    if ($host === '') {
        return '';
    }

    $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
    $path = isset($parts['path']) && (string)$parts['path'] !== '' ? (string)$parts['path'] : '/';
    return $scheme . '://' . $host . $port . $path;
}

function pixl_configured_stats_urls(): array
{
    $configured = pixl_config()['stats_urls'] ?? [];
    if (!is_array($configured)) {
        return [];
    }

    $urls = [];
    foreach ($configured as $value) {
        $normalized = pixl_normalize_configured_url($value);
        if ($normalized !== '') {
            $urls[$normalized] = true;
        }
    }
    return array_keys($urls);
}

function pixl_configured_stats_url_rules(): array
{
    $rules = [];
    foreach (pixl_configured_stats_urls() as $url) {
        if ($url[0] === '/') {
            $rules[] = ['host' => '', 'path' => $url];
            continue;
        }

        $host = parse_url($url, PHP_URL_HOST);
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($host) || $host === '') {
            continue;
        }
        $rules[] = [
            'host' => strtolower(rtrim($host, '.')),
            'path' => is_string($path) && $path !== '' ? $path : '/',
        ];
    }
    return $rules;
}

function pixl_event_matches_configured_stats_url(array $row): bool
{
    $rules = pixl_configured_stats_url_rules();
    if (!$rules) {
        return true;
    }

    $pageUrl = trim((string)($row['page_url'] ?? ''));
    $host = strtolower(rtrim(trim((string)($row['hostname'] ?? '')), '.'));
    if ($host === '' && $pageUrl !== '') {
        $parsedHost = parse_url($pageUrl, PHP_URL_HOST);
        if (is_string($parsedHost)) {
            $host = strtolower(rtrim($parsedHost, '.'));
        }
    }

    $path = trim((string)($row['path'] ?? ''));
    if ($path === '' && $pageUrl !== '') {
        $parsedPath = parse_url($pageUrl, PHP_URL_PATH);
        $path = is_string($parsedPath) ? $parsedPath : '';
    }
    $parsedPath = parse_url($path, PHP_URL_PATH);
    if (is_string($parsedPath) && $parsedPath !== '') {
        $path = $parsedPath;
    }
    $path = pixl_normalize_configured_url($path !== '' ? $path : '/');

    foreach ($rules as $rule) {
        if ($path === $rule['path'] && ($rule['host'] === '' || $host === $rule['host'])) {
            return true;
        }
    }
    return false;
}

function pixl_sql_configured_stats_url_condition(PDO $pdo, string $alias = ''): string
{
    if ($alias !== '' && !preg_match('/^[A-Za-z0-9_]+$/', $alias)) {
        throw new RuntimeException('Ungueltiger SQL-Alias.');
    }

    $rules = pixl_configured_stats_url_rules();
    if (!$rules) {
        return '';
    }

    $prefix = $alias !== '' ? '`' . $alias . '`.' : '';
    $pathExpression = pixl_sql_path_expression($alias);
    $conditions = [];
    foreach ($rules as $rule) {
        $path = $pdo->quote((string)$rule['path']);
        if ($rule['host'] === '') {
            $conditions[] = "$pathExpression = $path";
            continue;
        }
        $host = $pdo->quote((string)$rule['host']);
        $conditions[] = "(LOWER({$prefix}`hostname`) = $host AND $pathExpression = $path)";
    }

    return '(' . implode(' OR ', $conditions) . ')';
}

function pixl_ensure_schema(PDO $pdo): void
{
    $table = pixl_table_name();
    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `$table` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_id` VARCHAR(80) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at` DATETIME NULL,
  `site_id` VARCHAR(100) NOT NULL DEFAULT '',
  `reason` VARCHAR(40) NOT NULL DEFAULT '',
  `title` VARCHAR(255) NOT NULL DEFAULT '',
  `hostname` VARCHAR(255) NOT NULL DEFAULT '',
  `page_url` TEXT NULL,
  `path` VARCHAR(1024) NOT NULL DEFAULT '',
  `referrer` TEXT NULL,
  `browser` VARCHAR(80) NOT NULL DEFAULT '',
  `os` VARCHAR(80) NOT NULL DEFAULT '',
  `device` VARCHAR(80) NOT NULL DEFAULT '',
  `country` VARCHAR(20) NOT NULL DEFAULT '',
  `language` VARCHAR(40) NOT NULL DEFAULT '',
  `screen` VARCHAR(40) NOT NULL DEFAULT '',
  `viewport` VARCHAR(40) NOT NULL DEFAULT '',
  `screen_category` VARCHAR(40) NOT NULL DEFAULT '',
  `known_resolution` TINYINT(1) NOT NULL DEFAULT 0,
  `session_duration` INT UNSIGNED NULL,
  `reading_label` VARCHAR(40) NOT NULL DEFAULT '',
  `reading_seconds` INT UNSIGNED NULL,
  `reading_score` INT UNSIGNED NULL,
  `v3_user_score` DECIMAL(10,2) NULL,
  `render_status` VARCHAR(255) NOT NULL DEFAULT '',
  `console_error_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `dialog_error_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `source` VARCHAR(40) NOT NULL DEFAULT '',
  `is_bot` TINYINT(1) NOT NULL DEFAULT 0,
  `bot_score` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `bot_category` VARCHAR(80) NOT NULL DEFAULT '',
  `bot_name` VARCHAR(120) NOT NULL DEFAULT '',
  `bot_reasons` TEXT NULL,
  `visitor_hash` CHAR(64) NOT NULL DEFAULT '',
  `ip_hash` CHAR(64) NOT NULL DEFAULT '',
  `request_method` VARCHAR(12) NOT NULL DEFAULT '',
  `request_uri` TEXT NULL,
  `user_agent` TEXT NULL,
  `message` TEXT NULL,
  `payload_json` LONGTEXT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `event_id` (`event_id`),
  KEY `created_at` (`created_at`),
  KEY `site_reason_created` (`site_id`, `reason`, `created_at`),
  KEY `host_path_created` (`hostname`, `path`(191), `created_at`),
  KEY `device_created` (`device`, `browser`, `os`, `created_at`),
  KEY `country_created` (`country`, `created_at`),
  KEY `bot_created` (`is_bot`, `bot_category`, `created_at`),
  KEY `visitor_created` (`visitor_hash`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    $pdo->exec($sql);

    pixl_try_alter($pdo, "ALTER TABLE `$table` ADD COLUMN `source` VARCHAR(40) NOT NULL DEFAULT '' AFTER `dialog_error_count`");
    pixl_try_alter($pdo, "ALTER TABLE `$table` ADD COLUMN `is_bot` TINYINT(1) NOT NULL DEFAULT 0 AFTER `source`");
    pixl_try_alter($pdo, "ALTER TABLE `$table` ADD COLUMN `bot_score` SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER `is_bot`");
    pixl_try_alter($pdo, "ALTER TABLE `$table` ADD COLUMN `bot_category` VARCHAR(80) NOT NULL DEFAULT '' AFTER `bot_score`");
    pixl_try_alter($pdo, "ALTER TABLE `$table` ADD COLUMN `bot_name` VARCHAR(120) NOT NULL DEFAULT '' AFTER `bot_category`");
    pixl_try_alter($pdo, "ALTER TABLE `$table` ADD COLUMN `bot_reasons` TEXT NULL AFTER `bot_name`");
    pixl_try_alter($pdo, "ALTER TABLE `$table` ADD COLUMN `request_method` VARCHAR(12) NOT NULL DEFAULT '' AFTER `ip_hash`");
    pixl_try_alter($pdo, "ALTER TABLE `$table` ADD COLUMN `request_uri` TEXT NULL AFTER `request_method`");
    pixl_try_alter($pdo, "ALTER TABLE `$table` ADD KEY `bot_created` (`is_bot`, `bot_category`, `created_at`)");
}

/** Read and validate the complete create-only schema before executing any SQL. */
function pixl_unified_schema_definitions(?string $path = null, ?string $eventTable = null): array
{
    $eventTable ??= pixl_table_name();
    if (!preg_match('/^[A-Za-z0-9_]+$/', $eventTable)) {
        throw new RuntimeException('Ungueltiger Tabellenname.');
    }
    $sql = @file_get_contents($path ?? __DIR__ . '/pixl_schema.sql');
    if ($sql === false) throw new RuntimeException('pixl_schema.sql fehlt oder ist nicht lesbar.');
    $sql = preg_replace('/^\s*--[^\r\n]*/m', '', $sql) ?? '';
    $definitions = [];
    $names = ['pixl_events', 'pixl_events_push_subscriptions', 'pixl_events_push_meta'];
    $replacements = array_combine(
        array_map(static fn(string $name): string => '`' . $name . '`', $names),
        ['`' . $eventTable . '`', '`' . $eventTable . '_push_subscriptions`', '`' . $eventTable . '_push_meta`']
    );
    foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [] as $statement) {
        $statement = trim(strtr($statement, $replacements));
        if ($statement === '') continue;
        if (str_contains($statement, ';')
            || !preg_match('/\ACREATE TABLE IF NOT EXISTS `([A-Za-z0-9_]+)`\s*\(/', $statement, $match)
            || isset($definitions[$match[1]])) {
            throw new RuntimeException('Das zentrale Schema darf nur eindeutige CREATE TABLE IF NOT EXISTS-Anweisungen enthalten.');
        }
        $definitions[$match[1]] = $statement;
    }
    $expected = [
        $eventTable, $eventTable . '_push_subscriptions', $eventTable . '_push_meta',
        'pixl_captcha_state', 'pixl_captcha_visitors', 'pixl_captcha_stats', 'pixl_captcha_page_views',
        'stat4_visitors', 'stat4_sessions', 'stat4_events', 'stat4_notifications',
        'mind_geo_cache', 'mind_notifications', 'impressions',
        'ppcmate_attributions', 'ppcmate_conversions', 'legacy_sqlite_rows', 'storage_migrations',
    ];
    if (count($definitions) !== count($expected) || array_diff($expected, array_keys($definitions))) {
        throw new RuntimeException('pixl_schema.sql ist unvollstaendig oder passt nicht zu dieser Stats3-Version.');
    }
    return $definitions;
}

/** Add missing tables only; existing rows, schemas and legacy sources stay intact. */
function pixl_ensure_unified_schema(PDO $pdo): array
{
    $definitions = pixl_unified_schema_definitions();
    $tableSql = "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'";
    $existing = $pdo->query($tableSql)->fetchAll(PDO::FETCH_COLUMN);
    $created = [];
    foreach ($definitions as $name => $statement) {
        if (in_array($name, $existing, true)) continue;
        $pdo->exec($statement);
        $created[] = $name;
    }
    $missing = array_diff(array_keys($definitions), $pdo->query($tableSql)->fetchAll(PDO::FETCH_COLUMN));
    if ($missing) throw new RuntimeException('Zentrale Tabellen fehlen weiterhin: ' . implode(', ', $missing));
    return ['tables' => array_keys($definitions), 'created' => $created];
}

function pixl_try_alter(PDO $pdo, string $sql): void
{
    try {
        $pdo->exec($sql);
    } catch (Throwable $e) {
        $message = strtolower($e->getMessage());
        if (
            strpos($message, 'duplicate column') === false &&
            strpos($message, 'duplicate key') === false &&
            strpos($message, 'already exists') === false
        ) {
            throw $e;
        }
    }
}

function pixl_json_response(array $data, int $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function pixl_get(array $data, array $path, $default = null)
{
    $cursor = $data;
    foreach ($path as $key) {
        if (!is_array($cursor) || !array_key_exists($key, $cursor)) {
            return $default;
        }
        $cursor = $cursor[$key];
    }
    return $cursor;
}

function pixl_string($value, int $maxLength = 255): string
{
    if ($value === null) {
        return '';
    }
    if (is_bool($value)) {
        $value = $value ? '1' : '0';
    }
    if (is_array($value) || is_object($value)) {
        $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    $string = trim((string)$value);
    if ($maxLength > 0 && function_exists('mb_substr')) {
        return mb_substr($string, 0, $maxLength, 'UTF-8');
    }
    return $maxLength > 0 ? substr($string, 0, $maxLength) : $string;
}

function pixl_nullable_int($value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    return is_numeric($value) ? max(0, (int)$value) : null;
}

function pixl_nullable_float($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    return is_numeric($value) ? (float)$value : null;
}

function pixl_bool_int($value): int
{
    return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
}

function pixl_detect_bot(string $userAgent, array $payload = []): array
{
    $ua = strtolower($userAgent);
    $score = 0;
    $category = '';
    $name = '';
    $reasons = [];

    $add = static function (int $points, string $reason, string $nextCategory = '', string $nextName = '') use (&$score, &$category, &$name, &$reasons): void {
        $score += $points;
        if ($reason !== '' && !in_array($reason, $reasons, true)) {
            $reasons[] = $reason;
        }
        if ($category === '' && $nextCategory !== '') {
            $category = $nextCategory;
        }
        if ($name === '' && $nextName !== '') {
            $name = $nextName;
        }
    };

    $known = [
        ['googlebot', 'search', 'Googlebot'],
        ['bingbot', 'search', 'Bingbot'],
        ['duckduckbot', 'search', 'DuckDuckBot'],
        ['baiduspider', 'search', 'Baiduspider'],
        ['yandexbot', 'search', 'YandexBot'],
        ['yandex', 'search', 'Yandex'],
        ['applebot', 'search', 'Applebot'],
        ['facebookexternalhit', 'social', 'Facebook'],
        ['facebot', 'social', 'Facebook'],
        ['twitterbot', 'social', 'Twitterbot'],
        ['linkedinbot', 'social', 'LinkedInBot'],
        ['slackbot', 'social', 'Slackbot'],
        ['discordbot', 'social', 'Discordbot'],
        ['whatsapp', 'social', 'WhatsApp'],
        ['telegrambot', 'social', 'TelegramBot'],
        ['semrush', 'seo', 'Semrush'],
        ['ahrefs', 'seo', 'Ahrefs'],
        ['mj12bot', 'seo', 'MJ12bot'],
        ['dotbot', 'seo', 'DotBot'],
        ['petalbot', 'seo', 'PetalBot'],
        ['bytespider', 'ai', 'ByteSpider'],
        ['gptbot', 'ai', 'GPTBot'],
        ['chatgpt-user', 'ai', 'ChatGPT-User'],
        ['chatgpt', 'ai', 'ChatGPT'],
        ['claude', 'ai', 'Claude'],
        ['anthropic-ai', 'ai', 'Anthropic'],
        ['perplexity', 'ai', 'Perplexity'],
        ['ccbot', 'crawler', 'CCBot'],
        ['commoncrawl', 'crawler', 'Common Crawl'],
        ['headlesschrome', 'automation', 'HeadlessChrome'],
        ['playwright', 'automation', 'Playwright'],
        ['puppeteer', 'automation', 'Puppeteer'],
        ['selenium', 'automation', 'Selenium'],
        ['phantomjs', 'automation', 'PhantomJS'],
        ['curl', 'tool', 'curl'],
        ['wget', 'tool', 'wget'],
        ['python-requests', 'tool', 'Python requests'],
        ['python', 'tool', 'Python'],
        ['aiohttp', 'tool', 'aiohttp'],
        ['httpclient', 'tool', 'HTTP client'],
        ['go-http-client', 'tool', 'Go HTTP client'],
        ['okhttp', 'tool', 'OkHttp'],
        ['node-fetch', 'tool', 'node-fetch'],
        ['axios', 'tool', 'Axios'],
        ['php', 'tool', 'PHP client'],
        ['java/', 'tool', 'Java client'],
        ['feedfetcher', 'feed', 'Feedfetcher'],
        ['validator', 'validator', 'Validator'],
        ['lighthouse', 'audit', 'Lighthouse'],
    ];

    foreach ($known as $pattern) {
        if ($ua !== '' && strpos($ua, $pattern[0]) !== false) {
            $add(40, 'ua:' . $pattern[0], $pattern[1], $pattern[2]);
        }
    }

    $generic = [
        'bot', 'crawler', 'spider', 'scraper', 'slurp', 'headless',
        'monitor', 'uptime', 'scanner', 'fetch', 'crawl', 'preview'
    ];
    foreach ($generic as $pattern) {
        if ($ua !== '' && strpos($ua, $pattern) !== false) {
            $add(18, 'pattern:' . $pattern, $category !== '' ? $category : 'crawler', $name !== '' ? $name : $pattern);
        }
    }

    if (trim($userAgent) === '') {
        $add(25, 'missing-user-agent', 'unknown', 'Unknown UA');
    }

    if (preg_match('/^(curl|wget|python|java|okhttp|go-http-client|php|node|axios)/i', $userAgent)) {
        $add(35, 'tool-like-user-agent-prefix', 'tool', $name !== '' ? $name : 'HTTP tool');
    }

    if (pixl_get($payload, ['flags', 'webdriver'], false)) {
        $add(60, 'client:navigator.webdriver', 'automation', $name !== '' ? $name : 'webdriver');
    }

    if (pixl_get($payload, ['bot', 'isBot'], false)) {
        $add(45, 'client:bot-flag', pixl_string(pixl_get($payload, ['bot', 'category'], ''), 80), pixl_string(pixl_get($payload, ['bot', 'name'], ''), 120));
        $clientReasons = pixl_get($payload, ['bot', 'reasons'], []);
        if (is_array($clientReasons)) {
            foreach ($clientReasons as $reason) {
                $add(0, 'client:' . pixl_string($reason, 120));
            }
        }
    }

    $score = max(0, min(100, $score));
    $isBot = $score >= 35;

    return [
        'is_bot' => $isBot ? 1 : 0,
        'score' => $score,
        'category' => $category !== '' ? $category : ($isBot ? 'unknown' : 'human'),
        'name' => $name !== '' ? $name : ($isBot ? 'Unknown bot' : 'Human-like'),
        'reasons' => $reasons,
    ];
}

function pixl_parse_sent_at($value): ?string
{
    if (!is_string($value) || trim($value) === '') {
        return null;
    }
    try {
        $dt = new DateTimeImmutable($value);
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return null;
    }
}

function pixl_remote_ip(): string
{
    return pixl_geoip_client_ip($_SERVER);
}

function pixl_hash(string $value): string
{
    if ($value === '') {
        return '';
    }
    $config = pixl_config();
    $salt = (string)($config['hash_salt'] ?? '');
    return hash_hmac('sha256', $value, $salt !== '' ? $salt : 'pixl');
}

function pixl_ends_with(string $haystack, string $needle): bool
{
    if ($needle === '') {
        return true;
    }
    return substr($haystack, -strlen($needle)) === $needle;
}

function pixl_allowed_host(string $host): bool
{
    $config = pixl_config();
    $allowed = $config['allowed_hosts'] ?? [];
    if (!is_array($allowed) || !$allowed) {
        return true;
    }

    $host = strtolower(trim($host));
    foreach ($allowed as $entry) {
        $entry = strtolower(trim((string)$entry));
        if ($entry === '') {
            continue;
        }
        if ($host === $entry || pixl_ends_with($host, '.' . $entry)) {
            return true;
        }
    }
    return false;
}

function pixl_apply_cors(): void
{
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '') {
        $host = parse_url($origin, PHP_URL_HOST);
        if (is_string($host) && pixl_allowed_host($host)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        }
    }
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 86400');
}

function pixl_stats_cookie_name(): string
{
    $config = pixl_config();
    $name = (string)($config['stats_cookie_name'] ?? 'pixl_stats_login');
    return preg_match('/^[A-Za-z0-9_\\-]+$/', $name) ? $name : 'pixl_stats_login';
}

function pixl_stats_auth_token(): string
{
    $config = pixl_config();
    $password = (string)($config['stats_password'] ?? '');
    $salt = (string)($config['hash_salt'] ?? '');
    return hash_hmac('sha256', 'pixl-stats|' . $password, $salt !== '' ? $salt : 'pixl');
}

function pixl_stats_password_ok(string $given): bool
{
    $config = pixl_config();
    $password = (string)($config['stats_password'] ?? '');
    if ($password === '') {
        return true;
    }

    if (preg_match('/^\\$2y\\$|^\\$argon2/i', $password)) {
        return password_verify($given, $password);
    }

    return hash_equals($password, $given);
}

function pixl_set_stats_auth_cookie(): void
{
    $config = pixl_config();
    $days = max(1, min(365, (int)($config['stats_auto_login_days'] ?? 30)));
    setcookie(pixl_stats_cookie_name(), pixl_stats_auth_token(), [
        'expires' => time() + ($days * 86400),
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function pixl_clear_stats_auth_cookie(): void
{
    setcookie(pixl_stats_cookie_name(), '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function pixl_stats_safe_return_url($value): string
{
    $value = trim((string)$value);
    if ($value === '' || preg_match('/[\r\n]/', $value)) {
        return '';
    }

    $parts = parse_url($value);
    if (!is_array($parts) || isset($parts['scheme']) || isset($parts['host'])) {
        return '';
    }

    $path = (string)($parts['path'] ?? '');
    $allowedPaths = [
        'pixl_stats.php',
        'configurator.php',
        'reset_stats.php',
        'pixl_setup_check.php',
        'comp.php',
        'utm.php',
        'live.php',
        'export.php',
        'stat/index.php',
        'stat/dashboard.php',
        'stat/dashboardx2.html',
        'stat/checkthis.php',
    ];
    if (!in_array($path, $allowedPaths, true)) {
        return '';
    }

    $queryValues = [];
    parse_str((string)($parts['query'] ?? ''), $queryValues);
    $query = [];
    foreach (['days', 'range', 'limit', 'ar', 'bot', 'exclude_germans', 'top25', 'scope', 'q'] as $key) {
        if (isset($queryValues[$key]) && is_scalar($queryValues[$key])) {
            $query[$key] = substr((string)$queryValues[$key], 0, 40);
        }
    }
    if ($path === 'live.php' && ($queryValues['view'] ?? '') === 'useragents') $query['view'] = 'useragents';

    return $path . ($query ? '?' . http_build_query($query) : '');
}

function pixl_redirect_without_password(): void
{
    $returnUrl = pixl_stats_safe_return_url($_GET['return'] ?? '');
    if ($returnUrl !== '') {
        header('Location: ' . $returnUrl);
        exit;
    }

    $params = $_GET;
    unset($params['key'], $params['logout']);
    $query = $params ? '?' . http_build_query($params) : '';
    header('Location: ' . strtok((string)($_SERVER['REQUEST_URI'] ?? 'pixl_stats.php'), '?') . $query);
    exit;
}

function pixl_render_stats_login(bool $failed = false): void
{
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    $days = htmlspecialchars((string)($_GET['days'] ?? '30'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $range = htmlspecialchars((string)($_GET['range'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $error = $failed ? '<p class="error">Passwort stimmt nicht.</p>' : '';
    echo <<<HTML
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Pixl Statistik Login</title>
  <style>
    body { margin: 0; min-height: 100vh; display: grid; place-items: center; font: 14px/1.45 system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color: #17202a; background: #f6f7f9; }
    form { width: min(420px, calc(100vw - 32px)); padding: 24px; border: 1px solid #d8dee8; border-radius: 8px; background: #fff; }
    h1 { margin: 0 0 16px; font-size: 22px; letter-spacing: 0; }
    label { display: block; margin-bottom: 6px; color: #667085; font-weight: 700; }
    input, button { width: 100%; min-height: 40px; border: 1px solid #d8dee8; border-radius: 6px; padding: 0 10px; font: inherit; }
    button { margin-top: 12px; color: #fff; border-color: #146c94; background: #146c94; cursor: pointer; }
    .hint { margin: 12px 0 0; color: #667085; }
    .error { margin: 0 0 12px; color: #a84818; font-weight: 700; }
  </style>
</head>
<body>
  <form method="post" autocomplete="on">
    <h1>Pixl Statistik</h1>
    $error
    <input type="hidden" name="days" value="$days">
    <input type="hidden" name="range" value="$range">
    <label for="stats_password">Passwort</label>
    <input id="stats_password" name="stats_password" type="password" autocomplete="current-password" autofocus required>
    <button type="submit">Einloggen</button>
    <p class="hint">Nach dem Login bleibt dieser Browser automatisch angemeldet.</p>
  </form>
</body>
</html>
HTML;
    exit;
}

function pixl_require_stats_auth(): void
{
    $config = pixl_config();
    $password = (string)($config['stats_password'] ?? '');
    if ($password === '') {
        $returnUrl = pixl_stats_safe_return_url($_GET['return'] ?? '');
        if ($returnUrl !== '') {
            header('Location: ' . $returnUrl);
            exit;
        }
        return;
    }

    if (isset($_GET['logout'])) {
        pixl_clear_stats_auth_cookie();
        pixl_render_stats_login(false);
    }

    $cookie = (string)($_COOKIE[pixl_stats_cookie_name()] ?? '');
    if ($cookie !== '' && hash_equals(pixl_stats_auth_token(), $cookie)) {
        $returnUrl = pixl_stats_safe_return_url($_GET['return'] ?? '');
        if ($returnUrl !== '') {
            header('Location: ' . $returnUrl);
            exit;
        }
        return;
    }

    if (isset($_GET['key']) && pixl_stats_password_ok((string)$_GET['key'])) {
        pixl_set_stats_auth_cookie();
        pixl_redirect_without_password();
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $given = (string)($_POST['stats_password'] ?? '');
        if (pixl_stats_password_ok($given)) {
            pixl_set_stats_auth_cookie();
            $returnUrl = pixl_stats_safe_return_url($_GET['return'] ?? '');
            if ($returnUrl !== '') {
                header('Location: ' . $returnUrl);
                exit;
            }
            if (basename((string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH)) === 'export.php') {
                header('Location: export.php');
                exit;
            }
            if (basename((string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH)) === 'live.php'
                && ($_GET['view'] ?? '') === 'useragents') {
                $userAgentsReturn = 'live.php?' . http_build_query(array_intersect_key($_GET, ['view' => true, 'range' => true]));
                header('Location: ' . pixl_stats_safe_return_url($userAgentsReturn));
                exit;
            }
            $days = max(1, min(365, (int)($_POST['days'] ?? 30)));
            $range = (string)($_POST['range'] ?? '');
            $rangeQuery = preg_match('/^(?:60min|24h|1d|last[1-7]d)$/', $range) === 1
                ? '&range=' . rawurlencode($range)
                : '';
            header('Location: ' . strtok((string)($_SERVER['REQUEST_URI'] ?? 'pixl_stats.php'), '?') . '?days=' . $days . $rangeQuery);
            exit;
        }
        pixl_render_stats_login(true);
    }

    pixl_render_stats_login(false);
}

function pixl_expand_message_payload(array $payload): array
{
    $message = trim((string)pixl_get($payload, ['message'], ''));
    if ($message === '') {
        return $payload;
    }

    foreach (['context', 'engagement', 'health'] as $section) {
        if (!isset($payload[$section]) || !is_array($payload[$section])) {
            $payload[$section] = [];
        }
    }

    $lines = preg_split('/\R/u', $message) ?: [];
    $values = [];
    foreach ($lines as $line) {
        if (preg_match('/^([A-Za-z][A-Za-z0-9 ]*):\s*(.*)$/u', trim((string)$line), $match)) {
            $values[strtolower(trim($match[1]))] = trim($match[2]);
        }
    }

    $setDefault = static function (array &$target, string $key, $value): void {
        if ($value !== null && $value !== '' && (!array_key_exists($key, $target) || $target[$key] === '' || $target[$key] === null)) {
            $target[$key] = $value;
        }
    };
    $labelCode = static function (string $value): string {
        return preg_match('/\(([A-Za-z]{2}(?:[-_][A-Za-z0-9]{2,8})*)\)\s*$/u', $value, $match)
            ? str_replace('_', '-', $match[1])
            : '';
    };

    if (isset($values['screen']) && preg_match('/^(\d+x\d+)\s*(?:\((OKAY|BAD)\))?/i', $values['screen'], $match)) {
        $setDefault($payload['context'], 'screen', $match[1]);
        if (!array_key_exists('knownResolution', $payload['context']) && isset($match[2])) {
            $payload['context']['knownResolution'] = strtoupper($match[2]) === 'OKAY';
        }
    }
    if (isset($values['iscreen']) && preg_match('/^(\d+x\d+)\s*(?:\((OKAY|BAD)\))?/i', $values['iscreen'], $match)) {
        $setDefault($payload['context'], 'viewport', $match[1]);
        if (!array_key_exists('knownViewport', $payload['context']) && isset($match[2])) {
            $payload['context']['knownViewport'] = strtoupper($match[2]) === 'OKAY';
        }
    }
    if (isset($values['lang'])) {
        $language = $labelCode($values['lang']);
        $setDefault($payload['context'], 'language', $language !== '' ? $language : $values['lang']);
    }
    if (isset($values['country'])) {
        $country = $labelCode($values['country']);
        $setDefault($payload['context'], 'country', $country !== '' ? strtoupper($country) : $values['country']);
    }
    foreach (['browser', 'os'] as $key) {
        if (isset($values[$key])) {
            $setDefault($payload['context'], $key, $values[$key]);
        }
    }
    if (isset($values['device'])) {
        $deviceParts = explode('/', $values['device'], 2);
        $setDefault($payload['context'], 'device', trim($deviceParts[0]));
        if (isset($deviceParts[1])) {
            $setDefault($payload['context'], 'screenCategory', trim($deviceParts[1]));
        }
    }
    if (isset($values['sessionduration']) && preg_match('/^(\d+)s$/i', $values['sessionduration'], $match)) {
        $setDefault($payload['engagement'], 'sessionDuration', (int)$match[1]);
    }
    if (isset($values['reading']) && preg_match('/^([A-Za-z]+)(?:\s*\((\d+)s\))?/i', $values['reading'], $match)) {
        $setDefault($payload['engagement'], 'readingLabel', strtoupper($match[1]));
        if (isset($match[2])) {
            $setDefault($payload['engagement'], 'readingSeconds', (int)$match[2]);
        }
    }
    if (isset($values['readingscore']) && preg_match('/^-?\d+/', $values['readingscore'], $match)) {
        $setDefault($payload['engagement'], 'readingScore', (int)$match[0]);
    }
    if (isset($values['v3userscore']) && is_numeric($values['v3userscore'])) {
        $setDefault($payload['engagement'], 'v3UserScore', (float)$values['v3userscore']);
    }
    if (isset($values['render'])) {
        $setDefault($payload['health'], 'renderStatus', $values['render']);
    }

    return $payload;
}

function pixl_notification_language_name(string $value): string
{
    $code = strtolower((string)preg_replace('/[-_].*$/', '', trim($value)));
    $names = [
        'ar' => 'Arabic', 'bg' => 'Bulgarian', 'cs' => 'Czech', 'da' => 'Danish',
        'de' => 'German', 'el' => 'Greek', 'en' => 'English', 'es' => 'Spanish',
        'et' => 'Estonian', 'fi' => 'Finnish', 'fr' => 'French', 'he' => 'Hebrew',
        'hi' => 'Hindi', 'hr' => 'Croatian', 'hu' => 'Hungarian', 'id' => 'Indonesian',
        'it' => 'Italian', 'ja' => 'Japanese', 'ko' => 'Korean', 'lt' => 'Lithuanian',
        'lv' => 'Latvian', 'nl' => 'Dutch', 'no' => 'Norwegian', 'pl' => 'Polish',
        'pt' => 'Portuguese', 'ro' => 'Romanian', 'ru' => 'Russian', 'sk' => 'Slovak',
        'sl' => 'Slovenian', 'sr' => 'Serbian', 'sv' => 'Swedish', 'th' => 'Thai',
        'tr' => 'Turkish', 'uk' => 'Ukrainian', 'ur' => 'Urdu', 'vi' => 'Vietnamese',
        'zh' => 'Chinese',
    ];
    return $names[$code] ?? ($code !== '' ? $code : 'Unknown');
}

function pixl_notification_country_code(string $value): string
{
    $value = str_replace('_', '-', trim($value));
    if (preg_match('/^[A-Za-z]{2}$/', $value)) {
        return strtoupper($value);
    }
    $parts = explode('-', $value);
    for ($index = count($parts) - 1; $index > 0; $index--) {
        if (preg_match('/^[A-Za-z]{2}$/', $parts[$index])) {
            return strtoupper($parts[$index]);
        }
    }
    return '';
}

function pixl_country_code_from_locale_region(string $value): string
{
    $parts = preg_split('/[-_]/', trim($value)) ?: [];
    foreach (array_slice($parts, 1) as $part) {
        if (preg_match('/^[A-Za-z]{2}$/', $part)) {
            return strtoupper($part);
        }
    }
    return '';
}

function pixl_apply_country_geolocation(array $payload): array
{
    if (!isset($payload['context']) || !is_array($payload['context'])) {
        $payload['context'] = [];
    }

    $browserCountry = pixl_notification_country_code(
        pixl_string(pixl_get($payload, ['context', 'country'], ''), 40)
    );
    $payload['context']['countryBrowser'] = $browserCountry;

    $localCountry = pixl_geoip_country_code(pixl_remote_ip());
    if ($localCountry !== '') {
        $payload['context']['country'] = $localCountry;
        $payload['context']['countrySource'] = 'local_geoip';
        $payload['context']['countryConfidence'] = 'hoch';
        return $payload;
    }

    $ipCountry = pixl_geoip_trust_proxy_headers()
        ? strtoupper(trim((string)($_SERVER['HTTP_CF_IPCOUNTRY'] ?? '')))
        : '';
    if (preg_match('/^[A-Z]{2}$/', $ipCountry) && !in_array($ipCountry, ['XX'], true)) {
        $payload['context']['country'] = $ipCountry;
        $payload['context']['countrySource'] = 'ip_geolocation';
        $payload['context']['countryConfidence'] = 'hoch';
        return $payload;
    }

    $country = $browserCountry;
    $localeCountry = pixl_country_code_from_locale_region(
        pixl_string(pixl_get($payload, ['context', 'language'], ''), 40)
    );

    if ($country === '' && $localeCountry !== '') {
        $country = $localeCountry;
    }

    $payload['context']['country'] = $country;
    $payload['context']['countrySource'] = $country !== '' ? 'browser' : 'unknown';
    $payload['context']['countryConfidence'] = $country !== '' && $country === $localeCountry
        ? 'mittel'
        : 'niedrig';
    return $payload;
}

function pixl_notification_country_name(string $code): string
{
    $code = strtoupper(trim($code));
    if ($code === '') {
        return 'Unknown';
    }

    // ICU kennt die vollstaendigen ISO-Laendernamen. Die statische Liste
    // darunter bleibt als Rueckfall fuer Server ohne PHP-Intl erhalten.
    if (class_exists('Locale')) {
        $displayName = trim((string)Locale::getDisplayRegion('und_' . $code, 'en'));
        if ($displayName !== '' && strcasecmp($displayName, 'Unknown Region') !== 0) {
            return $displayName;
        }
    }

    $names = [
        'AT' => 'Austria', 'AU' => 'Australia', 'BE' => 'Belgium', 'BG' => 'Bulgaria',
        'BR' => 'Brazil', 'CA' => 'Canada', 'CH' => 'Switzerland', 'CN' => 'China',
        'CZ' => 'Czechia', 'DE' => 'Germany', 'DK' => 'Denmark', 'EE' => 'Estonia',
        'ES' => 'Spain', 'FI' => 'Finland', 'FR' => 'France', 'GB' => 'United Kingdom',
        'GR' => 'Greece', 'HR' => 'Croatia', 'HU' => 'Hungary', 'ID' => 'Indonesia',
        'IE' => 'Ireland', 'IN' => 'India', 'IS' => 'Iceland', 'IT' => 'Italy',
        'JP' => 'Japan', 'KR' => 'South Korea', 'LT' => 'Lithuania', 'LU' => 'Luxembourg',
        'LV' => 'Latvia', 'MX' => 'Mexico', 'MY' => 'Malaysia', 'NL' => 'Netherlands',
        'NO' => 'Norway', 'NZ' => 'New Zealand', 'PH' => 'Philippines', 'PK' => 'Pakistan',
        'PL' => 'Poland', 'PT' => 'Portugal', 'RO' => 'Romania', 'RS' => 'Serbia',
        'RU' => 'Russia', 'SE' => 'Sweden', 'SG' => 'Singapore', 'SI' => 'Slovenia',
        'SK' => 'Slovakia', 'TH' => 'Thailand', 'TR' => 'Turkey', 'TW' => 'Taiwan',
        'UA' => 'Ukraine', 'US' => 'United States', 'VN' => 'Vietnam', 'ZA' => 'South Africa',
    ];
    return $names[$code] ?? $code;
}

/**
 * Interaction estimate, not proof of reading. Keep the raw input so a stored
 * legacy payload can be processed again without normalizing its score twice.
 */
function pixl_notification_reading_score(array $payload): array
{
    $method = 'activity-v1';
    $finiteNumber = static function ($value): ?float {
        return is_numeric($value) && is_finite((float)$value) ? (float)$value : null;
    };
    $samples = pixl_get($payload, ['engagement', 'readingSamples']);
    $raw = null;
    if (is_array($samples)) {
        // An empty sample list measures zero; malformed-only input is unknown.
        $raw = $samples === [] ? 0.0 : null;
        foreach (array_slice($samples, -50) as $sample) {
            $value = $finiteNumber($sample);
            if ($value !== null) {
                $raw = ($raw ?? 0.0) + max(0.0, min(1000.0, $value));
            }
        }
    }

    if ($raw === null) {
        if (pixl_get($payload, ['engagement', 'readingScoreMethod']) === $method) {
            $raw = $finiteNumber(pixl_get($payload, ['engagement', 'readingScoreRaw']));
        } else {
            // pixl77.js, pixl6.js and expanded text messages send a score only.
            $raw = $finiteNumber(pixl_get($payload, ['engagement', 'readingScore']));
        }
        if ($raw !== null) {
            $raw = max(0.0, min(50000.0, $raw));
        }
    }

    $score = null;
    $label = 'no measurement';
    if ($raw !== null) {
        // Diminishing returns prevent raw mouse/scroll volume dominating.
        $normalized = 100.0 * $raw / ($raw + 50.0);
        $duration = $finiteNumber(pixl_get($payload, ['engagement', 'sessionDuration']));
        if ($duration !== null && $duration >= 0.0) {
            // Elapsed visit time is only a ceiling, never evidence of activity.
            $ceiling = floor(100.0 * min(60.0, $duration) / 60.0);
            $normalized = min($normalized, $ceiling);
        }
        $score = max(0, min(100, (int)round($normalized)));
        $label = match (true) {
            $raw === 0.0 => 'No activity',
            $score < 25 => 'Low',
            $score < 50 => 'Moderate',
            $score < 75 => 'High',
            default => 'Very high',
        };
    }

    return ['score' => $score, 'raw' => $raw, 'label' => $label, 'method' => $method];
}

function pixl_server_build_notification(array $payload, array $bot): array
{
    $reason = strtoupper(pixl_string(pixl_get($payload, ['reason'], 'UNKNOWN'), 40));
    $language = pixl_string(pixl_get($payload, ['context', 'language'], ''), 40);
    $languageName = pixl_notification_language_name($language);
    $languageLabel = strcasecmp($languageName, $language) === 0 || $language === ''
        ? $languageName
        : $languageName . ' (' . $language . ')';

    $countryCode = pixl_notification_country_code(pixl_string(pixl_get($payload, ['context', 'country'], ''), 40));
    if (!pixl_geoip_pushover_country_enabled()
        && pixl_get($payload, ['context', 'countrySource'], '') === 'local_geoip') {
        $countryCode = pixl_notification_country_code(
            pixl_string(pixl_get($payload, ['context', 'countryBrowser'], ''), 40)
        );
        $localeCode = pixl_country_code_from_locale_region($language);
        if ($countryCode === '' && $localeCode !== '') {
            $countryCode = $localeCode;
        }
        $payload['context']['countryConfidence'] = $countryCode !== '' && $countryCode === $localeCode
            ? 'mittel'
            : 'niedrig';
    }
    $countryName = pixl_notification_country_name($countryCode);
    $countryConfidence = strtolower(pixl_string(pixl_get($payload, ['context', 'countryConfidence'], 'niedrig'), 10));
    if (!in_array($countryConfidence, ['niedrig', 'mittel', 'hoch'], true)) {
        $countryConfidence = 'niedrig';
    }
    $countryLabel = $countryName . ' (' . $countryConfidence . ')';

    $screen = pixl_string(pixl_get($payload, ['context', 'screen'], ''), 40);
    $knownResolutions = [
        '1920x1080', '1366x768', '1536x864', '1440x900', '1280x720',
        '2560x1440', '3840x2160', '1680x1050', '1600x900', '1280x800',
        '390x844', '393x873', '412x915', '375x812', '360x780',
        '414x896', '428x926', '430x932', '360x800', '412x892',
    ];
    $configuredResolution = pixl_get($payload, ['context', 'knownResolution']);
    $knownResolution = is_bool($configuredResolution)
        ? $configuredResolution
        : in_array($screen, $knownResolutions, true);
    $inFrame = filter_var(pixl_get($payload, ['flags', 'inFrame'], false), FILTER_VALIDATE_BOOLEAN);

    $title = implode(' - ', [
        !empty($bot['is_bot']) ? 'Bot' : 'Visit',
        $languageName,
        $knownResolution ? 'OKAY' : 'BAD',
        $inFrame ? 'Frame' : 'NoFrame',
    ]);

    $reached = pixl_get($payload, ['events', 'reached'], []);
    $seconds = pixl_get($payload, ['events', 'seconds'], []);
    $reached = is_array($reached) ? $reached : [];
    $seconds = is_array($seconds) ? $seconds : [];
    if (!empty($reached['READ'])) {
        $readingLabel = 'READ';
        $readingValue = $seconds['READ'] ?? null;
    } elseif (!empty($reached['LEAVE'])) {
        $readingLabel = 'LEAVE';
        $readingValue = $seconds['LEAVE'] ?? null;
    } else {
        $readingLabel = $reason;
        $readingValue = $seconds['VISIT'] ?? null;
    }
    $readingDisplay = is_numeric($readingValue)
        ? $readingLabel . ' (' . max(0, (int)$readingValue) . 's)'
        : $readingLabel;

    $renderIssues = pixl_get($payload, ['health', 'renderIssues'], []);
    $renderIssues = is_array($renderIssues)
        ? array_values(array_filter(array_map(static fn($value): string => pixl_string($value, 160), $renderIssues)))
        : [];
    $render = $renderIssues ? implode('|', $renderIssues) : 'OK';
    $path = pixl_string(pixl_get($payload, ['page', 'path'], '/'), 1024);
    $sessionDuration = pixl_nullable_int(pixl_get($payload, ['engagement', 'sessionDuration'])) ?? 0;
    $reading = pixl_notification_reading_score($payload);
    $readingScore = $reading['score'];
    $readingScoreDisplay = $readingScore === null ? 'n/a' : $readingScore . '/100';

    $lines = [
        'Screen: ' . ($screen !== '' ? $screen : 'Unknown') . ' (' . ($knownResolution ? 'OKAY' : 'BAD') . ')',
        'iScreen: ' . pixl_string(pixl_get($payload, ['context', 'viewport'], 'Unknown'), 40),
        'Lang: ' . $languageLabel,
        'Country: ' . $countryLabel,
        'Browser: ' . pixl_string(pixl_get($payload, ['context', 'browser'], 'Unknown'), 80),
        'OS: ' . pixl_string(pixl_get($payload, ['context', 'os'], 'Unknown'), 80),
        'Device: ' . pixl_string(pixl_get($payload, ['context', 'device'], 'Unknown'), 80)
            . '/' . pixl_string(pixl_get($payload, ['context', 'screenCategory'], 'Unknown'), 40),
        'Timezone: ' . pixl_string(pixl_get($payload, ['context', 'timezone'], 'Unknown'), 80),
        'Path: ' . ($path !== '' ? $path : '/'),
    ];
    $lines[] = 'SessionDuration: ' . $sessionDuration . 's';
    $lines[] = 'Reading: ' . $readingDisplay;
    $lines[] = 'ReadingScore: ' . $readingScoreDisplay . ' (' . $reading['label'] . ')';
    $lines[] = 'Render: ' . $render;

    $v3Score = pixl_nullable_float(pixl_get($payload, ['engagement', 'v3UserScore']));
    if ($v3Score !== null) {
        $lines[] = 'v3UserScore: ' . number_format($v3Score, 2, '.', '');
    }
    if (!empty($bot['is_bot'])) {
        $lines[] = 'Bot: ' . pixl_string($bot['name'] ?? 'Unknown', 120)
            . ' / ' . pixl_string($bot['category'] ?? 'unknown', 80)
            . ' / score ' . (int)($bot['score'] ?? 0);
    }

    $consoleErrors = pixl_get($payload, ['health', 'consoleErrors'], []);
    if (is_array($consoleErrors) && $consoleErrors) {
        $lines[] = '';
        $lines[] = 'Console Errors (last 3):';
        foreach (array_slice($consoleErrors, -3) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $time = pixl_string($entry['time'] ?? '', 40);
            $message = pixl_string($entry['message'] ?? '', 160);
            if ($message !== '') {
                $lines[] = '- [' . $time . '] ' . $message;
            }
        }
    }

    return [
        'title' => $title,
        'message' => implode("\n", $lines),
        'known_resolution' => $knownResolution,
        'reading_label' => $readingLabel,
        'reading_seconds' => is_numeric($readingValue) ? max(0, (int)$readingValue) : null,
        'reading_score' => $readingScore,
        'reading_score_raw' => $reading['raw'],
        'reading_score_method' => $reading['method'],
        'render_status' => $render,
    ];
}

/** A final summary may have a configured label while keeping its terminal event marker. */
function pixl_event_is_final(array $payload): bool
{
    return strtoupper(pixl_string($payload['reason'] ?? '', 40)) === 'LEAVE'
        || pixl_get($payload, ['events', 'reached', 'LEAVE']) === true;
}

function pixl_event_phase(array $payload): int
{
    if (pixl_event_is_final($payload)) return 3;
    return in_array(strtoupper(pixl_string($payload['reason'] ?? '', 40)), ['READ', 'HIDDEN'], true)
        || pixl_get($payload, ['events', 'reached', 'READ']) === true ? 2 : 1;
}

/** Use the original timestamp, since the database DATETIME rounds to whole seconds. */
function pixl_event_timestamp(array $payload): ?float
{
    $value = $payload['sentAt'] ?? null;
    if (!is_string($value) || trim($value) === '') return null;
    try { return (float)(new DateTimeImmutable($value))->format('U.u'); }
    catch (Throwable) { return null; }
}

function pixl_event_snapshot_is_newer(array $incoming, array $saved): bool
{
    $incomingTime = pixl_event_timestamp($incoming);
    $savedTime = pixl_event_timestamp($saved);
    if ($incomingTime !== null && $savedTime !== null && $incomingTime < $savedTime) return false;
    $incomingPhase = pixl_event_phase($incoming);
    $savedPhase = pixl_event_phase($saved);
    $hasProgress = false;
    // Legacy clients can send multiple checkpoints within the same second.
    // Repeated snapshots must not trigger the notification dispatcher again.
    foreach ([['engagement', 'sessionDuration'], ['engagement', 'readingScore'],
        ['health', 'consoleErrorCount'], ['health', 'dialogErrorCount']] as $path) {
        $next = pixl_get($incoming, $path);
        $previous = pixl_get($saved, $path);
        if (is_numeric($next) && (!is_numeric($previous) || (float)$next > (float)$previous)) $hasProgress = true;
    }
    // BFCache may produce another READ after LEAVE. Preserve the final phase,
    // but accept new measured progress; a fresh VISIT cannot reopen that summary.
    if ($incomingPhase < $savedPhase) return $incomingPhase >= 2 && $hasProgress;
    if ($incomingPhase > $savedPhase) return true;
    if ($incomingTime !== null && $savedTime !== null && $incomingTime > $savedTime) return true;
    return $hasProgress;
}

/** A reload can reuse the page row; it must not erase progress already measured there. */
function pixl_merge_event_progress(array $incoming, array $saved): array
{
    if (pixl_event_phase($incoming) < pixl_event_phase($saved)) $incoming['reason'] = $saved['reason'] ?? 'LEAVE';
    foreach (['sessionDuration', 'readingSeconds', 'bestReadScore', 'bestReadDuration'] as $key) {
        $old = pixl_get($saved, ['engagement', $key]);
        $next = pixl_get($incoming, ['engagement', $key]);
        if (is_numeric($old) && (!is_numeric($next) || (float)$old > (float)$next)) {
            $incoming['engagement'][$key] = $old;
        }
    }
    $oldScore = pixl_notification_reading_score($saved);
    $newScore = pixl_notification_reading_score($incoming);
    if ($oldScore['score'] !== null && ($newScore['score'] === null || $oldScore['score'] > $newScore['score'])) {
        unset($incoming['engagement']['readingSamples']);
        $incoming['engagement']['readingScore'] = $oldScore['score'];
        $incoming['engagement']['readingScoreRaw'] = $oldScore['raw'];
        $incoming['engagement']['readingScoreMethod'] = $oldScore['method'];
    }
    foreach (['VISIT', 'READ', 'LEAVE'] as $phase) {
        if (pixl_get($saved, ['events', 'reached', $phase]) === true) $incoming['events']['reached'][$phase] = true;
        $old = pixl_get($saved, ['events', 'seconds', $phase]);
        $next = pixl_get($incoming, ['events', 'seconds', $phase]);
        if (is_numeric($old) && (!is_numeric($next) || (float)$old > (float)$next)) $incoming['events']['seconds'][$phase] = $old;
    }
    return $incoming;
}

function pixl_finalize_event_payload(array $payload, array $bot): array
{
    $notification = pixl_server_build_notification($payload, $bot);
    $payload['title'] = $notification['title'];
    $payload['message'] = $notification['message'];
    if (!isset($payload['context']) || !is_array($payload['context'])) {
        $payload['context'] = [];
    }
    $payload['context']['knownResolution'] = $notification['known_resolution'];
    if (!isset($payload['engagement']) || !is_array($payload['engagement'])) {
        $payload['engagement'] = [];
    }
    $payload['engagement']['readingLabel'] = $notification['reading_label'];
    $payload['engagement']['readingSeconds'] = $notification['reading_seconds'];
    $payload['engagement']['readingScore'] = $notification['reading_score'];
    $payload['engagement']['readingScoreRaw'] = $notification['reading_score_raw'];
    $payload['engagement']['readingScoreMethod'] = $notification['reading_score_method'];
    if (!isset($payload['health']) || !is_array($payload['health'])) {
        $payload['health'] = [];
    }
    $payload['health']['renderStatus'] = $notification['render_status'];
    return $payload;
}

function pixl_event_database_params(array $payload, array $bot, string $eventId, string $hostname,
    string $pageUrl, string $pagePath, string $visitorHash, string $ipHash, string $userAgent): array
{
    return [
        ':event_id' => $eventId,
        ':sent_at' => pixl_parse_sent_at(pixl_get($payload, ['sentAt'])),
        ':site_id' => pixl_string(pixl_get($payload, ['siteId'], ''), 100),
        ':reason' => pixl_string(pixl_get($payload, ['reason'], ''), 40),
        ':title' => pixl_string(pixl_get($payload, ['title'], ''), 255),
        ':hostname' => $hostname,
        ':page_url' => $pageUrl,
        ':path' => $pagePath,
        ':referrer' => pixl_string(pixl_get($payload, ['page', 'referrer'], ''), 0),
        ':browser' => pixl_string(pixl_get($payload, ['context', 'browser'], ''), 80),
        ':os' => pixl_string(pixl_get($payload, ['context', 'os'], ''), 80),
        ':device' => pixl_string(pixl_get($payload, ['context', 'device'], ''), 80),
        ':country' => pixl_string(pixl_get($payload, ['context', 'country'], ''), 20),
        ':language' => pixl_string(pixl_get($payload, ['context', 'language'], ''), 40),
        ':screen' => pixl_string(pixl_get($payload, ['context', 'screen'], ''), 40),
        ':viewport' => pixl_string(pixl_get($payload, ['context', 'viewport'], ''), 40),
        ':screen_category' => pixl_string(pixl_get($payload, ['context', 'screenCategory'], ''), 40),
        ':known_resolution' => pixl_bool_int(pixl_get($payload, ['context', 'knownResolution'], false)),
        ':session_duration' => pixl_nullable_int(pixl_get($payload, ['engagement', 'sessionDuration'])),
        ':reading_label' => pixl_string(pixl_get($payload, ['engagement', 'readingLabel'], ''), 40),
        ':reading_seconds' => pixl_nullable_int(pixl_get($payload, ['engagement', 'readingSeconds'])),
        ':reading_score' => pixl_nullable_int(pixl_get($payload, ['engagement', 'readingScore'])),
        ':v3_user_score' => pixl_nullable_float(pixl_get($payload, ['engagement', 'v3UserScore'])),
        ':render_status' => pixl_string(pixl_get($payload, ['health', 'renderStatus'], ''), 255),
        ':console_error_count' => pixl_nullable_int(pixl_get($payload, ['health', 'consoleErrorCount'])) ?? 0,
        ':dialog_error_count' => pixl_nullable_int(pixl_get($payload, ['health', 'dialogErrorCount'])) ?? 0,
        ':source' => pixl_string(pixl_get($payload, ['source'], 'js'), 40),
        ':is_bot' => $bot['is_bot'],
        ':bot_score' => $bot['score'],
        ':bot_category' => pixl_string($bot['category'], 80),
        ':bot_name' => pixl_string($bot['name'], 120),
        ':bot_reasons' => implode(', ', $bot['reasons']),
        ':visitor_hash' => $visitorHash,
        ':ip_hash' => $ipHash,
        ':request_method' => pixl_string($_SERVER['REQUEST_METHOD'] ?? '', 12),
        ':request_uri' => pixl_string($_SERVER['REQUEST_URI'] ?? '', 0),
        ':user_agent' => $userAgent,
        ':message' => pixl_string(pixl_get($payload, ['message'], ''), 0),
        ':payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ];

}

function pixl_insert_event(PDO $pdo, array $payload): int
{
    $payload = pixl_expand_message_payload($payload);
    $payload = pixl_apply_country_geolocation($payload);
    $table = pixl_table_name();
    $siteKey = (string)pixl_get($payload, ['siteKey'], '');
    $requiredKey = (string)(pixl_config()['public_key'] ?? '');
    if ($requiredKey !== '' && !hash_equals($requiredKey, $siteKey)) {
        pixl_json_response(['ok' => false, 'error' => 'bad_site_key'], 403);
    }

    $hostname = pixl_string(pixl_get($payload, ['page', 'hostname'], ''), 255);
    if ($hostname !== '' && !pixl_allowed_host($hostname)) {
        pixl_json_response(['ok' => false, 'error' => 'host_not_allowed'], 403);
    }

    $eventId = pixl_string(pixl_get($payload, ['eventId'], ''), 80);
    if ($eventId === '') {
        $eventId = bin2hex(random_bytes(16));
    }

    $userAgent = pixl_string(pixl_get($payload, ['context', 'userAgent'], $_SERVER['HTTP_USER_AGENT'] ?? ''), 0);
    $bot = pixl_detect_bot($userAgent, $payload);
    $payload = pixl_finalize_event_payload($payload, $bot);
    $ip = pixl_remote_ip();
    $visitorHash = pixl_hash($ip . '|' . $userAgent);
    $ipHash = pixl_hash($ip);
    $pageUrl = pixl_string(pixl_get($payload, ['page', 'url'], ''), 0);
    $pagePath = pixl_string(pixl_get($payload, ['page', 'path'], ''), 1024);
    if ($pagePath === '' && $pageUrl !== '') {
        $parsedPath = parse_url($pageUrl, PHP_URL_PATH);
        $pagePath = is_string($parsedPath) && $parsedPath !== '' ? $parsedPath : '/';
    }
    $normalizedPagePath = parse_url($pagePath !== '' ? $pagePath : '/', PHP_URL_PATH);
    $pagePath = is_string($normalizedPagePath) && $normalizedPagePath !== '' ? $normalizedPagePath : '/';

    $pageRecountMinutes = pixl_nullable_int(pixl_get($payload, ['session', 'pageRecountMinutes']));
    if ($pageRecountMinutes !== null && $pageRecountMinutes > 0 && $hostname !== '' && $visitorHash !== '') {
        $pageRecountMinutes = max(1, min(1440, $pageRecountMinutes));
        $pathExpression = pixl_sql_path_expression();
        $sessionStmt = $pdo->prepare(
            "SELECT `event_id` FROM `$table`
             WHERE `visitor_hash` = :session_visitor_hash
               AND LOWER(`hostname`) = :session_hostname
               AND $pathExpression = :session_path
               AND `created_at` >= UTC_TIMESTAMP() - INTERVAL $pageRecountMinutes MINUTE
             ORDER BY `id` DESC
             LIMIT 1"
        );
        $sessionStmt->execute([
            ':session_visitor_hash' => $visitorHash,
            ':session_hostname' => strtolower($hostname),
            ':session_path' => $pagePath,
        ]);
        $existingEventId = $sessionStmt->fetchColumn();
        if (is_string($existingEventId) && $existingEventId !== '') {
            $eventId = $existingEventId;
            $payload['eventId'] = $eventId;
            if (!isset($payload['session']) || !is_array($payload['session'])) {
                $payload['session'] = [];
            }
            $payload['session']['reused'] = true;
        }
    }

    $params = pixl_event_database_params($payload, $bot, $eventId, $hostname, $pageUrl, $pagePath, $visitorHash, $ipHash, $userAgent);

    $columns = array_map(static function (string $key): string {
        return substr($key, 1);
    }, array_keys($params));
    $updateColumns = array_values(array_filter($columns, static function (string $column): bool {
        return !in_array($column, ['event_id', 'visitor_hash', 'ip_hash'], true);
    }));
    // The duplicate-key no-op acquires the row lock before comparing snapshots.
    // Only the transaction that advances a summary returns its id for notification.
    $sql = sprintf(
        'INSERT INTO `%s` (`%s`) VALUES (%s) ON DUPLICATE KEY UPDATE `id` = `id`',
        $table, implode('`, `', $columns), implode(', ', array_keys($params))
    );
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ($stmt->rowCount() === 1) {
            $id = (int)$pdo->lastInsertId();
            if ($ownsTransaction) $pdo->commit();
            return $id;
        }
        $savedStmt = $pdo->prepare("SELECT `id`, `payload_json` FROM `$table` WHERE `event_id` = :event_id FOR UPDATE");
        $savedStmt->execute([':event_id' => $eventId]);
        $row = $savedStmt->fetch(PDO::FETCH_ASSOC);
        $saved = is_array($row) ? json_decode((string)$row['payload_json'], true) : null;
        if (is_array($saved) && !pixl_event_snapshot_is_newer($payload, $saved)) {
            if ($ownsTransaction) $pdo->commit();
            return 0;
        }
        $notifyFinal = pixl_event_is_final($payload);
        if (is_array($saved)) $payload = pixl_finalize_event_payload(pixl_merge_event_progress($payload, $saved), $bot);
        $params = pixl_event_database_params($payload, $bot, $eventId, $hostname, $pageUrl, $pagePath, $visitorHash, $ipHash, $userAgent);
        $updates = array_map(static fn(string $column): string => "`$column` = :$column", $updateColumns);
        unset($params[':visitor_hash'], $params[':ip_hash']);
        $stmt = $pdo->prepare("UPDATE `$table` SET " . implode(', ', $updates) . ' WHERE `event_id` = :event_id');
        $stmt->execute($params);
        $id = $notifyFinal ? (int)($row['id'] ?? 0) : 0;
        if ($ownsTransaction) $pdo->commit();
        return $id;
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
