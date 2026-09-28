<?php
declare(strict_types=1);

require_once __DIR__ . '/pixl_server.php';

/** Page identity and landing rules ignore query parameters and fragments. */
function pixl_captcha_normalize_url(mixed $value): string
{
    if (!is_string($value)) return '';
    $value = trim($value);
    if ($value === '' || strlen($value) > 4096 || preg_match('/[\x00-\x20\x7f]/', $value) || str_contains($value, '\\')) return '';
    if ($value[0] === '/') {
        return str_starts_with($value, '//') ? '' : pixl_url_without_parameters($value);
    }
    $parts = parse_url($value);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
        || isset($parts['user']) || isset($parts['pass']) || filter_var($value, FILTER_VALIDATE_URL) === false) return '';
    $scheme = strtolower($parts['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) return '';
    $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    return $scheme . '://' . strtolower(rtrim($parts['host'], '.'))
        . ($port === ($scheme === 'https' ? 443 : 80) ? '' : ':' . $port)
        . (($parts['path'] ?? '') !== '' ? $parts['path'] : '/');
}

function pixl_captcha_landing_allowed(array $settings, string $url): bool
{
    $rules = $settings['landing_urls'] ?? [];
    if ($rules === []) return true;
    $page = pixl_captcha_normalize_url($url);
    if ($page === '' || $page[0] === '/') return false;
    $path = parse_url($page, PHP_URL_PATH);
    foreach ($rules as $rule) {
        if ($rule !== '' && ($rule === $page || ($rule[0] === '/' && $rule === $path))) return true;
    }
    return false;
}

function pixl_captcha_settings(?array $config = null): array
{
    $config ??= pixl_config();
    $captcha = is_array($config['captcha'] ?? null) ? $config['captcha'] : [];
    return [
        'enabled' => !empty($captcha['enabled']),
        'visitor_interval' => max(1, min(1000000, (int)($captcha['visitor_interval'] ?? 100))),
        'success_target' => max(1, min(10000, (int)($captcha['success_target'] ?? 10))),
        'revision' => (string)($captcha['revision'] ?? ''),
        'page_view_interval' => max(0, min(1000000, (int)($captcha['page_view_interval'] ?? 0))),
        // Invalid manual rules remain empty sentinels, so they cannot turn a
        // configured restriction into an unrestricted, empty list.
        'landing_urls' => array_values(array_unique(array_map('pixl_captcha_normalize_url', (array)($captcha['landing_urls'] ?? [])))),
        'max_duration_hours' => max(1, min(8760, (int)($captcha['max_duration_hours'] ?? 4))),
    ];
}

function pixl_captcha_revision(array $settings): string
{
    // Adding optional defaults must preserve a running phase on older installs.
    if (empty($settings['page_view_interval'])) unset($settings['page_view_interval']);
    if (empty($settings['landing_urls'])) unset($settings['landing_urls']);
    if (($settings['max_duration_hours'] ?? 4) === 4) unset($settings['max_duration_hours']);
    return hash('sha256', json_encode($settings, JSON_THROW_ON_ERROR));
}

function pixl_captcha_ensure_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS pixl_captcha_state (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        revision CHAR(64) NOT NULL DEFAULT '',
        waiting_visitors INT UNSIGNED NOT NULL DEFAULT 0,
        waiting_page_views BIGINT UNSIGNED NOT NULL DEFAULT 0,
        phase_id CHAR(32) NOT NULL DEFAULT '',
        phase_started_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
        successes INT UNSIGNED NOT NULL DEFAULT 0,
        last_cleanup BIGINT UNSIGNED NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (!$pdo->query("SHOW COLUMNS FROM pixl_captcha_state LIKE 'waiting_page_views'")->fetch()) {
        try {
            $pdo->exec('ALTER TABLE pixl_captcha_state ADD COLUMN waiting_page_views BIGINT UNSIGNED NOT NULL DEFAULT 0');
        } catch (PDOException $error) {
            if ((int)($error->errorInfo[1] ?? 0) !== 1060) throw $error;
        }
    }
    if (!$pdo->query("SHOW COLUMNS FROM pixl_captcha_state LIKE 'phase_started_at'")->fetch()) {
        try {
            $pdo->exec('ALTER TABLE pixl_captcha_state ADD COLUMN phase_started_at BIGINT UNSIGNED NOT NULL DEFAULT 0');
        } catch (PDOException $error) {
            if ((int)($error->errorInfo[1] ?? 0) !== 1060) throw $error;
        }
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS pixl_captcha_page_views (
        visitor_hash CHAR(64) NOT NULL,
        page_hash CHAR(64) NOT NULL,
        PRIMARY KEY (visitor_hash, page_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS pixl_captcha_visitors (
        visitor_hash CHAR(64) NOT NULL PRIMARY KEY,
        revision CHAR(64) NOT NULL DEFAULT '',
        last_seen BIGINT UNSIGNED NOT NULL,
        phase_id CHAR(32) NOT NULL DEFAULT '',
        token CHAR(64) NOT NULL DEFAULT '',
        expires_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
        verified_phase CHAR(32) NOT NULL DEFAULT '',
        failed_attempts INT UNSIGNED NOT NULL DEFAULT 0,
        failed_attempt_sources TEXT NULL,
        KEY last_seen (last_seen),
        KEY phase_expiry (phase_id, expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Existing installations keep their visitor tickets and current phase.
    if (!$pdo->query("SHOW COLUMNS FROM pixl_captcha_visitors LIKE 'failed_attempts'")->fetch()) {
        try {
            $pdo->exec('ALTER TABLE pixl_captcha_visitors ADD COLUMN failed_attempts INT UNSIGNED NOT NULL DEFAULT 0');
        } catch (PDOException $error) {
            if ((int)($error->errorInfo[1] ?? 0) !== 1060) throw $error;
        }
    }
    if (!$pdo->query("SHOW COLUMNS FROM pixl_captcha_visitors LIKE 'failed_attempt_sources'")->fetch()) {
        try {
            $pdo->exec('ALTER TABLE pixl_captcha_visitors ADD COLUMN failed_attempt_sources TEXT NULL');
        } catch (PDOException $error) {
            if ((int)($error->errorInfo[1] ?? 0) !== 1060) throw $error;
        }
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS pixl_captcha_stats (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        revision CHAR(64) NOT NULL DEFAULT '',
        failures BIGINT UNSIGNED NOT NULL DEFAULT 0,
        blocked_visitors BIGINT UNSIGNED NULL,
        last_successes INT UNSIGNED NULL,
        last_failures BIGINT UNSIGNED NULL,
        last_blocked_visitors BIGINT UNSIGNED NULL,
        last_completed_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
        tracking_started_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
        sample_started_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
        sample_visitors BIGINT UNSIGNED NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // NULL keeps older, incompletely measured phases distinct from a real zero.
    foreach (['blocked_visitors', 'last_blocked_visitors'] as $column) {
        if (!$pdo->query("SHOW COLUMNS FROM pixl_captcha_stats LIKE '$column'")->fetch()) {
            try {
                $pdo->exec("ALTER TABLE pixl_captcha_stats ADD COLUMN $column BIGINT UNSIGNED NULL");
            } catch (PDOException $error) {
                if ((int)($error->errorInfo[1] ?? 0) !== 1060) throw $error;
            }
        }
    }
    $pdo->exec('INSERT IGNORE INTO pixl_captcha_state (id) VALUES (1)');
    $pdo->exec('INSERT IGNORE INTO pixl_captcha_stats (id) VALUES (1)');
}

/** An older active phase gets its initial timestamp on the next gate request. */
function pixl_captcha_phase_deadline(array $state, array $settings): int
{
    $started = (int)($state['phase_started_at'] ?? 0);
    if ($state['phase_id'] === '' || $started <= 0) return 0;
    return $started + (int)($settings['max_duration_hours'] ?? 4) * 3600;
}

/** Preserve the ended phase's results before starting a new waiting interval. */
function pixl_captcha_finish_phase(array &$state, array &$stats, int $endedAt): void
{
    $stats['last_successes'] = $state['successes'];
    $stats['last_failures'] = $stats['failures'];
    $stats['last_blocked_visitors'] = $stats['blocked_visitors'];
    $stats['last_completed_at'] = $endedAt;
    $stats['failures'] = 0;
    $stats['blocked_visitors'] = 0;
    $state['phase_id'] = '';
    $state['phase_started_at'] = 0;
    $state['waiting_visitors'] = 0;
    $state['waiting_page_views'] = 0;
    $state['successes'] = 0;
}

/** Global gate state; dashboard date/country filters cannot change its countdown. */
function pixl_captcha_summary(PDO $pdo, array $settings, ?int $now = null): array
{
    $now ??= time();
    $row = $pdo->query('SELECT s.*, m.revision AS stats_revision, m.failures, m.blocked_visitors,
        m.last_successes, m.last_failures, m.last_blocked_visitors, m.last_completed_at, m.tracking_started_at,
        m.sample_started_at, m.sample_visitors
        FROM pixl_captcha_state s JOIN pixl_captcha_stats m ON m.id=s.id WHERE s.id=1')->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) throw new RuntimeException('Captcha statistics are missing.');
    $revision = pixl_captcha_revision($settings);
    $current = $row['revision'] === $revision;
    $measured = $current && $row['stats_revision'] === $revision;
    $deadline = $current ? pixl_captcha_phase_deadline($row, $settings) : 0;
    $expired = $deadline > 0 && $now >= $deadline;
    $counting = $current && !$expired;
    $active = !empty($settings['enabled']) && $counting && $row['phase_id'] !== '';
    // N visitors pass; the next (N+1) triggers the gate, including when N remain=0.
    $remaining = empty($settings['enabled']) ? null : ($active ? 0 :
        max(0, (int)$settings['visitor_interval'] - ($counting ? (int)$row['waiting_visitors'] : 0)) + 1);
    $pageViews = $counting ? (int)$row['waiting_page_views'] : 0;
    $remainingPages = empty($settings['enabled']) ? null : ($active ? 0 :
        max(0, (int)($settings['page_view_interval'] ?? 0) - $pageViews));
    $samples = $measured ? (int)$row['sample_visitors'] : 0;
    $sampleStart = $measured ? (int)$row['sample_started_at'] : 0;
    $seconds = $active ? 0 : null;
    if ($remaining !== null && $remainingPages === 0 && !$active && $samples >= 2 && $sampleStart > 0 && $now > $sampleStart) {
        // Include idle time. The first visitor starts observation, not an interval.
        $seconds = (int)ceil($remaining * ($now - $sampleStart) / ($samples - 1));
    }
    return [
        'enabled' => !empty($settings['enabled']), 'active' => $active,
        'remaining_visitors' => $remaining, 'estimated_seconds' => $seconds,
        'waiting_page_views' => $pageViews, 'remaining_page_views' => $remainingPages,
        'successes' => $counting ? (int)$row['successes'] : 0,
        'failures' => $measured && !$expired ? (int)$row['failures'] : 0,
        'blocked_visitors' => !$active ? 0 : ($measured && $row['blocked_visitors'] !== null ? (int)$row['blocked_visitors'] : null),
        // Project an elapsed limit without writing from the statistics page.
        'last_successes' => $expired ? (int)$row['successes'] : ($row['last_successes'] === null ? null : (int)$row['last_successes']),
        'last_failures' => $expired ? ($measured ? (int)$row['failures'] : 0) : ($row['last_failures'] === null ? null : (int)$row['last_failures']),
        'last_blocked_visitors' => $expired ? ($measured && $row['blocked_visitors'] !== null ? (int)$row['blocked_visitors'] : null) : ($row['last_blocked_visitors'] === null ? null : (int)$row['last_blocked_visitors']),
        'last_completed_at' => $expired ? $deadline : (int)$row['last_completed_at'],
        'tracking_started_at' => (int)$row['tracking_started_at'],
        'sample_started_at' => $sampleStart, 'sample_visitors' => $samples,
    ];
}

function pixl_captcha_attempt_source_valid(mixed $source): bool
{
    return is_string($source) && ($source === '' || preg_match('/\A[A-Za-z0-9_-]{1,128}\z/D', $source) === 1);
}

/**
 * One global row serializes visitor selection and success acknowledgements.
 * A visitor is the same salted IP/User-Agent identity used by Stats3, with a
 * 24-hour inactivity window. Reloads and additional pages do not add visitors.
 * Every new visitor receives a challenge while the phase is active; open
 * challenges do not limit selection. The success target or time limit ends the phase.
 * This coordinates the supplied client-side interaction gate, not bot proof.
 */
function pixl_captcha_process(PDO $pdo, array $settings, string $visitorHash, string $action, string $token = '', ?int $now = null, int $failedAttempts = 0, string $attemptSource = '', string $url = ''): array
{
    $skip = ['ok' => true, 'required' => false, 'verified' => false, 'active' => false];
    if (empty($settings['enabled'])) {
        return $skip;
    }
    if (!in_array($action, ['check', 'status', 'verify', 'fail'], true)) {
        return ['ok' => false, 'error' => 'invalid_action'];
    }
    if (!pixl_captcha_attempt_source_valid($attemptSource)) {
        return ['ok' => false, 'error' => 'invalid_attempt_source'];
    }
    $now ??= time();
    $failedAttempts = max(0, min(10000, $failedAttempts));
    $revision = pixl_captcha_revision($settings);
    $page = pixl_captcha_normalize_url($url);
    $landingAllowed = pixl_captcha_landing_allowed($settings, $url);
    $pdo->beginTransaction();
    try {
        $state = $pdo->query('SELECT * FROM pixl_captcha_state WHERE id=1 FOR UPDATE')->fetch(PDO::FETCH_ASSOC);
        if (!is_array($state)) {
            throw new RuntimeException('Captcha state is missing.');
        }
        $stats = $pdo->query('SELECT * FROM pixl_captcha_stats WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        if (!is_array($stats)) throw new RuntimeException('Captcha statistics are missing.');
        if ((int)$stats['tracking_started_at'] === 0) $stats['tracking_started_at'] = $now;
        if ($stats['revision'] !== $revision) {
            $stats['revision'] = $revision;
            $stats['failures'] = 0;
            $stats['sample_started_at'] = 0;
            $stats['sample_visitors'] = 0;
        }
        if ($state['revision'] !== $revision) {
            $state['revision'] = $revision;
            $state['waiting_visitors'] = 0;
            $state['waiting_page_views'] = 0;
            $pdo->exec('DELETE FROM pixl_captcha_page_views');
            $state['phase_id'] = '';
            $state['phase_started_at'] = 0;
            $state['successes'] = 0;
            $stats['blocked_visitors'] = 0;
        }
        if ($state['phase_id'] !== '') {
            // No reliable start exists on an older installation. Start its time
            // limit once, preserving the running phase and all existing tickets.
            if ((int)$state['phase_started_at'] === 0) $state['phase_started_at'] = $now;
            $deadline = pixl_captcha_phase_deadline($state, $settings);
            if ($now >= $deadline) {
                pixl_captcha_finish_phase($state, $stats, $deadline);
                $pdo->exec('DELETE FROM pixl_captcha_page_views');
            }
        }
        if ($now - (int)$state['last_cleanup'] >= 3600) {
            $pdo->prepare('DELETE FROM pixl_captcha_visitors WHERE last_seen < ?')->execute([$now - 86400]);
            $pdo->exec('DELETE p FROM pixl_captcha_page_views p LEFT JOIN pixl_captcha_visitors v ON v.visitor_hash=p.visitor_hash WHERE v.visitor_hash IS NULL');
            $state['last_cleanup'] = $now;
        }
        $query = $pdo->prepare('SELECT * FROM pixl_captcha_visitors WHERE visitor_hash=?');
        $query->execute([$visitorHash]);
        $visitor = $query->fetch(PDO::FETCH_ASSOC);
        $currentVisitor = is_array($visitor) && $visitor['revision'] === $revision;
        $result = $skip;

        if ($action === 'check') {
            $newVisitor = !$currentVisitor || (int)$visitor['last_seen'] <= $now - 86400;
            if ($newVisitor) {
                $pdo->prepare('DELETE FROM pixl_captcha_page_views WHERE visitor_hash=?')->execute([$visitorHash]);
                $visitor = ['revision' => $revision, 'last_seen' => $now, 'phase_id' => '', 'token' => '', 'expires_at' => 0, 'verified_phase' => '', 'failed_attempts' => 0, 'failed_attempt_sources' => '{}'];
                if ((int)$stats['sample_visitors'] === 0) $stats['sample_started_at'] = $now;
                $stats['sample_visitors']++;
            }
            if ($state['phase_id'] === '') {
                if ($page !== '' && $page[0] !== '/') {
                    // The global lock and unique key also deduplicate parallel
                    // tabs. Store only hashes, never full visitor URLs.
                    $seen = $pdo->prepare('INSERT IGNORE INTO pixl_captcha_page_views (visitor_hash,page_hash) VALUES (?,?)');
                    $seen->execute([$visitorHash, hash('sha256', $page)]);
                    $state['waiting_page_views'] += $seen->rowCount();
                }
                if ($newVisitor) {
                    if ((int)$state['waiting_visitors'] >= (int)$settings['visitor_interval']
                        && (int)$state['waiting_page_views'] >= (int)($settings['page_view_interval'] ?? 0)) {
                        // At least N visitors pass first. A new visitor starts
                        // the phase only after the page threshold is met too.
                        $state['phase_id'] = bin2hex(random_bytes(16));
                        $state['phase_started_at'] = $now;
                        $state['successes'] = 0;
                        $stats['blocked_visitors'] = 0;
                    } else {
                        $state['waiting_visitors']++;
                    }
                }
            }
            $visitor['last_seen'] = $now;
            $phase = (string)$state['phase_id'];
            $enteredDuringPhase = $phase !== '' && $visitor['phase_id'] === $phase;
            $alreadySelected = $enteredDuringPhase && $visitor['token'] !== '';
            if ($phase !== '' && ($newVisitor || $enteredDuringPhase) && $visitor['verified_phase'] !== $phase) {
                // Remember new arrivals on other pages without issuing a ticket
                // or counting a block until they reach an allowed landing URL.
                $visitor['phase_id'] = $phase;
                if ($landingAllowed) {
                    // Count a selected visitor once, including abandoned challenges.
                    // Renewing an expired ticket or reloading must not add a visitor.
                    if (!$alreadySelected && $stats['blocked_visitors'] !== null) $stats['blocked_visitors']++;
                    if (!$alreadySelected || (int)$visitor['expires_at'] <= $now) {
                        // Ticket expiry belongs to this visitor only; other open
                        // challenges never prevent a new visitor from being checked.
                        $visitor['token'] = bin2hex(random_bytes(32));
                        $visitor['expires_at'] = $now + 600;
                        $visitor['failed_attempts'] = 0;
                        $visitor['failed_attempt_sources'] = '{}';
                    }
                    $result = ['ok' => true, 'required' => true, 'verified' => false, 'token' => $visitor['token'], 'failed_attempts' => (int)$visitor['failed_attempts']];
                }
            }
            $save = $pdo->prepare('INSERT INTO pixl_captcha_visitors (visitor_hash,revision,last_seen,phase_id,token,expires_at,verified_phase,failed_attempts,failed_attempt_sources) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE revision=VALUES(revision),last_seen=VALUES(last_seen),phase_id=VALUES(phase_id),token=VALUES(token),expires_at=VALUES(expires_at),verified_phase=VALUES(verified_phase),failed_attempts=VALUES(failed_attempts),failed_attempt_sources=VALUES(failed_attempt_sources)');
            $save->execute([$visitorHash, $visitor['revision'], $visitor['last_seen'], $visitor['phase_id'], $visitor['token'], $visitor['expires_at'], $visitor['verified_phase'], $visitor['failed_attempts'], $visitor['failed_attempt_sources']]);
        } elseif (!$landingAllowed) {
            // Polls and acknowledgements from other pages cannot display a gate
            // or change successes/failures, even with an existing visitor ticket.
            $result = $skip;
        } elseif ($currentVisitor && $token !== '' && hash_equals((string)$visitor['token'], $token)) {
            $alreadyVerified = $visitor['phase_id'] !== '' && $visitor['verified_phase'] === $visitor['phase_id'];
            $activeTicket = $state['phase_id'] !== '' && $visitor['phase_id'] === $state['phase_id'] && (int)$visitor['expires_at'] > $now;
            if (!$alreadyVerified && $activeTicket && in_array($action, ['fail', 'verify'], true) && $failedAttempts > 0) {
                // Every page instance has its own high-water mark. Tabs/reloads
                // add independently; retries and late reports cannot count twice.
                // Existing tickets without a map retain their legacy high-water mark.
                $sources = $visitor['failed_attempt_sources'] === null
                    ? ['legacy' => (int)$visitor['failed_attempts']]
                    : json_decode((string)$visitor['failed_attempt_sources'], true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($sources)) throw new RuntimeException('Invalid CAPTCHA attempt state.');
                $sourceKey = $attemptSource === '' ? 'legacy' : 'page:' . $attemptSource;
                if (!array_key_exists($sourceKey, $sources) && count($sources) >= 256) {
                    // Bound the ticket's storage without silently dropping failures
                    // or accepting an incomplete success acknowledgement.
                    $pdo->rollBack();
                    return ['ok' => false, 'error' => 'attempt_source_limit'];
                }
                $previous = (int)($sources[$sourceKey] ?? 0);
                if ($failedAttempts > $previous) {
                    $difference = $failedAttempts - $previous;
                    $sources[$sourceKey] = $failedAttempts;
                    $stats['failures'] += $difference;
                    $visitor['failed_attempts'] = (int)$visitor['failed_attempts'] + $difference;
                    $pdo->prepare('UPDATE pixl_captcha_visitors SET failed_attempts=?,failed_attempt_sources=? WHERE visitor_hash=?')
                        ->execute([$visitor['failed_attempts'], json_encode($sources, JSON_THROW_ON_ERROR), $visitorHash]);
                }
            }
            if ($alreadyVerified) {
                // Retrying an acknowledgement after a lost response is idempotent.
                $result['verified'] = true;
            } elseif ($activeTicket && $action === 'verify') {
                $pdo->prepare('UPDATE pixl_captcha_visitors SET verified_phase=phase_id,expires_at=0 WHERE visitor_hash=?')->execute([$visitorHash]);
                $state['successes']++;
                if ($stats['blocked_visitors'] !== null) $stats['blocked_visitors'] = max(0, (int)$stats['blocked_visitors'] - 1);
                $result['verified'] = true;
                if ((int)$state['successes'] >= (int)$settings['success_target']) {
                    pixl_captcha_finish_phase($state, $stats, $now);
                    $pdo->exec('DELETE FROM pixl_captcha_page_views');
                }
            } elseif ($activeTicket) {
                $result['required'] = true;
            }
        } elseif ($action === 'verify' || $action === 'fail') {
            $result = ['ok' => false, 'error' => 'invalid_challenge'];
        }

        // Report the global phase separately from this visitor's selection.
        // Compute it after verification, which may have just completed the phase.
        if (!empty($result['ok'])) $result['active'] = $state['phase_id'] !== '';

        $pdo->prepare('UPDATE pixl_captcha_state SET revision=?,waiting_visitors=?,waiting_page_views=?,phase_id=?,phase_started_at=?,successes=?,last_cleanup=? WHERE id=1')
            ->execute([$state['revision'], $state['waiting_visitors'], $state['waiting_page_views'], $state['phase_id'], $state['phase_started_at'], $state['successes'], $state['last_cleanup']]);
        $pdo->prepare('UPDATE pixl_captcha_stats SET revision=?,failures=?,blocked_visitors=?,last_successes=?,last_failures=?,last_blocked_visitors=?,last_completed_at=?,tracking_started_at=?,sample_started_at=?,sample_visitors=? WHERE id=1')
            ->execute([$stats['revision'], $stats['failures'], $stats['blocked_visitors'], $stats['last_successes'], $stats['last_failures'], $stats['last_blocked_visitors'], $stats['last_completed_at'], $stats['tracking_started_at'], $stats['sample_started_at'], $stats['sample_visitors']]);
        $pdo->commit();
        return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function pixl_captcha_request_allowed(string $origin, string $url): bool
{
    $a = parse_url($origin);
    $b = parse_url($url);
    if (!is_array($a) || !is_array($b) || !isset($a['host'], $b['host'], $a['scheme'], $b['scheme'])) {
        return false;
    }
    $scheme = strtolower($a['scheme']);
    return in_array($scheme, ['http', 'https'], true)
        && $scheme === strtolower($b['scheme'])
        && strtolower($a['host']) === strtolower($b['host'])
        && ($a['port'] ?? ($scheme === 'https' ? 443 : 80)) === ($b['port'] ?? ($scheme === 'https' ? 443 : 80))
        && pixl_allowed_host($a['host']);
}
