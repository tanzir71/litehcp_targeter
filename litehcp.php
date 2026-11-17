<?php
/*
LiteHCP Targeter — single-file PHP + SQLite app (portable)

Deploy (shared hosting / cPanel / Namecheap):
1) Upload this file as `public_html/litehcp.php` and force HTTPS for the domain.
2) Ensure PHP 8.0+ (8.3+ recommended) and write permission next to this file (creates `litehcp.db` + `litehcp_uploads/`).
3) Visit `/litehcp.php` and register the first user (becomes Admin).
4) Overview → "Load sample data" imports 200 HCPs, 9 rules and 6 audiences in one click.
5) Optional cron recompute: php /home/USER/public_html/litehcp.php action=cron_recompute token=CHANGE_ME
6) Self-test (CLI, uses a throwaway in-memory database): php litehcp.php action=selftest
7) Lock down access (HTTPS, strong admin password, optional `.htaccess` allowlist/basic-auth).
*/

declare(strict_types=1);

const LITEHCP_VERSION = '1.2.0';

if (PHP_VERSION_ID < 80000) {
    http_response_code(500);
    echo 'LiteHCP Targeter needs PHP 8.0 or newer (8.3+ recommended). This server runs PHP ' . PHP_VERSION . '.';
    exit;
}

/*
====================================================================================================
Customize here
====================================================================================================
*/

$CONFIG = [
    'appName' => 'LiteHCP Targeter',
    'repoUrl' => 'https://github.com/tanzir71/litehcp_targeter',
    'dbDsn' => 'sqlite:./litehcp.db',
    'accentColor' => '#2540FF',
    'locale' => 'en_US',
    'currency' => 'USD',
    'currencySymbol' => '$',

    'sessionTimeoutSeconds' => 60 * 30,
    'sessionCookieSameSite' => 'Lax',
    'allowOpenRegistrationAfterAdmin' => false,
    // Role given to users who self-register after the first admin (when registration is open).
    'defaultNewUserRole' => 'viewer',

    'importCommitEvery' => 200,
    'importMaxPreviewRows' => 10,
    'importMaxTestProfiles' => 50,
    'importSecondsPerChunk' => 8,

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

    // Tiers are derived from the priority score (0–100). A >= A cut-off, B >= B cut-off, otherwise C.
    'tierCutoffs' => ['A' => 80, 'B' => 50],
    // Planned calls per cycle written into the "CRM target list" export.
    'plannedCallsPerTier' => ['A' => 6, 'B' => 3, 'C' => 1],

    // Suppression is absolute: HCPs with one of these personas, or on the manual suppression list,
    // never appear in an audience count, preview or export.
    'suppressPersonas' => ['Do Not Contact'],

    'allowedExportFields' => [
        'hcp_id','npi','name','email','specialty','region','organization','role',
        'consent_email','consent_web','last_activity_ts','imports_count',
        'persona','tier','planned_calls','priority_score','priority_decile','compliance_flag','confidence_score',
    ],
    'defaultExportFieldsMinimal' => [
        'hcp_id','specialty','region','persona','priority_score','confidence_score',
    ],
    'exportPresets' => [
        'minimal' => [
            'label' => 'Minimal CSV',
            'description' => '6 fields, no email or name',
            'fields' => ['hcp_id','specialty','region','persona','priority_score','confidence_score'],
            'pii' => false,
        ],
        'crm_targets' => [
            'label' => 'CRM target list',
            'description' => 'HCP ID, NPI, tier and planned calls per cycle',
            'fields' => ['hcp_id','npi','tier','planned_calls','specialty','region','persona','priority_score'],
            'pii' => false,
        ],
        'email_platform' => [
            'label' => 'Email platform',
            'description' => 'Name and email, only for HCPs with email consent',
            'fields' => ['hcp_id','name','email','specialty','persona','tier'],
            'pii' => true,
            'requireEmailConsent' => true,
        ],
    ],

    'cronToken' => 'CHANGE_ME',
    'envPath' => __DIR__ . DIRECTORY_SEPARATOR . '.env',

    'uploadMaxBytes' => 5 * 1024 * 1024,
    'uploadAllowedExtensions' => ['csv'],
    'uploadAllowedMimeTypes' => ['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/csv'],

    'logPath' => __DIR__ . DIRECTORY_SEPARATOR . 'litehcp_uploads' . DIRECTORY_SEPARATOR . 'litehcp.log',
    'errorLogPath' => __DIR__ . DIRECTORY_SEPARATOR . 'litehcp_uploads' . DIRECTORY_SEPARATOR . 'litehcp_error.log',

    'rateLimit' => [
        'login' => ['windowSeconds' => 15 * 60, 'maxAttempts' => 8, 'blockSeconds' => 15 * 60],
        'register' => ['windowSeconds' => 60 * 60, 'maxAttempts' => 5, 'blockSeconds' => 60 * 60],
        'export' => ['windowSeconds' => 10 * 60, 'maxAttempts' => 20, 'blockSeconds' => 10 * 60],
    ],

    // Rules are re-applied to every HCP after a rule is saved when the database holds at most this many HCPs.
    'autoReapplyRulesMaxProfiles' => 20000,

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
    // Deterministic demo dataset: 200 realistic HCP profiles with believable gaps
    // (missing emails/regions/specialties/activity) so imputation + confidence scoring
    // have something real to chew on. Same seed => same data on every load.
    $seed = 20260612;
    $rand = function (int $n) use (&$seed): int {
        $seed = ($seed * 1103515245 + 12345) % 2147483648;
        return (int)($seed % max(1, $n));
    };

    $firstNames = ['Amira','Ben','Carla','Dinesh','Elena','Farid','Grace','Hiro','Imani','Jonas','Katya','Liam','Mei','Noor','Omar','Priya','Quinn','Rosa','Sven','Tara','Umar','Vera','Wei','Ximena','Yusuf','Zoe','Anders','Bianca','Chidi','Dalia','Emil','Fatima','Gustav','Hana','Idris','Jana','Kofi','Leila','Marco','Nadia'];
    $lastNames  = ['Khan','Wu','Diaz','Patel','Sorensen','Haddad','Okafor','Tanaka','Jallow','Berg','Ivanova','Connolly','Chen','Rahman','Farouk','Iyer','Marsh','Alvarez','Lindqvist','Bose','Aziz','Petrova','Zhang','Reyes','Demir','Mertens','Holm','Ricci','Eze','Mansour','Novak','Said','Lund','Kobayashi','Toure','Kovac','Mensah','Nasser','Bianchi','Karim'];

    // Weighted pools (repeat entries = higher frequency).
    $specialtyPool = ['oncology','oncology','oncology','cardiology','cardiology','cardiology','dermatology','dermatology','neurology','endocrinology','pediatrics','psychiatry','rheumatology','pulmonology','primary care','primary care'];
    $regions = ['West','East','Midwest','South','Northeast'];
    $orgs = ['Acme Health','Sunrise Clinic','Metro Hospital','Lakeside Medical Group','Northbridge Health','Cedar Valley Clinic','St. Adelhart Hospital','Riverbend Oncology Center','Harborview Cardiology','Summit Care Partners','Prairie Family Medicine','Bluestone Medical Center','Pinecrest Health Network','Westgate Specialty Group'];
    $rolePool = ['Physician','Physician','Physician','Physician','Physician','Physician','Nurse','Nurse','Nurse Practitioner','Physician Assistant','Pharmacist'];

    $now = time();
    // kol_score is intentionally NOT an internal field: it demonstrates custom-field import.
    $out = "hcp_id,name,email,specialty,region,organization,role,consent_email,consent_web,last_activity_ts,imports_count,kol_score\n";

    for ($i = 0; $i < 200; $i++) {
        $fn = $firstNames[$rand(count($firstNames))];
        $ln = $lastNames[$rand(count($lastNames))];
        $role = $rolePool[$rand(count($rolePool))];
        $prefix = $role === 'Physician' ? 'Dr. ' : ($role === 'Nurse' ? 'Nurse ' : '');
        $name = $prefix . $fn . ' ' . $ln;

        // ~12% missing email, ~6% missing specialty, ~9% missing region, ~15% missing activity.
        $email = ($rand(100) < 12) ? '' : strtolower($fn) . '.' . strtolower($ln) . ($i + 1) . '@example.test';
        $specialty = ($rand(100) < 6) ? '' : $specialtyPool[$rand(count($specialtyPool))];
        $region = ($rand(100) < 9) ? '' : $regions[$rand(count($regions))];
        $org = $orgs[$rand(count($orgs))];

        $consentEmail = ($rand(100) < 68) ? 1 : 0;
        $consentWeb = ($rand(100) < 58) ? 1 : 0;

        if ($rand(100) < 15) {
            $lastActivity = '';
        } else {
            // Skew toward recent activity: min of two draws over 365 days.
            $daysAgo = min($rand(365), $rand(365));
            $lastActivity = (string)($now - $daysAgo * 86400 - $rand(86400));
        }

        // Engagement loosely correlated with consent.
        $imports = 1 + $rand(4) + (($consentEmail && $consentWeb) ? $rand(5) : 0);

        // Custom field demo: key-opinion-leader score, skewed low with a high tail.
        $kol = max($rand(101), $rand(101) > 79 ? $rand(101) : 0);

        $out .= 'HCP-' . (1001 + $i) . ",{$name},{$email},{$specialty},{$region},{$org},{$role},{$consentEmail},{$consentWeb},{$lastActivity},{$imports},{$kol}\n";
    }
    return $out;
}

function sample_rules(): array {
    $recentCutoff = time() - 86400 * 30;
    return [
        [
            'name' => 'No consent at all => Do Not Contact',
            'priority' => 200,
            'conditions' => [
                'match' => 'AND',
                'conditions' => [
                    ['field' => 'consent_email', 'op' => '=', 'value' => 0],
                    ['field' => 'consent_web', 'op' => '=', 'value' => 0],
                ],
                'continue_on_match' => false,
            ],
            'actions' => [
                'set_persona' => 'Do Not Contact',
                'set_priority_score' => 5,
                'set_compliance_flag' => 0,
                'add_tags' => ['no_consent', 'suppressed'],
            ],
        ],
        [
            'name' => 'Oncology + email consent => Oncology Engaged',
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
                'add_priority_delta' => 15,
                'set_compliance_flag' => 1,
                'add_tags' => ['oncology', 'email_ok'],
            ],
        ],
        [
            'name' => 'Cardiology + email consent => Cardio Engaged',
            'priority' => 95,
            'conditions' => [
                'match' => 'AND',
                'conditions' => [
                    ['field' => 'specialty', 'op' => '=', 'value' => 'cardiology'],
                    ['field' => 'consent_email', 'op' => '=', 'value' => 1],
                ],
                'continue_on_match' => true,
            ],
            'actions' => [
                'set_persona' => 'Cardio Engaged',
                'add_priority_delta' => 12,
                'set_compliance_flag' => 1,
                'add_tags' => ['cardiology', 'email_ok'],
            ],
        ],
        [
            'name' => 'High-frequency engagers (5+ imports)',
            'priority' => 80,
            'conditions' => [
                'match' => 'AND',
                'conditions' => [
                    ['field' => 'imports_count', 'op' => '>=', 'value' => 5],
                ],
                'continue_on_match' => true,
            ],
            'actions' => [
                'add_priority_delta' => 10,
                'add_tags' => ['power_user'],
            ],
        ],
        [
            'name' => 'Active in last 30 days => recency boost',
            'priority' => 75,
            'conditions' => [
                'match' => 'AND',
                'conditions' => [
                    ['field' => 'last_activity_ts', 'op' => '>=', 'value' => $recentCutoff],
                ],
                'continue_on_match' => true,
            ],
            'actions' => [
                'add_priority_delta' => 8,
                'add_tags' => ['recently_active'],
            ],
        ],
        [
            // Demonstrates rules over CUSTOM fields (kol_score arrives via custom-field import).
            'name' => 'KOL score 80+ (custom field) => boost',
            'priority' => 70,
            'conditions' => [
                'match' => 'AND',
                'conditions' => [
                    ['field' => 'custom.kol_score', 'op' => '>=', 'value' => 80],
                ],
                'continue_on_match' => true,
            ],
            'actions' => [
                'add_priority_delta' => 10,
                'add_tags' => ['kol'],
            ],
        ],
        [
            'name' => 'Web-only consent => Web Reachable',
            'priority' => 60,
            'conditions' => [
                'match' => 'AND',
                'conditions' => [
                    ['field' => 'consent_email', 'op' => '=', 'value' => 0],
                    ['field' => 'consent_web', 'op' => '=', 'value' => 1],
                ],
                'continue_on_match' => true,
            ],
            'actions' => [
                'set_persona' => 'Web Reachable',
                'add_tags' => ['web_only'],
            ],
        ],
        [
            'name' => 'Advanced practice providers => APP tag',
            'priority' => 55,
            'conditions' => [
                'match' => 'AND',
                'conditions' => [
                    ['field' => 'role', 'op' => 'in', 'value' => ['Nurse Practitioner', 'Physician Assistant']],
                ],
                'continue_on_match' => true,
            ],
            'actions' => [
                'add_priority_delta' => 5,
                'add_tags' => ['advanced_practice'],
            ],
        ],
        [
            'name' => 'Low engagement (single import) => cooldown',
            'priority' => 40,
            'conditions' => [
                'match' => 'AND',
                'conditions' => [
                    ['field' => 'imports_count', 'op' => '<=', 'value' => 1],
                ],
                'continue_on_match' => true,
            ],
            'actions' => [
                'add_priority_delta' => -8,
                'add_tags' => ['low_engagement'],
            ],
        ],
    ];
}



// Sample audiences use the visual-builder definition format (see compile_definition()).
// Suppression is applied to every audience automatically, so no "suppression list" audience is needed.
const SAMPLE_SEGMENTS = [
    [
        'name' => 'Oncology, high confidence',
        'definition' => ['match' => 'all', 'conditions' => [
            ['field' => 'specialty', 'op' => 'in', 'value' => ['oncology']],
            ['field' => 'confidence_score', 'op' => '>=', 'value' => 70],
        ]],
    ],
    [
        'name' => 'Cardiology, engaged',
        'definition' => ['match' => 'all', 'conditions' => [
            ['field' => 'specialty', 'op' => 'in', 'value' => ['cardiology']],
            ['field' => 'priority_score', 'op' => '>=', 'value' => 60],
        ]],
    ],
    [
        'name' => 'Email-reachable West',
        'definition' => ['match' => 'all', 'conditions' => [
            ['field' => 'reachable', 'op' => 'any', 'value' => ['email']],
            ['field' => 'region', 'op' => 'in', 'value' => ['West']],
        ]],
    ],
    [
        'name' => 'Power users (5+ imports)',
        'definition' => ['match' => 'all', 'conditions' => [
            ['field' => 'imports_count', 'op' => '>=', 'value' => 5],
        ]],
    ],
    [
        'name' => 'Key opinion leaders',
        'definition' => ['match' => 'all', 'conditions' => [
            ['field' => 'custom.kol_score', 'op' => '>=', 'value' => 80],
        ]],
    ],
    [
        'name' => 'Tier A, email-reachable',
        'definition' => ['match' => 'all', 'conditions' => [
            ['field' => 'tier', 'op' => 'in', 'value' => ['A']],
            ['field' => 'reachable', 'op' => 'any', 'value' => ['email']],
        ]],
    ],
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

if (php_sapi_name() !== 'cli') {
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
        flash('warning', 'Your session timed out. Sign in again.');
    }
    $_SESSION['__last_activity'] = time();
}
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

    // v1.2 loads no third-party assets: system fonts, inline CSS, one nonce'd script.
    $csp = implode('; ', [
        "default-src 'self'",
        "base-uri 'self'",
        "frame-ancestors 'none'",
        "object-src 'none'",
        "img-src 'self' data:",
        "font-src 'self'",
        "style-src 'self' 'unsafe-inline'",
        "script-src 'self' 'nonce-{$nonce}'",
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
    $posted = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($posted) || $posted === '' || !hash_equals(csrf_token(), $posted)) {
        if (str_starts_with((string)($_GET['action'] ?? ''), 'api_')) json_out(['error' => 'This page expired. Reload it and try again.'], 400);
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
    if (is_int($v) || is_float($v)) return ((float)$v) != 0.0 ? 1 : 0;
    $s = strtolower(trim((string)$v));
    return in_array($s, ['1', 'true', 'yes', 'y', 'on', 'x', 'opt-in', 'opt in', 'optin', 'opted in', 'granted', 'consented', 'subscribed'], true) ? 1 : 0;
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

function db_connect(string $dsn): PDO {
    $pdo = new PDO($dsn, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    if (str_starts_with($dsn, 'sqlite:') && $dsn !== 'sqlite::memory:') {
        $pdo->exec('PRAGMA journal_mode=WAL;');
    }
    $pdo->exec('PRAGMA synchronous=NORMAL;');
    $pdo->exec('PRAGMA foreign_keys=ON;');
    $pdo->exec('PRAGMA temp_store=MEMORY;');
    return $pdo;
}

function db(array $CONFIG): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $pdo = db_connect((string)$CONFIG['dbDsn']);
    return $pdo;
}

function table_columns(PDO $pdo, string $table): array {
    $cols = [];
    foreach ($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll() as $c) $cols[] = (string)$c['name'];
    return $cols;
}

function add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): void {
    if (!in_array($column, table_columns($pdo, $table), true)) {
        $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
    }
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

    migrate_schema($pdo);
}

// Numbered, idempotent migrations tracked in PRAGMA user_version.
function migrate_schema(PDO $pdo): void {
    $version = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    if ($version < 2) {
        // v1.2: identity, tiers, suppression, builder definitions, richer exports, roles.
        add_column_if_missing($pdo, 'profiles', 'npi', 'TEXT');
        add_column_if_missing($pdo, 'profiles', 'tier', 'TEXT');
        add_column_if_missing($pdo, 'profiles', 'priority_decile', 'INTEGER');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_profiles_npi ON profiles(npi);');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_profiles_tier ON profiles(tier);');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_profiles_persona ON profiles(persona);');

        add_column_if_missing($pdo, 'segments', 'definition_json', 'TEXT');
        add_column_if_missing($pdo, 'segments', 'plan_json', 'TEXT');
        add_column_if_missing($pdo, 'segments', 'created_at', 'INTEGER');
        add_column_if_missing($pdo, 'segments', 'updated_at', 'INTEGER');

        add_column_if_missing($pdo, 'exports', 'row_count', 'INTEGER NOT NULL DEFAULT 0');
        add_column_if_missing($pdo, 'exports', 'excluded_count', 'INTEGER NOT NULL DEFAULT 0');
        add_column_if_missing($pdo, 'exports', 'preset', 'TEXT');
        add_column_if_missing($pdo, 'exports', 'user_id', 'INTEGER');
        add_column_if_missing($pdo, 'exports', 'include_pii', 'INTEGER NOT NULL DEFAULT 0');

        $pdo->exec('CREATE TABLE IF NOT EXISTS suppressions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            hcp_id TEXT NOT NULL UNIQUE,
            reason TEXT NOT NULL,
            source TEXT NOT NULL DEFAULT "manual",
            created_at INTEGER NOT NULL,
            created_by INTEGER
        );');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_audit_ts ON audit_log(ts);');

        // Roles: the old "user" role becomes "editor" (same abilities it had before).
        $pdo->exec("UPDATE users SET role = 'editor' WHERE role = 'user'");
        $pdo->exec('PRAGMA user_version = 2');
        $version = 2;
        // Existing HCPs get tiers and deciles straight away.
        if (isset($GLOBALS['CONFIG']) && is_array($GLOBALS['CONFIG'])) refresh_derived($pdo, $GLOBALS['CONFIG'], get_settings($pdo));
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
    // The self-test points uploads at a throwaway directory so it never touches real import files.
    $override = $GLOBALS['UPLOAD_DIR_OVERRIDE'] ?? null;
    $dir = is_string($override) && $override !== '' ? $override : (__DIR__ . DIRECTORY_SEPARATOR . 'litehcp_uploads');
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $ht = $dir . DIRECTORY_SEPARATOR . '.htaccess';
    if (!file_exists($ht)) @file_put_contents($ht, "Deny from all\n");
    $idx = $dir . DIRECTORY_SEPARATOR . 'index.html';
    if (!file_exists($idx)) @file_put_contents($idx, "<!doctype html><meta charset=\"utf-8\"><title>Forbidden</title>Forbidden\n");
    return $dir;
}


/*
====================================================================================================
Roles + permissions
====================================================================================================
viewer: read everything except PII · editor: + build audiences, rules, imports and non-PII exports
admin: + settings, users, suppression list, sample data, deletes, PII export (when enabled)
*/

function user_role(): string {
    $u = current_user();
    $r = (string)($u['role'] ?? '');
    if ($r === 'user') return 'editor';
    return in_array($r, ['admin', 'editor', 'viewer'], true) ? $r : 'viewer';
}

function can(string $perm): bool {
    if (!current_user()) return false;
    $role = user_role();
    $matrix = [
        'view' => ['viewer', 'editor', 'admin'],
        'edit' => ['editor', 'admin'],
        'export' => ['editor', 'admin'],
        'admin' => ['admin'],
    ];
    return in_array($role, $matrix[$perm] ?? [], true);
}

function require_perm(string $perm): void {
    require_login();
    if (!can($perm)) {
        http_response_code(403);
        flash('danger', 'Your role (' . user_role() . ') cannot do that. Ask an admin.');
        redirect('?action=dashboard');
    }
}

function role_label(string $role): string {
    return match ($role) { 'admin' => 'Admin', 'editor' => 'Editor', 'viewer' => 'Viewer', 'user' => 'Editor', default => ucfirst($role) };
}

/*
====================================================================================================
Labels + formatting (the UI speaks in these words, never in column names)
====================================================================================================
*/

function field_labels(): array {
    return [
        'hcp_id' => 'HCP ID', 'npi' => 'NPI', 'name' => 'Full name', 'email' => 'Email',
        'specialty' => 'Specialty', 'region' => 'Region', 'organization' => 'Organization', 'role' => 'Role',
        'consent_email' => 'Email consent', 'consent_web' => 'Web consent', 'last_activity_ts' => 'Last activity',
        'imports_count' => 'Engagement count', 'persona' => 'Persona', 'priority_score' => 'Priority score',
        'priority_decile' => 'Priority decile', 'tier' => 'Tier', 'compliance_flag' => 'Cleared to contact',
        'confidence_score' => 'Data confidence', 'planned_calls' => 'Planned calls', 'created_at' => 'Added',
        'tags' => 'Tags',
    ];
}

function custom_label(string $name): string {
    $s = str_replace('_', ' ', $name);
    $s = preg_replace('/\b(kol|npi|hcp|id|crm|roi)\b/i', '§$1§', $s) ?? $s;
    $s = preg_replace_callback('/§([a-z]+)§/i', fn($m) => strtoupper($m[1]), $s) ?? $s;
    return ucfirst($s);
}

function label_for(string $field): string {
    $l = field_labels();
    if (isset($l[$field])) return $l[$field];
    if (str_starts_with($field, 'custom.')) return custom_label(substr($field, 7));
    return custom_label($field);
}

function fmt_date(?int $ts): string { return $ts ? date('j M Y', $ts) : '—'; }
function fmt_datetime(?int $ts): string { return $ts ? date('j M Y, H:i', $ts) : '—'; }
function fmt_ago(?int $ts): string {
    if (!$ts) return '—';
    $d = time() - $ts;
    if ($d < 60) return 'just now';
    if ($d < 3600) return (int)floor($d / 60) . ' min ago';
    if ($d < 86400) return (int)floor($d / 3600) . ' h ago';
    if ($d < 86400 * 7) return (int)floor($d / 86400) . ' d ago';
    return date('j M Y', $ts);
}
function fmt_int($n): string { return number_format((float)$n, 0); }
function fmt_score($n): string { $f = (float)$n; return (abs($f - round($f)) < 0.05) ? (string)(int)round($f) : number_format($f, 1); }
function fmt_money(float $v): string {
    $sym = (string)($GLOBALS['CONFIG']['currencySymbol'] ?? '$');
    return $sym . number_format($v, $v < 100 ? 2 : 0);
}
function plural(int $n, string $one, string $many): string { return fmt_int($n) . ' ' . ($n === 1 ? $one : $many); }

function yes_no($v): string { return ((int)$v) === 1 ? 'Yes' : 'No'; }

/*
====================================================================================================
Value detection (import)
====================================================================================================
*/

// NPI check digit: Luhn over "80840" + first 9 digits (CMS NPI standard).
function npi_is_valid(string $npi): bool {
    if (!preg_match('/^\d{10}$/', $npi)) return false;
    $digits = '80840' . substr($npi, 0, 9);
    $sum = 0;
    $double = true;
    for ($i = strlen($digits) - 1; $i >= 0; $i--) {
        $d = (int)$digits[$i];
        if ($double) { $d *= 2; if ($d > 9) $d -= 9; }
        $sum += $d;
        $double = !$double;
    }
    return ((10 - ($sum % 10)) % 10) === (int)$npi[9];
}

// Dates: Unix seconds or milliseconds, ISO 8601, Y-m-d, US m/d/Y, d.m.Y. Returns a Unix timestamp or null.
function parse_date_value($v): ?int {
    if ($v === null) return null;
    if (is_int($v)) return $v;
    $t = trim((string)$v);
    if ($t === '') return null;
    if (preg_match('/^\d{9,10}$/', $t)) return (int)$t;
    if (preg_match('/^\d{13}$/', $t)) return intdiv((int)$t, 1000);
    foreach (['Y-m-d', 'Y-m-d H:i', 'Y-m-d H:i:s', 'Y/m/d', 'm/d/Y', 'm/d/Y H:i', 'n/j/Y', 'd.m.Y', 'd.m.Y H:i'] as $fmt) {
        $d = DateTime::createFromFormat('!' . $fmt, $t);
        $errors = DateTime::getLastErrors();
        if ($d instanceof DateTime && (!is_array($errors) || ((int)$errors['warning_count'] === 0 && (int)$errors['error_count'] === 0))) {
            return $d->getTimestamp();
        }
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}T/', $t)) {
        $ts = strtotime($t);
        if ($ts !== false) return $ts;
    }
    return null;
}

function boolish_known($v): bool {
    if (is_bool($v) || is_int($v)) return true;
    $t = strtolower(trim((string)$v));
    return $t === '' || in_array($t, ['1','true','yes','y','on','x','opt-in','opt in','opted in','optin','granted','subscribed','consented','0','false','no','n','off','opt-out','opt out','opted out','optout','denied','unsubscribed','withdrawn','none'], true);
}

/*
====================================================================================================
Tiers + deciles
====================================================================================================
*/

function tier_cutoffs(array $CONFIG, array $settings): array {
    $c = is_array($settings['tier_cutoffs'] ?? null) ? $settings['tier_cutoffs'] : $CONFIG['tierCutoffs'];
    return ['A' => (float)($c['A'] ?? 80), 'B' => (float)($c['B'] ?? 50)];
}

function planned_calls_map(array $CONFIG, array $settings): array {
    $c = is_array($settings['planned_calls'] ?? null) ? $settings['planned_calls'] : $CONFIG['plannedCallsPerTier'];
    return ['A' => (int)($c['A'] ?? 6), 'B' => (int)($c['B'] ?? 3), 'C' => (int)($c['C'] ?? 1)];
}

function compute_tier(float $score, array $cut): string {
    return $score >= $cut['A'] ? 'A' : ($score >= $cut['B'] ? 'B' : 'C');
}

// Recomputes tier (from priority score) and priority decile (NTILE over the whole universe; 10 = top 10%).
function refresh_derived(PDO $pdo, array $CONFIG, array $settings): void {
    $cut = tier_cutoffs($CONFIG, $settings);
    $pdo->prepare("UPDATE profiles SET tier = CASE WHEN priority_score >= :a THEN 'A' WHEN priority_score >= :b THEN 'B' ELSE 'C' END")
        ->execute([':a' => $cut['A'], ':b' => $cut['B']]);
    try {
        $pdo->exec('DROP TABLE IF EXISTS temp._decile');
        $pdo->exec('CREATE TEMP TABLE _decile (id INTEGER PRIMARY KEY, d INTEGER)');
        $pdo->exec('INSERT INTO _decile (id, d) SELECT id, NTILE(10) OVER (ORDER BY priority_score ASC, id ASC) FROM profiles');
        $pdo->exec('UPDATE profiles SET priority_decile = (SELECT d FROM _decile WHERE _decile.id = profiles.id)');
        $pdo->exec('DROP TABLE IF EXISTS temp._decile');
    } catch (Throwable $e) {
        // SQLite older than 3.25 has no window functions; deciles stay empty, everything else works.
    }
}

/*
====================================================================================================
Suppression (absolute)
====================================================================================================
*/

function suppression_sql(PDO $pdo): string {
    $personas = (array)($GLOBALS['CONFIG']['suppressPersonas'] ?? ['Do Not Contact']);
    $quoted = $personas ? implode(',', array_map(fn($p) => $pdo->quote((string)$p), $personas)) : "''";
    return "(COALESCE(persona,'') NOT IN ({$quoted}) AND hcp_id NOT IN (SELECT hcp_id FROM suppressions))";
}

function suppression_reason(PDO $pdo, array $profile): ?string {
    $personas = (array)($GLOBALS['CONFIG']['suppressPersonas'] ?? ['Do Not Contact']);
    $st = $pdo->prepare('SELECT reason FROM suppressions WHERE hcp_id = :h');
    $st->execute([':h' => (string)$profile['hcp_id']]);
    $r = $st->fetchColumn();
    if ($r !== false) return 'On the suppression list: ' . (string)$r;
    if (in_array((string)($profile['persona'] ?? ''), $personas, true)) return 'Persona "' . (string)$profile['persona'] . '" set by an enrichment rule';
    return null;
}

function suppressed_count(PDO $pdo): int {
    return (int)$pdo->query('SELECT COUNT(*) FROM profiles WHERE NOT ' . suppression_sql($pdo))->fetchColumn();
}

/*
====================================================================================================
Audience definitions (visual builder) → safe SQL
====================================================================================================
Definitions are validated against a fixed field/operator allowlist and every value is quoted with
PDO::quote() or cast to a number, so the compiled SQL never contains user-written SQL.
*/

function builder_field_specs(PDO $pdo): array {
    $specs = [
        'specialty' => ['label' => 'Specialty', 'type' => 'set', 'column' => 'specialty'],
        'region' => ['label' => 'Region', 'type' => 'set', 'column' => 'region'],
        'role' => ['label' => 'Role', 'type' => 'set', 'column' => 'role'],
        'organization' => ['label' => 'Organization', 'type' => 'set', 'column' => 'organization'],
        'persona' => ['label' => 'Persona', 'type' => 'set', 'column' => 'persona'],
        'tier' => ['label' => 'Tier', 'type' => 'set', 'column' => 'tier'],
        'reachable' => ['label' => 'Reachable by', 'type' => 'channels'],
        'priority_score' => ['label' => 'Priority score', 'type' => 'number', 'column' => 'priority_score'],
        'priority_decile' => ['label' => 'Priority decile (10 = top 10%)', 'type' => 'number', 'column' => 'priority_decile'],
        'confidence_score' => ['label' => 'Data confidence', 'type' => 'number', 'column' => 'confidence_score'],
        'imports_count' => ['label' => 'Engagement count', 'type' => 'number', 'column' => 'imports_count'],
        'last_activity_days' => ['label' => 'Last activity', 'type' => 'days'],
        'tags' => ['label' => 'Tag', 'type' => 'tag'],
        'rule' => ['label' => 'Enrichment rule', 'type' => 'rule'],
    ];
    foreach (custom_field_names($pdo) as $name => $kind) {
        $specs['custom.' . $name] = ['label' => custom_label($name) . ' (custom)', 'type' => $kind === 'number' ? 'number' : 'text', 'custom' => $name];
    }
    return $specs;
}

function builder_ops(): array {
    return [
        'set' => ['in' => 'is any of', 'not_in' => 'is none of'],
        'channels' => ['any' => 'any of', 'all' => 'all of'],
        'number' => ['>=' => 'at least', '<=' => 'at most', '=' => 'exactly'],
        'days' => ['within' => 'within the last (days)', 'older' => 'not within the last (days)'],
        'tag' => ['has' => 'has tag', 'lacks' => 'does not have tag'],
        'rule' => ['applied' => 'was applied', 'not_applied' => 'was not applied'],
        'text' => ['=' => 'is', '!=' => 'is not', 'contains' => 'contains'],
    ];
}

function custom_field_names(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    if (!sqlite_supports_json($pdo)) return $cache;
    try {
        $rows = $pdo->query("SELECT j.key AS k, j.value AS v FROM (SELECT metadata FROM profiles LIMIT 500) p, json_each(p.metadata, '$.custom') j")->fetchAll();
    } catch (Throwable $e) { return $cache; }
    foreach ($rows as $r) {
        $k = (string)$r['k'];
        if (!preg_match('/^[a-z_][a-z0-9_]*$/', $k)) continue;
        $isNum = is_numeric($r['v']);
        if (!isset($cache[$k])) $cache[$k] = $isNum ? 'number' : 'text';
        elseif (!$isNum) $cache[$k] = 'text';
    }
    ksort($cache);
    return $cache;
}

function builder_options(PDO $pdo, array $specs): array {
    $opts = [];
    foreach (['specialty', 'region', 'role', 'organization', 'persona'] as $col) {
        $opts[$col] = array_map(fn($r) => (string)$r['v'], $pdo->query("SELECT {$col} AS v, COUNT(*) c FROM profiles WHERE {$col} IS NOT NULL AND {$col} != '' GROUP BY {$col} ORDER BY c DESC LIMIT 60")->fetchAll());
    }
    $opts['tier'] = ['A', 'B', 'C'];
    $opts['reachable'] = ['email', 'web'];
    $tags = [];
    if (sqlite_supports_json($pdo)) {
        try {
            foreach ($pdo->query("SELECT DISTINCT j.value AS v FROM profiles p, json_each(p.metadata, '$.tags') j ORDER BY 1 LIMIT 80")->fetchAll() as $r) $tags[] = (string)$r['v'];
        } catch (Throwable $e) { /* ignore */ }
    }
    $opts['tags'] = $tags;
    $opts['rule'] = array_map(fn($r) => ['id' => (int)$r['id'], 'name' => (string)$r['name']], $pdo->query('SELECT id, name FROM rules ORDER BY priority DESC, id ASC')->fetchAll());
    return $opts;
}

function validate_definition(PDO $pdo, $raw): array {
    if (!is_array($raw)) return [false, 'Audience definition is not valid.'];
    $match = strtolower((string)($raw['match'] ?? 'all')) === 'any' ? 'any' : 'all';
    $specs = builder_field_specs($pdo);
    $ops = builder_ops();
    $out = [];
    foreach ((array)($raw['conditions'] ?? []) as $c) {
        if (!is_array($c)) continue;
        $field = (string)($c['field'] ?? '');
        $op = (string)($c['op'] ?? '');
        $value = $c['value'] ?? null;
        if (!isset($specs[$field])) {
            // Custom fields may not be present in the sampled rows; accept a well-formed custom name.
            if (preg_match('/^custom\.([a-z_][a-z0-9_]*)$/', $field, $mm)) {
                $specs[$field] = ['label' => custom_label($mm[1]), 'type' => is_numeric($value) ? 'number' : 'text', 'custom' => $mm[1]];
            } else {
                return [false, 'Unknown field: ' . $field];
            }
        }
        $type = $specs[$field]['type'];
        if (!isset($ops[$type][$op])) return [false, 'Unsupported condition for ' . $specs[$field]['label']];
        if (in_array($type, ['set', 'channels'], true)) {
            $vals = array_values(array_filter(array_map(fn($v) => trim((string)$v), (array)$value), fn($v) => $v !== ''));
            if ($type === 'channels') $vals = array_values(array_intersect($vals, ['email', 'web']));
            if (!$vals) continue; // an empty pick is ignored rather than matching nothing
            $value = $vals;
        } elseif (in_array($type, ['number', 'days'], true)) {
            if (!is_numeric($value)) return [false, $specs[$field]['label'] . ' needs a number.'];
            $value = 0 + $value;
        } elseif ($type === 'rule') {
            $value = (int)(is_array($value) ? ($value[0] ?? 0) : $value);
            if ($value <= 0) continue;
        } else {
            $value = trim((string)$value);
            if ($value === '') continue;
        }
        $out[] = ['field' => $field, 'op' => $op, 'value' => $value];
    }
    return [true, ['match' => $match, 'conditions' => $out]];
}

function compile_condition(PDO $pdo, array $c, array $specs): string {
    $field = $c['field'];
    $op = $c['op'];
    $v = $c['value'];
    $spec = $specs[$field] ?? null;
    if (!$spec && preg_match('/^custom\.([a-z_][a-z0-9_]*)$/', $field, $mm)) $spec = ['type' => is_numeric($v) ? 'number' : 'text', 'custom' => $mm[1]];
    if (!$spec) return '1=0';
    $type = $spec['type'];
    $num = fn($x) => (string)(0 + $x);
    $col = isset($spec['custom'])
        ? ($type === 'number' ? "CAST(json_extract(metadata, '$.custom." . $spec['custom'] . "') AS REAL)" : "json_extract(metadata, '$.custom." . $spec['custom'] . "')")
        : ($spec['column'] ?? '');
    switch ($type) {
        case 'set':
            $list = implode(',', array_map(fn($x) => $pdo->quote((string)$x), (array)$v));
            return $op === 'not_in' ? "COALESCE({$col},'') NOT IN ({$list})" : "COALESCE({$col},'') IN ({$list})";
        case 'channels':
            $parts = [];
            foreach ((array)$v as $ch) $parts[] = $ch === 'email' ? 'consent_email = 1' : 'consent_web = 1';
            return '(' . implode($op === 'all' ? ' AND ' : ' OR ', $parts) . ')';
        case 'number':
            $sqlOp = in_array($op, ['>=', '<=', '='], true) ? $op : '>=';
            return "{$col} {$sqlOp} " . $num($v);
        case 'days':
            $cut = time() - (int)round((float)$v * 86400);
            return $op === 'older' ? "(last_activity_ts IS NULL OR last_activity_ts < {$cut})" : "last_activity_ts >= {$cut}";
        case 'tag':
            $q = $pdo->quote((string)$v);
            $has = sqlite_supports_json($pdo)
                ? "EXISTS (SELECT 1 FROM json_each(profiles.metadata, '$.tags') t WHERE t.value = {$q})"
                : 'metadata LIKE ' . $pdo->quote('%"' . str_replace(['%', '_'], '', (string)$v) . '"%');
            return $op === 'lacks' ? "NOT {$has}" : $has;
        case 'rule':
            $pattern = $pdo->quote('%"applied_rule_ids_csv"%,' . (int)$v . ',%');
            return $op === 'not_applied' ? "metadata NOT LIKE {$pattern}" : "metadata LIKE {$pattern}";
        case 'text':
            $q = $pdo->quote((string)$v);
            if ($op === 'contains') return "{$col} LIKE " . $pdo->quote('%' . (string)$v . '%');
            return $op === '!=' ? "COALESCE({$col},'') != {$q}" : "{$col} = {$q}";
    }
    return '1=0';
}

// $upTo limits compilation to the first N conditions (used for running counts in the builder).
function compile_definition(PDO $pdo, array $def, ?int $upTo = null): string {
    $specs = builder_field_specs($pdo);
    $conds = (array)($def['conditions'] ?? []);
    if ($upTo !== null) $conds = array_slice($conds, 0, $upTo);
    if (!$conds) return '1=1';
    $parts = array_map(fn($c) => '(' . compile_condition($pdo, $c, $specs) . ')', $conds);
    return implode(($def['match'] ?? 'all') === 'any' ? ' OR ' : ' AND ', $parts);
}

function definition_summary(PDO $pdo, array $def): string {
    $specs = builder_field_specs($pdo);
    $ops = builder_ops();
    $rules = [];
    foreach ($pdo->query('SELECT id, name FROM rules')->fetchAll() as $r) $rules[(int)$r['id']] = (string)$r['name'];
    $bits = [];
    foreach ((array)($def['conditions'] ?? []) as $c) {
        $spec = $specs[$c['field']] ?? ['label' => label_for((string)$c['field']), 'type' => 'text'];
        $opLabel = $ops[$spec['type']][$c['op']] ?? $c['op'];
        $v = $c['value'];
        if ($spec['type'] === 'rule') $v = $rules[(int)$v] ?? ('#' . $v);
        if (is_array($v)) $v = implode(', ', $v);
        $bits[] = $spec['label'] . ' ' . $opLabel . ' ' . $v;
    }
    if (!$bits) return 'All HCPs';
    return implode(($def['match'] ?? 'all') === 'any' ? ' · or ' : ' · ', $bits);
}

function segment_allowed_fields(): array {
    return ['hcp_id','npi','name','email','specialty','region','organization','role','consent_email','consent_web','last_activity_ts','imports_count','persona','tier','priority_decile','priority_score','compliance_flag','confidence_score','created_at'];
}

// WHERE clause for an audience. Suppression is ALWAYS appended unless the caller explicitly asks for the
// pre-suppression count (used only to display how many HCPs were excluded). Exports never pass false.
function build_segment_where(PDO $pdo, array $segment, string $mode, int $threshold, array &$params, bool $applySuppression = true): string {
    $params = [];
    $clauses = [];
    $def = json_decode_safe((string)($segment['definition_json'] ?? ''));
    if ($def && isset($def['conditions'])) {
        [$ok, $norm] = validate_definition($pdo, $def);
        if (!$ok) throw new RuntimeException((string)$norm);
        $clauses[] = '(' . compile_definition($pdo, $norm) . ')';
    } elseif (!empty($segment['sql_filter'])) {
        $filter = (string)$segment['sql_filter'];
        [$ok, $res] = validate_sql_filter($filter, segment_allowed_fields());
        if (!$ok) throw new RuntimeException((string)$res);
        if (preg_match('/\bcustom\./', $res) && !sqlite_supports_json($pdo)) {
            throw new RuntimeException('This SQLite build lacks JSON1 support; custom.* filters are unavailable. Use a rule-based audience instead.');
        }
        $clauses[] = '(' . translate_custom_fields_sql($res) . ')';
        if ($mode === 'strict') {
            $clauses[] = 'confidence_score >= :th';
            $params[':th'] = $threshold;
        }
    } else {
        $ids = json_decode_safe((string)($segment['rule_ids'] ?? '[]'));
        if (!is_array($ids) || !$ids) {
            $clauses[] = '1=1';
        } else {
            $i = 0;
            foreach ($ids as $rid) {
                $i++;
                $k = ':rid' . $i;
                $clauses[] = "metadata LIKE {$k}";
                $params[$k] = '%"applied_rule_ids_csv"%,' . (int)$rid . ',%';
            }
        }
        if ($mode === 'strict') {
            $clauses[] = 'confidence_score >= :th';
            $params[':th'] = $threshold;
        }
    }
    if ($applySuppression) $clauses[] = suppression_sql($pdo);
    return $clauses ? implode(' AND ', $clauses) : '1=1';
}

function segment_mode(array $segment): string {
    $def = json_decode_safe((string)($segment['definition_json'] ?? ''));
    if ($def && isset($def['conditions'])) return 'builder';
    $plan = json_decode_safe((string)($segment['plan_json'] ?? ''));
    return in_array($plan['mode'] ?? '', ['strict', 'inclusive'], true) ? (string)$plan['mode'] : 'strict';
}

function audience_stats(PDO $pdo, array $segment, array $CONFIG, array $settings): array {
    $threshold = (int)($settings['strict_confidence_threshold'] ?? $CONFIG['confidenceStrictThreshold']);
    $mode = segment_mode($segment);
    $p1 = [];
    $p2 = [];
    try {
        $wAll = build_segment_where($pdo, $segment, $mode, $threshold, $p1, false);
        $wReady = build_segment_where($pdo, $segment, $mode, $threshold, $p2, true);
    } catch (Throwable $e) {
        return ['error' => $e->getMessage(), 'matches' => 0, 'ready' => 0, 'excluded' => 0, 'tiers' => ['A' => 0, 'B' => 0, 'C' => 0]];
    }
    $s = $pdo->prepare('SELECT COUNT(*) FROM profiles WHERE ' . $wAll);
    $s->execute($p1);
    $matches = (int)$s->fetchColumn();
    $s = $pdo->prepare("SELECT COALESCE(tier,'C') t, COUNT(*) c FROM profiles WHERE {$wReady} GROUP BY t");
    $s->execute($p2);
    $tiers = ['A' => 0, 'B' => 0, 'C' => 0];
    foreach ($s->fetchAll() as $r) $tiers[(string)$r['t']] = (int)$r['c'];
    $ready = array_sum($tiers);
    return ['matches' => $matches, 'ready' => $ready, 'excluded' => max(0, $matches - $ready), 'tiers' => $tiers, 'mode' => $mode];
}

/*
====================================================================================================
Export presets
====================================================================================================
*/

function export_preset(array $CONFIG, string $key): array {
    $presets = (array)$CONFIG['exportPresets'];
    return $presets[$key] ?? $presets['minimal'];
}

// Returns [rows (array of assoc arrays in export field order), fields]. Suppression is always applied.
function export_rows(PDO $pdo, array $CONFIG, array $settings, array $segment, string $presetKey): array {
    $preset = export_preset($CONFIG, $presetKey);
    $fields = array_values(array_filter((array)$preset['fields'], fn($f) => in_array($f, $CONFIG['allowedExportFields'], true)));
    $threshold = (int)($settings['strict_confidence_threshold'] ?? $CONFIG['confidenceStrictThreshold']);
    $params = [];
    $where = build_segment_where($pdo, $segment, segment_mode($segment), $threshold, $params, true);
    if (!empty($preset['requireEmailConsent'])) $where .= " AND consent_email = 1 AND COALESCE(email,'') != ''";
    $dbFields = array_values(array_diff($fields, ['planned_calls']));
    if (in_array('planned_calls', $fields, true) && !in_array('tier', $dbFields, true)) $dbFields[] = 'tier';
    $q = $pdo->prepare('SELECT ' . implode(',', $dbFields) . ' FROM profiles WHERE ' . $where . ' ORDER BY priority_score DESC, id ASC');
    $q->execute($params);
    $calls = planned_calls_map($CONFIG, $settings);
    $rows = [];
    while ($r = $q->fetch()) {
        $line = [];
        foreach ($fields as $f) {
            $line[$f] = $f === 'planned_calls' ? ($calls[(string)($r['tier'] ?? 'C')] ?? 0) : ($r[$f] ?? '');
        }
        $rows[] = $line;
    }
    return [$rows, $fields];
}

/*
====================================================================================================
Explainable score
====================================================================================================
*/

function explain_profile(array $p, array $rules, array $CONFIG): array {
    $w = $CONFIG['priorityWeights'];
    $consent = (((int)($p['consent_email'] ?? 0) ? 1 : 0) + ((int)($p['consent_web'] ?? 0) ? 1 : 0)) / 2.0;
    $recency = 0.0;
    $ts = to_int_or_null($p['last_activity_ts'] ?? null);
    if ($ts !== null && $ts > 0) {
        $days = max(0.0, (time() - $ts) / 86400.0);
        $recency = max(0.0, min(1.0, 1.0 - ($days / max(1.0, (float)$CONFIG['recencyWindowDays']))));
    }
    $engagement = max(0.0, min(1.0, ((int)($p['imports_count'] ?? 0)) / 10.0));
    $components = [
        ['label' => 'Consent', 'detail' => 'Email ' . yes_no($p['consent_email'] ?? 0) . ', web ' . yes_no($p['consent_web'] ?? 0), 'weight' => (float)$w['consent'], 'points' => round($w['consent'] * $consent * 100, 1)],
        ['label' => 'Recency', 'detail' => $ts ? ('Last active ' . fmt_date($ts) . ' (' . (int)$CONFIG['recencyWindowDays'] . '-day window)') : 'No activity date', 'weight' => (float)$w['recency'], 'points' => round($w['recency'] * $recency * 100, 1)],
        ['label' => 'Engagement', 'detail' => (int)($p['imports_count'] ?? 0) . ' of 10 counted', 'weight' => (float)$w['engagement'], 'points' => round($w['engagement'] * $engagement * 100, 1)],
    ];
    $base = compute_base_priority($p, $CONFIG);
    $work = $p;
    $work['priority_score'] = $base;
    $work['metadata'] = json_encode_safe(reset_rule_trace(json_decode_safe((string)($p['metadata'] ?? '{}'))));
    $steps = [];
    foreach ($rules as $r) {
        $cond = json_decode_safe($r['conditions_json'] ?? '{}');
        $actions = json_decode_safe($r['actions_json'] ?? '{}');
        if (!eval_conditions($work, $cond)) continue;
        $before = (float)$work['priority_score'];
        $persona = $work['persona'] ?? null;
        $work = apply_actions($work, $actions, (int)$r['id'], (string)$r['name']);
        $steps[] = ['rule' => (string)$r['name'], 'from' => $before, 'to' => (float)$work['priority_score'], 'persona' => ($work['persona'] ?? null) !== $persona ? $work['persona'] : null];
        if (!(bool)($cond['continue_on_match'] ?? false)) break;
    }
    $final = max(0.0, min(100.0, (float)$work['priority_score']));
    return ['components' => $components, 'base' => $base, 'steps' => $steps, 'final' => $final];
}


/*
====================================================================================================
Import: field catalogue, auto-matching, checks, chunked processing
====================================================================================================
*/

function import_field_specs(): array {
    return [
        'hcp_id' => ['label' => 'HCP ID', 'required' => true, 'kind' => 'text', 'synonyms' => ['hcp_id', 'hcpid', 'id', 'hcp', 'customer_id', 'account_id', 'veeva_id', 'external_id', 'record_id']],
        'npi' => ['label' => 'NPI', 'required' => false, 'kind' => 'npi', 'synonyms' => ['npi', 'npi_number', 'npi_id', 'national_provider_identifier']],
        'name' => ['label' => 'Full name', 'required' => true, 'kind' => 'text', 'synonyms' => ['name', 'full_name', 'fullname', 'hcp_name', 'provider_name', 'physician_name', 'contact_name']],
        'name_first' => ['label' => 'First name (combined into full name)', 'required' => false, 'kind' => 'text', 'synonyms' => ['first_name', 'firstname', 'given_name', 'provider_first_name']],
        'name_last' => ['label' => 'Last name (combined into full name)', 'required' => false, 'kind' => 'text', 'synonyms' => ['last_name', 'lastname', 'surname', 'family_name', 'provider_last_name']],
        'email' => ['label' => 'Email', 'required' => false, 'kind' => 'text', 'synonyms' => ['email', 'email_address', 'e_mail', 'mail']],
        'specialty' => ['label' => 'Specialty', 'required' => false, 'kind' => 'text', 'synonyms' => ['specialty', 'speciality', 'primary_specialty', 'specialty_name', 'taxonomy_description']],
        'region' => ['label' => 'Region', 'required' => false, 'kind' => 'text', 'synonyms' => ['region', 'territory', 'area', 'state', 'zone']],
        'organization' => ['label' => 'Organization', 'required' => false, 'kind' => 'text', 'synonyms' => ['organization', 'organisation', 'org', 'organization_name', 'practice', 'hospital', 'account', 'affiliation', 'institution']],
        'role' => ['label' => 'Role', 'required' => false, 'kind' => 'text', 'synonyms' => ['role', 'profession', 'credential', 'title', 'provider_type', 'hcp_type']],
        'consent_email' => ['label' => 'Email consent', 'required' => false, 'kind' => 'bool', 'synonyms' => ['consent_email', 'email_consent', 'email_opt_in', 'email_optin', 'email_permission']],
        'consent_web' => ['label' => 'Web consent', 'required' => false, 'kind' => 'bool', 'synonyms' => ['consent_web', 'web_consent', 'web_opt_in', 'digital_consent', 'web_permission']],
        'last_activity_ts' => ['label' => 'Last activity', 'required' => false, 'kind' => 'date', 'synonyms' => ['last_activity_ts', 'last_activity', 'last_activity_date', 'last_engagement', 'last_contact', 'last_contact_date', 'last_seen']],
        'imports_count' => ['label' => 'Engagement count', 'required' => false, 'kind' => 'int', 'synonyms' => ['imports_count', 'engagement_count', 'engagements', 'interactions', 'touches', 'touchpoints']],
    ];
}

function normalize_header(string $h): string {
    return trim((string)preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($h))), '_');
}

// Suggests a target for every CSV column: an internal field, a new custom field, or skip.
function auto_map_columns(array $headers): array {
    $specs = import_field_specs();
    $taken = [];
    $map = [];
    $match = [];
    foreach ($headers as $hh) {
        $n = normalize_header((string)$hh);
        $target = null;
        $how = 'new';
        foreach ($specs as $field => $spec) {
            if (isset($taken[$field])) continue;
            if ($n === $field) { $target = $field; $how = 'exact'; break; }
        }
        if ($target === null) {
            foreach ($specs as $field => $spec) {
                if (isset($taken[$field])) continue;
                if (in_array($n, $spec['synonyms'], true)) { $target = $field; $how = 'synonym'; break; }
            }
        }
        if ($target !== null) { $taken[$target] = true; $map[$hh] = $target; $match[$hh] = $how; continue; }
        $map[$hh] = ($n !== '' && preg_match('/^[a-z_]/', $n)) ? 'custom' : 'skip';
        $match[$hh] = $map[$hh] === 'custom' ? 'new' : 'skip';
    }
    // A full name can be assembled from first + last; drop the pair if a real name column exists.
    if (isset($taken['name'])) {
        foreach ($map as $col => $t) if (in_array($t, ['name_first', 'name_last'], true)) { $map[$col] = 'custom'; $match[$col] = 'new'; }
    }
    return ['map' => $map, 'match' => $match];
}

// One streaming pass: row count, empty count and first example per column.
function csv_column_profile(string $path, int $maxRows = 200000): array {
    $fh = csv_open($path);
    if (!$fh) return ['rows' => 0, 'headers' => [], 'empty' => [], 'example' => []];
    $headers = csv_read_headers($fh);
    $empty = array_fill_keys($headers, 0);
    $example = array_fill_keys($headers, '');
    $rows = 0;
    while (($r = fgetcsv($fh, null, ',', '"', '\\')) !== false && $rows < $maxRows) {
        if (!is_array($r) || $r === [null]) continue;
        $rows++;
        foreach ($headers as $i => $hh) {
            $v = trim((string)($r[$i] ?? ''));
            if ($v === '') { $empty[$hh]++; continue; }
            if ($example[$hh] === '') $example[$hh] = $v;
        }
    }
    fclose($fh);
    return ['rows' => $rows, 'headers' => $headers, 'empty' => $empty, 'example' => $example];
}

// Converts the column-oriented choices into the stored mapping structures used by the importer.
function mapping_from_choices(array $choices, array $customNames): array {
    $mapping = [];
    foreach (array_keys(import_field_specs()) as $f) $mapping[$f] = '';
    $customMap = [];
    foreach ($choices as $col => $target) {
        if ($target === 'skip' || $target === '') continue;
        if ($target === 'custom') {
            $name = strtolower(trim((string)($customNames[$col] ?? normalize_header((string)$col))));
            $name = trim((string)preg_replace('/[^a-z0-9_]/', '_', $name), '_');
            if ($name === '' || !preg_match('/^[a-z_]/', $name) || isset($mapping[$name])) continue;
            $customMap[$col] = $name;
            continue;
        }
        if (array_key_exists($target, $mapping) && $mapping[$target] === '') $mapping[$target] = (string)$col;
    }
    if ($mapping['name'] === '' && $mapping['name_first'] !== '' && $mapping['name_last'] !== '') {
        $mapping['name'] = '__concat__:' . $mapping['name_first'] . '|' . $mapping['name_last'];
    }
    unset($mapping['name_first'], $mapping['name_last']);
    return [$mapping, $customMap];
}

function mapped_value(array $row, array $idx, string $col) {
    if ($col === '') return null;
    if (str_starts_with($col, '__concat__:')) {
        $parts = explode('|', substr($col, 11));
        $vals = [];
        foreach ($parts as $pc) { $i = $idx[$pc] ?? null; if ($i !== null) { $v = trim((string)($row[$i] ?? '')); if ($v !== '') $vals[] = $v; } }
        return $vals ? implode(' ', $vals) : '';
    }
    $i = $idx[$col] ?? null;
    return $i === null ? null : ($row[$i] ?? null);
}

// Validation scan shown on the "Check data" step. Nothing is written.
function import_check_scan(PDO $pdo, string $path, array $mapping): array {
    $fh = csv_open($path);
    $out = ['rows' => 0, 'missing_required' => 0, 'duplicate_ids' => 0, 'invalid_npi' => 0, 'bad_dates' => 0, 'unknown_consent' => 0, 'existing' => 0, 'new' => 0, 'examples' => []];
    if (!$fh) return $out;
    $headers = csv_read_headers($fh);
    $idx = [];
    foreach ($headers as $i => $hh) $idx[$hh] = $i;
    $seen = [];
    $ids = [];
    $line = 1;
    while (($r = fgetcsv($fh, null, ',', '"', '\\')) !== false) {
        if (!is_array($r) || $r === [null]) continue;
        $line++;
        $out['rows']++;
        $id = trim((string)mapped_value($r, $idx, (string)($mapping['hcp_id'] ?? '')));
        $name = trim((string)mapped_value($r, $idx, (string)($mapping['name'] ?? '')));
        if ($id === '' || $name === '') { $out['missing_required']++; if (count($out['examples']) < 8) $out['examples'][] = ['row' => $line, 'issue' => 'Missing HCP ID or name']; continue; }
        if (isset($seen[$id])) { $out['duplicate_ids']++; if (count($out['examples']) < 8) $out['examples'][] = ['row' => $line, 'issue' => 'Duplicate HCP ID ' . $id . ' (later row wins)']; }
        $seen[$id] = true;
        $ids[] = $id;
        if (($mapping['npi'] ?? '') !== '') {
            $npi = preg_replace('/\D/', '', (string)mapped_value($r, $idx, (string)$mapping['npi'])) ?? '';
            if ($npi !== '' && !npi_is_valid($npi)) { $out['invalid_npi']++; if (count($out['examples']) < 8) $out['examples'][] = ['row' => $line, 'issue' => 'NPI ' . $npi . ' fails the check digit']; }
        }
        if (($mapping['last_activity_ts'] ?? '') !== '') {
            $dv = trim((string)mapped_value($r, $idx, (string)$mapping['last_activity_ts']));
            if ($dv !== '' && parse_date_value($dv) === null) { $out['bad_dates']++; if (count($out['examples']) < 8) $out['examples'][] = ['row' => $line, 'issue' => 'Unreadable date "' . $dv . '"']; }
        }
        foreach (['consent_email', 'consent_web'] as $cf) {
            if (($mapping[$cf] ?? '') === '') continue;
            $cv = mapped_value($r, $idx, (string)$mapping[$cf]);
            if (!boolish_known($cv)) { $out['unknown_consent']++; if (count($out['examples']) < 8) $out['examples'][] = ['row' => $line, 'issue' => 'Consent value "' . (string)$cv . '" read as No']; }
        }
    }
    fclose($fh);
    $unique = array_values(array_unique($ids));
    foreach (array_chunk($unique, 400) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        $st = $pdo->prepare("SELECT COUNT(*) FROM profiles WHERE hcp_id IN ({$in})");
        $st->execute($chunk);
        $out['existing'] += (int)$st->fetchColumn();
    }
    $out['new'] = count($unique) - $out['existing'];
    return $out;
}

// Processes rows until the end of the file or $maxSeconds. Used by the wizard (via fetch), the no-JS
// fallback, "Load sample data" and the self-test.
function import_process_chunk(PDO $pdo, array $CONFIG, int $id, ?int $userId, float $maxSeconds): array {
    $imp = $pdo->prepare('SELECT * FROM imports WHERE id = :id');
    $imp->execute([':id' => $id]);
    $import = $imp->fetch();
    if (!$import) throw new RuntimeException('Import not found.');
    $path = ensure_upload_dir() . DIRECTORY_SEPARATOR . 'import_' . $id . '.csv';
    if (!file_exists($path)) throw new RuntimeException('Uploaded file missing on disk.');

    $errorsObj = json_decode_safe((string)($import['errors_json'] ?? '{}'));
    $mapping = is_array($errorsObj['mapping'] ?? null) ? $errorsObj['mapping'] : [];
    $customMap = is_array($errorsObj['custom_map'] ?? null) ? $errorsObj['custom_map'] : [];
    $impute = is_array($errorsObj['impute'] ?? null) ? $errorsObj['impute'] : [];
    $stats = is_array($errorsObj['stats'] ?? null) ? $errorsObj['stats'] : [];
    $mappingConfidence01 = (float)($errorsObj['mapping_confidence01'] ?? 0.7);
    if (!$mapping || empty($mapping['hcp_id']) || empty($mapping['name'])) throw new RuntimeException('Columns are not matched yet.');

    $total = (int)($import['rows_total'] ?? 0);
    $already = (int)($import['rows_imported'] ?? 0);
    if (!empty($errorsObj['finished_at'])) {
        return ['processed' => 0, 'inserted' => 0, 'updated' => 0, 'done' => $already, 'total' => $total, 'finished' => true, 'warnings' => count((array)($errorsObj['import_errors'] ?? []))];
    }

    $rules = $pdo->query('SELECT * FROM rules ORDER BY priority DESC, id ASC')->fetchAll();
    $start = microtime(true);
    $commitEvery = max(1, (int)$CONFIG['importCommitEvery']);

    $fh = csv_open($path);
    if (!$fh) throw new RuntimeException('Could not open the file for import.');
    $headers = csv_read_headers($fh);
    $idx = [];
    foreach ($headers as $i => $hh) $idx[$hh] = $i;

    $skipped = 0;
    while ($skipped < $already && ($row = fgetcsv($fh, null, ',', '"', '\\')) !== false) { if (is_array($row) && $row !== [null]) $skipped++; }

    $inserted = 0;
    $updated = 0;
    $errors = is_array($errorsObj['import_errors'] ?? null) ? $errorsObj['import_errors'] : [];
    $processed = 0;
    $hitTimeLimit = false;
    $di = $CONFIG['defaultImputation'];

    $pdo->beginTransaction();
    $sinceCommit = 0;
    try {
        while (($row = fgetcsv($fh, null, ',', '"', '\\')) !== false) {
            if (!is_array($row) || $row === [null]) continue;
            $processed++;
            $rowNo = $already + $processed + 1;
            $imputedFlags = [];
            $p = [
                'hcp_id' => '', 'npi' => null, 'name' => '', 'email' => null, 'specialty' => null, 'region' => null,
                'organization' => null, 'role' => null, 'consent_email' => 0, 'consent_web' => 0,
                'last_activity_ts' => null, 'imports_count' => 0, 'persona' => null, 'priority_score' => 0,
                'compliance_flag' => 0, 'metadata' => '{}', 'confidence_score' => 0, 'created_at' => time(),
            ];
            foreach ($mapping as $field => $col) {
                if ($col === '' || !array_key_exists($field, $p)) continue;
                $p[$field] = mapped_value($row, $idx, (string)$col);
            }

            $p['consent_email'] = parse_boolish(apply_imputation('consent_email', $p['consent_email'], $impute['consent_email'] ?? ($di['consent_email'] ?? []), $stats, $imputedFlags));
            $p['consent_web'] = parse_boolish(apply_imputation('consent_web', $p['consent_web'], $impute['consent_web'] ?? ($di['consent_web'] ?? []), $stats, $imputedFlags));
            $p['imports_count'] = (int)apply_imputation('imports_count', $p['imports_count'], $impute['imports_count'] ?? ($di['imports_count'] ?? []), $stats, $imputedFlags);
            $p['hcp_id'] = trim((string)apply_imputation('hcp_id', $p['hcp_id'], ['strategy' => 'leave_null_flag', 'value' => null], $stats, $imputedFlags));
            $p['name'] = trim((string)apply_imputation('name', $p['name'], ['strategy' => 'leave_null_flag', 'value' => null], $stats, $imputedFlags));
            $p['email'] = apply_imputation('email', $p['email'], $impute['email'] ?? ['strategy' => 'leave_null_flag', 'value' => null], $stats, $imputedFlags);
            $p['specialty'] = apply_imputation('specialty', $p['specialty'], $impute['specialty'] ?? ($di['specialty'] ?? []), $stats, $imputedFlags);
            $p['region'] = apply_imputation('region', $p['region'], $impute['region'] ?? ($di['region'] ?? []), $stats, $imputedFlags);
            $p['organization'] = apply_imputation('organization', $p['organization'], $impute['organization'] ?? ($di['organization'] ?? []), $stats, $imputedFlags);
            $p['role'] = apply_imputation('role', $p['role'], $impute['role'] ?? ($di['role'] ?? []), $stats, $imputedFlags);
            $rawDate = apply_imputation('last_activity_ts', $p['last_activity_ts'], $impute['last_activity_ts'] ?? ($di['last_activity_ts'] ?? []), $stats, $imputedFlags);
            $p['last_activity_ts'] = parse_date_value($rawDate);
            // An imputation plan for "required"/"email" only applies to empty values; never flag a present value.
            if ($p['hcp_id'] !== '') unset($imputedFlags['hcp_id']);
            if ($p['name'] !== '') unset($imputedFlags['name']);

            $npiNote = null;
            $npiRaw = $p['npi'] !== null ? (preg_replace('/\D/', '', (string)$p['npi']) ?? '') : '';
            $p['npi'] = null;
            if ($npiRaw !== '') {
                if (npi_is_valid($npiRaw)) $p['npi'] = $npiRaw;
                else { $npiNote = $npiRaw; $errors[] = ['row' => $rowNo, 'error' => 'NPI ' . $npiRaw . ' fails the check digit; stored without NPI', 'level' => 'warning']; }
            }

            $custom = [];
            foreach ($customMap as $col => $cname) {
                $i = $idx[$col] ?? null;
                if ($i === null) continue;
                $cv = trim((string)($row[$i] ?? ''));
                if ($cv === '') continue;
                $custom[$cname] = is_numeric($cv) ? $cv + 0 : $cv;
            }

            $meta = [
                'imputed_fields' => $imputedFlags,
                'imputation_plan' => $impute,
                'last_import_id' => $id,
                'tags' => [],
                'applied_rules' => [],
                'applied_rule_ids_csv' => ',',
                'custom' => $custom,
            ];
            if ($npiNote !== null) $meta['npi_invalid'] = $npiNote;
            $p['metadata'] = json_encode_safe($meta);
            $p['confidence_score'] = compute_confidence($p, $imputedFlags, $CONFIG, $mappingConfidence01);
            $p['priority_score'] = compute_base_priority($p, $CONFIG);
            $p = enrich_profile($p, $rules, $CONFIG);

            if ($p['hcp_id'] === '' || $p['name'] === '') {
                $errors[] = ['row' => $rowNo, 'error' => 'Skipped: missing HCP ID or name', 'level' => 'error'];
            } else {
                $result = upsert_profile($pdo, $p);
                if ($result === 'insert') $inserted++;
                if ($result === 'update') $updated++;
            }

            $sinceCommit++;
            if ($sinceCommit >= $commitEvery) {
                $pdo->commit();
                $pdo->beginTransaction();
                $sinceCommit = 0;
            }
            if ((microtime(true) - $start) > $maxSeconds) { $hitTimeLimit = true; break; }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fclose($fh);
        $errId = bin2hex(random_bytes(6));
        error_line($CONFIG, 'import_failed', ['id' => $errId, 'import_id' => $id, 'message' => $e->getMessage()]);
        $errors[] = ['row' => $already + $processed + 1, 'error' => 'Import stopped (error ' . $errId . ')', 'level' => 'error'];
        $errorsObj['import_errors'] = array_slice($errors, -200);
        $pdo->prepare('UPDATE imports SET errors_json = :e WHERE id = :id')->execute([':e' => json_encode_safe($errorsObj), ':id' => $id]);
        audit($pdo, $userId, 'import_failed', ['import_id' => $id]);
        throw new RuntimeException('Import stopped (error ' . $errId . ').');
    }
    $finished = !$hitTimeLimit;
    fclose($fh);

    $errorsObj['import_errors'] = array_slice($errors, -200);
    $errorsObj['inserted_total'] = (int)($errorsObj['inserted_total'] ?? 0) + $inserted;
    $errorsObj['updated_total'] = (int)($errorsObj['updated_total'] ?? 0) + $updated;
    if ($finished) $errorsObj['finished_at'] = time();
    $done = $already + $processed;
    if ($total < $done) $total = $done;
    $pdo->prepare('UPDATE imports SET rows_imported = :d, rows_total = :t, errors_json = :e WHERE id = :id')
        ->execute([':d' => $done, ':t' => $total, ':e' => json_encode_safe($errorsObj), ':id' => $id]);
    audit($pdo, $userId, 'import_chunk_processed', ['import_id' => $id, 'inserted' => $inserted, 'updated' => $updated, 'processed' => $processed, 'seconds' => round(microtime(true) - $start, 2)]);
    if ($finished) {
        refresh_derived($pdo, $CONFIG, get_settings($pdo));
        audit($pdo, $userId, 'import_completed', ['import_id' => $id, 'filename' => (string)$import['filename'], 'rows' => $done, 'inserted' => (int)$errorsObj['inserted_total'], 'updated' => (int)$errorsObj['updated_total']]);
    }
    return ['processed' => $processed, 'inserted' => $inserted, 'updated' => $updated, 'done' => $done, 'total' => $total, 'finished' => $finished, 'warnings' => count($errors)];
}

// Creates an import record for a CSV already on disk, with auto-matched columns and default imputation.
function create_auto_import(PDO $pdo, array $CONFIG, string $filename, string $csvContent): int {
    $pdo->prepare('INSERT INTO imports (filename, rows_total, rows_imported, errors_json, created_at) VALUES (:f,0,0,:e,:t)')
        ->execute([':f' => $filename, ':e' => json_encode_safe([]), ':t' => time()]);
    $importId = (int)$pdo->lastInsertId();
    $path = ensure_upload_dir() . DIRECTORY_SEPARATOR . 'import_' . $importId . '.csv';
    file_put_contents($path, $csvContent);
    [$headers, $_preview] = csv_preview_rows($path, 1);
    $auto = auto_map_columns($headers);
    $customNames = [];
    foreach ($auto['map'] as $col => $t) if ($t === 'custom') $customNames[$col] = normalize_header((string)$col);
    [$mapping, $customMap] = mapping_from_choices($auto['map'], $customNames);
    $impute = [];
    foreach (array_keys($mapping) as $f) $impute[$f] = $CONFIG['defaultImputation'][$f] ?? ['strategy' => 'leave_null_flag', 'value' => null];
    $stats = compute_csv_stats_for_imputation($path, $mapping, $impute);
    $errorsObj = ['mapping' => $mapping, 'custom_map' => $customMap, 'impute' => $impute, 'stats' => $stats, 'mapping_confidence01' => 1.0];
    $pdo->prepare('UPDATE imports SET errors_json = :e, rows_total = :rt WHERE id = :id')
        ->execute([':e' => json_encode_safe($errorsObj), ':rt' => (int)($stats['rows_total'] ?? 0), ':id' => $importId]);
    return $importId;
}

// "Load sample data": rules + audiences (if none yet) + the 200-row sample imported to completion.
function load_sample_data(PDO $pdo, array $CONFIG, ?int $userId): array {
    $rulesAdded = 0;
    $segmentsAdded = 0;
    if ((int)$pdo->query('SELECT COUNT(*) FROM rules')->fetchColumn() === 0) {
        foreach (sample_rules() as $r) {
            $pdo->prepare('INSERT INTO rules (name, conditions_json, actions_json, priority, created_at) VALUES (:n,:c,:a,:p,:t)')->execute([
                ':n' => (string)$r['name'], ':c' => json_encode_safe($r['conditions']), ':a' => json_encode_safe($r['actions']), ':p' => (int)$r['priority'], ':t' => time(),
            ]);
            $rulesAdded++;
        }
        audit($pdo, $userId, 'sample_rules_loaded', ['count' => $rulesAdded]);
    }
    $importId = create_auto_import($pdo, $CONFIG, 'sample.csv', sample_csv());
    audit($pdo, $userId, 'sample_import_created', ['import_id' => $importId]);
    $guard = 0;
    do {
        $res = import_process_chunk($pdo, $CONFIG, $importId, $userId, 20.0);
        $guard++;
    } while (!$res['finished'] && $guard < 50);
    if ((int)$pdo->query('SELECT COUNT(*) FROM segments')->fetchColumn() === 0) {
        foreach (SAMPLE_SEGMENTS as $seg) {
            [$ok, $def] = validate_definition($pdo, $seg['definition']);
            if (!$ok) continue;
            $pdo->prepare('INSERT INTO segments (name, rule_ids, sql_filter, definition_json, created_at, updated_at, last_run_at) VALUES (:n,:r,:s,:d,:t,:t,NULL)')
                ->execute([':n' => $seg['name'], ':r' => '[]', ':s' => null, ':d' => json_encode_safe($def), ':t' => time()]);
            $segmentsAdded++;
        }
        audit($pdo, $userId, 'sample_segments_loaded', ['count' => $segmentsAdded]);
    }
    return ['import_id' => $importId, 'rows' => (int)$res['done'], 'rules' => $rulesAdded, 'segments' => $segmentsAdded];
}


/*
====================================================================================================
Layout
====================================================================================================
*/

function nav_key_for(string $action): string {
    return match ($action) {
        'segments', 'segment_edit', 'segment_run', 'simulator' => 'audiences',
        'profiles', 'profile_view' => 'hcps',
        'rules', 'rule_edit', 'rule_test' => 'rules',
        'import', 'import_map', 'import_check', 'import_run' => 'imports',
        'exports' => 'exports',
        'suppression' => 'suppression',
        'settings' => 'settings',
        'audit' => 'audit',
        default => 'overview',
    };
}

function app_css(string $accent): string {
    return <<<CSS
:root{--bg:#F3F5F8;--panel:#fff;--ink:#0E1726;--slate:#465163;--quiet:#768195;--rule:#DCE1E8;--rule-soft:#EEF1F5;--hover:#F6F7F9;
--acc:{$accent};--acc-deep:#1A2DC4;--acc-wash:#ECEFFF;--acc-line:#C9D0FF;--ok:#1D7A52;--ok-wash:#E6F3EC;--warn:#8A5A00;--warn-wash:#FBF1DD;--bad:#A8322A;--bad-wash:#FCEDEB;--bad-line:#F0CFCB;
--f-sans:system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;--f-mono:ui-monospace,"SF Mono",Menlo,Consolas,"Liberation Mono",monospace}
*{box-sizing:border-box}html{-webkit-text-size-adjust:100%}
body{margin:0;background:var(--bg);color:var(--ink);font-family:var(--f-sans);font-size:14px;line-height:1.5;-webkit-font-smoothing:antialiased}
a{color:inherit;text-decoration:none}a.link,.link{color:var(--acc);font-weight:600}a.link:hover{text-decoration:underline}
code,.mono{font-family:var(--f-mono);font-size:12.5px}
:focus-visible{outline:2px solid var(--acc);outline-offset:2px;border-radius:4px}
h1,h2,h3{margin:0;letter-spacing:-.015em}
.muted{color:var(--quiet)}.sl{color:var(--slate)}.small{font-size:12.5px}.tiny{font-size:12px}.num{font-variant-numeric:tabular-nums}.r{text-align:right}.nowrap{white-space:nowrap}
.text-danger{color:var(--bad)}.ok{color:var(--ok)}.bad{color:var(--bad)}.warn{color:var(--warn)}
/* shell */
[hidden]{display:none!important}
.shell{display:grid;grid-template-columns:236px minmax(0,1fr);min-height:100vh;background:linear-gradient(to right,#fff 235px,var(--rule) 235px,var(--rule) 236px,transparent 236px)}
.side{background:#fff;border-right:1px solid var(--rule);padding:16px 12px;display:flex;flex-direction:column;position:sticky;top:0;height:100vh;overflow:auto}
.brand{display:flex;align-items:center;gap:9px;font-weight:650;font-size:15px;padding:4px 8px 16px}
.brand svg{width:26px;height:26px;flex:none}
.nav-grp{font-size:12px;color:var(--quiet);padding:14px 10px 4px;font-weight:500}
.nav-a{display:flex;align-items:center;gap:10px;padding:7px 10px;border-radius:7px;color:var(--slate);font-size:14px}
.nav-a:hover{background:var(--hover);color:var(--ink)}
.nav-a.on{background:var(--acc-wash);color:var(--acc-deep);font-weight:600}
.nav-a svg{width:16px;height:16px;flex:none;opacity:.75}
.nav-a .ct{margin-left:auto;font-size:12px;color:var(--quiet);font-weight:500;font-variant-numeric:tabular-nums}
.nav-a.on .ct{color:var(--acc-deep)}
.side-foot{margin-top:auto;padding:12px 10px 0;border-top:1px solid var(--rule-soft);font-size:12px;color:var(--quiet)}
.side-foot b{display:block;color:var(--ink);font-weight:500;font-size:13px;word-break:break-all}
.side-foot a{color:var(--slate);text-decoration:underline;text-underline-offset:2px}
.main{padding:24px 32px 40px;min-width:0}
.topbar{display:none}
.page-top{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;margin-bottom:20px;flex-wrap:wrap}
.crumb{font-size:13px;color:var(--quiet);margin-bottom:2px}.crumb a:hover{color:var(--ink)}
.page-title{font-size:24px;font-weight:700;letter-spacing:-.02em;line-height:1.2}
.page-sub{font-size:14px;color:var(--slate);margin-top:3px;max-width:70ch}
.actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
/* buttons */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;height:36px;padding:0 14px;border-radius:8px;border:1px solid var(--rule);background:#fff;color:var(--ink);font:600 13.5px/1 var(--f-sans);cursor:pointer;white-space:nowrap;text-decoration:none}
.btn:hover{border-color:#B9C1CD;background:var(--hover)}
.btn-primary{background:var(--acc);border-color:var(--acc);color:#fff}.btn-primary:hover{background:var(--acc-deep);border-color:var(--acc-deep)}
.btn-ghost{border-color:transparent;background:transparent;color:var(--slate)}.btn-ghost:hover{background:var(--hover)}
.btn-danger{color:var(--bad);border-color:var(--bad-line)}.btn-danger:hover{background:var(--bad-wash)}
.btn-sm{height:30px;padding:0 10px;font-size:12.5px}.btn-lg{height:42px;padding:0 18px;font-size:14px}.w-100{width:100%}
.btn:disabled,.btn[aria-disabled=true]{opacity:.5;cursor:not-allowed}
.btn-outline-dark{background:#fff}
/* cards */
.card{background:var(--panel);border:1px solid var(--rule);border-radius:12px}
.pad{padding:18px 20px}.pad-sm{padding:12px 16px}
.card-h{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:14px 18px;border-bottom:1px solid var(--rule-soft)}
.card-h .h{font-size:15px;font-weight:600}
.h{font-size:15px;font-weight:600}
.grid{display:grid;gap:16px}.g2{grid-template-columns:repeat(2,minmax(0,1fr))}.g3{grid-template-columns:repeat(3,minmax(0,1fr))}.g4{grid-template-columns:repeat(4,minmax(0,1fr))}
.split{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:20px;align-items:start}
.stack{display:flex;flex-direction:column;gap:16px}
.mt-1{margin-top:4px}.mt-2{margin-top:8px}.mt-3{margin-top:16px}.mt-4{margin-top:24px}.mb-1{margin-bottom:4px}.mb-2{margin-bottom:8px}.mb-3{margin-bottom:16px}.m-0{margin:0}
.d-flex{display:flex}.gap-2{gap:8px}.gap-3{gap:16px}.wrap{flex-wrap:wrap}.items-center{align-items:center}.between{justify-content:space-between}.ms-auto{margin-left:auto}
/* tiles */
.tile .v{font-size:26px;font-weight:700;letter-spacing:-.02em;font-variant-numeric:tabular-nums;line-height:1.2}
.tile .l{font-size:13px;color:var(--slate)}
.tile .v.bad{color:var(--bad)}
/* chips / badges */
.chip{display:inline-flex;align-items:center;gap:5px;height:22px;padding:0 8px;border-radius:999px;font-size:12px;font-weight:600;background:#F0F2F5;color:var(--slate);white-space:nowrap}
.chip.blue{background:var(--acc-wash);color:var(--acc-deep)}.chip.green{background:var(--ok-wash);color:var(--ok)}.chip.red{background:var(--bad-wash);color:var(--bad)}.chip.amber{background:var(--warn-wash);color:var(--warn)}
.tier{display:inline-grid;place-items:center;width:22px;height:22px;border-radius:6px;font-size:12px;font-weight:700;flex:none}
.tA{background:var(--acc);color:#fff}.tB{background:var(--acc-line);color:var(--acc-deep)}.tC{background:#E9EDF2;color:var(--slate)}
.imputed{border-bottom:1.5px dotted var(--acc)}
/* tables */
.table-wrap{overflow-x:auto}
.tbl{width:100%;border-collapse:collapse;font-size:13.5px}
.tbl th{text-align:left;font-weight:500;color:var(--quiet);font-size:12.5px;padding:9px 12px;border-bottom:1px solid var(--rule);white-space:nowrap;background:#fff}
.tbl th a{color:inherit}.tbl th a:hover{color:var(--ink)}.tbl th .sort{color:var(--acc)}
.tbl td{padding:9px 12px;border-bottom:1px solid var(--rule-soft);vertical-align:middle}
.tbl tbody tr:last-child td{border-bottom:0}
.tbl tbody tr.click:hover{background:var(--hover);cursor:pointer}
.tbl td.wrap-cell{white-space:normal}
.tbl .sub{display:block;font-size:12px;color:var(--quiet)}
.mix{display:flex;height:8px;width:110px;border-radius:4px;overflow:hidden;background:#E9EDF2}
.mix i{display:block;height:100%}
.bar{height:8px;border-radius:4px;background:#E9EDF2;overflow:hidden}.bar i{display:block;height:100%;background:var(--ink);border-radius:4px}
.stackbar{display:flex;height:14px;border-radius:7px;overflow:hidden;background:#E9EDF2}.stackbar i{display:block;height:100%}
.legend{display:grid;grid-template-columns:1fr 1fr;gap:8px 18px;font-size:13px;margin-top:12px}
.legend span{display:flex;align-items:center;gap:8px}.legend b{margin-left:auto;font-variant-numeric:tabular-nums}
.dot{display:inline-block;width:8px;height:8px;border-radius:50%;flex:none}
/* forms */
label.lbl,.form-label{display:block;font-size:12.5px;font-weight:600;margin:0 0 5px;color:var(--ink)}
.input,.form-control,.form-select,select.input{display:block;width:100%;height:36px;padding:0 10px;border:1px solid var(--rule);border-radius:8px;background:#fff;color:var(--ink);font:400 13.5px var(--f-sans)}
textarea.input,textarea.form-control{height:auto;padding:8px 10px;font-family:var(--f-mono);font-size:12.5px;line-height:1.55}
.input:focus,.form-control:focus,.form-select:focus{outline:none;border-color:var(--acc);box-shadow:0 0 0 3px var(--acc-wash)}
.input-sm{height:30px;font-size:12.5px;border-radius:7px}
.field{margin-bottom:14px}.help{font-size:12px;color:var(--quiet);margin-top:4px}
.check{display:flex;align-items:center;gap:8px;font-size:13.5px;margin:6px 0}
.check input{width:16px;height:16px;accent-color:var(--acc);margin:0}
input[type=file].input{padding:6px 10px;height:auto}
.affix{display:flex;align-items:center;border:1px solid var(--rule);border-radius:8px;background:#fff;height:36px;padding:0 10px;gap:6px}
.affix input{border:0;outline:0;flex:1;min-width:0;font:400 13.5px var(--f-sans);background:transparent;height:100%}
.affix span{color:var(--quiet);font-size:13px}
/* alerts + toasts */
.alert{border-radius:10px;padding:12px 14px;font-size:13.5px;border:1px solid var(--rule);background:#fff}
.alert.info{background:var(--acc-wash);border-color:var(--acc-line);color:var(--acc-deep)}
.alert.warn{background:var(--warn-wash);border-color:#EED9A8;color:var(--warn)}
.alert.bad{background:var(--bad-wash);border-color:var(--bad-line);color:var(--bad)}
.alert.ok{background:var(--ok-wash);border-color:#BFE0CD;color:var(--ok)}
.toasts{position:fixed;right:16px;bottom:16px;z-index:100;display:flex;flex-direction:column;gap:8px;align-items:flex-end}
.toast{display:flex;align-items:center;gap:10px;min-width:260px;max-width:420px;padding:11px 12px 11px 14px;border-radius:10px;background:var(--ink);color:#fff;font-size:13.5px;box-shadow:0 10px 30px -10px rgba(14,23,38,.45)}
.toast.success{background:#14532D}.toast.danger{background:#7F1D1D}.toast.warning{background:#78350F}
.toast button{margin-left:auto;background:transparent;border:0;color:inherit;opacity:.75;font-size:18px;line-height:1;cursor:pointer;padding:0 4px}
/* empty state */
.empty{text-align:center;padding:40px 20px}.empty .h{font-size:17px}.empty p{color:var(--slate);max-width:52ch;margin:8px auto 18px}
/* steps / checklist */
.steps5{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin-top:14px}
.s5{padding:12px 4px 2px 0;border-top:3px solid var(--rule)}
.s5.done{border-top-color:var(--ok)}.s5.now{border-top-color:var(--acc)}
.s5 .k{font-size:12px;color:var(--quiet)}.s5.now .k{color:var(--acc)}.s5 .t{font-weight:600;margin-top:2px}.s5 .d{font-size:12.5px;color:var(--slate);margin-top:3px}
.s5 a.t:hover{color:var(--acc)}
.wiz{display:flex;margin-bottom:18px;border:1px solid var(--rule);border-radius:10px;overflow:hidden;background:#fff}
.wz{flex:1;display:flex;align-items:center;gap:10px;padding:11px 16px;font-size:13.5px;color:var(--slate);border-right:1px solid var(--rule)}
.wz:last-child{border-right:0}
.wz .n{width:24px;height:24px;border-radius:50%;display:grid;place-items:center;font-size:12px;font-weight:700;background:#E9EDF2;flex:none}
.wz.done .n{background:var(--ok);color:#fff}.wz.now{background:#F6F7FF;color:var(--ink);font-weight:600;box-shadow:inset 0 -3px 0 var(--acc)}.wz.now .n{background:var(--acc);color:#fff}
/* builder */
.cond{display:flex;align-items:center;gap:8px;padding:9px 10px;border:1px solid var(--rule);border-radius:10px;background:#fff;margin-bottom:8px;flex-wrap:wrap}
.cond .w{color:var(--quiet);width:40px;font-size:13px;flex:none}
.cond select,.cond input[type=number],.cond input[type=text]{height:32px;border:1px solid var(--rule);border-radius:7px;background:#F8F9FB;padding:0 8px;font:400 13.5px var(--f-sans);color:var(--ink)}
.cond input[type=number]{width:90px}.cond input[type=text]{min-width:160px}
.cond .cnt{margin-left:auto;font-size:12.5px;color:var(--quiet);white-space:nowrap}
.cond .rm{border:0;background:transparent;color:var(--quiet);font-size:18px;cursor:pointer;padding:0 6px;line-height:1}
.cond .rm:hover{color:var(--bad)}
.pills{display:flex;flex-wrap:wrap;gap:6px}
.pill{display:inline-flex;align-items:center;gap:6px;height:30px;padding:0 10px;border:1px solid var(--rule);border-radius:7px;background:#F8F9FB;font-size:13px;cursor:pointer;user-select:none}
.pill input{display:none}.pill.on{background:var(--acc-wash);border-color:var(--acc-line);color:var(--acc-deep);font-weight:600}
.addl{display:flex;gap:4px 16px;flex-wrap:wrap;align-items:center;font-size:13px;font-weight:600}.addl button{border:0;background:transparent;color:var(--acc);font:inherit;cursor:pointer;padding:4px 0}
.match-toggle{display:inline-flex;border:1px solid var(--rule);border-radius:8px;overflow:hidden}
.match-toggle label{padding:6px 12px;font-size:13px;cursor:pointer;background:#fff}.match-toggle input{display:none}.match-toggle label.on{background:var(--acc-wash);color:var(--acc-deep);font-weight:600}
.funnel{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));border:1px solid var(--rule);border-radius:12px;background:#fff;overflow:hidden}
.fz{padding:12px 16px;border-right:1px solid var(--rule-soft)}.fz:last-child{border-right:0;background:var(--acc-wash)}
.fz .l{font-size:12.5px;color:var(--slate)}.fz .v{font-size:22px;font-weight:700;font-variant-numeric:tabular-nums}.fz:last-child .v{color:var(--acc)}
.fz .bar{margin-top:8px;height:6px}.fz:last-child .bar i{background:var(--acc)}
.lock{display:flex;gap:12px;align-items:flex-start;padding:13px 16px;border-radius:12px;background:#fff;border:1px solid var(--bad-line)}
.lock .ic{width:30px;height:30px;border-radius:8px;background:var(--bad-wash);display:grid;place-items:center;flex:none;color:var(--bad)}
.kv{display:flex;justify-content:space-between;gap:10px;font-size:13.5px;padding:6px 0;border-bottom:1px dashed #E3E7ED}.kv:last-child{border-bottom:0}
.opt{display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border:1px solid var(--rule);border-radius:10px;font-size:13px;margin-bottom:8px;cursor:pointer}
.opt input{margin-top:2px;accent-color:var(--acc)}.opt.on{border-color:var(--acc);background:#F6F7FF}.opt.off{opacity:.55;cursor:not-allowed}
.blk{border:1px solid var(--rule);border-radius:12px;background:#fff}
.blk-h{display:flex;align-items:center;gap:10px;padding:12px 16px;border-bottom:1px solid var(--rule-soft);font-weight:600}
.blk-b{padding:12px 16px}
.order{list-style:none;margin:0;padding:0}
.order li{display:flex;align-items:center;gap:8px;padding:7px 8px;border-radius:8px;font-size:13px;color:var(--slate)}
.order li.on{background:var(--acc-wash);color:var(--acc-deep);font-weight:600}
.order li .p{margin-left:auto;font-size:12px;color:var(--quiet);font-variant-numeric:tabular-nums}
.order form{display:inline}
.diff{display:grid;grid-template-columns:1.4fr 1fr 18px 1fr;gap:6px 10px;align-items:center;font-size:13px;padding:8px 0;border-bottom:1px solid var(--rule-soft)}.diff:last-child{border-bottom:0}
.score-row{display:grid;grid-template-columns:110px minmax(0,1fr) 60px;gap:10px;align-items:center;font-size:13px;padding:6px 0}
.dl{display:grid;grid-template-columns:150px minmax(0,1fr);gap:0;font-size:13.5px}
.dl dt{color:var(--quiet);padding:7px 0;border-bottom:1px solid var(--rule-soft)}.dl dd{margin:0;padding:7px 0;border-bottom:1px solid var(--rule-soft);word-break:break-word}
.progress{height:10px;border-radius:5px;background:#E9EDF2;overflow:hidden}.progress i{display:block;height:100%;background:var(--acc);border-radius:5px;transition:width .3s ease}
pre.json{white-space:pre-wrap;margin:0;font-family:var(--f-mono);font-size:12.5px;background:#F8F9FB;border:1px solid var(--rule-soft);border-radius:8px;padding:12px}
.pager{display:flex;align-items:center;gap:8px;justify-content:flex-end;padding:10px 14px;font-size:13px;color:var(--slate);border-top:1px solid var(--rule-soft)}
.filters{display:flex;flex-wrap:wrap;gap:8px;align-items:center;padding:12px 14px;border-bottom:1px solid var(--rule-soft)}
.filters .input{width:auto;min-width:140px;height:32px;font-size:13px}.filters .input.search{min-width:220px}
/* auth */
.auth{min-height:100vh;display:grid;place-items:center;padding:24px}
.auth .card{width:100%;max-width:400px}
.auth .brand{justify-content:center;padding-bottom:20px}
/* compatibility with older markup */
.p-3{padding:16px}.p-4{padding:24px}.row{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:16px}[class*=col-]{grid-column:span 12}
.badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11.5px;font-weight:600;background:#F0F2F5;color:var(--slate)}
.alert-dark{background:var(--ink);color:#fff;border-color:var(--ink)}
@media (max-width:1100px){.split{grid-template-columns:1fr}.g4{grid-template-columns:repeat(2,minmax(0,1fr))}.steps5{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:860px){
 .shell{grid-template-columns:1fr;background:none}
 .side{position:fixed;inset:0 auto 0 0;width:260px;z-index:60;transform:translateX(-100%);transition:transform .2s ease;box-shadow:0 0 40px rgba(14,23,38,.25)}
 .shell.nav-open .side{transform:none}
 .topbar{display:flex;align-items:center;justify-content:space-between;padding:10px 16px;background:#fff;border-bottom:1px solid var(--rule);position:sticky;top:0;z-index:50}
 .main{padding:18px 16px 32px}.g2,.g3,.g4{grid-template-columns:1fr}.funnel{grid-template-columns:repeat(2,minmax(0,1fr))}.legend{grid-template-columns:1fr}
 .dl{grid-template-columns:1fr}.dl dt{border-bottom:0;padding-bottom:0}
}
CSS;
}

function nav_icon(string $k): string {
    $p = match ($k) {
        'overview' => '<path d="M2.5 8.5 8 3l5.5 5.5"/><path d="M4 7.5V13h8V7.5"/>',
        'audiences' => '<circle cx="6" cy="6" r="2.5"/><path d="M1.8 13c.5-2.2 2.2-3.5 4.2-3.5s3.7 1.3 4.2 3.5"/><path d="M10.5 3.6a2.4 2.4 0 0 1 0 4.8M12.2 9.8c1 .6 1.7 1.7 2 3.2"/>',
        'hcps' => '<rect x="2.5" y="2.5" width="11" height="11" rx="2"/><path d="M5 6h6M5 8.5h6M5 11h3.5"/>',
        'rules' => '<path d="M3 4h10M3 8h6M3 12h8"/><circle cx="12" cy="8" r="1.4"/>',
        'imports' => '<path d="M8 2.5v8M5 7.5l3 3 3-3"/><path d="M2.5 11v2.5h11V11"/>',
        'exports' => '<path d="M8 10.5v-8M5 5.5l3-3 3 3"/><path d="M2.5 11v2.5h11V11"/>',
        'suppression' => '<circle cx="8" cy="8" r="5.5"/><path d="M4.2 11.8l7.6-7.6"/>',
        'settings' => '<circle cx="8" cy="8" r="2"/><path d="M8 1.8v2M8 12.2v2M1.8 8h2M12.2 8h2M3.6 3.6l1.4 1.4M11 11l1.4 1.4M3.6 12.4 5 11M11 5l1.4-1.4"/>',
        'audit' => '<path d="M4 2.5h6l2.5 2.5v8.5H4z"/><path d="M6 7h4.5M6 9.5h4.5"/>',
        default => '<circle cx="8" cy="8" r="5"/>',
    };
    return '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}

function brand_mark(): string {
    $acc = h((string)($GLOBALS['CONFIG']['accentColor'] ?? '#2540FF'));
    return '<svg viewBox="0 0 32 32" aria-hidden="true"><rect width="32" height="32" rx="7" fill="' . $acc . '"/><circle cx="16" cy="16" r="8.5" fill="none" stroke="#fff" stroke-width="2.4"/><circle cx="16" cy="16" r="2.6" fill="#fff"/></svg>';
}

function page_header(string $title, array $CONFIG, array $settings, ?array $user): void {
    $app = (string)$CONFIG['appName'];
    $accent = (string)$CONFIG['accentColor'];
    $action = (string)($_GET['action'] ?? 'dashboard');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h($title) . ' · ' . h($app) . '</title>';
    echo '<meta name="csrf" content="' . h(csrf_token()) . '">';
    echo '<link rel="icon" href="data:image/svg+xml,' . rawurlencode(brand_mark()) . '">';
    echo '<style>' . app_css($accent) . '</style></head><body>';

    if ($user === null) {
        echo '<div class="auth"><div style="width:100%;max-width:400px">';
        echo '<div class="brand">' . brand_mark() . h($app) . '</div>';
        render_flashes();
        return;
    }

    $pdo = db($CONFIG);
    $counts = [
        'audiences' => (int)$pdo->query('SELECT COUNT(*) FROM segments')->fetchColumn(),
        'hcps' => (int)$pdo->query('SELECT COUNT(*) FROM profiles')->fetchColumn(),
        'rules' => (int)$pdo->query('SELECT COUNT(*) FROM rules')->fetchColumn(),
        'suppression' => suppressed_count($pdo),
    ];
    $on = nav_key_for($action);
    $item = function (string $key, string $label, string $href, ?int $count = null) use ($on): string {
        $cls = $key === $on ? 'nav-a on' : 'nav-a';
        $aria = $key === $on ? ' aria-current="page"' : '';
        return '<a class="' . $cls . '" href="' . h($href) . '"' . $aria . '>' . nav_icon($key) . h($label) . ($count !== null ? '<span class="ct">' . h(fmt_int($count)) . '</span>' : '') . '</a>';
    };

    echo '<div class="shell" id="shell">';
    echo '<aside class="side" id="side" aria-label="Main navigation">';
    echo '<a class="brand" href="?action=dashboard">' . brand_mark() . h($app) . '</a>';
    echo $item('overview', 'Overview', '?action=dashboard');
    echo '<div class="nav-grp">Build</div>';
    echo $item('audiences', 'Audiences', '?action=segments', $counts['audiences']);
    echo $item('hcps', 'HCPs', '?action=profiles', $counts['hcps']);
    echo $item('rules', 'Enrichment rules', '?action=rules', $counts['rules']);
    echo '<div class="nav-grp">Data</div>';
    echo $item('imports', 'Imports', '?action=import');
    echo $item('exports', 'Exports', '?action=exports');
    echo '<div class="nav-grp">Admin</div>';
    echo $item('suppression', 'Suppression', '?action=suppression', $counts['suppression']);
    echo $item('settings', 'Settings', '?action=settings');
    echo $item('audit', 'Audit log', '?action=audit');
    echo '<div class="side-foot"><b>' . h((string)($user['email'] ?? '')) . '</b>' . h(role_label(user_role())) . ' · <a href="?action=logout">Sign out</a>';
    echo '<div class="mt-2">v' . h(LITEHCP_VERSION) . ' · stored locally in SQLite</div></div>';
    echo '</aside>';
    echo '<div class="main-wrap"><div class="topbar"><button class="btn btn-sm" type="button" id="nav-toggle" aria-controls="side" aria-expanded="false">Menu</button><span class="d-flex items-center gap-2" style="font-weight:600">' . brand_mark() . '</span></div>';
    echo '<main class="main" id="content">';
    if (PHP_VERSION_ID < 80200 && can('admin')) {
        echo '<div class="alert warn mb-3">This server runs PHP ' . h(PHP_VERSION) . ', which no longer receives security fixes. Ask your host to switch this folder to PHP 8.3 or newer.</div>';
    }
    render_flashes();
}

function render_flashes(): void {
    $flashes = take_flashes();
    if (!$flashes) return;
    echo '<div class="toasts" role="status" aria-live="polite">';
    foreach ($flashes as $f) {
        $t = (string)($f['type'] ?? 'info');
        $cls = in_array($t, ['success', 'danger', 'warning'], true) ? $t : '';
        echo '<div class="toast ' . h($cls) . '"><span>' . h((string)($f['message'] ?? '')) . '</span><button type="button" aria-label="Dismiss">×</button></div>';
    }
    echo '</div>';
}

function page_top(string $title, string $sub = '', string $crumbHtml = '', string $actionsHtml = ''): void {
    echo '<div class="page-top"><div>';
    if ($crumbHtml !== '') echo '<div class="crumb">' . $crumbHtml . '</div>';
    echo '<h1 class="page-title">' . h($title) . '</h1>';
    if ($sub !== '') echo '<div class="page-sub">' . $sub . '</div>';
    echo '</div>';
    if ($actionsHtml !== '') echo '<div class="actions">' . $actionsHtml . '</div>';
    echo '</div>';
}

function tier_badge(?string $t): string {
    $t = in_array($t, ['A', 'B', 'C'], true) ? $t : 'C';
    return '<span class="tier t' . $t . '" title="Tier ' . $t . '">' . $t . '</span>';
}

function tier_mix_bar(array $tiers): string {
    $tot = max(1, array_sum($tiers));
    $c = ['A' => 'var(--acc)', 'B' => 'var(--acc-line)', 'C' => '#D5DBE3'];
    $out = '<div class="mix" title="Tier A ' . (int)$tiers['A'] . ' · B ' . (int)$tiers['B'] . ' · C ' . (int)$tiers['C'] . '">';
    foreach (['A', 'B', 'C'] as $k) if ($tiers[$k] > 0) $out .= '<i style="width:' . round($tiers[$k] / $tot * 100, 1) . '%;background:' . $c[$k] . '"></i>';
    return $out . '</div>';
}

function reach_chips(array $p): string {
    $e = (int)($p['consent_email'] ?? 0) === 1;
    $w = (int)($p['consent_web'] ?? 0) === 1;
    if (!$e && !$w) return '<span class="chip red">No consent</span>';
    return ($e ? '<span class="chip green">Email</span> ' : '') . ($w ? '<span class="chip green">Web</span>' : '');
}

function page_footer(): void {
    $user = current_user();
    if ($user === null) {
        echo '</div></div>';
    } else {
        echo '</main></div></div>';
    }
    $nonce = (string)($GLOBALS['CSP_NONCE'] ?? '');
    echo '<script nonce="' . h($nonce) . '">' . app_js() . '</script>';
    echo '</body></html>';
}


// One small vanilla-JS block, loaded with the CSP nonce. Page modules activate only when their root element exists.
function app_js(): string {
    return <<<'JS'
(function(){
"use strict";
const $=(s,r)=>(r||document).querySelector(s), $$=(s,r)=>Array.from((r||document).querySelectorAll(s));
const csrf=(document.querySelector('meta[name=csrf]')||{}).content||'';
const esc=s=>String(s==null?'':s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const fmt=n=>Number(n||0).toLocaleString('en-US');
const fmtScore=n=>{n=Number(n||0);return Math.abs(n-Math.round(n))<0.05?String(Math.round(n)):n.toFixed(1);};
async function post(url,body){const r=await fetch(url,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(body||{})});let j=null;try{j=await r.json();}catch(e){}if(!r.ok)throw new Error((j&&j.error)||('Request failed ('+r.status+')'));return j;}
function debounce(fn,ms){let t;return(...a)=>{clearTimeout(t);t=setTimeout(()=>fn(...a),ms);};}
function config(id){const el=document.getElementById(id);if(!el)return null;try{return JSON.parse(el.textContent);}catch(e){return null;}}

/* toasts, confirms, nav, rows, option cards */
$$('.toast').forEach(t=>{const b=$('button',t);if(b)b.addEventListener('click',()=>t.remove());setTimeout(()=>{t.style.transition='opacity .4s';t.style.opacity='0';setTimeout(()=>t.remove(),450);},6000);});
document.addEventListener('submit',e=>{const m=e.target.getAttribute&&e.target.getAttribute('data-confirm');if(m&&!window.confirm(m))e.preventDefault();},true);
const nt=$('#nav-toggle'),sh=$('#shell');
if(nt&&sh){nt.addEventListener('click',()=>{const o=sh.classList.toggle('nav-open');nt.setAttribute('aria-expanded',o?'true':'false');});document.addEventListener('keydown',e=>{if(e.key==='Escape')sh.classList.remove('nav-open');});}
$$('tr[data-href]').forEach(tr=>tr.addEventListener('click',e=>{if(e.target.closest('a,button,input,select,form,label'))return;location.href=tr.getAttribute('data-href');}));
function syncOpts(){$$('.opt').forEach(o=>{const i=$('input',o);if(i)o.classList.toggle('on',i.checked);});}
$$('.opt input').forEach(i=>i.addEventListener('change',syncOpts));syncOpts();
$$('[data-autosubmit]').forEach(el=>el.addEventListener('change',()=>{if(el.form)el.form.submit();}));
$$('select[data-cname]').forEach(sel=>{const inp=document.getElementById(sel.getAttribute('data-cname'));if(!inp)return;const sync=()=>{inp.hidden=sel.value!=='custom';};sel.addEventListener('change',sync);sync();});
$$('[data-copy]').forEach(b=>b.addEventListener('click',()=>{const t=document.getElementById(b.getAttribute('data-copy'));if(t&&navigator.clipboard){navigator.clipboard.writeText(t.textContent.trim());b.textContent='Copied';}}));

/* ---------- audience builder ---------- */
const ab=$('#audience-builder');
if(ab){
  const C=config('builder-config')||{};
  const specs=C.specs||{},ops=C.ops||{},options=C.options||{};
  let def=C.definition||{match:'all',conditions:[]};
  const rows=$('#conds'),hidden=$('#definition_json'),readonly=!!C.readonly;
  const fieldOrder=Object.keys(specs);
  function defaultCond(field){const s=specs[field];const t=s.type;const op=Object.keys(ops[t]||{})[0];let v='';if(t==='set'||t==='channels')v=[];if(t==='number')v=field==='confidence_score'?70:(field==='priority_decile'?8:0);if(t==='days')v=30;if(t==='rule')v=(options.rule&&options.rule[0])?options.rule[0].id:0;if(t==='tag')v=(options.tags&&options.tags[0])||'';return{field,op,value:v};}
  function valueControl(c,i){
    const s=specs[c.field]||{type:'text'};const t=s.type;
    if(t==='set'||t==='channels'){let opts=(options[c.field]||options[t==='channels'?'reachable':c.field]||[]);const sel=Array.isArray(c.value)?c.value:[];
      opts=opts.concat(sel.filter(v=>opts.indexOf(v)<0));
      if(!opts.length)return '<span class="muted small">No values yet</span>';
      return '<span class="pills">'+opts.map(o=>'<label class="pill'+(sel.indexOf(o)>=0?' on':'')+'"><input type="checkbox" data-i="'+i+'" data-k="val" value="'+esc(o)+'"'+(sel.indexOf(o)>=0?' checked':'')+(readonly?' disabled':'')+'>'+esc(o==='email'?'Email':o==='web'?'Web':o)+'</label>').join('')+'</span>';}
    if(t==='number'||t==='days')return '<input type="number" step="any" data-i="'+i+'" data-k="val" value="'+esc(c.value)+'"'+(readonly?' disabled':'')+'>'+(t==='days'?'<span class="muted small">days</span>':'');
    if(t==='rule'){const o=options.rule||[];return '<select data-i="'+i+'" data-k="val"'+(readonly?' disabled':'')+'>'+o.map(r=>'<option value="'+r.id+'"'+(Number(c.value)===r.id?' selected':'')+'>'+esc(r.name)+'</option>').join('')+'</select>';}
    if(t==='tag'&&(options.tags||[]).length){const o=options.tags;return '<select data-i="'+i+'" data-k="val"'+(readonly?' disabled':'')+'>'+o.map(x=>'<option'+(x===c.value?' selected':'')+'>'+esc(x)+'</option>').join('')+'</select>';}
    return '<input type="text" data-i="'+i+'" data-k="val" value="'+esc(c.value)+'"'+(readonly?' disabled':'')+'>';
  }
  function render(){
    rows.innerHTML=def.conditions.map((c,i)=>{const s=specs[c.field]||{label:c.field,type:'text'};const opsT=ops[s.type]||{};
      return '<div class="cond" data-row="'+i+'"><span class="w">'+(i===0?'Where':(def.match==='any'?'or':'and'))+'</span>'+
      '<select data-i="'+i+'" data-k="field" aria-label="Field"'+(readonly?' disabled':'')+'>'+fieldOrder.map(f=>'<option value="'+esc(f)+'"'+(f===c.field?' selected':'')+'>'+esc(specs[f].label)+'</option>').join('')+'</select>'+
      '<select data-i="'+i+'" data-k="op" aria-label="Condition"'+(readonly?' disabled':'')+'>'+Object.keys(opsT).map(o=>'<option value="'+esc(o)+'"'+(o===c.op?' selected':'')+'>'+esc(opsT[o])+'</option>').join('')+'</select>'+
      valueControl(c,i)+'<span class="cnt" id="cnt-'+i+'"></span>'+(readonly?'':'<button type="button" class="rm" data-rm="'+i+'" aria-label="Remove condition">×</button>')+'</div>';}).join('')||'<div class="muted small" style="padding:6px 2px 10px">No conditions yet: the audience is every HCP who is not excluded.</div>';
    $$('input[name=match]').forEach(r=>{r.checked=(r.value===def.match);r.closest('label').classList.toggle('on',r.checked);});
    sync();
  }
  function sync(){hidden.value=JSON.stringify(def);refresh();}
  rows.addEventListener('change',e=>{const el=e.target;const i=Number(el.getAttribute('data-i'));const k=el.getAttribute('data-k');if(isNaN(i)||!def.conditions[i])return;const c=def.conditions[i];
    if(k==='field'){def.conditions[i]=defaultCond(el.value);render();return;}
    if(k==='op'){c.op=el.value;sync();return;}
    if(k==='val'){const t=(specs[c.field]||{}).type;if(t==='set'||t==='channels'){c.value=$$('input[data-i="'+i+'"][data-k=val]',rows).filter(x=>x.checked).map(x=>x.value);el.closest('.pill').classList.toggle('on',el.checked);}else if(t==='number'||t==='days'){c.value=el.value===''?'':Number(el.value);}else if(t==='rule'){c.value=Number(el.value);}else{c.value=el.value;}sync();}
  });
  rows.addEventListener('input',e=>{const el=e.target;if(el.getAttribute('data-k')!=='val'||el.type==='checkbox')return;const i=Number(el.getAttribute('data-i'));const c=def.conditions[i];if(!c)return;const t=(specs[c.field]||{}).type;c.value=(t==='number'||t==='days')?(el.value===''?'':Number(el.value)):el.value;hidden.value=JSON.stringify(def);refreshSoon();});
  rows.addEventListener('click',e=>{const b=e.target.closest('[data-rm]');if(!b)return;def.conditions.splice(Number(b.getAttribute('data-rm')),1);render();});
  $$('[data-add]').forEach(b=>b.addEventListener('click',()=>{def.conditions.push(defaultCond(b.getAttribute('data-add')));render();}));
  $$('input[name=match]').forEach(r=>r.addEventListener('change',()=>{def.match=r.value;render();}));
  let seq=0;
  async function refresh(){const my=++seq;const st=$('#builder-status');if(st)st.textContent='Counting…';
    try{const j=await post('?action=api_count',{definition:def,segment_id:C.segmentId||0});if(my!==seq)return;
      if(j.error){if(st)st.textContent=j.error;return;}
      if(st)st.textContent='';
      const set=(id,v)=>{const el=document.getElementById(id);if(el)el.textContent=v;};
      set('f-universe',fmt(j.universe));set('f-matches',fmt(j.matches));set('f-excluded',j.excluded?('−'+fmt(j.excluded)):'0');set('f-ready',fmt(j.ready));
      const bar=(id,v)=>{const el=document.getElementById(id);if(el)el.style.width=(j.universe?Math.max(v>0?1:0,Math.round(v/j.universe*1000)/10):0)+'%';};
      bar('b-matches',j.matches);bar('b-ready',j.ready);bar('b-excluded',j.excluded);
      set('lock-count',fmt(j.excluded));const lock=$('#lock');if(lock)lock.hidden=!j.excluded;
      set('tier-mix','Tier A '+fmt(j.tiers.A)+' · B '+fmt(j.tiers.B)+' · C '+fmt(j.tiers.C));
      (j.steps||[]).forEach((n,i)=>set('cnt-'+i,fmt(n)+' match'));
      const pb=$('#preview-body');if(pb){pb.innerHTML=(j.preview||[]).map(p=>'<tr class="click" data-href="?action=profile_view&id='+p.id+'"><td><span class="tier t'+esc(p.tier)+'">'+esc(p.tier)+'</span></td><td><a href="?action=profile_view&id='+p.id+'"><b>'+esc(p.name)+'</b></a> <span class="muted mono">'+esc(p.hcp_id)+'</span></td><td>'+esc(p.specialty)+'</td><td>'+esc(p.region)+'</td><td>'+(p.consent_email?'<span class="chip green">Email</span> ':'')+(p.consent_web?'<span class="chip green">Web</span>':'')+'</td><td class="r num">'+fmtScore(p.priority_score)+'</td><td class="r num">'+esc(p.confidence_score)+'</td></tr>').join('')||'<tr><td colspan="7" class="muted">No HCPs match yet.</td></tr>';
        $$('tr[data-href]',pb).forEach(tr=>tr.addEventListener('click',e=>{if(e.target.closest('a'))return;location.href=tr.getAttribute('data-href');}));}
      set('preview-count',fmt(j.ready)+' HCPs, highest priority first');
      document.dispatchEvent(new CustomEvent('audience:count',{detail:j.ready}));
    }catch(err){if(my===seq&&st)st.textContent=err.message;}}
  const refreshSoon=debounce(refresh,350);
  render();
}

/* ---------- plan + export panel ---------- */
const plan=$('#plan');
if(plan){
  let size=Number(plan.getAttribute('data-size')||0);const sym=plan.getAttribute('data-currency')||'$';
  const money=v=>sym+(v<100?v.toFixed(2):Math.round(v).toLocaleString('en-US'));
  function calc(){const g=id=>Number(($('#'+id)||{}).value||0);const cr=Math.min(100,Math.max(0,g('p-contact')))/100,rr=Math.min(100,Math.max(0,g('p-response')))/100,cost=Math.max(0,g('p-cost')),val=Math.max(0,g('p-value'));
    const contacts=size*cr,resp=contacts*rr,total=contacts*cost,value=resp*val;const set=(id,v)=>{const el=document.getElementById(id);if(el)el.textContent=v;};
    set('o-contacts',fmt(Math.round(contacts)));set('o-responses',resp<1&&resp>0?'under 1':(Number.isInteger(Math.round(resp*10)/10)?'':'about ')+fmt(Math.round(resp)));set('o-cost',money(total));set('o-value',money(value));set('o-roi',total>0?((value/total).toFixed(1).replace(/\.0$/,'')+'× cost'):'—');
    $$('[data-export-count]').forEach(b=>b.textContent='Export '+fmt(size)+' HCPs');}
  $$('#plan input').forEach(i=>i.addEventListener('input',calc));
  document.addEventListener('audience:count',e=>{size=Number(e.detail||0);calc();});
  calc();
}

/* ---------- rule builder ---------- */
const rb=$('#rule-builder');
if(rb){
  const C=config('rule-config')||{};const fields=C.fields||[],opsList=C.ops||[];
  const tc=$('#conditions_json'),ta=$('#actions_json');
  let cond=null,act={};
  try{cond=JSON.parse(tc.value||'{}');act=JSON.parse(ta.value||'{}');}catch(e){}
  const flat=cond&&Array.isArray(cond.conditions)&&cond.conditions.every(c=>c&&c.field!==undefined&&!c.conditions&&!c.all&&!c.any)&&!cond.all&&!cond.any;
  if(!flat){rb.innerHTML='<div class="alert warn">This rule uses nested groups, so it is shown as JSON. Edit it under "View as JSON".</div>';const d=$('#json-details');if(d)d.open=true;}
  else{
    const list=$('#r-conds');
    function fieldOpts(sel){let o=fields.slice();if(sel&&!o.some(f=>f.key===sel))o.push({key:sel,label:sel});return o.map(f=>'<option value="'+esc(f.key)+'"'+(f.key===sel?' selected':'')+'>'+esc(f.label)+'</option>').join('');}
    function renderC(){list.innerHTML=cond.conditions.map((c,i)=>'<div class="cond"><span class="w">'+(i===0?'When':(cond.match==='OR'?'or':'and'))+'</span><select data-i="'+i+'" data-k="field">'+fieldOpts(c.field)+'</select><select data-i="'+i+'" data-k="op">'+opsList.map(o=>'<option value="'+esc(o[0])+'"'+(o[0]===String(c.op)?' selected':'')+'>'+esc(o[1])+'</option>').join('')+'</select><input type="text" data-i="'+i+'" data-k="value" value="'+esc(Array.isArray(c.value)?c.value.join(', '):c.value)+'" placeholder="value"><button type="button" class="rm" data-rm="'+i+'" aria-label="Remove condition">×</button></div>').join('')||'<div class="muted small">No conditions: the rule would apply to every HCP.</div>';
      $$('input[name=r-match]').forEach(r=>{r.checked=(r.value===(cond.match==='OR'?'OR':'AND'));r.closest('label').classList.toggle('on',r.checked);});write();}
    function castVal(op,v){if(op==='in')return v.split(',').map(x=>x.trim()).filter(Boolean).map(x=>/^-?\d+(\.\d+)?$/.test(x)?Number(x):x);if(/^-?\d+(\.\d+)?$/.test(v.trim())&&op!=='regex'&&op!=='contains')return Number(v.trim());return v;}
    list.addEventListener('input',e=>{const el=e.target;const i=Number(el.getAttribute('data-i'));const k=el.getAttribute('data-k');const c=cond.conditions[i];if(!c)return;if(k==='value')c.value=castVal(String(c.op),el.value);else c[k]=el.value;if(k==='op')c.value=castVal(el.value,String(Array.isArray(c.value)?c.value.join(', '):c.value));write();});
    list.addEventListener('change',e=>{list.dispatchEvent(new Event('input',{bubbles:true}));});
    list.addEventListener('click',e=>{const b=e.target.closest('[data-rm]');if(!b)return;cond.conditions.splice(Number(b.getAttribute('data-rm')),1);renderC();});
    $('#r-add').addEventListener('click',()=>{cond.conditions.push({field:'specialty',op:'=',value:''});renderC();});
    $$('input[name=r-match]').forEach(r=>r.addEventListener('change',()=>{cond.match=r.value;renderC();}));
    const A={persona:$('#a-persona'),delta:$('#a-delta'),setp:$('#a-setp'),clear:$('#a-clear'),tags:$('#a-tags'),cont:$('#a-continue')};
    A.persona.value=act.set_persona!=null?act.set_persona:'';A.delta.value=act.add_priority_delta!=null?act.add_priority_delta:'';A.setp.value=act.set_priority_score!=null?act.set_priority_score:'';
    A.clear.value=act.set_compliance_flag==null?'':(Number(act.set_compliance_flag)?'1':'0');A.tags.value=Array.isArray(act.add_tags)?act.add_tags.join(', '):'';A.cont.checked=!!cond.continue_on_match;
    Object.values(A).forEach(el=>el.addEventListener('input',write));Object.values(A).forEach(el=>el.addEventListener('change',write));
    function write(){cond.continue_on_match=A.cont?A.cont.checked:false;if(!cond.match)cond.match='AND';const out={};
      Object.keys(act).forEach(k=>{if(['set_persona','add_priority_delta','set_priority_score','set_compliance_flag','add_tags'].indexOf(k)<0)out[k]=act[k];});
      if(A.persona.value.trim()!=='')out.set_persona=A.persona.value.trim();
      if(A.delta.value!=='')out.add_priority_delta=Number(A.delta.value);
      if(A.setp.value!=='')out.set_priority_score=Number(A.setp.value);
      if(A.clear.value!=='')out.set_compliance_flag=Number(A.clear.value);
      const tags=A.tags.value.split(',').map(x=>x.trim()).filter(Boolean);if(tags.length)out.add_tags=tags;
      tc.value=JSON.stringify(cond,null,2);ta.value=JSON.stringify(out,null,2);}
    renderC();
  }
  const tb=$('#r-test');
  if(tb)tb.addEventListener('click',async()=>{const box=$('#rule-test');box.innerHTML='<div class="muted small">Testing on every HCP…</div>';
    try{const j=await post('?action=api_rule_test',{conditions_json:tc.value,actions_json:ta.value});
      box.innerHTML='<div class="d-flex between items-center mb-2"><b>Test result</b><span class="chip blue">Matches '+fmt(j.matched)+' of '+fmt(j.total)+' HCPs</span></div>'+
      (j.examples&&j.examples.length?'<div class="diff muted small"><span>HCP</span><span>Before</span><span></span><span>After</span></div>'+j.examples.map(x=>'<div class="diff"><span><b>'+esc(x.name)+'</b> <span class="muted mono">'+esc(x.hcp_id)+'</span></span><span class="sl">'+esc(x.before.persona||'No persona')+' · '+fmtScore(x.before.priority)+'</span><span class="muted">→</span><span><b>'+esc(x.after.persona||'No persona')+' · '+fmtScore(x.after.priority)+'</b></span></div>').join(''):'<div class="muted small">No HCP matches these conditions.</div>')+
      '<div class="help mt-2">Tested in memory against the current data; nothing was saved.</div>';}
    catch(err){box.innerHTML='<div class="alert bad">'+esc(err.message)+'</div>';}});
}

/* ---------- import runner ---------- */
const ir=$('#import-runner');
if(ir){
  const id=ir.getAttribute('data-id');const bar=$('#imp-bar'),txt=$('#imp-text'),btn=$('#imp-fallback');
  let running=false;
  async function run(){if(running)return;running=true;if(btn)btn.hidden=true;
    try{while(true){const j=await post('?action=api_import_chunk&id='+encodeURIComponent(id),{});const pct=j.total?Math.round(j.done/j.total*100):100;
      if(bar)bar.style.width=Math.min(100,pct)+'%';if(txt)txt.textContent=fmt(j.done)+' of '+fmt(j.total)+' rows imported';
      if(j.finished){location.href='?action=import_run&id='+encodeURIComponent(id);return;}}}
    catch(err){if(txt)txt.textContent=err.message;if(btn)btn.hidden=false;running=false;}}
  if(ir.getAttribute('data-autostart')==='1')run();
  const start=$('#imp-start');if(start)start.addEventListener('click',e=>{e.preventDefault();run();});
}
})();
JS;
}


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
    // Custom fields: explicit "custom.X", with bare-name fallback for unknown fields.
    if (str_starts_with($field, 'custom.')) {
        $m = json_decode_safe($profile['metadata'] ?? '{}');
        return $m['custom'][substr($field, 7)] ?? null;
    }
    if (!array_key_exists($field, $profile)) {
        $m = json_decode_safe($profile['metadata'] ?? '{}');
        if (isset($m['custom']) && is_array($m['custom']) && array_key_exists($field, $m['custom'])) {
            return $m['custom'][$field];
        }
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
    $row = fgetcsv($fh, null, ',', '"', '\\');
    if (!is_array($row)) return [];
    return array_map(fn($v) => trim((string)$v), $row);
}

function csv_preview_rows(string $path, int $maxRows): array {
    $fh = csv_open($path);
    if (!$fh) return [[], []];
    $headers = csv_read_headers($fh);
    $rows = [];
    $i = 0;
    while (($r = fgetcsv($fh, null, ',', '"', '\\')) !== false) {
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
    while (($r = fgetcsv($fh, null, ',', '"', '\\')) !== false) {
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

function upsert_profile(PDO $pdo, array $p): string {
    $exists = $pdo->prepare('SELECT id, imports_count, metadata FROM profiles WHERE hcp_id = :h');
    $exists->execute([':h' => (string)$p['hcp_id']]);
    $row = $exists->fetch();
    if (!$row) {
        $stmt = $pdo->prepare('INSERT INTO profiles
            (hcp_id,npi,name,email,specialty,region,organization,role,consent_email,consent_web,last_activity_ts,imports_count,persona,priority_score,compliance_flag,metadata,confidence_score,created_at)
            VALUES
            (:hcp_id,:npi,:name,:email,:specialty,:region,:organization,:role,:consent_email,:consent_web,:last_activity_ts,:imports_count,:persona,:priority_score,:compliance_flag,:metadata,:confidence_score,:created_at)');
        $stmt->execute([
            ':hcp_id' => (string)$p['hcp_id'],
            ':npi' => ($p['npi'] ?? null) !== null && (string)$p['npi'] !== '' ? (string)$p['npi'] : null,
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
    $merged['custom'] = array_merge(
        is_array($existingMeta['custom'] ?? null) ? $existingMeta['custom'] : [],
        is_array($newMeta['custom'] ?? null) ? $newMeta['custom'] : []
    );

    $stmt = $pdo->prepare('UPDATE profiles SET
        name=:name,
        npi=COALESCE(:npi, npi),
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
        ':npi' => ($p['npi'] ?? null) !== null && (string)$p['npi'] !== '' ? (string)$p['npi'] : null,
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

    // Custom fields: "custom.X" is allowed and later translated to a JSON lookup.
    // Neutralize them (and quoted strings) before scanning remaining identifiers.
    $scan = preg_replace('/\bcustom\.[a-zA-Z_][a-zA-Z0-9_]*/', '0', $trim) ?? $trim;
    $scan = preg_replace("/'([^']|'')*'/", "''", $scan) ?? $scan;
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

// Translate custom.X -> json_extract(metadata, '$.custom.X'). Call at EXECUTION time only,
// so stored segment filters stay in the human-readable custom.X form.
function translate_custom_fields_sql(string $sql): string {
    return preg_replace_callback('/\bcustom\.([a-zA-Z_][a-zA-Z0-9_]*)/', function ($mm) {
        return "json_extract(metadata, '$.custom." . $mm[1] . "')";
    }, $sql) ?? $sql;
}

function sqlite_supports_json(PDO $pdo): bool {
    static $supported = null;
    if ($supported === null) {
        try { $pdo->query("SELECT json_extract('{}', '$.a')"); $supported = true; }
        catch (Throwable $e) { $supported = false; }
    }
    return $supported;
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
    refresh_derived($pdo, $CONFIG, get_settings($pdo));
}




/*
====================================================================================================
Auth
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
            redirect('?action=dashboard');
        }
        rate_limit_fail($pdo, $GLOBALS['CONFIG'], 'login', '');
        rate_limit_fail($pdo, $GLOBALS['CONFIG'], 'login', $email);
        audit($pdo, null, 'auth_login_failed', ['email' => $email]);
        flash('danger', 'That email and password don\'t match an account.');
        redirect('?action=login');
    }

    $count = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($count === 0) redirect('?action=register');
    page_header('Sign in', $GLOBALS['CONFIG'], get_settings($pdo), null);
    echo '<div class="card pad"><h1 class="page-title mb-3" style="font-size:20px">Sign in</h1>';
    echo '<form method="post">' . csrf_field();
    echo '<div class="field"><label class="lbl" for="em">Email</label><input class="input" id="em" type="email" name="email" autocomplete="username" required></div>';
    echo '<div class="field"><label class="lbl" for="pw">Password</label><input class="input" id="pw" type="password" name="password" autocomplete="current-password" required></div>';
    echo '<button class="btn btn-primary btn-lg w-100" type="submit">Sign in</button>';
    echo '<div class="help mt-3">No account? If an admin opened registration, you can <a class="link" href="?action=register">create one</a>.</div>';
    echo '</form></div>';
    page_footer();
}

function handle_register(PDO $pdo, array $CONFIG, array $settings): void {
    $count = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $openReg = (bool)($settings['open_registration'] ?? false);
    if ($count > 0 && !$openReg && !($CONFIG['allowOpenRegistrationAfterAdmin'] ?? false)) {
        page_header('Registration closed', $CONFIG, $settings, null);
        echo '<div class="card pad"><h1 class="page-title" style="font-size:20px">Registration is closed</h1>';
        echo '<p class="sl">Ask an admin to open registration in Settings, or to create your account.</p>';
        echo '<a class="btn" href="?action=login">Back to sign in</a></div>';
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
            flash('danger', 'Enter a valid email address.');
            redirect('?action=register');
        }
        if (strlen($pass) < 10) {
            rate_limit_fail($pdo, $GLOBALS['CONFIG'], 'register', '');
            rate_limit_fail($pdo, $GLOBALS['CONFIG'], 'register', $email);
            flash('danger', 'Use a password of at least 10 characters.');
            redirect('?action=register');
        }
        $count = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $role = ($count === 0) ? 'admin' : (in_array($CONFIG['defaultNewUserRole'] ?? 'viewer', ['viewer', 'editor'], true) ? (string)$CONFIG['defaultNewUserRole'] : 'viewer');
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
            flash('success', $role === 'admin' ? 'Admin account created. Load the sample data to see the whole workflow.' : 'Account created with the ' . role_label($role) . ' role.');
            redirect('?action=dashboard');
        } catch (Throwable $e) {
            rate_limit_fail($pdo, $GLOBALS['CONFIG'], 'register', '');
            rate_limit_fail($pdo, $GLOBALS['CONFIG'], 'register', $email);
            flash('danger', 'Couldn\'t create the account. The email may already be registered.');
            redirect('?action=register');
        }
    }

    page_header('Create account', $CONFIG, $settings, null);
    echo '<div class="card pad"><h1 class="page-title mb-2" style="font-size:20px">' . ($count === 0 ? 'Create the admin account' : 'Create an account') . '</h1>';
    if ($count === 0) echo '<p class="sl small mb-3" style="margin-top:0">This is the first account, so it becomes the admin. You can invite others later in Settings.</p>';
    echo '<form method="post">' . csrf_field();
    echo '<div class="field"><label class="lbl" for="em">Email</label><input class="input" id="em" type="email" name="email" autocomplete="username" required></div>';
    echo '<div class="field"><label class="lbl" for="pw">Password</label><input class="input" id="pw" type="password" name="password" autocomplete="new-password" minlength="10" required><div class="help">At least 10 characters.</div></div>';
    echo '<button class="btn btn-primary btn-lg w-100" type="submit">Create account</button>';
    if ($count > 0) echo '<div class="help mt-3"><a class="link" href="?action=login">Back to sign in</a></div>';
    echo '</form></div>';
    page_footer();
}

function handle_logout(PDO $pdo): void {
    $u = current_user();
    if ($u) audit($pdo, (int)$u['id'], 'auth_logout', []);
    $_SESSION = [];
    if (session_id() !== '') session_destroy();
    session_start();
    flash('success', 'Signed out.');
    redirect('?action=login');
}

/*
====================================================================================================
Audit sentences (shared by Overview and the Audit log)
====================================================================================================
*/

function audit_sentence(array $row, array $names): string {
    $a = (string)$row['action'];
    $p = json_decode_safe((string)($row['payload'] ?? '{}'));
    $seg = function ($id) use ($names) { $id = (int)$id; return isset($names['segments'][$id]) ? '“' . $names['segments'][$id] . '”' : 'audience #' . $id; };
    $rule = function ($id, $fallback = null) use ($names) { $id = (int)$id; return isset($names['rules'][$id]) ? '“' . $names['rules'][$id] . '”' : ($fallback ? '“' . $fallback . '”' : 'rule #' . $id); };
    return match ($a) {
        'auth_login' => 'signed in',
        'auth_logout' => 'signed out',
        'auth_login_failed' => 'failed sign-in for ' . (string)($p['email'] ?? 'an unknown email'),
        'auth_register' => 'created an account (' . role_label((string)($p['role'] ?? '')) . ')',
        'import_uploaded' => 'uploaded ' . (string)($p['filename'] ?? 'a CSV'),
        'import_mapping_saved' => 'matched columns for import #' . (int)($p['import_id'] ?? 0),
        'import_chunk_processed' => 'imported ' . fmt_int($p['processed'] ?? 0) . ' rows (import #' . (int)($p['import_id'] ?? 0) . ')',
        'import_completed' => 'finished importing ' . (string)($p['filename'] ?? 'a file') . ': ' . fmt_int($p['inserted'] ?? 0) . ' new, ' . fmt_int($p['updated'] ?? 0) . ' updated',
        'import_failed' => 'import #' . (int)($p['import_id'] ?? 0) . ' stopped with an error',
        'rule_created' => 'created rule ' . $rule($p['rule_id'] ?? 0, $p['name'] ?? null),
        'rule_updated' => 'updated rule ' . $rule($p['rule_id'] ?? 0, $p['name'] ?? null),
        'rule_deleted' => 'deleted rule #' . (int)($p['rule_id'] ?? 0),
        'rules_reordered' => 'changed the order of enrichment rules',
        'rules_reapplied' => 're-applied rules to ' . fmt_int($p['count'] ?? 0) . ' HCPs',
        'segment_created' => 'created audience ' . $seg($p['segment_id'] ?? 0),
        'segment_updated' => 'updated audience ' . $seg($p['segment_id'] ?? 0),
        'segment_deleted' => 'deleted audience #' . (int)($p['segment_id'] ?? 0),
        'segment_previewed' => 'previewed ' . $seg($p['segment_id'] ?? 0),
        'simulation_run', 'plan_saved' => 'saved a plan for ' . $seg($p['segment_id'] ?? 0) . (isset($p['segment_size']) ? ' (' . fmt_int($p['segment_size']) . ' HCPs)' : ''),
        'export_created' => 'exported ' . (isset($p['row_count']) ? fmt_int($p['row_count']) . ' HCPs from ' : '') . $seg($p['segment_id'] ?? 0) . (!empty($p['include_pii']) ? ' with email and name' : '') . (isset($p['preset']) ? ' as ' . (string)(($GLOBALS['CONFIG']['exportPresets'][$p['preset']]['label'] ?? $p['preset'])) : ''),
        'settings_updated' => 'changed settings' . (isset($p['export_pii']) ? ' (PII export ' . ($p['export_pii'] ? 'on' : 'off') . ')' : ''),
        'user_role_changed' => 'changed ' . (string)($p['email'] ?? 'a user') . ' to ' . role_label((string)($p['role'] ?? '')),
        'user_created' => 'created an account for ' . (string)($p['email'] ?? 'a user') . ' (' . role_label((string)($p['role'] ?? '')) . ')',
        'suppression_added' => 'added ' . fmt_int($p['count'] ?? 1) . ' HCP' . ((int)($p['count'] ?? 1) === 1 ? '' : 's') . ' to the suppression list',
        'suppression_removed' => 'removed ' . (string)($p['hcp_id'] ?? 'an HCP') . ' from the suppression list',
        'sample_rules_loaded' => 'loaded ' . fmt_int($p['count'] ?? 0) . ' sample rules',
        'sample_segments_loaded' => 'loaded ' . fmt_int($p['count'] ?? 0) . ' sample audiences',
        'sample_import_created' => 'started the sample import',
        'profile_re_enriched' => 're-ran rules for HCP #' . (int)($p['profile_id'] ?? 0),
        'recompute_all' => 're-applied rules and scores to every HCP',
        'repair_region' => 'filled a missing region for ' . fmt_int($p['count'] ?? 0) . ' HCPs',
        'bulk_consent_update' => 'overwrote consent for every HCP',
        default => str_replace('_', ' ', $a),
    };
}

function audit_names(PDO $pdo): array {
    $n = ['segments' => [], 'rules' => [], 'users' => []];
    foreach ($pdo->query('SELECT id, name FROM segments')->fetchAll() as $r) $n['segments'][(int)$r['id']] = (string)$r['name'];
    foreach ($pdo->query('SELECT id, name FROM rules')->fetchAll() as $r) $n['rules'][(int)$r['id']] = (string)$r['name'];
    foreach ($pdo->query('SELECT id, email FROM users')->fetchAll() as $r) $n['users'][(int)$r['id']] = (string)$r['email'];
    return $n;
}

/*
====================================================================================================
Overview
====================================================================================================
*/

function page_dashboard(PDO $pdo, array $CONFIG, array $settings): void {
    $u = current_user();
    page_header('Overview', $CONFIG, $settings, $u);
    $profiles = (int)$pdo->query('SELECT COUNT(*) FROM profiles')->fetchColumn();
    $rules = (int)$pdo->query('SELECT COUNT(*) FROM rules')->fetchColumn();
    $segments = (int)$pdo->query('SELECT COUNT(*) FROM segments')->fetchColumn();
    $imports = (int)$pdo->query('SELECT COUNT(*) FROM imports')->fetchColumn();
    $exports = (int)$pdo->query('SELECT COUNT(*) FROM exports')->fetchColumn();

    $lastImport = $pdo->query('SELECT filename, created_at FROM imports ORDER BY id DESC LIMIT 1')->fetch();
    $sub = $profiles > 0 ? h(fmt_int($profiles)) . ' HCPs' . ($lastImport ? ' · last import ' . h((string)$lastImport['filename']) . ', ' . h(fmt_ago((int)$lastImport['created_at'])) : '') : 'Your HCP targeting workspace';
    $actions = '';
    if (can('edit')) $actions .= '<a class="btn" href="?action=import">Import HCPs</a><a class="btn btn-primary" href="?action=segment_edit">New audience</a>';
    page_top('Overview', $sub, '', $actions);

    if ($profiles === 0) {
        echo '<div class="card empty"><div class="h">Start with your HCP list</div>';
        echo '<p>Import a CSV from any source: LiteHCP matches the columns for you, checks the data, then scores and enriches every HCP. Or load the built-in sample to see the whole workflow first.</p>';
        echo '<div class="actions" style="justify-content:center">';
        if (can('edit')) echo '<a class="btn btn-primary btn-lg" href="?action=import">Import a CSV</a>';
        if (can('admin')) echo '<form method="post" action="?action=load_sample" class="m-0">' . csrf_field() . '<button class="btn btn-lg" type="submit">Load sample data (200 HCPs)</button></form>';
        echo '</div>';
        if (!can('edit')) echo '<p class="small">Your role can view data but not import it. Ask an admin to load data.</p>';
        echo '</div>';
        page_footer();
        return;
    }

    // Checklist
    $missingEmail = (int)$pdo->query("SELECT COUNT(*) FROM profiles WHERE COALESCE(email,'') = ''")->fetchColumn();
    $noActivity = (int)$pdo->query('SELECT COUNT(*) FROM profiles WHERE last_activity_ts IS NULL')->fetchColumn();
    $invalidNpi = (int)$pdo->query("SELECT COUNT(*) FROM profiles WHERE metadata LIKE '%\"npi_invalid\"%'")->fetchColumn();
    $notes = ($missingEmail > 0 ? 1 : 0) + ($noActivity > 0 ? 1 : 0) + ($invalidNpi > 0 ? 1 : 0);
    $steps = [
        ['Import HCPs', fmt_int($profiles) . ' HCPs from ' . fmt_int($imports) . ' import' . ($imports === 1 ? '' : 's'), $profiles > 0, '?action=import'],
        ['Review data health', $notes ? $notes . ' note' . ($notes === 1 ? '' : 's') . ', nothing blocking' : 'No issues found', $profiles > 0, '#health'],
        ['Check enrichment rules', $rules ? fmt_int($rules) . ' rules active' : 'Add rules for personas and tags', $rules > 0, '?action=rules'],
        ['Build an audience', $segments ? fmt_int($segments) . ' audiences' : 'Pick HCPs by specialty, consent, tier', $segments > 0, '?action=segment_edit'],
        ['Plan and export', $exports ? fmt_int($exports) . ' exports so far' : 'Estimate reach, then export for CRM or email', $exports > 0, '?action=segments'],
    ];
    $doneCount = count(array_filter($steps, fn($s) => $s[2]));
    $nowIdx = null;
    foreach ($steps as $i => $s) if (!$s[2]) { $nowIdx = $i; break; }
    echo '<div class="card pad"><div class="d-flex between items-center"><div class="h">' . ($doneCount === 5 ? 'Your workflow is set up' : 'Get to your first export') . '</div><span class="chip blue">' . $doneCount . ' of 5 done</span></div><div class="steps5">';
    foreach ($steps as $i => $s) {
        $cls = $s[2] ? 'done' : ($i === $nowIdx ? 'now' : '');
        $k = 'Step ' . ($i + 1) . ($s[2] ? ' · Done' : ($i === $nowIdx ? ' · Next' : ''));
        echo '<div class="s5 ' . $cls . '"><div class="k">' . h($k) . '</div><a class="t" href="' . h($s[3]) . '">' . h($s[0]) . '</a><div class="d">' . h($s[1]) . '</div></div>';
    }
    echo '</div></div>';

    // Reachability
    $sup = suppression_sql($pdo);
    $excluded = (int)$pdo->query("SELECT COUNT(*) FROM profiles WHERE NOT {$sup}")->fetchColumn();
    $both = (int)$pdo->query("SELECT COUNT(*) FROM profiles WHERE {$sup} AND consent_email = 1 AND consent_web = 1")->fetchColumn();
    $emailOnly = (int)$pdo->query("SELECT COUNT(*) FROM profiles WHERE {$sup} AND consent_email = 1 AND consent_web = 0")->fetchColumn();
    $webOnly = (int)$pdo->query("SELECT COUNT(*) FROM profiles WHERE {$sup} AND consent_email = 0 AND consent_web = 1")->fetchColumn();
    $none = max(0, $profiles - $excluded - $both - $emailOnly - $webOnly);
    $reachable = $both + $emailOnly + $webOnly;
    $parts = [['Email and web', $both, 'var(--acc)'], ['Email only', $emailOnly, '#7C8CFF'], ['Web only', $webOnly, 'var(--acc-line)'], ['No consent on file', $none, '#D5DBE3'], ['Always excluded', $excluded, '#C2362B']];
    echo '<div class="grid mt-3" id="health" style="grid-template-columns:minmax(0,1.25fr) minmax(0,1fr)"><div class="card pad"><div class="d-flex between"><div class="h">Who you can reach</div><span class="muted small">Consent on file · ' . h(fmt_int($profiles)) . ' HCPs</span></div><div class="stackbar mt-3" role="img" aria-label="Reachability breakdown">';
    foreach ($parts as $pt) if ($pt[1] > 0) echo '<i style="width:' . round($pt[1] / max(1, $profiles) * 100, 2) . '%;background:' . $pt[2] . '" title="' . h($pt[0]) . ': ' . (int)$pt[1] . '"></i>';
    echo '</div><div class="legend">';
    foreach ($parts as $pt) if ($pt[1] > 0) echo '<span><i class="dot" style="background:' . $pt[2] . '"></i>' . h($pt[0]) . '<b>' . h(fmt_int($pt[1])) . '</b></span>';
    echo '</div>';
    if ($invalidNpi > 0) echo '<div class="help mt-2">' . h(fmt_int($invalidNpi)) . ' HCPs had an NPI that failed the check digit; they were kept without an NPI.</div>';
    echo '</div><div class="grid g2">';
    echo '<div class="card pad tile"><div class="v">' . h(fmt_int($reachable)) . '</div><div class="l">reachable HCPs (' . (int)round($reachable / max(1, $profiles) * 100) . '%)</div></div>';
    echo '<a class="card pad tile" href="?action=suppression"><div class="v bad">' . h(fmt_int($excluded)) . '</div><div class="l">always excluded from lists</div></a>';
    echo '<a class="card pad tile" href="?action=profiles&amp;has_email=0"><div class="v">' . h(fmt_int($missingEmail)) . '</div><div class="l">missing email address</div></a>';
    echo '<div class="card pad tile"><div class="v">' . h(fmt_int($noActivity)) . '</div><div class="l">no activity date</div></div>';
    echo '</div></div>';

    // Audiences
    $segs = $pdo->query('SELECT * FROM segments ORDER BY id ASC LIMIT 8')->fetchAll();
    $lastExport = [];
    foreach ($pdo->query('SELECT segment_id, MAX(created_at) t FROM exports GROUP BY segment_id')->fetchAll() as $r) $lastExport[(int)$r['segment_id']] = (int)$r['t'];
    echo '<div class="card mt-3"><div class="card-h"><div class="h">Audiences</div><span class="small sl">Counts already leave out excluded HCPs</span></div>';
    if (!$segs) {
        echo '<div class="empty"><div class="h">No audiences yet</div><p>An audience is a saved list of HCPs, such as "Oncology, email-reachable". Counts update as data changes.</p>' . (can('edit') ? '<a class="btn btn-primary" href="?action=segment_edit">New audience</a>' : '') . '</div>';
    } else {
        echo '<div class="table-wrap"><table class="tbl"><thead><tr><th>Audience</th><th class="r">Matches</th><th class="r">Excluded</th><th class="r">Ready</th><th>Tier mix (A / B / C)</th><th>Last export</th></tr></thead><tbody>';
        foreach ($segs as $s) {
            $st = audience_stats($pdo, $s, $CONFIG, $settings);
            $sid = (int)$s['id'];
            echo '<tr class="click" data-href="?action=segment_edit&amp;id=' . $sid . '"><td><a href="?action=segment_edit&amp;id=' . $sid . '"><b>' . h((string)$s['name']) . '</b></a></td>';
            if (isset($st['error'])) { echo '<td colspan="4" class="bad small">' . h((string)$st['error']) . '</td>'; }
            else {
                echo '<td class="r num">' . h(fmt_int($st['matches'])) . '</td><td class="r num ' . ($st['excluded'] ? 'bad' : 'muted') . '">' . ($st['excluded'] ? '−' . h(fmt_int($st['excluded'])) : '0') . '</td><td class="r num"><b>' . h(fmt_int($st['ready'])) . '</b></td><td>' . tier_mix_bar($st['tiers']) . '</td>';
            }
            echo '<td class="' . (isset($lastExport[$sid]) ? '' : 'muted') . '">' . (isset($lastExport[$sid]) ? h(fmt_ago($lastExport[$sid])) : 'Never') . '</td></tr>';
        }
        echo '</tbody></table></div>';
        if ($segments > count($segs)) echo '<div class="pager"><a class="link" href="?action=segments">All ' . h(fmt_int($segments)) . ' audiences</a></div>';
    }
    echo '</div>';

    // Activity
    $names = audit_names($pdo);
    $recent = $pdo->query('SELECT * FROM audit_log ORDER BY id DESC LIMIT 6')->fetchAll();
    if ($recent) {
        echo '<div class="card mt-3"><div class="card-h"><div class="h">Recent activity</div><a class="link small" href="?action=audit">Audit log</a></div><div class="table-wrap"><table class="tbl"><tbody>';
        foreach ($recent as $r) {
            $who = isset($r['user_id']) && $r['user_id'] !== null ? ($names['users'][(int)$r['user_id']] ?? 'User #' . (int)$r['user_id']) : 'Someone';
            echo '<tr><td class="wrap-cell"><b>' . h($who) . '</b> ' . h(audit_sentence($r, $names)) . '</td><td class="r muted nowrap">' . h(fmt_ago((int)$r['ts'])) . '</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }
    page_footer();
}

function handle_load_sample(PDO $pdo, array $CONFIG, array $settings): void {
    require_perm('admin');
    $u = current_user();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('?action=dashboard');
    try {
        $res = load_sample_data($pdo, $CONFIG, (int)$u['id']);
    } catch (Throwable $e) {
        flash('danger', 'Sample data couldn\'t load: ' . $e->getMessage());
        redirect('?action=dashboard');
    }
    $rules = (int)$pdo->query('SELECT COUNT(*) FROM rules')->fetchColumn();
    $segs = (int)$pdo->query('SELECT COUNT(*) FROM segments')->fetchColumn();
    flash('success', 'Sample loaded: ' . fmt_int($res['rows']) . ' HCPs, ' . $rules . ' rules, ' . $segs . ' audiences.');
    redirect('?action=dashboard');
}


/*
====================================================================================================
Imports (wizard: Upload → Match columns → Check data → Import)
====================================================================================================
*/

function import_wizard(int $step, int $id = 0): void {
    $labels = ['Upload', 'Match columns', 'Check data', 'Import'];
    $links = ['?action=import', '?action=import_map&id=' . $id, '?action=import_check&id=' . $id, '?action=import_run&id=' . $id];
    echo '<nav class="wiz" aria-label="Import steps">';
    foreach ($labels as $i => $l) {
        $n = $i + 1;
        $cls = $n < $step ? 'done' : ($n === $step ? 'now' : '');
        $inner = '<span class="n">' . ($n < $step ? '✓' : $n) . '</span>' . h($l);
        echo ($n < $step && $id > 0) ? '<a class="wz ' . $cls . '" href="' . h($links[$i]) . '">' . $inner . '</a>' : '<div class="wz ' . $cls . '"' . ($n === $step ? ' aria-current="step"' : '') . '>' . $inner . '</div>';
    }
    echo '</nav>';
}

function load_import(PDO $pdo, int $id): array {
    $imp = $pdo->prepare('SELECT * FROM imports WHERE id = :id');
    $imp->execute([':id' => $id]);
    $import = $imp->fetch();
    if (!$import) { flash('danger', 'That import no longer exists.'); redirect('?action=import'); }
    $path = ensure_upload_dir() . DIRECTORY_SEPARATOR . 'import_' . $id . '.csv';
    if (!file_exists($path)) { flash('danger', 'The uploaded file is missing on the server.'); redirect('?action=import'); }
    return [$import, $path, json_decode_safe((string)($import['errors_json'] ?? '{}'))];
}

function import_status(array $im): array {
    $e = json_decode_safe((string)($im['errors_json'] ?? '{}'));
    if (!empty($e['finished_at'])) return ['Finished', 'green', '?action=import_run&id=' . (int)$im['id']];
    if ((int)$im['rows_imported'] > 0) return ['In progress', 'amber', '?action=import_run&id=' . (int)$im['id']];
    if (!empty($e['mapping'])) return ['Ready to import', 'blue', '?action=import_check&id=' . (int)$im['id']];
    return ['Needs matching', '', '?action=import_map&id=' . (int)$im['id']];
}

function page_import(PDO $pdo, array $CONFIG, array $settings): void {
    $u = current_user();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_perm('edit');
        $fail = function (string $m) { flash('danger', $m); redirect('?action=import'); };
        if (!isset($_FILES['csv']) || !is_array($_FILES['csv'])) $fail('Choose a CSV file to upload.');
        $err = (int)($_FILES['csv']['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) $fail('That file is larger than the server allows.');
        if ($err !== UPLOAD_ERR_OK) $fail('The upload didn\'t complete. Try again.');
        $tmp = (string)($_FILES['csv']['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) $fail('The upload didn\'t complete. Try again.');
        $size = (int)($_FILES['csv']['size'] ?? 0);
        if ($size <= 0) $fail('That file is empty.');
        if ($size > (int)$CONFIG['uploadMaxBytes']) $fail('That file is larger than ' . round((int)$CONFIG['uploadMaxBytes'] / 1048576, 1) . ' MB. Split it, or raise UPLOAD_MAX_BYTES.');
        $name = sanitize_filename((string)($_FILES['csv']['name'] ?? 'upload.csv'), 'upload.csv');
        $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, (array)$CONFIG['uploadAllowedExtensions'], true)) $fail('Only .csv files can be imported. In Excel, use File → Save As → CSV.');
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) {
                $mime = finfo_file($fi, $tmp) ?: '';
                finfo_close($fi);
                if ($mime !== '' && !in_array($mime, (array)$CONFIG['uploadAllowedMimeTypes'], true)) $fail('That file doesn\'t look like a CSV.');
            }
        }
        $dir = ensure_upload_dir();
        $pdo->prepare('INSERT INTO imports (filename, rows_total, rows_imported, errors_json, created_at) VALUES (:f,0,0,:e,:t)')
            ->execute([':f' => $name, ':e' => json_encode_safe([]), ':t' => time()]);
        $importId = (int)$pdo->lastInsertId();
        $dest = $dir . DIRECTORY_SEPARATOR . 'import_' . $importId . '.csv';
        if (!@move_uploaded_file($tmp, $dest)) $fail('The server couldn\'t store the file. Check folder permissions.');
        audit($pdo, (int)$u['id'], 'import_uploaded', ['import_id' => $importId, 'filename' => $name]);
        redirect('?action=import_map&id=' . $importId);
    }

    page_header('Imports', $CONFIG, $settings, $u);
    page_top('Imports', 'Bring in HCP lists from any CSV. Columns are matched for you and every row is checked before anything is written.');
    if (can('edit')) {
        import_wizard(1);
        echo '<div class="grid" style="grid-template-columns:minmax(0,1.4fr) minmax(0,1fr)">';
        echo '<form class="card pad" method="post" enctype="multipart/form-data">' . csrf_field();
        echo '<div class="h mb-2">Upload a CSV</div><div class="field"><label class="lbl" for="csv">File</label><input class="input" id="csv" type="file" name="csv" accept=".csv,text/csv" required>';
        echo '<div class="help">Up to ' . h((string)round((int)$CONFIG['uploadMaxBytes'] / 1048576, 1)) . ' MB. The first row must hold column names. Re-importing the same HCP ID updates that HCP instead of duplicating it.</div></div>';
        echo '<button class="btn btn-primary" type="submit">Upload and match columns</button></form>';
        echo '<div class="card pad"><div class="h mb-2">What works</div><ul class="sl small" style="margin:0;padding-left:18px;line-height:1.8">';
        echo '<li>Any column order and names: "NPI", "First name", "Email opt-in" and similar are recognized</li><li>Dates as 2026-09-14, 09/14/2026 or Unix timestamps</li><li>Consent as 1/0, yes/no, true/false or opt-in/opt-out</li><li>Extra columns, such as a KOL score, become custom fields you can filter on</li></ul>';
        if (can('admin') && (int)$pdo->query('SELECT COUNT(*) FROM profiles')->fetchColumn() === 0) {
            echo '<form method="post" action="?action=load_sample" class="mt-3">' . csrf_field() . '<button class="btn" type="submit">Load sample data instead</button></form>';
        }
        echo '</div></div>';
    } else {
        echo '<div class="alert info">Your role can view imports but not upload files.</div>';
    }

    $imports = $pdo->query('SELECT * FROM imports ORDER BY id DESC LIMIT 25')->fetchAll();
    echo '<div class="card mt-3"><div class="card-h"><div class="h">Recent imports</div></div>';
    if (!$imports) {
        echo '<div class="pad muted">No imports yet.</div>';
    } else {
        echo '<div class="table-wrap"><table class="tbl"><thead><tr><th>File</th><th>Status</th><th class="r">Rows</th><th class="r">New</th><th class="r">Updated</th><th>Uploaded</th></tr></thead><tbody>';
        foreach ($imports as $im) {
            [$st, $col, $href] = import_status($im);
            $e = json_decode_safe((string)$im['errors_json']);
            echo '<tr class="click" data-href="' . h($href) . '"><td><a href="' . h($href) . '"><b>' . h((string)$im['filename']) . '</b></a> <span class="muted small">#' . (int)$im['id'] . '</span></td><td><span class="chip ' . h($col) . '">' . h($st) . '</span></td>';
            echo '<td class="r num">' . h(fmt_int($im['rows_imported'])) . ' / ' . h(fmt_int($im['rows_total'])) . '</td><td class="r num">' . h(fmt_int($e['inserted_total'] ?? 0)) . '</td><td class="r num">' . h(fmt_int($e['updated_total'] ?? 0)) . '</td><td class="muted">' . h(fmt_ago((int)$im['created_at'])) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';
    page_footer();
}

function internal_confidence_fields(): array {
    return ['hcp_id','name','email','specialty','region','organization','role','consent_email','consent_web','last_activity_ts','imports_count'];
}

function page_import_map(PDO $pdo, array $CONFIG, array $settings): void {
    require_perm('edit');
    $u = current_user();
    $id = (int)($_GET['id'] ?? 0);
    [$import, $path, $errorsObj] = load_import($pdo, $id);
    $prof = csv_column_profile($path);
    $headers = $prof['headers'];
    if (!$headers) { flash('danger', 'The file has no header row. The first row must hold column names.'); redirect('?action=import'); }
    $auto = auto_map_columns($headers);
    $choices = is_array($errorsObj['choices'] ?? null) ? $errorsObj['choices'] : $auto['map'];
    $customNames = is_array($errorsObj['custom_names'] ?? null) ? $errorsObj['custom_names'] : [];
    $specs = import_field_specs();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $postedChoice = is_array($_POST['col'] ?? null) ? $_POST['col'] : [];
        $postedName = is_array($_POST['cname'] ?? null) ? $_POST['cname'] : [];
        $newChoices = [];
        $newNames = [];
        foreach ($headers as $hh) {
            $k = md5((string)$hh);
            $t = (string)($postedChoice[$k] ?? 'skip');
            if (!isset($specs[$t]) && !in_array($t, ['custom', 'skip'], true)) $t = 'skip';
            $newChoices[$hh] = $t;
            $newNames[$hh] = (string)($postedName[$k] ?? normalize_header((string)$hh));
        }
        [$mapping, $customMap] = mapping_from_choices($newChoices, $newNames);
        if ($mapping['hcp_id'] === '' || $mapping['name'] === '') {
            $errorsObj['choices'] = $newChoices;
            $errorsObj['custom_names'] = $newNames;
            $pdo->prepare('UPDATE imports SET errors_json = :e WHERE id = :id')->execute([':e' => json_encode_safe($errorsObj), ':id' => $id]);
            flash('danger', 'Match one column to HCP ID and one to Full name (or First name + Last name).');
            redirect('?action=import_map&id=' . $id);
        }
        $strategies = ['fixed', 'mode', 'mean', 'leave_null_flag'];
        $impute = [];
        $postedImp = is_array($_POST['impute'] ?? null) ? $_POST['impute'] : [];
        foreach (array_keys($mapping) as $f) {
            $def = $CONFIG['defaultImputation'][$f] ?? ['strategy' => 'leave_null_flag', 'value' => null];
            $s = (string)($postedImp[$f]['strategy'] ?? $def['strategy']);
            if (!in_array($s, $strategies, true)) $s = (string)$def['strategy'];
            $impute[$f] = ['strategy' => $s, 'value' => $postedImp[$f]['value'] ?? $def['value']];
        }
        $mapped = 0;
        foreach (internal_confidence_fields() as $f) if (($mapping[$f] ?? '') !== '') $mapped++;
        $stats = compute_csv_stats_for_imputation($path, $mapping, $impute);
        $errorsObj['mapping'] = $mapping;
        $errorsObj['custom_map'] = $customMap;
        $errorsObj['impute'] = $impute;
        $errorsObj['stats'] = $stats;
        $errorsObj['choices'] = $newChoices;
        $errorsObj['custom_names'] = $newNames;
        $errorsObj['mapping_confidence01'] = $mapped / count(internal_confidence_fields());
        unset($errorsObj['check']);
        $pdo->prepare('UPDATE imports SET errors_json = :e, rows_total = :rt WHERE id = :id')
            ->execute([':e' => json_encode_safe($errorsObj), ':rt' => (int)($stats['rows_total'] ?? 0), ':id' => $id]);
        audit($pdo, (int)$u['id'], 'import_mapping_saved', ['import_id' => $id, 'mapping_confidence01' => $errorsObj['mapping_confidence01']]);
        redirect('?action=import_check&id=' . $id);
    }

    page_header('Match columns', $CONFIG, $settings, $u);
    $matched = count(array_filter($choices, fn($t) => $t !== 'skip'));
    page_top((string)$import['filename'], h(fmt_int(count($headers))) . ' columns · ' . h(fmt_int($prof['rows'])) . ' rows', '<a href="?action=import">Imports</a> / New import');
    import_wizard(2, $id);
    echo '<form method="post">' . csrf_field();
    echo '<div class="split"><div class="card"><div class="card-h"><div class="h">We matched ' . (int)$matched . ' of ' . count($headers) . ' columns</div><span class="small sl">Change any match before continuing</span></div>';
    echo '<div class="table-wrap"><table class="tbl"><thead><tr><th>Your column</th><th>Example</th><th></th><th>LiteHCP field</th><th>Match</th></tr></thead><tbody>';
    foreach ($headers as $hh) {
        $k = md5((string)$hh);
        $cur = (string)($choices[$hh] ?? 'skip');
        $how = $auto['match'][$hh] ?? 'new';
        if (($errorsObj['choices'][$hh] ?? null) !== null && ($auto['map'][$hh] ?? '') !== $cur) $how = 'changed';
        $badge = match (true) {
            $cur === 'skip' => '<span class="chip">Skipped</span>',
            $cur === 'custom' => '<span class="chip blue">New field</span>',
            $how === 'changed' => '<span class="chip blue">Your choice</span>',
            $how === 'synonym' => '<span class="chip green">Recognized</span>',
            default => '<span class="chip green">Exact</span>',
        };
        $ex = (string)($prof['example'][$hh] ?? '');
        $hint = '';
        if (isset($specs[$cur])) {
            $kind = $specs[$cur]['kind'];
            if ($kind === 'date' && $ex !== '') { $ts = parse_date_value($ex); $hint = $ts ? ' · read as ' . fmt_date($ts) : ' · not a date we can read'; }
            if ($kind === 'bool' && $ex !== '') $hint = ' · read as ' . yes_no(parse_boolish($ex));
            if ($kind === 'npi' && $ex !== '') $hint = npi_is_valid(preg_replace('/\D/', '', $ex) ?? '') ? ' · valid NPI' : ' · fails NPI check';
        }
        echo '<tr><td class="mono nowrap">' . h((string)$hh) . '</td><td class="muted">' . h(mb_strimwidth($ex, 0, 28, '…')) . ($ex === '' ? '<span class="muted">empty</span>' : '') . '</td><td class="muted">→</td><td><div class="d-flex gap-2 items-center wrap"><select class="input input-sm" style="width:auto;min-width:200px" name="col[' . $k . ']" data-cname="cn-' . $k . '" aria-label="Field for ' . h((string)$hh) . '">';
        foreach ($specs as $f => $sp) echo '<option value="' . h($f) . '"' . ($cur === $f ? ' selected' : '') . '>' . h($sp['label'] . ($sp['required'] ? ' (required)' : '')) . '</option>';
        echo '<option value="custom"' . ($cur === 'custom' ? ' selected' : '') . '>New custom field…</option><option value="skip"' . ($cur === 'skip' ? ' selected' : '') . '>Don\'t import</option></select>';
        echo '<input class="input input-sm mono" style="width:150px" id="cn-' . $k . '"' . ($cur === 'custom' ? '' : ' hidden') . ' name="cname[' . $k . ']" value="' . h((string)($customNames[$hh] ?? normalize_header((string)$hh))) . '" aria-label="Custom field name" title="Name used if this column becomes a custom field">';
        echo '</div>' . ($hint !== '' ? '<div class="help">' . h(ltrim($hint, ' ·')) . '</div>' : '') . '</td><td>' . $badge . '</td></tr>';
    }
    echo '</tbody></table></div></div>';

    // Missing values: only for matched fields whose column actually has gaps.
    echo '<div class="stack"><div class="card pad"><div class="h">When values are missing</div><div class="help mb-2">Filled values are marked on every HCP and lower its data confidence.</div>';
    $saved = is_array($errorsObj['impute'] ?? null) ? $errorsObj['impute'] : [];
    $any = false;
    $stratLabels = ['fixed' => 'Fill with', 'mode' => 'Most common value', 'mean' => 'Average', 'leave_null_flag' => 'Leave empty and flag'];
    foreach ($choices as $col => $t) {
        if (!isset($CONFIG['defaultImputation'][$t]) && $t !== 'email') continue;
        $empty = (int)($prof['empty'][$col] ?? 0);
        if ($empty === 0) continue;
        $any = true;
        $plan = $saved[$t] ?? ($CONFIG['defaultImputation'][$t] ?? ['strategy' => 'leave_null_flag', 'value' => null]);
        echo '<div class="field"><label class="lbl">' . h(label_for($t)) . ' <span class="muted" style="font-weight:400">· empty in ' . h(plural($empty, 'row', 'rows')) . '</span></label><div class="d-flex gap-2"><select class="input input-sm" style="flex:1.6" name="impute[' . h($t) . '][strategy]">';
        foreach ($stratLabels as $sv => $sl) {
            if ($sv === 'mean' && !in_array($t, ['imports_count'], true)) continue;
            echo '<option value="' . h($sv) . '"' . ((string)($plan['strategy'] ?? '') === $sv ? ' selected' : '') . '>' . h($sl) . '</option>';
        }
        echo '</select><input class="input input-sm" style="flex:1" name="impute[' . h($t) . '][value]" value="' . h(is_scalar($plan['value'] ?? null) ? (string)$plan['value'] : '') . '" placeholder="value" aria-label="Fill value"></div></div>';
    }
    if (!$any) echo '<div class="sl small">No gaps in the matched columns.</div>';
    echo '</div><div class="card pad"><div class="d-flex gap-2"><a class="btn btn-ghost" href="?action=import">Cancel</a><button class="btn btn-primary ms-auto" type="submit">Continue to check</button></div></div></div></div></form>';
    page_footer();
}

function page_import_check(PDO $pdo, array $CONFIG, array $settings): void {
    require_perm('edit');
    $u = current_user();
    $id = (int)($_GET['id'] ?? 0);
    [$import, $path, $errorsObj] = load_import($pdo, $id);
    $mapping = is_array($errorsObj['mapping'] ?? null) ? $errorsObj['mapping'] : [];
    if (!$mapping) redirect('?action=import_map&id=' . $id);
    $check = is_array($errorsObj['check'] ?? null) ? $errorsObj['check'] : null;
    if (!$check) {
        $check = import_check_scan($pdo, $path, $mapping);
        $errorsObj['check'] = $check;
        $pdo->prepare('UPDATE imports SET errors_json = :e WHERE id = :id')->execute([':e' => json_encode_safe($errorsObj), ':id' => $id]);
    }
    page_header('Check data', $CONFIG, $settings, $u);
    page_top((string)$import['filename'], 'Nothing has been written yet. Review what will happen, then start the import.', '<a href="?action=import">Imports</a> / New import');
    import_wizard(3, $id);
    $issues = (int)$check['missing_required'] + (int)$check['invalid_npi'] + (int)$check['bad_dates'] + (int)$check['unknown_consent'];
    echo '<div class="grid g4">';
    echo '<div class="card pad tile"><div class="v">' . h(fmt_int($check['rows'])) . '</div><div class="l">rows in the file</div></div>';
    echo '<div class="card pad tile"><div class="v">' . h(fmt_int($check['new'])) . '</div><div class="l">new HCPs</div></div>';
    echo '<div class="card pad tile"><div class="v">' . h(fmt_int($check['existing'])) . '</div><div class="l">existing HCPs will be updated</div></div>';
    echo '<div class="card pad tile"><div class="v' . ($issues ? ' bad' : '') . '">' . h(fmt_int($issues)) . '</div><div class="l">notes to review</div></div></div>';
    echo '<div class="split mt-3"><div class="card"><div class="card-h"><div class="h">What we found</div></div><div class="pad">';
    $lines = [
        [(int)$check['missing_required'], 'row has no HCP ID or name and will be skipped', 'rows have no HCP ID or name and will be skipped', 'bad'],
        [(int)$check['duplicate_ids'], 'HCP ID appears more than once; the later row wins', 'HCP IDs appear more than once; the later row wins', 'warn'],
        [(int)$check['invalid_npi'], 'NPI fails the check digit; that HCP is kept without an NPI', 'NPIs fail the check digit; those HCPs are kept without an NPI', 'warn'],
        [(int)$check['bad_dates'], 'activity date can\'t be read and will be left empty', 'activity dates can\'t be read and will be left empty', 'warn'],
        [(int)$check['unknown_consent'], 'consent value isn\'t recognized and will be read as No', 'consent values aren\'t recognized and will be read as No', 'warn'],
    ];
    $shown = false;
    foreach ($lines as $l) if ($l[0] > 0) { $shown = true; echo '<div class="kv"><span class="' . $l[3] . '">' . h(plural($l[0], $l[1], $l[2])) . '</span></div>'; }
    if (!$shown) echo '<div class="ok">Every row has an HCP ID and a name, and all values can be read.</div>';
    if ($check['examples']) {
        echo '<div class="h mt-3 mb-1" style="font-size:13.5px">Examples</div><table class="tbl"><tbody>';
        foreach ($check['examples'] as $ex) echo '<tr><td class="muted nowrap">Row ' . (int)$ex['row'] . '</td><td>' . h((string)$ex['issue']) . '</td></tr>';
        echo '</tbody></table>';
    }
    echo '</div></div><div class="card pad"><div class="h mb-2">Then</div><p class="sl small" style="margin-top:0">Each HCP is scored, enrichment rules run, and suppression is applied. Large files import in the background in small chunks, so shared hosting time limits aren\'t a problem.</p>';
    echo '<form method="post" action="?action=import_run&amp;id=' . $id . '">' . csrf_field() . '<input type="hidden" name="mode" value="start"><div class="d-flex gap-2"><a class="btn btn-ghost" href="?action=import_map&amp;id=' . $id . '">Back</a><button class="btn btn-primary ms-auto" type="submit">Import ' . h(fmt_int(max(0, (int)$check['rows'] - (int)$check['missing_required']))) . ' rows</button></div></form></div></div>';
    page_footer();
}

function page_import_run(PDO $pdo, array $CONFIG, array $settings): void {
    require_perm('edit');
    $u = current_user();
    $id = (int)($_GET['id'] ?? 0);
    [$import, $path, $errorsObj] = load_import($pdo, $id);
    if (empty($errorsObj['mapping'])) redirect('?action=import_map&id=' . $id);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $mode = (string)($_POST['mode'] ?? 'continue');
        if ($mode === 'start') redirect('?action=import_run&id=' . $id . '&start=1');
        try {
            $r = import_process_chunk($pdo, $CONFIG, $id, (int)$u['id'], (float)$CONFIG['importSecondsPerChunk']);
            flash('success', 'Imported ' . fmt_int($r['done']) . ' of ' . fmt_int($r['total']) . ' rows.');
        } catch (Throwable $e) {
            flash('danger', $e->getMessage());
        }
        redirect('?action=import_run&id=' . $id);
    }

    page_header('Import', $CONFIG, $settings, $u);
    page_top((string)$import['filename'], '', '<a href="?action=import">Imports</a> / Import #' . $id);
    import_wizard(empty($errorsObj['finished_at']) ? 4 : 5, $id);
    $total = max(1, (int)$import['rows_total']);
    $done = (int)$import['rows_imported'];
    $errors = is_array($errorsObj['import_errors'] ?? null) ? $errorsObj['import_errors'] : [];
    if (!empty($errorsObj['finished_at'])) {
        echo '<div class="card pad"><div class="d-flex between items-center wrap gap-2"><div><div class="h">Import finished</div><div class="sl">' . h(fmt_int($errorsObj['inserted_total'] ?? 0)) . ' new HCPs, ' . h(fmt_int($errorsObj['updated_total'] ?? 0)) . ' updated, ' . h(fmt_int(count($errors))) . ' notes · ' . h(fmt_datetime((int)$errorsObj['finished_at'])) . '</div></div>';
        echo '<div class="actions"><a class="btn" href="?action=profiles">View HCPs</a><a class="btn btn-primary" href="?action=segment_edit">Build an audience</a></div></div><div class="progress mt-3"><i style="width:100%"></i></div></div>';
    } else {
        $autostart = ((string)($_GET['start'] ?? '') === '1' || $done > 0) ? '1' : '0';
        echo '<div class="card pad" id="import-runner" data-id="' . $id . '" data-autostart="' . $autostart . '"><div class="d-flex between items-center"><div class="h">Importing</div><span class="sl small" id="imp-text">' . h(fmt_int($done)) . ' of ' . h(fmt_int($total)) . ' rows imported</span></div>';
        echo '<div class="progress mt-3"><i id="imp-bar" style="width:' . round($done / $total * 100) . '%"></i></div>';
        echo '<form method="post" class="mt-3" id="imp-fallback"' . ($autostart === '1' ? ' hidden' : '') . '>' . csrf_field() . '<input type="hidden" name="mode" value="continue"><button class="btn btn-primary" type="submit" id="imp-start">' . ($done > 0 ? 'Continue import' : 'Start import') . '</button> <span class="help">Keep this page open; the import runs in small chunks.</span></form></div>';
    }
    if ($errors) {
        echo '<div class="card mt-3"><div class="card-h"><div class="h">Notes</div><span class="small sl">Latest ' . min(50, count($errors)) . '</span></div><div class="table-wrap"><table class="tbl"><tbody>';
        foreach (array_slice(array_reverse($errors), 0, 50) as $e) {
            $lvl = (string)($e['level'] ?? 'error');
            echo '<tr><td class="muted nowrap">Row ' . h((string)($e['row'] ?? '')) . '</td><td><span class="chip ' . ($lvl === 'warning' ? 'amber' : 'red') . '">' . ($lvl === 'warning' ? 'Note' : 'Skipped') . '</span></td><td class="wrap-cell">' . h((string)($e['error'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }
    page_footer();
}

function api_import_chunk(PDO $pdo, array $CONFIG): void {
    if (!can('edit')) json_out(['error' => 'Your role cannot import.'], 403);
    $u = current_user();
    $id = (int)($_GET['id'] ?? 0);
    try {
        json_out(import_process_chunk($pdo, $CONFIG, $id, (int)$u['id'], (float)$CONFIG['importSecondsPerChunk']));
    } catch (Throwable $e) {
        json_out(['error' => $e->getMessage()], 500);
    }
}


/*
====================================================================================================
HCPs
====================================================================================================
*/

function page_profiles(PDO $pdo, array $CONFIG, array $settings): void {
    $u = current_user();
    page_header('HCPs', $CONFIG, $settings, $u);
    $g = fn(string $k) => trim((string)($_GET[$k] ?? ''));
    $q = $g('q'); $specialty = $g('specialty'); $region = $g('region'); $tier = $g('tier'); $persona = $g('persona');
    $reach = $g('reach'); $status = $g('status') ?: 'all'; $hasEmail = $g('has_email');
    $sortMap = ['priority' => 'priority_score', 'name' => 'name', 'confidence' => 'confidence_score', 'activity' => 'last_activity_ts', 'decile' => 'priority_decile', 'added' => 'created_at', 'id' => 'hcp_id'];
    $sort = $g('sort') ?: 'priority';
    if (!isset($sortMap[$sort])) $sort = 'priority';
    $dir = strtolower($g('dir')) === 'asc' ? 'ASC' : 'DESC';
    if ($g('dir') === '' && $sort === 'name') $dir = 'ASC';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = 50;

    $where = [];
    $params = [];
    if ($q !== '') { $where[] = '(hcp_id LIKE :q OR name LIKE :q OR npi LIKE :q OR organization LIKE :q' . (can('edit') ? ' OR email LIKE :q' : '') . ')'; $params[':q'] = '%' . $q . '%'; }
    if ($specialty !== '') { $where[] = 'specialty = :s'; $params[':s'] = $specialty; }
    if ($region !== '') { $where[] = 'region = :r'; $params[':r'] = $region; }
    if (in_array($tier, ['A', 'B', 'C'], true)) { $where[] = 'tier = :t'; $params[':t'] = $tier; }
    if ($persona !== '') { $where[] = 'persona = :p'; $params[':p'] = $persona; }
    if ($reach === 'email') $where[] = 'consent_email = 1';
    if ($reach === 'web') $where[] = 'consent_web = 1';
    if ($reach === 'none') $where[] = 'consent_email = 0 AND consent_web = 0';
    if ($hasEmail === '0') $where[] = "COALESCE(email,'') = ''";
    $sup = suppression_sql($pdo);
    if ($status === 'active') $where[] = $sup;
    if ($status === 'excluded') $where[] = "NOT {$sup}";
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $cnt = $pdo->prepare("SELECT COUNT(*) FROM profiles {$whereSql}");
    $cnt->execute($params);
    $total = (int)$cnt->fetchColumn();
    $pages = max(1, (int)ceil($total / $per));
    $page = min($page, $pages);
    $offset = ($page - 1) * $per;
    $col = $sortMap[$sort];
    $stmt = $pdo->prepare("SELECT id, hcp_id, npi, name, specialty, region, organization, consent_email, consent_web, priority_score, priority_decile, confidence_score, tier, persona, CASE WHEN {$sup} THEN 0 ELSE 1 END AS excluded FROM profiles {$whereSql} ORDER BY {$col} {$dir}, id ASC LIMIT {$per} OFFSET {$offset}");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $opts = function (string $column) use ($pdo): array {
        return array_map(fn($r) => (string)$r['v'], $pdo->query("SELECT {$column} AS v, COUNT(*) c FROM profiles WHERE {$column} IS NOT NULL AND {$column} != '' GROUP BY {$column} ORDER BY c DESC LIMIT 60")->fetchAll());
    };
    $all = (int)$pdo->query('SELECT COUNT(*) FROM profiles')->fetchColumn();
    page_top('HCPs', h(fmt_int($all)) . ' HCPs in this workspace. Tiers come from the priority score; excluded HCPs never reach an audience or export.', '', can('edit') ? '<a class="btn" href="?action=import">Import HCPs</a>' : '');

    $qs = function (array $over) use ($q, $specialty, $region, $tier, $persona, $reach, $status, $hasEmail, $sort, $dir): string {
        $base = ['action' => 'profiles', 'q' => $q, 'specialty' => $specialty, 'region' => $region, 'tier' => $tier, 'persona' => $persona, 'reach' => $reach, 'status' => $status === 'all' ? '' : $status, 'has_email' => $hasEmail, 'sort' => $sort, 'dir' => strtolower($dir)];
        $m = array_filter(array_merge($base, $over), fn($v) => $v !== '' && $v !== null);
        return '?' . http_build_query($m);
    };
    $sel = function (string $name, string $label, array $values, string $cur) {
        $o = '<select class="input" name="' . h($name) . '" aria-label="' . h($label) . '" data-autosubmit><option value="">' . h($label) . ': all</option>';
        foreach ($values as $k => $v) { $val = is_int($k) ? $v : $k; $o .= '<option value="' . h((string)$val) . '"' . ((string)$val === $cur ? ' selected' : '') . '>' . h((string)$v) . '</option>'; }
        return $o . '</select>';
    };
    echo '<div class="card"><form class="filters" method="get"><input type="hidden" name="action" value="profiles"><input type="hidden" name="sort" value="' . h($sort) . '"><input type="hidden" name="dir" value="' . h(strtolower($dir)) . '">';
    echo '<input class="input search" type="search" name="q" value="' . h($q) . '" placeholder="Search name, HCP ID, NPI, organization" aria-label="Search">';
    echo $sel('specialty', 'Specialty', $opts('specialty'), $specialty);
    echo $sel('region', 'Region', $opts('region'), $region);
    echo $sel('tier', 'Tier', ['A' => 'Tier A', 'B' => 'Tier B', 'C' => 'Tier C'], $tier);
    echo $sel('persona', 'Persona', $opts('persona'), $persona);
    echo $sel('reach', 'Reachable', ['email' => 'By email', 'web' => 'By web', 'none' => 'No consent'], $reach);
    echo $sel('status', 'Status', ['active' => 'Active', 'excluded' => 'Excluded'], $status === 'all' ? '' : $status);
    if ($hasEmail === '0') echo '<input type="hidden" name="has_email" value="0"><span class="chip amber">Missing email</span>';
    echo '<button class="btn btn-sm" type="submit">Apply</button><a class="btn btn-ghost btn-sm" href="?action=profiles">Reset</a><span class="ms-auto small sl">' . h(fmt_int($total)) . ' match</span></form>';

    $th = function (string $key, string $label, bool $right = false) use ($sort, $dir, $qs): string {
        $isOn = $sort === $key;
        $nd = $isOn && $dir === 'DESC' ? 'asc' : ($isOn ? 'desc' : ($key === 'name' || $key === 'id' ? 'asc' : 'desc'));
        $arrow = $isOn ? ($dir === 'DESC' ? ' ↓' : ' ↑') : '';
        return '<th' . ($right ? ' class="r"' : '') . ($isOn ? ' aria-sort="' . ($dir === 'DESC' ? 'descending' : 'ascending') . '"' : '') . '><a href="' . h($qs(['sort' => $key, 'dir' => $nd, 'page' => null])) . '">' . h($label) . '<span class="sort">' . $arrow . '</span></a></th>';
    };
    echo '<div class="table-wrap"><table class="tbl"><thead><tr><th>Tier</th>' . $th('name', 'HCP') . '<th>Specialty</th><th>Region</th><th>Reachable by</th>' . $th('priority', 'Priority', true) . $th('decile', 'Decile', true) . $th('confidence', 'Confidence', true) . '<th>Status</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $pid = (int)$r['id'];
        echo '<tr class="click" data-href="?action=profile_view&amp;id=' . $pid . '"><td>' . tier_badge($r['tier'] ?? null) . '</td><td><a href="?action=profile_view&amp;id=' . $pid . '"><b>' . h((string)$r['name']) . '</b></a> <span class="muted mono">' . h((string)$r['hcp_id']) . '</span></td>';
        echo '<td>' . h((string)($r['specialty'] ?? '')) . '</td><td>' . h((string)($r['region'] ?? '')) . '</td><td>' . reach_chips($r) . '</td>';
        echo '<td class="r num">' . h(fmt_score($r['priority_score'])) . '</td><td class="r num">' . h($r['priority_decile'] !== null ? (string)$r['priority_decile'] : '—') . '</td><td class="r num">' . h((string)$r['confidence_score']) . '</td>';
        echo '<td>' . ((int)$r['excluded'] === 1 ? '<span class="chip red">Excluded</span>' : '') . '</td></tr>';
    }
    if (!$rows) echo '<tr><td colspan="9"><div class="empty"><div class="h">No HCPs match</div><p>Change the filters or <a class="link" href="?action=profiles">reset them</a>.</p></div></td></tr>';
    echo '</tbody></table></div>';
    if ($pages > 1) {
        echo '<div class="pager"><span>' . h(fmt_int($offset + 1)) . '–' . h(fmt_int(min($total, $offset + $per))) . ' of ' . h(fmt_int($total)) . '</span>';
        if ($page > 1) echo '<a class="btn btn-sm" href="' . h($qs(['page' => $page - 1])) . '">Previous</a>';
        if ($page < $pages) echo '<a class="btn btn-sm" href="' . h($qs(['page' => $page + 1])) . '">Next</a>';
        echo '</div>';
    }
    echo '</div>';
    page_footer();
}

function page_profile_view(PDO $pdo, array $CONFIG, array $settings): void {
    $u = current_user();
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT * FROM profiles WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $p = $stmt->fetch();
    if (!$p) { flash('danger', 'That HCP no longer exists.'); redirect('?action=profiles'); }
    $meta = json_decode_safe((string)($p['metadata'] ?? '{}'));
    $imputed = is_array($meta['imputed_fields'] ?? null) ? $meta['imputed_fields'] : [];
    $rules = $pdo->query('SELECT * FROM rules ORDER BY priority DESC, id ASC')->fetchAll();
    $ex = explain_profile($p, $rules, $CONFIG);
    $reason = suppression_reason($pdo, $p);

    page_header((string)$p['name'], $CONFIG, $settings, $u);
    $sub = '<span class="mono">' . h((string)$p['hcp_id']) . '</span>' . ($p['npi'] ? ' · NPI <span class="mono">' . h((string)$p['npi']) . '</span>' : '') . ' · ' . h((string)($p['specialty'] ?? '')) . ($p['organization'] ? ' · ' . h((string)$p['organization']) : '');
    $actions = '';
    if (can('edit')) $actions .= '<form method="post" action="?action=re_enrich&amp;id=' . $id . '" class="m-0">' . csrf_field() . '<button class="btn" type="submit">Re-run rules</button></form>';
    page_top((string)$p['name'], $sub, '<a href="?action=profiles">HCPs</a> /', $actions);

    if ($reason) {
        echo '<div class="lock mb-3"><div class="ic">' . nav_icon('suppression') . '</div><div style="flex:1"><b>Always excluded</b><div class="sl small">' . h($reason) . '. This HCP never appears in an audience count, preview or export.</div></div>';
        if (can('admin') && str_starts_with($reason, 'On the suppression list')) {
            echo '<form method="post" action="?action=suppression" class="m-0" data-confirm="Remove this HCP from the suppression list?">' . csrf_field() . '<input type="hidden" name="op" value="remove"><input type="hidden" name="hcp_id" value="' . h((string)$p['hcp_id']) . '"><button class="btn btn-sm" type="submit">Remove from list</button></form>';
        }
        echo '</div>';
    }

    echo '<div class="split"><div class="stack">';
    // Why this priority
    echo '<div class="card"><div class="card-h"><div class="h">Why this priority</div><div class="d-flex items-center gap-2">' . tier_badge($p['tier'] ?? null) . '<b class="num">' . h(fmt_score($p['priority_score'])) . '</b><span class="muted small">/ 100</span></div></div><div class="pad">';
    echo '<div class="small sl mb-2">Base score from consent, recency and engagement</div>';
    foreach ($ex['components'] as $c) {
        $max = $c['weight'] * 100;
        echo '<div class="score-row"><span>' . h($c['label']) . '</span><div><div class="bar"><i style="width:' . ($max > 0 ? round($c['points'] / $max * 100) : 0) . '%"></i></div><div class="help">' . h($c['detail']) . '</div></div><span class="r num">' . h(fmt_score($c['points'])) . ' / ' . h(fmt_score($max)) . '</span></div>';
    }
    echo '<div class="kv mt-2"><span class="sl">Base score</span><b class="num">' . h(fmt_score($ex['base'])) . '</b></div>';
    if ($ex['steps']) {
        echo '<div class="small sl mt-3 mb-1">Then enrichment rules, in run order</div>';
        foreach ($ex['steps'] as $s) {
            $delta = $s['to'] - $s['from'];
            echo '<div class="kv"><span>' . h($s['rule']) . ($s['persona'] ? ' <span class="chip blue">' . h((string)$s['persona']) . '</span>' : '') . '</span><span class="num nowrap">' . h(fmt_score($s['from'])) . ' → <b>' . h(fmt_score($s['to'])) . '</b>' . (abs($delta) > 0.001 ? ' <span class="' . ($delta > 0 ? 'ok' : 'bad') . '">(' . ($delta > 0 ? '+' : '') . h(fmt_score($delta)) . ')</span>' : '') . '</span></div>';
        }
    } else {
        echo '<div class="help mt-2">No enrichment rule matches this HCP.</div>';
    }
    $cut = tier_cutoffs($CONFIG, $settings);
    echo '<div class="kv"><span class="sl">Final score (capped at 0–100) and tier</span><b class="num">' . h(fmt_score($ex['final'])) . ' · Tier ' . h(compute_tier($ex['final'], $cut)) . '</b></div>';
    echo '<div class="help">Tier A from ' . h(fmt_score($cut['A'])) . ', tier B from ' . h(fmt_score($cut['B'])) . '. Change cut-offs in Settings.</div>';
    if (abs($ex['final'] - (float)$p['priority_score']) > 0.5) echo '<div class="alert warn mt-2">Rules or data changed since this HCP was last scored. Re-run rules to update it.</div>';
    echo '</div></div>';

    // Details
    echo '<div class="card"><div class="card-h"><div class="h">Details</div>' . ($imputed ? '<span class="small sl"><span class="imputed">Underlined</span> values were filled in on import</span>' : '') . '</div><div class="pad"><dl class="dl">';
    $fields = ['hcp_id', 'npi', 'name', 'email', 'specialty', 'role', 'organization', 'region', 'last_activity_ts', 'imports_count', 'created_at'];
    foreach ($fields as $f) {
        $v = $p[$f] ?? null;
        if ($f === 'email' && !can('edit')) $display = $v ? 'Hidden for viewers' : '—';
        elseif ($f === 'last_activity_ts' || $f === 'created_at') $display = fmt_date($v !== null ? (int)$v : null);
        elseif ($f === 'npi' && !$v && !empty($meta['npi_invalid'])) $display = (string)$meta['npi_invalid'] . ' (failed the check digit)';
        else $display = ($v === null || $v === '') ? '—' : (string)$v;
        $cls = isset($imputed[$f]) ? ' class="imputed" title="Filled in on import"' : '';
        echo '<dt>' . h(label_for($f)) . '</dt><dd><span' . $cls . '>' . h($display) . '</span></dd>';
    }
    $custom = is_array($meta['custom'] ?? null) ? $meta['custom'] : [];
    foreach ($custom as $cf => $cv) echo '<dt>' . h(custom_label((string)$cf)) . ' <span class="chip">custom</span></dt><dd>' . h(is_scalar($cv) ? (string)$cv : json_encode_safe($cv)) . '</dd>';
    echo '</dl></div></div></div>';

    // Side column
    echo '<div class="stack"><div class="card pad"><div class="h mb-2">Status</div>';
    echo '<div class="kv"><span class="sl">Tier</span><span>' . tier_badge($p['tier'] ?? null) . '</span></div>';
    echo '<div class="kv"><span class="sl">Priority decile</span><b class="num">' . h($p['priority_decile'] !== null ? (string)$p['priority_decile'] . ' of 10' : '—') . '</b></div>';
    echo '<div class="kv"><span class="sl">Data confidence</span><b class="num">' . h((string)$p['confidence_score']) . '</b></div>';
    echo '<div class="kv"><span class="sl">Persona</span><span>' . h((string)($p['persona'] ?: '—')) . '</span></div>';
    echo '<div class="kv"><span class="sl">Cleared to contact</span><span>' . h(yes_no($p['compliance_flag'])) . '</span></div>';
    echo '<div class="kv"><span class="sl">Reachable by</span><span>' . reach_chips($p) . '</span></div>';
    echo '<div class="kv"><span class="sl">Email consent</span><span>' . h(yes_no($p['consent_email'])) . (isset($imputed['consent_email']) ? ' <span class="chip">filled</span>' : '') . '</span></div>';
    echo '<div class="kv"><span class="sl">Web consent</span><span>' . h(yes_no($p['consent_web'])) . (isset($imputed['consent_web']) ? ' <span class="chip">filled</span>' : '') . '</span></div></div>';

    // Audiences this HCP is in
    $inAud = [];
    $threshold = (int)($settings['strict_confidence_threshold'] ?? $CONFIG['confidenceStrictThreshold']);
    foreach ($pdo->query('SELECT * FROM segments ORDER BY name')->fetchAll() as $s) {
        $params = [];
        try {
            $w = build_segment_where($pdo, $s, segment_mode($s), $threshold, $params, true);
            $st = $pdo->prepare("SELECT 1 FROM profiles WHERE id = :pid AND {$w}");
            $st->execute(array_merge($params, [':pid' => $id]));
            if ($st->fetchColumn()) $inAud[] = $s;
        } catch (Throwable $e) { /* invalid legacy filter: skip */ }
    }
    echo '<div class="card pad"><div class="h mb-2">Audiences</div>';
    if ($inAud) foreach ($inAud as $s) echo '<div class="kv"><a class="link" href="?action=segment_edit&amp;id=' . (int)$s['id'] . '">' . h((string)$s['name']) . '</a></div>';
    else echo '<div class="sl small">' . ($reason ? 'None: this HCP is excluded.' : 'Not in any saved audience.') . '</div>';
    echo '</div>';

    $tags = is_array($meta['tags'] ?? null) ? $meta['tags'] : [];
    echo '<div class="card pad"><div class="h mb-2">Tags</div>';
    if ($tags) { echo '<div class="pills">'; foreach ($tags as $t) echo '<span class="chip">' . h((string)$t) . '</span>'; echo '</div>'; }
    else echo '<div class="sl small">No tags.</div>';
    echo '</div></div></div>';
    page_footer();
}

function handle_re_enrich(PDO $pdo, array $CONFIG): void {
    require_perm('edit');
    $u = current_user();
    $id = (int)($_GET['id'] ?? 0);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('?action=profile_view&id=' . $id);
    $stmt = $pdo->prepare('SELECT * FROM profiles WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $p = $stmt->fetch();
    if (!$p) { flash('danger', 'That HCP no longer exists.'); redirect('?action=profiles'); }
    $rules = $pdo->query('SELECT * FROM rules ORDER BY priority DESC, id ASC')->fetchAll();
    $meta = reset_rule_trace(json_decode_safe((string)($p['metadata'] ?? '{}')));
    $p['metadata'] = json_encode_safe($meta);
    $p['persona'] = null;
    $p['priority_score'] = compute_base_priority($p, $CONFIG);
    $p = enrich_profile($p, $rules, $CONFIG);
    $pdo->prepare('UPDATE profiles SET persona=:persona, priority_score=:ps, compliance_flag=:cf, metadata=:m WHERE id=:id')->execute([
        ':persona' => $p['persona'] !== null ? (string)$p['persona'] : null,
        ':ps' => (float)$p['priority_score'],
        ':cf' => (int)($p['compliance_flag'] ?? 0),
        ':m' => (string)$p['metadata'],
        ':id' => $id,
    ]);
    refresh_derived($pdo, $CONFIG, get_settings($pdo));
    audit($pdo, (int)$u['id'], 'profile_re_enriched', ['profile_id' => $id]);
    flash('success', 'Rules re-run for this HCP.');
    redirect('?action=profile_view&id=' . $id);
}


/*
====================================================================================================
Enrichment rules
====================================================================================================
*/

function rule_op_labels(): array {
    return ['=' => 'is', '!=' => 'is not', '>=' => 'is at least', '<=' => 'is at most', '>' => 'is more than', '<' => 'is less than', 'in' => 'is any of', 'contains' => 'contains', 'regex' => 'matches pattern'];
}

function rule_summary(array $cond, array $act): string {
    $ops = rule_op_labels();
    $parts = [];
    $list = is_array($cond['conditions'] ?? null) ? $cond['conditions'] : [];
    $flat = true;
    foreach ($list as $c) {
        if (!is_array($c) || !isset($c['field'])) { $flat = false; break; }
        $v = $c['value'] ?? '';
        if (in_array($c['field'], ['consent_email', 'consent_web', 'compliance_flag'], true) && in_array((string)$v, ['0', '1'], true)) $v = yes_no($v);
        $parts[] = label_for((string)$c['field']) . ' ' . ($ops[(string)($c['op'] ?? '')] ?? (string)($c['op'] ?? '')) . ' ' . (is_array($v) ? implode(', ', $v) : (string)$v);
    }
    $when = !$flat || isset($cond['all']) || isset($cond['any']) ? 'custom conditions' : ($parts ? implode(strtoupper((string)($cond['match'] ?? 'AND')) === 'OR' ? ' or ' : ' and ', $parts) : 'every HCP');
    $then = [];
    if (array_key_exists('set_persona', $act)) $then[] = 'persona ' . (string)$act['set_persona'];
    if (array_key_exists('add_priority_delta', $act)) { $d = (float)$act['add_priority_delta']; $then[] = ($d >= 0 ? '+' : '') . fmt_score($d) . ' priority'; }
    if (array_key_exists('set_priority_score', $act)) $then[] = 'priority set to ' . fmt_score($act['set_priority_score']);
    if (array_key_exists('set_compliance_flag', $act)) $then[] = parse_boolish($act['set_compliance_flag']) ? 'cleared to contact' : 'not cleared to contact';
    if (!empty($act['add_tags']) && is_array($act['add_tags'])) $then[] = 'tags ' . implode(', ', array_map('strval', $act['add_tags']));
    return 'When ' . $when . ' → ' . ($then ? implode(', ', $then) : 'no changes');
}

function normalize_rule_priorities(PDO $pdo): array {
    $rules = $pdo->query('SELECT id FROM rules ORDER BY priority DESC, id ASC')->fetchAll();
    $n = count($rules);
    $st = $pdo->prepare('UPDATE rules SET priority = :p WHERE id = :id');
    foreach ($rules as $i => $r) $st->execute([':p' => ($n - $i) * 10, ':id' => (int)$r['id']]);
    return array_map(fn($r) => (int)$r['id'], $rules);
}

function reapply_rules(PDO $pdo, array $CONFIG, int $userId): int {
    cron_recompute_all($pdo, $CONFIG);
    $n = (int)$pdo->query('SELECT COUNT(*) FROM profiles')->fetchColumn();
    audit($pdo, $userId, 'rules_reapplied', ['count' => $n]);
    return $n;
}

function page_rules(PDO $pdo, array $CONFIG, array $settings): void {
    $u = current_user();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $op = (string)($_POST['op'] ?? '');
        if ($op === 'delete') {
            require_perm('admin');
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare('DELETE FROM rules WHERE id = :id')->execute([':id' => $id]);
            audit($pdo, (int)$u['id'], 'rule_deleted', ['rule_id' => $id]);
            flash('success', 'Rule deleted. Re-apply rules to update existing HCPs.');
            redirect('?action=rules');
        }
        if ($op === 'move') {
            require_perm('edit');
            $id = (int)($_POST['id'] ?? 0);
            $dir = (string)($_POST['dir'] ?? '');
            $order = normalize_rule_priorities($pdo);
            $i = array_search($id, $order, true);
            if ($i !== false) {
                $j = $dir === 'up' ? $i - 1 : $i + 1;
                if ($j >= 0 && $j < count($order)) {
                    [$order[$i], $order[$j]] = [$order[$j], $order[$i]];
                    $n = count($order);
                    $st = $pdo->prepare('UPDATE rules SET priority = :p WHERE id = :id');
                    foreach ($order as $k => $rid) $st->execute([':p' => ($n - $k) * 10, ':id' => $rid]);
                    audit($pdo, (int)$u['id'], 'rules_reordered', ['rule_id' => $id, 'dir' => $dir]);
                    flash('success', 'Order changed. Re-apply rules to update existing HCPs.');
                }
            }
            redirect('?action=rules');
        }
        if ($op === 'reapply') {
            require_perm('edit');
            $n = reapply_rules($pdo, $CONFIG, (int)$u['id']);
            flash('success', 'Rules re-applied to ' . fmt_int($n) . ' HCPs.');
            redirect('?action=rules');
        }
    }

    page_header('Enrichment rules', $CONFIG, $settings, $u);
    $rules = $pdo->query('SELECT * FROM rules ORDER BY priority DESC, id ASC')->fetchAll();
    $actions = '';
    if (can('edit')) {
        $actions .= '<form method="post" class="m-0" data-confirm="Re-score every HCP and re-run all rules now?">' . csrf_field() . '<input type="hidden" name="op" value="reapply"><button class="btn" type="submit">Re-apply to all HCPs</button></form>';
        $actions .= '<a class="btn btn-primary" href="?action=rule_edit">New rule</a>';
    }
    page_top('Enrichment rules', 'Rules run top to bottom on every HCP. Each can set a persona, change priority, mark someone cleared to contact or Do Not Contact, and add tags. A rule stops the run unless it is set to keep going.', '', $actions);
    echo '<div class="card">';
    if (!$rules) {
        echo '<div class="empty"><div class="h">No rules yet</div><p>Start with a consent rule: "When email consent is No and web consent is No, set persona Do Not Contact". Do Not Contact HCPs are excluded everywhere.</p>' . (can('edit') ? '<a class="btn btn-primary" href="?action=rule_edit">New rule</a>' : '') . '</div>';
    } else {
        echo '<div class="table-wrap"><table class="tbl"><thead><tr><th>Order</th><th>Rule</th><th class="r">HCPs matched</th><th></th></tr></thead><tbody>';
        $n = count($rules);
        foreach ($rules as $i => $r) {
            $id = (int)$r['id'];
            $cond = json_decode_safe((string)$r['conditions_json']);
            $act = json_decode_safe((string)$r['actions_json']);
            $st = $pdo->prepare('SELECT COUNT(*) FROM profiles WHERE metadata LIKE :p');
            $st->execute([':p' => '%"applied_rule_ids_csv"%,' . $id . ',%']);
            $matched = (int)$st->fetchColumn();
            $cont = (bool)($cond['continue_on_match'] ?? false);
            echo '<tr><td class="nowrap"><span class="num" style="display:inline-block;width:20px">' . ($i + 1) . '</span>';
            if (can('edit')) {
                foreach (['up' => '↑', 'down' => '↓'] as $d => $sym) {
                    $disabled = ($d === 'up' && $i === 0) || ($d === 'down' && $i === $n - 1);
                    echo '<form method="post" class="m-0" style="display:inline">' . csrf_field() . '<input type="hidden" name="op" value="move"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="dir" value="' . $d . '"><button class="btn btn-ghost btn-sm" type="submit" aria-label="Move ' . $d . '"' . ($disabled ? ' disabled' : '') . '>' . $sym . '</button></form>';
                }
            }
            echo '</td><td class="wrap-cell"><a href="?action=rule_edit&amp;id=' . $id . '"><b>' . h((string)$r['name']) . '</b></a>' . (!$cont ? ' <span class="chip" title="Later rules are skipped for HCPs this rule matches">Stops here</span>' : '') . '<span class="sub">' . h(rule_summary($cond, $act)) . '</span></td>';
            echo '<td class="r num">' . h(fmt_int($matched)) . '</td><td class="r nowrap"><a class="btn btn-sm" href="?action=rule_edit&amp;id=' . $id . '">' . (can('edit') ? 'Edit' : 'View') . '</a>';
            if (can('admin')) echo ' <form method="post" class="m-0" style="display:inline" data-confirm="Delete this rule?">' . csrf_field() . '<input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="' . $id . '"><button class="btn btn-sm btn-danger" type="submit">Delete</button></form>';
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';
    page_footer();
}

function page_rule_edit(PDO $pdo, array $CONFIG, array $settings): void {
    $u = current_user();
    $id = (int)($_GET['id'] ?? 0);
    $rule = null;
    if ($id > 0) {
        $stmt = $pdo->prepare('SELECT * FROM rules WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $rule = $stmt->fetch();
        if (!$rule) { flash('danger', 'That rule no longer exists.'); redirect('?action=rules'); }
    } else {
        require_perm('edit');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_perm('edit');
        $name = trim((string)($_POST['name'] ?? ''));
        $back = $id ? ('?action=rule_edit&id=' . $id) : '?action=rule_edit';
        if ($name === '') { flash('danger', 'Give the rule a name.'); redirect($back); }
        $cj = json_decode(trim((string)($_POST['conditions_json'] ?? '')), true);
        $aj = json_decode(trim((string)($_POST['actions_json'] ?? '')), true);
        if (!is_array($cj)) { flash('danger', 'The conditions aren\'t valid JSON.'); redirect($back); }
        if (!is_array($aj)) { flash('danger', 'The actions aren\'t valid JSON.'); redirect($back); }
        $priority = isset($_POST['priority']) && is_numeric($_POST['priority']) ? (int)$_POST['priority'] : null;
        if ($id > 0) {
            $pdo->prepare('UPDATE rules SET name=:n, conditions_json=:c, actions_json=:a, priority=COALESCE(:p, priority) WHERE id=:id')
                ->execute([':n' => $name, ':c' => json_encode_safe($cj), ':a' => json_encode_safe($aj), ':p' => $priority, ':id' => $id]);
            audit($pdo, (int)$u['id'], 'rule_updated', ['rule_id' => $id, 'name' => $name]);
        } else {
            $pos = (string)($_POST['position'] ?? 'last');
            $ext = $pdo->query('SELECT MIN(priority) mn, MAX(priority) mx FROM rules')->fetch();
            $pr = $priority ?? ($pos === 'first' ? ((int)($ext['mx'] ?? 0) + 10) : ((int)($ext['mn'] ?? 10) - 10));
            $pdo->prepare('INSERT INTO rules (name, conditions_json, actions_json, priority, created_at) VALUES (:n,:c,:a,:p,:t)')
                ->execute([':n' => $name, ':c' => json_encode_safe($cj), ':a' => json_encode_safe($aj), ':p' => $pr, ':t' => time()]);
            $id = (int)$pdo->lastInsertId();
            audit($pdo, (int)$u['id'], 'rule_created', ['rule_id' => $id, 'name' => $name]);
        }
        $count = (int)$pdo->query('SELECT COUNT(*) FROM profiles')->fetchColumn();
        if ($count > 0 && $count <= (int)$CONFIG['autoReapplyRulesMaxProfiles']) {
            reapply_rules($pdo, $CONFIG, (int)$u['id']);
            flash('success', 'Rule saved and applied to ' . fmt_int($count) . ' HCPs.');
        } else {
            flash('success', $count > 0 ? 'Rule saved. Re-apply rules (or let cron run) to update existing HCPs.' : 'Rule saved.');
        }
        redirect('?action=rule_edit&id=' . $id);
    }

    $title = $rule ? (string)$rule['name'] : 'New rule';
    page_header($title, $CONFIG, $settings, $u);
    $defaultCond = ['match' => 'AND', 'conditions' => [['field' => 'specialty', 'op' => '=', 'value' => 'oncology'], ['field' => 'consent_email', 'op' => '=', 'value' => 1]], 'continue_on_match' => true];
    $defaultAct = ['set_persona' => 'Oncology Engaged', 'add_priority_delta' => 10, 'add_tags' => ['oncology']];
    $condJson = json_encode(json_decode_safe($rule ? (string)$rule['conditions_json'] : json_encode_safe($defaultCond)), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    $actJson = json_encode(json_decode_safe($rule ? (string)$rule['actions_json'] : json_encode_safe($defaultAct)), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    $readonly = !can('edit');
    $subtitle = $rule ? h(rule_summary(json_decode_safe((string)$rule['conditions_json']), json_decode_safe((string)$rule['actions_json']))) : 'Describe who the rule applies to and what it changes. It is tested on your data before you save.';
    page_top($title, $subtitle, '<a href="?action=rules">Enrichment rules</a> /');

    $fields = [];
    foreach (['specialty', 'region', 'role', 'organization', 'consent_email', 'consent_web', 'imports_count', 'last_activity_ts', 'confidence_score', 'priority_score', 'persona', 'compliance_flag', 'npi', 'hcp_id', 'name', 'email', 'tags'] as $f) $fields[] = ['key' => $f, 'label' => label_for($f)];
    foreach (custom_field_names($pdo) as $cf => $_k) $fields[] = ['key' => 'custom.' . $cf, 'label' => custom_label($cf) . ' (custom)'];
    $ops = [];
    foreach (rule_op_labels() as $k => $v) $ops[] = [$k, $v];
    $personas = array_map(fn($r) => (string)$r['persona'], $pdo->query("SELECT DISTINCT persona FROM profiles WHERE persona IS NOT NULL AND persona != '' ORDER BY persona")->fetchAll());
    foreach ((array)$CONFIG['suppressPersonas'] as $sp) if (!in_array($sp, $personas, true)) $personas[] = (string)$sp;
    $cfg = ['fields' => $fields, 'ops' => $ops];
    echo '<script type="application/json" id="rule-config">' . json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . '</script>';

    echo '<form method="post">' . csrf_field() . '<div class="split"><div class="stack">';
    echo '<div class="card pad"><label class="lbl" for="rname">Rule name</label><input class="input" id="rname" name="name" value="' . h($rule ? (string)$rule['name'] : '') . '" placeholder="e.g. Oncology + email consent" required' . ($readonly ? ' disabled' : '') . '></div>';
    echo '<div id="rule-builder" class="stack">';
    echo '<div class="blk"><div class="blk-h"><span class="chip blue">When</span><span class="match-toggle" role="radiogroup" aria-label="Match"><label><input type="radio" name="r-match" value="AND">all of these are true</label><label><input type="radio" name="r-match" value="OR">any of these is true</label></span></div><div class="blk-b"><div id="r-conds"></div><div class="addl"><button type="button" id="r-add">+ Add condition</button></div><div class="help mt-2">Consent and "cleared to contact" use 1 for Yes and 0 for No. Use "is any of" with a comma-separated list. Fields: any HCP field, tags, or custom fields.</div></div></div>';
    echo '<div class="blk"><div class="blk-h"><span class="chip green">Then</span>update the HCP</div><div class="blk-b">';
    echo '<datalist id="personas">'; foreach ($personas as $pp) echo '<option value="' . h($pp) . '">'; echo '</datalist>';
    echo '<div class="grid g2"><div class="field"><label class="lbl" for="a-persona">Set persona to</label><input class="input" id="a-persona" list="personas" placeholder="no change"><div class="help">"Do Not Contact" excludes the HCP everywhere.</div></div>';
    echo '<div class="field"><label class="lbl" for="a-clear">Cleared to contact</label><select class="input" id="a-clear"><option value="">no change</option><option value="1">Yes</option><option value="0">No</option></select></div>';
    echo '<div class="field"><label class="lbl" for="a-delta">Raise priority by (negative lowers it)</label><input class="input" id="a-delta" type="number" step="any" placeholder="no change"></div>';
    echo '<div class="field"><label class="lbl" for="a-setp">Or set priority to</label><input class="input" id="a-setp" type="number" step="any" min="0" max="100" placeholder="no change"></div></div>';
    echo '<div class="field"><label class="lbl" for="a-tags">Add tags</label><input class="input" id="a-tags" placeholder="comma separated, e.g. oncology, email_ok"></div>';
    echo '<label class="check"><input type="checkbox" id="a-continue"> Keep running later rules after this one matches</label></div></div>';
    echo '</div>';
    echo '<div class="card pad"><div class="d-flex between items-center"><div class="h">Test on your data</div><button class="btn btn-sm" type="button" id="r-test">Test on all HCPs</button></div><div id="rule-test" class="mt-2"><div class="help">Shows how many HCPs match and what would change. Nothing is saved.</div></div></div>';
    echo '<details class="card pad" id="json-details"><summary class="h" style="cursor:pointer">View as JSON</summary><div class="grid g2 mt-3"><div><label class="lbl" for="conditions_json">Conditions</label><textarea class="input" id="conditions_json" name="conditions_json" rows="14"' . ($readonly ? ' readonly' : '') . '>' . h($condJson) . '</textarea></div><div><label class="lbl" for="actions_json">Actions</label><textarea class="input" id="actions_json" name="actions_json" rows="14"' . ($readonly ? ' readonly' : '') . '>' . h($actJson) . '</textarea></div></div>';
    echo '<div class="field mt-3" style="max-width:200px"><label class="lbl" for="prio">Priority number (higher runs first)</label><input class="input" id="prio" type="number" name="priority" value="' . h($rule ? (string)$rule['priority'] : '') . '" placeholder="automatic"' . ($readonly ? ' disabled' : '') . '></div>';
    echo '<div class="help">Advanced: supports nested {"all": [...]} / {"any": [...]} groups and operators =, !=, &gt;, &lt;, &gt;=, &lt;=, in, contains, regex. Actions: set_persona, add_priority_delta, set_priority_score, set_compliance_flag, add_tags.</div></details>';
    echo '</div><div class="stack">';
    $all = $pdo->query('SELECT id, name FROM rules ORDER BY priority DESC, id ASC')->fetchAll();
    echo '<div class="card pad"><div class="h">Run order</div><div class="help mb-2">Rules run top to bottom. Reorder them on the rules list.</div><ul class="order">';
    foreach ($all as $i => $r) echo '<li class="' . ((int)$r['id'] === $id ? 'on' : '') . '">' . h((string)$r['name']) . '<span class="p">' . ($i + 1) . '</span></li>';
    if (!$rule) echo '<li class="on">This new rule<span class="p">' . (count($all) + 1) . '</span></li>';
    echo '</ul>';
    if (!$rule && can('edit')) echo '<div class="field mt-2"><label class="lbl" for="pos">Position</label><select class="input" id="pos" name="position"><option value="last">Run last</option><option value="first">Run first</option></select></div>';
    echo '</div>';
    if (!$readonly) echo '<div class="card pad"><button class="btn btn-primary w-100 btn-lg" type="submit">Save rule</button><div class="help mt-2">Saving re-applies all rules to your HCPs, so audiences update right away.</div></div>';
    else echo '<div class="alert info">Your role can view rules but not change them.</div>';
    echo '</div></div></form>';
    page_footer();
}

function page_rule_test(PDO $pdo, array $CONFIG, array $settings): void {
    $rid = (int)($_GET['rule_id'] ?? 0);
    redirect($rid > 0 ? '?action=rule_edit&id=' . $rid : '?action=rules');
}

function api_rule_test(PDO $pdo, array $CONFIG): void {
    if (!can('view')) json_out(['error' => 'Sign in again.'], 403);
    $body = json_body();
    $cond = json_decode((string)($body['conditions_json'] ?? ''), true);
    $act = json_decode((string)($body['actions_json'] ?? ''), true);
    if (!is_array($cond)) json_out(['error' => 'The conditions aren\'t valid JSON.'], 400);
    if (!is_array($act)) json_out(['error' => 'The actions aren\'t valid JSON.'], 400);
    $total = 0;
    $matched = 0;
    $examples = [];
    $stmt = $pdo->query('SELECT * FROM profiles ORDER BY priority_score DESC, id ASC LIMIT 50000');
    while ($p = $stmt->fetch()) {
        $total++;
        if (!eval_conditions($p, $cond)) continue;
        $matched++;
        if (count($examples) < 5) {
            $after = apply_actions($p, $act, 0, 'test');
            $examples[] = [
                'hcp_id' => (string)$p['hcp_id'], 'name' => (string)$p['name'],
                'before' => ['persona' => $p['persona'], 'priority' => (float)$p['priority_score']],
                'after' => ['persona' => $after['persona'], 'priority' => max(0.0, min(100.0, (float)$after['priority_score']))],
            ];
        }
    }
    json_out(['matched' => $matched, 'total' => $total, 'examples' => $examples]);
}


/*
====================================================================================================
JSON helpers (small API endpoints used by the builders and the import runner)
====================================================================================================
*/

function json_out(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function json_body(): array {
    static $body = null;
    if ($body !== null) return $body;
    $raw = (string)file_get_contents('php://input', false, null, 0, 1048576);
    $d = json_decode($raw, true);
    $body = is_array($d) ? $d : [];
    return $body;
}

// Neutralizes spreadsheet formula injection in exported text cells.
function csv_safe($v): string {
    $s = (string)$v;
    if ($s !== '' && !is_numeric($s) && strpbrk($s[0], "=+-@\t\r") !== false) return "'" . $s;
    return $s;
}

/*
====================================================================================================
Audiences
====================================================================================================
*/

function load_segment(PDO $pdo, int $id): ?array {
    if ($id <= 0) return null;
    $s = $pdo->prepare('SELECT * FROM segments WHERE id = :id');
    $s->execute([':id' => $id]);
    $r = $s->fetch();
    return $r ?: null;
}

// Rule-based audiences from v1.1 open in the builder as "Enrichment rule was applied" conditions.
function segment_definition_for_builder(array $seg): ?array {
    $def = json_decode_safe((string)($seg['definition_json'] ?? ''));
    if ($def && isset($def['conditions'])) return $def;
    if (!empty($seg['sql_filter'])) return null;
    $ids = json_decode_safe((string)($seg['rule_ids'] ?? '[]'));
    $conds = [];
    foreach ($ids as $rid) if ((int)$rid > 0) $conds[] = ['field' => 'rule', 'op' => 'applied', 'value' => (int)$rid];
    return ['match' => 'all', 'conditions' => $conds];
}

function segment_summary(PDO $pdo, array $seg): string {
    $def = json_decode_safe((string)($seg['definition_json'] ?? ''));
    if ($def && isset($def['conditions'])) {
        [$ok, $norm] = validate_definition($pdo, $def);
        return $ok ? definition_summary($pdo, $norm) : (string)$norm;
    }
    if (!empty($seg['sql_filter'])) return 'Filter: ' . (string)$seg['sql_filter'];
    $b = segment_definition_for_builder($seg);
    return $b ? definition_summary($pdo, $b) : 'Every HCP';
}

function page_segments(PDO $pdo, array $CONFIG, array $settings): void {
    $u = current_user();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $op = (string)($_POST['op'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        if ($op === 'delete') {
            require_perm('admin');
            $pdo->prepare('DELETE FROM segments WHERE id = :id')->execute([':id' => $id]);
            audit($pdo, (int)$u['id'], 'segment_deleted', ['segment_id' => $id]);
            flash('success', 'Audience deleted.');
        }
        if ($op === 'duplicate') {
            require_perm('edit');
            $seg = load_segment($pdo, $id);
            if ($seg) {
                $pdo->prepare('INSERT INTO segments (name, rule_ids, sql_filter, definition_json, plan_json, created_at, updated_at) VALUES (:n,:r,:f,:d,:p,:t,:t)')->execute([
                    ':n' => mb_substr((string)$seg['name'] . ' (copy)', 0, 120), ':r' => (string)($seg['rule_ids'] ?? '[]'), ':f' => $seg['sql_filter'], ':d' => $seg['definition_json'], ':p' => $seg['plan_json'], ':t' => time(),
                ]);
                $nid = (int)$pdo->lastInsertId();
                audit($pdo, (int)$u['id'], 'segment_created', ['segment_id' => $nid, 'copied_from' => $id]);
                redirect('?action=segment_edit&id=' . $nid);
            }
        }
        redirect('?action=segments');
    }

    page_header('Audiences', $CONFIG, $settings, $u);
    $segments = $pdo->query('SELECT * FROM segments ORDER BY COALESCE(updated_at, created_at, 0) DESC, id DESC')->fetchAll();
    $sup = suppressed_count($pdo);
    page_top('Audiences', 'Saved groups of HCPs you can count, plan and export. Counts are live and never include the ' . h(fmt_int($sup)) . ' excluded HCP' . ($sup === 1 ? '' : 's') . '.', '', can('edit') ? '<a class="btn btn-primary" href="?action=segment_edit">New audience</a>' : '');
    echo '<div class="card">';
    if (!$segments) {
        echo '<div class="empty"><div class="h">No audiences yet</div><p>Pick specialties, regions, tiers and consent in plain language and see the count change as you go.</p>' . (can('edit') ? '<a class="btn btn-primary" href="?action=segment_edit">New audience</a>' : '') . '</div>';
    } else {
        echo '<div class="table-wrap"><table class="tbl"><thead><tr><th>Audience</th><th class="r">Ready to use</th><th class="r">Excluded</th><th>Tier mix</th><th>Updated</th><th></th></tr></thead><tbody>';
        foreach ($segments as $s) {
            $id = (int)$s['id'];
            $st = audience_stats($pdo, $s, $CONFIG, $settings);
            $when = (int)($s['updated_at'] ?? 0) ?: (int)($s['created_at'] ?? 0);
            echo '<tr class="click" data-href="?action=segment_edit&amp;id=' . $id . '"><td class="wrap-cell"><a href="?action=segment_edit&amp;id=' . $id . '"><b>' . h((string)$s['name']) . '</b></a><span class="sub">' . h(mb_strimwidth(segment_summary($pdo, $s), 0, 140, '…')) . '</span></td>';
            if (isset($st['error'])) {
                echo '<td colspan="3"><span class="chip red">Needs fixing</span> <span class="small sl">' . h((string)$st['error']) . '</span></td>';
            } else {
                echo '<td class="r num"><b>' . h(fmt_int($st['ready'])) . '</b></td><td class="r num">' . ($st['excluded'] ? '<span class="bad">−' . h(fmt_int($st['excluded'])) . '</span>' : '0') . '</td><td>' . tier_mix_bar($st['tiers']) . '</td>';
            }
            echo '<td class="muted nowrap">' . h($when ? fmt_ago($when) : '—') . '</td><td class="r nowrap">';
            if (can('edit')) echo '<form method="post" class="m-0" style="display:inline">' . csrf_field() . '<input type="hidden" name="op" value="duplicate"><input type="hidden" name="id" value="' . $id . '"><button class="btn btn-ghost btn-sm" type="submit">Duplicate</button></form>';
            if (can('admin')) echo ' <form method="post" class="m-0" style="display:inline" data-confirm="Delete this audience? Past exports stay in the history.">' . csrf_field() . '<input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="' . $id . '"><button class="btn btn-sm btn-danger" type="submit">Delete</button></form>';
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';
    page_footer();
}

function plan_defaults(array $seg): array {
    $p = json_decode_safe((string)($seg['plan_json'] ?? ''));
    return [
        'contact' => is_numeric($p['contact_rate'] ?? null) ? (float)$p['contact_rate'] : 60.0,
        'response' => is_numeric($p['response_rate'] ?? null) ? (float)$p['response_rate'] : 8.0,
        'cost' => is_numeric($p['cost_per_contact'] ?? null) ? (float)$p['cost_per_contact'] : 2.5,
        'value' => is_numeric($p['value_per_response'] ?? null) ? (float)$p['value_per_response'] : 120.0,
        'mode' => in_array($p['mode'] ?? '', ['strict', 'inclusive'], true) ? (string)$p['mode'] : 'strict',
        'saved_at' => (int)($p['saved_at'] ?? 0),
    ];
}

function page_segment_edit(PDO $pdo, array $CONFIG, array $settings): void {
    $u = current_user();
    $id = (int)($_GET['id'] ?? 0);
    $seg = load_segment($pdo, $id);
    if ($id > 0 && !$seg) { flash('danger', 'That audience no longer exists.'); redirect('?action=segments'); }
    if (!$seg) require_perm('edit');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_perm('edit');
        $op = (string)($_POST['op'] ?? 'save');
        if ($op === 'save_plan' && $seg) {
            $num = fn(string $k, float $min, float $max) => max($min, min($max, (float)($_POST[$k] ?? 0)));
            $plan = json_decode_safe((string)($seg['plan_json'] ?? ''));
            $plan = array_merge($plan, [
                'contact_rate' => $num('contact_rate', 0, 100), 'response_rate' => $num('response_rate', 0, 100),
                'cost_per_contact' => $num('cost_per_contact', 0, 1e9), 'value_per_response' => $num('value_per_response', 0, 1e9), 'saved_at' => time(),
            ]);
            $pdo->prepare('UPDATE segments SET plan_json = :p, updated_at = :t WHERE id = :id')->execute([':p' => json_encode_safe($plan), ':t' => time(), ':id' => $id]);
            $st = audience_stats($pdo, $seg, $CONFIG, $settings);
            audit($pdo, (int)$u['id'], 'plan_saved', ['segment_id' => $id, 'segment_size' => (int)($st['ready'] ?? 0)] + $plan);
            flash('success', 'Plan saved.');
            redirect('?action=segment_edit&id=' . $id . '#plan');
        }
        $name = trim((string)($_POST['name'] ?? ''));
        $back = $seg ? '?action=segment_edit&id=' . $id : '?action=segment_edit';
        if ($name === '') { flash('danger', 'Give the audience a name.'); redirect($back); }
        $name = mb_substr($name, 0, 120);
        if ($seg && !empty($seg['sql_filter']) && empty($seg['definition_json']) && isset($_POST['sql_filter'])) {
            // Audience saved with a v1.1 filter: keep it as a filter, re-validated.
            $filter = trim((string)$_POST['sql_filter']);
            [$ok, $res] = validate_sql_filter($filter, segment_allowed_fields());
            if (!$ok) { flash('danger', (string)$res); redirect($back); }
            $plan = json_decode_safe((string)($seg['plan_json'] ?? ''));
            $plan['mode'] = ((string)($_POST['mode'] ?? 'strict')) === 'inclusive' ? 'inclusive' : 'strict';
            $pdo->prepare('UPDATE segments SET name=:n, sql_filter=:f, plan_json=:p, updated_at=:t WHERE id=:id')->execute([':n' => $name, ':f' => $filter, ':p' => json_encode_safe($plan), ':t' => time(), ':id' => $id]);
            audit($pdo, (int)$u['id'], 'segment_updated', ['segment_id' => $id]);
            flash('success', 'Audience saved.');
            redirect('?action=segment_edit&id=' . $id);
        }
        [$ok, $norm] = validate_definition($pdo, json_decode((string)($_POST['definition_json'] ?? ''), true));
        if (!$ok) { flash('danger', (string)$norm); redirect($back); }
        $defJson = json_encode_safe($norm);
        if ($seg) {
            $pdo->prepare('UPDATE segments SET name=:n, definition_json=:d, sql_filter=NULL, rule_ids=:r, updated_at=:t WHERE id=:id')->execute([':n' => $name, ':d' => $defJson, ':r' => '[]', ':t' => time(), ':id' => $id]);
            audit($pdo, (int)$u['id'], 'segment_updated', ['segment_id' => $id]);
        } else {
            $pdo->prepare('INSERT INTO segments (name, rule_ids, sql_filter, definition_json, created_at, updated_at) VALUES (:n,:r,NULL,:d,:t,:t)')->execute([':n' => $name, ':r' => '[]', ':d' => $defJson, ':t' => time()]);
            $id = (int)$pdo->lastInsertId();
            audit($pdo, (int)$u['id'], 'segment_created', ['segment_id' => $id]);
        }
        flash('success', 'Audience saved.');
        redirect('?action=segment_edit&id=' . $id);
    }

    $readonly = !can('edit');
    $legacyFilter = $seg && !empty($seg['sql_filter']) && empty($seg['definition_json']);
    $def = $seg ? segment_definition_for_builder($seg) : null;
    if (!$seg) {
        $def = ['match' => 'all', 'conditions' => []];
        $pre = (string)($_GET['specialty'] ?? '');
        if ($pre !== '') $def['conditions'][] = ['field' => 'specialty', 'op' => 'in', 'value' => [$pre]];
    }
    $title = $seg ? (string)$seg['name'] : 'New audience';
    page_header($title, $CONFIG, $settings, $u);
    $stats = $seg ? audience_stats($pdo, $seg, $CONFIG, $settings) : ['matches' => 0, 'ready' => 0, 'excluded' => 0, 'tiers' => ['A' => 0, 'B' => 0, 'C' => 0]];
    if (!$seg) {
        $tmp = ['definition_json' => json_encode_safe($def)];
        $stats = audience_stats($pdo, $tmp, $CONFIG, $settings);
    }
    $universe = (int)$pdo->query('SELECT COUNT(*) FROM profiles')->fetchColumn();
    $sub = $seg ? 'Counts update as you change conditions. Excluded HCPs are removed automatically and can\'t be added back.' : 'Describe who you want to reach. The count updates as you go.';
    page_top($title, $sub, '<a href="?action=segments">Audiences</a> /');

    if (isset($stats['error'])) echo '<div class="alert bad mb-3">' . h((string)$stats['error']) . '</div>';

    // Funnel
    $pct = fn(int $n) => $universe > 0 ? max($n > 0 ? 1 : 0, round($n / $universe * 1000) / 10) : 0;
    echo '<div class="funnel mb-3" aria-live="polite">';
    echo '<div class="fz"><div class="l">All HCPs</div><div class="v" id="f-universe">' . h(fmt_int($universe)) . '</div><div class="bar"><i style="width:100%;background:#C9D1DC"></i></div></div>';
    echo '<div class="fz"><div class="l">Match the conditions</div><div class="v" id="f-matches">' . h(fmt_int($stats['matches'])) . '</div><div class="bar"><i id="b-matches" style="width:' . $pct((int)$stats['matches']) . '%;background:var(--slate)"></i></div></div>';
    echo '<div class="fz"><div class="l">Always excluded</div><div class="v bad" id="f-excluded">' . ($stats['excluded'] ? '−' . h(fmt_int($stats['excluded'])) : '0') . '</div><div class="bar"><i id="b-excluded" style="width:' . $pct((int)$stats['excluded']) . '%;background:var(--bad)"></i></div></div>';
    echo '<div class="fz"><div class="l">Ready to use</div><div class="v" id="f-ready">' . h(fmt_int($stats['ready'])) . '</div><div class="bar"><i id="b-ready" style="width:' . $pct((int)$stats['ready']) . '%"></i></div></div>';
    echo '</div>';

    echo '<div class="split"><div class="stack">';
    echo '<form method="post" id="audience-form" class="stack">' . csrf_field() . '<input type="hidden" name="op" value="save">';
    echo '<div class="card pad"><label class="lbl" for="aname">Audience name</label><input class="input" id="aname" name="name" value="' . h($seg ? (string)$seg['name'] : '') . '" placeholder="e.g. Oncology, email-reachable, West" required maxlength="120"' . ($readonly ? ' disabled' : '') . '></div>';

    if ($legacyFilter) {
        $mode = plan_defaults($seg)['mode'];
        echo '<div class="card pad"><div class="h">Filter</div><div class="help mb-2">This audience was saved with a filter in an earlier version. It still works, and suppression is applied on top. To use the point-and-click builder, create a new audience.</div>';
        echo '<textarea class="input mono" name="sql_filter" rows="3"' . ($readonly ? ' readonly' : '') . '>' . h((string)$seg['sql_filter']) . '</textarea>';
        echo '<div class="field mt-2"><label class="check"><input type="radio" name="mode" value="strict"' . ($mode === 'strict' ? ' checked' : '') . ($readonly ? ' disabled' : '') . '> Only HCPs with data confidence of at least ' . h((string)($settings['strict_confidence_threshold'] ?? $CONFIG['confidenceStrictThreshold'])) . '</label><label class="check"><input type="radio" name="mode" value="inclusive"' . ($mode === 'inclusive' ? ' checked' : '') . ($readonly ? ' disabled' : '') . '> Include lower-confidence HCPs</label></div></div>';
    } else {
        $specs = builder_field_specs($pdo);
        $cfg = ['specs' => $specs, 'ops' => builder_ops(), 'options' => builder_options($pdo, $specs), 'definition' => $def, 'readonly' => $readonly, 'segmentId' => $seg ? (int)$seg['id'] : 0];
        echo '<script type="application/json" id="builder-config">' . json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . '</script>';
        echo '<input type="hidden" name="definition_json" id="definition_json" value="' . h(json_encode_safe($def)) . '">';
        echo '<div class="blk" id="audience-builder"><div class="blk-h"><span>Include HCPs who match</span><span class="match-toggle" role="radiogroup" aria-label="Match"><label><input type="radio" name="match" value="all"' . ($readonly ? ' disabled' : '') . '>all conditions</label><label><input type="radio" name="match" value="any"' . ($readonly ? ' disabled' : '') . '>any condition</label></span><span class="ms-auto small sl" id="builder-status" aria-live="polite"></span></div><div class="blk-b"><div id="conds">';
        // Server-rendered summary for no-JS; replaced by the builder.
        echo '<div class="small sl">' . h($def ? definition_summary($pdo, $def) : '') . '</div>';
        echo '</div>';
        if (!$readonly) {
            echo '<div class="addl mt-2"><span class="muted" style="font-weight:400">Add</span>';
            foreach (['specialty' => 'Specialty', 'region' => 'Region', 'tier' => 'Tier', 'reachable' => 'Reachable by', 'priority_decile' => 'Priority decile', 'confidence_score' => 'Data confidence', 'last_activity_days' => 'Last activity', 'tags' => 'Tag', 'rule' => 'Enrichment rule'] as $f => $l) {
                if (isset($specs[$f])) echo '<button type="button" data-add="' . h($f) . '">+ ' . h($l) . '</button>';
            }
            $firstCustom = null;
            foreach ($specs as $k => $sp) if (!empty($sp['custom'])) { $firstCustom = $k; break; }
            if ($firstCustom) echo '<button type="button" data-add="' . h($firstCustom) . '">+ Custom field</button>';
            echo '</div>';
        }
        echo '</div></div>';
    }
    echo '<div class="lock" id="lock"' . ($stats['excluded'] ? '' : ' hidden') . '><div class="ic">' . nav_icon('suppression') . '</div><div><b><span id="lock-count">' . h(fmt_int($stats['excluded'])) . '</span> matching HCPs are excluded</b><div class="sl small">They are on the suppression list or have the Do Not Contact persona. They are left out of counts, previews and exports, and can\'t be added back here.' . (can('admin') ? ' <a class="link" href="?action=suppression">Manage suppression</a>' : '') . '</div></div></div>';
    if (!$readonly) echo '<div class="d-flex gap-2"><button class="btn btn-primary" type="submit">' . ($seg ? 'Save audience' : 'Save audience') . '</button>' . ($seg ? '' : '<a class="btn btn-ghost" href="?action=segments">Cancel</a>') . '</div>';
    echo '</form>';

    // Preview
    $prev = [];
    if (!isset($stats['error'])) {
        $p = [];
        $w = build_segment_where($pdo, $seg ?: ['definition_json' => json_encode_safe($def)], $seg ? segment_mode($seg) : 'builder', (int)($settings['strict_confidence_threshold'] ?? $CONFIG['confidenceStrictThreshold']), $p, true);
        $q = $pdo->prepare("SELECT id, hcp_id, name, specialty, region, consent_email, consent_web, priority_score, confidence_score, COALESCE(tier,'C') tier FROM profiles WHERE {$w} ORDER BY priority_score DESC, id ASC LIMIT 10");
        $q->execute($p);
        $prev = $q->fetchAll();
    }
    echo '<div class="card"><div class="card-h"><div class="h">Preview</div><span class="small sl" id="preview-count">' . h(fmt_int($stats['ready'])) . ' HCPs, highest priority first</span></div><div class="table-wrap"><table class="tbl"><thead><tr><th>Tier</th><th>HCP</th><th>Specialty</th><th>Region</th><th>Reachable by</th><th class="r">Priority</th><th class="r">Confidence</th></tr></thead><tbody id="preview-body">';
    foreach ($prev as $r) {
        echo '<tr class="click" data-href="?action=profile_view&amp;id=' . (int)$r['id'] . '"><td>' . tier_badge((string)$r['tier']) . '</td><td><a href="?action=profile_view&amp;id=' . (int)$r['id'] . '"><b>' . h((string)$r['name']) . '</b></a> <span class="muted mono">' . h((string)$r['hcp_id']) . '</span></td><td>' . h((string)($r['specialty'] ?? '')) . '</td><td>' . h((string)($r['region'] ?? '')) . '</td><td>' . reach_chips($r) . '</td><td class="r num">' . h(fmt_score($r['priority_score'])) . '</td><td class="r num">' . h((string)$r['confidence_score']) . '</td></tr>';
    }
    if (!$prev) echo '<tr><td colspan="7" class="muted">No HCPs match yet.</td></tr>';
    echo '</tbody></table></div></div>';
    echo '</div>';

    // Side column: tiers, plan, export
    echo '<div class="stack"><div class="card pad"><div class="h mb-2">Ready to use</div><div class="kv"><span class="sl">By tier</span><span class="small" id="tier-mix">Tier A ' . h(fmt_int($stats['tiers']['A'])) . ' · B ' . h(fmt_int($stats['tiers']['B'])) . ' · C ' . h(fmt_int($stats['tiers']['C'])) . '</span></div>';
    $calls = planned_calls_map($CONFIG, $settings);
    echo '<div class="kv"><span class="sl">Planned calls per cycle</span><b class="num">' . h(fmt_int($stats['tiers']['A'] * $calls['A'] + $stats['tiers']['B'] * $calls['B'] + $stats['tiers']['C'] * $calls['C'])) . '</b></div><div class="help">A ' . (int)$calls['A'] . ', B ' . (int)$calls['B'] . ', C ' . (int)$calls['C'] . ' calls per HCP, set in Settings.</div></div>';

    if ($seg) {
        $pl = plan_defaults($seg);
        $cur = (string)$CONFIG['currencySymbol'];
        echo '<form class="card pad" id="plan" method="post" data-size="' . (int)$stats['ready'] . '" data-currency="' . h($cur) . '">' . csrf_field() . '<input type="hidden" name="op" value="save_plan"><div class="h">Plan</div><div class="help mb-2">Estimate reach and return before you export.' . ($pl['saved_at'] ? ' Saved ' . h(fmt_ago($pl['saved_at'])) . '.' : '') . '</div>';
        echo '<div class="grid g2" style="gap:10px"><div><label class="lbl" for="p-contact">Contact rate %</label><input class="input input-sm" id="p-contact" name="contact_rate" type="number" min="0" max="100" step="any" value="' . h(fmt_score($pl['contact'])) . '"' . ($readonly ? ' disabled' : '') . '></div>';
        echo '<div><label class="lbl" for="p-response">Response rate %</label><input class="input input-sm" id="p-response" name="response_rate" type="number" min="0" max="100" step="any" value="' . h(fmt_score($pl['response'])) . '"' . ($readonly ? ' disabled' : '') . '></div>';
        echo '<div><label class="lbl" for="p-cost">Cost per contact (' . h($cur) . ')</label><input class="input input-sm" id="p-cost" name="cost_per_contact" type="number" min="0" step="any" value="' . h((string)$pl['cost']) . '"' . ($readonly ? ' disabled' : '') . '></div>';
        echo '<div><label class="lbl" for="p-value">Value per response (' . h($cur) . ')</label><input class="input input-sm" id="p-value" name="value_per_response" type="number" min="0" step="any" value="' . h((string)$pl['value']) . '"' . ($readonly ? ' disabled' : '') . '></div></div>';
        $contacts = $stats['ready'] * $pl['contact'] / 100;
        $resp = $contacts * $pl['response'] / 100;
        $cost = $contacts * $pl['cost'];
        $val = $resp * $pl['value'];
        echo '<div class="mt-3"><div class="kv"><span class="sl">Contacts</span><b class="num" id="o-contacts">' . h(fmt_int(round($contacts))) . '</b></div><div class="kv"><span class="sl">Responses</span><b class="num" id="o-responses">' . h(fmt_int(round($resp))) . '</b></div>';
        echo '<div class="kv"><span class="sl">Cost</span><b class="num" id="o-cost">' . h(fmt_money($cost)) . '</b></div><div class="kv"><span class="sl">Value</span><b class="num" id="o-value">' . h(fmt_money($val)) . '</b></div><div class="kv"><span class="sl">Return</span><b class="num" id="o-roi">' . ($cost > 0 ? h(rtrim(rtrim(number_format($val / $cost, 1), '0'), '.')) . '× cost' : '—') . '</b></div></div>';
        if (!$readonly) echo '<button class="btn btn-sm mt-3" type="submit">Save plan</button>';
        echo '</form>';

        // Export
        echo '<form class="card pad" method="post" action="?action=export_segment">' . csrf_field() . '<input type="hidden" name="id" value="' . (int)$seg['id'] . '"><div class="h mb-2">Export</div>';
        if (!can('export')) {
            echo '<div class="sl small">Viewers can see audiences but not export them.</div></form>';
        } else {
            $piiOn = (bool)($settings['export_pii'] ?? false);
            $first = true;
            foreach ((array)$CONFIG['exportPresets'] as $k => $pr) {
                $allowed = empty($pr['pii']) || (can('admin') && $piiOn);
                $why = '';
                if (!empty($pr['pii']) && !$allowed) $why = can('admin') ? 'Turn on PII export in Settings to use this.' : 'Only admins can export names and emails.';
                echo '<label class="opt' . ($allowed ? '' : ' off') . '"><input type="radio" name="preset" value="' . h((string)$k) . '"' . ($first && $allowed ? ' checked' : '') . ($allowed ? '' : ' disabled') . '><span><b>' . h((string)$pr['label']) . '</b><span class="help" style="display:block;margin-top:2px">' . h((string)$pr['description']) . ($why ? ' ' . h($why) : '') . '</span></span></label>';
                if ($allowed) $first = false;
            }
            echo '<button class="btn btn-primary w-100" type="submit" data-export-count>Export ' . h(fmt_int($stats['ready'])) . ' HCPs</button><div class="help mt-2">Excluded HCPs are never written to a file. Every export is recorded in the audit log.</div></form>';
        }
    } else {
        echo '<div class="card pad"><div class="h">Plan and export</div><div class="sl small">Save the audience to plan reach and return and to export it.</div></div>';
    }
    echo '</div></div>';
    page_footer();
}

function api_count(PDO $pdo, array $CONFIG, array $settings): void {
    if (!can('view')) json_out(['error' => 'Sign in again.'], 403);
    $b = json_body();
    [$ok, $def] = validate_definition($pdo, $b['definition'] ?? null);
    if (!$ok) json_out(['error' => (string)$def], 400);
    try {
        $universe = (int)$pdo->query('SELECT COUNT(*) FROM profiles')->fetchColumn();
        $where = compile_definition($pdo, $def);
        $sup = suppression_sql($pdo);
        $matches = (int)$pdo->query("SELECT COUNT(*) FROM profiles WHERE ({$where})")->fetchColumn();
        $tiers = ['A' => 0, 'B' => 0, 'C' => 0];
        foreach ($pdo->query("SELECT COALESCE(tier,'C') t, COUNT(*) c FROM profiles WHERE ({$where}) AND {$sup} GROUP BY t")->fetchAll() as $r) $tiers[(string)$r['t']] = (int)$r['c'];
        $ready = array_sum($tiers);
        $steps = [];
        $n = count($def['conditions']);
        if ($n > 1 && $n <= 12) {
            for ($i = 1; $i <= $n; $i++) $steps[] = (int)$pdo->query('SELECT COUNT(*) FROM profiles WHERE (' . compile_definition($pdo, $def, $i) . ')')->fetchColumn();
        } elseif ($n === 1) {
            $steps[] = $matches;
        }
        $preview = [];
        foreach ($pdo->query("SELECT id, hcp_id, name, specialty, region, consent_email, consent_web, priority_score, confidence_score, COALESCE(tier,'C') tier FROM profiles WHERE ({$where}) AND {$sup} ORDER BY priority_score DESC, id ASC LIMIT 10")->fetchAll() as $r) {
            $preview[] = ['id' => (int)$r['id'], 'hcp_id' => (string)$r['hcp_id'], 'name' => (string)$r['name'], 'specialty' => (string)($r['specialty'] ?? ''), 'region' => (string)($r['region'] ?? ''), 'consent_email' => (int)$r['consent_email'], 'consent_web' => (int)$r['consent_web'], 'priority_score' => (float)$r['priority_score'], 'confidence_score' => (int)$r['confidence_score'], 'tier' => (string)$r['tier']];
        }
        json_out(['universe' => $universe, 'matches' => $matches, 'ready' => $ready, 'excluded' => max(0, $matches - $ready), 'tiers' => $tiers, 'steps' => $steps, 'preview' => $preview]);
    } catch (Throwable $e) {
        json_out(['error' => 'Those conditions can\'t be counted: ' . $e->getMessage()], 400);
    }
}

function handle_export_segment(PDO $pdo, array $CONFIG, array $settings): void {
    $u = current_user();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('?action=segments');
    require_perm('export');
    $id = (int)($_POST['id'] ?? 0);
    $seg = load_segment($pdo, $id);
    if (!$seg) { flash('danger', 'That audience no longer exists.'); redirect('?action=segments'); }
    $presetKey = (string)($_POST['preset'] ?? '');
    if ($presetKey === '' && (int)($_POST['include_pii'] ?? 0) === 1) $presetKey = 'email_platform'; // v1.1 form field
    if (!isset($CONFIG['exportPresets'][$presetKey])) $presetKey = 'minimal';
    $preset = export_preset($CONFIG, $presetKey);
    $pii = !empty($preset['pii']);
    if ($pii && !can('admin')) { flash('danger', 'Only admins can export names and emails.'); redirect('?action=segment_edit&id=' . $id); }
    if ($pii && !(bool)($settings['export_pii'] ?? false)) { flash('danger', 'PII export is off. An admin can turn it on in Settings.'); redirect('?action=segment_edit&id=' . $id); }
    rate_limit_assert($pdo, $CONFIG, 'export', '');
    try {
        [$rows, $fields] = export_rows($pdo, $CONFIG, $settings, $seg, $presetKey);
    } catch (Throwable $e) {
        flash('danger', $e->getMessage());
        redirect('?action=segment_edit&id=' . $id);
    }
    $st = audience_stats($pdo, $seg, $CONFIG, $settings);
    $slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower((string)$seg['name'])), '-') ?: 'audience';
    $filename = 'litehcp_' . substr($slug, 0, 40) . '_' . $presetKey . '_' . date('Ymd_His') . '.csv';
    $pdo->prepare('INSERT INTO exports (segment_id, filename, created_at, row_count, excluded_count, preset, user_id, include_pii) VALUES (:sid,:fn,:t,:rc,:ec,:p,:u,:pii)')
        ->execute([':sid' => $id, ':fn' => $filename, ':t' => time(), ':rc' => count($rows), ':ec' => (int)($st['excluded'] ?? 0), ':p' => $presetKey, ':u' => (int)$u['id'], ':pii' => $pii ? 1 : 0]);
    $pdo->prepare('UPDATE segments SET last_run_at = :t WHERE id = :id')->execute([':t' => time(), ':id' => $id]);
    audit($pdo, (int)$u['id'], 'export_created', ['segment_id' => $id, 'filename' => $filename, 'preset' => $presetKey, 'include_pii' => $pii, 'row_count' => count($rows), 'excluded_count' => (int)($st['excluded'] ?? 0), 'fields' => $fields]);
    rate_limit_hit($pdo, $CONFIG, 'export', '');

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'wb');
    fputcsv($out, $fields, ',', '"', '\\');
    foreach ($rows as $r) fputcsv($out, array_map('csv_safe', array_values($r)), ',', '"', '\\');
    fclose($out);
    exit;
}

function page_segment_run(PDO $pdo, array $CONFIG, array $settings): void {
    $id = (int)($_GET['id'] ?? $_GET['segment_id'] ?? 0);
    redirect($id > 0 ? '?action=segment_edit&id=' . $id : '?action=segments');
}

function page_simulator(PDO $pdo, array $CONFIG, array $settings): void {
    $id = (int)($_GET['segment_id'] ?? $_GET['id'] ?? 0);
    redirect($id > 0 ? '?action=segment_edit&id=' . $id . '#plan' : '?action=segments');
}


/*
====================================================================================================
Exports history
====================================================================================================
*/

function page_exports(PDO $pdo, array $CONFIG, array $settings): void {
    $u = current_user();
    page_header('Exports', $CONFIG, $settings, $u);
    page_top('Exports', 'Every export, who made it and what it contained. Files are generated on demand and never stored on the server; export again from the audience to get a fresh copy.');
    $rows = $pdo->query('SELECT e.*, s.name AS segment_name, us.email AS user_email FROM exports e LEFT JOIN segments s ON s.id = e.segment_id LEFT JOIN users us ON us.id = e.user_id ORDER BY e.id DESC LIMIT 200')->fetchAll();
    echo '<div class="card">';
    if (!$rows) {
        echo '<div class="empty"><div class="h">No exports yet</div><p>Open an audience and choose a format: a minimal CSV, a CRM target list with tiers and planned calls, or an email platform list.</p><a class="btn btn-primary" href="?action=segments">Go to audiences</a></div>';
    } else {
        echo '<div class="table-wrap"><table class="tbl"><thead><tr><th>When</th><th>Audience</th><th>Format</th><th class="r">HCPs</th><th class="r">Excluded</th><th>By</th><th>File</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $preset = (string)($r['preset'] ?? '');
            $label = $preset !== '' ? (string)($CONFIG['exportPresets'][$preset]['label'] ?? $preset) : 'Minimal CSV (v1.1)';
            $seg = $r['segment_name'] !== null ? '<a class="link" href="?action=segment_edit&amp;id=' . (int)$r['segment_id'] . '">' . h((string)$r['segment_name']) . '</a>' : '<span class="muted">Deleted audience #' . (int)$r['segment_id'] . '</span>';
            echo '<tr><td class="nowrap" title="' . h(fmt_datetime((int)$r['created_at'])) . '">' . h(fmt_ago((int)$r['created_at'])) . '</td><td>' . $seg . '</td><td>' . h($label) . ((int)($r['include_pii'] ?? 0) ? ' <span class="chip amber">Names and emails</span>' : '') . '</td>';
            echo '<td class="r num">' . ($preset !== '' ? h(fmt_int($r['row_count'])) : '<span class="muted">—</span>') . '</td><td class="r num">' . ($preset !== '' ? h(fmt_int($r['excluded_count'])) : '<span class="muted">—</span>') . '</td><td class="muted">' . h((string)($r['user_email'] ?? '—')) . '</td><td class="mono muted">' . h((string)$r['filename']) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';
    page_footer();
}

/*
====================================================================================================
Suppression
====================================================================================================
*/

function parse_id_list(string $raw): array {
    $ids = preg_split('/[\s,;]+/', $raw) ?: [];
    $out = [];
    foreach ($ids as $id) {
        $id = trim($id, " \t\"'");
        if ($id !== '' && strlen($id) <= 64 && !in_array($id, $out, true)) $out[] = $id;
    }
    return $out;
}

function page_suppression(PDO $pdo, array $CONFIG, array $settings): void {
    $u = current_user();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_perm('admin');
        $op = (string)($_POST['op'] ?? '');
        if ($op === 'add') {
            $ids = parse_id_list((string)($_POST['hcp_ids'] ?? ''));
            $reason = trim((string)($_POST['reason'] ?? ''));
            $other = trim((string)($_POST['reason_other'] ?? ''));
            if ($reason === 'Other' && $other !== '') $reason = $other;
            $reason = mb_substr($reason !== '' ? $reason : 'Added manually', 0, 120);
            if (!$ids) { flash('danger', 'Paste at least one HCP ID.'); redirect('?action=suppression'); }
            if (count($ids) > 50000) { flash('danger', 'Add at most 50,000 IDs at a time.'); redirect('?action=suppression'); }
            $ins = $pdo->prepare('INSERT OR IGNORE INTO suppressions (hcp_id, reason, source, created_at, created_by) VALUES (:h,:r,:s,:t,:u)');
            $known = $pdo->prepare('SELECT 1 FROM profiles WHERE hcp_id = :h');
            $added = 0;
            $unknown = 0;
            $pdo->beginTransaction();
            foreach ($ids as $id) {
                $ins->execute([':h' => $id, ':r' => $reason, ':s' => 'manual', ':t' => time(), ':u' => (int)$u['id']]);
                if ($ins->rowCount() > 0) {
                    $added++;
                    $known->execute([':h' => $id]);
                    if (!$known->fetchColumn()) $unknown++;
                }
            }
            $pdo->commit();
            audit($pdo, (int)$u['id'], 'suppression_added', ['count' => $added, 'reason' => $reason]);
            $dupes = count($ids) - $added;
            $msg = 'Added ' . plural($added, 'HCP', 'HCPs') . ' to the suppression list.';
            if ($dupes > 0) $msg .= ' ' . plural($dupes, 'was', 'were') . ' already on it.';
            if ($unknown > 0) $msg .= ' ' . plural($unknown, 'isn\'t', 'aren\'t') . ' in your data yet and will be excluded if imported later.';
            flash('success', $msg);
            redirect('?action=suppression');
        }
        if ($op === 'remove') {
            $hid = trim((string)($_POST['hcp_id'] ?? ''));
            $pdo->prepare('DELETE FROM suppressions WHERE hcp_id = :h')->execute([':h' => $hid]);
            audit($pdo, (int)$u['id'], 'suppression_removed', ['hcp_id' => $hid]);
            flash('success', $hid . ' removed from the suppression list. HCPs with the Do Not Contact persona stay excluded.');
            $back = (string)($_POST['back'] ?? '');
            redirect($back === 'profile' ? '?action=profiles&q=' . rawurlencode($hid) : '?action=suppression');
        }
        redirect('?action=suppression');
    }

    page_header('Suppression', $CONFIG, $settings, $u);
    $personas = (array)$CONFIG['suppressPersonas'];
    $total = suppressed_count($pdo);
    $in = implode(',', array_map(fn($p) => $pdo->quote((string)$p), $personas ?: ['__none__']));
    $byPersona = (int)$pdo->query("SELECT COUNT(*) FROM profiles WHERE COALESCE(persona,'') IN ({$in})")->fetchColumn();
    $listed = (int)$pdo->query('SELECT COUNT(*) FROM suppressions')->fetchColumn();
    $listedInData = (int)$pdo->query('SELECT COUNT(*) FROM suppressions s JOIN profiles p ON p.hcp_id = s.hcp_id')->fetchColumn();
    page_top('Suppression', 'Excluded HCPs never appear in an audience count, preview or export, for any role. There is no override.');

    echo '<div class="grid g3"><div class="card pad tile"><div class="v">' . h(fmt_int($total)) . '</div><div class="l">HCPs excluded right now</div></div>';
    echo '<div class="card pad tile"><div class="v">' . h(fmt_int($byPersona)) . '</div><div class="l">with the ' . h(implode(' / ', $personas)) . ' persona (set by <a class="link" href="?action=rules">rules</a>)</div></div>';
    echo '<div class="card pad tile"><div class="v">' . h(fmt_int($listed)) . '</div><div class="l">on the suppression list' . ($listed !== $listedInData ? ' (' . h(fmt_int($listed - $listedInData)) . ' not imported yet)' : '') . '</div></div></div>';

    echo '<div class="split mt-3"><div class="stack">';
    $rows = $pdo->query('SELECT s.*, p.id AS pid, p.name AS pname, us.email AS by_email FROM suppressions s LEFT JOIN profiles p ON p.hcp_id = s.hcp_id LEFT JOIN users us ON us.id = s.created_by ORDER BY s.id DESC LIMIT 500')->fetchAll();
    echo '<div class="card"><div class="card-h"><div class="h">Suppression list</div><span class="small sl">' . ($listed > 500 ? 'Latest 500 of ' . h(fmt_int($listed)) : h(fmt_int($listed)) . ' HCPs') . '</span></div>';
    if (!$rows) {
        echo '<div class="pad sl small">The list is empty. Add HCPs who opted out, are on the AMA PDRP list, or must not be contacted for any other reason.</div>';
    } else {
        echo '<div class="table-wrap"><table class="tbl"><thead><tr><th>HCP</th><th>Reason</th><th>Added</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $who = $r['pid'] !== null ? '<a href="?action=profile_view&amp;id=' . (int)$r['pid'] . '"><b>' . h((string)$r['pname']) . '</b></a> <span class="muted mono">' . h((string)$r['hcp_id']) . '</span>' : '<span class="mono">' . h((string)$r['hcp_id']) . '</span> <span class="chip">Not imported</span>';
            echo '<tr><td>' . $who . '</td><td>' . h((string)$r['reason']) . '</td><td class="muted nowrap" title="' . h(fmt_datetime((int)$r['created_at'])) . '">' . h(fmt_ago((int)$r['created_at'])) . ($r['by_email'] ? ' by ' . h((string)$r['by_email']) : '') . '</td><td class="r">';
            if (can('admin')) echo '<form method="post" class="m-0" data-confirm="Remove ' . h((string)$r['hcp_id']) . ' from the suppression list? They can appear in audiences and exports again.">' . csrf_field() . '<input type="hidden" name="op" value="remove"><input type="hidden" name="hcp_id" value="' . h((string)$r['hcp_id']) . '"><button class="btn btn-sm" type="submit">Remove</button></form>';
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';

    $pr = $pdo->query("SELECT id, hcp_id, name, specialty, persona FROM profiles WHERE COALESCE(persona,'') IN ({$in}) ORDER BY name LIMIT 200")->fetchAll();
    echo '<div class="card"><div class="card-h"><div class="h">Excluded by persona</div><span class="small sl">' . h(fmt_int($byPersona)) . ' HCPs</span></div>';
    if (!$pr) {
        echo '<div class="pad sl small">No HCP has the ' . h(implode(' / ', $personas)) . ' persona. Add a rule such as "When email consent is No and web consent is No, set persona Do Not Contact".</div>';
    } else {
        echo '<div class="table-wrap"><table class="tbl"><tbody>';
        foreach ($pr as $r) echo '<tr class="click" data-href="?action=profile_view&amp;id=' . (int)$r['id'] . '"><td><a href="?action=profile_view&amp;id=' . (int)$r['id'] . '"><b>' . h((string)$r['name']) . '</b></a> <span class="muted mono">' . h((string)$r['hcp_id']) . '</span></td><td>' . h((string)($r['specialty'] ?? '')) . '</td><td><span class="chip red">' . h((string)$r['persona']) . '</span></td></tr>';
        echo '</tbody></table></div>';
        if ($byPersona > 200) echo '<div class="pager">Showing 200 of ' . h(fmt_int($byPersona)) . '. <a class="link" href="?action=profiles&amp;status=excluded">See all excluded HCPs</a></div>';
    }
    echo '</div></div>';

    echo '<div class="stack">';
    if (can('admin')) {
        echo '<form class="card pad" method="post">' . csrf_field() . '<input type="hidden" name="op" value="add"><div class="h mb-2">Add to the list</div>';
        echo '<div class="field"><label class="lbl" for="hcp_ids">HCP IDs</label><textarea class="input mono" id="hcp_ids" name="hcp_ids" rows="6" placeholder="One per line, or paste a column from a spreadsheet" required></textarea><div class="help">IDs that aren\'t imported yet are kept, so those HCPs are excluded the moment they arrive.</div></div>';
        echo '<div class="field"><label class="lbl" for="reason">Reason</label><select class="input" id="reason" name="reason">';
        foreach (['Opted out', 'AMA PDRP restriction', 'Deceased or retired', 'Legal or compliance hold', 'Other'] as $r) echo '<option>' . h($r) . '</option>';
        echo '</select><input class="input mt-2" name="reason_other" placeholder="If other, describe" maxlength="120"></div>';
        echo '<button class="btn btn-primary w-100" type="submit">Add to suppression list</button></form>';
    } else {
        echo '<div class="alert info">Only admins can change the suppression list.</div>';
    }
    echo '<div class="card pad"><div class="h mb-2">How exclusion works</div><ul class="sl small" style="margin:0;padding-left:18px;line-height:1.7"><li>An HCP is excluded if it is on this list or an enrichment rule gives it the ' . h(implode(' / ', $personas)) . ' persona.</li><li>Exclusion is applied inside every count, preview and export query. It can\'t be switched off per audience or per user.</li><li>Each change here is recorded in the audit log.</li></ul></div>';
    echo '</div></div>';
    page_footer();
}

/*
====================================================================================================
Audit log
====================================================================================================
*/

function audit_groups(): array {
    return [
        'exports' => ['Exports', ['export_created']],
        'audiences' => ['Audiences', ['segment_created', 'segment_updated', 'segment_deleted', 'segment_previewed', 'plan_saved', 'simulation_run']],
        'rules' => ['Rules', ['rule_created', 'rule_updated', 'rule_deleted', 'rules_reordered', 'rules_reapplied', 'profile_re_enriched', 'recompute_all']],
        'imports' => ['Imports', ['import_uploaded', 'import_mapping_saved', 'import_chunk_processed', 'import_completed', 'import_failed', 'sample_import_created', 'sample_rules_loaded', 'sample_segments_loaded']],
        'suppression' => ['Suppression', ['suppression_added', 'suppression_removed']],
        'access' => ['Sign-ins and users', ['auth_login', 'auth_logout', 'auth_login_failed', 'auth_register', 'user_created', 'user_role_changed']],
        'settings' => ['Settings and repairs', ['settings_updated', 'repair_region', 'bulk_consent_update']],
    ];
}

function page_audit(PDO $pdo, array $CONFIG, array $settings): void {
    $u = current_user();
    page_header('Audit log', $CONFIG, $settings, $u);
    page_top('Audit log', 'Who did what, and when. Entries can\'t be edited or deleted from the app.');
    if (!can('edit')) {
        echo '<div class="alert info">Only editors and admins can see the audit log.</div>';
        page_footer();
        return;
    }
    $groups = audit_groups();
    $g = (string)($_GET['type'] ?? '');
    $who = (int)($_GET['user'] ?? 0);
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = 50;
    $where = [];
    $params = [];
    if (isset($groups[$g])) {
        $ph = [];
        foreach ($groups[$g][1] as $i => $a) { $ph[] = ':a' . $i; $params[':a' . $i] = $a; }
        $where[] = 'action IN (' . implode(',', $ph) . ')';
    }
    if ($who > 0) { $where[] = 'user_id = :u'; $params[':u'] = $who; }
    $ws = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $c = $pdo->prepare("SELECT COUNT(*) FROM audit_log {$ws}");
    $c->execute($params);
    $total = (int)$c->fetchColumn();
    $pages = max(1, (int)ceil($total / $per));
    $page = min($page, $pages);
    $off = ($page - 1) * $per;
    $s = $pdo->prepare("SELECT * FROM audit_log {$ws} ORDER BY id DESC LIMIT {$per} OFFSET {$off}");
    $s->execute($params);
    $rows = $s->fetchAll();
    $names = audit_names($pdo);

    echo '<div class="card"><form class="filters" method="get"><input type="hidden" name="action" value="audit"><select class="input" name="type" aria-label="Type" data-autosubmit><option value="">All activity</option>';
    foreach ($groups as $k => $gg) echo '<option value="' . h($k) . '"' . ($g === $k ? ' selected' : '') . '>' . h($gg[0]) . '</option>';
    echo '</select><select class="input" name="user" aria-label="User" data-autosubmit><option value="0">Everyone</option>';
    foreach ($names['users'] as $id => $email) echo '<option value="' . (int)$id . '"' . ($who === (int)$id ? ' selected' : '') . '>' . h($email) . '</option>';
    echo '</select><button class="btn btn-sm" type="submit">Apply</button><span class="ms-auto small sl">' . h(fmt_int($total)) . ' entries</span></form>';
    echo '<div class="table-wrap"><table class="tbl"><thead><tr><th>When</th><th>Who</th><th>What</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $email = $r['user_id'] !== null ? ($names['users'][(int)$r['user_id']] ?? 'user #' . (int)$r['user_id']) : 'System';
        echo '<tr><td class="nowrap muted" title="' . h(fmt_datetime((int)$r['ts'])) . '">' . h(fmt_datetime((int)$r['ts'])) . '</td><td class="nowrap">' . h($email) . '</td><td class="wrap-cell">' . h(ucfirst(audit_sentence($r, $names))) . '</td></tr>';
    }
    if (!$rows) echo '<tr><td colspan="3" class="muted">Nothing recorded yet.</td></tr>';
    echo '</tbody></table></div>';
    if ($pages > 1) {
        $qs = fn(int $p) => '?' . http_build_query(array_filter(['action' => 'audit', 'type' => $g, 'user' => $who ?: null, 'page' => $p]));
        echo '<div class="pager"><span>Page ' . $page . ' of ' . $pages . '</span>' . ($page > 1 ? '<a class="btn btn-sm" href="' . h($qs($page - 1)) . '">Newer</a>' : '') . ($page < $pages ? '<a class="btn btn-sm" href="' . h($qs($page + 1)) . '">Older</a>' : '') . '</div>';
    }
    echo '</div>';
    page_footer();
}

/*
====================================================================================================
Settings
====================================================================================================
*/

function page_settings(PDO $pdo, array $CONFIG, array $settings): void {
    $u = current_user();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_perm('admin');
        $op = (string)($_POST['op'] ?? '');
        if ($op === 'save') {
            $settings['export_pii'] = isset($_POST['export_pii']);
            $settings['open_registration'] = isset($_POST['open_registration']);
            $settings['strict_confidence_threshold'] = max(0, min(100, (int)($_POST['strict_confidence_threshold'] ?? 70)));
            $a = max(0.0, min(100.0, (float)($_POST['tier_a'] ?? 80)));
            $b = max(0.0, min($a, (float)($_POST['tier_b'] ?? 50)));
            $settings['tier_cutoffs'] = ['A' => $a, 'B' => $b];
            $settings['planned_calls'] = ['A' => max(0, min(99, (int)($_POST['calls_a'] ?? 6))), 'B' => max(0, min(99, (int)($_POST['calls_b'] ?? 3))), 'C' => max(0, min(99, (int)($_POST['calls_c'] ?? 1)))];
            set_settings($pdo, $settings);
            refresh_derived($pdo, $CONFIG, $settings);
            audit($pdo, (int)$u['id'], 'settings_updated', ['export_pii' => $settings['export_pii'], 'open_registration' => $settings['open_registration'], 'strict_confidence_threshold' => $settings['strict_confidence_threshold'], 'tier_cutoffs' => $settings['tier_cutoffs'], 'planned_calls' => $settings['planned_calls']]);
            flash('success', 'Settings saved. Tiers were recalculated.');
            redirect('?action=settings');
        }
        if ($op === 'role') {
            $uid = (int)($_POST['user_id'] ?? 0);
            $role = (string)($_POST['role'] ?? '');
            if (!in_array($role, ['viewer', 'editor', 'admin'], true)) redirect('?action=settings');
            if ($uid === (int)$u['id'] && $role !== 'admin') { flash('danger', 'You can\'t remove your own admin role. Ask another admin.'); redirect('?action=settings#users'); }
            $st = $pdo->prepare('SELECT email FROM users WHERE id = :id');
            $st->execute([':id' => $uid]);
            $email = $st->fetchColumn();
            if ($email === false) redirect('?action=settings#users');
            $pdo->prepare('UPDATE users SET role = :r WHERE id = :id')->execute([':r' => $role, ':id' => $uid]);
            audit($pdo, (int)$u['id'], 'user_role_changed', ['user_id' => $uid, 'email' => (string)$email, 'role' => $role]);
            flash('success', (string)$email . ' is now ' . role_label($role) . '.');
            redirect('?action=settings#users');
        }
        if ($op === 'add_user') {
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            $pass = (string)($_POST['password'] ?? '');
            $role = (string)($_POST['role'] ?? 'viewer');
            if (!in_array($role, ['viewer', 'editor', 'admin'], true)) $role = 'viewer';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { flash('danger', 'Enter a valid email address.'); redirect('?action=settings#users'); }
            if (strlen($pass) < 10) { flash('danger', 'The temporary password needs at least 10 characters.'); redirect('?action=settings#users'); }
            try {
                $pdo->prepare('INSERT INTO users (email, password_hash, role, created_at) VALUES (:e,:p,:r,:t)')->execute([':e' => $email, ':p' => password_hash($pass, PASSWORD_DEFAULT), ':r' => $role, ':t' => time()]);
            } catch (Throwable $e) {
                flash('danger', 'An account with that email already exists.');
                redirect('?action=settings#users');
            }
            audit($pdo, (int)$u['id'], 'user_created', ['email' => $email, 'role' => $role]);
            flash('success', 'Account created for ' . $email . '. Share the temporary password with them directly.');
            redirect('?action=settings#users');
        }
        if ($op === 'repair_region') {
            $newRegion = mb_substr(trim((string)($_POST['region'] ?? 'Unknown')), 0, 60) ?: 'Unknown';
            $stmt = $pdo->query("SELECT id, metadata FROM profiles WHERE region IS NULL OR region = ''");
            $n = 0;
            $up = $pdo->prepare('UPDATE profiles SET region = :r, metadata = :m WHERE id = :id');
            $pdo->beginTransaction();
            while ($p = $stmt->fetch()) {
                $meta = reset_rule_trace(json_decode_safe((string)($p['metadata'] ?? '{}')));
                $up->execute([':r' => $newRegion, ':m' => json_encode_safe($meta), ':id' => (int)$p['id']]);
                $n++;
            }
            $pdo->commit();
            audit($pdo, (int)$u['id'], 'repair_region', ['region' => $newRegion, 'count' => $n]);
            flash('success', 'Region set to "' . $newRegion . '" for ' . fmt_int($n) . ' HCPs. Re-apply rules if any rule depends on region.');
            redirect('?action=settings#maintenance');
        }
        if ($op === 'bulk_consent') {
            if (trim((string)($_POST['confirm'] ?? '')) !== 'OVERWRITE') { flash('danger', 'Type OVERWRITE to confirm the bulk consent change.'); redirect('?action=settings#maintenance'); }
            $ce = isset($_POST['consent_email']) ? 1 : 0;
            $cw = isset($_POST['consent_web']) ? 1 : 0;
            $pdo->prepare('UPDATE profiles SET consent_email = :ce, consent_web = :cw')->execute([':ce' => $ce, ':cw' => $cw]);
            audit($pdo, (int)$u['id'], 'bulk_consent_update', ['consent_email' => $ce, 'consent_web' => $cw]);
            cron_recompute_all($pdo, $CONFIG);
            flash('success', 'Consent overwritten for every HCP and scores recalculated.');
            redirect('?action=settings#maintenance');
        }
        if ($op === 'recompute_all') {
            cron_recompute_all($pdo, $CONFIG);
            audit($pdo, (int)$u['id'], 'recompute_all', []);
            flash('success', 'Scores, rules and tiers recalculated for every HCP.');
            redirect('?action=settings#maintenance');
        }
        redirect('?action=settings');
    }

    page_header('Settings', $CONFIG, $settings, $u);
    page_top('Settings', 'Workspace rules for exports, scoring tiers, people and maintenance.');
    $admin = can('admin');
    $dis = $admin ? '' : ' disabled';
    if (!$admin) echo '<div class="alert info mb-3">You are signed in as ' . h(role_label(user_role())) . '. Only admins can change settings.</div>';
    $cut = tier_cutoffs($CONFIG, $settings);
    $calls = planned_calls_map($CONFIG, $settings);

    echo '<form method="post">' . csrf_field() . '<input type="hidden" name="op" value="save"><div class="grid g2">';
    echo '<div class="card pad"><div class="h mb-2">Data protection</div>';
    echo '<label class="check"><input type="checkbox" name="export_pii"' . (!empty($settings['export_pii']) ? ' checked' : '') . $dis . '> Allow admins to export names and emails</label><div class="help mb-2">Only the Email platform format includes them, and only for HCPs with email consent. Each export is audited.</div>';
    echo '<label class="check"><input type="checkbox" name="open_registration"' . (!empty($settings['open_registration']) ? ' checked' : '') . $dis . '> Let anyone with the link create an account</label><div class="help mb-2">New accounts get the ' . h(role_label((string)$CONFIG['defaultNewUserRole'])) . ' role. Leave off and add people below instead.</div>';
    echo '<div class="field mt-2"><label class="lbl" for="th">Confidence threshold for filter-based audiences</label><input class="input" id="th" type="number" min="0" max="100" name="strict_confidence_threshold" value="' . h((string)($settings['strict_confidence_threshold'] ?? $CONFIG['confidenceStrictThreshold'])) . '" style="max-width:120px"' . $dis . '><div class="help">Applies to audiences saved with a filter in v1.1. Builder audiences use a Data confidence condition instead.</div></div></div>';

    echo '<div class="card pad"><div class="h mb-2">Tiers and call plan</div><div class="help mb-2">Tiers come from the priority score. Planned calls per cycle are added to CRM target list exports.</div>';
    echo '<div class="grid g2" style="gap:10px"><div><label class="lbl" for="ta">Tier A from score</label><input class="input" id="ta" type="number" step="any" min="0" max="100" name="tier_a" value="' . h(fmt_score($cut['A'])) . '"' . $dis . '></div><div><label class="lbl" for="tb">Tier B from score</label><input class="input" id="tb" type="number" step="any" min="0" max="100" name="tier_b" value="' . h(fmt_score($cut['B'])) . '"' . $dis . '></div></div>';
    echo '<div class="grid g3 mt-3" style="gap:10px"><div><label class="lbl" for="ca">Calls, tier A</label><input class="input" id="ca" type="number" min="0" max="99" name="calls_a" value="' . (int)$calls['A'] . '"' . $dis . '></div><div><label class="lbl" for="cb">Calls, tier B</label><input class="input" id="cb" type="number" min="0" max="99" name="calls_b" value="' . (int)$calls['B'] . '"' . $dis . '></div><div><label class="lbl" for="cc">Calls, tier C</label><input class="input" id="cc" type="number" min="0" max="99" name="calls_c" value="' . (int)$calls['C'] . '"' . $dis . '></div></div>';
    $mix = ['A' => 0, 'B' => 0, 'C' => 0];
    foreach ($pdo->query("SELECT COALESCE(tier,'C') t, COUNT(*) c FROM profiles WHERE " . suppression_sql($pdo) . ' GROUP BY t')->fetchAll() as $r) $mix[(string)$r['t']] = (int)$r['c'];
    echo '<div class="kv mt-3"><span class="sl">Current mix (excluding suppressed)</span><span class="small">A ' . h(fmt_int($mix['A'])) . ' · B ' . h(fmt_int($mix['B'])) . ' · C ' . h(fmt_int($mix['C'])) . '</span></div></div>';
    echo '</div>';
    if ($admin) echo '<div class="mt-3"><button class="btn btn-primary" type="submit">Save settings</button></div>';
    echo '</form>';

    // People and roles
    $users = $pdo->query('SELECT id, email, role, created_at FROM users ORDER BY id')->fetchAll();
    echo '<div class="card mt-3" id="users"><div class="card-h"><div class="h">People and roles</div><span class="small sl">Viewers see counts and HCPs. Editors also import, build and export. Admins manage suppression, settings and people.</span></div>';
    echo '<div class="table-wrap"><table class="tbl"><thead><tr><th>Email</th><th>Role</th><th>Added</th></tr></thead><tbody>';
    foreach ($users as $us) {
        $role = (string)$us['role'] === 'user' ? 'editor' : (string)$us['role'];
        echo '<tr><td>' . h((string)$us['email']) . ((int)$us['id'] === (int)$u['id'] ? ' <span class="chip">You</span>' : '') . '</td><td>';
        if ($admin) {
            echo '<form method="post" class="m-0 d-flex gap-2 items-center">' . csrf_field() . '<input type="hidden" name="op" value="role"><input type="hidden" name="user_id" value="' . (int)$us['id'] . '"><select class="input input-sm" name="role" style="width:auto" aria-label="Role for ' . h((string)$us['email']) . '" data-autosubmit>';
            foreach (['viewer', 'editor', 'admin'] as $r) echo '<option value="' . $r . '"' . ($role === $r ? ' selected' : '') . '>' . h(role_label($r)) . '</option>';
            echo '</select><noscript><button class="btn btn-sm" type="submit">Save</button></noscript></form>';
        } else {
            echo h(role_label($role));
        }
        echo '</td><td class="muted">' . h(fmt_date((int)$us['created_at'])) . '</td></tr>';
    }
    echo '</tbody></table></div>';
    if ($admin) {
        echo '<form method="post" class="pad d-flex gap-2 wrap items-center" style="border-top:1px solid var(--rule-soft)">' . csrf_field() . '<input type="hidden" name="op" value="add_user"><input class="input" style="max-width:240px" type="email" name="email" placeholder="name@company.com" required aria-label="Email"><input class="input" style="max-width:200px" type="text" name="password" placeholder="Temporary password" minlength="10" required aria-label="Temporary password" autocomplete="off"><select class="input" style="width:auto" name="role" aria-label="Role"><option value="viewer">Viewer</option><option value="editor">Editor</option><option value="admin">Admin</option></select><button class="btn" type="submit">Add person</button></form>';
    }
    echo '</div>';

    // Maintenance
    $missingRegion = (int)$pdo->query("SELECT COUNT(*) FROM profiles WHERE region IS NULL OR region = ''")->fetchColumn();
    echo '<div class="grid g2 mt-3" id="maintenance"><div class="card pad"><div class="h mb-2">Maintenance</div>';
    if ($admin) {
        echo '<form method="post" class="mb-3" data-confirm="Re-score every HCP and re-run all rules now?">' . csrf_field() . '<input type="hidden" name="op" value="recompute_all"><button class="btn" type="submit">Recalculate all scores and rules</button><div class="help">Runs now. For large databases, use the cron job instead.</div></form>';
        echo '<form method="post" class="mb-3">' . csrf_field() . '<input type="hidden" name="op" value="repair_region"><label class="lbl" for="rg">Fill missing region (' . h(fmt_int($missingRegion)) . ' HCPs)</label><div class="d-flex gap-2"><input class="input" id="rg" name="region" value="Unknown" style="max-width:200px"><button class="btn" type="submit"' . ($missingRegion ? '' : ' disabled') . '>Apply</button></div></form>';
        echo '<details><summary class="small sl" style="cursor:pointer">Overwrite consent for every HCP</summary><form method="post" class="mt-2" data-confirm="This replaces the consent of every HCP in the workspace. Continue?">' . csrf_field() . '<input type="hidden" name="op" value="bulk_consent"><div class="alert warn mb-2">This replaces recorded consent for all HCPs and can\'t be undone. Prefer re-importing a file with the correct consent columns.</div>';
        echo '<label class="check"><input type="checkbox" name="consent_email"> Email consent: Yes</label><label class="check"><input type="checkbox" name="consent_web"> Web consent: Yes</label>';
        echo '<label class="lbl mt-2" for="cf">Type OVERWRITE to confirm</label><div class="d-flex gap-2"><input class="input" id="cf" name="confirm" autocomplete="off" style="max-width:200px"><button class="btn btn-danger" type="submit">Overwrite consent</button></div></form></details>';
    } else {
        echo '<div class="sl small">Only admins can run maintenance.</div>';
    }
    echo '</div><div class="card pad"><div class="h mb-2">Scheduled recalculation</div><p class="sl small" style="margin-top:0">Add a cron job so scores, recency and tiers stay current without anyone pressing a button:</p>';
    $path = __FILE__;
    echo '<pre class="mono" id="cron-line" style="white-space:pre-wrap;background:#F6F7F9;border:1px solid var(--rule);border-radius:8px;padding:10px 12px;margin:0">0 3 * * * php ' . h($path) . ' action=cron_recompute token=YOUR_TOKEN</pre><button class="btn btn-sm mt-2" type="button" data-copy="cron-line">Copy</button>';
    $weak = in_array((string)$CONFIG['cronToken'], ['', 'CHANGE_ME'], true);
    echo '<div class="help mt-2">Set CRON_TOKEN in your .env file' . ($weak ? '. <span class="bad">The token is still the default, so cron is refused until you change it.</span>' : ' and keep it secret.') . '</div>';
    echo '<div class="kv mt-3"><span class="sl">Version</span><span class="mono">' . h(LITEHCP_VERSION) . ' · PHP ' . h(PHP_VERSION) . '</span></div><div class="help">Run <span class="mono">php litehcp.php action=selftest</span> after upgrading to check the install.</div></div></div>';
    page_footer();
}

/*
====================================================================================================
How it works (summary)
====================================================================================================
- Imports: columns are matched automatically (exact names, then common synonyms), every row is checked
  before anything is written, and large files run in short chunks. Missing values can be filled and are
  flagged; each HCP gets a data-confidence score from completeness, mapping coverage and filled values.
- Scoring: a base priority comes from consent, activity recency and engagement. Enrichment rules then run
  in order and can set a persona, adjust priority, mark someone cleared to contact, and add tags.
  Priority maps to tiers A/B/C (configurable cut-offs) and to deciles 1–10 across the whole database.
- Suppression: HCPs on the suppression list or with a Do Not Contact persona are excluded inside every
  audience count, preview and export query. There is no per-audience or per-role override.
- Audiences are built from a fixed allowlist of fields and operators; values are quoted with PDO::quote()
  or cast to numbers, so no user-written SQL runs. Filters saved by v1.1 still work and are re-validated.
- Exports come in presets (minimal, CRM target list with planned calls, email platform). Names and
  emails need an admin, the PII setting, and email consent. Every export is written to the audit log.


/*
====================================================================================================
Self-test (php litehcp.php action=selftest)
====================================================================================================
Runs against a throwaway in-memory database and temp upload folder. Never touches your data.
*/

function run_selftest(array $CONFIG): int {
    $tmp = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'litehcp_selftest_' . bin2hex(random_bytes(4));
    $GLOBALS['UPLOAD_DIR_OVERRIDE'] = $tmp;
    $fails = 0;
    $n = 0;
    $check = function (string $label, bool $ok, string $detail = '') use (&$fails, &$n): void {
        $n++;
        if (!$ok) $fails++;
        fwrite(STDOUT, ($ok ? '  ok    ' : '  FAIL  ') . $label . ($detail !== '' ? '  (' . $detail . ')' : '') . "\n");
    };
    fwrite(STDOUT, 'LiteHCP Targeter ' . LITEHCP_VERSION . ' self-test on PHP ' . PHP_VERSION . "\n");
    try {
        $check('PHP 8.0 or newer', PHP_VERSION_ID >= 80000, PHP_VERSION_ID < 80300 ? 'works; 8.3+ recommended' : '');
        foreach (['pdo_sqlite', 'json', 'mbstring'] as $ext) $check('extension ' . $ext, extension_loaded($ext));

        $pdo = db_connect('sqlite::memory:');
        ensure_schema($pdo);
        $check('schema migrated to version 2', (int)$pdo->query('PRAGMA user_version')->fetchColumn() === 2);
        $check('SQLite JSON functions available', sqlite_supports_json($pdo));
        $settings = get_settings($pdo);

        $r = load_sample_data($pdo, $CONFIG, null);
        $count = fn(string $sql) => (int)$pdo->query($sql)->fetchColumn();
        $check('sample: 200 HCPs imported', $count('SELECT COUNT(*) FROM profiles') === 200, 'got ' . $count('SELECT COUNT(*) FROM profiles'));
        $check('sample: 9 enrichment rules', $count('SELECT COUNT(*) FROM rules') === 9);
        $check('sample: 6 audiences', $count('SELECT COUNT(*) FROM segments') === 6);
        $check('sample: import finished in one go', (int)$r['rows'] === 200);
        $dnc = $count("SELECT COUNT(*) FROM profiles WHERE persona = 'Do Not Contact'");
        $check('23 HCPs get the Do Not Contact persona', $dnc === 23, 'got ' . $dnc);
        $check('suppression excludes exactly those 23', suppressed_count($pdo) === 23);

        $segs = [];
        foreach ($pdo->query('SELECT * FROM segments')->fetchAll() as $s) $segs[(string)$s['name']] = $s;
        $kol = audience_stats($pdo, $segs['Key opinion leaders'] ?? [], $CONFIG, $settings);
        $check('KOL audience: 53 ready, 7 excluded', ($kol['ready'] ?? -1) === 53 && ($kol['excluded'] ?? -1) === 7, 'ready ' . ($kol['ready'] ?? '?') . ', excluded ' . ($kol['excluded'] ?? '?'));
        $onc = audience_stats($pdo, $segs['Oncology, high confidence'] ?? [], $CONFIG, $settings);
        $check('Oncology high-confidence audience: 29 ready', ($onc['ready'] ?? -1) === 29, 'got ' . ($onc['ready'] ?? '?'));

        // No export, in any format, from any audience, may contain a suppressed HCP.
        $supIds = array_map(fn($x) => (string)$x['hcp_id'], $pdo->query('SELECT hcp_id FROM profiles WHERE NOT ' . suppression_sql($pdo))->fetchAll());
        $leak = 0;
        $rowsTotal = 0;
        foreach ($segs as $s) {
            foreach (array_keys((array)$CONFIG['exportPresets']) as $pk) {
                [$rows] = export_rows($pdo, $CONFIG, $settings, $s, (string)$pk);
                $rowsTotal += count($rows);
                foreach ($rows as $row) if (in_array((string)$row['hcp_id'], $supIds, true)) $leak++;
            }
        }
        $check('exports never include suppressed HCPs (' . count($segs) . ' audiences × ' . count((array)$CONFIG['exportPresets']) . ' formats)', $leak === 0 && $rowsTotal > 0, $leak . ' leaked of ' . $rowsTotal . ' rows');
        [$emailRows] = export_rows($pdo, $CONFIG, $settings, $segs['Oncology, high confidence'], 'email_platform');
        $noConsent = 0;
        foreach ($emailRows as $row) {
            $st = $pdo->prepare('SELECT consent_email FROM profiles WHERE hcp_id = :h');
            $st->execute([':h' => $row['hcp_id']]);
            if ((int)$st->fetchColumn() !== 1 || (string)$row['email'] === '') $noConsent++;
        }
        $check('email platform export only lists HCPs with email consent and an address', $noConsent === 0);
        [$crm, $crmFields] = export_rows($pdo, $CONFIG, $settings, $segs['Key opinion leaders'], 'crm_targets');
        $check('CRM target list has tier and planned calls', in_array('planned_calls', $crmFields, true) && isset($crm[0]['tier']) && $crm[0]['planned_calls'] === planned_calls_map($CONFIG, $settings)[(string)$crm[0]['tier']]);

        // Manual suppression list
        $pdo->prepare('INSERT INTO suppressions (hcp_id, reason, source, created_at) VALUES (:h, :r, :s, :t)')->execute([':h' => 'HCP-1102', ':r' => 'Opted out', ':s' => 'manual', ':t' => time()]);
        $onc2 = audience_stats($pdo, $segs['Oncology, high confidence'], $CONFIG, $settings);
        $check('adding HCP-1102 to the suppression list drops oncology to 28', ($onc2['ready'] ?? -1) === 28, 'got ' . ($onc2['ready'] ?? '?'));
        $pdo->prepare('INSERT OR IGNORE INTO suppressions (hcp_id, reason, source, created_at) VALUES (:h, :r, :s, :t)')->execute([':h' => 'HCP-NOT-YET', ':r' => 'Opted out', ':s' => 'manual', ':t' => time()]);
        $check('IDs can be suppressed before they are imported', $count("SELECT COUNT(*) FROM suppressions WHERE hcp_id = 'HCP-NOT-YET'") === 1);

        // Tiers and deciles
        $tiers = $count("SELECT COUNT(*) FROM profiles WHERE tier IN ('A','B','C')");
        $check('every HCP has a tier', $tiers === 200, 'got ' . $tiers);
        $dmin = $count('SELECT MIN(priority_decile) FROM profiles');
        $dmax = $count('SELECT MAX(priority_decile) FROM profiles');
        $check('priority deciles run 1 to 10', $dmin === 1 && $dmax === 10, $dmin . '–' . $dmax);
        $check('tier cut-offs: 80 → A, 79.9 → B, 49.9 → C', compute_tier(80, ['A' => 80, 'B' => 50]) === 'A' && compute_tier(79.9, ['A' => 80, 'B' => 50]) === 'B' && compute_tier(49.9, ['A' => 80, 'B' => 50]) === 'C');

        // Identity and parsing
        $check('NPI 1234567893 passes the check digit', npi_is_valid('1234567893'));
        $check('NPI 1234567890 fails the check digit', !npi_is_valid('1234567890'));
        $check('dates: 2026-09-14, 09/14/2026 and Unix seconds read the same day',
            date('Y-m-d', (int)parse_date_value('2026-09-14')) === '2026-09-14' && date('Y-m-d', (int)parse_date_value('09/14/2026')) === '2026-09-14' && date('Y-m-d', (int)parse_date_value((string)mktime(12, 0, 0, 9, 14, 2026))) === '2026-09-14');
        $check('dates: unreadable values are rejected', parse_date_value('next tuesday-ish') === null);
        $check('consent: yes / opt-in / 1 / true read as Yes', parse_boolish('yes') === 1 && parse_boolish('opt-in') === 1 && parse_boolish('1') === 1 && parse_boolish('TRUE') === 1);
        $check('consent: no / opt-out / withdrawn read as No', parse_boolish('no') === 0 && parse_boolish('opt-out') === 0 && parse_boolish('withdrawn') === 0);

        // Builder safety: values never reach SQL unquoted.
        [$ok, $def] = validate_definition($pdo, ['match' => 'all', 'conditions' => [['field' => 'specialty', 'op' => 'in', 'value' => ["x') OR 1=1 --"]]]]);
        $cnt = $ok ? $count('SELECT COUNT(*) FROM profiles WHERE ' . compile_definition($pdo, $def)) : -1;
        $check('audience builder quotes every value', $ok && $cnt === 0, 'matched ' . $cnt);
        [$bad] = validate_definition($pdo, ['conditions' => [['field' => 'password_hash', 'op' => 'in', 'value' => ['x']]]]);
        $check('audience builder rejects unknown fields', $bad === false);
        [$okF] = validate_sql_filter('specialty = \'oncology\'; DROP TABLE users', segment_allowed_fields());
        $check('legacy filters reject statements', $okF === false);

        // Roles
        $_SESSION['user'] = ['id' => 1, 'email' => 'v@example.com', 'role' => 'viewer'];
        $v = [can('view'), can('edit'), can('export'), can('admin')];
        $_SESSION['user']['role'] = 'editor';
        $e = [can('view'), can('edit'), can('export'), can('admin')];
        $_SESSION['user']['role'] = 'user';
        $legacy = can('export');
        unset($_SESSION['user']);
        $check('roles: viewers view only, editors build and export, admins manage', $v === [true, false, false, false] && $e === [true, true, true, false] && $legacy === true);

        // Rule re-application keeps suppression in step.
        cron_recompute_all($pdo, $CONFIG);
        $check('recalculating all HCPs keeps 23 Do Not Contact', $count("SELECT COUNT(*) FROM profiles WHERE persona = 'Do Not Contact'") === 23);
    } catch (Throwable $e) {
        $check('self-test ran without errors', false, $e->getMessage() . ' at line ' . $e->getLine());
    }
    // Clean up the throwaway upload folder.
    if (is_dir($tmp)) {
        foreach (glob($tmp . DIRECTORY_SEPARATOR . '{,.}*', GLOB_BRACE) ?: [] as $f) if (is_file($f)) @unlink($f);
        @rmdir($tmp);
    }
    fwrite(STDOUT, "\n" . ($fails === 0 ? 'All ' . $n . ' checks passed.' : $fails . ' of ' . $n . ' checks failed.') . "\n");
    return $fails === 0 ? 0 : 1;
}

/*
====================================================================================================
Routing
====================================================================================================
*/

if (php_sapi_name() === 'cli') {
    $args = [];
    foreach (array_slice($_SERVER['argv'] ?? [], 1) as $a) if (strpos($a, '=') !== false) { [$k, $v] = explode('=', $a, 2); $args[$k] = $v; }
    $action = (string)($args['action'] ?? 'help');
    if ($action === 'selftest') exit(run_selftest($CONFIG));
    if ($action === 'cron_recompute') {
        $token = (string)($args['token'] ?? '');
        $expected = (string)($CONFIG['cronToken'] ?? '');
        if ($expected === '' || $expected === 'CHANGE_ME') { fwrite(STDERR, "Set CRON_TOKEN in .env first; the default token is refused.\n"); exit(1); }
        if (!hash_equals($expected, $token)) { fwrite(STDERR, "Invalid token\n"); exit(1); }
        $pdo = db($CONFIG);
        ensure_schema($pdo);
        cron_recompute_all($pdo, $CONFIG);
        audit($pdo, null, 'recompute_all', ['via' => 'cron']);
        fwrite(STDOUT, "OK\n");
        exit(0);
    }
    fwrite(STDOUT, "LiteHCP Targeter " . LITEHCP_VERSION . "\n\nUsage:\n  php litehcp.php action=selftest\n  php litehcp.php action=cron_recompute token=YOUR_TOKEN\n");
    exit(0);
}

$pdo = db($CONFIG);
ensure_schema($pdo);
$settings = get_settings($pdo);

$action = (string)($_GET['action'] ?? 'dashboard');
$isApi = str_starts_with($action, 'api_');

// A POST larger than post_max_size arrives empty; explain it instead of failing the CSRF check.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$_POST && !$_FILES && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 && !$isApi) {
    flash('danger', 'That upload is larger than the server allows (post_max_size). Use a smaller file.');
    redirect($action === 'import' ? '?action=import' : '?action=dashboard');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') require_csrf();

if ($action === 'login') { handle_login($pdo); exit; }
if ($action === 'register') { handle_register($pdo, $CONFIG, $settings); exit; }
if ($action === 'logout') { handle_logout($pdo); exit; }

if ($action === 'cron_recompute') {
    $token = (string)($_GET['token'] ?? '');
    $expected = (string)($CONFIG['cronToken'] ?? '');
    if ($expected === '' || $expected === 'CHANGE_ME' || !hash_equals($expected, $token)) { http_response_code(403); echo 'Forbidden'; exit; }
    cron_recompute_all($pdo, $CONFIG);
    audit($pdo, null, 'recompute_all', ['via' => 'cron']);
    echo 'OK';
    exit;
}

if (!current_user()) {
    if ($isApi) json_out(['error' => 'Your session ended. Sign in again.'], 401);
    redirect('?action=login');
}

// Keep the session role in step with the database, so role changes apply right away.
$__u = $pdo->prepare('SELECT id, email, role FROM users WHERE id = :id');
$__u->execute([':id' => (int)current_user()['id']]);
$__row = $__u->fetch();
if (!$__row) {
    $_SESSION = [];
    session_destroy();
    redirect('?action=login');
}
$_SESSION['user']['role'] = (string)$__row['role'];
$_SESSION['user']['email'] = (string)$__row['email'];

if ($isApi && $_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'Use POST.'], 405);

switch ($action) {
    case 'dashboard': page_dashboard($pdo, $CONFIG, $settings); break;
    case 'import': page_import($pdo, $CONFIG, $settings); break;
    case 'import_map': page_import_map($pdo, $CONFIG, $settings); break;
    case 'import_check': page_import_check($pdo, $CONFIG, $settings); break;
    case 'import_run': page_import_run($pdo, $CONFIG, $settings); break;
    case 'profiles': page_profiles($pdo, $CONFIG, $settings); break;
    case 'profile_view': page_profile_view($pdo, $CONFIG, $settings); break;
    case 're_enrich': handle_re_enrich($pdo, $CONFIG); break;
    case 'rules': page_rules($pdo, $CONFIG, $settings); break;
    case 'rule_edit': page_rule_edit($pdo, $CONFIG, $settings); break;
    case 'rule_test': page_rule_test($pdo, $CONFIG, $settings); break;
    case 'segments': page_segments($pdo, $CONFIG, $settings); break;
    case 'segment_edit': page_segment_edit($pdo, $CONFIG, $settings); break;
    case 'segment_run': page_segment_run($pdo, $CONFIG, $settings); break;
    case 'simulator': page_simulator($pdo, $CONFIG, $settings); break;
    case 'export_segment': handle_export_segment($pdo, $CONFIG, $settings); break;
    case 'exports': page_exports($pdo, $CONFIG, $settings); break;
    case 'suppression': page_suppression($pdo, $CONFIG, $settings); break;
    case 'audit': page_audit($pdo, $CONFIG, $settings); break;
    case 'settings': page_settings($pdo, $CONFIG, $settings); break;
    case 'load_sample': handle_load_sample($pdo, $CONFIG, $settings); break;
    case 'api_count': api_count($pdo, $CONFIG, $settings); break;
    case 'api_rule_test': api_rule_test($pdo, $CONFIG); break;
    case 'api_import_chunk': api_import_chunk($pdo, $CONFIG); break;
    default: page_dashboard($pdo, $CONFIG, $settings); break;
}
