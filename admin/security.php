<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_bootstrap.php';
require_once dirname(__DIR__) . '/_security_admin.php';
admin_init();
lh_rm_restore();
$user = require_admin();
lh_sec_ensure_schema();

$module_help = [
    'rate_limit' => 'Blocks an IP that submits too often (HTTP 429 + Retry-After, which the website SDK already handles).',
    'sqli'       => 'Blocks clear SQL-injection payloads; quarantines borderline text. Ordinary words like "select" or apostrophes are fine.',
    'xss'        => 'Blocks script tags, event handlers and javascript: links; quarantines submissions containing HTML links / markup.',
    'duplicate'  => 'Quarantines spam blasts (same message from several sources) and floods from one email/phone. Exact re-submits of a saved lead are still merged by the CRM as before.',
    'bot'        => 'Honeypot, scanner tools, automation User-Agents, wrong-website origin and spam wording. Combined score decides; one weak signal never rejects anyone.',
    'quarantine' => 'Holds suspicious submissions for your review instead of saving them as leads. The visitor still sees the normal success message. When OFF, suspicious items are delivered as normal leads (only clearly malicious requests are blocked).',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_csrf();
    if (post_str('action', 20) === 'save') {
        $errors = [];
        $vals = ['engine' => isset($_POST['engine']) ? 1 : 0, 'monitor' => isset($_POST['monitor']) ? 1 : 0];
        foreach (array_keys(LH_SEC_MODULES) as $m) {
            $vals[$m] = isset($_POST[$m]) ? 1 : 0;
        }
        $vals['rl_max'] = (int) post_str('rl_max', 6);
        $vals['rl_window'] = (int) post_str('rl_window_min', 6) * 60;
        $vals['bot_threshold'] = (int) post_str('bot_threshold', 4);
        $vals['dup_body_sources'] = (int) post_str('dup_body_sources', 4);
        $vals['dup_window'] = (int) post_str('dup_window_h', 5) * 3600;
        $vals['dup_contact_max'] = (int) post_str('dup_contact_max', 4);
        $vals['retention_days'] = (int) post_str('retention_days', 5);
        $hdr = post_str('ip_header', 20);
        $vals['ip_header'] = in_array($hdr, ['', 'cf-connecting-ip', 'x-real-ip', 'x-forwarded-for'], true) ? $hdr : '';

        $trusted = is_string($_POST['trusted_ips'] ?? null) ? (string) $_POST['trusted_ips'] : '';
        $clean = [];
        foreach (preg_split('/[\s,;]+/', $trusted, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
            $parts = explode('/', $entry, 2);
            $okIp = filter_var($parts[0], FILTER_VALIDATE_IP) !== false;
            $max = ($okIp && strpos($parts[0], ':') !== false) ? 128 : 32;
            if (!$okIp || (isset($parts[1]) && (!ctype_digit($parts[1]) || (int) $parts[1] > $max))) {
                $errors[] = 'Trusted IP "' . $entry . '" is not a valid IP address or CIDR range.';
            } else {
                $clean[] = $entry;
            }
        }
        $vals['trusted_ips'] = implode("\n", array_unique($clean));
        $kw = is_string($_POST['spam_keywords'] ?? null) ? (string) $_POST['spam_keywords'] : '';
        $kws = array_values(array_filter(array_map('trim', preg_split('/\R/', $kw) ?: []), static function ($k) {
            return $k !== '';
        }));
        $vals['spam_keywords'] = implode("\n", array_slice(array_map(static function ($k) {
            return lh_sec_clip($k, 60);
        }, $kws), 0, 100));

        if ($errors) {
            foreach ($errors as $err) {
                flash('error', $err);
            }
        } else {
            lh_sec_config_save($vals);
            log_event('info', 'security.settings_updated', ['user_id' => (int) $user['id']]);
            flash('success', 'Security settings saved. They apply to every website immediately.');
        }
    }
    redirect('security.php');
}

$g = lh_sec_config(true);
$since24 = gmdate('Y-m-d H:i:s', time() - 86400);
$since7 = gmdate('Y-m-d H:i:s', time() - 7 * 86400);
$st = db_one(
    "SELECT COALESCE(SUM(action = 'blocked' AND enforced = 1), 0) AS b, COALESCE(SUM(action = 'quarantined' AND enforced = 1), 0) AS q,
            COALESCE(SUM(action = 'flagged'), 0) AS f, COALESCE(SUM(enforced = 0), 0) AS m
     FROM security_log WHERE created_at >= ?",
    [$since24]
) ?: ['b' => 0, 'q' => 0, 'f' => 0, 'm' => 0];
$pending = lh_sec_pending_count();

$perSite = [];
foreach (db_all("SELECT website_id, COALESCE(SUM(action = 'blocked'), 0) AS b, COALESCE(SUM(action = 'quarantined'), 0) AS q FROM security_log WHERE created_at >= ? AND website_id IS NOT NULL GROUP BY website_id", [$since7]) as $r) {
    $perSite[(int) $r['website_id']] = $r;
}
$overrides = [];
foreach (db_all('SELECT website_id, config FROM security_site_rules') as $r) {
    $j = json_decode((string) $r['config'], true);
    $overrides[(int) $r['website_id']] = is_array($j) ? $j : [];
}
$sites = db_all('SELECT id, name, domain, is_active FROM websites ORDER BY is_active DESC, name');

$engineOn = (int) $g['engine'] === 1;
$monitor = (int) $g['monitor'] === 1;
$statusClass = $engineOn && !$monitor ? 'ok' : 'off';
$statusText = !$engineOn ? 'Security engine OFF' : ($monitor ? 'Monitor only (not blocking)' : 'Security engine ON');

page_header('Security Settings', 'settings');
?>
<div class="page-head">
  <div><p class="eyebrow">Administration</p><h1>Security Settings</h1>
    <p class="muted">One central engine checks every submission to <code>/api/v1/leads</code> for all registered websites. No website code, form or API key needs to change.</p></div>
  <span class="status-dot <?= $statusClass ?>" data-testid="security-status"><?= e($statusText) ?></span>
</div>

<section class="stats" data-testid="security-stats">
  <a class="stat stat-due reveal" href="security_logs.php?action=blocked"><span class="stat-label">Blocked (24 h)</span><span class="stat-value"><?= number_format((int) $st['b']) ?></span><span class="stat-hint">clearly malicious requests</span></a>
  <a class="stat stat-awaiting reveal" href="security_logs.php?action=quarantined"><span class="stat-label">Quarantined (24 h)</span><span class="stat-value"><?= number_format((int) $st['q']) ?></span><span class="stat-hint">held for review</span></a>
  <a class="stat stat-new reveal" href="security_quarantine.php"><span class="stat-label">Waiting for review</span><span class="stat-value"><?= number_format($pending) ?></span><span class="stat-hint">pending quarantine items</span></a>
  <a class="stat stat-today reveal" href="security_logs.php?action=flagged"><span class="stat-label">Flagged, delivered (24 h)</span><span class="stat-value"><?= number_format((int) $st['f']) ?></span><span class="stat-hint">suspicious but saved (quarantine off)</span></a>
</section>

<div class="lead-layout">
  <form method="post" action="security.php" class="panel stack" data-testid="security-form" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="action" value="save">

    <div class="panel-head"><h2>Security engine</h2></div>
    <label class="check"><input type="checkbox" name="engine" value="1" <?= $engineOn ? 'checked' : '' ?> data-testid="sec-engine"> <strong>Security Engine</strong> <span class="muted small">— master switch for all checks below</span></label>
    <label class="check"><input type="checkbox" name="monitor" value="1" <?= $monitor ? 'checked' : '' ?> data-testid="sec-monitor"> Monitor only <span class="muted small">— log what would be blocked / quarantined, but let everything through (good for the first days)</span></label>

    <div class="panel-head"><h2>Protection modules</h2></div>
    <?php foreach (LH_SEC_MODULES as $m => $label): ?>
      <div>
        <label class="check"><input type="checkbox" name="<?= e($m) ?>" value="1" <?= (int) $g[$m] ? 'checked' : '' ?> data-testid="sec-<?= e($m) ?>"> <strong><?= e($label) ?></strong></label>
        <p class="small muted" style="margin:.25rem 0 0 2.1rem"><?= e($module_help[$m]) ?></p>
      </div>
    <?php endforeach; ?>

    <div class="panel-head"><h2>Thresholds</h2></div>
    <div class="settings-grid">
      <label>Rate limit: max submissions per IP<input name="rl_max" type="number" min="1" max="10000" value="<?= (int) $g['rl_max'] ?>" data-testid="sec-rl-max"></label>
      <label>…per window (minutes)<input name="rl_window_min" type="number" min="1" max="1440" value="<?= (int) round($g['rl_window'] / 60) ?>" data-testid="sec-rl-window"></label>
      <label class="span-2 muted small" style="font-weight:400">Counted per IP per website; the same IP across all websites is capped at 3× this number.</label>
      <label>Bot sensitivity<select name="bot_threshold" data-testid="sec-bot-threshold">
        <?php foreach ([80 => 'Relaxed (80)', 60 => 'Balanced (60) — recommended', 40 => 'Strict (40)'] as $v => $t): ?>
          <option value="<?= $v ?>" <?= (int) $g['bot_threshold'] === $v ? 'selected' : '' ?>><?= e($t) ?></option>
        <?php endforeach; ?>
        <?php if (!in_array((int) $g['bot_threshold'], [80, 60, 40], true)): ?><option value="<?= (int) $g['bot_threshold'] ?>" selected>Custom (<?= (int) $g['bot_threshold'] ?>)</option><?php endif; ?>
      </select></label>
      <label>Spam blast: same message from this many sources<input name="dup_body_sources" type="number" min="2" max="50" value="<?= (int) $g['dup_body_sources'] ?>" data-testid="sec-dup-sources"></label>
      <label>…within (hours)<input name="dup_window_h" type="number" min="1" max="720" value="<?= (int) round($g['dup_window'] / 3600) ?>" data-testid="sec-dup-window"></label>
      <label>Flood: different messages from one email/phone per hour<input name="dup_contact_max" type="number" min="2" max="100" value="<?= (int) $g['dup_contact_max'] ?>" data-testid="sec-dup-contact"></label>
    </div>

    <div class="panel-head"><h2>Allow-list &amp; tuning</h2></div>
    <label>Trusted IPs <span class="muted small">— never inspected. One per line, CIDR allowed (your office, your own testing)</span>
      <textarea name="trusted_ips" rows="3" class="mono" placeholder="203.0.113.7&#10;198.51.100.0/24" data-testid="sec-trusted-ips"><?= e($g['trusted_ips']) ?></textarea></label>
    <label>Custom spam keywords <span class="muted small">— one per line; a match quarantines the submission</span>
      <textarea name="spam_keywords" rows="3" placeholder="guaranteed ranking&#10;work from home" data-testid="sec-spam-keywords"><?= e($g['spam_keywords']) ?></textarea></label>
    <div class="settings-grid">
      <label>Visitor IP source<select name="ip_header" data-testid="sec-ip-header">
        <?php foreach (['' => 'Automatic (server connection)', 'cf-connecting-ip' => 'CF-Connecting-IP (Cloudflare)', 'x-real-ip' => 'X-Real-IP', 'x-forwarded-for' => 'X-Forwarded-For (last entry)'] as $v => $t): ?>
          <option value="<?= e($v) ?>" <?= $g['ip_header'] === $v ? 'selected' : '' ?>><?= e($t) ?></option>
        <?php endforeach; ?>
      </select></label>
      <label>Keep logs &amp; reviewed items (days)<input name="retention_days" type="number" min="1" max="3650" value="<?= (int) $g['retention_days'] ?>" data-testid="sec-retention"></label>
    </div>
    <div class="row-gap"><button class="btn btn-primary" data-testid="sec-save">Save security settings</button></div>
  </form>

  <aside class="stack-lg">
    <section class="panel">
      <div class="panel-head"><h2>How decisions are made</h2></div>
      <p class="small"><strong>Blocked</strong> — clearly malicious: injection payloads, scanner tools, honeypot, rate abuse. Nothing is saved.</p>
      <p class="small"><strong>Quarantined</strong> — suspicious: bot score, spam wording, link/markup spam, message blasts. Saved for review, <em>not</em> as a lead. The visitor sees the normal thank-you.</p>
      <p class="small"><strong>Allowed</strong> — everything else goes to the CRM exactly as before: same response, same lead flow.</p>
      <p class="small muted">If the engine ever hits an unexpected error it lets the request through, so a genuine lead is never lost because of it.</p>
    </section>
    <section class="panel">
      <div class="panel-head"><h2>Shortcuts</h2></div>
      <div class="stack">
        <a class="btn" href="security_quarantine.php" data-testid="goto-quarantine">Review quarantine<?= $pending ? ' (' . $pending . ')' : '' ?></a>
        <a class="btn" href="security_logs.php" data-testid="goto-logs">View security logs</a>
      </div>
      <p class="small muted" style="margin-top:.75rem">Testing with <code>curl</code>? Add your IP to Trusted IPs, or turn on Monitor only.</p>
    </section>
  </aside>
</div>

<section class="panel panel-flush" data-testid="site-rules">
  <div class="panel-head" style="padding:1.25rem 1.5rem 0"><h2>Per-website overrides</h2><span class="muted small">Global rules apply unless a website overrides them</span></div>
  <div class="table-wrap"><table class="table table-cards">
    <thead><tr><th>Website</th><th>Overrides</th><th class="num">Blocked 7 d</th><th class="num">Quarantined 7 d</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($sites as $w): $o = $overrides[(int) $w['id']] ?? []; $sum = lh_sec_override_summary($o); $ps = $perSite[(int) $w['id']] ?? ['b' => 0, 'q' => 0]; ?>
      <tr data-testid="site-rule-row-<?= (int) $w['id'] ?>">
        <td data-label="Website"><strong><?= e($w['name']) ?></strong><div class="muted small"><?= e($w['domain']) ?></div></td>
        <td data-label="Overrides" class="small"><?php if ($sum): foreach ($sum as $s): ?><span class="badge status-contacted"><?= e($s) ?></span> <?php endforeach; else: ?><span class="muted">Uses global rules</span><?php endif; ?></td>
        <td class="num" data-label="Blocked 7 d"><?= (int) $ps['b'] ?></td>
        <td class="num" data-label="Quarantined 7 d"><?= (int) $ps['q'] ?></td>
        <td class="actions" data-label=""><a class="btn btn-sm" href="security_site.php?id=<?= (int) $w['id'] ?>" data-testid="site-rule-edit-<?= (int) $w['id'] ?>">Customize</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php
page_footer();
