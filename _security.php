<?php
declare(strict_types=1);

/**
 * Centralized Security Engine for the Lead CRM.
 *
 * One guard, called from api/v1/leads.php BEFORE the core api_handle(), protects every registered website
 * without any change to their forms, API keys or integrations. The request is inspected, then it is
 *   - ALLOWED     : handed to the untouched core (identical response to before),
 *   - BLOCKED     : clearly malicious (injection payloads, honeypot, attack tools, rate abuse),
 *   - QUARANTINED : suspicious. Stored for admin review (NOT saved as a lead) while the visitor still
 *                   gets the normal success response, so a wrongly-flagged genuine visitor loses nothing.
 *
 * Design rules
 *  - Fail OPEN: any unexpected error inside the engine lets the request through to the core.
 *  - No CAPTCHA of any kind. Signals are: honeypot, headers, origin/domain match, content patterns, history.
 *  - Single weak signals never reject anyone: block needs a clear attack, quarantine needs a combined score.
 *  - Configuration: global rows in `settings` (sec_*) + optional per-website overrides (security_site_rules).
 *  - Written for PHP 7.2+ (the code base runs on 8.x, nothing here needs it).
 *
 * Injection detection is defence-in-depth and data-quality protection; the CRM's real SQL/XSS safety is
 * prepared statements + output escaping, which stay in place.
 */

const LH_SEC_MODULES = [
    'rate_limit' => 'IP rate limiting',
    'sqli'       => 'SQL injection detection',
    'xss'        => 'XSS detection',
    'duplicate'  => 'Duplicate submission detection',
    'bot'        => 'Automated bot detection',
    'quarantine' => 'Suspicious submission quarantine',
];

const LH_SEC_RULE_LABELS = [
    'rate_limit' => 'Rate limit',
    'sqli'       => 'SQL injection',
    'xss'        => 'XSS',
    'duplicate'  => 'Duplicate',
    'bot'        => 'Bot',
    'engine'     => 'Engine',
];

// ---------------------------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------------------------

function lh_sec_defaults(): array
{
    return [
        'engine' => 1, 'monitor' => 0,
        'rate_limit' => 1, 'sqli' => 1, 'xss' => 1, 'duplicate' => 1, 'bot' => 1, 'quarantine' => 1,
        'rl_max' => 10,            // submissions per IP per website per window (IP-wide cap = 3x)
        'rl_window' => 600,        // seconds
        'bot_threshold' => 60,     // quarantine at this bot score (80 relaxed / 60 balanced / 40 strict)
        'dup_body_sources' => 3,   // same message from this many distinct IP+website sources => quarantine
        'dup_window' => 86400,     // seconds
        'dup_contact_max' => 5,    // same email/phone with this many different messages per hour => quarantine
        'trusted_ips' => '',       // never inspected (admin / office IPs, one per line, CIDR allowed)
        'spam_keywords' => '',     // one per line; a match quarantines
        'ip_header' => '',         // '', cf-connecting-ip, x-real-ip, x-forwarded-for
        'retention_days' => 60,
        'quarantine_cap' => 5000,
        'schema' => 0,             // set to 1 once the security tables exist (self-installing, see lh_sec_ensure_schema)
    ];
}

function lh_sec_int_keys(): array
{
    return ['engine', 'monitor', 'rate_limit', 'sqli', 'xss', 'duplicate', 'bot', 'quarantine', 'rl_max', 'rl_window',
        'bot_threshold', 'dup_body_sources', 'dup_window', 'dup_contact_max', 'retention_days', 'quarantine_cap', 'schema'];
}

function lh_sec_clamp(string $key, int $v): int
{
    $r = [
        'rl_max' => [1, 10000], 'rl_window' => [30, 86400], 'bot_threshold' => [20, 200],
        'dup_body_sources' => [2, 50], 'dup_window' => [300, 2592000], 'dup_contact_max' => [2, 100],
        'retention_days' => [1, 3650], 'quarantine_cap' => [100, 100000],
    ];
    if (isset($r[$key])) {
        return max($r[$key][0], min($r[$key][1], $v));
    }
    return $v ? 1 : 0;
}

/** Global configuration (defaults overlaid with `settings` rows named sec_*). One query, cached per request. */
function lh_sec_config(bool $fresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$fresh) {
        return $cache;
    }
    $cfg = lh_sec_defaults();
    $rows = db_all("SELECT name, value FROM settings WHERE LEFT(name, 4) = 'sec_'");
    $ints = array_flip(lh_sec_int_keys());
    foreach ($rows as $r) {
        $k = substr((string) $r['name'], 4);
        if (!array_key_exists($k, $cfg)) {
            continue;
        }
        $cfg[$k] = isset($ints[$k]) ? lh_sec_clamp($k, (int) $r['value']) : (string) $r['value'];
    }
    $cache = $cfg;
    return $cfg;
}

function lh_sec_config_save(array $values): void
{
    $def = lh_sec_defaults();
    $ints = array_flip(lh_sec_int_keys());
    foreach ($values as $k => $v) {
        if (!array_key_exists($k, $def)) {
            continue;
        }
        $store = isset($ints[$k]) ? (string) lh_sec_clamp($k, (int) $v) : (string) $v;
        setting_set('sec_' . $k, $store);
    }
    lh_sec_config(true);
}

/** Per-website overrides: ['engine'=>inherit|on|off, 'modules'=>[m=>inherit|on|off], rl_max, rl_window, bot_threshold, extra_hosts]. */
function lh_sec_site_config(int $siteId): array
{
    if ($siteId <= 0) {
        return [];
    }
    $row = db_one('SELECT config FROM security_site_rules WHERE website_id = ?', [$siteId]);
    if (!$row) {
        return [];
    }
    $j = json_decode((string) $row['config'], true);
    return is_array($j) ? $j : [];
}

function lh_sec_effective(array $g, array $o): array
{
    $e = $g;
    $tri = static function ($v, int $cur): int {
        if ($v === 'on') {
            return 1;
        }
        if ($v === 'off') {
            return 0;
        }
        return $cur;
    };
    $e['engine'] = $tri($o['engine'] ?? 'inherit', (int) $g['engine']);
    foreach (array_keys(LH_SEC_MODULES) as $m) {
        $e[$m] = $tri($o['modules'][$m] ?? 'inherit', (int) $g[$m]);
    }
    foreach (['rl_max', 'rl_window', 'bot_threshold'] as $k) {
        if (isset($o[$k]) && is_numeric($o[$k]) && (int) $o[$k] > 0) {
            $e[$k] = lh_sec_clamp($k, (int) $o[$k]);
        }
    }
    $e['extra_hosts'] = (string) ($o['extra_hosts'] ?? '');
    return $e;
}

// ---------------------------------------------------------------------------------------------
// Small helpers
// ---------------------------------------------------------------------------------------------

function lh_sec_clip(string $s, int $n): string
{
    if (function_exists('mb_scrub')) {
        $s = mb_scrub($s);
    }
    return function_exists('mb_substr') ? mb_substr($s, 0, $n, 'UTF-8') : substr($s, 0, $n);
}

function lh_sec_new_public_id(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $id = '';
    for ($i = 0; $i < 8; $i++) {
        $id .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return 'LH-' . gmdate('ymd') . '-' . $id;
}

function lh_sec_json(array $a): string
{
    $j = json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    return is_string($j) ? $j : '{}';
}

function lh_sec_inet_match(string $ip, string $net, int $bits): bool
{
    $a = @inet_pton($ip);
    $b = @inet_pton($net);
    if ($a === false || $b === false || strlen($a) !== strlen($b)) {
        return false;
    }
    $bytes = intdiv($bits, 8);
    if ($bytes > 0 && substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
        return false;
    }
    $rem = $bits % 8;
    if ($rem === 0) {
        return true;
    }
    $mask = (0xFF << (8 - $rem)) & 0xFF;
    return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
}

/** True when $ip equals / falls inside any entry of a whitespace/comma separated list (IPv4, IPv6, CIDR). */
function lh_sec_ip_in_list(string $ip, string $list): bool
{
    foreach (preg_split('/[\s,;]+/', $list, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
        if (strpos($entry, '/') === false) {
            $a = @inet_pton($ip);
            $b = @inet_pton($entry);
            if ($a !== false && $b !== false && $a === $b) {
                return true;
            }
            continue;
        }
        list($net, $bits) = explode('/', $entry, 2);
        if (ctype_digit($bits) && lh_sec_inet_match($ip, $net, (int) $bits)) {
            return true;
        }
    }
    return false;
}

function lh_sec_client_ip(array $g): string
{
    $hdr = (string) ($g['ip_header'] ?? '');
    $map = ['cf-connecting-ip' => 'HTTP_CF_CONNECTING_IP', 'x-real-ip' => 'HTTP_X_REAL_IP', 'x-forwarded-for' => 'HTTP_X_FORWARDED_FOR'];
    if ($hdr !== '' && isset($map[$hdr]) && !empty($_SERVER[$map[$hdr]])) {
        $v = trim((string) $_SERVER[$map[$hdr]]);
        if ($hdr === 'x-forwarded-for') {
            $parts = array_map('trim', explode(',', $v));
            $v = (string) end($parts); // the entry appended by YOUR proxy is the right-most one
        }
        if (filter_var($v, FILTER_VALIDATE_IP)) {
            return $v;
        }
    }
    // Prefer the core's own notion of "client IP" so rate limits match the IP stored on leads.
    foreach (['client_ip', 'lh_client_ip', 'get_client_ip', 'request_ip'] as $fn) {
        if (function_exists($fn)) {
            try {
                $rf = new ReflectionFunction($fn);
                if ($rf->getNumberOfRequiredParameters() === 0) {
                    $v = $fn();
                    if (is_string($v) && filter_var($v, FILTER_VALIDATE_IP)) {
                        return $v;
                    }
                }
            } catch (Throwable $e) {
                // ignore and fall through
            }
        }
    }
    $v = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($v, FILTER_VALIDATE_IP) ? $v : '';
}

/** Hostname only, lower-case, without port and leading "www.". */
function lh_sec_host(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    $h = parse_url(strpos($url, '//') === false ? '//' . $url : $url, PHP_URL_HOST);
    $h = is_string($h) ? strtolower($h) : '';
    return preg_replace('/^www\./', '', $h) ?? '';
}

function lh_sec_host_allowed(string $host, array $allowed): bool
{
    if ($host === '') {
        return false;
    }
    foreach ($allowed as $a) {
        if ($a !== '' && ($host === $a || substr($host, -(strlen($a) + 1)) === '.' . $a)) {
            return true;
        }
    }
    return false;
}

function lh_sec_is_dev_host(string $host): bool
{
    return $host === 'localhost' || $host === '127.0.0.1' || $host === '::1' || $host === '[::1]'
        || (bool) preg_match('/\.(?:local|localhost|test|internal)$/', $host);
}

/** Hosts the website legitimately runs on: its domain, allowed_origins column, per-site extra trusted hosts. */
function lh_sec_site_hosts(array $site, array $cfg): array
{
    $hosts = [lh_sec_host((string) ($site['domain'] ?? ''))];
    $extra = (string) ($site['allowed_origins'] ?? '') . "\n" . (string) ($cfg['extra_hosts'] ?? '');
    foreach (preg_split('/[\s,;]+/', $extra, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $x) {
        $hosts[] = lh_sec_host($x);
    }
    return array_values(array_unique(array_filter($hosts)));
}

// ---------------------------------------------------------------------------------------------
// Request context (reads the body without consuming it: php://input stays readable for the core)
// ---------------------------------------------------------------------------------------------

function lh_sec_leaves($data, string $path, array &$out, int $depth = 0, bool $top = true): void
{
    if (count($out) >= 400 || $depth > 6) {
        return;
    }
    if (is_array($data)) {
        foreach ($data as $k => $v) {
            $k = (string) $k;
            if ($top && in_array($k, ['key', 'idempotency_key', 'source_url', 'page_title', 'referrer', 'channel', 'hp'], true)) {
                continue; // envelope values: credentials / meta (meta is scanned separately with stricter rules)
            }
            if (!ctype_digit($k) && $k !== '') {
                $out[] = ['path' => 'field name', 'v' => lh_sec_clip($k, 300), 'meta' => false];
            }
            lh_sec_leaves($v, $path === '' ? $k : $path . '.' . $k, $out, $depth + 1, false);
        }
        return;
    }
    if (is_string($data) || is_int($data) || is_float($data)) {
        $s = (string) $data;
        if ($s !== '') {
            $out[] = ['path' => $path, 'v' => $s, 'meta' => false];
        }
    }
}

function lh_sec_context(): ?array
{
    $raw = @file_get_contents('php://input');
    $raw = is_string($raw) ? $raw : '';
    $data = null;
    if ($raw !== '') {
        $j = json_decode($raw, true);
        if (is_array($j)) {
            $data = $j;
        }
    }
    if ($data === null && !empty($_POST) && is_array($_POST)) {
        $data = $_POST;
    }
    if ($data === null) {
        return null; // nothing we can parse: the core produces its own error response
    }
    $reserved = ['key', 'channel', 'idempotency_key', 'source_url', 'page_title', 'referrer', 'hp', 'fields'];
    $fields = (isset($data['fields']) && is_array($data['fields'])) ? $data['fields'] : array_diff_key($data, array_flip($reserved));

    $key = $data['key'] ?? '';
    if (!is_string($key) || $key === '') {
        $key = (string) ($_SERVER['HTTP_X_API_KEY'] ?? '');
        $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if ($key === '' && stripos($auth, 'bearer ') === 0) {
            $key = trim(substr($auth, 7));
        }
    }
    $str = static function ($v, int $n): string {
        return is_string($v) ? lh_sec_clip($v, $n) : '';
    };
    $hp = $data['hp'] ?? ($data['_lh_hp'] ?? ($fields['_lh_hp'] ?? ''));

    return [
        'key' => lh_sec_clip($key, 200),
        'data' => $data,
        'fields' => $fields,
        'channel' => in_array($data['channel'] ?? '', ['form', 'whatsapp', 'email'], true) ? $data['channel'] : 'form',
        'idem' => $str($data['idempotency_key'] ?? '', 64),
        'source_url' => $str($data['source_url'] ?? '', 2048),
        'page_title' => $str($data['page_title'] ?? '', 255),
        'referrer' => $str($data['referrer'] ?? '', 2048),
        'honeypot' => is_scalar($hp) && trim((string) $hp) !== '',
        'ua' => lh_sec_clip((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 512),
        'origin' => lh_sec_clip((string) ($_SERVER['HTTP_ORIGIN'] ?? ''), 255),
        'referer_hdr' => lh_sec_clip((string) ($_SERVER['HTTP_REFERER'] ?? ''), 2048),
        'accept_lang' => (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''),
    ];
}

function lh_sec_find_site(string $key): ?array
{
    if ($key === '' || strlen($key) > 120) {
        return null;
    }
    $row = db_one(
        'SELECT id, name, domain, allowed_origins, is_active FROM websites WHERE api_key = ? OR api_key_hash = ? LIMIT 1',
        [$key, hash('sha256', $key)]
    );
    return $row ?: null;
}

// ---------------------------------------------------------------------------------------------
// Detection: SQL injection + XSS (pattern tiers: weight >= 100 => block, >= 40 => quarantine)
// ---------------------------------------------------------------------------------------------

function lh_sec_patterns(string $type): array
{
    static $p = null;
    if ($p === null) {
        $p = [
            'sqli' => [
                ['~\bunion\b[\s/*+()]*(?:all\b|distinct\b)?[\s/*+()]*select\b~i', 100, 'UNION SELECT'],
                ['~[\'"`]\s*\)?\s*(?:or|and|xor|\|\||&&)\s+[\'"(]?\w+[\'")]?\s*(?:=|like\b|<|>)~i', 100, 'quote-based boolean injection'],
                ['~\b(?:or|and)\b\s+\(?\s*[\'"]?(\w+)[\'"]?\s*=\s*[\'"]?\1\b~i', 100, 'tautology (1=1)'],
                ['~\b(?:or|and)\s+\d+\s*=\s*\d+\b~i', 60, 'numeric comparison injection'],
                ['~;\s*(?:drop|truncate|alter|create|rename)\s+(?:table|database|schema|user|index|view)\b~i', 100, 'stacked DDL statement'],
                ['~;\s*(?:delete\s+from|insert\s+into|update\s+[\w`.]+\s+set|shutdown\b|exec(?:ute)?\b|declare\s+@)~i', 100, 'stacked DML statement'],
                ['~\b(?:sleep|benchmark|pg_sleep|randomblob)\s*\(\s*\d~i', 100, 'time-based injection'],
                ['~\bwaitfor\s+delay\s+[\'"]~i', 100, 'WAITFOR DELAY'],
                ['~\b(?:information_schema|mysql\.user|pg_catalog|sysobjects|sqlite_master|sys\.databases)\b~i', 100, 'schema enumeration'],
                ['~\b(?:load_file|extractvalue|updatexml|xp_cmdshell|sp_executesql)\s*\(~i', 100, 'dangerous SQL function'],
                ['~\binto\s+(?:out|dump)file\b~i', 100, 'INTO OUTFILE'],
                ['~@@(?:version|datadir|hostname|basedir)\b~i', 100, 'server variable probe'],
                ['~\border\s+by\s+\d+\s*(?:--|#|/\*|;)~i', 100, 'ORDER BY probing'],
                ['~\bhaving\s+\d+\s*=\s*\d+~i', 100, 'HAVING probe'],
                ['~\bselect\b[^;]{0,120}\bfrom\b[^;]{0,120}\bwhere\b~i', 60, 'SELECT ... FROM ... WHERE'],
                ['~\b(?:insert\s+into\s+[\w`.]+\s*(?:\(|values|select)|delete\s+from\s+[\w`.]+\s+where|update\s+[\w`.]+\s+set\s+[\w`.]+\s*=)~i', 60, 'DML statement'],
                ['~\bchar\s*\(\s*\d+(?:\s*,\s*\d+)+\s*\)~i', 60, 'CHAR() string building'],
                ['~[\'"]\s*\)?\s*(?:--(?:\s|$)|#(?:\s|$)|/\*)~', 40, 'quote followed by SQL comment'],
                ['~\bselect\b[\s\S]{0,80}\bfrom\b~i', 25, 'SELECT ... FROM'],
            ],
            'xss' => [
                ['~<\s*script\b~i', 100, '<script> tag'],
                ['~<\s*/\s*script\s*>~i', 100, '</script> tag'],
                ['~\bjavascript\s*:\s*(?:alert|eval|document|window|void|fetch|location|prompt|confirm|this|top|self|\w+\s*\()~i', 100, 'javascript: URI'],
                ['~\bvbscript\s*:~i', 100, 'vbscript: URI'],
                ['~\bdata\s*:\s*text/html~i', 100, 'data:text/html URI'],
                ['~<[^>]{0,300}?\bon(?:error|load|click|dblclick|mouse[a-z]+|key[a-z]+|focus[a-z]*|blur|submit|change|input|toggle|animation[a-z]+|transition[a-z]+|pointer[a-z]+|touch[a-z]+|drag[a-z]*|drop|scroll|wheel|resize|begin|start|show|reset|abort|copy|cut|paste|play|pause|message|contextmenu|unload|hashchange|popstate)\s*=~i', 100, 'inline event handler'],
                ['~<\s*(?:iframe|object|embed|applet|frameset)\b~i', 100, 'embedded-content tag'],
                ['~<\s*(?:meta\b[^>]*http-equiv|base\b[^>]*href)~i', 100, 'meta/base injection'],
                ['~\bdocument\s*\.\s*(?:cookie|write(?:ln)?|location|domain)\b~i', 100, 'document.* access'],
                ['~\b(?:window|self|top|parent)\s*\.\s*location\s*=~i', 100, 'location redirect'],
                ['~style\s*=[^>]*expression\s*\(~i', 100, 'CSS expression()'],
                ['~&lt;\s*/?\s*script|&#0*60;\s*script|%3c\s*script|\\\\x3c\s*script|\\\\u003c\s*script~i', 100, 'encoded <script>'],
                ['~\bString\s*\.\s*fromCharCode\s*\(~i', 80, 'String.fromCharCode'],
                ['~\b(?:eval|setTimeout|setInterval|Function)\s*\(\s*(?:[\'"`]|atob\b|String\b)~i', 80, 'eval-style execution'],
                ['~\batob\s*\(\s*[\'"]~i', 60, 'atob() payload'],
                ['~<\s*a\b[^>]*\bhref\s*=~i', 45, 'HTML link'],
                ['~\[\s*(?:url|link)\s*=~i', 45, 'BBCode link'],
                ['~<\s*svg\b[^>]*>~i', 45, 'SVG markup'],
                ['~<\s*/?\s*(?:img|style|link|body|html|input|textarea|button|marquee|details|video|audio|source|math|form|select|option|template|noscript|center|font)\b[^>]*>~i', 45, 'HTML markup'],
                ['~<\s*/?\s*[a-z][a-z0-9]*\b[^<>]{0,200}>~i', 20, 'HTML tag'],
            ],
        ];
    }
    return $p[$type] ?? [];
}

/** Original text + URL/entity-decoded form (+ SQL comments flattened) so encoded payloads are caught too. */
function lh_sec_variants(string $v, bool $sql): array
{
    $v = substr(str_replace("\0", '', $v), 0, 8000);
    $out = [$v];
    $d = $v;
    for ($i = 0; $i < 2; $i++) {
        $n = $d;
        if (preg_match('/%[0-9a-f]{2}/i', $n)) {
            $n = rawurldecode($n);
        }
        if (strpos($n, '&') !== false) {
            $n = html_entity_decode($n, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if ($n === $d) {
            break;
        }
        $d = $n;
    }
    if ($d !== $v) {
        $out[] = $d;
    }
    if ($sql) {
        $flat = preg_replace('~/\*.*?\*/~s', ' ', $d);
        if (is_string($flat) && $flat !== $d) {
            $out[] = $flat;
        }
    }
    return $out;
}

/** @return array|null ['score'=>int,'labels'=>string[],'path'=>string,'sample'=>string] for the worst leaf, or null */
function lh_sec_scan(string $type, array $leaves): ?array
{
    $pats = lh_sec_patterns($type);
    $best = null;
    foreach ($leaves as $lf) {
        $variants = lh_sec_variants((string) $lf['v'], $type === 'sqli');
        $score = 0;
        $labels = [];
        foreach ($pats as $p) {
            if (!empty($lf['meta']) && $p[1] < 100) {
                continue; // URLs / page titles: only unambiguous attack strings count
            }
            foreach ($variants as $t) {
                if (@preg_match($p[0], $t) === 1) {
                    $score += $p[1];
                    $labels[] = $p[2];
                    break;
                }
            }
        }
        if ($score > 0 && ($best === null || $score > $best['score'])) {
            $best = ['score' => min(300, $score), 'labels' => $labels, 'path' => (string) $lf['path'], 'sample' => lh_sec_clip((string) $lf['v'], 200)];
        }
    }
    return $best;
}

function lh_sec_scan_verdict(string $type, array $leaves, string $title): ?array
{
    $r = lh_sec_scan($type, $leaves);
    if ($r === null || $r['score'] < 40) {
        return null;
    }
    $strong = $r['score'] >= 100;
    return [
        'rule' => $type,
        'action' => $strong ? 'block' : 'quarantine',
        'score' => $r['score'],
        'reason' => $title . ' (' . implode(', ', array_slice($r['labels'], 0, 3)) . ') in "' . lh_sec_clip($r['path'], 60) . '"',
        'sample' => $r['sample'],
    ];
}

// ---------------------------------------------------------------------------------------------
// Detection: bots
// ---------------------------------------------------------------------------------------------

function lh_sec_max_urls(array $leaves): int
{
    $max = 0;
    foreach ($leaves as $lf) {
        $n = preg_match_all('~https?://|\bwww\.~i', substr((string) $lf['v'], 0, 8000));
        $max = max($max, (int) $n);
    }
    return $max;
}

function lh_sec_bot_verdict(array $ctx, array $site, array $cfg, array $leaves): ?array
{
    if ($ctx['honeypot']) {
        return ['rule' => 'bot', 'action' => 'block', 'score' => 100, 'silent' => true,
            'reason' => 'Honeypot field was filled (automated form filler)', 'sample' => ''];
    }
    $ua = $ctx['ua'];
    if ($ua !== '' && preg_match('~\b(?:sqlmap|nikto|nmap|masscan|acunetix|nessus|wpscan|dirbuster|gobuster|havij|w3af|zgrab|nuclei|openvas|netsparker|appscan|arachni)\b~i', $ua, $m)) {
        return ['rule' => 'bot', 'action' => 'block', 'score' => 100,
            'reason' => 'Known attack / scanner tool in User-Agent (' . strtolower($m[0]) . ')', 'sample' => lh_sec_clip($ua, 120)];
    }

    $score = 0;
    $why = [];
    $add = static function (int $n, string $t) use (&$score, &$why): void {
        $score += $n;
        $why[] = $t . ' (+' . $n . ')';
    };

    $browserLike = (bool) preg_match('~^Mozilla/\d.*(?:AppleWebKit|Gecko|Trident)~i', $ua) && (bool) preg_match('~(?:Chrome|Safari|Firefox|Edg|OPR|Version)/~i', $ua);
    if ($ua === '') {
        $add(30, 'no User-Agent');
    } elseif (strlen($ua) < 10) {
        $add(20, 'very short User-Agent');
    }
    if ($ua !== '') {
        if (preg_match('~headlesschrome|phantomjs|selenium|puppeteer|playwright|webdriver~i', $ua)) {
            $add(60, 'headless / browser-automation User-Agent');
        } elseif (!$browserLike && preg_match('~\b(?:bot|crawler|spider|scraper|slurp)\b|[a-z]bot/|[a-z]bot\b~i', $ua)) {
            $add(60, 'bot-style User-Agent');
        } elseif (preg_match('~python-requests|python-urllib|aiohttp|httpx|go-http-client|okhttp|apache-httpclient|libwww-perl|scrapy|node-fetch|axios/|undici|postmanruntime|insomnia|curl/|wget/|java/\d|httpie|restsharp|mechanize|jsdom|colly|reqwest|faraday~i', $ua)) {
            $add(45, 'HTTP-library User-Agent');
        }
    }
    if (trim($ctx['accept_lang']) === '') {
        $add(10, 'no Accept-Language header');
    }

    // Origin / source-page consistency with the registered website. Keys are public in page source, so a
    // bot that scraped one will usually post from somewhere else.
    $hosts = lh_sec_site_hosts($site, $cfg);
    $originHost = lh_sec_host($ctx['origin']);
    $refHost = lh_sec_host($ctx['referer_hdr']);
    $srcHost = lh_sec_host($ctx['source_url']);
    if ($originHost !== '') {
        if (!lh_sec_is_dev_host($originHost) && !lh_sec_host_allowed($originHost, $hosts)) {
            $add(35, 'Origin ' . $originHost . ' is not this website');
        }
    } elseif ($refHost === '') {
        $add(10, 'no Origin / Referer');
    } elseif (!lh_sec_is_dev_host($refHost) && !lh_sec_host_allowed($refHost, $hosts)) {
        $add(25, 'Referer ' . $refHost . ' is not this website');
    }
    if ($srcHost === '') {
        $add(10, 'no source_url');
    } elseif (!lh_sec_is_dev_host($srcHost) && !lh_sec_host_allowed($srcHost, $hosts)) {
        $add(30, 'source_url host ' . $srcHost . ' is not this website');
    }

    // Spam-bot content signals.
    $urls = lh_sec_max_urls($leaves);
    if ($urls >= 6) {
        $add(60, $urls . ' links in one field');
    } elseif ($urls >= 3) {
        $add(35, $urls . ' links in one field');
    }
    $blob = '';
    foreach ($leaves as $lf) {
        if ($lf['path'] !== 'field name') {
            $blob .= substr((string) $lf['v'], 0, 4000) . "\n";
            if (strlen($blob) > 60000) {
                break;
            }
        }
    }
    if (preg_match_all('~\b(?:casino|viagra|cialis|porn|escorts?|payday loans?|buy followers|usdt|bitcoin|forex (?:signals|trading)|per 1,?000 (?:form )?submissions|guest post(?:ing)? (?:service|offer)|backlinks? (?:service|package)|crypto(?:currency)? (?:investment|mining))\b~i', $blob, $mm)) {
        $uniq = array_unique(array_map('strtolower', $mm[0]));
        $add(min(80, 40 * count($uniq)), 'spam wording: ' . implode(', ', array_slice($uniq, 0, 3)));
    }
    foreach (preg_split('/\R/', (string) $cfg['spam_keywords'], -1, PREG_SPLIT_NO_EMPTY) ?: [] as $kw) {
        $kw = trim($kw);
        if ($kw !== '' && stripos($blob, $kw) !== false) {
            $add(60, 'custom spam keyword "' . lh_sec_clip($kw, 30) . '"');
            break;
        }
    }
    if (preg_match('~(?:^|[\s"\'<>:])[\w.+-]*(?:no-?reply|donotreply|do-not-reply|noemail|see_email_in_the_message)@~i', $blob)) {
        $add(20, 'no-reply / placeholder email');
    }
    foreach ($ctx['fields'] as $fk => $fv) {
        if (is_string($fv) && preg_match('/(^|[_\-\s])name$/i', (string) $fk) && preg_match('~https?://|www\.~i', $fv)) {
            $add(40, 'link inside a name field');
            break;
        }
    }

    $threshold = (int) $cfg['bot_threshold'];
    if ($score >= $threshold) {
        return ['rule' => 'bot', 'action' => 'quarantine', 'score' => min(300, $score),
            'reason' => 'Automated-submission score ' . $score . ' (threshold ' . $threshold . '): ' . implode('; ', $why), 'sample' => lh_sec_clip($ua, 120)];
    }
    return null;
}

// ---------------------------------------------------------------------------------------------
// Detection: duplicates / floods (exact re-submits of a saved lead are merged by the core and are NOT touched)
// ---------------------------------------------------------------------------------------------

function lh_sec_norm_text(string $s): string
{
    $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    return trim((string) preg_replace('/\s+/u', ' ', $s));
}

/** @return array [contentHash, bodyHash|null, contactHash|null] */
function lh_sec_hashes(array $fields): array
{
    $flat = [];
    $longest = '';
    $contact = '';
    $walk = function ($v, string $k) use (&$walk, &$flat, &$longest, &$contact): void {
        if (is_array($v)) {
            foreach ($v as $kk => $vv) {
                $walk($vv, $k . '.' . $kk);
            }
            return;
        }
        if (!is_scalar($v) || $k === '' || $k[0] === '_') {
            return;
        }
        $s = lh_sec_norm_text((string) $v);
        if ($s === '') {
            return;
        }
        $flat[] = strtolower($k) . '=' . $s;
        if (strlen($s) > strlen($longest)) {
            $longest = $s;
        }
        if ($contact === '' && preg_match('/e-?mail/i', $k) && strpos($s, '@') !== false) {
            $contact = $s;
        }
    };
    foreach ($fields as $k => $v) {
        $walk($v, (string) $k);
    }
    if ($contact === '') {
        foreach ($fields as $k => $v) {
            if (is_string($v) && preg_match('/(phone|mobile|tel|whatsapp)/i', (string) $k)) {
                $d = preg_replace('/\D+/', '', $v);
                if (is_string($d) && strlen($d) >= 7) {
                    $contact = 'tel:' . substr($d, -10);
                    break;
                }
            }
        }
    }
    sort($flat);
    $content = hash('sha256', implode("\n", $flat));
    $body = strlen($longest) >= 30 ? hash('sha256', (string) preg_replace('/[^\p{L}\p{N} ]+/u', '', $longest)) : null;
    return [$content, $body, $contact !== '' ? hash('sha256', $contact) : null];
}

function lh_sec_dup_verdict(int $siteId, string $ip, array $hashes, array $cfg): ?array
{
    list($content, $body, $contact) = $hashes;
    $now = time();
    if ($body !== null) {
        $since = gmdate('Y-m-d H:i:s', $now - (int) $cfg['dup_window']);
        $n = (int) db_val(
            'SELECT COUNT(*) FROM (SELECT 1 FROM security_seen WHERE body_hash = ? AND created_at >= ? AND NOT (website_id = ? AND ip = ?) GROUP BY website_id, ip) t',
            [$body, $since, $siteId, $ip]
        );
        if ($n + 1 >= (int) $cfg['dup_body_sources']) {
            return ['rule' => 'duplicate', 'action' => 'quarantine', 'score' => 70, 'sample' => '',
                'reason' => 'Identical message already received from ' . $n . ' other source(s) (IP / website) in ' . round((int) $cfg['dup_window'] / 3600, 1) . 'h - typical spam blast'];
        }
    }
    if ($contact !== null) {
        $since = gmdate('Y-m-d H:i:s', $now - 3600);
        $n = (int) db_val(
            'SELECT COUNT(DISTINCT content_hash) FROM security_seen WHERE contact_hash = ? AND created_at >= ? AND content_hash <> ?',
            [$contact, $since, $content]
        );
        if ($n >= (int) $cfg['dup_contact_max']) {
            return ['rule' => 'duplicate', 'action' => 'quarantine', 'score' => 60, 'sample' => '',
                'reason' => 'Same email/phone sent ' . ($n + 1) . ' different submissions within an hour (flood)'];
        }
    }
    return null;
}

function lh_sec_record_seen(int $siteId, string $ip, array $hashes): void
{
    list($content, $body, $contact) = $hashes;
    db_exec(
        'INSERT INTO security_seen (website_id, ip, content_hash, body_hash, contact_hash, created_at) VALUES (?, ?, ?, ?, ?, ?)',
        [$siteId, $ip, $content, $body, $contact, utc_now()]
    );
}

// ---------------------------------------------------------------------------------------------
// Rate limiting (re-uses the existing `rate_limits` table; own bucket namespace, fixed windows)
// ---------------------------------------------------------------------------------------------

/** @return array [hits, retryAfterSeconds] */
function lh_sec_rate_hit(string $scope, int $window): array
{
    $now = time();
    $start = $now - ($now % $window);
    $bucket = hash('sha256', 'lhsec|rl|' . $scope . '|' . $window . '|' . $start);
    db_exec(
        'INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE hits = hits + 1',
        [$bucket, $start]
    );
    $hits = (int) db_val('SELECT hits FROM rate_limits WHERE bucket = ?', [$bucket]);
    return [$hits, max(1, $start + $window - $now)];
}

function lh_sec_rate_verdict(string $ip, int $siteId, array $cfg): ?array
{
    $window = (int) $cfg['rl_window'];
    $max = (int) $cfg['rl_max'];
    list($h1, $retry) = lh_sec_rate_hit('ipsite|' . $ip . '|' . $siteId, $window);
    list($h2, $retry2) = lh_sec_rate_hit('ip|' . $ip, $window);
    if ($h1 > $max) {
        return ['rule' => 'rate_limit', 'action' => 'block', 'score' => 100, 'status' => 429, 'retry' => $retry, 'sample' => '',
            'reason' => 'More than ' . $max . ' submissions in ' . round($window / 60, 1) . ' min from this IP to this website (' . $h1 . ')'];
    }
    if ($h2 > $max * 3) {
        return ['rule' => 'rate_limit', 'action' => 'block', 'score' => 100, 'status' => 429, 'retry' => $retry2, 'sample' => '',
            'reason' => 'More than ' . ($max * 3) . ' submissions in ' . round($window / 60, 1) . ' min from this IP across websites (' . $h2 . ')'];
    }
    return null;
}

// ---------------------------------------------------------------------------------------------
// Storage: log + quarantine
// ---------------------------------------------------------------------------------------------

function lh_sec_log(array $ctx, ?int $siteId, string $ip, string $action, bool $enforced, array $verdicts, ?int $quarantineId): void
{
    $rules = [];
    $reasons = [];
    $score = 0;
    $sample = '';
    foreach ($verdicts as $v) {
        $rules[$v['rule']] = true;
        $reasons[] = $v['reason'];
        $score = max($score, (int) $v['score']);
        if ($sample === '' && !empty($v['sample'])) {
            $sample = (string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', (string) $v['sample']);
        }
    }
    db_exec(
        'INSERT INTO security_log (created_at, website_id, ip, action, enforced, rule, reason, score, user_agent, origin, source_url, sample, quarantine_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            utc_now(), $siteId ?: null, lh_sec_clip($ip, 45), $action, $enforced ? 1 : 0,
            lh_sec_clip(implode(',', array_keys($rules)), 120), lh_sec_clip(implode(' | ', $reasons), 1000), min(65000, $score),
            $ctx ? lh_sec_clip((string) $ctx['ua'], 512) : null, $ctx ? lh_sec_clip((string) $ctx['origin'], 255) : null,
            $ctx ? lh_sec_clip((string) $ctx['source_url'], 500) : null, $sample !== '' ? lh_sec_clip($sample, 400) : null, $quarantineId,
        ]
    );
    if (function_exists('log_event')) {
        try {
            log_event($action === 'blocked' ? 'warning' : 'info', 'security.' . $action, ['site' => $siteId, 'ip' => $ip, 'rule' => implode(',', array_keys($rules)), 'enforced' => $enforced]);
        } catch (Throwable $e) {
            // logging must never matter
        }
    }
}

/** @return array [quarantineId|0, publicId] */
function lh_sec_quarantine_store(array $ctx, int $siteId, string $ip, array $verdicts, string $contentHash, array $cfg): array
{
    // Same payload already waiting for review: count it, do not pile up copies.
    $existing = db_one(
        "SELECT id, public_id FROM security_quarantine WHERE website_id = ? AND content_hash = ? AND status = 'pending' ORDER BY id DESC LIMIT 1",
        [$siteId, $contentHash]
    );
    if ($existing) {
        db_exec('UPDATE security_quarantine SET hits = hits + 1, last_seen_at = ? WHERE id = ?', [utc_now(), (int) $existing['id']]);
        return [(int) $existing['id'], (string) $existing['public_id']];
    }
    $publicId = lh_sec_new_public_id();
    if ((int) db_val("SELECT COUNT(*) FROM security_quarantine WHERE status = 'pending'") >= (int) $cfg['quarantine_cap']) {
        return [0, $publicId]; // review queue is full: acknowledge, log, do not store
    }
    $rules = [];
    $reasons = [];
    $score = 0;
    foreach ($verdicts as $v) {
        $rules[$v['rule']] = true;
        $reasons[] = $v['reason'];
        $score = max($score, (int) $v['score']);
    }
    $now = utc_now();
    db_exec(
        'INSERT INTO security_quarantine (public_id, website_id, status, channel, rule, reason, score, ip, user_agent, origin, source_url, page_title, referrer, idempotency_key, payload, content_hash, hits, created_at, last_seen_at)
         VALUES (?, ?, \'pending\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)',
        [
            $publicId, $siteId, $ctx['channel'], lh_sec_clip(implode(',', array_keys($rules)), 120), lh_sec_clip(implode(' | ', $reasons), 1000), min(65000, $score),
            lh_sec_clip($ip, 45), $ctx['ua'] !== '' ? $ctx['ua'] : null, $ctx['origin'] !== '' ? $ctx['origin'] : null,
            $ctx['source_url'] !== '' ? $ctx['source_url'] : null, $ctx['page_title'] !== '' ? $ctx['page_title'] : null,
            $ctx['referrer'] !== '' ? $ctx['referrer'] : null, $ctx['idem'] !== '' ? $ctx['idem'] : null,
            lh_sec_json($ctx['fields']), $contentHash, $now, $now,
        ]
    );
    return [(int) db()->lastInsertId(), $publicId];
}

function lh_sec_pending_count(): int
{
    try {
        return (int) db_val("SELECT COUNT(*) FROM security_quarantine WHERE status = 'pending'");
    } catch (Throwable $e) {
        return 0;
    }
}

function lh_sec_maybe_purge(array $cfg): void
{
    if (mt_rand(1, 300) !== 1) {
        return;
    }
    $cut = gmdate('Y-m-d H:i:s', time() - (int) $cfg['retention_days'] * 86400);
    db_exec('DELETE FROM security_log WHERE created_at < ? LIMIT 2000', [$cut]);
    db_exec("DELETE FROM security_quarantine WHERE status <> 'pending' AND COALESCE(reviewed_at, created_at) < ? LIMIT 500", [$cut]);
    db_exec('DELETE FROM security_seen WHERE created_at < ? LIMIT 5000', [gmdate('Y-m-d H:i:s', time() - max(2 * (int) $cfg['dup_window'], 172800))]);
    db_exec('DELETE FROM rate_limits WHERE window_start < ? LIMIT 5000', [time() - 7 * 86400]);
}

// ---------------------------------------------------------------------------------------------
// Responses (same JSON envelope the SDK already understands: {"ok":..., "error":{"code","message"}})
// ---------------------------------------------------------------------------------------------

function lh_sec_respond_error(int $status, string $code, string $message, array $headers = []): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        foreach ($headers as $h) {
            header($h);
        }
    }
    echo json_encode(['ok' => false, 'error' => ['code' => $code, 'message' => $message]], JSON_UNESCAPED_SLASHES);
    exit;
}

/** Success-shaped reply: used for quarantined submissions and honeypot hits so bots learn nothing. */
function lh_sec_respond_ok(string $publicId): void
{
    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode(['ok' => true, 'lead_id' => $publicId, 'duplicate' => false], JSON_UNESCAPED_SLASHES);
    exit;
}

// ---------------------------------------------------------------------------------------------
// The guard
// ---------------------------------------------------------------------------------------------

/** Call once from api/v1/leads.php, before api_handle(). Returns normally when the core should proceed. */
function lh_sec_guard(): void
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
        return;
    }
    try {
        lh_sec_guard_run();
    } catch (Throwable $e) {
        // FAIL OPEN: a problem in the engine must never stop genuine leads.
        if (function_exists('log_event')) {
            try {
                log_event('error', 'security.engine_error', ['error' => $e->getMessage(), 'at' => basename($e->getFile()) . ':' . $e->getLine()]);
            } catch (Throwable $e2) {
                // ignore
            }
        }
    }
}

function lh_sec_guard_run(): void
{
    if (!function_exists('db_all') || !function_exists('db_exec') || !function_exists('utc_now')) {
        return;
    }
    $g = lh_sec_config();
    if (!(int) $g['schema']) {
        // First run after deployment: create the security tables once (idempotent), then continue.
        require_once __DIR__ . '/_security_admin.php';
        lh_sec_ensure_schema();
        setting_set('sec_schema', '1');
        $g = lh_sec_config(true);
    }
    if (!(int) $g['engine']) {
        return;
    }
    $ip = lh_sec_client_ip($g);
    if ($ip !== '' && $g['trusted_ips'] !== '' && lh_sec_ip_in_list($ip, (string) $g['trusted_ips'])) {
        return;
    }
    $ipKey = $ip !== '' ? $ip : 'unknown';

    $ctx = lh_sec_context();
    $site = $ctx ? lh_sec_find_site($ctx['key']) : null;
    if ($site && !(int) $site['is_active']) {
        return; // the core rejects inactive websites exactly as before
    }
    $siteId = $site ? (int) $site['id'] : 0;
    $cfg = lh_sec_effective($g, $site ? lh_sec_site_config($siteId) : []);
    if (!(int) $cfg['engine']) {
        return;
    }
    $enforce = !(int) $cfg['monitor'];
    lh_sec_maybe_purge($cfg);

    // 1. Rate limit (also protects against API-key guessing: unknown keys are counted per IP).
    if ((int) $cfg['rate_limit']) {
        $rl = lh_sec_rate_verdict($ipKey, $siteId, $cfg);
        if ($rl) {
            lh_sec_log($ctx ?: [], $siteId ?: null, $ipKey, 'blocked', $enforce, [$rl], null);
            if ($enforce) {
                lh_sec_respond_error(429, 'rate_limited', 'Too many submissions. Please try again shortly.', ['Retry-After: ' . (int) $rl['retry']]);
            }
        }
    }
    if (!$ctx || !$site) {
        return; // unparsable body / unknown key: the core answers exactly as it always did
    }

    // 2. Content + behaviour checks.
    $leaves = [];
    lh_sec_leaves($ctx['data'], '', $leaves);
    $meta = [];
    foreach (['source_url' => 'source_url', 'page_title' => 'page_title', 'referrer' => 'referrer'] as $k => $label) {
        if ($ctx[$k] !== '') {
            $meta[] = ['path' => $label, 'v' => $ctx[$k], 'meta' => true];
        }
    }
    $allLeaves = array_merge($leaves, $meta);

    $verdicts = [];
    if ((int) $cfg['bot']) {
        $v = lh_sec_bot_verdict($ctx, $site, $cfg, $leaves);
        if ($v) {
            $verdicts[] = $v;
        }
    }
    if ((int) $cfg['sqli']) {
        $v = lh_sec_scan_verdict('sqli', $allLeaves, 'SQL injection pattern');
        if ($v) {
            $verdicts[] = $v;
        }
    }
    if ((int) $cfg['xss']) {
        $v = lh_sec_scan_verdict('xss', $allLeaves, 'XSS / markup injection');
        if ($v) {
            $verdicts[] = $v;
        }
    }
    $hashes = lh_sec_hashes($ctx['fields']);
    if ((int) $cfg['duplicate'] && !$verdicts) {
        $v = lh_sec_dup_verdict($siteId, $ipKey, $hashes, $cfg);
        if ($v) {
            $verdicts[] = $v;
        }
    }

    // 3. Decide.
    $block = [];
    $quar = [];
    foreach ($verdicts as $v) {
        if ($v['action'] === 'block') {
            $block[] = $v;
        } else {
            $quar[] = $v;
        }
    }

    if ($block) {
        lh_sec_log($ctx, $siteId, $ipKey, 'blocked', $enforce, array_merge($block, $quar), null);
        if ($enforce) {
            $silent = false;
            foreach ($block as $b) {
                $silent = $silent || !empty($b['silent']);
            }
            if ($silent) {
                lh_sec_respond_ok(lh_sec_new_public_id()); // honeypot: look successful, save nothing
            }
            lh_sec_respond_error(400, 'submission_rejected', 'Your submission could not be accepted.');
        }
    } elseif ($quar) {
        if (!(int) $cfg['quarantine']) {
            lh_sec_log($ctx, $siteId, $ipKey, 'flagged', true, $quar, null); // suspicious but delivered normally
        } elseif (!$enforce) {
            lh_sec_log($ctx, $siteId, $ipKey, 'quarantined', false, $quar, null); // monitor mode: log only
        } else {
            list($qid, $publicId) = lh_sec_quarantine_store($ctx, $siteId, $ipKey, $quar, $hashes[0], $cfg);
            if ($qid > 0) {
                lh_sec_log($ctx, $siteId, $ipKey, 'quarantined', true, $quar, $qid);
            } else {
                lh_sec_log($ctx, $siteId, $ipKey, 'blocked', true, array_merge($quar, [['rule' => 'engine', 'action' => 'block', 'score' => 0, 'reason' => 'Quarantine queue is full - submission acknowledged but not stored', 'sample' => '']]), null);
            }
            if ((int) $cfg['duplicate']) {
                try {
                    lh_sec_record_seen($siteId, $ipKey, $hashes);
                } catch (Throwable $e) {
                    // history is best-effort
                }
            }
            lh_sec_respond_ok($publicId);
        }
    }

    // 4. Allowed: remember it for duplicate/flood detection, then let the core handle it untouched.
    if ((int) $cfg['duplicate']) {
        try {
            lh_sec_record_seen($siteId, $ipKey, $hashes);
        } catch (Throwable $e) {
            // best-effort
        }
    }
}
