<?php
declare(strict_types=1);

// Location of the PRIVATE Lead CRM folder (config.php, inc/, cron/, storage/) — keep it OUTSIDE every web root.
// Resolution order: LEADCRM_ROOT environment variable (or SetEnv in .htaccess / vhost),
// otherwise a sibling folder of this web root named "leadcrm-app".
if (!defined('LH_ROOT')) {
    $lhRoot = getenv('LEADCRM_ROOT') ?: ($_SERVER['LEADCRM_ROOT'] ?? '');
    define('LH_ROOT', rtrim($lhRoot !== '' ? $lhRoot : dirname(__DIR__) . '/leadcrm-app', '/'));
}

// ---- Sessions: never expire on their own (set BEFORE the core boots / starts the session) ----
@ini_set('session.gc_maxlifetime', '31536000');   // 1 year
@ini_set('session.cookie_lifetime', '31536000');  // persistent cookie

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

// ---------------------------------------------------------------------------------------------
// Admin HTML post-processing: favicon / app icons / mobile viewport / theme colour are injected
// into <head> of every admin page (login included) without touching the core templates.
// ---------------------------------------------------------------------------------------------
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
            $tags .= '<meta name="theme-color" content="#0f1f1d">' . "\n";
        }
        if (!preg_match('/rel=["\'](shortcut )?icon["\']/i', $html)) {
            $tags .= '<link rel="icon" type="image/svg+xml" href="' . $a . 'favicon.svg">' . "\n"
                . '<link rel="icon" type="image/png" sizes="32x32" href="' . $a . 'favicon-32.png">' . "\n"
                . '<link rel="shortcut icon" href="' . lh_base_path() . '/favicon.ico">' . "\n"
                . '<link rel="apple-touch-icon" href="' . $a . 'apple-touch-icon.png">' . "\n"
                . '<link rel="manifest" href="' . $a . 'site.webmanifest">' . "\n";
        }
        return $tags === '' ? $html : preg_replace('~</head>~i', $tags . '</head>', $html, 1);
    });
}
