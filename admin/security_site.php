<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_bootstrap.php';
require_once dirname(__DIR__) . '/_security_admin.php';
admin_init();
lh_rm_restore();
$user = require_admin();
lh_sec_ensure_schema();

$id = (int) get_str('id');
$site = $id ? db_one('SELECT id, name, domain, allowed_origins FROM websites WHERE id = ?', [$id]) : null;
if (!$site) {
    flash('error', 'Website not found.');
    redirect('security.php');
}
$self = 'security_site.php?id=' . $id;
$g = lh_sec_config();
$tri = ['inherit', 'on', 'off'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_csrf();
    $action = post_str('action', 20);
    if ($action === 'reset') {
        db_exec('DELETE FROM security_site_rules WHERE website_id = ?', [$id]);
        log_event('info', 'security.site_rules_reset', ['website_id' => $id, 'user_id' => (int) $user['id']]);
        flash('success', 'This website now follows the global security rules.');
        redirect($self);
    }
    if ($action === 'save') {
        $o = [];
        $engine = post_str('engine', 8);
        if (in_array($engine, ['on', 'off'], true)) {
            $o['engine'] = $engine;
        }
        foreach (array_keys(LH_SEC_MODULES) as $m) {
            $v = post_str('m_' . $m, 8);
            if (in_array($v, ['on', 'off'], true)) {
                $o['modules'][$m] = $v;
            }
        }
        $max = (int) post_str('rl_max', 6);
        $win = (int) post_str('rl_window_min', 6);
        if ($max > 0) {
            $o['rl_max'] = lh_sec_clamp('rl_max', $max);
        }
        if ($win > 0) {
            $o['rl_window'] = lh_sec_clamp('rl_window', $win * 60);
        }
        $bt = (int) post_str('bot_threshold', 4);
        if ($bt > 0) {
            $o['bot_threshold'] = lh_sec_clamp('bot_threshold', $bt);
        }
        $hosts = [];
        $raw = is_string($_POST['extra_hosts'] ?? null) ? (string) $_POST['extra_hosts'] : '';
        $errors = [];
        foreach (preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $h) {
            $host = lh_sec_host($h);
            if ($host === '' || !preg_match('/^[a-z0-9.\-]+$/', $host)) {
                $errors[] = '"' . $h . '" is not a valid domain.';
            } else {
                $hosts[] = $host;
            }
        }
        if ($errors) {
            foreach ($errors as $err) {
                flash('error', $err);
            }
            redirect($self);
        }
        if ($hosts) {
            $o['extra_hosts'] = implode("\n", array_unique($hosts));
        }
        if (!$o) {
            db_exec('DELETE FROM security_site_rules WHERE website_id = ?', [$id]);
        } else {
            db_exec(
                'INSERT INTO security_site_rules (website_id, config, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE config = VALUES(config), updated_at = VALUES(updated_at)',
                [$id, lh_sec_json($o), utc_now()]
            );
        }
        log_event('info', 'security.site_rules_saved', ['website_id' => $id, 'user_id' => (int) $user['id']]);
        flash('success', 'Security rules for ' . $site['name'] . ' saved.');
    }
    redirect($self);
}

$o = lh_sec_site_config($id);
$sel = static function (string $name, string $cur, bool $globalOn): string {
    $h = '<select name="' . e($name) . '" data-testid="' . e($name) . '">';
    foreach (['inherit' => 'Use global (' . ($globalOn ? 'ON' : 'OFF') . ')', 'on' => 'Force ON', 'off' => 'Force OFF'] as $v => $t) {
        $h .= '<option value="' . $v . '"' . ($cur === $v ? ' selected' : '') . '>' . e($t) . '</option>';
    }
    return $h . '</select>';
};

page_header('Security · ' . $site['name'], 'settings');
?>
<a class="back-link" href="security.php">&larr; Security Settings</a>
<div class="page-head">
  <div><p class="eyebrow">Website override</p><h1><?= e($site['name']) ?></h1>
    <p class="muted"><?= e($site['domain']) ?> · Anything left on “Use global” follows the global Security Settings.</p></div>
</div>

<div class="lead-layout">
  <form method="post" action="<?= e($self) ?>" class="panel stack" data-testid="site-security-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="save">
    <div class="panel-head"><h2>Switches</h2></div>
    <div class="settings-grid">
      <label class="span-2">Security Engine for this website<?= $sel('engine', (string) ($o['engine'] ?? 'inherit'), (bool) $g['engine']) ?></label>
      <?php foreach (LH_SEC_MODULES as $m => $label): ?>
        <label><?= e($label) ?><?= $sel('m_' . $m, (string) ($o['modules'][$m] ?? 'inherit'), (bool) $g[$m]) ?></label>
      <?php endforeach; ?>
    </div>

    <div class="panel-head"><h2>Thresholds</h2></div>
    <div class="settings-grid">
      <label>Rate limit: max submissions per IP<input name="rl_max" type="number" min="1" max="10000" value="<?= e((string) ($o['rl_max'] ?? '')) ?>" placeholder="Global: <?= (int) $g['rl_max'] ?>"></label>
      <label>…per window (minutes)<input name="rl_window_min" type="number" min="1" max="1440" value="<?= isset($o['rl_window']) ? (int) round($o['rl_window'] / 60) : '' ?>" placeholder="Global: <?= (int) round($g['rl_window'] / 60) ?>"></label>
      <label class="span-2">Bot sensitivity<select name="bot_threshold">
        <?php foreach (['' => 'Use global (' . (int) $g['bot_threshold'] . ')', '80' => 'Relaxed (80)', '60' => 'Balanced (60)', '40' => 'Strict (40)'] as $v => $t): ?>
          <option value="<?= e((string) $v) ?>" <?= (string) ($o['bot_threshold'] ?? '') === (string) $v ? 'selected' : '' ?>><?= e($t) ?></option>
        <?php endforeach; ?>
      </select></label>
    </div>

    <div class="panel-head"><h2>Additional trusted domains</h2></div>
    <label>Other domains this website's forms legitimately run on <span class="muted small">— one per line (e.g. a staging site or a second domain). Sub-domains and www are already accepted automatically.</span>
      <textarea name="extra_hosts" rows="3" class="mono" placeholder="staging.example.com" data-testid="site-extra-hosts"><?= e((string) ($o['extra_hosts'] ?? '')) ?></textarea></label>
    <div class="row-gap">
      <button class="btn btn-primary" data-testid="site-security-save">Save overrides</button>
      <a class="btn" href="security.php">Cancel</a>
    </div>
  </form>

  <aside class="stack-lg">
    <section class="panel">
      <div class="panel-head"><h2>Current effective rules</h2></div>
      <?php $eff = lh_sec_effective($g, $o); ?>
      <ul class="small" style="list-style:disc;padding-left:1.1rem">
        <li>Engine: <strong><?= $eff['engine'] ? 'ON' : 'OFF' ?></strong><?= $g['monitor'] ? ' (global monitor-only mode is on)' : '' ?></li>
        <?php foreach (LH_SEC_MODULES as $m => $label): ?><li><?= e($label) ?>: <strong><?= $eff[$m] ? 'ON' : 'OFF' ?></strong></li><?php endforeach; ?>
        <li>Rate limit: <?= (int) $eff['rl_max'] ?> per <?= round($eff['rl_window'] / 60, 1) ?> min</li>
        <li>Bot threshold: <?= (int) $eff['bot_threshold'] ?></li>
      </ul>
    </section>
    <?php if ($o): ?>
    <section class="panel danger-zone">
      <div class="panel-head"><h2>Reset</h2></div>
      <p class="small">Remove every override and follow the global rules again.</p>
      <form method="post" action="<?= e($self) ?>" data-confirm="Remove all overrides for this website?">
        <?= csrf_field() ?><input type="hidden" name="action" value="reset"><button class="btn btn-danger" data-testid="site-security-reset">Reset to global</button>
      </form>
    </section>
    <?php endif; ?>
  </aside>
</div>
<?php
page_footer();
