<?php
/*
LiteHCP Targeter — single-file PHP + SQLite app (portable)

Deploy (shared hosting / cPanel / Namecheap):
1) Upload this file as `public_html/litehcp.php` and force HTTPS for the domain.
2) Ensure PHP 8+ and write permission next to this file (creates `litehcp.db` + `litehcp_uploads/`).
3) Visit `/litehcp.php` and register the first user (becomes Admin).
4) Click “Load sample” (admin) to import a small embedded CSV + rules + segment.
5) Optional cron recompute: php /home/USER/public_html/litehcp.php action=cron_recompute token=CHANGE_ME
6) Lock down access (HTTPS, strong admin password, optional `.htaccess` allowlist/basic-auth).
*/

declare(strict_types=1);

/*
====================================================================================================
Customize here
====================================================================================================
*/

$CONFIG = [
    'appName' => 'LiteHCP Targeter',
    'repoUrl' => 'https://github.com/tanzir71/litehcp_targeter',
    'dbDsn' => 'sqlite:./litehcp.db',
    'accentColor' => '#1A73E8',
    'locale' => 'en_US',
    'currency' => 'USD',

    'sessionTimeoutSeconds' => 60 * 30,
    'sessionCookieSameSite' => 'Lax',
    'allowOpenRegistrationAfterAdmin' => false,

    'importCommitEvery' => 200,
    'importMaxPreviewRows' => 10,
    'importMaxTestProfiles' => 50,

    'confidenceStrictThreshold' => 70,
    'confidenceMissingPenalty' => 10,
    'confidenceOptionalMissingPenalty' => 3,
    'confidenceImputedPenalty' => 6,

    'defaultImputation' => [
        'region' => ['strategy' => 'fixed', 'value' => 'Unknown'],
        'specialty' => ['strategy' => 'fixed', 'value' => 'General'],
        'organization' => ['strategy' => 'fixed', 'value' => 'Unknown'],
        'role' => ['strategy' => 'fixed', 'value' => 'Clinician'],
        'consent_email' => ['strategy' => 'fixed', 'value' => 0],
        'consent_web' => ['strategy' => 'fixed', 'value' => 0],
        'imports_count' => ['strategy' => 'fixed', 'value' => 1],
        'last_activity_ts' => ['strategy' => 'leave_null_flag', 'value' => null],
    ],

    'priorityWeights' => [
        'consent' => 0.30,
        'recency' => 0.40,
        'engagement' => 0.30,
    ],
    'recencyWindowDays' => 90,
    'defaultProjectedValuePerResponse' => 100.0,

    'allowedExportFields' => [
        'hcp_id','name','email','specialty','region','organization','role',
        'consent_email','consent_web','last_activity_ts','imports_count',
        'persona','priority_score','compliance_flag','confidence_score',
    ],
    'defaultExportFieldsMinimal' => [
        'hcp_id','specialty','region','persona','priority_score','confidence_score',
    ],

    'cronToken' => 'CHANGE_ME',
    'envPath' => __DIR__ . DIRECTORY_SEPARATOR . '.env',

    'uploadMaxBytes' => 5 * 1024 * 1024,
    'uploadAllowedExtensions' => ['csv'],
    'uploadAllowedMimeTypes' => ['text/csv', 'text/plain', 'application/vnd.ms-excel'],

    'logPath' => __DIR__ . DIRECTORY_SEPARATOR . 'litehcp_uploads' . DIRECTORY_SEPARATOR . 'litehcp.log',
    'errorLogPath' => __DIR__ . DIRECTORY_SEPARATOR . 'litehcp_uploads' . DIRECTORY_SEPARATOR . 'litehcp_error.log',

    'rateLimit' => [
        'login' => ['windowSeconds' => 15 * 60, 'maxAttempts' => 8, 'blockSeconds' => 15 * 60],
        'register' => ['windowSeconds' => 60 * 60, 'maxAttempts' => 5, 'blockSeconds' => 60 * 60],
        'export' => ['windowSeconds' => 10 * 60, 'maxAttempts' => 20, 'blockSeconds' => 10 * 60],
    ],

    'hooks' => [
        'enableExternalEnrichment' => false,
    ],
];

function load_env_file(string $path): array {
    if (!is_file($path)) return [];
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) return [];
    $env = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $k = trim(substr($line, 0, $pos));
        $v = trim(substr($line, $pos + 1));
        if ($k === '') continue;
        if ((str_starts_with($v, '"') && str_ends_with($v, '"')) || (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
            $v = substr($v, 1, -1);
        }
        $env[$k] = $v;
    }
    return $env;
}

$ENV = load_env_file($CONFIG['envPath']);
if ($ENV) {
    if (isset($ENV['CRON_TOKEN']) && is_string($ENV['CRON_TOKEN']) && $ENV['CRON_TOKEN'] !== '') $CONFIG['cronToken'] = $ENV['CRON_TOKEN'];
    if (isset($ENV['ACCENT_COLOR']) && is_string($ENV['ACCENT_COLOR']) && preg_match('/^#[0-9a-fA-F]{6}$/', $ENV['ACCENT_COLOR'])) $CONFIG['accentColor'] = $ENV['ACCENT_COLOR'];
    if (isset($ENV['SESSION_TIMEOUT_SECONDS']) && is_string($ENV['SESSION_TIMEOUT_SECONDS']) && ctype_digit($ENV['SESSION_TIMEOUT_SECONDS'])) $CONFIG['sessionTimeoutSeconds'] = (int)$ENV['SESSION_TIMEOUT_SECONDS'];
    if (isset($ENV['UPLOAD_MAX_BYTES']) && is_string($ENV['UPLOAD_MAX_BYTES']) && ctype_digit($ENV['UPLOAD_MAX_BYTES'])) $CONFIG['uploadMaxBytes'] = (int)$ENV['UPLOAD_MAX_BYTES'];
}

$GLOBALS['CONFIG'] = $CONFIG;

/*
Security notes (admins):
- Enforce HTTPS and use a strong admin password.
- Consider restricting access via `.htaccess` (IP allowlist or basic auth).
- Ensure `litehcp.php`, `litehcp.db`, and `litehcp_uploads/` are not world-writable.
*/

/*
====================================================================================================
Sample content (embedded)
====================================================================================================
*/

function sample_csv(): string {
    $ts1 = time() - 86400 * 5;
    $ts2 = time() - 86400 * 45;
    $ts4 = time() - 86400 * 120;
    return "hcp_id,name,email,specialty,region,organization,role,consent_email,consent_web,last_activity_ts,imports_count\n" .
        "HCP-1001,Dr. Amira Khan,amira.khan@example.test,oncology,West,Acme Health,Physician,1,1,{$ts1},3\n" .
        "HCP-1002,Dr. Ben Wu,,cardiology,East,Sunrise Clinic,Physician,1,0,{$ts2},2\n" .
        "HCP-1003,Nurse Carla Diaz,carla.diaz@example.test,oncology,West,Acme Health,Nurse,0,1,,1\n" .
        "HCP-1004,Dr. Dinesh Patel,dinesh.patel@example.test,dermatology,,Metro Hospital,Physician,1,1,{$ts4},5\n";
}

const SAMPLE_RULES = [
    [
        'name' => 'Oncology + Email Consent => Engaged persona',
        'priority' => 100,
        'conditions' => [
            'match' => 'AND',
            'conditions' => [
                ['field' => 'specialty', 'op' => '=', 'value' => 'oncology'],
                ['field' => 'consent_email', 'op' => '=', 'value' => 1],
            ],
            'continue_on_match' => true,
        ],
        'actions' => [
            'set_persona' => 'Oncology Engaged',
            'set_priority_score' => 85,
            'set_compliance_flag' => 1,
            'add_tags' => ['oncology', 'email_ok'],
        ],
    ],
    [
        'name' => 'Low consent => Compliance flag',
        'priority' => 50,
        'conditions' => [
            'match' => 'OR',
            'conditions' => [
                ['field' => 'consent_email', 'op' => '=', 'value' => 0],
                ['field' => 'consent_web', 'op' => '=', 'value' => 0],
            ],
            'continue_on_match' => false,
        ],
        'actions' => [
            'set_compliance_flag' => 0,
            'add_priority_delta' => -10,
            'add_tags' => ['consent_missing'],
        ],
    ],
];

const SAMPLE_SEGMENT = [
    'name' => 'Oncology (high confidence)',
    'rule_ids' => [],
    'sql_filter' => "specialty = 'oncology' AND confidence_score >= 70",
];

/*
====================================================================================================
Bootstrap
====================================================================================================
*/

ini_set('display_errors', '0');
error_reporting(E_ALL);

date_default_timezone_set(@date_default_timezone_get() ?: 'UTC');
setlocale(LC_ALL, $CONFIG['locale'] . '.UTF-8', $CONFIG['locale']);

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'httponly' => true,
    'samesite' => $CONFIG['sessionCookieSameSite'],
]);
session_start();

if (!isset($_SESSION['__last_activity'])) {
    $_SESSION['__last_activity'] = time();
} else {
    $idle = time() - (int)$_SESSION['__last_activity'];
    if ($idle > (int)$CONFIG['sessionTimeoutSeconds']) {
        $_SESSION = [];
        if (session_id() !== '') session_destroy();
        session_start();
        flash('warning', 'Session timed out. Please log in again.');
    }
    $_SESSION['__last_activity'] = time();
}

/*
====================================================================================================
Helpers
====================================================================================================
*/

function htmlEscape(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function h(?string $s): string { return htmlEscape($s); }

function client_ip(): string {
    $candidates = [
        $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ];
    foreach ($candidates as $c) {
        if (!is_string($c) || trim($c) === '') continue;
        $parts = array_map('trim', explode(',', $c));
        $ip = $parts[0] ?? '';
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
    return '0.0.0.0';
}

function sanitize_filename(string $name, string $fallback = 'upload.csv'): string {
    $base = basename($name);
    $base = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $base) ?? '';
    $base = trim($base, " \t\n\r\0\x0B._-");
    if ($base === '') return $fallback;
    if (strlen($base) > 180) $base = substr($base, -180);
    return $base;
}

function log_line(array $CONFIG, string $level, string $message, array $context = []): void {
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'litehcp_uploads';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $ht = $dir . DIRECTORY_SEPARATOR . '.htaccess';
    if (!file_exists($ht)) @file_put_contents($ht, "Deny from all\n");
    $line = json_encode_safe([
        'ts' => time(),
        'level' => $level,
        'ip' => client_ip(),
        'msg' => $message,
        'ctx' => $context,
    ]) . "\n";
    @file_put_contents((string)$CONFIG['logPath'], $line, FILE_APPEND | LOCK_EX);
}

function error_line(array $CONFIG, string $message, array $context = []): void {
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'litehcp_uploads';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $ht = $dir . DIRECTORY_SEPARATOR . '.htaccess';
    if (!file_exists($ht)) @file_put_contents($ht, "Deny from all\n");
    $line = json_encode_safe([
        'ts' => time(),
        'level' => 'error',
        'ip' => client_ip(),
        'msg' => $message,
        'ctx' => $context,
    ]) . "\n";
    @file_put_contents((string)$CONFIG['errorLogPath'], $line, FILE_APPEND | LOCK_EX);
}

function send_security_headers(array $CONFIG, string $nonce): void {
    if (php_sapi_name() === 'cli') return;
    if (headers_sent()) return;

    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

    // CSP customization: if you host assets locally, remove the external domains (fonts.googleapis.com, fonts.gstatic.com, cdn.jsdelivr.net).
    $csp = implode('; ', [
        "default-src 'self'",
        "base-uri 'self'",
        "frame-ancestors 'none'",
        "object-src 'none'",
        "img-src 'self' data:",
        "font-src 'self' https://fonts.gstatic.com",
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net",
        "script-src 'self' https://cdn.jsdelivr.net 'nonce-{$nonce}'",
        "connect-src 'self'",
        "form-action 'self'",
        "upgrade-insecure-requests",
    ]);
    header('Content-Security-Policy: ' . $csp);
}

$GLOBALS['CSP_NONCE'] = base64_encode(random_bytes(16));
send_security_headers($CONFIG, (string)$GLOBALS['CSP_NONCE']);

set_error_handler(function (int $severity, string $message, string $file, int $line) {
    if (!(error_reporting() & $severity)) return false;
    $id = bin2hex(random_bytes(6));
    error_line($GLOBALS['CONFIG'], 'php_error', ['id' => $id, 'severity' => $severity, 'message' => $message, 'file' => $file, 'line' => $line]);
    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, "Error {$id}\n");
        return false;
    }
    http_response_code(500);
    echo 'Server error (' . htmlEscape($id) . ')';
    exit;
});

set_exception_handler(function (Throwable $e) {
    $id = bin2hex(random_bytes(6));
    error_line($GLOBALS['CONFIG'], 'uncaught_exception', ['id' => $id, 'type' => get_class($e), 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, "Exception {$id}: {$e->getMessage()}\n");
        exit(1);
    }
    http_response_code(500);
    echo 'Server error (' . htmlEscape($id) . ')';
    exit;
});

register_shutdown_function(function () {
    $e = error_get_last();
    if (!is_array($e)) return;
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    if (!in_array((int)($e['type'] ?? 0), $fatalTypes, true)) return;
    $id = bin2hex(random_bytes(6));
    error_line($GLOBALS['CONFIG'], 'fatal_error', ['id' => $id, 'error' => $e]);
});

function flash(string $type, string $message): void { $_SESSION['__flash'][] = ['type' => $type, 'message' => $message]; }

function take_flashes(): array { $m = $_SESSION['__flash'] ?? []; unset($_SESSION['__flash']); return is_array($m) ? $m : []; }

function redirect(string $url): never { header('Location: ' . $url); exit; }

function csrf_token(): string { if (empty($_SESSION['__csrf'])) $_SESSION['__csrf'] = bin2hex(random_bytes(32)); return (string)$_SESSION['__csrf']; }

function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'; }

function require_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    $posted = $_POST['csrf'] ?? '';
    if (!is_string($posted) || !hash_equals(csrf_token(), $posted)) {
        http_response_code(400);
        echo 'Bad Request (CSRF)';
        exit;
    }
}

function current_user(): ?array { return isset($_SESSION['user']) && is_array($_SESSION['user']) ? $_SESSION['user'] : null; }
function is_admin(): bool { $u = current_user(); return $u && ($u['role'] ?? '') === 'admin'; }
function require_login(): void { if (!current_user()) redirect('?action=login'); }
function require_admin(): void { require_login(); if (!is_admin()) { http_response_code(403); echo 'Forbidden'; exit; } }

function rate_limit_assert(PDO $pdo, array $CONFIG, string $action, string $identifier = ''): void {
    $ip = client_ip();
    $conf = $CONFIG['rateLimit'][$action] ?? null;
    if (!is_array($conf)) return;
    $now = time();
    $stmt = $pdo->prepare('SELECT window_start, attempts, blocked_until FROM rate_limits WHERE ip = :ip AND action = :a AND identifier = :i');
    $stmt->execute([':ip' => $ip, ':a' => $action, ':i' => $identifier]);
    $row = $stmt->fetch();
    if (!$row) {
        $pdo->prepare('INSERT OR IGNORE INTO rate_limits (ip, action, identifier, window_start, attempts, blocked_until) VALUES (:ip,:a,:i,:ws,0,0)')
            ->execute([':ip' => $ip, ':a' => $action, ':i' => $identifier, ':ws' => $now]);
        return;
    }
    $blockedUntil = (int)($row['blocked_until'] ?? 0);
    if ($blockedUntil > $now) {
        $retry = $blockedUntil - $now;
        header('Retry-After: ' . $retry);
        http_response_code(429);
        echo 'Too Many Requests';
        exit;
    }
    $windowStart = (int)($row['window_start'] ?? 0);
    $window = (int)($conf['windowSeconds'] ?? 900);
    if ($windowStart <= 0 || ($now - $windowStart) > $window) {
        $pdo->prepare('UPDATE rate_limits SET window_start = :ws, attempts = 0, blocked_until = 0 WHERE ip = :ip AND action = :a AND identifier = :i')
            ->execute([':ws' => $now, ':ip' => $ip, ':a' => $action, ':i' => $identifier]);
    }
}

function rate_limit_fail(PDO $pdo, array $CONFIG, string $action, string $identifier = ''): void {
    $ip = client_ip();
    $conf = $CONFIG['rateLimit'][$action] ?? null;
    if (!is_array($conf)) return;
    $now = time();
    $max = (int)($conf['maxAttempts'] ?? 10);
    $blockSeconds = (int)($conf['blockSeconds'] ?? 900);

    $stmt = $pdo->prepare('SELECT window_start, attempts FROM rate_limits WHERE ip = :ip AND action = :a AND identifier = :i');
    $stmt->execute([':ip' => $ip, ':a' => $action, ':i' => $identifier]);
    $row = $stmt->fetch();
    if (!$row) {
        $pdo->prepare('INSERT INTO rate_limits (ip, action, identifier, window_start, attempts, blocked_until) VALUES (:ip,:a,:i,:ws,1,0)')
            ->execute([':ip' => $ip, ':a' => $action, ':i' => $identifier, ':ws' => $now]);
        return;
    }
    $attempts = (int)($row['attempts'] ?? 0) + 1;
    $blockedUntil = 0;
    if ($attempts >= $max) {
        $blockedUntil = $now + $blockSeconds;
    }
    $pdo->prepare('UPDATE rate_limits SET attempts = :n, blocked_until = :b WHERE ip = :ip AND action = :a AND identifier = :i')
        ->execute([':n' => $attempts, ':b' => $blockedUntil, ':ip' => $ip, ':a' => $action, ':i' => $identifier]);
}

function rate_limit_hit(PDO $pdo, array $CONFIG, string $action, string $identifier = ''): void {
    rate_limit_fail($pdo, $CONFIG, $action, $identifier);
}

function rate_limit_clear(PDO $pdo, string $action, string $identifier = ''): void {
    $ip = client_ip();
    $pdo->prepare('DELETE FROM rate_limits WHERE ip = :ip AND action = :a AND identifier = :i')
        ->execute([':ip' => $ip, ':a' => $action, ':i' => $identifier]);
}

function parse_boolish($v): int {
    if (is_bool($v)) return $v ? 1 : 0;
    if (is_int($v)) return $v ? 1 : 0;
    if (is_string($v)) {
        $t = strtolower(trim($v));
        if (in_array($t, ['1','true','yes','y'], true)) return 1;
        if (in_array($t, ['0','false','no','n'], true)) return 0;
    }
    return 0;
}

function to_int_or_null($v): ?int {
    if ($v === null) return null;
    if (is_int($v)) return $v;
    if (is_string($v)) { $t = trim($v); if ($t === '') return null; if (preg_match('/^-?\d+$/', $t)) return (int)$t; }
    if (is_float($v)) return (int)$v;
    return null;
}

function json_encode_safe($v): string { return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}'; }
function json_decode_safe(?string $s): array { if (!$s) return []; $d = json_decode($s, true); return is_array($d) ? $d : []; }

/*
====================================================================================================
Database
====================================================================================================
*/

function db(array $CONFIG): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $pdo = new PDO($CONFIG['dbDsn'], null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL;');
    $pdo->exec('PRAGMA synchronous=NORMAL;');
    $pdo->exec('PRAGMA foreign_keys=ON;');
    $pdo->exec('PRAGMA temp_store=MEMORY;');
    return $pdo;
}

function ensure_schema(PDO $pdo): void {
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL, role TEXT NOT NULL, created_at INTEGER NOT NULL);');
    $pdo->exec('CREATE TABLE IF NOT EXISTS profiles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        hcp_id TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL,
        email TEXT,
        specialty TEXT,
        region TEXT,
        organization TEXT,
        role TEXT,
        consent_email INTEGER NOT NULL DEFAULT 0,
        consent_web INTEGER NOT NULL DEFAULT 0,
        last_activity_ts INTEGER,
        imports_count INTEGER NOT NULL DEFAULT 0,
        persona TEXT,
        priority_score REAL NOT NULL DEFAULT 0,
        compliance_flag INTEGER NOT NULL DEFAULT 0,
        metadata TEXT NOT NULL DEFAULT "{}",
        confidence_score INTEGER NOT NULL DEFAULT 0,
        created_at INTEGER NOT NULL
    );');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_profiles_specialty ON profiles(specialty);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_profiles_region ON profiles(region);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_profiles_priority ON profiles(priority_score);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_profiles_confidence ON profiles(confidence_score);');
    $pdo->exec('CREATE TABLE IF NOT EXISTS rules (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, conditions_json TEXT NOT NULL, actions_json TEXT NOT NULL, priority INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL);');
    $pdo->exec('CREATE TABLE IF NOT EXISTS segments (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, rule_ids TEXT NOT NULL DEFAULT "[]", sql_filter TEXT, last_run_at INTEGER);');
    $pdo->exec('CREATE TABLE IF NOT EXISTS exports (id INTEGER PRIMARY KEY AUTOINCREMENT, segment_id INTEGER, filename TEXT NOT NULL, created_at INTEGER NOT NULL);');
    $pdo->exec('CREATE TABLE IF NOT EXISTS audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT NOT NULL, payload TEXT NOT NULL DEFAULT "{}", ts INTEGER NOT NULL);');
    $pdo->exec('CREATE TABLE IF NOT EXISTS imports (id INTEGER PRIMARY KEY AUTOINCREMENT, filename TEXT NOT NULL, rows_total INTEGER NOT NULL DEFAULT 0, rows_imported INTEGER NOT NULL DEFAULT 0, errors_json TEXT NOT NULL DEFAULT "{}", created_at INTEGER NOT NULL);');
    $pdo->exec('CREATE TABLE IF NOT EXISTS app_settings (id INTEGER PRIMARY KEY CHECK (id = 1), json TEXT NOT NULL);');
    $pdo->exec('CREATE TABLE IF NOT EXISTS rate_limits (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ip TEXT NOT NULL,
        action TEXT NOT NULL,
        identifier TEXT NOT NULL DEFAULT "",
        window_start INTEGER NOT NULL,
        attempts INTEGER NOT NULL DEFAULT 0,
        blocked_until INTEGER NOT NULL DEFAULT 0,
        UNIQUE(ip, action, identifier)
    );');

    $row = $pdo->query('SELECT COUNT(*) c FROM app_settings WHERE id = 1')->fetch();
    if ((int)($row['c'] ?? 0) === 0) {
        $pdo->prepare('INSERT INTO app_settings (id, json) VALUES (1, :j)')->execute([':j' => json_encode_safe(['export_pii' => false, 'open_registration' => false, 'strict_confidence_threshold' => 70])]);
    }
}

function get_settings(PDO $pdo): array {
    $row = $pdo->query('SELECT json FROM app_settings WHERE id = 1')->fetch();
    return json_decode_safe(is_array($row) ? (string)($row['json'] ?? '{}') : '{}');
}
function set_settings(PDO $pdo, array $settings): void { $pdo->prepare('UPDATE app_settings SET json = :j WHERE id = 1')->execute([':j' => json_encode_safe($settings)]); }

function audit(PDO $pdo, ?int $userId, string $action, array $payload = []): void {
    $pdo->prepare('INSERT INTO audit_log (user_id, action, payload, ts) VALUES (:u,:a,:p,:t)')->execute([
        ':u' => $userId,
        ':a' => $action,
        ':p' => json_encode_safe($payload),
        ':t' => time(),
    ]);
}

function ensure_upload_dir(): string {
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'litehcp_uploads';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $ht = $dir . DIRECTORY_SEPARATOR . '.htaccess';
    if (!file_exists($ht)) @file_put_contents($ht, "Deny from all\n");
    $idx = $dir . DIRECTORY_SEPARATOR . 'index.html';
    if (!file_exists($idx)) @file_put_contents($idx, "<!doctype html><meta charset=\"utf-8\"><title>Forbidden</title>Forbidden\n");
    return $dir;
}

/*
====================================================================================================
Renderer
====================================================================================================
*/

function page_header(string $title, array $CONFIG, array $settings, ?array $user): void {
    $app = $CONFIG['appName'];
    $accent = $CONFIG['accentColor'];
    $isLoggedIn = $user !== null;
    $tabs = ['dashboard'=>'Dashboard','import'=>'Import','profiles'=>'Profiles','rules'=>'Rules','segments'=>'Segments','simulator'=>'Simulator','settings'=>'Settings'];
    $action = $_GET['action'] ?? 'dashboard';

    echo "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">";
    echo "<title>" . h($app) . " — " . h($title) . "</title>";
    echo "<link rel=\"preconnect\" href=\"https://fonts.googleapis.com\"><link rel=\"preconnect\" href=\"https://fonts.gstatic.com\" crossorigin>";
    echo "<link href=\"https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap\" rel=\"stylesheet\">";
    echo "<link href=\"https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css\" rel=\"stylesheet\">";
    echo "<style>
        :root{--accent:{$accent};}
        body{font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#fff;color:#111;}
        .app-shell{max-width:1180px;}
        .navbar{border-bottom:1px solid #eaeaea;}
        .nav-tabs .nav-link{color:#111;}
        .nav-tabs .nav-link.active{border-color:#111 #111 #fff;}
        .btn-primary{background:var(--accent);border-color:var(--accent);}
        .btn-primary:hover{filter:brightness(0.95);background:var(--accent);border-color:var(--accent);}
        .badge-accent{background:var(--accent);}
        .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,\"Liberation Mono\",\"Courier New\",monospace;}
        .table thead th{border-bottom:1px solid #111 !important;}
        .card{border:1px solid #eaeaea;}
        .muted{color:#666;}
        .imputed{border-bottom:2px dotted var(--accent);}
        .kbd{font-family:ui-monospace,monospace;padding:2px 6px;border:1px solid #ddd;border-radius:6px;background:#fafafa;}
    </style></head><body>";

    echo "<nav class=\"navbar navbar-expand-lg bg-white\"><div class=\"container-fluid app-shell\">";
    echo "<a class=\"navbar-brand fw-bold\" href=\"?action=dashboard\">" . h($app) . "</a>";
    echo "<div class=\"ms-auto d-flex align-items-center gap-2\">";
    if ($isLoggedIn) {
        echo "<span class=\"small muted\">" . h((string)($user['email'] ?? '')) . "</span>";
        echo "<span class=\"badge text-bg-light border\">" . h((string)($user['role'] ?? '')) . "</span>";
        echo "<a class=\"btn btn-sm btn-outline-dark\" href=\"?action=logout\">Logout</a>";
    } else {
        echo "<a class=\"btn btn-sm btn-outline-dark\" href=\"?action=login\">Login</a>";
    }
    echo "</div></div></nav>";

    echo "<main class=\"container-fluid app-shell py-3\">";
    if ($isLoggedIn) {
        echo "<ul class=\"nav nav-tabs\">";
        foreach ($tabs as $k=>$label) {
            $active = ($action === $k) ? 'active' : '';
            echo "<li class=\"nav-item\"><a class=\"nav-link {$active}\" href=\"?action={$k}\">" . h($label) . "</a></li>";
        }
        echo "</ul>";
    }

    $flashes = take_flashes();
    echo "<div class=\"toast-container position-fixed bottom-0 end-0 p-3\" style=\"z-index:1080\">";
    foreach ($flashes as $f) {
        $t = h((string)($f['type'] ?? 'info'));
        $m = h((string)($f['message'] ?? ''));
        $bg = match ($t) { 'success'=>'text-bg-success','danger'=>'text-bg-danger','warning'=>'text-bg-warning', default=>'text-bg-dark' };
        echo "<div class=\"toast align-items-center {$bg} border-0 mb-2\" role=\"alert\" aria-live=\"assertive\" aria-atomic=\"true\">";
        echo "<div class=\"d-flex\"><div class=\"toast-body\">{$m}</div><button type=\"button\" class=\"btn-close btn-close-white me-2 m-auto\" data-bs-dismiss=\"toast\" aria-label=\"Close\"></button></div></div>";
    }
    echo "</div>";
}

function page_footer(): void {
    $repo = (string)($GLOBALS['CONFIG']['repoUrl'] ?? 'https://github.com/tanzir71/litehcp_targeter');
    $setup = $repo . '/blob/main/SETUP.md';
    $sec = $repo . '/blob/main/SECURITY.md';
    echo "<footer class=\"pt-4 pb-3 border-top mt-4\"><div class=\"d-flex flex-wrap justify-content-between align-items-center\">";
    echo "<div class=\"small muted\">Docs: <a class=\"link-dark\" href=\"" . h($setup) . "\" target=\"_blank\" rel=\"noopener\">SETUP</a> · <a class=\"link-dark\" href=\"" . h($sec) . "\" target=\"_blank\" rel=\"noopener\">SECURITY</a></div>";
    echo "<div class=\"small muted\"><a class=\"link-dark\" href=\"" . h($repo) . "\" target=\"_blank\" rel=\"noopener\">GitHub</a></div>";
    echo "</div></footer>";
    echo "</main>";
    echo "<script src=\"https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js\"></script>";
    $nonce = (string)($GLOBALS['CSP_NONCE'] ?? '');
    echo "<script nonce=\"" . h($nonce) . "\">document.querySelectorAll('.toast').forEach(t=>{try{new bootstrap.Toast(t,{delay:4500}).show()}catch(e){}})</script>";
    echo "</body></html>";
}

/*
====================================================================================================
Routing
====================================================================================================
*/

$pdo = db($CONFIG);
ensure_schema($pdo);
$settings = get_settings($pdo);

if (php_sapi_name() === 'cli') {
    $argv = $_SERVER['argv'] ?? [];
    $args = [];
    foreach ($argv as $a) if (strpos($a, '=') !== false) { [$k,$v] = explode('=', $a, 2); $args[$k] = $v; }
    $action = $args['action'] ?? 'help';
    if ($action === 'cron_recompute') {
        $token = $args['token'] ?? '';
        if ($token !== (string)($CONFIG['cronToken'] ?? '')) { fwrite(STDERR, "Invalid token\n"); exit(1); }
        cron_recompute_all($pdo, $CONFIG);
        fwrite(STDOUT, "OK\n");
        exit(0);
    }
    fwrite(STDOUT, "Usage: php litehcp.php action=cron_recompute token=...\n");
    exit(0);
}

$action = $_GET['action'] ?? 'dashboard';
if ($_SERVER['REQUEST_METHOD'] === 'POST') require_csrf();

if ($action === 'login') { handle_login($pdo); exit; }
if ($action === 'register') { handle_register($pdo, $CONFIG, $settings); exit; }
if ($action === 'logout') { handle_logout($pdo); exit; }

if ($action === 'cron_recompute') {
    $token = (string)($_GET['token'] ?? '');
    if (!hash_equals((string)($CONFIG['cronToken'] ?? ''), $token)) { http_response_code(403); echo 'Forbidden'; exit; }
    cron_recompute_all($pdo, $CONFIG);
    echo 'OK';
    exit;
}

require_login();

switch ($action) {
    case 'dashboard': page_dashboard($pdo, $CONFIG, $settings); break;
    case 'import': page_import($pdo, $CONFIG, $settings); break;
    case 'import_map': page_import_map($pdo, $CONFIG, $settings); break;
    case 'import_run': page_import_run($pdo, $CONFIG, $settings); break;
    case 'profiles': page_profiles($pdo, $CONFIG, $settings); break;
    case 'profile_view': page_profile_view($pdo, $CONFIG, $settings); break;
    case 'rules': page_rules($pdo, $CONFIG, $settings); break;
    case 'rule_edit': page_rule_edit($pdo, $CONFIG, $settings); break;
    case 'rule_test': page_rule_test($pdo, $CONFIG, $settings); break;
    case 'segments': page_segments($pdo, $CONFIG, $settings); break;
    case 'segment_run': page_segment_run($pdo, $CONFIG, $settings); break;
    case 'simulator': page_simulator($pdo, $CONFIG, $settings); break;
    case 'export_segment': handle_export_segment($pdo, $CONFIG, $settings); break;
    case 'settings': page_settings($pdo, $CONFIG, $settings); break;
    case 'load_sample': handle_load_sample($pdo, $CONFIG, $settings); break;
    case 're_enrich': handle_re_enrich($pdo, $CONFIG); break;
    default: page_dashboard($pdo, $CONFIG, $settings); break;
}

/*
====================================================================================================
Implementation below
====================================================================================================
*/


/*
====================================================================================================
Scoring + Rules Engine
====================================================================================================
*/

function compute_base_priority(array $profile, array $CONFIG): float {
    $w = $CONFIG['priorityWeights'];
    $consentEmail = (int)($profile['consent_email'] ?? 0);
    $consentWeb = (int)($profile['consent_web'] ?? 0);
    $consentScore = (($consentEmail ? 1 : 0) + ($consentWeb ? 1 : 0)) / 2.0;

    $recencyScore = 0.0;
    $ts = to_int_or_null($profile['last_activity_ts'] ?? null);
    if ($ts !== null && $ts > 0) {
        $days = max(0.0, (time() - $ts) / 86400.0);
        $window = max(1.0, (float)$CONFIG['recencyWindowDays']);
        $recencyScore = max(0.0, min(1.0, 1.0 - ($days / $window)));
    }

    $imports = (int)($profile['imports_count'] ?? 0);
    $engagementScore = max(0.0, min(1.0, $imports / 10.0));

    $score01 = ($w['consent'] * $consentScore) + ($w['recency'] * $recencyScore) + ($w['engagement'] * $engagementScore);
    return round($score01 * 100.0, 2);
}

function get_field_value(array $profile, string $field) {
    if ($field === 'tags') {
        $m = json_decode_safe($profile['metadata'] ?? '{}');
        return $m['tags'] ?? [];
    }
    return $profile[$field] ?? null;
}

function op_match($left, string $op, $right): bool {
    $op = strtolower(trim($op));

    if ($op === 'in') {
        $arr = [];
        if (is_array($right)) $arr = $right;
        if (is_string($right)) $arr = array_map('trim', explode(',', $right));
        return in_array((string)$left, array_map('strval', $arr), true);
    }

    if ($op === 'contains') {
        if (is_array($left)) {
            return in_array((string)$right, array_map('strval', $left), true);
        }
        return stripos((string)$left, (string)$right) !== false;
    }

    if ($op === 'regex') {
        $pattern = (string)$right;
        if ($pattern === '') return false;
        return @preg_match($pattern, (string)$left) === 1;
    }

    $ln = is_numeric($left) ? (float)$left : null;
    $rn = is_numeric($right) ? (float)$right : null;
    $l = ($ln !== null && $rn !== null) ? $ln : (string)$left;
    $r = ($ln !== null && $rn !== null) ? $rn : (string)$right;

    return match ($op) {
        '=' => $l == $r,
        '!=' => $l != $r,
        '>' => $l > $r,
        '<' => $l < $r,
        '>=' => $l >= $r,
        '<=' => $l <= $r,
        default => false,
    };
}

function eval_conditions(array $profile, $node): bool {
    if (!is_array($node)) return false;

    if (isset($node['all']) && is_array($node['all'])) {
        foreach ($node['all'] as $child) {
            if (!eval_conditions($profile, $child)) return false;
        }
        return true;
    }
    if (isset($node['any']) && is_array($node['any'])) {
        foreach ($node['any'] as $child) {
            if (eval_conditions($profile, $child)) return true;
        }
        return false;
    }

    if (isset($node['conditions']) && is_array($node['conditions'])) {
        $match = strtoupper((string)($node['match'] ?? 'AND'));
        if ($match !== 'OR') $match = 'AND';
        if ($match === 'AND') {
            foreach ($node['conditions'] as $c) if (!eval_conditions($profile, $c)) return false;
            return true;
        }
        foreach ($node['conditions'] as $c) if (eval_conditions($profile, $c)) return true;
        return false;
    }

    $field = (string)($node['field'] ?? '');
    $op = (string)($node['op'] ?? '');
    $value = $node['value'] ?? null;
    if ($field === '' || $op === '') return false;
    $left = get_field_value($profile, $field);
    return op_match($left, $op, $value);
}

function apply_actions(array $profile, array $actions, int $ruleId, string $ruleName): array {
    $m = json_decode_safe($profile['metadata'] ?? '{}');
    $m['tags'] = $m['tags'] ?? [];
    $m['applied_rules'] = $m['applied_rules'] ?? [];
    $m['applied_rule_ids_csv'] = $m['applied_rule_ids_csv'] ?? ',';

    if (array_key_exists('set_persona', $actions)) {
        $profile['persona'] = (string)$actions['set_persona'];
    }
    if (array_key_exists('set_priority_score', $actions)) {
        $profile['priority_score'] = (float)$actions['set_priority_score'];
    }
    if (array_key_exists('add_priority_delta', $actions)) {
        $profile['priority_score'] = (float)($profile['priority_score'] ?? 0) + (float)$actions['add_priority_delta'];
    }
    if (array_key_exists('set_compliance_flag', $actions)) {
        $profile['compliance_flag'] = parse_boolish($actions['set_compliance_flag']);
    }
    if (isset($actions['add_tags']) && is_array($actions['add_tags'])) {
        foreach ($actions['add_tags'] as $t) {
            $t = trim((string)$t);
            if ($t !== '' && !in_array($t, $m['tags'], true)) $m['tags'][] = $t;
        }
    }

    $m['applied_rules'][] = ['rule_id' => $ruleId, 'rule_name' => $ruleName, 'ts' => time()];
    if (strpos((string)$m['applied_rule_ids_csv'], ',' . $ruleId . ',') === false) {
        $m['applied_rule_ids_csv'] .= $ruleId . ',';
    }
    $profile['metadata'] = json_encode_safe($m);
    return $profile;
}

function external_enrichment_hook(array $profile, array $CONFIG): array {
    if (!($CONFIG['hooks']['enableExternalEnrichment'] ?? false)) return $profile;
    return $profile;
}

function enrich_profile(array $profile, array $rules, array $CONFIG): array {
    foreach ($rules as $r) {
        $cond = json_decode_safe($r['conditions_json'] ?? '{}');
        $actions = json_decode_safe($r['actions_json'] ?? '{}');
        if (eval_conditions($profile, $cond)) {
            $profile = apply_actions($profile, $actions, (int)$r['id'], (string)$r['name']);
            $continue = (bool)($cond['continue_on_match'] ?? false);
            if (!$continue) break;
        }
    }
    $profile = external_enrichment_hook($profile, $CONFIG);
    $profile['priority_score'] = max(0.0, min(100.0, (float)($profile['priority_score'] ?? 0)));
    return $profile;
}

function compute_confidence(array $profile, array $imputedFields, array $CONFIG, float $mappingConfidence01): int {
    $required = ['hcp_id', 'name'];
    $optional = ['specialty', 'region', 'organization', 'role', 'last_activity_ts', 'imports_count'];
    $score = 100;
    foreach ($required as $f) {
        $v = $profile[$f] ?? null;
        if ($v === null || (is_string($v) && trim($v) === '')) $score -= (int)$CONFIG['confidenceMissingPenalty'];
    }
    foreach ($optional as $f) {
        $v = $profile[$f] ?? null;
        if ($v === null || (is_string($v) && trim($v) === '')) $score -= (int)$CONFIG['confidenceOptionalMissingPenalty'];
    }
    foreach ($imputedFields as $f => $_) $score -= (int)$CONFIG['confidenceImputedPenalty'];
    $score = (int)round($score * max(0.1, min(1.0, $mappingConfidence01)));
    return max(0, min(100, $score));
}

/*
====================================================================================================
CSV import utilities
====================================================================================================
*/

function csv_open(string $path) {
    $fh = @fopen($path, 'rb');
    return $fh ?: null;
}

function csv_read_headers($fh): array {
    $row = fgetcsv($fh);
    if (!is_array($row)) return [];
    return array_map(fn($v) => trim((string)$v), $row);
}

function csv_preview_rows(string $path, int $maxRows): array {
    $fh = csv_open($path);
    if (!$fh) return [[], []];
    $headers = csv_read_headers($fh);
    $rows = [];
    $i = 0;
    while (($r = fgetcsv($fh)) !== false) {
        if (!is_array($r)) continue;
        $rows[] = $r;
        $i++;
        if ($i >= $maxRows) break;
    }
    fclose($fh);
    return [$headers, $rows];
}

function compute_csv_stats_for_imputation(string $path, array $mapping, array $imputePlan): array {
    $needMode = [];
    $needMean = [];
    foreach ($imputePlan as $field => $plan) {
        $strategy = (string)($plan['strategy'] ?? 'fixed');
        $col = $mapping[$field] ?? '';
        if ($col === '') continue;
        if ($strategy === 'mode') $needMode[$field] = $col;
        if ($strategy === 'mean') $needMean[$field] = $col;
    }

    $fh = csv_open($path);
    if (!$fh) return ['rows_total' => 0, 'mode' => [], 'mean' => []];
    $headers = csv_read_headers($fh);
    $idx = [];
    foreach ($headers as $i => $h) $idx[$h] = $i;

    $modeCounts = [];
    $meanSums = [];
    $meanCounts = [];
    foreach ($needMode as $field => $_) $modeCounts[$field] = [];
    foreach ($needMean as $field => $_) { $meanSums[$field] = 0.0; $meanCounts[$field] = 0; }

    $rows = 0;
    while (($r = fgetcsv($fh)) !== false) {
        if (!is_array($r)) continue;
        $rows++;
        foreach ($needMode as $field => $col) {
            $i = $idx[$col] ?? null;
            if ($i === null) continue;
            $v = trim((string)($r[$i] ?? ''));
            if ($v === '') continue;
            $modeCounts[$field][$v] = ($modeCounts[$field][$v] ?? 0) + 1;
        }
        foreach ($needMean as $field => $col) {
            $i = $idx[$col] ?? null;
            if ($i === null) continue;
            $v = trim((string)($r[$i] ?? ''));
            if ($v === '' || !is_numeric($v)) continue;
            $meanSums[$field] += (float)$v;
            $meanCounts[$field] += 1;
        }
    }
    fclose($fh);

    $mode = [];
    foreach ($modeCounts as $field => $counts) {
        arsort($counts);
        $mode[$field] = key($counts);
    }
    $mean = [];
    foreach ($meanSums as $field => $sum) {
        $c = $meanCounts[$field] ?? 0;
        $mean[$field] = $c > 0 ? ($sum / $c) : null;
    }

    return ['rows_total' => $rows, 'mode' => $mode, 'mean' => $mean];
}

function apply_imputation(string $field, $rawValue, array $plan, array $stats, array &$imputedFlags): mixed {
    $raw = is_string($rawValue) ? trim($rawValue) : $rawValue;
    $empty = ($raw === null) || (is_string($raw) && $raw === '');
    if (!$empty) return $rawValue;

    $strategy = (string)($plan['strategy'] ?? 'fixed');
    if ($strategy === 'leave_null_flag') {
        $imputedFlags[$field] = true;
        return null;
    }
    if ($strategy === 'mode') {
        $imputedFlags[$field] = true;
        return $stats['mode'][$field] ?? ($plan['value'] ?? null);
    }
    if ($strategy === 'mean') {
        $imputedFlags[$field] = true;
        return $stats['mean'][$field] ?? ($plan['value'] ?? null);
    }
    $imputedFlags[$field] = true;
    return $plan['value'] ?? null;
}

/*
====================================================================================================
Auth + Core pages
====================================================================================================
*/

function handle_login(PDO $pdo): void {
    $user = current_user();
    if ($user) redirect('?action=dashboard');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $pass = (string)($_POST['password'] ?? '');
        rate_limit_assert($pdo, $GLOBALS['CONFIG'], 'login', '');
        rate_limit_assert($pdo, $GLOBALS['CONFIG'], 'login', $email);
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = :e');
        $stmt->execute([':e' => $email]);
        $u = $stmt->fetch();
        if ($u && password_verify($pass, (string)$u['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user'] = ['id' => (int)$u['id'], 'email' => (string)$u['email'], 'role' => (string)$u['role']];
            rate_limit_clear($pdo, 'login', '');
            rate_limit_clear($pdo, 'login', $email);
            audit($pdo, (int)$u['id'], 'auth_login', ['email' => $email]);
            flash('success', 'Logged in.');
            redirect('?action=dashboard');
        }
        rate_limit_fail($pdo, $GLOBALS['CONFIG'], 'login', '');
        rate_limit_fail($pdo, $GLOBALS['CONFIG'], 'login', $email);
        audit($pdo, null, 'auth_login_failed', ['email' => $email]);
        flash('danger', 'Invalid email or password.');
        redirect('?action=login');
    }

    page_header('Login', $GLOBALS['CONFIG'], get_settings($pdo), null);
    echo "<div class=\"row justify-content-center\"><div class=\"col-md-6 col-lg-5\">";
    echo "<div class=\"card p-4 mt-3\"><h1 class=\"h4 mb-3\">Login</h1>";
    echo "<form method=\"post\">" . csrf_field();
    echo "<div class=\"mb-3\"><label class=\"form-label\">Email</label><input class=\"form-control\" type=\"email\" name=\"email\" required></div>";
    echo "<div class=\"mb-3\"><label class=\"form-label\">Password</label><input class=\"form-control\" type=\"password\" name=\"password\" required></div>";
    echo "<button class=\"btn btn-primary w-100\" type=\"submit\">Login</button>";
    $count = (int)($pdo->query('SELECT COUNT(*) c FROM users')->fetch()['c'] ?? 0);
    if ($count === 0) {
        echo "<div class=\"mt-3 small\">No users yet. <a href=\"?action=register\">Register the first admin</a>.</div>";
    } else {
        echo "<div class=\"mt-3 small muted\">If enabled by admin, you can <a href=\"?action=register\">register here</a>.</div>";
    }
    echo "</form></div></div></div>";
    page_footer();
}

function handle_register(PDO $pdo, array $CONFIG, array $settings): void {
    $count = (int)($pdo->query('SELECT COUNT(*) c FROM users')->fetch()['c'] ?? 0);
    $openReg = (bool)($settings['open_registration'] ?? false);
    if ($count > 0 && !$openReg && !($CONFIG['allowOpenRegistrationAfterAdmin'] ?? false)) {
        page_header('Register', $CONFIG, $settings, null);
        echo "<div class=\"row justify-content-center\"><div class=\"col-md-6 col-lg-5\">";
        echo "<div class=\"card p-4 mt-3\"><h1 class=\"h4 mb-2\">Registration closed</h1>";
        echo "<p class=\"muted mb-0\">An admin can enable registration in Settings.</p>";
        echo "<div class=\"mt-3\"><a class=\"btn btn-outline-dark\" href=\"?action=login\">Back to login</a></div>";
        echo "</div></div></div>";
        page_footer();
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $pass = (string)($_POST['password'] ?? '');
        rate_limit_assert($pdo, $GLOBALS['CONFIG'], 'register', '');
        rate_limit_assert($pdo, $GLOBALS['CONFIG'], 'register', $email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            rate_limit_fail($pdo, $GLOBALS['CONFIG'], 'register', '');
            rate_limit_fail($pdo, $GLOBALS['CONFIG'], 'register', $email);
            flash('danger', 'Enter a valid email.');
            redirect('?action=register');
        }
        if (strlen($pass) < 10) {
            rate_limit_fail($pdo, $GLOBALS['CONFIG'], 'register', '');
            rate_limit_fail($pdo, $GLOBALS['CONFIG'], 'register', $email);
            flash('danger', 'Use a password of at least 10 characters.');
            redirect('?action=register');
        }
        $role = ($count === 0) ? 'admin' : 'user';
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        try {
            $pdo->prepare('INSERT INTO users (email, password_hash, role, created_at) VALUES (:e,:p,:r,:t)')
                ->execute([':e' => $email, ':p' => $hash, ':r' => $role, ':t' => time()]);
            $uid = (int)$pdo->lastInsertId();
            session_regenerate_id(true);
            $_SESSION['user'] = ['id' => $uid, 'email' => $email, 'role' => $role];
            rate_limit_clear($pdo, 'register', '');
            rate_limit_clear($pdo, 'register', $email);
            audit($pdo, $uid, 'auth_register', ['email' => $email, 'role' => $role]);
            flash('success', $role === 'admin' ? 'Admin account created.' : 'Account created.');
            redirect('?action=dashboard');
        } catch (Throwable $e) {
            rate_limit_fail($pdo, $GLOBALS['CONFIG'], 'register', '');
            rate_limit_fail($pdo, $GLOBALS['CONFIG'], 'register', $email);
            flash('danger', 'Registration failed (email may already exist).');
            redirect('?action=register');
        }
    }

    page_header('Register', $CONFIG, $settings, null);
    echo "<div class=\"row justify-content-center\"><div class=\"col-md-6 col-lg-5\">";
    echo "<div class=\"card p-4 mt-3\"><h1 class=\"h4 mb-3\">Register</h1>";
    if ($count === 0) {
        echo "<div class=\"alert alert-dark\">First registered user becomes <b>admin</b>.</div>";
    }
    echo "<form method=\"post\">" . csrf_field();
    echo "<div class=\"mb-3\"><label class=\"form-label\">Email</label><input class=\"form-control\" type=\"email\" name=\"email\" required></div>";
    echo "<div class=\"mb-3\"><label class=\"form-label\">Password</label><input class=\"form-control\" type=\"password\" name=\"password\" required></div>";
    echo "<button class=\"btn btn-primary w-100\" type=\"submit\">Create account</button>";
    echo "<div class=\"mt-3 small\"><a href=\"?action=login\">Back to login</a></div>";
    echo "</form></div></div></div>";
    page_footer();
}

function handle_logout(PDO $pdo): void {
    $u = current_user();
    if ($u) audit($pdo, (int)$u['id'], 'auth_logout', []);
    $_SESSION = [];
    if (session_id() !== '') session_destroy();
    session_start();
    flash('success', 'Logged out.');
    redirect('?action=login');
}

function page_dashboard(PDO $pdo, array $CONFIG, array $settings): void {
    $u = current_user();
    page_header('Dashboard', $CONFIG, $settings, $u);
    $counts = [
        'profiles' => (int)($pdo->query('SELECT COUNT(*) c FROM profiles')->fetch()['c'] ?? 0),
        'rules' => (int)($pdo->query('SELECT COUNT(*) c FROM rules')->fetch()['c'] ?? 0),
        'segments' => (int)($pdo->query('SELECT COUNT(*) c FROM segments')->fetch()['c'] ?? 0),
        'imports' => (int)($pdo->query('SELECT COUNT(*) c FROM imports')->fetch()['c'] ?? 0),
    ];
    $recent = $pdo->query('SELECT action, ts FROM audit_log ORDER BY ts DESC LIMIT 10')->fetchAll();

    echo "<div class=\"d-flex justify-content-between align-items-start mt-3\">";
    echo "<div><h1 class=\"h4 mb-1\">Dashboard</h1><div class=\"muted\">Import → normalize/impute → rules enrichment → segment → simulate → export.</div></div>";
    echo "<div class=\"d-flex gap-2\">";
    if (is_admin()) {
        echo "<form method=\"post\" action=\"?action=load_sample\" class=\"m-0\" onsubmit=\"return confirm('Load embedded sample CSV + rules + segment?')\">" . csrf_field() . "<button class=\"btn btn-primary\" type=\"submit\">Load sample</button></form>";
    }
    echo "<a class=\"btn btn-outline-dark\" href=\"?action=import\">Import CSV</a>";
    echo "</div></div>";

    echo "<div class=\"row g-3 mt-1\">";
    foreach ($counts as $k => $v) {
        echo "<div class=\"col-6 col-md-3\"><div class=\"card p-3\">";
        echo "<div class=\"muted small\">" . h(ucfirst($k)) . "</div><div class=\"display-6\" style=\"line-height:1\">" . h((string)$v) . "</div>";
        echo "</div></div>";
    }
    echo "</div>";

    $pii = (bool)($settings['export_pii'] ?? false);
    echo "<div class=\"row g-3 mt-2\">";
    echo "<div class=\"col-lg-7\"><div class=\"card p-3\">";
    echo "<div class=\"d-flex justify-content-between\"><div><div class=\"fw-semibold\">Privacy-first export</div><div class=\"muted small\">Exports exclude email unless admin enables export_pii (audited).</div></div>";
    echo "<div><span class=\"badge " . ($pii ? 'text-bg-warning' : 'text-bg-dark') . "\">export_pii: " . ($pii ? 'ON' : 'OFF') . "</span></div></div>";
    echo "<hr><div class=\"small\">Cron recompute endpoint: <span class=\"kbd\">/litehcp.php?action=cron_recompute&amp;token=…</span></div>";
    echo "</div></div>";

    echo "<div class=\"col-lg-5\"><div class=\"card p-3\">";
    echo "<div class=\"fw-semibold mb-2\">Recent activity</div>";
    if (!$recent) {
        echo "<div class=\"muted small\">No audit events yet.</div>";
    } else {
        echo "<div class=\"table-responsive\"><table class=\"table table-sm align-middle mb-0\"><thead><tr><th>Action</th><th class=\"text-end\">When</th></tr></thead><tbody>";
        foreach ($recent as $r) {
            echo "<tr><td class=\"mono\">" . h((string)$r['action']) . "</td><td class=\"text-end small muted\">" . h(date('Y-m-d H:i', (int)$r['ts'])) . "</td></tr>";
        }
        echo "</tbody></table></div>";
    }
    echo "</div></div></div>";
    page_footer();
}

function page_import(PDO $pdo, array $CONFIG, array $settings): void {
    $u = current_user();
    page_header('Import', $CONFIG, $settings, $u);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_FILES['csv']) || !is_array($_FILES['csv'])) {
            flash('danger', 'Upload a CSV file.');
            redirect('?action=import');
        }
        if ((int)($_FILES['csv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            flash('danger', 'Upload failed.');
            redirect('?action=import');
        }
        $tmp = (string)($_FILES['csv']['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            flash('danger', 'Upload failed.');
            redirect('?action=import');
        }
        $size = (int)($_FILES['csv']['size'] ?? 0);
        if ($size <= 0 || $size > (int)$CONFIG['uploadMaxBytes']) {
            flash('danger', 'File too large.');
            redirect('?action=import');
        }
        $name = sanitize_filename((string)($_FILES['csv']['name'] ?? 'upload.csv'), 'upload.csv');
        $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, (array)$CONFIG['uploadAllowedExtensions'], true)) {
            flash('danger', 'Invalid file type.');
            redirect('?action=import');
        }
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) {
                $mime = finfo_file($fi, $tmp) ?: '';
                finfo_close($fi);
                if ($mime !== '' && !in_array($mime, (array)$CONFIG['uploadAllowedMimeTypes'], true)) {
                    flash('danger', 'Invalid file type.');
                    redirect('?action=import');
                }
            }
        }
        $dir = ensure_upload_dir();
        $pdo->prepare('INSERT INTO imports (filename, rows_total, rows_imported, errors_json, created_at) VALUES (:f,0,0,:e,:t)')
            ->execute([':f' => $name, ':e' => json_encode_safe([]), ':t' => time()]);
        $importId = (int)$pdo->lastInsertId();
        $dest = $dir . DIRECTORY_SEPARATOR . 'import_' . $importId . '.csv';
        if (!@move_uploaded_file($tmp, $dest)) {
            flash('danger', 'Could not store uploaded file. Check permissions.');
            redirect('?action=import');
        }
        audit($pdo, (int)$u['id'], 'import_uploaded', ['import_id' => $importId, 'filename' => $name]);
        redirect('?action=import_map&id=' . $importId);
    }

    echo "<div class=\"d-flex justify-content-between align-items-start mt-3\"><div><h1 class=\"h4 mb-1\">CSV Import</h1><div class=\"muted\">Upload, map columns, pick imputation strategies, then import in chunks.</div></div></div>";
    echo "<div class=\"row g-3 mt-1\">";
    echo "<div class=\"col-lg-7\"><div class=\"card p-3\">";
    echo "<form method=\"post\" enctype=\"multipart/form-data\">" . csrf_field();
    echo "<div class=\"mb-3\"><label class=\"form-label\">CSV file</label><input class=\"form-control\" type=\"file\" name=\"csv\" accept=\".csv,text/csv\" required></div>";
    echo "<button class=\"btn btn-primary\" type=\"submit\">Upload &amp; map</button></form></div></div>";
    $sc = sample_csv();
    echo "<div class=\"col-lg-5\"><div class=\"card p-3\"><div class=\"fw-semibold\">Embedded sample CSV</div><div class=\"small muted\">Dashboard → Load sample (admin) imports sample CSV + rules + a segment.</div><hr><pre class=\"small mono\" style=\"white-space:pre-wrap\">" . h(substr($sc, 0, 420)) . (strlen($sc) > 420 ? "..." : "") . "</pre></div></div>";
    echo "</div>";

    $imports = $pdo->query('SELECT * FROM imports ORDER BY created_at DESC LIMIT 10')->fetchAll();
    echo "<div class=\"card p-3 mt-3\"><div class=\"fw-semibold mb-2\">Recent imports</div>";
    if (!$imports) {
        echo "<div class=\"muted small\">No imports yet.</div>";
    } else {
        echo "<div class=\"table-responsive\"><table class=\"table table-sm align-middle mb-0\"><thead><tr><th>ID</th><th>File</th><th class=\"text-end\">Imported</th><th class=\"text-end\">Total</th><th></th></tr></thead><tbody>";
        foreach ($imports as $im) {
            $id = (int)$im['id'];
            echo "<tr><td class=\"mono\">" . h((string)$id) . "</td><td>" . h((string)$im['filename']) . "</td><td class=\"text-end\">" . h((string)$im['rows_imported']) . "</td><td class=\"text-end\">" . h((string)$im['rows_total']) . "</td><td class=\"text-end\"><a class=\"btn btn-sm btn-outline-dark\" href=\"?action=import_map&id={$id}\">Open</a></td></tr>";
        }
        echo "</tbody></table></div>";
    }
    echo "</div>";
    page_footer();
}

function page_import_map(PDO $pdo, array $CONFIG, array $settings): void {
    $u = current_user();
    $id = (int)($_GET['id'] ?? 0);
    $imp = $pdo->prepare('SELECT * FROM imports WHERE id = :id');
    $imp->execute([':id' => $id]);
    $import = $imp->fetch();
    if (!$import) { flash('danger', 'Import not found.'); redirect('?action=import'); }

    $path = ensure_upload_dir() . DIRECTORY_SEPARATOR . 'import_' . $id . '.csv';
    if (!file_exists($path)) { flash('danger', 'Uploaded file missing on disk.'); redirect('?action=import'); }

    $internalFields = [
        'hcp_id' => ['label' => 'hcp_id', 'required' => true],
        'name' => ['label' => 'name', 'required' => true],
        'email' => ['label' => 'email (optional)', 'required' => false],
        'specialty' => ['label' => 'specialty', 'required' => false],
        'region' => ['label' => 'region', 'required' => false],
        'organization' => ['label' => 'organization', 'required' => false],
        'role' => ['label' => 'role', 'required' => false],
        'consent_email' => ['label' => 'consent_email (0/1)', 'required' => false],
        'consent_web' => ['label' => 'consent_web (0/1)', 'required' => false],
        'last_activity_ts' => ['label' => 'last_activity_ts (unix ts)', 'required' => false],
        'imports_count' => ['label' => 'imports_count', 'required' => false],
    ];
    [$headers, $preview] = csv_preview_rows($path, (int)$CONFIG['importMaxPreviewRows']);
    $errorsObj = json_decode_safe((string)($import['errors_json'] ?? '{}'));
    $savedMapping = is_array($errorsObj['mapping'] ?? null) ? $errorsObj['mapping'] : [];
    $savedImpute = is_array($errorsObj['impute'] ?? null) ? $errorsObj['impute'] : [];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $mapping = [];
        foreach ($internalFields as $key => $_meta) {
            $col = (string)($_POST['map'][$key] ?? '');
            $mapping[$key] = in_array($col, $headers, true) ? $col : '';
        }
        if ($mapping['hcp_id'] === '' || $mapping['name'] === '') {
            flash('danger', 'Mapping requires at least hcp_id and name.');
            redirect('?action=import_map&id=' . $id);
        }

        $strategies = ['fixed', 'mode', 'mean', 'leave_null_flag'];
        $impute = [];
        foreach ($internalFields as $key => $_meta) {
            $strategy = (string)($_POST['impute'][$key]['strategy'] ?? ($CONFIG['defaultImputation'][$key]['strategy'] ?? 'fixed'));
            if (!in_array($strategy, $strategies, true)) $strategy = 'fixed';
            $val = $_POST['impute'][$key]['value'] ?? ($CONFIG['defaultImputation'][$key]['value'] ?? null);
            $impute[$key] = ['strategy' => $strategy, 'value' => $val];
        }

        $mappedCount = 0;
        foreach ($mapping as $v) if ($v !== '') $mappedCount++;
        $mappingConfidence01 = $mappedCount / max(1, count($internalFields));

        $stats = compute_csv_stats_for_imputation($path, $mapping, $impute);
        $errorsObj['mapping'] = $mapping;
        $errorsObj['impute'] = $impute;
        $errorsObj['stats'] = $stats;
        $errorsObj['mapping_confidence01'] = $mappingConfidence01;
        $errorsObj['notes'] = ['Mapping + imputation plan + computed stats are stored for resumable imports'];

        $pdo->prepare('UPDATE imports SET errors_json = :e, rows_total = :rt WHERE id = :id')
            ->execute([':e' => json_encode_safe($errorsObj), ':rt' => (int)($stats['rows_total'] ?? 0), ':id' => $id]);
        audit($pdo, (int)$u['id'], 'import_mapping_saved', ['import_id' => $id, 'mapping_confidence01' => $mappingConfidence01]);
        redirect('?action=import_run&id=' . $id);
    }

    page_header('Import mapping', $CONFIG, $settings, $u);
    echo "<div class=\"d-flex justify-content-between align-items-start mt-3\">";
    echo "<div><h1 class=\"h4 mb-1\">Map columns</h1><div class=\"muted\">Import #" . h((string)$id) . " — " . h((string)$import['filename']) . "</div></div>";
    echo "<div class=\"d-flex gap-2\"><a class=\"btn btn-outline-dark\" href=\"?action=import\">Back</a></div></div>";

    echo "<form method=\"post\" class=\"mt-3\">" . csrf_field();
    echo "<div class=\"row g-3\">";
    echo "<div class=\"col-lg-7\"><div class=\"card p-3\">";
    echo "<div class=\"fw-semibold mb-2\">Field mapping</div>";
    echo "<div class=\"table-responsive\"><table class=\"table table-sm align-middle mb-0\"><thead><tr><th>Internal field</th><th>CSV column</th><th>Imputation</th></tr></thead><tbody>";
    foreach ($internalFields as $key => $meta) {
        $selected = (string)($savedMapping[$key] ?? '');
        $plan = $savedImpute[$key] ?? ($CONFIG['defaultImputation'][$key] ?? ['strategy' => 'fixed', 'value' => '']);
        $strategy = (string)($plan['strategy'] ?? 'fixed');
        $value = $plan['value'] ?? '';
        echo "<tr><td class=\"mono\">" . h($meta['label']) . ($meta['required'] ? " <span class=\"text-danger\">*</span>" : "") . "</td>";
        echo "<td><select class=\"form-select form-select-sm\" name=\"map[" . h($key) . "]\"><option value=\"\">(none)</option>";
        foreach ($headers as $hh) {
            $sel = ($hh === $selected) ? 'selected' : '';
            echo "<option {$sel} value=\"" . h($hh) . "\">" . h($hh) . "</option>";
        }
        echo "</select></td>";
        echo "<td><div class=\"d-flex gap-2\">";
        echo "<select class=\"form-select form-select-sm\" name=\"impute[" . h($key) . "][strategy]\">";
        $opts = ['fixed'=>'Fixed default','mode'=>'Mode','mean'=>'Mean','leave_null_flag'=>'Leave null + flag'];
        foreach ($opts as $ov => $ol) { $sel = ($strategy === $ov) ? 'selected' : ''; echo "<option {$sel} value=\"" . h($ov) . "\">" . h($ol) . "</option>"; }
        echo "</select>";
        echo "<input class=\"form-control form-control-sm\" name=\"impute[" . h($key) . "][value]\" placeholder=\"default\" value=\"" . h(is_scalar($value) ? (string)$value : '') . "\">";
        echo "</div></td></tr>";
    }
    echo "</tbody></table></div>";
    echo "<div class=\"mt-3\"><button class=\"btn btn-primary\" type=\"submit\">Save mapping &amp; compute stats</button></div>";
    echo "<div class=\"small muted mt-2\">Mode/mean strategies do a two-pass streaming scan of the CSV (still memory-safe).</div>";
    echo "</div></div>";

    echo "<div class=\"col-lg-5\"><div class=\"card p-3\"><div class=\"fw-semibold mb-2\">Preview (first " . h((string)$CONFIG['importMaxPreviewRows']) . " rows)</div>";
    if (!$headers) {
        echo "<div class=\"text-danger\">Could not read CSV headers.</div>";
    } else {
        echo "<div class=\"table-responsive\"><table class=\"table table-sm\"><thead><tr>";
        foreach (array_slice($headers, 0, 8) as $hh) echo "<th class=\"small\">" . h($hh) . "</th>";
        echo "</tr></thead><tbody>";
        foreach ($preview as $r) {
            echo "<tr>";
            foreach (array_slice($r, 0, 8) as $cell) echo "<td class=\"small\">" . h((string)$cell) . "</td>";
            echo "</tr>";
        }
        echo "</tbody></table></div>";
    }
    echo "</div></div></div></form>";
    page_footer();
}

function page_import_run(PDO $pdo, array $CONFIG, array $settings): void {
    $u = current_user();
    $id = (int)($_GET['id'] ?? 0);
    $imp = $pdo->prepare('SELECT * FROM imports WHERE id = :id');
    $imp->execute([':id' => $id]);
    $import = $imp->fetch();
    if (!$import) { flash('danger', 'Import not found.'); redirect('?action=import'); }

    $path = ensure_upload_dir() . DIRECTORY_SEPARATOR . 'import_' . $id . '.csv';
    if (!file_exists($path)) { flash('danger', 'Uploaded file missing on disk.'); redirect('?action=import'); }

    $errorsObj = json_decode_safe((string)($import['errors_json'] ?? '{}'));
    $mapping = is_array($errorsObj['mapping'] ?? null) ? $errorsObj['mapping'] : [];
    $impute = is_array($errorsObj['impute'] ?? null) ? $errorsObj['impute'] : [];
    $stats = is_array($errorsObj['stats'] ?? null) ? $errorsObj['stats'] : [];
    $mappingConfidence01 = (float)($errorsObj['mapping_confidence01'] ?? 0.7);
    if (!$mapping || empty($mapping['hcp_id']) || empty($mapping['name'])) { flash('danger', 'Mapping not configured.'); redirect('?action=import_map&id=' . $id); }

    $rules = $pdo->query('SELECT * FROM rules ORDER BY priority DESC, id ASC')->fetchAll();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $mode = (string)($_POST['mode'] ?? 'continue');
        $maxSeconds = 12;
        $start = microtime(true);
        $commitEvery = (int)$CONFIG['importCommitEvery'];

        $fh = csv_open($path);
        if (!$fh) { flash('danger', 'Could not open file for import.'); redirect('?action=import'); }
        $headers = csv_read_headers($fh);
        $idx = [];
        foreach ($headers as $i => $h) $idx[$h] = $i;

        $already = (int)($import['rows_imported'] ?? 0);
        $skipped = 0;
        while ($skipped < $already && ($row = fgetcsv($fh)) !== false) $skipped++;

        $inserted = 0;
        $updated = 0;
        $errors = is_array($errorsObj['import_errors'] ?? null) ? $errorsObj['import_errors'] : [];

        $pdo->beginTransaction();
        $sinceCommit = 0;
        $processedThisRequest = 0;
        try {
            while (($row = fgetcsv($fh)) !== false) {
                if (!is_array($row)) continue;
                $processedThisRequest++;
                $imputedFlags = [];

                $p = [
                    'hcp_id' => '',
                    'name' => '',
                    'email' => null,
                    'specialty' => null,
                    'region' => null,
                    'organization' => null,
                    'role' => null,
                    'consent_email' => 0,
                    'consent_web' => 0,
                    'last_activity_ts' => null,
                    'imports_count' => 0,
                    'persona' => null,
                    'priority_score' => 0,
                    'compliance_flag' => 0,
                    'metadata' => '{}',
                    'confidence_score' => 0,
                    'created_at' => time(),
                ];
                foreach ($mapping as $field => $col) {
                    if ($col === '') continue;
                    $i = $idx[$col] ?? null;
                    if ($i === null) continue;
                    $p[$field] = $row[$i] ?? null;
                }

                $p['consent_email'] = parse_boolish(apply_imputation('consent_email', $p['consent_email'], $impute['consent_email'] ?? ($CONFIG['defaultImputation']['consent_email'] ?? []), $stats, $imputedFlags));
                $p['consent_web'] = parse_boolish(apply_imputation('consent_web', $p['consent_web'], $impute['consent_web'] ?? ($CONFIG['defaultImputation']['consent_web'] ?? []), $stats, $imputedFlags));
                $p['imports_count'] = (int)apply_imputation('imports_count', $p['imports_count'], $impute['imports_count'] ?? ($CONFIG['defaultImputation']['imports_count'] ?? []), $stats, $imputedFlags);
                $p['hcp_id'] = (string)apply_imputation('hcp_id', $p['hcp_id'], $impute['hcp_id'] ?? ['strategy'=>'leave_null_flag','value'=>null], $stats, $imputedFlags);
                $p['name'] = (string)apply_imputation('name', $p['name'], $impute['name'] ?? ['strategy'=>'leave_null_flag','value'=>null], $stats, $imputedFlags);
                $p['email'] = apply_imputation('email', $p['email'], $impute['email'] ?? ['strategy'=>'leave_null_flag','value'=>null], $stats, $imputedFlags);
                $p['specialty'] = apply_imputation('specialty', $p['specialty'], $impute['specialty'] ?? ($CONFIG['defaultImputation']['specialty'] ?? []), $stats, $imputedFlags);
                $p['region'] = apply_imputation('region', $p['region'], $impute['region'] ?? ($CONFIG['defaultImputation']['region'] ?? []), $stats, $imputedFlags);
                $p['organization'] = apply_imputation('organization', $p['organization'], $impute['organization'] ?? ($CONFIG['defaultImputation']['organization'] ?? []), $stats, $imputedFlags);
                $p['role'] = apply_imputation('role', $p['role'], $impute['role'] ?? ($CONFIG['defaultImputation']['role'] ?? []), $stats, $imputedFlags);
                $p['last_activity_ts'] = to_int_or_null(apply_imputation('last_activity_ts', $p['last_activity_ts'], $impute['last_activity_ts'] ?? ($CONFIG['defaultImputation']['last_activity_ts'] ?? []), $stats, $imputedFlags));

                $meta = [
                    'imputed_fields' => $imputedFlags,
                    'imputation_plan' => $impute,
                    'last_import_id' => $id,
                    'tags' => [],
                    'applied_rules' => [],
                    'applied_rule_ids_csv' => ',',
                ];
                $p['metadata'] = json_encode_safe($meta);
                $p['confidence_score'] = compute_confidence($p, $imputedFlags, $CONFIG, $mappingConfidence01);
                $p['priority_score'] = compute_base_priority($p, $CONFIG);
                $p = enrich_profile($p, $rules, $CONFIG);

                if (trim((string)$p['hcp_id']) === '' || trim((string)$p['name']) === '') {
                    $errors[] = ['row' => $already + $processedThisRequest, 'error' => 'Missing required fields (hcp_id/name)'];
                    continue;
                }

                $result = upsert_profile($pdo, $p);
                if ($result === 'insert') $inserted++;
                if ($result === 'update') $updated++;

                $sinceCommit++;
                if ($sinceCommit >= $commitEvery) {
                    $pdo->commit();
                    $pdo->beginTransaction();
                    $sinceCommit = 0;
                }
                if ((microtime(true) - $start) > $maxSeconds) break;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            fclose($fh);
            $errId = bin2hex(random_bytes(6));
            error_line($CONFIG, 'import_failed', ['id' => $errId, 'import_id' => $id, 'message' => $e->getMessage()]);
            $errors[] = ['row' => $already + $processedThisRequest, 'error' => 'Import aborted (error ' . $errId . ')'];
            $errorsObj['import_errors'] = $errors;
            $pdo->prepare('UPDATE imports SET errors_json = :e WHERE id = :id')->execute([':e' => json_encode_safe($errorsObj), ':id' => $id]);
            audit($pdo, (int)$u['id'], 'import_failed', ['import_id' => $id]);
            flash('danger', 'Import failed.');
            redirect('?action=import_run&id=' . $id);
        }
        fclose($fh);

        $delta = $inserted + $updated;
        $pdo->prepare('UPDATE imports SET rows_imported = rows_imported + :n, errors_json = :e WHERE id = :id')
            ->execute([':n' => $processedThisRequest, ':e' => json_encode_safe(array_merge($errorsObj, ['import_errors' => $errors])), ':id' => $id]);
        audit($pdo, (int)$u['id'], 'import_chunk_processed', ['import_id' => $id, 'inserted' => $inserted, 'updated' => $updated, 'processed' => $processedThisRequest, 'seconds' => round(microtime(true) - $start, 2)]);
        flash('success', "Processed {$processedThisRequest} rows (inserted {$inserted}, updated {$updated}).");
        if ($mode === 'run_to_end') redirect('?action=import_run&id=' . $id);
        redirect('?action=import_run&id=' . $id);
    }

    page_header('Import run', $CONFIG, $settings, $u);
    $total = (int)($import['rows_total'] ?? 0);
    $done = (int)($import['rows_imported'] ?? 0);
    $pct = $total > 0 ? (int)round(($done / max(1, $total)) * 100) : 0;

    echo "<div class=\"d-flex justify-content-between align-items-start mt-3\"><div><h1 class=\"h4 mb-1\">Run import</h1><div class=\"muted\">Import #" . h((string)$id) . " — chunked processing (shared-hosting friendly).</div></div>";
    echo "<div class=\"d-flex gap-2\"><a class=\"btn btn-outline-dark\" href=\"?action=import_map&id={$id}\">Edit mapping</a><a class=\"btn btn-outline-dark\" href=\"?action=import\">Back</a></div></div>";

    echo "<div class=\"card p-3 mt-3\">";
    echo "<div class=\"d-flex justify-content-between\"><div class=\"fw-semibold\">Progress</div><div class=\"small muted\">{$done} / {$total}</div></div>";
    echo "<div class=\"progress mt-2\" style=\"height:10px\"><div class=\"progress-bar bg-dark\" role=\"progressbar\" style=\"width: {$pct}%\"></div></div>";
    echo "<form method=\"post\" class=\"mt-3 d-flex gap-2\">" . csrf_field();
    echo "<button class=\"btn btn-primary\" name=\"mode\" value=\"continue\" type=\"submit\">Process next chunk</button>";
    echo "<button class=\"btn btn-outline-dark\" name=\"mode\" value=\"run_to_end\" type=\"submit\">Run until done (auto-reload)</button>";
    echo "</form><div class=\"small muted mt-2\">Tip: for huge CSVs, click “Process next chunk” repeatedly to avoid timeouts.</div></div>";

    $errs = json_decode_safe((string)($import['errors_json'] ?? '{}'));
    $importErrors = is_array($errs['import_errors'] ?? null) ? $errs['import_errors'] : [];
    if ($importErrors) {
        echo "<div class=\"card p-3 mt-3\"><div class=\"fw-semibold mb-2\">Import errors (latest)</div>";
        echo "<div class=\"table-responsive\"><table class=\"table table-sm mb-0\"><thead><tr><th>Row</th><th>Error</th></tr></thead><tbody>";
        foreach (array_slice($importErrors, -20) as $e) {
            echo "<tr><td class=\"mono\">" . h((string)($e['row'] ?? '')) . "</td><td>" . h((string)($e['error'] ?? '')) . "</td></tr>";
        }
        echo "</tbody></table></div></div>";
    }
    page_footer();
}

function upsert_profile(PDO $pdo, array $p): string {
    $exists = $pdo->prepare('SELECT id, imports_count, metadata FROM profiles WHERE hcp_id = :h');
    $exists->execute([':h' => (string)$p['hcp_id']]);
    $row = $exists->fetch();
    if (!$row) {
        $stmt = $pdo->prepare('INSERT INTO profiles
            (hcp_id,name,email,specialty,region,organization,role,consent_email,consent_web,last_activity_ts,imports_count,persona,priority_score,compliance_flag,metadata,confidence_score,created_at)
            VALUES
            (:hcp_id,:name,:email,:specialty,:region,:organization,:role,:consent_email,:consent_web,:last_activity_ts,:imports_count,:persona,:priority_score,:compliance_flag,:metadata,:confidence_score,:created_at)');
        $stmt->execute([
            ':hcp_id' => (string)$p['hcp_id'],
            ':name' => (string)$p['name'],
            ':email' => $p['email'] !== null ? (string)$p['email'] : null,
            ':specialty' => $p['specialty'] !== null ? (string)$p['specialty'] : null,
            ':region' => $p['region'] !== null ? (string)$p['region'] : null,
            ':organization' => $p['organization'] !== null ? (string)$p['organization'] : null,
            ':role' => $p['role'] !== null ? (string)$p['role'] : null,
            ':consent_email' => (int)$p['consent_email'],
            ':consent_web' => (int)$p['consent_web'],
            ':last_activity_ts' => to_int_or_null($p['last_activity_ts'] ?? null),
            ':imports_count' => (int)$p['imports_count'],
            ':persona' => $p['persona'] !== null ? (string)$p['persona'] : null,
            ':priority_score' => (float)$p['priority_score'],
            ':compliance_flag' => (int)$p['compliance_flag'],
            ':metadata' => (string)$p['metadata'],
            ':confidence_score' => (int)$p['confidence_score'],
            ':created_at' => (int)$p['created_at'],
        ]);
        return 'insert';
    }

    $existingCount = (int)($row['imports_count'] ?? 0);
    $existingMeta = json_decode_safe((string)($row['metadata'] ?? '{}'));
    $newMeta = json_decode_safe((string)($p['metadata'] ?? '{}'));
    $merged = $existingMeta;
    $merged['tags'] = array_values(array_unique(array_merge($existingMeta['tags'] ?? [], $newMeta['tags'] ?? [])));
    $merged['applied_rules'] = array_merge($existingMeta['applied_rules'] ?? [], $newMeta['applied_rules'] ?? []);
    $merged['applied_rule_ids_csv'] = (string)($existingMeta['applied_rule_ids_csv'] ?? ',');
    $csv = (string)($newMeta['applied_rule_ids_csv'] ?? ',');
    foreach (explode(',', trim($csv, ',')) as $rid) {
        $rid = trim($rid);
        if ($rid === '') continue;
        if (strpos($merged['applied_rule_ids_csv'], ',' . $rid . ',') === false) $merged['applied_rule_ids_csv'] .= $rid . ',';
    }
    $merged['imputed_fields'] = $newMeta['imputed_fields'] ?? ($existingMeta['imputed_fields'] ?? []);
    $merged['last_import_id'] = $newMeta['last_import_id'] ?? ($existingMeta['last_import_id'] ?? null);

    $stmt = $pdo->prepare('UPDATE profiles SET
        name=:name,
        email=:email,
        specialty=:specialty,
        region=:region,
        organization=:organization,
        role=:role,
        consent_email=:consent_email,
        consent_web=:consent_web,
        last_activity_ts=:last_activity_ts,
        imports_count=:imports_count,
        persona=:persona,
        priority_score=:priority_score,
        compliance_flag=:compliance_flag,
        metadata=:metadata,
        confidence_score=:confidence_score
        WHERE hcp_id=:hcp_id');
    $stmt->execute([
        ':name' => (string)$p['name'],
        ':email' => $p['email'] !== null ? (string)$p['email'] : null,
        ':specialty' => $p['specialty'] !== null ? (string)$p['specialty'] : null,
        ':region' => $p['region'] !== null ? (string)$p['region'] : null,
        ':organization' => $p['organization'] !== null ? (string)$p['organization'] : null,
        ':role' => $p['role'] !== null ? (string)$p['role'] : null,
        ':consent_email' => (int)$p['consent_email'],
        ':consent_web' => (int)$p['consent_web'],
        ':last_activity_ts' => to_int_or_null($p['last_activity_ts'] ?? null),
        ':imports_count' => max($existingCount, (int)$p['imports_count']) + 1,
        ':persona' => $p['persona'] !== null ? (string)$p['persona'] : null,
        ':priority_score' => (float)$p['priority_score'],
        ':compliance_flag' => (int)$p['compliance_flag'],
        ':metadata' => json_encode_safe($merged),
        ':confidence_score' => (int)$p['confidence_score'],
        ':hcp_id' => (string)$p['hcp_id'],
    ]);
    return 'update';
}

function page_profiles(PDO $pdo, array $CONFIG, array $settings): void {
    $u = current_user();
    page_header('Profiles', $CONFIG, $settings, $u);

    $q = trim((string)($_GET['q'] ?? ''));
    $specialty = trim((string)($_GET['specialty'] ?? ''));
    $region = trim((string)($_GET['region'] ?? ''));
    $persona = trim((string)($_GET['persona'] ?? ''));
    $minPriority = trim((string)($_GET['min_priority'] ?? ''));
    $minConfidence = trim((string)($_GET['min_confidence'] ?? ''));
    $sort = (string)($_GET['sort'] ?? 'priority_score');
    $dir = strtoupper((string)($_GET['dir'] ?? 'DESC'));
    if (!in_array($dir, ['ASC','DESC'], true)) $dir = 'DESC';
    $allowedSort = ['priority_score','confidence_score','imports_count','last_activity_ts','created_at','name','hcp_id'];
    if (!in_array($sort, $allowedSort, true)) $sort = 'priority_score';

    $where = [];
    $params = [];
    if ($q !== '') { $where[] = '(hcp_id LIKE :q OR name LIKE :q OR email LIKE :q OR organization LIKE :q)'; $params[':q'] = '%' . $q . '%'; }
    if ($specialty !== '') { $where[] = 'specialty = :s'; $params[':s'] = $specialty; }
    if ($region !== '') { $where[] = 'region = :r'; $params[':r'] = $region; }
    if ($persona !== '') { $where[] = 'persona = :p'; $params[':p'] = $persona; }
    if ($minPriority !== '' && is_numeric($minPriority)) { $where[] = 'priority_score >= :mp'; $params[':mp'] = (float)$minPriority; }
    if ($minConfidence !== '' && is_numeric($minConfidence)) { $where[] = 'confidence_score >= :mc'; $params[':mc'] = (int)$minConfidence; }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stmt = $pdo->prepare("SELECT * FROM profiles {$whereSql} ORDER BY {$sort} {$dir} LIMIT 200");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $specialties = $pdo->query('SELECT specialty, COUNT(*) c FROM profiles WHERE specialty IS NOT NULL AND specialty != "" GROUP BY specialty ORDER BY c DESC LIMIT 50')->fetchAll();
    $regions = $pdo->query('SELECT region, COUNT(*) c FROM profiles WHERE region IS NOT NULL AND region != "" GROUP BY region ORDER BY c DESC LIMIT 50')->fetchAll();
    $personas = $pdo->query('SELECT persona, COUNT(*) c FROM profiles WHERE persona IS NOT NULL AND persona != "" GROUP BY persona ORDER BY c DESC LIMIT 50')->fetchAll();

    echo "<div class=\"d-flex justify-content-between align-items-start mt-3\"><div><h1 class=\"h4 mb-1\">Profiles</h1><div class=\"muted\">List is capped to 200 rows per view (server-side filters).</div></div><div><a class=\"btn btn-outline-dark\" href=\"?action=profiles\">Reset</a></div></div>";

    echo "<form class=\"card p-3 mt-3\" method=\"get\"><input type=\"hidden\" name=\"action\" value=\"profiles\">";
    echo "<div class=\"row g-2\">";
    echo "<div class=\"col-md-4\"><input class=\"form-control\" name=\"q\" value=\"" . h($q) . "\" placeholder=\"Search name/hcp_id/email/org\"></div>";
    echo "<div class=\"col-md-2\"><select class=\"form-select\" name=\"specialty\"><option value=\"\">Specialty</option>";
    foreach ($specialties as $s) { $val = (string)$s['specialty']; $sel = ($val === $specialty) ? 'selected' : ''; echo "<option {$sel} value=\"" . h($val) . "\">" . h($val) . "</option>"; }
    echo "</select></div>";
    echo "<div class=\"col-md-2\"><select class=\"form-select\" name=\"region\"><option value=\"\">Region</option>";
    foreach ($regions as $r) { $val = (string)$r['region']; $sel = ($val === $region) ? 'selected' : ''; echo "<option {$sel} value=\"" . h($val) . "\">" . h($val) . "</option>"; }
    echo "</select></div>";
    echo "<div class=\"col-md-2\"><select class=\"form-select\" name=\"persona\"><option value=\"\">Persona</option>";
    foreach ($personas as $p) { $val = (string)$p['persona']; $sel = ($val === $persona) ? 'selected' : ''; echo "<option {$sel} value=\"" . h($val) . "\">" . h($val) . "</option>"; }
    echo "</select></div>";
    echo "<div class=\"col-md-1\"><input class=\"form-control\" name=\"min_priority\" value=\"" . h($minPriority) . "\" placeholder=\"Min P\"></div>";
    echo "<div class=\"col-md-1\"><input class=\"form-control\" name=\"min_confidence\" value=\"" . h($minConfidence) . "\" placeholder=\"Min C\"></div>";
    echo "</div><div class=\"d-flex gap-2 mt-2\">";
    echo "<select class=\"form-select\" style=\"max-width:220px\" name=\"sort\">";
    foreach ($allowedSort as $s) { $sel = ($s === $sort) ? 'selected' : ''; echo "<option {$sel} value=\"" . h($s) . "\">Sort: " . h($s) . "</option>"; }
    echo "</select>";
    echo "<select class=\"form-select\" style=\"max-width:140px\" name=\"dir\"><option " . ($dir === 'DESC' ? 'selected' : '') . " value=\"DESC\">DESC</option><option " . ($dir === 'ASC' ? 'selected' : '') . " value=\"ASC\">ASC</option></select>";
    echo "<button class=\"btn btn-primary\" type=\"submit\">Apply</button></div></form>";

    echo "<div class=\"card p-3 mt-3\"><div class=\"table-responsive\"><table class=\"table table-sm align-middle mb-0\"><thead><tr><th>HCP</th><th>Name</th><th>Specialty</th><th>Region</th><th>Persona</th><th class=\"text-end\">Priority</th><th class=\"text-end\">Conf.</th><th></th></tr></thead><tbody>";
    foreach ($rows as $r) {
        $cid = (int)$r['id'];
        $c = (int)$r['confidence_score'];
        $badge = $c >= 85 ? 'text-bg-success' : ($c >= 70 ? 'text-bg-dark' : 'text-bg-warning');
        echo "<tr><td class=\"mono\">" . h((string)$r['hcp_id']) . "</td><td>" . h((string)$r['name']) . "</td><td>" . h((string)($r['specialty'] ?? '')) . "</td><td>" . h((string)($r['region'] ?? '')) . "</td><td>" . h((string)($r['persona'] ?? '')) . "</td><td class=\"text-end\">" . h((string)$r['priority_score']) . "</td><td class=\"text-end\"><span class=\"badge {$badge}\">" . h((string)$c) . "</span></td><td class=\"text-end\"><a class=\"btn btn-sm btn-outline-dark\" href=\"?action=profile_view&id={$cid}\">View</a></td></tr>";
    }
    if (!$rows) echo "<tr><td colspan=\"8\" class=\"muted\">No matching profiles.</td></tr>";
    echo "</tbody></table></div></div>";
    page_footer();
}

function page_profile_view(PDO $pdo, array $CONFIG, array $settings): void {
    $u = current_user();
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT * FROM profiles WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $p = $stmt->fetch();
    if (!$p) { flash('danger', 'Profile not found.'); redirect('?action=profiles'); }
    $meta = json_decode_safe((string)($p['metadata'] ?? '{}'));
    $imputed = is_array($meta['imputed_fields'] ?? null) ? $meta['imputed_fields'] : [];

    page_header('Profile', $CONFIG, $settings, $u);
    echo "<div class=\"d-flex justify-content-between align-items-start mt-3\"><div><h1 class=\"h4 mb-1\">Profile</h1><div class=\"muted\">" . h((string)$p['hcp_id']) . "</div></div>";
    echo "<div class=\"d-flex gap-2\"><a class=\"btn btn-outline-dark\" href=\"?action=profiles\">Back</a>";
    echo "<form method=\"post\" action=\"?action=re_enrich&id={$id}\" class=\"m-0\">" . csrf_field() . "<button class=\"btn btn-primary\" type=\"submit\">Re-enrich</button></form></div></div>";

    echo "<div class=\"row g-3 mt-1\">";
    echo "<div class=\"col-lg-7\"><div class=\"card p-3\"><div class=\"fw-semibold mb-2\">Fields</div>";
    $fields = ['name','email','specialty','region','organization','role','consent_email','consent_web','last_activity_ts','imports_count','persona','priority_score','compliance_flag','confidence_score'];
    echo "<div class=\"table-responsive\"><table class=\"table table-sm mb-0\"><tbody>";
    foreach ($fields as $f) {
        $v = $p[$f] ?? null;
        $isImp = isset($imputed[$f]);
        $cls = $isImp ? 'imputed' : '';
        $display = $v === null ? '' : (string)$v;
        if ($f === 'last_activity_ts' && $v) $display = (string)$v . ' (' . date('Y-m-d', (int)$v) . ')';
        echo "<tr><td class=\"mono\">" . h($f) . "</td><td class=\"{$cls}\">" . h($display);
        if ($isImp) echo " <span class=\"badge badge-accent\">imputed</span>";
        echo "</td></tr>";
    }
    echo "</tbody></table></div></div></div>";

    echo "<div class=\"col-lg-5\"><div class=\"card p-3\"><div class=\"fw-semibold mb-2\">Traceability</div>";
    $tags = $meta['tags'] ?? [];
    if (is_array($tags) && $tags) {
        echo "<div class=\"mb-2\">";
        foreach ($tags as $t) echo "<span class=\"badge text-bg-light border me-1\">" . h((string)$t) . "</span>";
        echo "</div>";
    } else {
        echo "<div class=\"muted small mb-2\">No tags.</div>";
    }
    $applied = $meta['applied_rules'] ?? [];
    if (is_array($applied) && $applied) {
        echo "<div class=\"small muted\">Applied rules (latest 10)</div>";
        echo "<div class=\"table-responsive\"><table class=\"table table-sm mb-0\"><thead><tr><th>Rule</th><th class=\"text-end\">When</th></tr></thead><tbody>";
        foreach (array_slice($applied, -10) as $ar) {
            $when = isset($ar['ts']) ? date('Y-m-d H:i', (int)$ar['ts']) : '';
            echo "<tr><td>" . h((string)($ar['rule_name'] ?? '')) . "</td><td class=\"text-end small muted\">" . h($when) . "</td></tr>";
        }
        echo "</tbody></table></div>";
    } else {
        echo "<div class=\"muted small\">No rules applied yet. Create rules in the Rules tab, then Re-enrich or run cron.</div>";
    }
    echo "</div></div></div>";
    page_footer();
}

function handle_re_enrich(PDO $pdo, array $CONFIG): void {
    $u = current_user();
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT * FROM profiles WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $p = $stmt->fetch();
    if (!$p) { flash('danger', 'Profile not found.'); redirect('?action=profiles'); }
    $rules = $pdo->query('SELECT * FROM rules ORDER BY priority DESC, id ASC')->fetchAll();
    $meta = reset_rule_trace(json_decode_safe((string)($p['metadata'] ?? '{}')));
    $p['metadata'] = json_encode_safe($meta);
    $p['priority_score'] = compute_base_priority($p, $CONFIG);
    $p = enrich_profile($p, $rules, $CONFIG);
    $pdo->prepare('UPDATE profiles SET persona=:persona, priority_score=:ps, compliance_flag=:cf, metadata=:m WHERE id=:id')->execute([
        ':persona' => $p['persona'] !== null ? (string)$p['persona'] : null,
        ':ps' => (float)$p['priority_score'],
        ':cf' => (int)($p['compliance_flag'] ?? 0),
        ':m' => (string)$p['metadata'],
        ':id' => $id,
    ]);
    audit($pdo, (int)$u['id'], 'profile_re_enriched', ['profile_id' => $id]);
    flash('success', 'Profile re-enriched.');
    redirect('?action=profile_view&id=' . $id);
}

function reset_rule_trace(array $meta): array {
    $meta['applied_rules'] = [];
    $meta['applied_rule_ids_csv'] = ',';
    $meta['last_enriched_ts'] = time();
    if (!isset($meta['tags']) || !is_array($meta['tags'])) $meta['tags'] = [];
    if (!isset($meta['imputed_fields']) || !is_array($meta['imputed_fields'])) $meta['imputed_fields'] = [];
    return $meta;
}

function validate_sql_filter(string $where, array $allowedFields): array {
    $trim = trim($where);
    if ($trim === '') return [false, 'Filter is empty'];
    if (strpos($trim, ';') !== false) return [false, 'Semicolons are not allowed'];
    if (preg_match('/--|\/\*|\*\//', $trim)) return [false, 'SQL comments are not allowed'];
    if (strpos($trim, '"') !== false) return [false, 'Double quotes are not allowed'];
    if (preg_match('/\b(attach|detach|pragma|vacuum|insert|update|delete|drop|alter|create)\b/i', $trim)) {
        return [false, 'Only WHERE-like filters are allowed'];
    }
    $scan = preg_replace("/'([^']|'')*'/", "''", $trim) ?? $trim;
    preg_match_all('/\b[a-zA-Z_][a-zA-Z0-9_]*\b/', $scan, $m);
    $idents = $m[0] ?? [];
    $keywords = ['and','or','not','like','in','is','null','between','exists','true','false'];
    foreach ($idents as $id) {
        $lid = strtolower($id);
        if (in_array($lid, $keywords, true)) continue;
        if (!in_array($id, $allowedFields, true)) return [false, 'Unknown/unsafe field: ' . $id];
    }
    return [true, $trim];
}

/*
====================================================================================================
Rules UI
====================================================================================================
*/

function page_rules(PDO $pdo, array $CONFIG, array $settings): void {
    $u = current_user();
    page_header('Rules', $CONFIG, $settings, $u);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_admin();
        $op = (string)($_POST['op'] ?? '');
        if ($op === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare('DELETE FROM rules WHERE id = :id')->execute([':id' => $id]);
            audit($pdo, (int)$u['id'], 'rule_deleted', ['rule_id' => $id]);
            flash('success', 'Rule deleted.');
            redirect('?action=rules');
        }
    }

    $rules = $pdo->query('SELECT * FROM rules ORDER BY priority DESC, id ASC')->fetchAll();
    echo "<div class=\"d-flex justify-content-between align-items-start mt-3\">";
    echo "<div><h1 class=\"h4 mb-1\">Rules</h1><div class=\"muted\">JSON rules enrich profiles (persona, priority_score, compliance_flag, tags).</div></div>";
    echo "<div class=\"d-flex gap-2\">";
    if (is_admin()) echo "<a class=\"btn btn-primary\" href=\"?action=rule_edit\">New rule</a>";
    echo "<a class=\"btn btn-outline-dark\" href=\"?action=rule_test\">Test rule</a>";
    echo "</div></div>";

    echo "<div class=\"card p-3 mt-3\"><div class=\"table-responsive\"><table class=\"table table-sm align-middle mb-0\">";
    echo "<thead><tr><th>Priority</th><th>Name</th><th class=\"text-end\">Created</th><th></th></tr></thead><tbody>";
    foreach ($rules as $r) {
        $id = (int)$r['id'];
        echo "<tr><td class=\"mono\">" . h((string)$r['priority']) . "</td><td>" . h((string)$r['name']) . "</td><td class=\"text-end small muted\">" . h(date('Y-m-d', (int)$r['created_at'])) . "</td><td class=\"text-end\">";
        echo "<a class=\"btn btn-sm btn-outline-dark\" href=\"?action=rule_edit&id={$id}\">Edit</a> ";
        if (is_admin()) {
            echo "<form method=\"post\" class=\"d-inline\" onsubmit=\"return confirm('Delete rule?')\">" . csrf_field();
            echo "<input type=\"hidden\" name=\"op\" value=\"delete\"><input type=\"hidden\" name=\"id\" value=\"" . h((string)$id) . "\">";
            echo "<button class=\"btn btn-sm btn-outline-dark\" type=\"submit\">Delete</button></form>";
        }
        echo "</td></tr>";
    }
    if (!$rules) echo "<tr><td colspan=\"4\" class=\"muted\">No rules yet. Load sample or create a new rule.</td></tr>";
    echo "</tbody></table></div></div>";

    echo "<div class=\"card p-3 mt-3\"><div class=\"fw-semibold mb-2\">Rule JSON format</div>";
    echo "<pre class=\"small mono\" style=\"white-space:pre-wrap\">" . h("{\n  \"match\": \"AND\",\n  \"conditions\": [\n    {\"field\":\"specialty\",\"op\":\"=\",\"value\":\"oncology\"},\n    {\"field\":\"consent_email\",\"op\":\"=\",\"value\": 1}\n  ],\n  \"continue_on_match\": true\n}\n\nActions JSON example:\n{\n  \"set_persona\":\"Oncology Engaged\",\n  \"set_priority_score\":85,\n  \"set_compliance_flag\":1,\n  \"add_tags\":[\"oncology\",\"email_ok\"]\n}") . "</pre>";
    echo "<div class=\"small muted\">Supported ops: =, !=, &gt;, &lt;, &gt;=, &lt;=, in, contains, regex. Groups: use {\"all\": [...]} or {\"any\": [...]} nesting.</div></div>";
    page_footer();
}

function page_rule_edit(PDO $pdo, array $CONFIG, array $settings): void {
    require_admin();
    $u = current_user();
    $id = (int)($_GET['id'] ?? 0);
    $rule = null;
    if ($id > 0) {
        $stmt = $pdo->prepare('SELECT * FROM rules WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $rule = $stmt->fetch();
        if (!$rule) { flash('danger', 'Rule not found.'); redirect('?action=rules'); }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $name = trim((string)($_POST['name'] ?? ''));
        $priority = (int)($_POST['priority'] ?? 0);
        $conditions = trim((string)($_POST['conditions_json'] ?? ''));
        $actions = trim((string)($_POST['actions_json'] ?? ''));
        if ($name === '') { flash('danger', 'Name is required.'); redirect($id ? ('?action=rule_edit&id=' . $id) : '?action=rule_edit'); }
        $cj = json_decode($conditions, true);
        $aj = json_decode($actions, true);
        if (!is_array($cj)) { flash('danger', 'Conditions JSON is invalid.'); redirect($id ? ('?action=rule_edit&id=' . $id) : '?action=rule_edit'); }
        if (!is_array($aj)) { flash('danger', 'Actions JSON is invalid.'); redirect($id ? ('?action=rule_edit&id=' . $id) : '?action=rule_edit'); }

        if ($id > 0) {
            $pdo->prepare('UPDATE rules SET name=:n, conditions_json=:c, actions_json=:a, priority=:p WHERE id=:id')
                ->execute([':n' => $name, ':c' => json_encode_safe($cj), ':a' => json_encode_safe($aj), ':p' => $priority, ':id' => $id]);
            audit($pdo, (int)$u['id'], 'rule_updated', ['rule_id' => $id, 'name' => $name]);
            flash('success', 'Rule updated.');
        } else {
            $pdo->prepare('INSERT INTO rules (name, conditions_json, actions_json, priority, created_at) VALUES (:n,:c,:a,:p,:t)')
                ->execute([':n' => $name, ':c' => json_encode_safe($cj), ':a' => json_encode_safe($aj), ':p' => $priority, ':t' => time()]);
            $newId = (int)$pdo->lastInsertId();
            audit($pdo, (int)$u['id'], 'rule_created', ['rule_id' => $newId, 'name' => $name]);
            flash('success', 'Rule created.');
        }
        redirect('?action=rules');
    }

    $defaultCond = json_encode_safe(['match'=>'AND','conditions'=>[['field'=>'specialty','op'=>'=','value'=>'oncology'],['field'=>'consent_email','op'=>'=','value'=>1]],'continue_on_match'=>true]);
    $defaultAct = json_encode_safe(['set_persona'=>'Example persona','add_priority_delta'=>10,'add_tags'=>['example']]);

    page_header($id > 0 ? 'Edit rule' : 'New rule', $CONFIG, $settings, $u);
    echo "<div class=\"d-flex justify-content-between align-items-start mt-3\"><div><h1 class=\"h4 mb-1\">" . h($id > 0 ? 'Edit rule' : 'New rule') . "</h1><div class=\"muted\">Rules are applied in priority order (higher first).</div></div><div><a class=\"btn btn-outline-dark\" href=\"?action=rules\">Back</a></div></div>";

    echo "<form method=\"post\" class=\"card p-3 mt-3\">" . csrf_field();
    echo "<div class=\"row g-3\">";
    echo "<div class=\"col-md-7\"><label class=\"form-label\">Name</label><input class=\"form-control\" name=\"name\" value=\"" . h((string)($rule['name'] ?? '')) . "\" required></div>";
    echo "<div class=\"col-md-2\"><label class=\"form-label\">Priority</label><input class=\"form-control\" type=\"number\" name=\"priority\" value=\"" . h((string)($rule['priority'] ?? 0)) . "\"></div>";
    echo "</div>";
    echo "<div class=\"row g-3 mt-1\">";
    echo "<div class=\"col-lg-6\"><label class=\"form-label\">Conditions JSON</label><textarea class=\"form-control mono\" rows=\"14\" name=\"conditions_json\">" . h((string)($rule['conditions_json'] ?? $defaultCond)) . "</textarea></div>";
    echo "<div class=\"col-lg-6\"><label class=\"form-label\">Actions JSON</label><textarea class=\"form-control mono\" rows=\"14\" name=\"actions_json\">" . h((string)($rule['actions_json'] ?? $defaultAct)) . "</textarea></div>";
    echo "</div>";
    echo "<div class=\"mt-3 d-flex gap-2\"><button class=\"btn btn-primary\" type=\"submit\">Save</button><a class=\"btn btn-outline-dark\" href=\"?action=rule_test\">Test</a></div>";
    echo "</form>";
    page_footer();
}

function page_rule_test(PDO $pdo, array $CONFIG, array $settings): void {
    $u = current_user();
    page_header('Test rule', $CONFIG, $settings, $u);
    $rules = $pdo->query('SELECT * FROM rules ORDER BY priority DESC, id ASC')->fetchAll();
    $profiles = $pdo->query('SELECT * FROM profiles ORDER BY created_at DESC LIMIT 50')->fetchAll();

    $selectedRuleId = (int)($_GET['rule_id'] ?? 0);
    $selectedProfileId = (int)($_GET['profile_id'] ?? 0);
    $result = null;
    if ($selectedRuleId && $selectedProfileId) {
        $rStmt = $pdo->prepare('SELECT * FROM rules WHERE id = :id');
        $rStmt->execute([':id' => $selectedRuleId]);
        $rule = $rStmt->fetch();
        $pStmt = $pdo->prepare('SELECT * FROM profiles WHERE id = :id');
        $pStmt->execute([':id' => $selectedProfileId]);
        $p = $pStmt->fetch();
        if ($rule && $p) {
            $cond = json_decode_safe((string)$rule['conditions_json']);
            $actions = json_decode_safe((string)$rule['actions_json']);
            $matched = eval_conditions($p, $cond);
            $before = ['persona' => $p['persona'] ?? null, 'priority_score' => $p['priority_score'] ?? null, 'compliance_flag' => $p['compliance_flag'] ?? null, 'metadata' => json_decode_safe((string)($p['metadata'] ?? '{}'))];
            $afterProfile = $p;
            if ($matched) {
                $afterProfile = apply_actions($afterProfile, $actions, (int)$rule['id'], (string)$rule['name']);
            }
            $after = ['persona' => $afterProfile['persona'] ?? null, 'priority_score' => $afterProfile['priority_score'] ?? null, 'compliance_flag' => $afterProfile['compliance_flag'] ?? null, 'metadata' => json_decode_safe((string)($afterProfile['metadata'] ?? '{}'))];
            $result = ['matched' => $matched, 'before' => $before, 'after' => $after];
        }
    }

    echo "<div class=\"d-flex justify-content-between align-items-start mt-3\"><div><h1 class=\"h4 mb-1\">Test rule</h1><div class=\"muted\">Evaluate a single rule against a single profile (no database changes).</div></div><div><a class=\"btn btn-outline-dark\" href=\"?action=rules\">Back</a></div></div>";

    echo "<form class=\"card p-3 mt-3\" method=\"get\">";
    echo "<input type=\"hidden\" name=\"action\" value=\"rule_test\">";
    echo "<div class=\"row g-2\">";
    echo "<div class=\"col-md-6\"><label class=\"form-label\">Rule</label><select class=\"form-select\" name=\"rule_id\"><option value=\"0\">Select…</option>";
    foreach ($rules as $r) {
        $rid = (int)$r['id'];
        $sel = ($rid === $selectedRuleId) ? 'selected' : '';
        echo "<option {$sel} value=\"{$rid}\">" . h((string)$r['name']) . " (p=" . h((string)$r['priority']) . ")</option>";
    }
    echo "</select></div>";
    echo "<div class=\"col-md-6\"><label class=\"form-label\">Profile</label><select class=\"form-select\" name=\"profile_id\"><option value=\"0\">Select…</option>";
    foreach ($profiles as $p) {
        $pid = (int)$p['id'];
        $sel = ($pid === $selectedProfileId) ? 'selected' : '';
        echo "<option {$sel} value=\"{$pid}\">" . h((string)$p['hcp_id']) . " — " . h((string)$p['name']) . "</option>";
    }
    echo "</select></div>";
    echo "</div><div class=\"mt-2\"><button class=\"btn btn-primary\" type=\"submit\">Run test</button></div></form>";

    if ($result) {
        $matched = (bool)($result['matched'] ?? false);
        echo "<div class=\"card p-3 mt-3\"><div class=\"d-flex justify-content-between\"><div class=\"fw-semibold\">Result</div><div><span class=\"badge " . ($matched ? 'text-bg-success' : 'text-bg-dark') . "\">" . ($matched ? 'MATCHED' : 'NO MATCH') . "</span></div></div><hr>";
        echo "<div class=\"row g-3\">";
        echo "<div class=\"col-md-6\"><div class=\"fw-semibold\">Before</div><pre class=\"small mono\" style=\"white-space:pre-wrap\">" . h(json_encode_safe($result['before'])) . "</pre></div>";
        echo "<div class=\"col-md-6\"><div class=\"fw-semibold\">After (simulated)</div><pre class=\"small mono\" style=\"white-space:pre-wrap\">" . h(json_encode_safe($result['after'])) . "</pre></div>";
        echo "</div></div>";
    }

    page_footer();
}

/*
====================================================================================================
Segments + Export + Simulator + Settings + Sample + Cron
====================================================================================================
*/

function segment_allowed_fields(): array {
    return ['hcp_id','name','email','specialty','region','organization','role','consent_email','consent_web','last_activity_ts','imports_count','persona','priority_score','compliance_flag','confidence_score','created_at'];
}

function build_segment_where(PDO $pdo, array $segment, string $mode, int $threshold, array &$params): string {
    $params = [];
    $clauses = [];
    if (!empty($segment['sql_filter'])) {
        $filter = (string)$segment['sql_filter'];
        [$ok, $res] = validate_sql_filter($filter, segment_allowed_fields());
        if (!$ok) throw new RuntimeException((string)$res);
        $clauses[] = '(' . $res . ')';
    } else {
        $ids = json_decode_safe((string)($segment['rule_ids'] ?? '[]'));
        if (!is_array($ids) || !$ids) {
            $clauses[] = '1=1';
        } else {
            $i = 0;
            foreach ($ids as $rid) {
                $rid = (int)$rid;
                $i++;
                $k = ':rid' . $i;
                $clauses[] = "metadata LIKE {$k}";
                $params[$k] = '%"applied_rule_ids_csv"%,' . $rid . ',%';
            }
        }
    }

    if ($mode === 'strict') {
        $clauses[] = 'confidence_score >= :th';
        $params[':th'] = $threshold;
    }
    return $clauses ? implode(' AND ', $clauses) : '1=1';
}

function page_segments(PDO $pdo, array $CONFIG, array $settings): void {
    $u = current_user();
    page_header('Segments', $CONFIG, $settings, $u);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $op = (string)($_POST['op'] ?? '');
        if ($op === 'create') {
            $name = trim((string)($_POST['name'] ?? ''));
            $type = (string)($_POST['type'] ?? 'sql');
            $sql = trim((string)($_POST['sql_filter'] ?? ''));
            $ruleIds = $_POST['rule_ids'] ?? [];
            if ($name === '') { flash('danger', 'Segment name is required.'); redirect('?action=segments'); }
            if ($type === 'sql') {
                [$ok, $res] = validate_sql_filter($sql, segment_allowed_fields());
                if (!$ok) { flash('danger', (string)$res); redirect('?action=segments'); }
                $pdo->prepare('INSERT INTO segments (name, rule_ids, sql_filter, last_run_at) VALUES (:n,:r,:s,NULL)')
                    ->execute([':n' => $name, ':r' => '[]', ':s' => $res]);
            } else {
                $ids = [];
                if (is_array($ruleIds)) foreach ($ruleIds as $rid) $ids[] = (int)$rid;
                $pdo->prepare('INSERT INTO segments (name, rule_ids, sql_filter, last_run_at) VALUES (:n,:r,NULL,NULL)')
                    ->execute([':n' => $name, ':r' => json_encode_safe(array_values(array_unique($ids)))]);
            }
            $sid = (int)$pdo->lastInsertId();
            audit($pdo, (int)$u['id'], 'segment_created', ['segment_id' => $sid, 'name' => $name]);
            flash('success', 'Segment created.');
            redirect('?action=segments');
        }
        if ($op === 'delete') {
            require_admin();
            $sid = (int)($_POST['id'] ?? 0);
            $pdo->prepare('DELETE FROM segments WHERE id = :id')->execute([':id' => $sid]);
            audit($pdo, (int)$u['id'], 'segment_deleted', ['segment_id' => $sid]);
            flash('success', 'Segment deleted.');
            redirect('?action=segments');
        }
    }

    $segments = $pdo->query('SELECT * FROM segments ORDER BY id DESC')->fetchAll();
    $rules = $pdo->query('SELECT id, name, priority FROM rules ORDER BY priority DESC, id ASC')->fetchAll();

    echo "<div class=\"d-flex justify-content-between align-items-start mt-3\"><div><h1 class=\"h4 mb-1\">Segments</h1><div class=\"muted\">Build segments via safe SQL-like filters or rule-trace matching.</div></div></div>";

    echo "<div class=\"card p-3 mt-3\"><div class=\"fw-semibold mb-2\">Create segment</div>";
    echo "<form method=\"post\">" . csrf_field() . "<input type=\"hidden\" name=\"op\" value=\"create\">";
    echo "<div class=\"row g-2\">";
    echo "<div class=\"col-md-4\"><label class=\"form-label\">Name</label><input class=\"form-control\" name=\"name\" required></div>";
    echo "<div class=\"col-md-3\"><label class=\"form-label\">Type</label><select class=\"form-select\" name=\"type\" id=\"segType\"><option value=\"sql\">SQL filter</option><option value=\"rules\">Rule IDs</option></select></div>";
    echo "<div class=\"col-md-5\" id=\"segSqlWrap\"><label class=\"form-label\">SQL-like WHERE</label><input class=\"form-control mono\" name=\"sql_filter\" placeholder=\"specialty = 'oncology' AND confidence_score >= 70\"></div>";
    echo "</div>";
    echo "<div class=\"mt-2\" id=\"segRulesWrap\" style=\"display:none\">";
    if ($rules) {
        echo "<div class=\"small muted mb-1\">Select rules that must have been applied (based on metadata trace).</div>";
        foreach ($rules as $r) {
            $rid = (int)$r['id'];
            echo "<label class=\"me-3\"><input class=\"form-check-input me-1\" type=\"checkbox\" name=\"rule_ids[]\" value=\"{$rid}\">" . h((string)$r['name']) . "</label>";
        }
    } else {
        echo "<div class=\"muted\">No rules available yet.</div>";
    }
    echo "</div>";
    echo "<div class=\"mt-3\"><button class=\"btn btn-primary\" type=\"submit\">Create</button></div></form>";
    $nonce = (string)($GLOBALS['CSP_NONCE'] ?? '');
    echo "<script nonce=\"" . h($nonce) . "\">
        const segType = document.getElementById('segType');
        const sqlWrap = document.getElementById('segSqlWrap');
        const rulesWrap = document.getElementById('segRulesWrap');
        function syncSeg(){ if(!segType) return; const isRules = segType.value==='rules'; sqlWrap.style.display=isRules?'none':''; rulesWrap.style.display=isRules?'':'none'; }
        if(segType){ segType.addEventListener('change', syncSeg); syncSeg(); }
    </script>";
    echo "</div>";

    echo "<div class=\"card p-3 mt-3\"><div class=\"fw-semibold mb-2\">Existing segments</div>";
    echo "<div class=\"table-responsive\"><table class=\"table table-sm align-middle mb-0\"><thead><tr><th>ID</th><th>Name</th><th>Definition</th><th class=\"text-end\">Last run</th><th></th></tr></thead><tbody>";
    foreach ($segments as $s) {
        $sid = (int)$s['id'];
        $def = $s['sql_filter'] ? ('WHERE ' . (string)$s['sql_filter']) : ('rule_ids=' . (string)$s['rule_ids']);
        $lr = $s['last_run_at'] ? date('Y-m-d H:i', (int)$s['last_run_at']) : '';
        echo "<tr><td class=\"mono\">" . h((string)$sid) . "</td><td>" . h((string)$s['name']) . "</td><td class=\"small mono\" style=\"max-width:520px\">" . h($def) . "</td><td class=\"text-end small muted\">" . h($lr) . "</td><td class=\"text-end\">";
        echo "<a class=\"btn btn-sm btn-outline-dark\" href=\"?action=segment_run&id={$sid}\">Preview</a> ";
        if (is_admin()) {
            echo "<form method=\"post\" class=\"d-inline\" onsubmit=\"return confirm('Delete segment?')\">" . csrf_field();
            echo "<input type=\"hidden\" name=\"op\" value=\"delete\"><input type=\"hidden\" name=\"id\" value=\"" . h((string)$sid) . "\">";
            echo "<button class=\"btn btn-sm btn-outline-dark\" type=\"submit\">Delete</button></form>";
        }
        echo "</td></tr>";
    }
    if (!$segments) echo "<tr><td colspan=\"5\" class=\"muted\">No segments yet.</td></tr>";
    echo "</tbody></table></div></div>";
    page_footer();
}

function page_segment_run(PDO $pdo, array $CONFIG, array $settings): void {
    $u = current_user();
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT * FROM segments WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $segment = $stmt->fetch();
    if (!$segment) { flash('danger', 'Segment not found.'); redirect('?action=segments'); }

    $mode = (string)($_GET['mode'] ?? 'strict');
    if (!in_array($mode, ['strict','inclusive'], true)) $mode = 'strict';
    $threshold = (int)($settings['strict_confidence_threshold'] ?? $CONFIG['confidenceStrictThreshold']);
    $params = [];
    try {
        $where = build_segment_where($pdo, $segment, $mode, $threshold, $params);
    } catch (Throwable $e) {
        $errId = bin2hex(random_bytes(6));
        error_line($CONFIG, 'segment_filter_invalid', ['id' => $errId, 'segment_id' => $id, 'message' => $e->getMessage()]);
        flash('danger', 'Segment filter invalid (error ' . $errId . ').');
        redirect('?action=segments');
    }

    $cStmt = $pdo->prepare('SELECT COUNT(*) c FROM profiles WHERE ' . $where);
    $cStmt->execute($params);
    $count = (int)($cStmt->fetch()['c'] ?? 0);

    $sStmt = $pdo->prepare('SELECT id,hcp_id,name,specialty,region,persona,priority_score,confidence_score FROM profiles WHERE ' . $where . ' ORDER BY priority_score DESC LIMIT 50');
    $sStmt->execute($params);
    $rows = $sStmt->fetchAll();

    $pdo->prepare('UPDATE segments SET last_run_at = :t WHERE id = :id')->execute([':t' => time(), ':id' => $id]);
    audit($pdo, (int)$u['id'], 'segment_previewed', ['segment_id' => $id, 'mode' => $mode, 'count' => $count]);

    page_header('Segment preview', $CONFIG, $settings, $u);
    echo "<div class=\"d-flex justify-content-between align-items-start mt-3\"><div><h1 class=\"h4 mb-1\">Segment preview</h1><div class=\"muted\">" . h((string)$segment['name']) . "</div></div><div class=\"d-flex gap-2\"><a class=\"btn btn-outline-dark\" href=\"?action=segments\">Back</a><a class=\"btn btn-outline-dark\" href=\"?action=simulator&segment_id={$id}\">Simulate</a></div></div>";

    echo "<div class=\"card p-3 mt-3\"><div class=\"d-flex justify-content-between\"><div class=\"fw-semibold\">Count</div><div class=\"display-6\" style=\"line-height:1\">" . h((string)$count) . "</div></div>";
    echo "<div class=\"mt-2\">";
    echo "<a class=\"btn btn-sm " . ($mode==='strict'?'btn-primary':'btn-outline-dark') . "\" href=\"?action=segment_run&id={$id}&mode=strict\">Strict</a> ";
    echo "<a class=\"btn btn-sm " . ($mode==='inclusive'?'btn-primary':'btn-outline-dark') . "\" href=\"?action=segment_run&id={$id}&mode=inclusive\">Inclusive</a>";
    if ($mode === 'strict') echo " <span class=\"small muted\">(requires confidence_score ≥ " . h((string)$threshold) . ")</span>";
    echo "</div>";
    echo "<div class=\"mt-3 d-flex flex-wrap gap-2\">";
    echo "<form method=\"post\" action=\"?action=export_segment\" class=\"m-0\">" . csrf_field();
    echo "<input type=\"hidden\" name=\"id\" value=\"" . h((string)$id) . "\"><input type=\"hidden\" name=\"mode\" value=\"" . h($mode) . "\">";
    echo "<button class=\"btn btn-outline-dark\" type=\"submit\">Export (minimal)</button></form>";
    if (is_admin() && (bool)($settings['export_pii'] ?? false)) {
        echo "<form method=\"post\" action=\"?action=export_segment\" class=\"m-0\">" . csrf_field();
        echo "<input type=\"hidden\" name=\"id\" value=\"" . h((string)$id) . "\"><input type=\"hidden\" name=\"mode\" value=\"" . h($mode) . "\"><input type=\"hidden\" name=\"include_pii\" value=\"1\">";
        echo "<button class=\"btn btn-outline-dark\" type=\"submit\">Export (include email)</button></form>";
    }
    echo "</div></div>";

    echo "<div class=\"card p-3 mt-3\"><div class=\"fw-semibold mb-2\">Sample (50)</div>";
    echo "<div class=\"table-responsive\"><table class=\"table table-sm align-middle mb-0\"><thead><tr><th>HCP</th><th>Name</th><th>Specialty</th><th>Region</th><th>Persona</th><th class=\"text-end\">Priority</th><th class=\"text-end\">Conf.</th><th></th></tr></thead><tbody>";
    foreach ($rows as $r) {
        $pid = (int)$r['id'];
        echo "<tr><td class=\"mono\">" . h((string)$r['hcp_id']) . "</td><td>" . h((string)$r['name']) . "</td><td>" . h((string)($r['specialty'] ?? '')) . "</td><td>" . h((string)($r['region'] ?? '')) . "</td><td>" . h((string)($r['persona'] ?? '')) . "</td><td class=\"text-end\">" . h((string)$r['priority_score']) . "</td><td class=\"text-end\">" . h((string)$r['confidence_score']) . "</td><td class=\"text-end\"><a class=\"btn btn-sm btn-outline-dark\" href=\"?action=profile_view&id={$pid}\">View</a></td></tr>";
    }
    if (!$rows) echo "<tr><td colspan=\"8\" class=\"muted\">No rows.</td></tr>";
    echo "</tbody></table></div></div>";
    page_footer();
}

function page_simulator(PDO $pdo, array $CONFIG, array $settings): void {
    $u = current_user();
    page_header('Simulator', $CONFIG, $settings, $u);

    $segments = $pdo->query('SELECT id, name FROM segments ORDER BY id DESC')->fetchAll();
    $segmentId = (int)($_GET['segment_id'] ?? ($_POST['segment_id'] ?? 0));
    $mode = (string)($_POST['mode'] ?? ($_GET['mode'] ?? 'strict'));
    if (!in_array($mode, ['strict','inclusive'], true)) $mode = 'strict';

    $result = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $segmentId = (int)($_POST['segment_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT * FROM segments WHERE id = :id');
        $stmt->execute([':id' => $segmentId]);
        $segment = $stmt->fetch();
        if ($segment) {
            $threshold = (int)($settings['strict_confidence_threshold'] ?? $CONFIG['confidenceStrictThreshold']);
            $params = [];
            $where = build_segment_where($pdo, $segment, $mode, $threshold, $params);
            $cStmt = $pdo->prepare('SELECT COUNT(*) c FROM profiles WHERE ' . $where);
            $cStmt->execute($params);
            $size = (int)($cStmt->fetch()['c'] ?? 0);

            $contactRate = (float)($_POST['contact_rate'] ?? 0.6);
            $respRate = (float)($_POST['response_rate'] ?? 0.15);
            $costPer = (float)($_POST['cost_per_contact'] ?? 1.0);

            $contactsAttempted = $size * max(0.0, min(1.0, $contactRate));
            $expectedResponses = $contactsAttempted * max(0.0, min(1.0, $respRate));
            $cost = $contactsAttempted * max(0.0, $costPer);

            $projectedValueTotal = (float)($_POST['projected_value'] ?? 0);
            if ($projectedValueTotal <= 0) {
                $projectedValueTotal = $expectedResponses * (float)($CONFIG['defaultProjectedValuePerResponse'] ?? 100.0);
            }
            $roi = $cost > 0 ? ($projectedValueTotal / $cost) : null;

            $result = [
                'segment_id' => $segmentId,
                'segment_name' => (string)$segment['name'],
                'mode' => $mode,
                'segment_size' => $size,
                'contacts_attempted' => round($contactsAttempted, 2),
                'expected_responses' => round($expectedResponses, 2),
                'cost' => round($cost, 2),
                'projected_value' => round($projectedValueTotal, 2),
                'roi' => $roi === null ? null : round($roi, 3),
                'assumptions' => [
                    'contact_rate' => $contactRate,
                    'response_rate' => $respRate,
                    'cost_per_contact' => $costPer,
                    'projected_value_total' => $projectedValueTotal,
                ],
            ];
            audit($pdo, (int)$u['id'], 'simulation_run', $result);
        } else {
            flash('danger', 'Select a valid segment.');
            redirect('?action=simulator');
        }
    }

    echo "<div class=\"d-flex justify-content-between align-items-start mt-3\"><div><h1 class=\"h4 mb-1\">Target simulator</h1><div class=\"muted\">Project contacts, responses, cost, and ROI from segment size + assumptions.</div></div></div>";
    echo "<form method=\"post\" class=\"card p-3 mt-3\">" . csrf_field();
    echo "<div class=\"row g-2\">";
    echo "<div class=\"col-md-5\"><label class=\"form-label\">Segment</label><select class=\"form-select\" name=\"segment_id\" required><option value=\"\">Select…</option>";
    foreach ($segments as $s) {
        $sid = (int)$s['id'];
        $sel = ($sid === $segmentId) ? 'selected' : '';
        echo "<option {$sel} value=\"{$sid}\">" . h((string)$s['name']) . "</option>";
    }
    echo "</select></div>";
    echo "<div class=\"col-md-3\"><label class=\"form-label\">Mode</label><select class=\"form-select\" name=\"mode\"><option " . ($mode==='strict'?'selected':'') . " value=\"strict\">Strict</option><option " . ($mode==='inclusive'?'selected':'') . " value=\"inclusive\">Inclusive</option></select></div>";
    echo "<div class=\"col-md-2\"><label class=\"form-label\">Contact rate</label><input class=\"form-control\" name=\"contact_rate\" value=\"" . h((string)($_POST['contact_rate'] ?? '0.6')) . "\"></div>";
    echo "<div class=\"col-md-2\"><label class=\"form-label\">Response rate</label><input class=\"form-control\" name=\"response_rate\" value=\"" . h((string)($_POST['response_rate'] ?? '0.15')) . "\"></div>";
    echo "</div>";
    echo "<div class=\"row g-2 mt-1\">";
    echo "<div class=\"col-md-3\"><label class=\"form-label\">Cost per contact</label><input class=\"form-control\" name=\"cost_per_contact\" value=\"" . h((string)($_POST['cost_per_contact'] ?? '1.0')) . "\"></div>";
    echo "<div class=\"col-md-3\"><label class=\"form-label\">Projected value (total)</label><input class=\"form-control\" name=\"projected_value\" value=\"" . h((string)($_POST['projected_value'] ?? '')) . "\" placeholder=\"Leave blank to estimate\"></div>";
    echo "</div>";
    echo "<div class=\"mt-3\"><button class=\"btn btn-primary\" type=\"submit\">Run simulation</button></div>";
    echo "</form>";

    if ($result) {
        echo "<div class=\"card p-3 mt-3\"><div class=\"fw-semibold\">Results</div><hr>";
        echo "<div class=\"row g-3\">";
        echo "<div class=\"col-md-3\"><div class=\"muted small\">Segment size</div><div class=\"h3\">" . h((string)$result['segment_size']) . "</div></div>";
        echo "<div class=\"col-md-3\"><div class=\"muted small\">Contacts attempted</div><div class=\"h3\">" . h((string)$result['contacts_attempted']) . "</div></div>";
        echo "<div class=\"col-md-3\"><div class=\"muted small\">Expected responses</div><div class=\"h3\">" . h((string)$result['expected_responses']) . "</div></div>";
        echo "<div class=\"col-md-3\"><div class=\"muted small\">Cost</div><div class=\"h3\">" . h((string)$result['cost']) . "</div></div>";
        echo "</div><div class=\"row g-3 mt-1\">";
        echo "<div class=\"col-md-3\"><div class=\"muted small\">Projected value</div><div class=\"h3\">" . h((string)$result['projected_value']) . "</div></div>";
        $roi = $result['roi'];
        echo "<div class=\"col-md-3\"><div class=\"muted small\">ROI (value/cost)</div><div class=\"h3\">" . h($roi === null ? '—' : (string)$roi) . "</div></div>";
        echo "</div><hr><div class=\"small muted\">Stored in audit_log as a simulation run.</div></div>";
    }

    page_footer();
}

function handle_export_segment(PDO $pdo, array $CONFIG, array $settings): void {
    $u = current_user();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo 'Method Not Allowed';
        return;
    }
    $id = (int)($_POST['id'] ?? 0);
    $mode = (string)($_POST['mode'] ?? 'strict');
    if (!in_array($mode, ['strict','inclusive'], true)) $mode = 'strict';
    $includePii = (int)($_POST['include_pii'] ?? 0) === 1;
    rate_limit_assert($pdo, $CONFIG, 'export', '');

    $stmt = $pdo->prepare('SELECT * FROM segments WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $segment = $stmt->fetch();
    if (!$segment) { http_response_code(404); echo 'Segment not found'; return; }

    if ($includePii) {
        if (!is_admin()) { http_response_code(403); echo 'Forbidden'; return; }
        if (!(bool)($settings['export_pii'] ?? false)) { http_response_code(403); echo 'PII export disabled'; return; }
    }

    $fields = $CONFIG['defaultExportFieldsMinimal'];
    if ($includePii) {
        $fields = array_values(array_unique(array_merge($fields, ['email','name'])));
    }
    $allowed = $CONFIG['allowedExportFields'];
    $fields = array_values(array_filter($fields, fn($f) => in_array($f, $allowed, true)));
    if (!$fields) $fields = ['hcp_id','specialty','region','persona','priority_score','confidence_score'];

    $threshold = (int)($settings['strict_confidence_threshold'] ?? $CONFIG['confidenceStrictThreshold']);
    $params = [];
    $where = build_segment_where($pdo, $segment, $mode, $threshold, $params);

    $sql = 'SELECT ' . implode(',', array_map(fn($f) => $f, $fields)) . ' FROM profiles WHERE ' . $where . ' ORDER BY priority_score DESC';
    $q = $pdo->prepare($sql);
    $q->execute($params);

    $filename = 'litehcp_segment_' . $id . '_' . date('Ymd_His') . '.csv';
    $pdo->prepare('INSERT INTO exports (segment_id, filename, created_at) VALUES (:sid,:fn,:t)')
        ->execute([':sid' => $id, ':fn' => $filename, ':t' => time()]);
    audit($pdo, (int)$u['id'], 'export_created', ['segment_id' => $id, 'filename' => $filename, 'include_pii' => $includePii, 'fields' => $fields, 'mode' => $mode]);
    rate_limit_hit($pdo, $CONFIG, 'export', '');

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'wb');
    fputcsv($out, $fields);
    $count = 0;
    while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
        $count++;
        $line = [];
        foreach ($fields as $f) {
            $line[] = $row[$f] ?? '';
        }
        fputcsv($out, $line);
    }
    fclose($out);
}

function page_settings(PDO $pdo, array $CONFIG, array $settings): void {
    $u = current_user();
    page_header('Settings', $CONFIG, $settings, $u);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $op = (string)($_POST['op'] ?? '');
        if ($op === 'save') {
            require_admin();
            $settings['export_pii'] = isset($_POST['export_pii']);
            $settings['open_registration'] = isset($_POST['open_registration']);
            $th = (int)($_POST['strict_confidence_threshold'] ?? ($settings['strict_confidence_threshold'] ?? $CONFIG['confidenceStrictThreshold']));
            $settings['strict_confidence_threshold'] = max(0, min(100, $th));
            set_settings($pdo, $settings);
            audit($pdo, (int)$u['id'], 'settings_updated', ['export_pii' => $settings['export_pii'], 'open_registration' => $settings['open_registration'], 'strict_confidence_threshold' => $settings['strict_confidence_threshold']]);
            flash('success', 'Settings saved.');
            redirect('?action=settings');
        }
        if ($op === 'repair_region') {
            require_admin();
            $newRegion = trim((string)($_POST['region'] ?? 'Unknown'));
            $stmt = $pdo->prepare('SELECT id, metadata FROM profiles WHERE region IS NULL OR region = ""');
            $stmt->execute();
            $ids = [];
            while ($p = $stmt->fetch()) {
                $meta = reset_rule_trace(json_decode_safe((string)($p['metadata'] ?? '{}')));
                $pdo->prepare('UPDATE profiles SET region = :r, metadata = :m WHERE id = :id')->execute([':r' => $newRegion, ':m' => json_encode_safe($meta), ':id' => (int)$p['id']]);
                $ids[] = (int)$p['id'];
            }
            audit($pdo, (int)$u['id'], 'repair_region', ['region' => $newRegion, 'count' => count($ids)]);
            flash('success', 'Updated region for ' . count($ids) . ' profiles.');
            redirect('?action=settings');
        }
        if ($op === 'bulk_consent') {
            require_admin();
            $ce = isset($_POST['consent_email']) ? 1 : 0;
            $cw = isset($_POST['consent_web']) ? 1 : 0;
            $pdo->prepare('UPDATE profiles SET consent_email = :ce, consent_web = :cw')->execute([':ce' => $ce, ':cw' => $cw]);
            audit($pdo, (int)$u['id'], 'bulk_consent_update', ['consent_email' => $ce, 'consent_web' => $cw]);
            flash('success', 'Bulk consent updated.');
            redirect('?action=settings');
        }
        if ($op === 'recompute_all') {
            require_admin();
            cron_recompute_all($pdo, $CONFIG);
            audit($pdo, (int)$u['id'], 'recompute_all', []);
            flash('success', 'Recomputed scores + rules across all profiles.');
            redirect('?action=settings');
        }
    }

    echo "<div class=\"d-flex justify-content-between align-items-start mt-3\"><div><h1 class=\"h4 mb-1\">Settings</h1><div class=\"muted\">Admin controls and repair tools.</div></div></div>";

    echo "<div class=\"row g-3 mt-1\">";
    echo "<div class=\"col-lg-6\"><div class=\"card p-3\"><div class=\"fw-semibold mb-2\">App settings</div>";
    if (is_admin()) {
        echo "<form method=\"post\">" . csrf_field() . "<input type=\"hidden\" name=\"op\" value=\"save\">";
        echo "<div class=\"form-check form-switch\"><input class=\"form-check-input\" type=\"checkbox\" name=\"export_pii\" id=\"exportPii\" " . ((bool)($settings['export_pii'] ?? false) ? 'checked' : '') . "><label class=\"form-check-label\" for=\"exportPii\">Enable PII export (email/name)</label></div>";
        echo "<div class=\"small muted\">PII exports are audited in audit_log.</div><hr>";
        echo "<div class=\"form-check form-switch\"><input class=\"form-check-input\" type=\"checkbox\" name=\"open_registration\" id=\"openReg\" " . ((bool)($settings['open_registration'] ?? false) ? 'checked' : '') . "><label class=\"form-check-label\" for=\"openReg\">Allow new user registration</label></div>";
        echo "<hr>";
        echo "<label class=\"form-label\">Strict confidence threshold</label><input class=\"form-control\" type=\"number\" name=\"strict_confidence_threshold\" value=\"" . h((string)($settings['strict_confidence_threshold'] ?? $CONFIG['confidenceStrictThreshold'])) . "\">";
        echo "<div class=\"mt-3\"><button class=\"btn btn-primary\" type=\"submit\">Save settings</button></div></form>";
    } else {
        echo "<div class=\"muted\">Only admins can change settings.</div>";
    }
    echo "</div></div>";

    echo "<div class=\"col-lg-6\"><div class=\"card p-3\"><div class=\"fw-semibold mb-2\">Repair tools</div>";
    if (!is_admin()) {
        echo "<div class=\"muted\">Only admins can run repairs.</div>";
    } else {
        echo "<form method=\"post\" class=\"mb-3\">" . csrf_field() . "<input type=\"hidden\" name=\"op\" value=\"repair_region\">";
        echo "<label class=\"form-label\">Set default region where missing</label><div class=\"d-flex gap-2\"><input class=\"form-control\" name=\"region\" value=\"Unknown\"><button class=\"btn btn-outline-dark\" type=\"submit\">Apply</button></div></form>";

        echo "<form method=\"post\" class=\"mb-3\">" . csrf_field() . "<input type=\"hidden\" name=\"op\" value=\"bulk_consent\">";
        echo "<label class=\"form-label\">Bulk consent update (all profiles)</label><div class=\"d-flex gap-3\">";
        echo "<label><input class=\"form-check-input me-1\" type=\"checkbox\" name=\"consent_email\" checked> consent_email</label>";
        echo "<label><input class=\"form-check-input me-1\" type=\"checkbox\" name=\"consent_web\" checked> consent_web</label>";
        echo "<button class=\"btn btn-outline-dark\" type=\"submit\">Apply</button></div></form>";

        echo "<form method=\"post\" onsubmit=\"return confirm('Recompute priority + re-apply rules for all profiles?')\">" . csrf_field() . "<input type=\"hidden\" name=\"op\" value=\"recompute_all\">";
        echo "<button class=\"btn btn-primary\" type=\"submit\">Recompute all (scores + rules)</button>";
        echo "<div class=\"small muted mt-1\">This runs synchronously. For big datasets, prefer cron.</div></form>";
    }
    echo "</div></div></div>";

    echo "<div class=\"card p-3 mt-3\"><div class=\"fw-semibold mb-2\">Shared hosting cron</div>";
    echo "<div class=\"small\">Suggested line: <span class=\"kbd\">php /home/USER/public_html/litehcp.php action=cron_recompute token=... </span></div>";
    echo "<div class=\"small muted\">Set token in the Customize section (cronToken) and keep it secret.</div></div>";

    page_footer();
}

function handle_load_sample(PDO $pdo, array $CONFIG, array $settings): void {
    require_admin();
    $u = current_user();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect('?action=dashboard');
    }

    $existing = $pdo->query('SELECT COUNT(*) c FROM rules')->fetch();
    if ((int)($existing['c'] ?? 0) === 0) {
        foreach (SAMPLE_RULES as $r) {
            $pdo->prepare('INSERT INTO rules (name, conditions_json, actions_json, priority, created_at) VALUES (:n,:c,:a,:p,:t)')->execute([
                ':n' => (string)$r['name'],
                ':c' => json_encode_safe($r['conditions']),
                ':a' => json_encode_safe($r['actions']),
                ':p' => (int)$r['priority'],
                ':t' => time(),
            ]);
        }
        audit($pdo, (int)$u['id'], 'sample_rules_loaded', ['count' => count(SAMPLE_RULES)]);
    }

    $segCount = $pdo->query('SELECT COUNT(*) c FROM segments')->fetch();
    if ((int)($segCount['c'] ?? 0) === 0) {
        $pdo->prepare('INSERT INTO segments (name, rule_ids, sql_filter, last_run_at) VALUES (:n,:r,:s,NULL)')
            ->execute([':n' => SAMPLE_SEGMENT['name'], ':r' => json_encode_safe(SAMPLE_SEGMENT['rule_ids']), ':s' => SAMPLE_SEGMENT['sql_filter']]);
        audit($pdo, (int)$u['id'], 'sample_segment_loaded', ['name' => SAMPLE_SEGMENT['name']]);
    }

    $dir = ensure_upload_dir();
    $pdo->prepare('INSERT INTO imports (filename, rows_total, rows_imported, errors_json, created_at) VALUES (:f,0,0,:e,:t)')
        ->execute([':f' => 'sample.csv', ':e' => json_encode_safe([]), ':t' => time()]);
    $importId = (int)$pdo->lastInsertId();
    $path = $dir . DIRECTORY_SEPARATOR . 'import_' . $importId . '.csv';
    file_put_contents($path, sample_csv());

    [$headers, $_preview] = csv_preview_rows($path, 5);
    $mapping = [];
    foreach ($headers as $h) $mapping[$h] = $h;
    $internal = ['hcp_id','name','email','specialty','region','organization','role','consent_email','consent_web','last_activity_ts','imports_count'];
    $map2 = [];
    foreach ($internal as $f) $map2[$f] = in_array($f, $headers, true) ? $f : '';
    $impute = [];
    foreach ($internal as $f) $impute[$f] = $CONFIG['defaultImputation'][$f] ?? ['strategy'=>'leave_null_flag','value'=>null];
    $stats = compute_csv_stats_for_imputation($path, $map2, $impute);
    $errorsObj = ['mapping'=>$map2,'impute'=>$impute,'stats'=>$stats,'mapping_confidence01'=>1.0];
    $pdo->prepare('UPDATE imports SET errors_json = :e, rows_total = :rt WHERE id = :id')->execute([':e'=>json_encode_safe($errorsObj), ':rt'=>(int)($stats['rows_total'] ?? 0), ':id'=>$importId]);

    audit($pdo, (int)$u['id'], 'sample_import_created', ['import_id' => $importId]);
    flash('success', 'Sample loaded: rules, segment, and sample import created.');
    redirect('?action=import_run&id=' . $importId);
}

function cron_recompute_all(PDO $pdo, array $CONFIG): void {
    $rules = $pdo->query('SELECT * FROM rules ORDER BY priority DESC, id ASC')->fetchAll();
    $limit = 200;
    $offset = 0;
    while (true) {
        $stmt = $pdo->prepare('SELECT * FROM profiles ORDER BY id ASC LIMIT :l OFFSET :o');
        $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':o', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        if (!$rows) break;
        $pdo->beginTransaction();
        foreach ($rows as $p) {
            $meta = reset_rule_trace(json_decode_safe((string)($p['metadata'] ?? '{}')));
            $p['metadata'] = json_encode_safe($meta);
            $p['priority_score'] = compute_base_priority($p, $CONFIG);
            $p = enrich_profile($p, $rules, $CONFIG);
            $pdo->prepare('UPDATE profiles SET persona=:persona, priority_score=:ps, compliance_flag=:cf, metadata=:m WHERE id=:id')->execute([
                ':persona' => $p['persona'] !== null ? (string)$p['persona'] : null,
                ':ps' => (float)$p['priority_score'],
                ':cf' => (int)($p['compliance_flag'] ?? 0),
                ':m' => (string)$p['metadata'],
                ':id' => (int)$p['id'],
            ]);
        }
        $pdo->commit();
        $offset += $limit;
    }
}


/*
====================================================================================================
End summary (what happens under the hood)
====================================================================================================
- Missing data is handled via per-field imputation (fixed/mode/mean/leave-null+flag) and stored in metadata.
- Each profile gets a confidence_score (0–100) based on completeness, mapping coverage, and imputation penalties.
- A base priority_score is computed from consent presence, activity recency, and imports_count using configurable weights.
- JSON rules are evaluated by priority; matching rules can set persona/compliance_flag, adjust priority, and add tags.
- Segments can be built from safe SQL-like filters or applied-rule traces, previewed, and exported as CSV.
- Simulator projects contacts/responses/cost/ROI from segment size and user-defined assumptions; runs are audited.
*/

