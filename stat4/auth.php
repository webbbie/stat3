<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function stat4_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']),
        'httponly' => true, 'samesite' => 'Lax',
    ]);
    session_start();
}

function stat4_admin_configured(): bool
{
    return (string) (stat4_config()['admin']['password_hash'] ?? '') !== '';
}

function stat4_auth_cookie_valid(): bool
{
    $admin = stat4_config()['admin'];
    $name = (string) ($admin['cookie_name'] ?? 'stat4_admin');
    $hash = (string) ($admin['password_hash'] ?? '');
    $raw = (string) ($_COOKIE[$name] ?? '');
    if (!$raw || !$hash || !str_contains($raw, '.')) return false;
    [$expires, $signature] = explode('.', $raw, 2);
    if (!ctype_digit($expires) || (int) $expires < time()) return false;
    $expected = hash_hmac('sha256', $expires . '|' . $name, $hash);
    return hash_equals($expected, $signature);
}

function stat4_is_admin(): bool
{
    stat4_session_start();
    $hash = (string) (stat4_config()['admin']['password_hash'] ?? '');
    $version = $_SESSION['stat4_admin_version'] ?? null;
    if ($hash !== '' && !empty($_SESSION['stat4_admin']) && is_string($version)
        && hash_equals(hash('sha256', $hash), $version)) {
        return true;
    }
    // Legacy sessions and sessions issued before a password change must log in again.
    unset($_SESSION['stat4_admin'], $_SESSION['stat4_admin_version']);
    return stat4_auth_cookie_valid();
}

function stat4_login(string $password): bool
{
    $admin = stat4_config()['admin'];
    if (!password_verify($password, (string) ($admin['password_hash'] ?? ''))) return false;
    stat4_session_start();
    session_regenerate_id(true);
    $_SESSION['stat4_admin'] = true;
    $_SESSION['stat4_admin_version'] = hash('sha256', (string) $admin['password_hash']);
    if (!empty($admin['autologin'])) {
        $name = (string) ($admin['cookie_name'] ?? 'stat4_admin');
        $expires = time() + 30 * 86400;
        $signature = hash_hmac('sha256', $expires . '|' . $name, (string) $admin['password_hash']);
        setcookie($name, $expires . '.' . $signature, [
            'expires' => $expires, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']),
            'httponly' => true, 'samesite' => 'Lax',
        ]);
    }
    return true;
}

function stat4_logout(): void
{
    stat4_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    $admin = stat4_config()['admin'];
    setcookie((string) ($admin['cookie_name'] ?? 'stat4_admin'), '', [
        'expires' => time() - 3600, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']),
        'httponly' => true, 'samesite' => 'Lax',
    ]);
}

function stat4_csrf_token(): string
{
    stat4_session_start();
    return $_SESSION['stat4_csrf'] ??= bin2hex(random_bytes(24));
}

function stat4_csrf_valid(string $token): bool
{
    stat4_session_start();
    return isset($_SESSION['stat4_csrf']) && hash_equals($_SESSION['stat4_csrf'], $token);
}

function stat4_require_admin(bool $json = false): void
{
    if (!stat4_admin_configured()) {
        if ($json) stat4_json(['ok' => false, 'error' => 'Admin-Zugang muss zuerst eingerichtet werden.'], 503);
        header('Location: configurator.php'); exit;
    }
    if (stat4_is_admin()) return;
    if ($json) stat4_json(['ok' => false, 'error' => 'Anmeldung erforderlich.'], 401);
    $next = rawurlencode(basename((string) ($_SERVER['REQUEST_URI'] ?? 'index.php')));
    header('Location: login.php?next=' . $next); exit;
}
