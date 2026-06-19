<?php
declare(strict_types=1);

/**
 * dashboardx.php
 * - SQLite: main.sqlite (Tabelle: events)
 * - Passwortschutz: freedom22 (Session)
 * - KPIs + Charts + Top-Listen + Live-Reload
 */

session_start();

// -------------------------
// CONFIG
// -------------------------
const DB_PATH = __DIR__ . '/main.sqlite';
const DASH_PASSWORD = 'freedom22';
const TZ = 'Europe/Berlin';

const PAGE_LIMIT_DEFAULT = 25;   // "Top 25 und mehr" -> wir zeigen standardmäßig 100
const MAX_LIMIT = 500;

date_default_timezone_set(TZ);

const DASH_PARAM_INT = 1;
const DASH_PARAM_STR = 2;
const DASH_FETCH_COLUMN = 7;

class DashboardSqliteResult {
  private array $rows = [];
  private int $pos = 0;

  public function __construct($result) {
    if (is_object($result) && method_exists($result, 'fetchArray')) {
      while (($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
        $this->rows[] = $row;
      }
      if (method_exists($result, 'finalize')) {
        $result->finalize();
      }
    }
  }

  public function fetch($mode = null) {
    if (!array_key_exists($this->pos, $this->rows)) {
      return false;
    }
    return $this->rows[$this->pos++];
  }

  public function fetchAll($mode = null): array {
    if ($mode === DASH_FETCH_COLUMN) {
      return array_map(static function (array $row) {
        $values = array_values($row);
        return $values[0] ?? null;
      }, $this->rows);
    }
    return $this->rows;
  }

  public function fetchColumn(int $column = 0) {
    $row = $this->fetch();
    if ($row === false) {
      return false;
    }
    $values = array_values($row);
    return $values[$column] ?? false;
  }
}

class DashboardSqliteStatement {
  private $db;
  private string $sql;
  private array $bound = [];
  private ?DashboardSqliteResult $result = null;

  public function __construct($db, string $sql) {
    $this->db = $db;
    $this->sql = $sql;
  }

  public function bindValue($param, $value, ?int $type = null): bool {
    $this->bound[$param] = [$value, $type];
    return true;
  }

  public function execute(array $params = []): bool {
    $stmt = $this->db->prepare($this->sql);
    if (!$stmt) {
      throw new RuntimeException($this->db->lastErrorMsg());
    }

    $bindings = $this->bound;
    foreach ($params as $key => $value) {
      $bindings[$key] = [$value, null];
    }

    foreach ($bindings as $key => [$value, $type]) {
      $param = is_int($key) ? $key + 1 : $key;
      $stmt->bindValue($param, $value, self::sqliteType($value, $type));
    }

    $result = $stmt->execute();
    if ($result === false) {
      $message = $this->db->lastErrorMsg();
      $stmt->close();
      throw new RuntimeException($message);
    }

    $this->result = new DashboardSqliteResult($result);
    $stmt->close();
    return true;
  }

  public function fetch($mode = null) {
    return $this->result ? $this->result->fetch($mode) : false;
  }

  public function fetchAll($mode = null): array {
    return $this->result ? $this->result->fetchAll($mode) : [];
  }

  public function fetchColumn(int $column = 0) {
    return $this->result ? $this->result->fetchColumn($column) : false;
  }

  private static function sqliteType($value, ?int $type): int {
    if ($type === DASH_PARAM_INT) {
      return SQLITE3_INTEGER;
    }
    if ($type === DASH_PARAM_STR) {
      return SQLITE3_TEXT;
    }
    if ($value === null) {
      return SQLITE3_NULL;
    }
    if (is_int($value) || is_bool($value)) {
      return SQLITE3_INTEGER;
    }
    if (is_float($value)) {
      return SQLITE3_FLOAT;
    }
    return SQLITE3_TEXT;
  }
}

class DashboardSqliteDb {
  private $db;

  public function __construct(string $path) {
    $this->db = new SQLite3($path);
    $this->db->busyTimeout(5000);
  }

  public function exec(string $sql): bool {
    if (!$this->db->exec($sql)) {
      throw new RuntimeException($this->db->lastErrorMsg());
    }
    return true;
  }

  public function query(string $sql): DashboardSqliteResult {
    $result = $this->db->query($sql);
    if ($result === false) {
      throw new RuntimeException($this->db->lastErrorMsg());
    }
    return new DashboardSqliteResult($result);
  }

  public function prepare(string $sql): DashboardSqliteStatement {
    return new DashboardSqliteStatement($this->db, $sql);
  }
}

function open_sqlite_connection(string $path) {
  if (class_exists('PDO')) {
    try {
      if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        return new PDO('sqlite:' . $path, null, null, [
          PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
          PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
      }
    } catch (Throwable $e) {
      // Fall through to SQLite3 below.
    }
  }

  if (class_exists('SQLite3')) {
    return new DashboardSqliteDb($path);
  }

  throw new RuntimeException('Kein SQLite-Treiber verfügbar. Aktiviere in PHP entweder pdo_sqlite oder sqlite3.');
}

function open_main_pdo(string $path) {
  $dir = dirname($path);
  if (!is_dir($dir)) {
    throw new RuntimeException("DB-Verzeichnis nicht gefunden: $dir");
  }
  if (!is_file($path) && !is_writable($dir)) {
    throw new RuntimeException("DB fehlt und das Verzeichnis ist nicht beschreibbar: $dir");
  }
  if (is_file($path) && !is_readable($path)) {
    throw new RuntimeException("DB ist nicht lesbar: $path");
  }

  $pdo = open_sqlite_connection($path);
  $pdo->exec("PRAGMA foreign_keys = ON;");
  $pdo->exec("PRAGMA journal_mode=WAL;");
  $pdo->exec("PRAGMA synchronous=NORMAL;");
  ensure_main_schema($pdo);
  return $pdo;
}

function ensure_main_schema($pdo): void {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS events (
      id            INTEGER PRIMARY KEY AUTOINCREMENT,
      ts            TEXT,
      received_at   TEXT,
      url           TEXT,
      referrer      TEXT,
      lang          TEXT,
      ua            TEXT,
      host_domain   TEXT,
      screen_w      INTEGER,
      screen_h      INTEGER,
      ip            TEXT,
      bot           INTEGER NOT NULL DEFAULT 0,
      dc            INTEGER NOT NULL DEFAULT 0,
      human         INTEGER NOT NULL DEFAULT 0,
      score         REAL,
      dwell_ms      INTEGER,
      unique24h     INTEGER,
      visits_24h    INTEGER,
      visits_total  INTEGER,
      reg           TEXT,
      g_score       REAL,
      ml_score      REAL,
      tracked       INTEGER
    );
  ");

  $colsInfo = $pdo->query("PRAGMA table_info(events)")->fetchAll();
  $colNames = array_map(fn($r) => (string)($r['name'] ?? ''), $colsInfo);
  $missingColumns = [
    'ts' => 'TEXT',
    'received_at' => 'TEXT',
    'url' => 'TEXT',
    'referrer' => 'TEXT',
    'lang' => 'TEXT',
    'ua' => 'TEXT',
    'host_domain' => 'TEXT',
    'screen_w' => 'INTEGER',
    'screen_h' => 'INTEGER',
    'ip' => 'TEXT',
    'bot' => 'INTEGER NOT NULL DEFAULT 0',
    'dc' => 'INTEGER NOT NULL DEFAULT 0',
    'human' => 'INTEGER NOT NULL DEFAULT 0',
    'score' => 'REAL',
    'dwell_ms' => 'INTEGER',
    'unique24h' => 'INTEGER',
    'visits_24h' => 'INTEGER',
    'visits_total' => 'INTEGER',
    'reg' => 'TEXT',
    'g_score' => 'REAL',
    'ml_score' => 'REAL',
    'tracked' => 'INTEGER',
  ];
  foreach ($missingColumns as $name => $type) {
    if (!in_array($name, $colNames, true)) {
      $pdo->exec('ALTER TABLE events ADD COLUMN "' . $name . '" ' . $type);
    }
  }

  $pdo->exec("UPDATE events SET received_at = COALESCE(NULLIF(received_at, ''), NULLIF(ts, ''), strftime('%Y-%m-%dT%H:%M:%fZ','now')) WHERE received_at IS NULL OR received_at = '';");
  $pdo->exec("
    CREATE TRIGGER IF NOT EXISTS trg_events_received_at_fill
    AFTER INSERT ON events
    WHEN NEW.received_at IS NULL OR NEW.received_at = ''
    BEGIN
      UPDATE events
      SET received_at = COALESCE(NULLIF(NEW.ts, ''), strftime('%Y-%m-%dT%H:%M:%fZ','now'))
      WHERE rowid = NEW.rowid;
    END;
  ");

  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_events_ts ON events(ts);");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_events_received_at ON events(received_at);");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_events_url_ts ON events(url, ts);");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_events_ip_ts ON events(ip, ts);");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_events_bot_dc ON events(bot, dc);");
}

// -------------------------
// AUTH
// -------------------------
if (isset($_POST['logout'])) {
  $_SESSION = [];
  session_destroy();
  header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
  exit;
}

if (!($_SESSION['dash_ok'] ?? false)) {
  $err = null;
  if (isset($_POST['password'])) {
    if (hash_equals(DASH_PASSWORD, (string)$_POST['password'])) {
      $_SESSION['dash_ok'] = true;
      header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
      exit;
    } else {
      $err = 'Falsches Passwort.';
    }
  }
  echo renderLogin($err);
  exit;
}

// -------------------------
// DB
// -------------------------
try {
  $pdo = open_main_pdo(DB_PATH);
} catch (Throwable $e) {
  http_response_code(500);
  echo "<h1>SQLite Fehler</h1><p>Pfad: <code>" . h(DB_PATH) . "</code></p><pre>" . h($e->getMessage()) . "</pre>";
  exit;
}

// --- tracker.sqlite (emain.php) ---
function db_tracker_pdo() {
  $pdo = open_sqlite_connection(__DIR__ . '/tracker.sqlite');
  $pdo->exec("PRAGMA journal_mode=WAL;");
  $pdo->exec("PRAGMA synchronous=NORMAL;");
  return $pdo;
}

function tracker_kpis_today($pdo): array {
  // "Heute" über received_at in Localtime
  $whereToday = "date(received_at,'localtime') = date('now','localtime')";

  $impressionsToday = (int)($pdo->query("SELECT COUNT(*) AS n FROM events WHERE $whereToday")->fetch()['n'] ?? 0);

  // Echte Besucher (Heute): distinct IPs mit human=1 (und optional bot/dc=0)
  $realVisitorsToday = (int)($pdo->query("
    SELECT COUNT(DISTINCT ip) AS n
    FROM events
    WHERE $whereToday AND human=1 AND bot=0 AND dc=0 AND ip<>'' AND ip IS NOT NULL
  ")->fetch()['n'] ?? 0);

  $uniqueIpsToday = (int)($pdo->query("
    SELECT COUNT(DISTINCT ip) AS n
    FROM events
    WHERE $whereToday AND ip<>'' AND ip IS NOT NULL
  ")->fetch()['n'] ?? 0);

  $botVisitsToday = (int)($pdo->query("
    SELECT COUNT(*) AS n
    FROM events
    WHERE $whereToday AND (bot=1 OR dc=1)
  ")->fetch()['n'] ?? 0);

  $differentPagesToday = (int)($pdo->query("
    SELECT COUNT(DISTINCT url) AS n
    FROM events
    WHERE $whereToday AND url<>'' AND url IS NOT NULL
  ")->fetch()['n'] ?? 0);

  // Ø Besucher/Minute (Heute): Impressions / Minuten seit 00:00 local
  $minutesElapsed = max(1, (int)floor((time() - strtotime(date('Y-m-d 00:00:00'))) / 60));
  $avgVisitorsPerMinute = $impressionsToday / $minutesElapsed;

  // Ø Score (Gesamt)
  $avgScoreAll = (float)($pdo->query("SELECT AVG(score) AS a FROM events WHERE score IS NOT NULL")->fetch()['a'] ?? 0.0);

  return [
    'real_visitors_today' => $realVisitorsToday,
    'impressions_today'   => $impressionsToday,
    'unique_ips_today'    => $uniqueIpsToday,
    'bot_visits_today'    => $botVisitsToday,
    'pages_today'         => $differentPagesToday,
    'avg_per_min_today'   => $avgVisitorsPerMinute,
    'avg_score_all'       => $avgScoreAll,
  ];
}

function fmt_int(int $n): string {
  return number_format($n, 0, ',', '.');
}
function fmt_float(float $n, int $dec = 2): string {
  return number_format($n, $dec, ',', '.');
}

// usage:
$trackerKpis = null;
try {
  $pdoTracker = db_tracker_pdo();
  $trackerKpis = tracker_kpis_today($pdoTracker);
} catch (Throwable $e) {
  $trackerKpis = ['error' => $e->getMessage()];
}

//
// Ensure there is an ML column in the events table and keep it in sync
//
// Das Dashboard nutzt das Feld "ml", um eine binäre Klassifikation (1=score>=0.70, 0=sonst)
// persistieren zu können. Falls die Spalte nicht existiert, wird sie angelegt. Anschließend
// werden alle Zeilen, bei denen der ML-Wert noch nicht gesetzt oder ungültig ist, anhand
// ihres Scores aktualisiert. Fehler in diesem Block werden ignoriert, um den Betrieb des
// Dashboards nicht zu unterbrechen.
// -------------------------------------------
// Ensure g_score column exists (optional)
// -------------------------------------------
// Hinweis: g_score wird bei dir idealerweise schon in main.php geschrieben.
// Dieser Block ist nur "upgrade-safe", falls eine alte DB ohne g_score läuft.
try {
  $colsInfo = $pdo->query("PRAGMA table_info(events)")->fetchAll();
  $colNames = array_map(fn($r) => (string)($r['name'] ?? ''), $colsInfo);

  if (!in_array('g_score', $colNames, true)) {
    $pdo->exec("ALTER TABLE events ADD COLUMN g_score REAL");
  }
  if (!in_array('ml_score', $colNames, true)) {
    $pdo->exec("ALTER TABLE events ADD COLUMN ml_score REAL");
  }
  if (!in_array('tracked', $colNames, true)) {
    $pdo->exec("ALTER TABLE events ADD COLUMN tracked INTEGER");
  }
} catch (Throwable $e) {
  // Ignorieren – Dashboard soll weiterlaufen
}

// =========================
// LISTE1: Top10 aus data/human.sqlite
// =========================
$humanList = [
  'ok' => false,
  'db' => 'data/human.sqlite',
  'table' => null,
  'cols' => [],
  'rows' => [],
  'error' => null
];

$humanPath = __DIR__ . '/data/human.sqlite';

try {
  if (!is_file($humanPath)) {
    throw new RuntimeException("DB nicht gefunden: $humanPath");
  }

  $pdoHuman = open_sqlite_connection($humanPath);

  // Table finden (typische Namen zuerst)
  $tables = $pdoHuman->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll();
  $tableNames = array_map(fn($r)=> (string)$r['name'], $tables);

  $preferred = ['events','event','human','visits','visit','tracking','track','log'];
  $humanTable = null;
  foreach ($preferred as $t) {
    if (in_array($t, $tableNames, true)) { $humanTable = $t; break; }
  }
  if (!$humanTable) {
    $humanTable = $tableNames[0] ?? null;
  }
  if (!$humanTable) {
    throw new RuntimeException("Keine Tabelle in human.sqlite gefunden");
  }

  // Spalten holen
  $colInfo = $pdoHuman->query("PRAGMA table_info(".$humanTable.")")->fetchAll();
  $colsAll = array_map(fn($r)=> (string)$r['name'], $colInfo);

  // "wichtige Felder" (werden nur genommen, wenn vorhanden)
  $important = [
    'lastseen','received_at','created_utc','ts','datetime','created_at',
    'vid','ip','lang','url','referrer','human','unique24h','dwell','dwell_ms',
    'score','dc','bot','reg','ua'
  ];

  $colsShow = [];
  foreach ($important as $c) {
    if (in_array($c, $colsAll, true)) $colsShow[] = $c;
  }
  if (!$colsShow) {
    // Fallback: nimm die ersten 10 Spalten
    $colsShow = array_slice($colsAll, 0, 10);
  }

  // ORDER BY (zeitliche Spalte finden)
  $orderCandidates = ['lastseen','received_at','created_utc','ts','datetime','created_at'];
  $orderCol = null;
  foreach ($orderCandidates as $c) {
    if (in_array($c, $colsAll, true)) { $orderCol = $c; break; }
  }
  $orderBy = $orderCol ? $orderCol : 'rowid';

  // Query Top10
  $sql = "SELECT " . implode(", ", $colsShow) . " FROM $humanTable ORDER BY $orderBy DESC LIMIT 10";
  $rows = $pdoHuman->query($sql)->fetchAll();

  $humanList['ok'] = true;
  $humanList['table'] = $humanTable;
  $humanList['cols'] = $colsShow;
  $humanList['rows'] = $rows;

} catch (Throwable $e) {
  $humanList['ok'] = false;
  $humanList['error'] = $e->getMessage();
}

// =========================
// LISTE2: Top10 aus data/track.sqlite (mapping auf dein Wunsch-Schema)
// =========================
$trackList = [
  'ok' => false,
  'db' => 'data/track.sqlite',
  'table' => 'visitors',
  'cols' => ['lastseen','vid','ip','lang','human','unique24h','dwell','score','dc','bot','reg','ua'],
  'rows' => [],
  'error' => null
];

$trackPath = __DIR__ . '/data/track.sqlite';

try {
  if (!is_file($trackPath)) {
    throw new RuntimeException("DB nicht gefunden: $trackPath");
  }

  $pdoTrack = open_sqlite_connection($trackPath);

  // Sicherstellen, dass die Tabellen existieren
  $tables = $pdoTrack->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(DASH_FETCH_COLUMN);
  if (!in_array('visitors', $tables, true)) throw new RuntimeException("Tabelle 'visitors' fehlt in track.sqlite");
  if (!in_array('events', $tables, true)) throw new RuntimeException("Tabelle 'events' fehlt in track.sqlite (für unique24h Berechnung)");

  // Top10 aus visitors, Spalten auf deine Namen gemappt
  // unique24h wird aus events der letzten 24h pro vid berechnet
  $sql = "
    SELECT
      v.last_seen_at AS lastseen,
      v.vid          AS vid,
      v.ip           AS ip,
      v.language     AS lang,
      v.human_visits AS human,

      (
        SELECT COUNT(DISTINCT e.url)
        FROM events e
        WHERE e.vid = v.vid
          AND replace(substr(e.created_at,1,19),'T',' ') >= datetime('now','-24 hours')
      ) AS unique24h,

      v.dwell_total_sec AS dwell,
      v.user_score      AS score,
      v.is_datacenter   AS dc,
      v.bot_score       AS bot,
      v.user_registered AS reg,
      v.user_agent      AS ua
    FROM visitors v
    ORDER BY v.last_seen_at DESC
    LIMIT 10
  ";

  $trackList['rows'] = $pdoTrack->query($sql)->fetchAll();
  $trackList['ok'] = true;

} catch (Throwable $e) {
  $trackList['ok'] = false;
  $trackList['error'] = $e->getMessage();
}

// -------------------------
// TIME WINDOWS (Today Berlin -> UTC strings)
// -------------------------
$nowBerlin = new DateTimeImmutable('now', new DateTimeZone(TZ));
$todayStartBerlin = $nowBerlin->setTime(0, 0, 0);
$tomorrowStartBerlin = $todayStartBerlin->modify('+1 day');

$todayStartUTC = $todayStartBerlin->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
$tomorrowStartUTC = $tomorrowStartBerlin->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');

$last60UTC = $nowBerlin->modify('-60 minutes')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
$last24UTC = $nowBerlin->modify('-24 hours')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');

// -------------------------
// HELPERS
// -------------------------
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function q1($pdo, string $sql, array $p = []): mixed {
  $st = $pdo->prepare($sql);
  $st->execute($p);
  return $st->fetchColumn();
}

function qall($pdo, string $sql, array $p = []): array {
  $st = $pdo->prepare($sql);
  $st->execute($p);
  return $st->fetchAll();
}

/**
 * Sehr pragmatische UA-Erkennung (ausreichend fürs Dashboard).
 * Wenn du später "browser" / "os" direkt loggst, kann man das hier entfernen.
 */
function detectBrowser(string $ua): string {
  $u = strtolower($ua);
  if ($u === '') return 'Unknown';
  if (str_contains($u, 'edg/')) return 'Edge';
  if (str_contains($u, 'opr/') || str_contains($u, 'opera')) return 'Opera';
  if (str_contains($u, 'brave')) return 'Brave';
  if (str_contains($u, 'firefox/')) return 'Firefox';
  if (str_contains($u, 'safari') && !str_contains($u, 'chrome')) return 'Safari';
  if (str_contains($u, 'chrome/')) return 'Chrome';
  if (str_contains($u, 'bot') || str_contains($u, 'crawler') || str_contains($u, 'spider')) return 'Bot-UA';
  return 'Other';
}

function detectOS(string $ua): string {
  $u = strtolower($ua);
  if ($u === '') return 'Unknown';
  if (str_contains($u, 'windows')) return 'Windows';
  if (str_contains($u, 'android')) return 'Android';
  if (str_contains($u, 'iphone') || str_contains($u, 'ipad') || str_contains($u, 'ios')) return 'iOS';
  if (str_contains($u, 'mac os x') || str_contains($u, 'macintosh')) return 'macOS';
  if (str_contains($u, 'linux')) return 'Linux';
  return 'Other';
}

function normalizeLang(?string $lang): string {
  $lang = trim((string)$lang);
  if ($lang === '') return 'Unknown';
  // "de-DE,de;q=0.9" -> "de-DE"
  $parts = preg_split('/[,;]/', $lang);
  $x = trim((string)($parts[0] ?? $lang));
  if ($x === '') return 'Unknown';
  return $x;
}

function hostFromUrl(?string $url): string {
  $url = (string)$url;
  if ($url === '') return '';
  $h = parse_url($url, PHP_URL_HOST);
  return is_string($h) ? $h : '';
}

function shortUrl(?string $url, int $max = 80): string {
  $url = (string)$url;
  if (mb_strlen($url) <= $max) return $url;
  return mb_substr($url, 0, $max - 1) . '…';
}

function renderLogin(?string $err): string {
  $e = $err ? '<div class="err">'.h($err).'</div>' : '';
  return <<<HTML
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Dashboard Login</title>
<style>
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#0b0f14;color:#e9eef5;display:grid;place-items:center;height:100vh;margin:0}
  .card{width:min(420px,92vw);background:#121a24;border:1px solid #1f2c3c;border-radius:16px;padding:18px 18px 16px;box-shadow:0 10px 30px rgba(0,0,0,.35)}
  h1{font-size:18px;margin:0 0 10px}
  label{display:block;font-size:12px;opacity:.85;margin:8px 0 6px}
  input{width:100%;padding:12px 12px;border-radius:12px;border:1px solid #2a3b52;background:#0e1520;color:#e9eef5;outline:none}
  button{margin-top:12px;width:100%;padding:12px;border-radius:12px;border:1px solid #2a3b52;background:#1a2636;color:#e9eef5;cursor:pointer}
  .err{background:#2b1320;border:1px solid #5a1a33;color:#ffd0e0;border-radius:12px;padding:10px;margin:10px 0 0}
  .hint{opacity:.7;font-size:12px;margin-top:10px}
</style>
</head>
<body>
  <form class="card" method="post">
    <h1>Dashboard Zugriff</h1>
    {$e}
    <label>Passwort</label>
    <input name="password" type="password" autocomplete="current-password" autofocus>
    <button type="submit">Login</button>
    <div class="hint">SQLite: main.sqlite · TZ: Europe/Berlin</div>
  </form>
</body>
</html>
HTML;
}

// -------------------------
// INPUTS
// -------------------------
$limit = (int)($_GET['limit'] ?? PAGE_LIMIT_DEFAULT);
if ($limit < 25) $limit = 25;
if ($limit > MAX_LIMIT) $limit = MAX_LIMIT;

$autoReload = (int)($_GET['ar'] ?? 1); // 1=on
$autoReload = $autoReload ? 1 : 0;

// -------------------------
// KPI (Today + Overall)
// -------------------------
$kpi = [];
$kpi['real_visitors_today'] = (int) q1($pdo,
  "SELECT COUNT(*) FROM events WHERE received_at >= :a AND received_at < :b AND human=1 AND bot=0",
  [':a'=>$todayStartUTC, ':b'=>$tomorrowStartUTC]
);

$kpi['impressions_today'] = (int) q1($pdo,
  "SELECT COUNT(*) FROM events WHERE received_at >= :a AND received_at < :b",
  [':a'=>$todayStartUTC, ':b'=>$tomorrowStartUTC]
);

$kpi['unique_ips_today'] = (int) q1($pdo,
  "SELECT COUNT(DISTINCT ip) FROM events WHERE received_at >= :a AND received_at < :b AND ip IS NOT NULL AND ip <> ''",
  [':a'=>$todayStartUTC, ':b'=>$tomorrowStartUTC]
);

$kpi['bot_visits_today'] = (int) q1($pdo,
  "SELECT COUNT(*) FROM events WHERE received_at >= :a AND received_at < :b AND bot=1",
  [':a'=>$todayStartUTC, ':b'=>$tomorrowStartUTC]
);

$kpi['unique_pages_today'] = (int) q1($pdo,
  "SELECT COUNT(DISTINCT url) FROM events WHERE received_at >= :a AND received_at < :b AND url IS NOT NULL AND url <> ''",
  [':a'=>$todayStartUTC, ':b'=>$tomorrowStartUTC]
);

// Visitors/Minute (letzte 60 Min, echte Besucher)
$visLast60 = (int) q1($pdo,
  "SELECT COUNT(*) FROM events WHERE received_at >= :t",
  [':t'=>$last60UTC]
);
$kpi['avg_visitors_per_min'] = round($visLast60 / 60, 2);

// -------------------------
// KPI (Overall / Gesamt) – berechne Kennzahlen für alle Datensätze
// Diese Werte werden in einem zweiten KPI-Grid angezeigt.
$kpi['real_visitors_total'] = (int) q1($pdo,
  "SELECT COUNT(*) FROM events WHERE human=1 AND bot=0"
);
$kpi['impressions_total'] = (int) q1($pdo,
  "SELECT COUNT(*) FROM events"
);
$kpi['unique_ips_total'] = (int) q1($pdo,
  "SELECT COUNT(DISTINCT ip) FROM events WHERE ip IS NOT NULL AND ip <> ''"
);
$kpi['bot_visits_total'] = (int) q1($pdo,
  "SELECT COUNT(*) FROM events WHERE bot=1"
);
$kpi['unique_pages_total'] = (int) q1($pdo,
  "SELECT COUNT(DISTINCT url) FROM events WHERE url IS NOT NULL AND url <> ''"
);
// Durchschnittlicher Score über alle Events; kann NULL sein, daher cast auf float
$kpi['avg_score_total'] = (float) q1($pdo,
  "SELECT AVG(score) FROM events"
);

// -------------------------
// TOP LISTS
// -------------------------
$top10_today = qall($pdo,
  "SELECT url, COUNT(*) c
   FROM events
   WHERE received_at >= :a AND received_at < :b AND url IS NOT NULL AND url <> ''
   GROUP BY url
   ORDER BY c DESC
   LIMIT 10",
  [':a'=>$todayStartUTC, ':b'=>$tomorrowStartUTC]
);

$top10_total = qall($pdo,
  "SELECT url, COUNT(*) c
   FROM events
   WHERE url IS NOT NULL AND url <> ''
   GROUP BY url
   ORDER BY c DESC
   LIMIT 10"
);

// -------------------------
// CHARTS - Visitors per day (letzte 30 Tage)
// -------------------------
$days = (int)($_GET['days'] ?? 30);
if ($days < 7) $days = 7;
if ($days > 365) $days = 365;

$sinceUTC = $nowBerlin->modify("-{$days} days")->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
$visPerDay = qall($pdo,
  "SELECT substr(received_at,1,10) AS day, COUNT(*) AS c
   FROM events
   WHERE received_at >= :s AND human=1 AND bot=0
   GROUP BY day
   ORDER BY day ASC",
  [':s'=>$sinceUTC]
);

// -------------------------
// CHARTS - Top Referrer (letzte 24h)
// -------------------------
$refTop = qall($pdo,
  "SELECT referrer, COUNT(*) c
   FROM events
   WHERE received_at >= :s
     AND referrer IS NOT NULL AND referrer <> ''
     AND referrer NOT LIKE '%inconsequential.org%'
     AND referrer NOT LIKE '%bayerchristian.de%'
   GROUP BY referrer
   ORDER BY c DESC
   LIMIT 10",
  [':s'=>$last24UTC]
);

// -------------------------
// CHARTS - Browser / OS / Lang / Res (aus letzter 24h, damit’s “lebendig” bleibt)
// Wir ziehen Rohdaten und aggregieren in PHP, weil SQLite kein UA-Parsing kann.
// -------------------------
$raw24 = qall($pdo,
  "SELECT ua, lang, screen_w, screen_h, referrer
   FROM events
   WHERE received_at >= :s",
  [':s'=>$last24UTC]
);

$browserCounts = [];
$osCounts = [];
$langCounts = [];
$resCounts = [];

foreach ($raw24 as $r) {
  $ua = (string)($r['ua'] ?? '');
  $browser = detectBrowser($ua);
  $os = detectOS($ua);

  $browserCounts[$browser] = ($browserCounts[$browser] ?? 0) + 1;
  $osCounts[$os] = ($osCounts[$os] ?? 0) + 1;

  $lang = normalizeLang($r['lang'] ?? '');
  $langCounts[$lang] = ($langCounts[$lang] ?? 0) + 1;

  $w = $r['screen_w'];
  $h = $r['screen_h'];
  if (is_numeric($w) && is_numeric($h) && (int)$w > 0 && (int)$h > 0) {
    $key = ((int)$w) . "×" . ((int)$h);
    $resCounts[$key] = ($resCounts[$key] ?? 0) + 1;
  } else {
    $resCounts['Unknown'] = ($resCounts['Unknown'] ?? 0) + 1;
  }
}

arsort($browserCounts);
arsort($osCounts);
arsort($langCounts);
arsort($resCounts);

$browserCounts = array_slice($browserCounts, 0, 10, true);
$osCounts = array_slice($osCounts, 0, 10, true);
$langCounts = array_slice($langCounts, 0, 12, true);
$resCounts = array_slice($resCounts, 0, 12, true);

// -------------------------
// BIG LIST (Last accesses)
// -------------------------
// Für die große Liste berechnen wir Visits und Unique-Seiten für die letzten 24 Stunden sowie den Gesamtbesuchs-Zähler
// dynamisch per Subselect. Dadurch kann unique24h korrekt dargestellt werden, auch wenn das Feld in der Tabelle
// nicht gepflegt wird. Die Parameter :last24 (UTC) und :lim werden gebunden.
$sqlRows = "
  SELECT
      e.id,
      e.ts,
      e.received_at,
      e.ip,
      e.lang,
      e.url,
      e.referrer,
      e.human,
      e.score,
      e.dwell_ms,
      e.bot,
      e.dc,

      (SELECT COUNT(*) FROM events x WHERE x.ip = e.ip AND x.ua = e.ua AND x.received_at >= :last24) AS visits_24h,
      (SELECT COUNT(DISTINCT url) FROM events x WHERE x.ip = e.ip AND x.ua = e.ua AND x.received_at >= :last24) AS unique24h,
      (SELECT COUNT(*) FROM events x WHERE x.ip = e.ip AND x.ua = e.ua) AS visits_total,

      e.reg,
      e.ml_score,

      -- g_score: gespeicherter Wert ODER fallback aus score/ml_score
      COALESCE(e.g_score, (0.75 * IFNULL(e.score,0) + 0.25 * IFNULL(e.ml_score,0))) AS g_score,

      -- Gate nur über G_THRESHOLD (hier 0.75 als Beispiel)
      CASE WHEN COALESCE(e.g_score, (0.75 * IFNULL(e.score,0) + 0.25 * IFNULL(e.ml_score,0))) >= 0.575
           THEN 1 ELSE 0 END AS gate_ok,

      e.tracked,
      e.ua
   FROM events e
   ORDER BY e.received_at DESC
   LIMIT :lim
";

$st = $pdo->prepare($sqlRows);
$st->bindValue(':last24', $last24UTC, DASH_PARAM_STR);
$st->bindValue(':lim', (int)$limit, DASH_PARAM_INT); // <<< WICHTIG
$st->execute();
$rows = $st->fetchAll();

// -------------------------
// LAST10 LIST (Letzte 10 Einträge, alle Spalten)
// Wir lesen dynamisch alle Spalten der Tabelle events und holen die 10 neuesten Zeilen nach
// dem präferierten Zeitstempel (ts > received_at > id). Diese Liste wird am Ende
// des Dashboards angezeigt.
$lastList = [
  'ok' => false,
  'cols' => [],
  'rows' => [],
  'order' => null,
  'error' => null
];
try {
  // Spalten sammeln
  $cols3 = [];
  $colRows3 = $pdo->query("PRAGMA table_info(events)")->fetchAll();
  foreach ($colRows3 as $ci) {
    $name = $ci['name'] ?? '';
    if ($name !== '') $cols3[] = $name;
  }
  if (!$cols3) throw new RuntimeException("No columns found in events table");
  // Bevorzugte Sortierspalte
  $orderBy3 = in_array('ts', $cols3, true) ? 'ts'
           : (in_array('received_at', $cols3, true) ? 'received_at'
             : (in_array('id', $cols3, true) ? 'id' : 'rowid'));
  $limit3 = 10;
  $quoted3 = array_map(fn($c) => '"' . str_replace('"', '""', $c) . '"', $cols3);
  $sql3 = "SELECT " . implode(", ", $quoted3) . " FROM events ORDER BY \"$orderBy3\" DESC LIMIT :lim";
  $st3 = $pdo->prepare($sql3);
  $st3->bindValue(':lim', $limit3, DASH_PARAM_INT);
  $st3->execute();
  $rows3 = $st3->fetchAll();
  $lastList['ok'] = true;
  $lastList['cols'] = $cols3;
  $lastList['rows'] = $rows3;
  $lastList['order'] = $orderBy3;
} catch (Throwable $e) {
  $lastList['ok'] = false;
  $lastList['error'] = $e->getMessage();
}

// derive "vid" (best-effort): stable hash from ip+ua
function makeVid(?string $ip, ?string $ua): string {
  $ip = trim((string)$ip);
  $ua = trim((string)$ua);
  if ($ip === '' && $ua === '') return '—';
  return substr(hash('sha256', $ip . '|' . $ua), 0, 12);
}

// -------------------------
// JSON for charts
// -------------------------
$chart_vis_labels = array_map(fn($x)=>$x['day'], $visPerDay);
$chart_vis_values = array_map(fn($x)=>(int)$x['c'], $visPerDay);

$chart_ref_labels = array_map(fn($x)=>(string)$x['referrer'], $refTop);
$chart_ref_values = array_map(fn($x)=>(int)$x['c'], $refTop);

$chart_browser_labels = array_keys($browserCounts);
$chart_browser_values = array_values($browserCounts);

$chart_os_labels = array_keys($osCounts);
$chart_os_values = array_values($osCounts);

$chart_lang_labels = array_keys($langCounts);
$chart_lang_values = array_values($langCounts);

$chart_res_labels = array_keys($resCounts);
$chart_res_values = array_values($resCounts);

?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DashboardX · main.sqlite</title>

  <!-- Theme-Bootstrap: setze Theme-Attribut vor dem Laden der Styles, um Blitzen zu verhindern -->
  <script>
    (function() {
      try {
        const saved = localStorage.getItem('theme');
        const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
        const theme = saved || (prefersDark ? 'dark' : 'light');
        document.documentElement.setAttribute('data-theme', theme);
      } catch (e) {
        // Falls localStorage oder matchMedia nicht verfügbar sind, Standard-Lichtmodus
        document.documentElement.setAttribute('data-theme', 'light');
      }
    })();
  </script>

<style>
  /*
   * Theme-Variablen
   *
   * Basisthema (light) und Dark-Overrides über data-theme="dark". Mit diesen Variablen
   * können Farben bequem gewechselt werden, ohne das Markup anzupassen. Die Akzentfarbe
   * wird für die Gesamt-KPIs verwendet.
   */
  :root {
    --bg:   #f7f9fc;
    --card: #ffffff;
    --card2:#f3f6fa;
    --line: #d1d6e3;
    --line2:#c5ccda;
    --txt:  #0b0f14;
    --mut:  #626f81;
    --ok:   #42a15c;
    --bad:  #d95e6f;
    --warn: #d1983a;
    --accent:#38a3d1;
    color-scheme: light dark;
  }
  :root[data-theme="dark"] {
    --bg:   #0b0f14;
    --card: #121a24;
    --card2:#0e1520;
    --line: #1f2c3c;
    --line2:#2a3b52;
    --txt:  #e9eef5;
    --mut:  #a9b7c9;
    --ok:   #42d392;
    --bad:  #ff5d7a;
    --warn: #ffd166;
    --accent:#0091c9;
  }
  *{box-sizing:border-box}
  body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:var(--bg);color:var(--txt)}
  a{color:inherit}
  .wrap{max-width:1200px;margin:0 auto;padding:14px}
  header{display:flex;gap:10px;align-items:center;justify-content:space-between;margin-bottom:12px}
  .title{display:flex;flex-direction:column;gap:3px}
  h1{font-size:16px;margin:0}
  .sub{font-size:12px;color:var(--mut)}
  .actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
  .btn{
    border:1px solid var(--line2);
    background:var(--card2);
    color:var(--txt);
    padding:10px 12px;
    border-radius:12px;
    cursor:pointer;
  }
  .btn:hover{
    filter:brightness(1.05);
  }
  .tog{display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid var(--line2);border-radius:12px;background:var(--card)}
  .grid{display:grid;grid-template-columns:repeat(6,1fr);gap:10px}
  .card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:12px}

  /* Gesamt-KPI Karten mit Akzent-Rand */
  .card.overall {
    border-color: var(--accent);
  }
  .kpi{display:flex;flex-direction:column;gap:6px;min-height:88px}
  .kpi .k{font-size:12px;color:var(--mut)}
  .kpi .v{font-size:22px;font-weight:700;letter-spacing:.2px}
  .kpi .d{font-size:12px;color:var(--mut)}
  .span2{grid-column:span 2}
  .span3{grid-column:span 3}
  .span6{grid-column:span 6}

  .charts{display:grid;grid-template-columns:repeat(12,1fr);gap:10px;margin-top:10px}
  .chart{grid-column:span 6;background:var(--card);border:1px solid var(--line);border-radius:16px;padding:12px}
  .chart h2{font-size:13px;margin:0 0 10px;color:var(--mut);font-weight:600}
  canvas{width:100% !important;height:260px !important}

  .lists{display:grid;grid-template-columns:repeat(12,1fr);gap:10px;margin-top:10px}
  .list{grid-column:span 6;background:var(--card);border:1px solid var(--line);border-radius:16px;padding:12px}
  .list h2{font-size:13px;margin:0 0 10px;color:var(--mut);font-weight:600}
  table{width:100%;border-collapse:collapse;font-size:12px}
  th,td{padding:8px 8px;border-bottom:1px solid var(--line)}
  th{text-align:left;color:var(--mut);font-weight:600}
  .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace}
  .pill{display:inline-flex;align-items:center;gap:6px;padding:3px 8px;border-radius:999px;border:1px solid var(--line2);background:var(--card2);font-size:12px}
  .pill.ok{border-color:rgba(66,211,146,.35)}
  .pill.bad{border-color:rgba(255,93,122,.35)}
  .pill.warn{border-color:rgba(255,209,102,.35)}
  .footer{margin:14px 0 4px;color:var(--mut);font-size:12px}
  .big{margin-top:10px}
  .big h2{font-size:13px;margin:0 0 10px;color:var(--mut);font-weight:600}
  .big .card{padding:0}
  .scroll{overflow:auto;border-radius:16px}
  .right{display:flex;gap:8px;align-items:center}
  @media (max-width:1100px){
    .grid{grid-template-columns:repeat(2,1fr)}
    .span2,.span3,.span6{grid-column:span 2}
    .chart{grid-column:span 12}
    .list{grid-column:span 12}
  }
  /* FULL WIDTH */
.wrap{
  width:100%;
  max-width:none;
  margin:0;
  padding:15px;
}

/* CHART LAYOUT wie dashboard.php */
.chart-container{
  position:relative;
  height:260px;          /* dashboard.php vibe */
}
.chart-container canvas{
  width:100% !important;
  height:100% !important;
}
/* tst */
.grid {
  display: grid;
  grid-template-columns: repeat(6, minmax(0, 1fr));
  gap: 10px;
  width: 100%;
}
.grid > * {
  min-width: 0;
}
@media (max-width: 1200px){
  .ref-shift { margin-left: 0; }
}
.span2 {
grid-column: auto;
}
/* === 3-Box Layout (12er Grid) === */
.charts,
.grid,
.dashboard-grid {
  display: grid;
  grid-template-columns: repeat(12, minmax(0, 1fr));
  gap: 1rem; /* ggf. anpassen */
  align-items: start;
}

/* Jede Chart-Box nimmt 4 Spalten => 3 pro Reihe */
.chart {
  grid-column: span 4;
}

/* Responsive: 2 pro Reihe */
@media (max-width: 1100px) {
  .chart { grid-column: span 6; }
}

/* Responsive: 1 pro Reihe */
@media (max-width: 700px) {
  .chart { grid-column: span 12; }
}

/* === ref-shift entfernen / neutralisieren === */
.ref-shift {
  margin-left: 0 !important;
}
.span2 {
grid-column: span 2;
}
/* ============================
   LISTE3: UA zu lang -> clamp
   ============================ */

/* Gib deiner Liste3 einen Wrapper, z.B. <section id="list3"> ... */
#list3 .table-wrap,
#list3 table {
  width: 100%;
}

/* Tabelle stabil halten */
#list3 table {
  table-layout: fixed;      /* wichtig: Spaltenbreiten bleiben stabil */
  border-collapse: collapse;
}

/* Zellen dürfen nicht ausbrechen */
#list3 th,
#list3 td {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;      /* Standard: alles in einer Zeile, mit ... */
  max-width: 0;             /* notwendig für ellipsis im table-layout:fixed */
}

/* UA-Spalte: extra streng begrenzen */
#list3 td.ua,
#list3 td.useragent,
#list3 td[data-col="ua"] {
  max-width: 420px;         /* anpassen: 320/420/520 */
}

/* Optional: wenn du KEINE UA-Klasse hast, kannst du auf nth-child gehen:
   Beispiel: UA ist Spalte 6 -> td:nth-child(6) */
#list3 td:nth-child(6) {
  max-width: 420px;
}

/* LISTE3: Tabelle stabil + Text kürzen */
#list3 table{
  width: 100%;
  table-layout: fixed;   /* wichtig für ellipsis in tables */
  border-collapse: collapse;
}

#list3 th,
#list3 td{
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  max-width: 0;          /* nötig, damit ellipsis greift */
}

/* UA-Spalte kürzen (setzt voraus: <td class="ua" ...>) */
#list3 td.ua{
  max-width: 420px;      /* anpassen */
}

/* Falls du horizontales Scrollen willst statt Quetschen */
#list3 .scroll{
  overflow: auto;
  max-width: 100%;
}
</style>
</head>

<body>
<div class="wrap">
  <header>
    <div class="title">
      <h1>DashboardX · main.sqlite</h1>
      <div class="sub">
        Heute (Berlin): <?=h($todayStartBerlin->format('Y-m-d'))?> · Now: <?=h($nowBerlin->format('Y-m-d H:i:s'))?> · Auto-Reload: <?= $autoReload ? 'ON' : 'OFF' ?>
      </div>
    </div>

    <div class="actions">
      <button class="btn" onclick="location.reload()">Reload</button>

      <!-- Theme-Toggle (Light/Dark) -->
      <button class="btn" id="themeToggle" type="button" title="Design umschalten">🌙/☀️</button>

      <label class="tog" title="Alle 10 Sekunden neu laden">
        <input id="ar" type="checkbox" <?= $autoReload ? 'checked' : '' ?> />
        <span>10s Reload</span>
      </label>

      <form method="get" class="right">
        <input type="hidden" name="ar" value="<?= (int)$autoReload ?>">
        <span class="pill" title="Anzahl Zeilen in der großen Liste">Limit</span>
        <input class="btn" style="width:92px" name="limit" value="<?= (int)$limit ?>" />
        <button class="btn" type="submit">Apply</button>
      </form>

      <form method="post">
        <button class="btn" name="logout" value="1">Logout</button>
      </form>
    </div>
  </header>

  <!-- KPIs -->
  <div class="grid">
    <div class="card kpi span2">
      <div class="k">Echte Besucher (Heute)</div>
      <div class="v"><?= (int)$kpi['real_visitors_today'] ?></div>
      <div class="d">human=1 & bot=0</div>
    </div>

    <div class="card kpi span2">
      <div class="k">Seiten Impressionen (Heute)</div>
      <div class="v"><?= (int)$kpi['impressions_today'] ?></div>
      <div class="d">Events gesamt</div>
    </div>

    <div class="card kpi span2">
      <div class="k">Eindeutige IPs (Heute)</div>
      <div class="v"><?= (int)$kpi['unique_ips_today'] ?></div>
      <div class="d">Distinct IP</div>
    </div>

    <div class="card kpi span2">
      <div class="k">Bot Besuche (Heute)</div>
      <div class="v"><?= (int)$kpi['bot_visits_today'] ?></div>
      <div class="d">bot=1</div>
    </div>

    <div class="card kpi span2">
      <div class="k">Verschiedene Seiten (Heute)</div>
      <div class="v"><?= (int)$kpi['unique_pages_today'] ?></div>
      <div class="d">Distinct URL</div>
    </div>

    <div class="card kpi span2">
      <div class="k">Ø Besucher/Minute</div>
      <div class="v"><?= h((string)$kpi['avg_visitors_per_min']) ?></div>
      <div class="d">letzte 60 Min (human, no bot)</div>
    </div>
  </div>

  <!-- Gesamt-KPIs (Overall) -->
  <div class="grid" style="margin-top:10px">
    <div class="card kpi span2 overall">
      <div class="k">Echte Besucher (Gesamt)</div>
      <div class="v"><?= (int)$kpi['real_visitors_total'] ?></div>
      <div class="d">human=1 &amp; bot=0</div>
    </div>
    <div class="card kpi span2 overall">
      <div class="k">Seiten Impressionen (Gesamt)</div>
      <div class="v"><?= (int)$kpi['impressions_total'] ?></div>
      <div class="d">Events gesamt</div>
    </div>
    <div class="card kpi span2 overall">
      <div class="k">Eindeutige IPs (Gesamt)</div>
      <div class="v"><?= (int)$kpi['unique_ips_total'] ?></div>
      <div class="d">Distinct IP</div>
    </div>
    <div class="card kpi span2 overall">
      <div class="k">Bot Besuche (Gesamt)</div>
      <div class="v"><?= (int)$kpi['bot_visits_total'] ?></div>
      <div class="d">bot=1</div>
    </div>
    <div class="card kpi span2 overall">
      <div class="k">Verschiedene Seiten (Gesamt)</div>
      <div class="v"><?= (int)$kpi['unique_pages_total'] ?></div>
      <div class="d">Distinct URL</div>
    </div>
    <div class="card kpi span2 overall">
      <div class="k">Ø Score (Gesamt)</div>
      <div class="v"><?= number_format((float)$kpi['avg_score_total'], 2) ?></div>
      <div class="d">Durchschnitt Score</div>
    </div>
  </div>
<!-- Neue Card Karte -->
<?php if (isset($trackerKpis['error'])): ?>
  <div class="grid" style="margin-top:10px">
    <div class="card kpi span2 overall">
      <div class="k">tracker.sqlite</div>
      <div class="v">Fehler</div>
      <div class="d"><?= h($trackerKpis['error']) ?></div>
    </div>
  </div>
<?php else: ?>

  <div class="grid" style="margin-top:10px">
    <div class="card kpi span2 overall">
      <div class="k">Echte Besucher (Heute)</div>
      <div class="v"><?= fmt_int((int)$trackerKpis['real_visitors_today']) ?></div>
      <div class="d">distinct IP (human=1 &amp; bot=0 &amp; dc=0)</div>
    </div>

    <div class="card kpi span2 overall">
      <div class="k">Seiten Impressionen (Heute)</div>
      <div class="v"><?= fmt_int((int)$trackerKpis['impressions_today']) ?></div>
      <div class="d">Events (heute)</div>
    </div>

    <div class="card kpi span2 overall">
      <div class="k">Eindeutige IPs (Heute)</div>
      <div class="v"><?= fmt_int((int)$trackerKpis['unique_ips_today']) ?></div>
      <div class="d">distinct IP (heute)</div>
    </div>

    <div class="card kpi span2 overall">
      <div class="k">Bot Besuche (Heute)</div>
      <div class="v"><?= fmt_int((int)$trackerKpis['bot_visits_today']) ?></div>
      <div class="d">bot=1 oder dc=1</div>
    </div>

    <div class="card kpi span2 overall">
      <div class="k">Verschiedene Seiten (Heute)</div>
      <div class="v"><?= fmt_int((int)$trackerKpis['pages_today']) ?></div>
      <div class="d">distinct URL (heute)</div>
    </div>

    <div class="card kpi span2 overall">
      <div class="k">Ø Besucher/Minute</div>
      <div class="v"><?= fmt_float((float)$trackerKpis['avg_per_min_today'], 2) ?></div>
      <div class="d">heute seit 00:00</div>
    </div>
  </div>

<?php endif; ?>

  <!-- Charts -->
  <div class="charts">
    <div class="chart">
      <h2>Diagramm: Besucher pro Tag (letzte <?= (int)$days ?> Tage, UTC-Tage)</h2>
      <div class="chart-container">
      <canvas id="c_vis"></canvas>
      </div>
    </div>

    <div class="chart half ref-shift">
      <h2>Diagramm: Top Referrer (letzte 24h)</h2>
      <div class="chart-container">
      <canvas id="c_ref"></canvas>
      </div>
    </div>

    <div class="chart">
      <h2>Diagramm: Browser (letzte 24h)</h2>
      <div class="chart-container">
      <canvas id="c_browser"></canvas>
      </div>
    </div>

    <div class="chart">
      <h2>Diagramm: Betriebssysteme (letzte 24h)</h2>
      <div class="chart-container">
      <canvas id="c_os"></canvas>
      </div>
    </div>

    <div class="chart">
      <h2>Diagramm: Sprachen (letzte 24h)</h2>
      <div class="chart-container">
      <canvas id="c_lang"></canvas>
      </div>
    </div>

    <div class="chart">
      <h2>Diagramm: Bildschirmauflösungen (letzte 24h)</h2>
      <div class="chart-container">
      <canvas id="c_res"></canvas>
      </div>
    </div>
  </div>

  <!-- Top 10 lists -->
  <div class="lists">
    <div class="list">
      <h2>Top 10 Seiten Heute</h2>
      <table>
        <thead><tr><th>#</th><th>URL</th><th class="mono">Count</th></tr></thead>
        <tbody>
        <?php foreach ($top10_today as $i=>$r): ?>
          <tr>
            <td class="mono"><?= $i+1 ?></td>
            <td title="<?= h($r['url'] ?? '') ?>"><?= h(shortUrl($r['url'] ?? '', 90)) ?></td>
            <td class="mono"><?= (int)($r['c'] ?? 0) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$top10_today): ?>
          <tr><td colspan="3" style="color:var(--mut)">Keine Daten.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="list">
      <h2>Top 10 Seiten Gesamt</h2>
      <table>
        <thead><tr><th>#</th><th>URL</th><th class="mono">Count</th></tr></thead>
        <tbody>
        <?php foreach ($top10_total as $i=>$r): ?>
          <tr>
            <td class="mono"><?= $i+1 ?></td>
            <td title="<?= h($r['url'] ?? '') ?>"><?= h(shortUrl($r['url'] ?? '', 90)) ?></td>
            <td class="mono"><?= (int)($r['c'] ?? 0) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$top10_total): ?>
          <tr><td colspan="3" style="color:var(--mut)">Keine Daten.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Big list -->
  <div class="big">
    <h2>Top <?= (int)$limit ?> · Letzte Zugriffe (Top 25 und mehr)</h2>
    <div class="card scroll">
      <table>
        <thead>
          <tr>
            <th>Letzter Zugriff</th>
            <th class="mono">vid</th>
            <th class="mono">ip</th>
            <th>lang</th>
            <th>url</th>
            <th>refferer</th>
            <th>Mensch</th>
            <th class="mono">Score</th>
            <th class="mono">DWell</th>
            <th>Bot</th>
            <th>DC</th>
            <th class="mono">Visits</th>
            <th class="mono">Unique24h</th>
            <th class="mono">Visits24h</th>
            <th class="mono">Visits Total</th>
            <th>REG</th>
<th>ML</th>
<th class="mono">G</th>
<th class="mono">Gate</th>
<th class="mono">Tracked</th>
            <th>UserAgent</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <?php
            $vid = makeVid($r['ip'] ?? null, $r['ua'] ?? null);
            $isHuman = (int)($r['human'] ?? 0) === 1;
            $isBot = (int)($r['bot'] ?? 0) === 1;
            $isDc = (int)($r['dc'] ?? 0) === 1;

            $pillClass = $isBot ? 'bad' : ($isHuman ? 'ok' : 'warn');
            $pillText = $isBot ? 'Bot' : ($isHuman ? 'Human' : 'Unknown');

            $score = $r['score'];
            $scoreStr = is_numeric($score) ? number_format((float)$score, 2) : '—';
            $dwell = $r['dwell_ms'];
            $dwellStr = is_numeric($dwell) ? (string)((int)$dwell) : '—';

            // Visits: best-effort (visits_total if present else 1)
            $visits = is_numeric($r['visits_total'] ?? null) ? (int)$r['visits_total'] : 1;

// ML: echter Wert wie in count.js => events.ml_score
$mlScore = (isset($r['ml_score']) && is_numeric($r['ml_score'])) ? (float)$r['ml_score'] : null;

// Anzeige als Kommazahl (z.B. 0,73)
$ml = ($mlScore === null) ? '—' : number_format($mlScore, 2, ',', '');

$tracked = (isset($r['tracked']) && (int)$r['tracked'] === 1) ? '1' : '0';

            $last = (string)($r['received_at'] ?? $r['ts'] ?? '');
          ?>
          <tr>
            <td class="mono"><?= h($last) ?></td>
            <td class="mono"><?= h($vid) ?></td>
            <td class="mono"><?= h((string)($r['ip'] ?? '')) ?></td>
            <td><?= h(normalizeLang($r['lang'] ?? '')) ?></td>
            <td title="<?= h((string)($r['url'] ?? '')) ?>"><?= h(shortUrl($r['url'] ?? '', 70)) ?></td>
            <td title="<?= h((string)($r['referrer'] ?? '')) ?>"><?= h(shortUrl($r['referrer'] ?? '', 60)) ?></td>

            <td><span class="pill <?= h($pillClass) ?>"><?= h($pillText) ?></span></td>
            <td class="mono"><?= h($scoreStr) ?></td>
            <td class="mono"><?= h($dwellStr) ?></td>

            <td><?= $isBot ? '1' : '0' ?></td>
            <td><?= $isDc ? '1' : '0' ?></td>

            <td class="mono"><?= (int)$visits ?></td>
            <td class="mono"><?= h(is_numeric($r['unique24h'] ?? null) ? (string)(int)$r['unique24h'] : '—') ?></td>
            <td class="mono"><?= h(is_numeric($r['visits_24h'] ?? null) ? (string)(int)$r['visits_24h'] : '—') ?></td>
            <td class="mono"><?= h(is_numeric($r['visits_total'] ?? null) ? (string)(int)$r['visits_total'] : '—') ?></td>

            <td><?= h((string)($r['reg'] ?? '')) ?></td>
<?php
  $gScore = (isset($r['g_score']) && is_numeric($r['g_score'])) ? (float)$r['g_score'] : null;
  $gStr = ($gScore === null) ? '—' : number_format($gScore, 3, ',', '');
  $gateOk = (int)($r['gate_ok'] ?? 0);
  $gateStr = $gateOk ? '1' : '0';
?>

<td class="mono" title="<?= h($mlScore === null ? '' : number_format($mlScore, 3, '.', '')) ?>">
  <?= h($ml) ?>
</td>

<td class="mono" title="<?= h($gScore === null ? '' : number_format($gScore, 4, '.', '')) ?>">
  <?= h($gStr) ?>
</td>

<td class="mono"><?= h($gateStr) ?></td>

<td class="mono"><?= h($tracked) ?></td>
            <td title="<?= h((string)($r['ua'] ?? '')) ?>"><?= h(shortUrl($r['ua'] ?? '', 60)) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
          <tr><td colspan="18" style="color:var(--mut)">Keine Daten.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="footer">
      DB: <span class="mono"><?= h(DB_PATH) ?></span> · Tabelle: <span class="mono">events</span> · Auto-Reload: 10s ·
      Hinweis: Browser/OS werden aus UserAgent heuristisch erkannt.
    </div>
  </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
  // dashboard.php defaults
  Chart.defaults.color = '#8888a0';
  Chart.defaults.borderColor = 'rgba(255, 255, 255, 0.05)';
  // optional: wenn Outfit nicht geladen ist, ignoriert der Browser das einfach
  Chart.defaults.font.family = 'Outfit';

  const colors = {
    cyan:   '#00f5d4',
    magenta:'#f72585',
    violet: '#7b2cbf',
    orange: '#ff6b35',
    blue:   '#4361ee'
  };

  // Data (deine PHP-json Variablen)
  const visLabels = <?= json_encode($chart_vis_labels, JSON_UNESCAPED_SLASHES) ?>;
  const visValues = <?= json_encode($chart_vis_values) ?>;

  const refLabels = <?= json_encode($chart_ref_labels, JSON_UNESCAPED_SLASHES) ?>;
  const refValues = <?= json_encode($chart_ref_values) ?>;

  const bLabels = <?= json_encode($chart_browser_labels, JSON_UNESCAPED_SLASHES) ?>;
  const bValues = <?= json_encode($chart_browser_values) ?>;

  const osLabels = <?= json_encode($chart_os_labels, JSON_UNESCAPED_SLASHES) ?>;
  const osValues = <?= json_encode($chart_os_values) ?>;

  const lLabels = <?= json_encode($chart_lang_labels, JSON_UNESCAPED_SLASHES) ?>;
  const lValues = <?= json_encode($chart_lang_values) ?>;

  const rLabels = <?= json_encode($chart_res_labels, JSON_UNESCAPED_SLASHES) ?>;
  const rValues = <?= json_encode($chart_res_values) ?>;

  // 1) Besucher pro Tag (LINE + Fill)
  new Chart(document.getElementById('c_vis'), {
    type: 'line',
    data: {
      labels: visLabels,
      datasets: [{
        label: 'Besucher',
        data: visValues,
        borderColor: colors.cyan,
        backgroundColor: 'rgba(0, 245, 212, 0.1)',
        fill: true,
        tension: 0.4,
        borderWidth: 2,
        pointRadius: 2
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        x: { grid: { display: false } },
        y: { beginAtZero: true, grid: { color: 'rgba(255,255,255,0.03)' } }
      }
    }
  });

  // 2) Top Referrer (DOUGHNUT)
 new Chart(document.getElementById('c_ref'), {
  type: 'doughnut',
  data: {
    labels: refLabels,
    datasets: [{
      data: refValues,
      backgroundColor: [colors.cyan, colors.magenta, colors.violet, colors.orange, colors.blue, '#666'],
      borderWidth: 0
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,

    // ✅ OPTION B HIER:
    plugins: {
      legend: { position: 'right' },
      tooltip: {
        callbacks: {
          label: (ctx) => `${ctx.label} — ${ctx.parsed}`
        }
      }
    },

    cutout: '60%'
  }
});

  // helper: horizontal bar (wie dashboard.php)
function mkHBar(id, labels, rawValues, color) {
  // rawValues -> echte Werte
  const v = rawValues.map(n => Number(n) || 0);

  // bestimme max und zweitgrößten
  const sorted = [...v].sort((a,b)=>b-a);
  const max = sorted[0] || 0;
  const second = sorted[1] || 0;

  // optischer Cap: max darf nur 25% größer als second sein
  const cap = (second > 0) ? (second * 1.25) : max;

  // displayValues: ggf. größtes Element capped (nur optisch!)
  const display = v.map(x => (x === max && max > cap) ? cap : x);

  return new Chart(document.getElementById(id), {
    type: 'bar',
    data: {
      labels,
      datasets: [{
        data: display,
        backgroundColor: color,
        borderRadius: 4
      }]
    },
    options: {
      indexAxis: 'y',
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },

        // ✅ Tooltip zeigt echte Werte, nicht display-capped
        tooltip: {
          callbacks: {
            label: (ctx) => {
              const i = ctx.dataIndex;
              const raw = v[i] ?? ctx.parsed.x;
              return ` ${raw}`;
            }
          }
        }
      },
      scales: {
        x: {
          beginAtZero: true,
          grid: { color: 'rgba(255,255,255,0.03)' }
        },
        y: { grid: { display: false } }
      }
    }
  });
}

  mkHBar('c_browser', bLabels, bValues, colors.cyan);
  mkHBar('c_os',      osLabels, osValues, colors.magenta);
  mkHBar('c_lang',    lLabels, lValues, colors.violet);
  mkHBar('c_res',     rLabels, rValues, colors.orange);
</script>

<!-- Theme Toggle Script: Light/Dark Modus -->
<script>
(() => {
  const btn = document.getElementById('themeToggle');
  if (!btn) return;
  function getTheme() {
    return document.documentElement.getAttribute('data-theme') || 'light';
  }
  function apply(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    try {
      localStorage.setItem('theme', theme);
    } catch (e) {}
    btn.textContent = theme === 'dark' ? '☀️' : '🌙';
  }
  btn.addEventListener('click', () => {
    const current = getTheme();
    const next = current === 'dark' ? 'light' : 'dark';
    apply(next);
  });
  // Initialisiere Button-Icon
  apply(getTheme());
})();
</script>
<!-- =========================
     LISTE1: human.sqlite Top10
     ========================= -->
<div class="list" style="margin-top:14px">
  <h2>Liste1 · Top10 aus <?= h($humanList['db']) ?> <?= $humanList['ok'] ? '(Tabelle: <span class="mono">'.h($humanList['table']).'</span>)' : '' ?></h2>

  <?php if (!$humanList['ok']): ?>
    <div style="color:var(--mut)">Fehler: <?= h($humanList['error'] ?? 'Unbekannt') ?></div>
  <?php else: ?>
    <div class="scroll">
      <table>
        <thead>
          <tr>
            <?php foreach ($humanList['cols'] as $c): ?>
              <th><?= h($c) ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($humanList['rows'] as $row): ?>
            <tr>
              <?php foreach ($humanList['cols'] as $c): ?>
                <?php $v = (string)($row[$c] ?? ''); ?>
                <td title="<?= h($v) ?>"><?= h($v) ?></td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>

          <?php if (!$humanList['rows']): ?>
            <tr><td colspan="<?= (int)count($humanList['cols']) ?>" style="color:var(--mut)">Keine Daten.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<!-- =========================
     LISTE2: track.sqlite Top10
     ========================= -->
<div class="list" style="margin-top:14px">
  <h2>Liste2 · Top10 aus <?= h($trackList['db']) ?> <?= $trackList['ok'] ? '(Tabelle: <span class="mono">'.h($trackList['table']).'</span>)' : '' ?></h2>

  <?php if (!$trackList['ok']): ?>
    <div style="color:var(--mut)">Fehler: <?= h($trackList['error'] ?? 'Unbekannt') ?></div>
  <?php else: ?>
    <div class="scroll">
      <table>
        <thead>
          <tr>
            <?php foreach ($trackList['cols'] as $c): ?>
              <th><?= h($c) ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($trackList['rows'] as $row): ?>
            <tr>
              <?php foreach ($trackList['cols'] as $c): ?>
                <?php $v = (string)($row[$c] ?? ''); ?>
                <td title="<?= h($v) ?>"><?= h($v) ?></td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>

          <?php if (!$trackList['rows']): ?>
            <tr><td colspan="<?= (int)count($trackList['cols']) ?>" style="color:var(--mut)">Keine Daten.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<!-- =========================
     LISTE3: Letzte 10 Einträge (alle Felder)
     ========================= -->
     <section id="list3">
<div class="list" style="margin-top:14px">
  <h2>Liste3 · Letzte 10 Einträge (alle Felder)
    <?php if ($lastList['ok']): ?>
      (ORDER: <span class="mono"><?= h($lastList['order']) ?></span>)
    <?php endif; ?>
  </h2>
  <?php if (!$lastList['ok']): ?>
    <div style="color:var(--mut)">Fehler: <?= h($lastList['error'] ?? 'Unbekannt') ?></div>
  <?php else: ?>
    <div class="scroll">
      <table>
        <thead>
          <tr>
            <?php foreach ($lastList['cols'] as $c): ?>
              <th><?= h($c) ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($lastList['rows'] as $row): ?>
            <tr>
              <?php foreach ($lastList['cols'] as $c): ?>
<?php $v = (string)($row[$c] ?? ''); ?>
<td class="<?= ($c === 'ua' ? 'ua' : '') ?>" title="<?= h($v) ?>"><?= h($v) ?></td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
          <?php if (!$lastList['rows']): ?>
            <tr><td colspan="<?= (int)count($lastList['cols']) ?>" style="color:var(--mut)">Keine Daten.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
</section>
</body>
</html>
