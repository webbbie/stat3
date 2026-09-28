<?php
declare(strict_types=1);

/**
 * Gemeinsame lokale IP-zu-Land-Aufloesung fuer Stats3 und STAT4.
 * Die vollstaendige IP wird nur waehrend der Anfrage verwendet und nicht
 * durch diese Datei gespeichert.
 */

function pixl_geoip_config(): array
{
    $config = function_exists('pixl_config') ? pixl_config() : [];
    $geoip = is_array($config['geoip'] ?? null) ? $config['geoip'] : [];

    return array_replace([
        'enabled' => false,
        'database_path' => 'data/geoip/dbip-country-lite.mmdb',
        'pushover_country' => true,
        'trust_proxy_headers' => false,
    ], $geoip);
}

function pixl_geoip_enabled(): bool
{
    return !empty(pixl_geoip_config()['enabled']);
}

function pixl_geoip_pushover_country_enabled(): bool
{
    return !empty(pixl_geoip_config()['pushover_country']);
}

function pixl_geoip_trust_proxy_headers(): bool
{
    return !empty(pixl_geoip_config()['trust_proxy_headers']);
}

function pixl_geoip_database_path(): string
{
    $configured = trim((string)(pixl_geoip_config()['database_path'] ?? ''));
    if ($configured === '') {
        $configured = 'data/geoip/dbip-country-lite.mmdb';
    }
    if ($configured[0] === DIRECTORY_SEPARATOR) {
        return $configured;
    }
    return __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $configured);
}

function pixl_geoip_database_status(): array
{
    $path = pixl_geoip_database_path();
    $autoload = __DIR__ . '/vendor/autoload.php';
    return [
        'enabled' => pixl_geoip_enabled(),
        'path' => $path,
        'database_ready' => is_file($path) && is_readable($path) && filesize($path) > 0,
        'reader_ready' => class_exists('MaxMind\\Db\\Reader') || is_file($autoload),
        'modified_at' => is_file($path) ? (int)filemtime($path) : null,
        'size' => is_file($path) ? (int)filesize($path) : 0,
    ];
}

function pixl_geoip_client_ip(array $server): string
{
    $keys = pixl_geoip_trust_proxy_headers()
        ? ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR']
        : ['REMOTE_ADDR'];

    foreach ($keys as $key) {
        $raw = trim((string)($server[$key] ?? ''));
        if ($raw === '') {
            continue;
        }
        $candidate = trim(explode(',', $raw)[0]);
        if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
            return $candidate;
        }
    }
    return '';
}

function pixl_geoip_country_code(string $ip): string
{
    if (!pixl_geoip_enabled() || filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return '';
    }

    $path = pixl_geoip_database_path();
    if (!is_file($path) || !is_readable($path)) {
        return '';
    }

    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!class_exists('MaxMind\\Db\\Reader') && is_file($autoload)) {
        require_once $autoload;
    }
    if (!class_exists('MaxMind\\Db\\Reader')) {
        return '';
    }

    static $readers = [];
    try {
        if (!isset($readers[$path])) {
            $readers[$path] = new MaxMind\Db\Reader($path);
        }
        $record = $readers[$path]->get($ip);
        $code = is_array($record) ? strtoupper(trim((string)($record['country']['iso_code'] ?? ''))) : '';
        return preg_match('/^[A-Z]{2}$/', $code) ? $code : '';
    } catch (Throwable $error) {
        error_log('pixl local geoip lookup failed: ' . $error->getMessage());
        return '';
    }
}

function pixl_geoip_attribution(): string
{
    return 'IP Geolocation by DB-IP';
}
