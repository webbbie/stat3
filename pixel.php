<?php
declare(strict_types=1);

/**
 * Remote tracking pixel that writes Apache Combined Log Format lines.
 *
 * Improvements:
 * - Configurable trusted proxies for correct client IP via X-Forwarded-For.
 * - Stronger sanitization of fields (removes control chars, limits length).
 * - Safer atomic file writes using file_put_contents(..., LOCK_EX).
 * - Creates log directory securely and falls back to syslog on failure.
 * - Uses DateTimeImmutable with timezone for consistent timestamps.
 * - Avoids double line-injection risks; escapes quotes/backslashes for log fields.
 * - Emits proper headers for a 1x1 transparent GIF and minimal caching.
 *
 * Requirements: PHP 7.4+ (recommended 8+). Ensure the process can write to $LOG_FILE or adjust.
 */

/* -------------------- Configuration -------------------- */

// Absolute path to your log file. Ensure the user running PHP has write permission.
const LOG_FILE = '/var/log/remote-access/access.log';

// If your web server is behind one or more proxies (CDN / load balancer),
// list the IPs or networks (CIDR) of trusted proxies here. If empty, X-Forwarded-For will be ignored.
const TRUSTED_PROXIES = [
    // Example: '203.0.113.10', '198.51.100.0/24',
];

// Maximum length of logged referer / user agent / request fields
const MAX_FIELD_LENGTH = 4000;

// 1x1 transparent GIF (binary)
const PIXEL_BASE64 = 'R0lGODlhAQABAPAAAP///wAAACH5BAAAAAAALAAAAAABAAEAAAICRAEAOw==';

/* -------------------- Helpers -------------------- */

function isIpInCidr(string $ip, string $cidr): bool
{
    if (false === strpos($cidr, '/')) {
        // single IP
        return $ip === $cidr;
    }
    [$subnet, $mask] = explode('/', $cidr, 2);
    if (filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }
        $mask = (int)$mask;
        $maskLong = ~((1 << (32 - $mask)) - 1);
        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }
    // IPv6 support: use inet_pton and bit comparison
    $ipBin = @inet_pton($ip);
    $subnetBin = @inet_pton($subnet);
    if ($ipBin === false || $subnetBin === false) {
        return false;
    }
    $mask = (int)$mask;
    $bytes = intdiv($mask, 8);
    $bits = $mask % 8;
    if (substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
        return false;
    }
    if ($bits === 0) {
        return true;
    }
    $ipByte = ord($ipBin[$bytes]);
    $subnetByte = ord($subnetBin[$bytes]);
    $maskByte = (~((1 << (8 - $bits)) - 1)) & 0xFF;
    return ($ipByte & $maskByte) === ($subnetByte & $maskByte);
}

function isTrustedProxy(string $remoteAddr): bool
{
    foreach (TRUSTED_PROXIES as $p) {
        if (isIpInCidr($remoteAddr, $p)) {
            return true;
        }
    }
    return false;
}

/**
 * Get client IP, respecting X-Forwarded-For only when REMOTE_ADDR is a configured trusted proxy.
 * Returns a validated IP string, or "0.0.0.0" as fallback.
 */
function getClientIp(): string
{
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($remoteAddr === '') {
        return '0.0.0.0';
    }

    // If remote is trusted proxy and we have X-Forwarded-For, parse it
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR']) && isTrustedProxy($remoteAddr)) {
        // X-Forwarded-For is comma separated. The left-most is the original client.
        $parts = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
        foreach ($parts as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }
    }

    // Fall back to REMOTE_ADDR if valid
    if (filter_var($remoteAddr, FILTER_VALIDATE_IP)) {
        return $remoteAddr;
    }

    return '0.0.0.0';
}

/**
 * Remove control characters (including CR/LF), collapse whitespace, and truncate safely.
 */
function cleanLogField(string $value, int $maxLength = MAX_FIELD_LENGTH): string
{
    // Remove ASCII control chars and the Unicode control block
    $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value);
    // Normalize whitespace
    $value = preg_replace('/\s+/u', ' ', $value);
    $value = trim($value);
    if (mb_strlen($value) > $maxLength) {
        $value = mb_substr($value, 0, $maxLength);
    }
    return $value;
}

/** Escape for inclusion in a quoted log field. */
function logQuote(string $value): string
{
    $value = cleanLogField($value);
    $value = str_replace('\\', '\\\\', $value);
    $value = str_replace('"', '\"', $value);
    return $value;
}

/* -------------------- Build log line -------------------- */

$ip = getClientIp();

// Tracked URL: prefer GET param 'url', otherwise fallback to request URI path
$trackedUrl = $_GET['url'] ?? ($_SERVER['REQUEST_URI'] ?? '/');
$trackedUrl = (string)$trackedUrl;
$trackedUrl = trim($trackedUrl);
if ($trackedUrl === '') {
    $trackedUrl = '/';
}
// Remove control chars early
$trackedUrl = preg_replace('/[\x00-\x1F\x7F]/u', '', $trackedUrl);

// Parse into path and query to avoid logging arbitrary hostnames or malformed content
$parsed = parse_url($trackedUrl);

// If parse_url returned a scheme/host, prefer path+query; otherwise treat the raw string as path
$path = '/';
if (isset($parsed['path']) && $parsed['path'] !== '') {
    $path = $parsed['path'];
} else {
    // If parse_url couldn't find a path, try to use the raw value if it starts with /
    if (strpos($trackedUrl, '/') === 0) {
        $path = $trackedUrl;
    }
}
if (!empty($parsed['query'])) {
    $path .= '?' . $parsed['query'];
}

// Ensure the request-line components are safe
$method = 'GET';
$protocol = $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1';
$protocol = preg_replace('/[^\w\/\.]/', '', $protocol); // basic sanitation

$status = 200;

// Referer and UA: prefer GET-provided 'ref' only if not empty, else server header
$referer = $_GET['ref'] ?? ($_SERVER['HTTP_REFERER'] ?? '-');
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '-';

// Time in Apache combined log time: [day/Mon/year:hour:minute:second +ZZZZ]
$date = new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get() ?: 'UTC'));
$time = $date->format('d/M/Y:H:i:s O');

$requestLine = sprintf('%s %s %s', $method, $path, $protocol);

// Size of pixel in bytes
$gif = base64_decode(PIXEL_BASE64, true) ?: '';
$bytes = strlen($gif);

// Build combined-log format line, safe-escaped
$line = sprintf(
    "%s - - [%s] \"%s\" %d %d \"%s\" \"%s\"\n",
    $ip,
    $time,
    logQuote($requestLine),
    $status,
    $bytes,
    logQuote((string)$referer ?: '-'),
    logQuote((string)$userAgent ?: '-')
);

/* -------------------- Write log safely -------------------- */

$logFile = LOG_FILE;
$logDir = dirname($logFile);

if (!is_dir($logDir)) {
    // Create directory with restrictive permissions if possible
    $oldMask = umask(0);
    if (!@mkdir($logDir, 0755, true) && !is_dir($logDir)) {
        umask($oldMask);
        // Fallback: write to syslog
        error_log('remote-tracker: failed to create log directory ' . $logDir);
        @syslog(LOG_WARNING, 'remote-tracker: failed to create log directory ' . $logDir);
    } else {
        umask($oldMask);
    }
}

$wrote = false;
if (is_writable($logDir) || file_exists($logFile) && is_writable($logFile)) {
    // Atomic append with lock
    $res = @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    if ($res === false) {
        @syslog(LOG_WARNING, 'remote-tracker: failed to write to log file ' . $logFile);
    } else {
        $wrote = true;
    }
}

if (!$wrote) {
    // Fallback: send to syslog so we at least keep the event somewhere
    @syslog(LOG_INFO, 'remote-tracker: ' . trim($line));
}

/* -------------------- Send pixel response -------------------- */

if (!headers_sent()) {
    // Minimal caching headers to force revalidation on clients
    header('Content-Type: image/gif');
    header('Content-Length: ' . $bytes);
    header('Cache-Control: no-store, no-cache, must-revalidate, proxy-revalidate, max-age=0');
    header('Pragma: no-cache');
    // Optional: encourage proxies to revalidate
    header('Expires: 0');
}

echo $gif;
flush();
exit;