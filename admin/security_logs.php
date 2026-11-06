<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_bootstrap.php';
require_once dirname(__DIR__) . '/_security_admin.php';
admin_init();
lh_rm_restore();
$user = require_admin();
lh_sec_ensure_schema();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_csrf();
    if (post_str('action', 20) === 'purge') {
        $days = max(0, min(3650, (int) post_str('days', 5)));
        $cut = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $n = db_exec('DELETE FROM security_log WHERE created_at < ?', [$cut]);
        log_event('info', 'security.logs_purged', ['days' => $days, 'rows' => $n, 'user_id' => (int) $user['id']]);
        flash('success', 'Deleted ' . $n . ' log entr' . ($n === 1 ? 'y' : 'ies') . ' older than ' . $days . ' day' . ($days === 1 ? '' : 's') . '.');
    }
    redirect('security_logs.php');
}

$f = [
    'action' => in_array(get_str('action'), ['blocked', 'quarantined', 'flagged'], true) ? get_str('action') : '',
    'rule' => isset(LH_SEC_RULE_LABELS[get_str('rule')]) ? get_str('rule') : '',
    'website' => (int) get_str('website'),
    'ip' => get_str('ip', 45),
    'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', get_str('from')) ? get_str('from') : '',
    'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', get_str('to')) ? get_str('to') : '',
];
$where = [];
$p = [];
if ($f['action'] !== '') { $where[] = 'l.action = ?'; $p[] = $f['action']; }
if ($f['rule'] !== '') { $where[] = 'FIND_IN_SET(?, l.rule) > 0'; $p[] = $f['rule']; }
if ($f['website']) { $where[] = 'l.website_id = ?'; $p[] = $f['website']; }
if ($f['ip'] !== '') { $where[] = 'l.ip LIKE ?'; $p[] = like_term($f['ip']); }
if ($f['from'] && ($u = local_to_utc($f['from']))) { $where[] = 'l.created_at >= ?'; $p[] = $u; }
if ($f['to'] && ($u = local_to_utc($f['to']))) { $where[] = 'l.created_at < DATE_ADD(?, INTERVAL 1 DAY)'; $p[] = $u; }
$sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$perPage = 30;
$count = (int) db_val("SELECT COUNT(*) FROM security_log l $sqlWhere", $p);
$pages = max(1, (int) ceil($count / $perPage));
$page = min($pages, max(1, (int) get_str('page')));
$offset = ($page - 1) * $perPage;
$rows = db_all(
    "SELECT l.*, w.name AS website FROM security_log l LEFT JOIN websites w ON w.id = l.website_id $sqlWhere ORDER BY l.id DESC LIMIT $perPage OFFSET $offset",
    $p
);
$websites = db_all('SELECT id, name FROM websites ORDER BY name');
$active = (bool) array_filter($f);

page_header('Security logs', 'settings');
?>
<div class="page-head">
  <div><p class="eyebrow">Security</p><h1>Security logs</h1>
    <p class="muted">Every blocked, quarantined or flagged submission: when, from which IP, which website and why. Entries are kept for the retention period set in Security Settings.</p></div>
  <div class="head-actions"><p class="muted" data-testid="logs-count"><?= number_format($count) ?> entr<?= $count === 1 ? 'y' : 'ies' ?></p><a class="btn" href="security.php">Security Settings</a></div>
</div>

<form class="filters panel filters-open" method="get" action="security_logs.php" data-testid="logs-filters">
  <label>Result<select name="action"><option value="">All</option>
    <?php foreach (['blocked' => 'Blocked', 'quarantined' => 'Quarantined', 'flagged' => 'Flagged'] as $k => $t): ?><option value="<?= $k ?>" <?= $f['action'] === $k ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></label>
  <label>Detected by<select name="rule"><option value="">Any</option>
    <?php foreach (LH_SEC_RULE_LABELS as $k => $t): ?><option value="<?= e($k) ?>" <?= $f['rule'] === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></label>
  <label>Website<select name="website"><option value="">All websites</option>
    <?php foreach ($websites as $w): ?><option value="<?= (int) $w['id'] ?>" <?= $f['website'] === (int) $w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?></option><?php endforeach; ?></select></label>
  <label>IP<input name="ip" value="<?= e($f['ip']) ?>" placeholder="203.0.113."></label>
  <label>From<input type="date" name="from" value="<?= e($f['from']) ?>"></label>
  <label>To<input type="date" name="to" value="<?= e($f['to']) ?>"></label>
  <div class="f-actions"><button class="btn btn-primary">Apply</button><?php if ($active): ?><a class="btn" href="security_logs.php">Reset</a><?php endif; ?></div>
</form>

<section class="panel panel-flush">
<?php if (!$rows): ?>
  <div class="empty" data-testid="logs-empty"><h2>No log entries</h2><p class="muted">Nothing has been blocked or quarantined<?= $active ? ' for these filters' : ' yet' ?>.</p></div>
<?php else: ?>
  <div class="table-wrap"><table class="table table-cards" data-testid="logs-table">
    <thead><tr><th>Time</th><th>Result</th><th>Website</th><th>IP</th><th>Detected by</th><th>Reason</th><th class="num">Score</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr data-testid="log-row-<?= (int) $r['id'] ?>">
        <td class="small nowrap" data-label="Time" title="<?= e(fmt_dt($r['created_at'], 'd M Y, H:i:s')) ?>"><?= e(fmt_dt($r['created_at'], 'd M, H:i:s')) ?></td>
        <td data-label="Result"><?= lh_sec_action_badge((string) $r['action'], (bool) (int) $r['enforced']) ?></td>
        <td data-label="Website"><?= $r['website'] ? e($r['website']) : '<span class="muted">unknown key</span>' ?></td>
        <td class="mono small" data-label="IP"><?= e($r['ip']) ?></td>
        <td data-label="Detected by"><?= lh_sec_rule_badges((string) $r['rule']) ?></td>
        <td class="small cell" data-label="Reason"><?= e($r['reason']) ?>
          <?php if ($r['sample']): ?><div class="muted mono" title="Offending text (shown as plain text)"><?= e(lh_sec_clip((string) $r['sample'], 90)) ?></div><?php endif; ?>
          <?php if ($r['user_agent']): ?><div class="muted small">UA: <?= e(lh_sec_clip((string) $r['user_agent'], 80)) ?></div><?php endif; ?></td>
        <td class="num" data-label="Score"><?= (int) $r['score'] ?></td>
        <td class="actions-col" data-label=""><?php if ($r['quarantine_id']): ?><a class="btn btn-sm" href="security_quarantine.php?id=<?= (int) $r['quarantine_id'] ?>">Review</a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?= pagination($page, $pages) ?>
<?php endif; ?>
</section>

<section class="panel">
  <div class="panel-head"><h2>Clean up</h2></div>
  <form method="post" action="security_logs.php" class="row-gap" data-confirm="Permanently delete the old log entries?">
    <?= csrf_field() ?><input type="hidden" name="action" value="purge">
    <label class="check" style="font-weight:400">Delete entries older than <input name="days" type="number" min="0" max="3650" value="30" style="width:6rem"> days</label>
    <button class="btn btn-danger-ghost" data-testid="logs-purge">Delete</button>
  </form>
</section>
<?php
page_footer();
