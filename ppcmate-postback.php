<?php

declare(strict_types=1);

require_once __DIR__ . '/pixl_server.php';

/**
 * PPCmate endpoint for a static landing page using stats3/ppcmate-tracker.js.
 *
 * Deploy this file on the PHP/statistics server. The static website never
 * contacts PPCmate directly; this endpoint receives click attribution and
 * sends confirmed conversions to PPCmate server-to-server.
 *
 * PHP requirements: PHP 8.1+, cURL and PDO_MySQL.
 */

final class HttpError extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message
    ) {
        parent::__construct($message);
    }
}

final class PpcmateEndpoint
{
    private const EVENT_ID = '16012';
    private const PPCMATE_URL = 'https://adx.ppcmate.com/event/conversion';

    private const ATTRIBUTION_TTL = 2_592_000; // 30 days
    private const MAX_REQUEST_BYTES = 16_384;

    /** @var list<string> */
    private const ALLOWED_ORIGINS = [
        'https://inconsequential.org',
        'https://www.inconsequential.org',
    ];

    public static function run(): never
    {
        self::applySecurityHeaders();
        self::applyCors();

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new HttpError(405, 'Only POST requests are accepted.');
        }

        $payload = self::readJsonBody();
        $action = self::requiredText($payload, 'action', 20);

        if ($action === 'capture') {
            self::capture($payload);
        }

        if ($action === 'conversion') {
            self::conversion($payload);
        }

        throw new HttpError(422, 'Unknown action.');
    }

    /** @param array<string, mixed> $payload */
    private static function capture(array $payload): never
    {
        $clientId = self::clientId($payload['clientId'] ?? null);
        $trackingId = self::trackingId($payload['cid'] ?? null);
        $now = time();

        $database = self::database();
        self::prune($database, $now);

        $statement = $database->prepare(
            <<<'SQL'
            INSERT INTO ppcmate_attributions (
                client_id,
                tracking_id,
                cost,
                campaign,
                zone,
                ssp,
                geo,
                landing_url,
                captured_at,
                last_seen_at,
                expires_at
            ) VALUES (
                :client_id,
                :tracking_id,
                :cost,
                :campaign,
                :zone,
                :ssp,
                :geo,
                :landing_url,
                :captured_at,
                :last_seen_at,
                :expires_at
            )
            ON DUPLICATE KEY UPDATE
                tracking_id = VALUES(tracking_id),
                cost = VALUES(cost),
                campaign = VALUES(campaign),
                zone = VALUES(zone),
                ssp = VALUES(ssp),
                geo = VALUES(geo),
                landing_url = VALUES(landing_url),
                captured_at = VALUES(captured_at),
                last_seen_at = VALUES(last_seen_at),
                expires_at = VALUES(expires_at)
            SQL
        );

        $statement->execute([
            ':client_id' => $clientId,
            ':tracking_id' => $trackingId,
            ':cost' => self::optionalNumber($payload['cost'] ?? null),
            ':campaign' => self::optionalText($payload['campaign'] ?? null, 255),
            ':zone' => self::optionalText($payload['zone'] ?? null, 100),
            ':ssp' => self::optionalText($payload['ssp'] ?? null, 100),
            ':geo' => self::country($payload['geo'] ?? null),
            ':landing_url' => self::optionalUrl($payload['page'] ?? null),
            ':captured_at' => $now,
            ':last_seen_at' => $now,
            ':expires_at' => $now + self::ATTRIBUTION_TTL,
        ]);

        self::respond(200, [
            'ok' => true,
            'captured' => true,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private static function conversion(array $payload): never
    {
        $clientId = self::clientId($payload['clientId'] ?? null);
        $eventKey = self::optionalText($payload['eventKey'] ?? null, 200) ?? 'default';
        $value = self::conversionValue($payload['value'] ?? 0);
        $now = time();

        $database = self::database();
        $database->beginTransaction();
        $transactionActive = true;

        try {
            self::prune($database, $now);

            $lookup = $database->prepare(
                <<<'SQL'
                SELECT tracking_id
                FROM ppcmate_attributions
                WHERE client_id = :client_id
                  AND expires_at >= :now
                LIMIT 1
                FOR UPDATE
                SQL
            );
            $lookup->execute([
                ':client_id' => $clientId,
                ':now' => $now,
            ]);

            $attribution = $lookup->fetch();

            if (!is_array($attribution)) {
                throw new HttpError(409, 'No active PPCmate attribution was found.');
            }

            $trackingId = self::trackingId($attribution['tracking_id'] ?? null);
            $dedupeHash = hash('sha256', $trackingId . "\0" . $eventKey);

            $duplicateCheck = $database->prepare(
                'SELECT sent_at FROM ppcmate_conversions WHERE dedupe_hash = :hash LIMIT 1'
            );
            $duplicateCheck->execute([':hash' => $dedupeHash]);
            $existing = $duplicateCheck->fetch();

            if (is_array($existing)) {
                $database->commit();
                $transactionActive = false;

                self::respond(200, [
                    'ok' => true,
                    'sent' => false,
                    'duplicate' => true,
                ]);
            }

            $insert = $database->prepare(
                <<<'SQL'
                INSERT INTO ppcmate_conversions (
                    dedupe_hash,
                    client_id,
                    tracking_id,
                    event_key,
                    value,
                    created_at
                ) VALUES (
                    :dedupe_hash,
                    :client_id,
                    :tracking_id,
                    :event_key,
                    :value,
                    :created_at
                )
                SQL
            );
            try {
                $insert->execute([
                    ':dedupe_hash' => $dedupeHash,
                    ':client_id' => $clientId,
                    ':tracking_id' => $trackingId,
                    ':event_key' => $eventKey,
                    ':value' => $value,
                    ':created_at' => $now,
                ]);
            } catch (PDOException $error) {
                // A concurrent request can pass the read check before the
                // other transaction commits. The UNIQUE key remains the
                // authoritative, atomic deduplication guard.
                if (!self::isDuplicateKeyError($error)) {
                    throw $error;
                }

                $database->rollBack();
                $transactionActive = false;

                self::respond(200, [
                    'ok' => true,
                    'sent' => false,
                    'duplicate' => true,
                ]);
            }

            $postback = self::sendPostback($trackingId, $value);

            $update = $database->prepare(
                <<<'SQL'
                UPDATE ppcmate_conversions
                SET sent_at = :sent_at,
                    http_status = :http_status,
                    response = :response
                WHERE dedupe_hash = :dedupe_hash
                SQL
            );
            $update->execute([
                ':sent_at' => time(),
                ':http_status' => $postback['status'],
                ':response' => self::truncate($postback['body'], 2000),
                ':dedupe_hash' => $dedupeHash,
            ]);

            $database->commit();
            $transactionActive = false;

            self::respond(200, [
                'ok' => true,
                'sent' => true,
                'duplicate' => false,
                'httpStatus' => $postback['status'],
            ]);
        } catch (Throwable $error) {
            if ($transactionActive && $database->inTransaction()) {
                $database->rollBack();
            }

            throw $error;
        }
    }

    /** @return array{status: int, body: string} */
    private static function sendPostback(string $trackingId, string $value): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is required.');
        }

        $url = self::PPCMATE_URL . '?' . http_build_query(
            [
                'id' => self::EVENT_ID,
                'trackingId' => $trackingId,
                'value' => $value,
            ],
            '',
            '&',
            PHP_QUERY_RFC3986
        );

        $curl = curl_init($url);

        if ($curl === false) {
            throw new RuntimeException('Could not initialise the PPCmate request.');
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'PPCmate-Static-S2S/1.0',
            CURLOPT_HTTPHEADER => [
                'Accept: application/json, text/plain;q=0.9, */*;q=0.8',
            ],
        ]);

        $body = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($body === false) {
            throw new RuntimeException('PPCmate request failed: ' . $error);
        }

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(
                sprintf('PPCmate returned HTTP status %d.', $status)
            );
        }

        return [
            'status' => $status,
            'body' => trim((string) $body),
        ];
    }

    private static function database(): PDO
    {
        static $database = null;

        if ($database instanceof PDO) {
            return $database;
        }

        $database = pixl_pdo();

        if ($database->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new RuntimeException('The central Stats3 MySQL connection is required.');
        }

        $database->exec(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS ppcmate_attributions (
                client_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                tracking_id VARCHAR(512) NOT NULL,
                cost TEXT NULL,
                campaign VARCHAR(255) NULL,
                zone VARCHAR(100) NULL,
                ssp VARCHAR(100) NULL,
                geo CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NULL,
                landing_url TEXT NULL,
                captured_at BIGINT UNSIGNED NOT NULL,
                last_seen_at BIGINT UNSIGNED NOT NULL,
                expires_at BIGINT UNSIGNED NOT NULL,
                PRIMARY KEY (client_id),
                KEY idx_ppcmate_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL
        );

        $database->exec(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS ppcmate_conversions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                dedupe_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                client_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                tracking_id VARCHAR(512) NOT NULL,
                event_key VARCHAR(200) NOT NULL,
                value VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                created_at BIGINT UNSIGNED NOT NULL,
                sent_at BIGINT UNSIGNED NULL,
                http_status SMALLINT UNSIGNED NULL,
                response TEXT NULL,
                legacy_source VARCHAR(191) NULL,
                legacy_id BIGINT UNSIGNED NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_ppcmate_conversions_dedupe_hash (dedupe_hash),
                UNIQUE KEY uq_ppcmate_conversion_legacy (legacy_source, legacy_id),
                KEY idx_ppcmate_conversions_client_id (client_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL
        );

        return $database;
    }

    private static function isDuplicateKeyError(PDOException $error): bool
    {
        return (string) $error->getCode() === '23000'
            && (int) ($error->errorInfo[1] ?? 0) === 1062;
    }

    private static function prune(PDO $database, int $now): void
    {
        // Keep cleanup lightweight: roughly one percent of requests performs it.
        if (random_int(1, 100) !== 1) {
            return;
        }

        $statement = $database->prepare(
            'DELETE FROM ppcmate_attributions WHERE expires_at < :now'
        );
        $statement->execute([':now' => $now]);
    }

    /** @return array<string, mixed> */
    private static function readJsonBody(): array
    {
        $declaredLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

        if ($declaredLength > self::MAX_REQUEST_BYTES) {
            throw new HttpError(413, 'Request body is too large.');
        }

        $body = file_get_contents('php://input', false, null, 0, self::MAX_REQUEST_BYTES + 1);

        if ($body === false || $body === '') {
            throw new HttpError(400, 'Request body is empty.');
        }

        if (strlen($body) > self::MAX_REQUEST_BYTES) {
            throw new HttpError(413, 'Request body is too large.');
        }

        try {
            $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new HttpError(400, 'Request body contains invalid JSON.');
        }

        if (!is_array($payload)) {
            throw new HttpError(400, 'JSON object expected.');
        }

        return $payload;
    }

    private static function applyCors(): void
    {
        $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));

        if (!in_array($origin, self::ALLOWED_ORIGINS, true)) {
            throw new HttpError(403, 'Origin is not allowed.');
        }

        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 86400');
        header('Vary: Origin');
    }

    private static function applySecurityHeaders(): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }

    /** @param array<string, mixed> $payload */
    private static function requiredText(array $payload, string $key, int $maximumLength): string
    {
        $value = self::optionalText($payload[$key] ?? null, $maximumLength);

        if ($value === null) {
            throw new HttpError(422, sprintf('Missing or invalid field: %s.', $key));
        }

        return $value;
    }

    private static function optionalText(mixed $value, int $maximumLength): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);
        $text = preg_replace('/[\x00-\x1F\x7F]/u', '', $text) ?? '';

        if ($text === '') {
            return null;
        }

        return self::truncate($text, $maximumLength);
    }

    private static function truncate(string $text, int $maximumLength): string
    {
        return function_exists('mb_substr')
            ? mb_substr($text, 0, $maximumLength, 'UTF-8')
            : substr($text, 0, $maximumLength);
    }

    private static function clientId(mixed $value): string
    {
        $clientId = self::optionalText($value, 128);

        if (
            $clientId === null
            || preg_match('/^[A-Za-z0-9._:-]{16,128}$/', $clientId) !== 1
        ) {
            throw new HttpError(422, 'Invalid clientId.');
        }

        return $clientId;
    }

    private static function trackingId(mixed $value): string
    {
        if (!is_scalar($value)) {
            throw new HttpError(422, 'Invalid PPCmate tracking ID.');
        }

        $trackingId = trim((string) $value);

        if (
            $trackingId === ''
            || strlen($trackingId) > 512
            || preg_match('/[\x00-\x20\x7F]/', $trackingId) === 1
        ) {
            throw new HttpError(422, 'Invalid PPCmate tracking ID.');
        }

        return $trackingId;
    }

    private static function optionalNumber(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        if ($text === '' || !is_numeric($text)) {
            return null;
        }

        $number = (float) $text;

        if (!is_finite($number) || $number < 0) {
            return null;
        }

        return self::formatNumber($number);
    }

    private static function conversionValue(mixed $value): string
    {
        $number = self::optionalNumber($value);

        if ($number === null || (float) $number > 1_000_000_000) {
            throw new HttpError(422, 'Invalid conversion value.');
        }

        return $number;
    }

    private static function formatNumber(float $number): string
    {
        $formatted = rtrim(
            rtrim(number_format($number, 6, '.', ''), '0'),
            '.'
        );

        return $formatted === '' ? '0' : $formatted;
    }

    private static function country(mixed $value): ?string
    {
        $country = self::optionalText($value, 3);

        if ($country === null) {
            return null;
        }

        $country = strtoupper($country);

        return preg_match('/^[A-Z]{2,3}$/', $country) === 1 ? $country : null;
    }

    private static function optionalUrl(mixed $value): ?string
    {
        $url = self::optionalText($value, 2048);

        if ($url === null || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : null;
    }

    /** @param array<string, mixed> $data */
    public static function respond(int $status, array $data): never
    {
        http_response_code($status);
        echo json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        exit;
    }
}

try {
    PpcmateEndpoint::run();
} catch (HttpError $error) {
    PpcmateEndpoint::respond($error->status, [
        'ok' => false,
        'error' => $error->getMessage(),
    ]);
} catch (Throwable $error) {
    error_log('PPCmate endpoint: ' . $error->getMessage());

    PpcmateEndpoint::respond(500, [
        'ok' => false,
        'error' => 'Internal tracking error.',
    ]);
}
