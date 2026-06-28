<?php
declare(strict_types=1);

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
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        $raw = (string)($_SERVER[$key] ?? '');
        if ($raw === '') {
            continue;
        }
        $first = trim(explode(',', $raw)[0]);
        if ($first !== '') {
            return $first;
        }
    }
    return '';
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
    foreach (['days', 'limit', 'ar', 'bot', 'exclude_germans'] as $key) {
        if (isset($queryValues[$key]) && is_scalar($queryValues[$key])) {
            $query[$key] = substr((string)$queryValues[$key], 0, 40);
        }
    }

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
            $days = max(1, min(365, (int)($_POST['days'] ?? 30)));
            header('Location: ' . strtok((string)($_SERVER['REQUEST_URI'] ?? 'pixl_stats.php'), '?') . '?days=' . $days);
            exit;
        }
        pixl_render_stats_login(true);
    }

    pixl_render_stats_login(false);
}

function pixl_insert_event(PDO $pdo, array $payload): int
{
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

    $params = [
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

    $columns = array_map(static function (string $key): string {
        return substr($key, 1);
    }, array_keys($params));
    $updateColumns = array_values(array_filter($columns, static function (string $column): bool {
        return !in_array($column, ['event_id', 'visitor_hash', 'ip_hash'], true);
    }));
    $updates = array_map(static function (string $column): string {
        return sprintf('`%1$s` = VALUES(`%1$s`)', $column);
    }, $updateColumns);
    $sql = sprintf(
        'INSERT INTO `%s` (`%s`) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
        $table,
        implode('`, `', $columns),
        implode(', ', array_keys($params)),
        implode(', ', $updates)
    );

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // Nur der erste VISIT erzeugt eine neue Zeile und darf Push ausloesen.
    // READ und LEAVE aktualisieren dieselbe event_id und liefern deshalb 0.
    if ($stmt->rowCount() !== 1) {
        return 0;
    }

    return (int)$pdo->lastInsertId();
}
