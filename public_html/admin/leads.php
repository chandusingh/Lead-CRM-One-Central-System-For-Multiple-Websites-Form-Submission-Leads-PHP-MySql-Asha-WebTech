<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_bootstrap.php';
admin_init();
require_login();

$f = [
    'q' => get_str('q', 200),
    'website' => (int) get_str('website'),
    'channel' => isset(LH_CHANNELS[get_str('channel')]) ? get_str('channel') : '',
    'status' => isset(LH_STATUSES[get_str('status')]) ? get_str('status') : '',
    'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', get_str('from')) ? get_str('from') : '',
    'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', get_str('to')) ? get_str('to') : '',
    'followup' => in_array(get_str('followup'), ['due', 'scheduled', 'none'], true) ? get_str('followup') : '',
];

$where = [];
$p = [];
if ($f['q'] !== '') {
    if (preg_match('/^LH-\d{6}-[A-Z0-9]{8}$/i', $f['q'])) {
        $where[] = 'l.public_id = ?';
        $p[] = strtoupper($f['q']);
    } else {
        $t = like_term($f['q']);
        $where[] = '(l.name LIKE ? OR l.email LIKE ? OR l.phone LIKE ? OR l.public_id LIKE ? OR w.name LIKE ? OR w.domain LIKE ? OR CAST(l.payload AS CHAR) LIKE ?)';
        array_push($p, $t, $t, $t, $t, $t, $t, $t);
    }
}
if ($f['website']) { $where[] = 'l.website_id = ?'; $p[] = $f['website']; }
if ($f['channel']) { $where[] = 'l.channel = ?'; $p[] = $f['channel']; }
if ($f['status']) { $where[] = 'l.status = ?'; $p[] = $f['status']; }
if ($f['from'] && ($u = local_to_utc($f['from']))) { $where[] = 'l.created_at >= ?'; $p[] = $u; }
if ($f['to'] && ($u = local_to_utc($f['to']))) { $where[] = 'l.created_at < DATE_ADD(?, INTERVAL 1 DAY)'; $p[] = $u; }
if ($f['followup'] === 'due') { $where[] = "l.follow_up_at IS NOT NULL AND l.follow_up_at <= ? AND l.status NOT IN ('converted','lost')"; $p[] = utc_now(); }
if ($f['followup'] === 'scheduled') { $where[] = 'l.follow_up_at IS NOT NULL'; }
if ($f['followup'] === 'none') { $where[] = 'l.follow_up_at IS NULL'; }
$sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$perPage = 25;
$count = (int) db_val("SELECT COUNT(*) FROM leads l JOIN websites w ON w.id = l.website_id $sqlWhere", $p);
$pages = max(1, (int) ceil($count / $perPage));
$page = min($pages, max(1, (int) get_str('page')));
$offset = ($page - 1) * $perPage;
$rows = db_all(
    "SELECT l.public_id, l.name, l.email, l.phone, l.channel, l.status, l.follow_up_at, l.created_at, l.duplicate_count, l.payload, w.name AS website
     FROM leads l JOIN websites w ON w.id = l.website_id $sqlWhere ORDER BY l.created_at DESC, l.id DESC LIMIT $perPage OFFSET $offset",
    $p
);
$websites = db_all('SELECT id, name FROM websites ORDER BY name');

// ---- Dynamic columns: forms differ per website, so nothing is hardcoded. ----
function lx_label(string $k): string { return ucfirst(trim(preg_replace('/[_\-.]+/', ' ', $k))); }
function lx_cell($v): string {
    if ($v === null) return '';
    if (is_bool($v)) return $v ? 'Yes' : 'No';
    if (is_array($v)) {
        $flat = array_filter($v, 'is_scalar');
        $v = count($flat) === count($v) ? implode(', ', array_map('strval', $v)) : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $v = trim((string) $v);
    return mb_strlen($v) > 70 ? mb_substr($v, 0, 70) . '…' : $v;
}
function lx_fields(?string $payload): array {
    $d = json_decode((string) $payload, true);
    if (!is_array($d)) return [];
    if (isset($d['fields']) && is_array($d['fields']) && count($d) === 1) $d = $d['fields'];
    foreach ($d as $k => $_) if (strpos((string) $k, '_') === 0) unset($d[$k]);
    return $d;
}
$dynCols = [];
if ($f['website']) {
    $freq = [];
    foreach (db_all('SELECT payload FROM leads WHERE website_id = ? ORDER BY id DESC LIMIT 200', [$f['website']]) as $pr) {
        foreach (array_keys(lx_fields($pr['payload'])) as $k) $freq[(string) $k] = ($freq[(string) $k] ?? 0) + 1;
    }
    arsort($freq);
    $dynCols = array_slice(array_keys($freq), 0, 8);
}
$active = array_filter($f) ? true : false;
$nowUtc = utc_now();

page_header('Leads', 'leads');
?>
<div class="page-head">
  <div><p class="eyebrow">Central inbox</p><h1>Leads</h1></div>
  <div class="head-actions">
    <p class="muted" data-testid="leads-count"><?= number_format($count) ?> lead<?= $count === 1 ? '' : 's' ?><?= $active ? ' match your filters' : '' ?></p>
    <a class="btn btn-primary" href="lead_edit.php<?= $f['website'] ? '?website=' . (int) $f['website'] : '' ?>" data-testid="add-lead-button">+ Add lead</a>
  </div>
</div>

<nav class="site-tabs" data-testid="website-tabs">
  <a href="leads.php" class="<?= $f['website'] ? '' : 'on' ?>">All websites</a>
  <?php foreach ($websites as $w): ?><a href="leads.php?website=<?= (int) $w['id'] ?>" class="<?= $f['website'] === (int) $w['id'] ? 'on' : '' ?>"><?= e($w['name']) ?></a><?php endforeach; ?>
</nav>

<button type="button" class="btn filters-toggle" data-toggle-class="filters-open" data-target=".filters" aria-expanded="false">Search &amp; filters<?= $active ? ' • active' : '' ?></button>
<form class="filters panel<?= $active ? ' filters-open' : '' ?>" method="get" action="leads.php" data-testid="leads-filter-form">
  <label class="f-q">Search<input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Search any field value, lead ID, website…" data-testid="leads-search-input"></label>
  <label>Website<select name="website" data-testid="filter-website">
    <option value="">All websites</option>
    <?php foreach ($websites as $w): ?><option value="<?= (int) $w['id'] ?>" <?= $f['website'] === (int) $w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?></option><?php endforeach; ?>
  </select></label>
  <label>Channel<select name="channel" data-testid="filter-channel">
    <option value="">All</option>
    <?php foreach (LH_CHANNELS as $k => $v): ?><option value="<?= e($k) ?>" <?= $f['channel'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
  </select></label>
  <label>Status<select name="status" data-testid="filter-status">
    <option value="">All</option>
    <?php foreach (LH_STATUSES as $k => $v): ?><option value="<?= e($k) ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
  </select></label>
  <label>From<input type="date" name="from" value="<?= e($f['from']) ?>" data-testid="filter-date-from"></label>
  <label>To<input type="date" name="to" value="<?= e($f['to']) ?>" data-testid="filter-date-to"></label>
  <label>Follow-up<select name="followup" data-testid="filter-followup">
    <option value="">Any</option>
    <option value="due" <?= $f['followup'] === 'due' ? 'selected' : '' ?>>Due now</option>
    <option value="scheduled" <?= $f['followup'] === 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
    <option value="none" <?= $f['followup'] === 'none' ? 'selected' : '' ?>>Not set</option>
  </select></label>
  <div class="f-actions">
    <button class="btn btn-primary" data-testid="filter-apply-button">Apply</button>
    <?php if ($active): ?><a class="btn" href="leads.php" data-testid="filter-reset-button">Reset</a><?php endif; ?>
  </div>
</form>

<section class="panel panel-flush">
<?php if (!$rows): ?>
  <div class="empty" data-testid="leads-empty"><h2>No leads found</h2><p class="muted">Try a different search or clear the filters.</p></div>
<?php else: ?>
  <form method="post" action="leads_bulk.php" id="bulk-form" data-testid="bulk-form">
    <?= csrf_field() ?><input type="hidden" name="return" value="<?= e('leads.php' . ($_SERVER['QUERY_STRING'] ?? '' ? '?' . $_SERVER['QUERY_STRING'] : '')) ?>">
    <div class="bulk-bar" id="bulk-bar" hidden data-testid="bulk-bar">
      <strong><span id="bulk-count">0</span> selected</strong>
      <select name="status" aria-label="New status"><?php foreach (LH_STATUSES as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select>
      <button class="btn btn-sm" name="action" value="status" data-testid="bulk-status-button">Set status</button>
      <?php if (is_admin()): ?><button class="btn btn-sm btn-danger" name="action" value="delete" data-confirm-click="Delete the selected leads permanently? This cannot be undone." data-testid="bulk-delete-button">Delete selected</button><?php endif; ?>
    </div>
  <div class="table-wrap"><table class="table table-hover table-cards" data-testid="leads-table">
    <thead><tr><th class="sel"><input type="checkbox" id="select-all" aria-label="Select all leads on this page" data-testid="select-all"></th><th>Lead ID</th><th>Received</th><?php if (!$f['website']): ?><th>Website</th><?php endif; ?><th>Channel</th>
      <?php if ($dynCols): foreach ($dynCols as $c): ?><th><?= e(lx_label($c)) ?></th><?php endforeach; else: ?><th>Contact</th><th>Details</th><?php endif; ?>
      <th>Status</th><th>Follow-up</th><th class="actions-col"></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $due = $r['follow_up_at'] && $r['follow_up_at'] <= $nowUtc && !in_array($r['status'], ['converted', 'lost'], true); $pf = lx_fields($r['payload']); ?>
      <tr data-testid="lead-row-<?= e($r['public_id']) ?>" data-href="lead.php?id=<?= e(urlencode($r['public_id'])) ?>">
        <td class="sel" data-label=""><input type="checkbox" name="ids[]" value="<?= e($r['public_id']) ?>" class="row-check" aria-label="Select <?= e($r['public_id']) ?>"></td>
        <td data-label="Lead ID"><a class="mono" href="lead.php?id=<?= e(urlencode($r['public_id'])) ?>" data-testid="lead-link-<?= e($r['public_id']) ?>"><?= e($r['public_id']) ?></a>
          <?php if ((int) $r['duplicate_count'] > 0): ?><span class="badge badge-muted" title="Duplicate submissions merged">+<?= (int) $r['duplicate_count'] ?></span><?php endif; ?></td>
        <td class="small nowrap" data-label="Received" title="<?= e(fmt_dt($r['created_at'])) ?>"><?= e(fmt_dt($r['created_at'], 'd M, H:i')) ?></td>
        <?php if (!$f['website']): ?><td data-label="Website"><?= e($r['website']) ?></td><?php endif; ?>
        <td data-label="Channel"><?= channel_badge($r['channel']) ?></td>
        <?php if ($dynCols): foreach ($dynCols as $c): ?><td class="small cell" data-label="<?= e(lx_label($c)) ?>"><?= e(lx_cell($pf[$c] ?? null)) ?: '<span class="muted">—</span>' ?></td><?php endforeach; else:
            $shown = 0; $chips = []; foreach ($pf as $k => $val) { $t = lx_cell($val); if ($t === '') continue; $chips[] = '<span class="chip"><b>' . e(lx_label((string) $k)) . '</b> ' . e($t) . '</span>'; if (++$shown >= 5) break; } ?>
          <td class="small" data-label="Contact"><strong><?= e($r['name'] ?: '—') ?></strong><div class="muted"><?= e($r['email'] ?: $r['phone'] ?: '') ?></div></td>
          <td class="small chips" data-label="Details"><?= $chips ? implode('', $chips) : '<span class="muted">—</span>' ?></td>
        <?php endif; ?>
        <td data-label="Status"><?= status_badge($r['status']) ?></td>
        <td class="small nowrap <?= $due ? 'due' : 'muted' ?>" data-label="Follow-up"><?= $r['follow_up_at'] ? e(fmt_dt($r['follow_up_at'], 'd M, H:i')) . ($due ? ' · due' : '') : '—' ?></td>
        <td class="actions-col" data-label=""><div class="actions">
          <a class="btn btn-sm" href="lead_edit.php?id=<?= e(urlencode($r['public_id'])) ?>" data-testid="lead-edit-<?= e($r['public_id']) ?>">Edit</a>
          <?php if (is_admin()): ?><button class="btn btn-sm btn-danger-ghost" name="action" value="delete" formaction="leads_bulk.php" data-row-only data-confirm-click="Delete lead <?= e($r['public_id']) ?> permanently? This cannot be undone." data-testid="lead-delete-<?= e($r['public_id']) ?>">Delete</button><?php endif; ?>
        </div></td>
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
