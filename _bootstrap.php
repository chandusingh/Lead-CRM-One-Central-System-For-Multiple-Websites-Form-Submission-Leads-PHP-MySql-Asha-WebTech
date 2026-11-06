<?php
declare(strict_types=1);

// Location of the PRIVATE Lead CRM folder (config.php, inc/, cron/, storage/) — outside every web root.
if (!defined('LH_ROOT')) {
    define('LH_ROOT', '/home/u473825723/leadcrm-app');
}

// ---- Sessions: never expire on their own (set BEFORE the core boots / starts the session) ----
@ini_set('session.gc_maxlifetime', '31536000');   // 1 year
@ini_set('session.cookie_lifetime', '31536000');  // persistent cookie
@ini_set('session.use_strict_mode', '1');
@ini_set('session.cookie_httponly', '1');
@ini_set('session.cookie_samesite', 'Lax');

// Sessions stay in the server's default (fast) folder. If the server ever cleans them up, the signed
// 365-day login cookie below silently restores the login, so the user is never logged out automatically.

require LH_ROOT . '/inc/bootstrap.php';

// ---------------------------------------------------------------------------------------------
// Persistent login ("remember me", always on).
// If the PHP session is ever lost (idle timeout, server cleanup, browser restart) the signed
// cookie below silently signs the user back in. It is cleared on Logout and becomes invalid when
// the password changes. Used by admin/login.php, admin/logout.php, admin/account.php.
// ---------------------------------------------------------------------------------------------
const LH_REMEMBER_COOKIE = 'lh_remember';
const LH_REMEMBER_DAYS = 365;

function lh_base_path(): string
{
    $dir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/admin/x.php')));
    if (preg_match('#/(admin|api(/v1)?)$#', $dir)) {
        $dir = preg_replace('#/(admin|api(/v1)?)$#', '', $dir);
    }
    return rtrim((string) $dir, '/');
}

function lh_rm_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function lh_rm_secret(): string
{
    $s = (string) setting('remember_secret');
    if (strlen($s) < 32) {
        $s = bin2hex(random_bytes(32));
        setting_set('remember_secret', $s);
    }
    return $s;
}

function lh_rm_sign(int $uid, int $exp, string $passHash): string
{
    return hash_hmac('sha256', $uid . '|' . $exp . '|' . substr($passHash, -20), lh_rm_secret());
}

function lh_rm_cookie_set(string $value, int $expires): void
{
    if (headers_sent()) {
        return;
    }
    setcookie(LH_REMEMBER_COOKIE, $value, [
        'expires' => $expires,
        'path' => (lh_base_path() ?: '') . '/',
        'secure' => lh_rm_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function lh_rm_issue(array $user): void
{
    $uid = (int) $user['id'];
    $hash = (string) ($user['password_hash'] ?? db_val('SELECT password_hash FROM users WHERE id = ?', [$uid]));
    $exp = time() + LH_REMEMBER_DAYS * 86400;
    lh_rm_cookie_set($uid . '.' . $exp . '.' . lh_rm_sign($uid, $exp, $hash), $exp);
}

function lh_rm_clear(): void
{
    lh_rm_cookie_set('', time() - 3600);
    unset($_COOKIE[LH_REMEMBER_COOKIE]);
}

/** Returns the active user row for a valid remember cookie, or null. */
function lh_rm_user(): ?array
{
    $c = $_COOKIE[LH_REMEMBER_COOKIE] ?? '';
    if (!is_string($c) || substr_count($c, '.') !== 2) {
        return null;
    }
    [$uid, $exp, $sig] = explode('.', $c);
    if (!ctype_digit($uid) || !ctype_digit($exp) || (int) $exp < time()) {
        return null;
    }
    $user = db_one('SELECT * FROM users WHERE id = ? AND is_active = 1', [(int) $uid]);
    if (!$user) {
        return null;
    }
    $ok = hash_equals(lh_rm_sign((int) $uid, (int) $exp, (string) $user['password_hash']), $sig);
    return $ok ? $user : null;
}

/**
 * Call right after admin_init() on every admin page. If the PHP session is gone but the signed
 * "remember" cookie is valid, sign the user straight back in (no OTP: the cookie is only ever issued
 * after a completed password + OTP login). Also slides the cookie expiry forward.
 */
function lh_rm_restore(): void
{
    if (!current_user()) {
        $u = lh_rm_user();
        if ($u) {
            login_user($u);
        }
        return;
    }
    $c = $_COOKIE[LH_REMEMBER_COOKIE] ?? '';
    if (is_string($c) && substr_count($c, '.') === 2) {
        $exp = (int) explode('.', $c)[1];
        if ($exp - time() < (LH_REMEMBER_DAYS - 30) * 86400) {
            $cu = current_user();
            if ($cu && lh_rm_user()) {
                lh_rm_issue(['id' => (int) $cu['id']]);
            }
        }
    }
}

// ---------------------------------------------------------------------------------------------
// One-time SMS OTP (second step after the password). Provider: apitxt.com. Settings live in the
// `settings` table and are edited from Admin > Email & SMTP > "Login OTP (SMS)".
// ---------------------------------------------------------------------------------------------
const LH_OTP_DEFAULT_ENDPOINT = 'https://apitxt.com/api/sendOTP';
const LH_OTP_DEFAULT_MOBILE = '917024315567';
const LH_OTP_TTL = 300;          // code valid for 5 minutes
const LH_OTP_MAX_TRIES = 5;      // wrong entries before the code is thrown away
const LH_OTP_RESEND_WAIT = 30;   // seconds between SMS sends
const LH_OTP_LOGIN_WINDOW = 900; // password step stays valid for 15 minutes

function lh_otp_normalize_mobile(string $raw): string
{
    $d = preg_replace('/\D+/', '', $raw) ?? '';
    $d = ltrim($d, '0');
    if (strlen($d) === 10) {
        $d = '91' . $d;           // bare Indian number
    }
    return (strlen($d) >= 11 && strlen($d) <= 15) ? $d : '';
}

function lh_otp_mask(string $m): string
{
    $n = strlen($m);
    return $n < 7 ? $m : '+' . substr($m, 0, 2) . ' ' . substr($m, 2, 2) . str_repeat('•', max(0, $n - 7)) . substr($m, -3);
}

function lh_otp_enc(string $plain): string
{
    if ($plain === '') {
        return '';
    }
    if (function_exists('openssl_encrypt')) {
        $key = hash('sha256', 'lh-otp|' . lh_rm_secret(), true);
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct !== false) {
            return 'enc2:' . base64_encode($iv . $tag . $ct);
        }
    }
    return 'plain:' . $plain;
}

function lh_otp_dec(string $stored): string
{
    if (strncmp($stored, 'enc2:', 5) === 0 && function_exists('openssl_decrypt')) {
        $raw = base64_decode(substr($stored, 5), true);
        if ($raw === false || strlen($raw) < 29) {
            return '';
        }
        $key = hash('sha256', 'lh-otp|' . lh_rm_secret(), true);
        $pt = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $pt === false ? '' : $pt;
    }
    if (strncmp($stored, 'plain:', 6) === 0) {
        return substr($stored, 6);
    }
    return '';
}

function lh_otp_config(): array
{
    $mobile = lh_otp_normalize_mobile((string) setting('otp_mobile'));
    $endpoint = trim((string) setting('otp_endpoint'));
    return [
        'enabled' => (string) setting('otp_enabled') === '1',
        'mobile' => $mobile !== '' ? $mobile : LH_OTP_DEFAULT_MOBILE,
        'key' => lh_otp_dec((string) setting('otp_api_key')),
        'endpoint' => $endpoint !== '' ? $endpoint : LH_OTP_DEFAULT_ENDPOINT,
    ];
}

/** OTP is only enforced when it is switched on AND fully configured (so a half-setup never locks you out). */
function lh_otp_ready(): bool
{
    $c = lh_otp_config();
    return $c['enabled'] && $c['key'] !== '' && $c['mobile'] !== '';
}

/** @return array{0:bool,1:string} [success, short detail for logs / test button] */
function lh_otp_send(string $mobile, string $otp, array $cfg): array
{
    $fields = http_build_query(['authkey' => $cfg['key'], 'mobile' => $mobile, 'otp' => $otp]);
    $body = '';
    $code = 0;
    $err = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($cfg['endpoint']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        ]);
        $res = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $body = is_string($res) ? $res : '';
    } else {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
            'content' => $fields,
            'timeout' => 15,
            'ignore_errors' => true,
        ]]);
        $res = @file_get_contents($cfg['endpoint'], false, $ctx);
        $body = is_string($res) ? $res : '';
        foreach (($http_response_header ?? []) as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $code = (int) $m[1];
            }
        }
    }
    $snippet = trim(substr(preg_replace('/\s+/', ' ', $body) ?? '', 0, 200));
    if ($code < 200 || $code >= 300) {
        return [false, 'HTTP ' . $code . ($err !== '' ? ' ' . $err : '') . ($snippet !== '' ? ' - ' . $snippet : '')];
    }
    $j = json_decode($body, true);
    if (is_array($j)) {
        foreach (['status', 'type', 'result'] as $k) {
            if (isset($j[$k]) && is_string($j[$k]) && preg_match('/^(error|fail|failed|failure|invalid)$/i', $j[$k])) {
                return [false, $snippet];
            }
        }
        if ((array_key_exists('success', $j) && ($j['success'] === false || $j['success'] === 'false' || $j['success'] === 0))
            || (!empty($j['error']) && $j['error'] !== 'false')) {
            return [false, $snippet];
        }
    } elseif ($snippet === '' || preg_match('/\b(error|invalid|fail)/i', $snippet)) {
        return [false, $snippet !== '' ? $snippet : 'empty response'];
    }
    return [true, $snippet];
}

function lh_otp_hash(string $otp, int $uid): string
{
    return hash_hmac('sha256', $otp, lh_rm_secret() . '|otp|' . $uid);
}

/** Generates a fresh code, texts it, and stores it (hashed) in the session. @return array{0:bool,1:string} */
function lh_otp_start(array $user, bool $keepWindow = false): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    $uid = (int) $user['id'];
    $bucket = 'otp_send:' . $uid;
    if (!rate_limit_check($bucket, 5, 900)) {
        return [false, 'Too many OTP requests. Please wait 15 minutes and try again.'];
    }
    $cfg = lh_otp_config();
    $otp = (string) random_int(100000, 999999);
    [$ok, $detail] = lh_otp_send($cfg['mobile'], $otp, $cfg);
    rate_limit($bucket, 5, 900);
    if (!$ok) {
        log_event('error', 'auth.otp_send_failed', ['user_id' => $uid, 'detail' => $detail]);
        return [false, 'We could not send the OTP SMS right now. Please try again in a moment.'];
    }
    $prev = $_SESSION['lh_otp'] ?? null;
    $_SESSION['lh_otp'] = [
        'uid' => $uid,
        'h' => lh_otp_hash($otp, $uid),
        'exp' => time() + LH_OTP_TTL,
        'tries' => 0,
        'sent' => time(),
        'until' => ($keepWindow && is_array($prev) && isset($prev['until'])) ? (int) $prev['until'] : time() + LH_OTP_LOGIN_WINDOW,
        'mobile' => $cfg['mobile'],
    ];
    log_event('info', 'auth.otp_sent', ['user_id' => $uid]);
    return [true, ''];
}

// ---------------------------------------------------------------------------------------------
// Admin HTML post-processing: favicon / app icons / mobile viewport / theme colour are injected
// into <head> of every admin page (login included) without touching the core templates.
// ---------------------------------------------------------------------------------------------
require_once __DIR__ . '/_ui.php';

// Centralized Security Engine (guard for /api/v1/leads + helpers used by the admin Security pages).
require_once __DIR__ . '/_security.php';

if (strpos((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/admin/') !== false) {
    ob_start(static function (string $html): string {
        if (stripos($html, '</head>') === false) {
            return $html;
        }
        $a = lh_base_path() . '/assets/';
        $tags = '';
        if (stripos($html, 'name="viewport"') === false) {
            $tags .= '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">' . "\n";
        }
        if (stripos($html, 'name="theme-color"') === false) {
            $tags .= '<meta name="theme-color" content="#ffffff">' . "\n";
        }
        // Always (re)write the icon links so a stale / cached icon set from the core template can never win.
        $v = '?v=' . (int) @filemtime(__DIR__ . '/assets/logo-full.png');
        $html = (string) preg_replace('~<link\b[^>]*\brel=["\'](?:shortcut icon|icon|apple-touch-icon(?:-precomposed)?|manifest)["\'][^>]*>\s*~i', '', $html);
        $tags .= '<link rel="icon" type="image/svg+xml" href="' . $a . 'favicon.svg' . $v . '">' . "\n"
            . '<link rel="icon" type="image/png" sizes="32x32" href="' . $a . 'favicon-32.png' . $v . '">' . "\n"
            . '<link rel="shortcut icon" href="' . lh_base_path() . '/favicon.ico' . $v . '">' . "\n"
            . '<link rel="apple-touch-icon" href="' . $a . 'apple-touch-icon.png' . $v . '">' . "\n"
            . '<link rel="manifest" href="' . $a . 'site.webmanifest' . $v . '">' . "\n";

        // Stylesheet + script: always load the current files under a version tag, so a cached / not-yet-overwritten
        // admin.css can never leave the panel unstyled. The core template's own admin.css link is replaced.
        $html = (string) preg_replace('~<link\b[^>]*\bhref=["\'][^"\']*assets/admin\.css[^"\']*["\'][^>]*>\s*~i', '', $html);
        $css = is_file(__DIR__ . '/assets/app.css') ? 'app.css' : 'admin.css'; // admin.css is an identical copy
        $tags .= '<link rel="stylesheet" href="' . $a . $css . '?v=' . (int) @filemtime(__DIR__ . '/assets/' . $css) . '">' . "\n";
        $html = (string) preg_replace('~(assets/admin\.js)(\?[^"\']*)?~i', '$1?v=' . (int) @filemtime(__DIR__ . '/assets/admin.js'), $html);

        // TailAdmin shell: replaces the legacy sidebar / mobile bar (no-op if the markup is not recognised).
        $shelled = lh_ui_shell($html);
        $swapped = $shelled !== $html;
        $html = $shelled;

        // Fallback branding for the legacy sidebar (only if the shell swap did not apply).
        $logo = '<img class="brand-img" src="' . $a . 'logo-full.png' . $v . '" alt="Asha WebTech IT Solution Pvt Ltd" width="160" height="100" style="display:block;height:auto;max-width:100%">'
            . '<span class="brand-sub">Lead CRM</span>';
        if (!$swapped) {
        $html = (string) preg_replace_callback(
            '~(<(a|div)\b[^>]*\bclass=["\'](?:[^"\']*\s)?brand(?:\s[^"\']*)?["\'][^>]*>)(.*?)(</\2>)~is',
            static function (array $m) use ($logo): string {
                if (strpos($m[1], 'brand-lg') !== false || stripos($m[3], '<img') !== false
                    || stripos($m[3], '<div') !== false || stripos($m[3], '<a ') !== false) {
                    return $m[0];
                }
                return $m[1] . $logo . $m[4];
            },
            $html
        );
        }
        return $tags === '' ? $html : preg_replace('~</head>~i', $tags . '</head>', $html, 1);
    });
}