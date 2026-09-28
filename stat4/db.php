<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/pixl_server.php';

function stat4_config(): array
{
    static $config;
    return $config ??= require __DIR__ . '/config.php';
}

function stat4_db(): PDO
{
    return pixl_pdo();
}

// Preserve the URL already sent by count.js, including scheme, port and query.
function stat4_page_url(mixed $value): string
{
    if (!is_string($value)) return '';
    $parts = parse_url(mb_substr(trim($value), 0, 4096));
    if (!is_array($parts) || empty($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) return '';
    return strtolower($parts['scheme']) . '://' . $parts['host']
        . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($parts['path'] ?? '/')
        . (isset($parts['query']) ? '?' . $parts['query'] : '');
}

function stat4_ensure_page_url_column(PDO $pdo): void
{
    if ($pdo->query("SHOW COLUMNS FROM stat4_events LIKE 'page_url'")->fetch()) return;
    try {
        $pdo->exec('ALTER TABLE stat4_events ADD COLUMN page_url TEXT NULL AFTER path');
    } catch (PDOException $error) {
        // Concurrent collectors may both discover the old schema.
        if ((int)($error->errorInfo[1] ?? 0) !== 1060) throw $error;
    }
}

function stat4_json(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function stat4_client_ip(): string
{
    return pixl_geoip_client_ip($_SERVER);
}

/**
 * Speichert nur den gekürzten Netzpräfix, niemals die vollständige IP:
 * IPv4: erste drei Oktette, IPv6: erste drei Hextette (/48).
 */
function stat4_ip_prefix(string $ip): string
{
    $ip = trim($ip);
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $parts = explode('.', $ip);
        return implode('.', array_slice($parts, 0, 3));
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $packed = inet_pton($ip);
        if ($packed === false) return '';
        $parts = unpack('n3', substr($packed, 0, 6));
        if (!is_array($parts)) return '';
        return implode(':', array_map(static fn(int $part): string => dechex($part), array_values($parts)));
    }
    return '';
}

function stat4_ensure_ip_prefix_columns(PDO $pdo): void
{
    static $ready = false;
    if ($ready) return;

    $stmt = $pdo->prepare(
        "SELECT COLUMN_NAME
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE()
           AND TABLE_NAME='stat4_visitors'
           AND COLUMN_NAME IN ('first_ip_prefix','last_ip_prefix')"
    );
    $stmt->execute();
    $existing = array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true);
    if (!isset($existing['first_ip_prefix'])) {
        $pdo->exec("ALTER TABLE stat4_visitors ADD COLUMN first_ip_prefix VARCHAR(64) NOT NULL DEFAULT '' AFTER first_ip_hash");
    }
    if (!isset($existing['last_ip_prefix'])) {
        $pdo->exec("ALTER TABLE stat4_visitors ADD COLUMN last_ip_prefix VARCHAR(64) NOT NULL DEFAULT '' AFTER first_ip_prefix");
    }
    $ready = true;
}

function stat4_hash_salt(?array $centralConfig = null, ?string $environmentSalt = null): string
{
    $environmentSalt ??= (string)getenv('STAT4_HASH_SALT');
    $salt = $environmentSalt !== ''
        ? $environmentSalt
        : (string)(($centralConfig ?? pixl_config())['hash_salt'] ?? '');
    if (strlen($salt) < 24 || in_array($salt, ['please-change-stat4-salt', 'CHANGE-ME-TO-A-LONG-RANDOM-SECRET'], true)) {
        throw new RuntimeException('Individuellen Hash-Salt mit mindestens 24 Zeichen in pixl_config.php oder STAT4_HASH_SALT setzen.');
    }
    return $salt;
}

function stat4_hash(string $value): string
{
    return hash('sha256', $value . '|' . stat4_hash_salt());
}

function stat4_infer_country(string $language, string $timezone): string
{
    $localeParts = preg_split('/[-_]/', trim($language)) ?: [];
    foreach (array_slice($localeParts, 1) as $localePart) {
        if (preg_match('/^[A-Za-z]{2}$/', $localePart)) return strtoupper($localePart);
    }
    $timezoneCountries = [
        'Europe/Berlin'=>'DE','Europe/Vienna'=>'AT','Europe/Zurich'=>'CH','Europe/London'=>'GB',
        'Europe/Paris'=>'FR','Europe/Rome'=>'IT','Europe/Madrid'=>'ES','Europe/Amsterdam'=>'NL',
        'Europe/Brussels'=>'BE','Europe/Warsaw'=>'PL','Europe/Prague'=>'CZ','Europe/Athens'=>'GR',
        'Europe/Istanbul'=>'TR','Europe/Moscow'=>'RU','Europe/Kyiv'=>'UA','Europe/Lisbon'=>'PT',
        'Europe/Copenhagen'=>'DK','Europe/Stockholm'=>'SE','Europe/Oslo'=>'NO','Europe/Helsinki'=>'FI',
        'Europe/Dublin'=>'IE','Europe/Luxembourg'=>'LU','America/New_York'=>'US','America/Detroit'=>'US',
        'America/Chicago'=>'US','America/Denver'=>'US','America/Los_Angeles'=>'US','America/Phoenix'=>'US',
        'America/Toronto'=>'CA','America/Vancouver'=>'CA','America/Mexico_City'=>'MX',
        'America/Sao_Paulo'=>'BR','America/Argentina/Buenos_Aires'=>'AR','America/Santiago'=>'CL',
        'America/Bogota'=>'CO','America/Lima'=>'PE','Asia/Tokyo'=>'JP','Asia/Shanghai'=>'CN',
        'Asia/Hong_Kong'=>'HK','Asia/Singapore'=>'SG','Asia/Kolkata'=>'IN','Asia/Dubai'=>'AE',
        'Asia/Seoul'=>'KR','Asia/Bangkok'=>'TH','Asia/Jakarta'=>'ID','Asia/Manila'=>'PH',
        'Asia/Jerusalem'=>'IL','Australia/Sydney'=>'AU','Australia/Melbourne'=>'AU',
        'Pacific/Auckland'=>'NZ','Africa/Johannesburg'=>'ZA','Africa/Cairo'=>'EG','Africa/Casablanca'=>'MA',
    ];
    if (isset($timezoneCountries[$timezone])) return $timezoneCountries[$timezone];
    $languageCountries = [
        'de'=>'DE','en'=>'GB','fr'=>'FR','es'=>'ES','it'=>'IT','pt'=>'PT','nl'=>'NL','pl'=>'PL',
        'cs'=>'CZ','da'=>'DK','sv'=>'SE','no'=>'NO','fi'=>'FI','el'=>'GR','tr'=>'TR','ru'=>'RU',
        'uk'=>'UA','ja'=>'JP','ko'=>'KR','zh'=>'CN','hi'=>'IN','ar'=>'AE','he'=>'IL','th'=>'TH',
        'id'=>'ID','vi'=>'VN',
    ];
    $baseLanguage = strtolower((string) ($localeParts[0] ?? ''));
    return $languageCountries[$baseLanguage] ?? '';
}

function stat4_language_name(string $locale): string
{
    $base = strtolower((string) ((preg_split('/[-_]/', trim($locale)) ?: [])[0] ?? ''));
    $names = [
        'de'=>'Deutsch','en'=>'Englisch','fr'=>'Französisch','es'=>'Spanisch','it'=>'Italienisch',
        'pt'=>'Portugiesisch','nl'=>'Niederländisch','pl'=>'Polnisch','cs'=>'Tschechisch',
        'da'=>'Dänisch','sv'=>'Schwedisch','no'=>'Norwegisch','fi'=>'Finnisch','el'=>'Griechisch',
        'tr'=>'Türkisch','ru'=>'Russisch','uk'=>'Ukrainisch','ja'=>'Japanisch','ko'=>'Koreanisch',
        'zh'=>'Chinesisch','hi'=>'Hindi','ar'=>'Arabisch','he'=>'Hebräisch','th'=>'Thailändisch',
        'id'=>'Indonesisch','vi'=>'Vietnamesisch',
    ];
    return $names[$base] ?? ($locale !== '' ? $locale : 'Unbekannt');
}

function stat4_country_name(string $code): string
{
    $names = [
        'DE'=>'Deutschland','AT'=>'Österreich','CH'=>'Schweiz','GB'=>'Vereinigtes Königreich',
        'US'=>'Vereinigte Staaten','CA'=>'Kanada','FR'=>'Frankreich','IT'=>'Italien','ES'=>'Spanien',
        'PT'=>'Portugal','NL'=>'Niederlande','BE'=>'Belgien','PL'=>'Polen','CZ'=>'Tschechien',
        'DK'=>'Dänemark','SE'=>'Schweden','NO'=>'Norwegen','FI'=>'Finnland','IE'=>'Irland',
        'LU'=>'Luxemburg','GR'=>'Griechenland','TR'=>'Türkei','RU'=>'Russland','UA'=>'Ukraine',
        'MX'=>'Mexiko','BR'=>'Brasilien','AR'=>'Argentinien','CL'=>'Chile','CO'=>'Kolumbien',
        'PE'=>'Peru','JP'=>'Japan','CN'=>'China','HK'=>'Hongkong','SG'=>'Singapur','IN'=>'Indien',
        'AE'=>'Vereinigte Arabische Emirate','KR'=>'Südkorea','TH'=>'Thailand','ID'=>'Indonesien',
        'PH'=>'Philippinen','IL'=>'Israel','AU'=>'Australien','NZ'=>'Neuseeland',
        'ZA'=>'Südafrika','EG'=>'Ägypten','MA'=>'Marokko','VN'=>'Vietnam',
    ];
    $normalized = strtoupper(trim($code));
    return $names[$normalized] ?? ($normalized !== '' ? $normalized : 'Unbekannt');
}

function stat4_ua(string $ua): array
{
    $bot = preg_match('/bot|crawl|spider|slurp|bingpreview|headless|facebookexternalhit|monitor/i', $ua) === 1;
    $browser = 'Andere'; $browserVersion = '';
    foreach ([
        'Edge' => '/Edg\/([\d.]+)/', 'Opera' => '/OPR\/([\d.]+)/',
        'Chrome' => '/(?:Chrome|CriOS)\/([\d.]+)/', 'Firefox' => '/(?:Firefox|FxiOS)\/([\d.]+)/',
        'Safari' => '/Version\/([\d.]+).*Safari/', 'Internet Explorer' => '/(?:MSIE |rv:)([\d.]+)/',
    ] as $name => $pattern) {
        if (preg_match($pattern, $ua, $m)) { $browser = $name; $browserVersion = $m[1]; break; }
    }
    $os = 'Andere'; $osVersion = '';
    foreach ([
        'Windows' => '/Windows NT ([\d.]+)/', 'Android' => '/Android ([\d.]+)/',
        'iOS' => '/(?:iPhone OS|CPU OS) ([\d_]+)/', 'macOS' => '/Mac OS X ([\d_]+)/',
        'Linux' => '/Linux/',
    ] as $name => $pattern) {
        if (preg_match($pattern, $ua, $m)) { $os = $name; $osVersion = isset($m[1]) ? str_replace('_', '.', $m[1]) : ''; break; }
    }
    $device = preg_match('/tablet|ipad/i', $ua) ? 'Tablet' : (preg_match('/mobile|iphone|android/i', $ua) ? 'Mobile' : 'Desktop');
    return compact('bot', 'browser', 'browserVersion', 'os', 'osVersion', 'device');
}

function stat4_origin_headers(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowed = stat4_config()['dashboard']['allowed_origins'] ?? [];
    if ($origin && (!$allowed || in_array($origin, $allowed, true))) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}
