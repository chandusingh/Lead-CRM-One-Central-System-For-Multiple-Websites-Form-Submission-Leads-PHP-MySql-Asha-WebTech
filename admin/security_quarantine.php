<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_bootstrap.php';
require_once dirname(__DIR__) . '/_security_admin.php';
admin_init();
lh_rm_restore();
$user = require_admin();
lh_sec_ensure_schema();

// ---------------------------------------------------------------- actions (POST)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_csrf();
    $action = post_str('action', 20);
    $note = post_str('note', 300);
    $return = post_str('return', 600);
    if (!preg_match('/^security_quarantine\.php(\?[A-Za-z0-9_=&%.\-+:*~]*)?$/', $return)) {
        $return = 'security_quarantine.php';
    }

    if ($action === 'approve' || $action === 'reject') {
        $qid = (int) post_str('id', 20);
        if ($action === 'approve') {
            list($ok, $msg, $leadPid) = lh_sec_approve($qid, (int) $user['id'], $note);
        } else {
            list($ok, $msg) = lh_sec_reject($qid, (int) $user['id'], $note);
            $leadPid = null;
        }
        log_event($ok ? 'info' : 'warning', 'security.quarantine_' . $action, ['id' => $qid, 'ok' => $ok, 'user_id' => (int) $user['id']]);
        flash($ok ? 'success' : 'error', $msg);
        redirect($ok && $leadPid ? 'lead.php?id=' . urlencode((string) $leadPid) : $return);
    }

    if ($action === 'bulk_approve' || $action === 'bulk_reject') {
        $ids = [];
        foreach (is_array($_POST['ids'] ?? null) ? $_POST['ids'] : [] as $v) {
            if (is_string($v) && ctype_digit($v)) {
                $ids[(int) $v] = (int) $v;
            }
        }
        $ids = array_slice(array_values($ids), 0, 100);
        if (!$ids) {
            flash('error', 'Select at least one submission first.');
            redirect($return);
        }
        $done = 0;
        $failed = 0;
        foreach ($ids as $qid) {
            if ($action === 'bulk_approve') {
                list($ok) = lh_sec_approve($qid, (int) $user['id'], $note);
            } else {
                list($ok) = lh_sec_reject($qid, (int) $user['id'], $note);
            }
            $ok ? $done++ : $failed++;
        }
        log_event('info', 'security.quarantine_' . $action, ['count' => $done, 'failed' => $failed, 'user_id' => (int) $user['id']]);
        $verb = $action === 'bulk_approve' ? 'approved (saved as leads)' : 'rejected';
        flash($done ? 'success' : 'error', $done . ' submission' . ($done === 1 ? '' : 's') . ' ' . $verb . ($failed ? ', ' . $failed . ' skipped (already reviewed).' : '.'));
        redirect($return);
    }
    redirect('security_quarantine.php');
}

// ---------------------------------------------------------------- detail view
$detailId = (int) get_str('id');
if ($detailId) {
    $q = db_one('SELECT q.*, w.name AS website_name, w.domain AS website_domain, u.name AS reviewer FROM security_quarantine q JOIN websites w ON w.id = q.website_id LEFT JOIN users u ON u.id = q.reviewed_by WHERE q.id = ?', [$detailId]);
    if (!$q) {
        flash('error', 'Quarantined submission not found.');
        redirect('security_quarantine.php');
    }
    $fields = json_decode((string) $q['payload'], true);
    $fields = is_array($fields) ? $fields : [];
    list($cName, $cEmail, $cPhone) = lh_sec_extract_contact($fields);
    $self = 'security_quarantine.php?id=' . $detailId;
    $pend = $q['status'] === 'pending';
    $leadRow = $q['lead_id'] ? db_one('SELECT public_id FROM leads WHERE id = ?', [(int) $q['lead_id']]) : null;

    page_header('Quarantine ' . $q['public_id'], 'settings');
    ?>
<a class="back-link" href="security_quarantine.php" data-testid="back-to-quarantine">&larr; Quarantine</a>
<div class="page-head lead-head">
  <div>
    <p class="eyebrow mono"><?= e($q['public_id']) ?></p>
    <h1><?= e($cName ?: ($cEmail ?: 'Unnamed submission')) ?></h1>
    <p class="muted"><?= lh_sec_status_badge((string) $q['status']) ?> from <strong><?= e($q['website_name']) ?></strong> · <?= e(fmt_dt($q['created_at'])) ?> · seen <?= (int) $q['hits'] ?>×</p>
  </div>
</div>

<div class="flash flash-info" data-testid="quarantine-reason"><strong>Why it was held (score <?= (int) $q['score'] ?>):</strong> <?= e($q['reason']) ?></div>

<div class="lead-layout">
  <div class="stack-lg">
    <section class="panel" data-testid="quarantine-fields">
      <div class="panel-head"><h2>Submitted fields</h2><span class="muted small">shown as plain text, never executed</span></div>
      <?= render_kv($fields) ?>
    </section>
    <section class="panel">
      <div class="panel-head"><h2>Request details</h2></div>
      <dl class="meta">
        <dt>Website</dt><dd><?= e($q['website_name']) ?> <span class="muted">(<?= e($q['website_domain']) ?>)</span></dd>
        <dt>Detected by</dt><dd><?= lh_sec_rule_badges((string) $q['rule']) ?></dd>
        <dt>Received</dt><dd><?= e(fmt_dt($q['created_at'], 'd M Y, H:i:s')) ?></dd>
        <dt>Last seen</dt><dd><?= e(fmt_dt($q['last_seen_at'], 'd M Y, H:i:s')) ?> (<?= (int) $q['hits'] ?> submission<?= (int) $q['hits'] === 1 ? '' : 's' ?>)</dd>
        <dt>IP address</dt><dd class="mono"><?= e($q['ip'] ?: '—') ?></dd>
        <dt>Source page</dt><dd class="break"><?= $q['source_url'] ? e($q['source_url']) : '—' ?></dd>
        <dt>Origin</dt><dd><?= e($q['origin'] ?: '—') ?></dd>
        <dt>User agent</dt><dd class="small break"><?= e($q['user_agent'] ?: '—') ?></dd>
        <?php if ($q['status'] !== 'pending'): ?>
          <dt>Reviewed</dt><dd><?= e($q['reviewer'] ?: 'Unknown') ?> · <?= e(fmt_dt($q['reviewed_at'])) ?><?= $q['review_note'] ? ' · ' . e($q['review_note']) : '' ?></dd>
        <?php endif; ?>
        <?php if ($leadRow): ?><dt>Saved as lead</dt><dd><a class="mono" href="lead.php?id=<?= e(urlencode($leadRow['public_id'])) ?>"><?= e($leadRow['public_id']) ?></a></dd><?php endif; ?>
      </dl>
    </section>
  </div>
  <aside class="stack-lg">
    <section class="panel">
      <div class="panel-head"><h2>Decision</h2></div>
      <?php if ($pend): ?>
        <form method="post" action="<?= e($self) ?>" class="stack" data-testid="quarantine-decision-form">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= $detailId ?>"><input type="hidden" name="return" value="security_quarantine.php">
          <label>Note <span class="muted small">(optional)</span><input name="note" maxlength="300" placeholder="e.g. genuine customer, called back"></label>
          <button class="btn btn-primary" name="action" value="approve" data-testid="quarantine-approve">Approve — save as lead</button>
          <button class="btn btn-danger-ghost" name="action" value="reject" data-testid="quarantine-reject">Reject — discard</button>
        </form>
        <p class="small muted" style="margin-top:.75rem">Approving creates a normal “New” lead (and sends the usual new-lead email). Rejecting keeps this record for the audit trail only.</p>
      <?php else: ?>
        <p class="small muted">This submission has already been <?= e($q['status']) ?>.</p>
      <?php endif; ?>
    </section>
  </aside>
</div>
    <?php
    page_footer();
    exit;
}

// ---------------------------------------------------------------- list view
$f = [
    'status' => in_array(get_str('status'), ['pending', 'approved', 'rejected', 'all'], true) ? get_str('status') : 'pending',
    'website' => (int) get_str('website'),
    'rule' => isset(LH_SEC_RULE_LABELS[get_str('rule')]) ? get_str('rule') : '',
    'q' => get_str('q', 200),
];
$where = [];
$p = [];
if ($f['status'] !== 'all') {
    $where[] = 'q.status = ?';
    $p[] = $f['status'];
}
if ($f['website']) {
    $where[] = 'q.website_id = ?';
    $p[] = $f['website'];
}
if ($f['rule'] !== '') {
    $where[] = 'FIND_IN_SET(?, q.rule) > 0';
    $p[] = $f['rule'];
}
if ($f['q'] !== '') {
    $t = like_term($f['q']);
    $where[] = '(q.ip LIKE ? OR q.public_id LIKE ? OR q.reason LIKE ? OR q.payload LIKE ?)';
    array_push($p, $t, $t, $t, $t);
}
$sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$perPage = 25;
$count = (int) db_val("SELECT COUNT(*) FROM security_quarantine q $sqlWhere", $p);
$pages = max(1, (int) ceil($count / $perPage));
$page = min($pages, max(1, (int) get_str('page')));
$offset = ($page - 1) * $perPage;
$rows = db_all(
    "SELECT q.id, q.public_id, q.status, q.rule, q.reason, q.score, q.ip, q.hits, q.created_at, q.payload, w.name AS website
     FROM security_quarantine q JOIN websites w ON w.id = q.website_id $sqlWhere ORDER BY q.id DESC LIMIT $perPage OFFSET $offset",
    $p
);
$websites = db_all('SELECT id, name FROM websites ORDER BY name');
$counts = array_column(db_all('SELECT status, COUNT(*) AS c FROM security_quarantine GROUP BY status'), 'c', 'status');
$active = $f['website'] || $f['rule'] !== '' || $f['q'] !== '';
$qs = (string) ($_SERVER['QUERY_STRING'] ?? '');

page_header('Quarantine', 'settings');
?>
<div class="page-head">
  <div><p class="eyebrow">Security</p><h1>Quarantine</h1>
    <p class="muted">Suspicious submissions are held here instead of becoming leads. Approve the genuine ones, reject the rest.</p></div>
  <div class="head-actions"><a class="btn" href="security.php">Security Settings</a><a class="btn" href="security_logs.php">Logs</a></div>
</div>

<nav class="site-tabs" data-testid="quarantine-tabs">
  <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $k => $t): ?>
    <a href="security_quarantine.php?status=<?= $k ?>" class="<?= $f['status'] === $k ? 'on' : '' ?>"><?= e($t) ?><?= $k !== 'all' ? ' (' . (int) ($counts[$k] ?? 0) . ')' : '' ?></a>
  <?php endforeach; ?>
</nav>

<form class="filters filters-q panel filters-open" method="get" action="security_quarantine.php" data-testid="quarantine-filters">
  <input type="hidden" name="status" value="<?= e($f['status']) ?>">
  <label class="f-q">Search<input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="IP, reference, reason or any field value"></label>
  <label>Website<select name="website"><option value="">All websites</option>
    <?php foreach ($websites as $w): ?><option value="<?= (int) $w['id'] ?>" <?= $f['website'] === (int) $w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?></option><?php endforeach; ?></select></label>
  <label>Detected by<select name="rule"><option value="">Any</option>
    <?php foreach (LH_SEC_RULE_LABELS as $k => $t): if ($k === 'engine') continue; ?><option value="<?= e($k) ?>" <?= $f['rule'] === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></label>
  <div class="f-actions"><button class="btn btn-primary">Apply</button><?php if ($active): ?><a class="btn" href="security_quarantine.php?status=<?= e($f['status']) ?>">Reset</a><?php endif; ?></div>
</form>

<section class="panel panel-flush">
<?php if (!$rows): ?>
  <div class="empty" data-testid="quarantine-empty"><h2>Nothing here</h2><p class="muted"><?= $f['status'] === 'pending' ? 'No submissions are waiting for review.' : 'No submissions match these filters.' ?></p></div>
<?php else: ?>
  <form method="post" action="security_quarantine.php" id="bulk-form" data-testid="quarantine-bulk-form">
    <?= csrf_field() ?><input type="hidden" name="return" value="<?= e('security_quarantine.php' . ($qs !== '' ? '?' . $qs : '')) ?>">
    <div class="bulk-bar" id="bulk-bar" hidden>
      <strong><span id="bulk-count">0</span> selected</strong>
      <button class="btn btn-sm btn-primary" name="action" value="bulk_approve" data-confirm-click="Approve the selected submissions and save them as leads?" data-testid="bulk-approve">Approve selected</button>
      <button class="btn btn-sm btn-danger" name="action" value="bulk_reject" data-confirm-click="Reject the selected submissions?" data-testid="bulk-reject">Reject selected</button>
    </div>
    <div class="table-wrap"><table class="table table-hover table-cards" data-testid="quarantine-table">
      <thead><tr><th class="sel"><input type="checkbox" id="select-all" aria-label="Select all"></th><th>Reference</th><th>Received</th><th>Website</th><th>Contact</th><th>Why</th><th class="num">Score</th><th>IP</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $fl = json_decode((string) $r['payload'], true);
          list($cn, $ce, $cp) = lh_sec_extract_contact(is_array($fl) ? $fl : []); ?>
        <tr data-testid="quarantine-row-<?= (int) $r['id'] ?>" data-href="security_quarantine.php?id=<?= (int) $r['id'] ?>">
          <td class="sel" data-label=""><?php if ($r['status'] === 'pending'): ?><input type="checkbox" name="ids[]" value="<?= (int) $r['id'] ?>" class="row-check" aria-label="Select <?= e($r['public_id']) ?>"><?php endif; ?></td>
          <td data-label="Reference"><a class="mono" href="security_quarantine.php?id=<?= (int) $r['id'] ?>"><?= e($r['public_id']) ?></a><?php if ((int) $r['hits'] > 1): ?> <span class="badge badge-muted" title="Submitted several times">×<?= (int) $r['hits'] ?></span><?php endif; ?></td>
          <td class="small nowrap" data-label="Received"><?= e(fmt_dt($r['created_at'], 'd M, H:i')) ?></td>
          <td data-label="Website"><?= e($r['website']) ?></td>
          <td class="small" data-label="Contact"><strong><?= e($cn ?: '—') ?></strong><div class="muted"><?= e($ce ?: ($cp ?: '')) ?></div></td>
          <td class="small cell" data-label="Why"><?= lh_sec_rule_badges((string) $r['rule']) ?><div class="muted"><?= e(lh_sec_clip((string) $r['reason'], 110)) ?></div></td>
          <td class="num" data-label="Score"><?= (int) $r['score'] ?></td>
          <td class="mono small" data-label="IP"><?= e($r['ip']) ?></td>
          <td data-label="Status"><?= lh_sec_status_badge((string) $r['status']) ?></td>
          <td class="actions-col" data-label=""><div class="actions"><a class="btn btn-sm" href="security_quarantine.php?id=<?= (int) $r['id'] ?>">Review</a></div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </form>
  <?= pagination($page, $pages) ?>
<?php endif; ?>
</section>
<?php
page_footer();
