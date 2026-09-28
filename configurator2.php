<?php
declare(strict_types=1);

/**
 * Public count.js configuration. Edit the values here on the central PHP server.
 * Only browser-visible settings belong here; never add passwords or private keys.
 * External websites keep their single count.js include and fetch this JSON.
 */

// ================
// CONFIGURATION
// ================
$config = [
    "VERSION" => "2.31-fingerprint-exclude",
    "DEBUG" => [
        "ENABLED" => true,
        "FORCE_NOTIFY" => false,
        "BYPASS_FILTERS" => false
    ],
    "ALLOWED_DOMAINS" => [
        "bayerchristian.de",
        "www.bayerchristian.de",
        "inconsequential.org",
        "www.inconsequential.org",
        "localhost",
        "127.0.0.1"
    ],
    "FINGERPRINT_EXCLUDE" => [
        "ENABLED" => true,
        "BROWSER_IS" => "",
        "OS_IS" => "",
        "DEVICE_IS" => "",
        "COUNTRY_IS" => "",
        // Alternative Stats3-Ausschlüsse: Alle Felder innerhalb einer Regel müssen passen.
        // iPhone 16e: Bildschirmprofil, kein eindeutiger Modellnachweis.
        // Andere iPhones mit denselben Bildschirmwerten werden ebenfalls ausgeschlossen.
        "RULES" => [
            [
                "COUNTRY_IS" => "DE",
                "OS_IS" => "ios",
                "DEVICE_IS" => "Mobile",
                "USER_AGENT_CONTAINS" => "iPhone",
                "SCREEN_IS" => ["390x844", "844x390"],
                "PIXEL_RATIO_IS" => 3
            ],
            [
                "COUNTRY_IS" => "DE",
                "OS_IS" => "macos",
                "DEVICE_IS" => "Desktop",
                "USER_AGENT_CONTAINS" => "Macintosh",
                "SCREEN_IS" => ["2560x1440"]
            ]
        ]
    ],
    "SQL_ENDPOINT" => "https://www.bayerchristian.de/stats3/pixl_collect.php",
    "SQL_SITE_ID" => "www.bayerchristian.de",
    "SQL_PUBLIC_KEY" => "",
    "EXCLUDE_CHROME" => false,
    "ACCEPTED_OS" => null,
    "BOT_PATTERNS" => [
        "search" => [
            "googlebot",
            "bingbot",
            "duckduckbot",
            "baiduspider",
            "yandex"
        ],
        "social" => [
            "facebookexternalhit",
            "twitterbot",
            "linkedinbot",
            "whatsapp",
            "telegrambot"
        ],
        "seo" => [
            "semrush",
            "ahrefs",
            "mj12bot",
            "dotbot",
            "petalbot"
        ],
        "ai" => [
            "gptbot",
            "chatgpt",
            "claude",
            "perplexity"
        ],
        "crawler" => [
            "commoncrawl",
            "ccbot",
            "bot",
            "crawler",
            "spider",
            "scraper"
        ],
        "tool" => [
            "curl",
            "wget",
            "python",
            "node-fetch",
            "go-http",
            "php"
        ],
        "automation" => [
            "headless",
            "playwright",
            "puppeteer",
            "selenium",
            "phantomjs"
        ]
    ],
    "READING" => [
        "MIN_SCORE" => 10,
        "THRESHOLDS" => [
            "READ" => 10,
            "GOOD" => 25,
            "EXCELLENT" => 40
        ]
    ],
    "SQL" => [
        "MIN_SCORE_TO_NOTIFY" => 1,
        "MAX_NOTIFICATIONS_PER_PAGE" => 3,
        "SESSION_COOLDOWN_MS" => 0,
        "GLOBAL_COOLDOWN_KEY" => "__pixl77GlobalLastSubmitTsV1",
        "BLOCK_IFRAMES" => true,
        "INCLUDE_CONSOLE_LOGS" => true,
        "INCLUDE_RENDER_ISSUES" => true,
        "NOTIFY_ON_VISIT" => true,
        "VISIT_DELAY_MS" => 600,
        "NOTIFY_ON_READ" => true,
        "READ_DELAY_MS" => 15000,
        "READ_RECHECK_MS" => 5000,
        "NOTIFY_ON_LEAVE" => true,
        "NOTIFY_ON_HIDDEN" => false,
        "FINAL_SUMMARY_ONLY" => false,
        "FINAL_SUMMARY_REASON" => "FINAL",
        "ONE_AUTO_NOTIFICATION_ONLY" => false,
        "TRACK_BOTS_IMMEDIATELY" => true,
        "FETCH_TIMEOUT_MS" => 4500,
        "RETRY_QUEUE_ENABLED" => false,
        "RETRY_QUEUE_KEY" => "__pixl77ReliableQueueV1",
        "RETRY_QUEUE_LEGACY_KEYS" => [
            "__pixl6ReliableQueueV1",
            "__pixl5ReliableQueueV2"
        ],
        "RETRY_QUEUE_MAX_ITEMS" => 0
    ],
    "USER_SESSION" => [
        "TIMEOUT_MINUTES" => 30,
        "PAGE_RECOUNT_MINUTES" => 10,
        "STORAGE_KEY" => "__pixl77UserSessionV1"
    ],
    "CONSOLE_SPY" => [
        "ENABLED" => true,
        "MAX_ENTRIES" => 50,
        "MAX_MESSAGE_LENGTH" => 200
    ],
    "DEVICE_DETECT" => [
        "ENABLED" => true
    ],
    "RENDER_HEALTH" => [
        "ENABLED" => true,
        "INTERVAL_MS" => 5000,
        "MAX_FAILED_CHECKS" => 5,
        "MAX_CONSOLE_ERRORS" => 20
    ],
    "READING_TRACKER" => [
        "ENABLED" => true,
        "SAMPLE_LENGTH" => 31,
        "MAX_SAMPLES" => 50
    ],
    "KNOWN_RESOLUTIONS" => [
        "1920x1080",
        "1366x768",
        "1536x864",
        "1440x900",
        "1280x720",
        "2560x1440",
        "3840x2160",
        "1680x1050",
        "1600x900",
        "1280x800",
        "390x844",
        "393x873",
        "412x915",
        "375x812",
        "360x780",
        "414x896",
        "428x926",
        "430x932",
        "360x800",
        "412x892"
    ],
    "STAT4" => [
        "ENDPOINT" => "https://www.bayerchristian.de/stats3/stat4/collect.php",
        "VISITOR_TTL" => 86400000,
        "HEARTBEAT_INTERVAL" => 30000,
        "TICK_INTERVAL" => 5000
    ]
];

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Vary: Origin');

$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '') {
    $parts = parse_url($origin);
    $host = strtolower((string)($parts['host'] ?? ''));
    $allowed = false;
    if (is_array($parts) && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
        && $host !== '' && !isset($parts['user']) && !isset($parts['pass'])
        && !isset($parts['path']) && !isset($parts['query']) && !isset($parts['fragment'])) {
        foreach ($config['ALLOWED_DOMAINS'] as $domain) {
            $domain = strtolower(trim($domain));
            if ($domain !== '' && ($host === $domain || str_ends_with($host, '.' . $domain))) {
                $allowed = true;
                break;
            }
        }
    }
    if (!$allowed) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'origin_not_allowed']);
        exit;
    }
    header('Access-Control-Allow-Origin: ' . $origin);
}
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($method !== 'GET') {
    header('Allow: GET, OPTIONS');
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'GET required']);
    exit;
}

echo json_encode(['ok' => true, 'schemaVersion' => 1, 'config' => $config],
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
