<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_bootstrap.php';
admin_init();
$user = require_login();

$pid = strtoupper(get_str('id', 30));
$lead = $pid !== '' ? db_one(
    'SELECT l.*, w.name AS website_name, w.domain AS website_domain FROM leads l JOIN websites w ON w.id = l.website_id WHERE l.public_id = ?',
    [$pid]
) : null;
if (!$lead) {
    http_response_code(404);
    page_header('Lead not found', 'leads');
    echo '<section class="panel empty" data-testid="lead-not-found"><h1>Lead not found</h1><p class="muted">No lead with that ID exists.</p><a class="btn" href="leads.php">Back to inbox</a></section>';
    page_footer();
    exit;
}
$leadId = (int) $lead['id'];
$self = 'lead.php?id=' . urlencode($lead['public_id']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_csrf();
    $action = post_str('action', 20);
    $pdo = db();
    if ($action === 'status') {
        $new = post_str('status', 20);
        if (!isset(LH_STATUSES[$new])) {
            flash('error', 'Invalid status.');
        } elseif ($new !== $lead['status']) {
            $pdo->beginTransaction();
            db_exec('UPDATE leads SET status = ?, updated_at = ? WHERE id = ?', [$new, utc_now(), $leadId]);
            db_exec('INSERT INTO lead_notes (lead_id, user_id, kind, body, created_at) VALUES (?, ?, \'status\', ?, ?)', [
                $leadId, (int) $user['id'], 'Status changed from ' . LH_STATUSES[$lead['status']] . ' to ' . LH_STATUSES[$new], utc_now(),
            ]);
            $pdo->commit();
            log_event('info', 'lead.status_changed', ['lead_id' => $lead['public_id'], 'user_id' => (int) $user['id'], 'status' => $new]);
            flash('success', 'Status updated to ' . LH_STATUSES[$new] . '.');
        }
    } elseif ($action === 'followup') {
        $clear = isset($_POST['clear']);
        $local = post_str('follow_up_at', 25);
        $utc = $clear ? null : local_to_utc($local);
        if (!$clear && $utc === null) {
            flash('error', 'Please choose a valid follow-up date and time.');
        } else {
            $pdo->beginTransaction();
            db_exec('UPDATE leads SET follow_up_at = ?, updated_at = ? WHERE id = ?', [$utc, utc_now(), $leadId]);
            db_exec('INSERT INTO lead_notes (lead_id, user_id, kind, body, created_at) VALUES (?, ?, \'follow_up\', ?, ?)', [
                $leadId, (int) $user['id'], $utc ? 'Follow-up set for ' . fmt_dt($utc) : 'Follow-up cleared', utc_now(),
            ]);
            $pdo->commit();
            flash('success', $utc ? 'Follow-up scheduled.' : 'Follow-up cleared.');
        }
    } elseif ($action === 'note') {
        $body = post_str('body', 5000);
        if ($body === '') {
            flash('error', 'Note cannot be empty.');
        } else {
            db_exec('INSERT INTO lead_notes (lead_id, user_id, kind, body, created_at) VALUES (?, ?, \'note\', ?, ?)', [$leadId, (int) $user['id'], $body, utc_now()]);
            db_exec('UPDATE leads SET updated_at = ? WHERE id = ?', [utc_now(), $leadId]);
            flash('success', 'Note added.');
        }
    }
    redirect($self);
}

$fields = json_decode((string) $lead['payload'], true) ?: [];
$raw = json_encode($fields, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$notes = db_all(
    'SELECT n.kind, n.body, n.created_at, u.name AS author FROM lead_notes n LEFT JOIN users u ON u.id = n.user_id WHERE n.lead_id = ? ORDER BY n.id DESC',
    [$leadId]
);
$phoneDigits = $lead['phone'] ? preg_replace('/[^\d+]/', '', $lead['phone']) : '';
$waDigits = ltrim((string) $phoneDigits, '+');
$due = $lead['follow_up_at'] && $lead['follow_up_at'] <= utc_now() && !in_array($lead['status'], ['converted', 'lost'], true);

page_header($lead['public_id'], 'leads');
?>
<a class="back-link" href="leads.php" data-testid="back-to-leads">&larr; All leads</a>
<div class="page-head lead-head">
  <div>
    <p class="eyebrow mono" data-testid="lead-public-id"><?= e($lead['public_id']) ?></p>
    <h1 data-testid="lead-name"><?= e($lead['name'] ?: ($lead['email'] ?: 'Unnamed lead')) ?></h1>
    <p class="muted"><?= channel_badge($lead['channel']) ?> from <strong><?= e($lead['website_name']) ?></strong> · <?= e(fmt_dt($lead['created_at'])) ?> (<?= e(time_ago($lead['created_at'])) ?>)</p>
  </div>
  <div class="contact-actions">
    <?php if ($lead['email']): ?><a class="btn" href="mailto:<?= e($lead['email']) ?>" data-testid="contact-email-link">Email</a><?php endif; ?>
    <?php if ($phoneDigits): ?><a class="btn" href="tel:<?= e($phoneDigits) ?>" data-testid="contact-call-link">Call</a>
      <a class="btn" href="https://wa.me/<?= e($waDigits) ?>" target="_blank" rel="noopener noreferrer" data-testid="contact-whatsapp-link">WhatsApp</a><?php endif; ?>
    <a class="btn btn-primary" href="lead_edit.php?id=<?= e(urlencode($lead['public_id'])) ?>" data-testid="lead-edit-button">Edit lead</a>
    <?php if (is_admin()): ?>
    <form method="post" action="leads_bulk.php" class="inline" data-confirm="Delete lead <?= e($lead['public_id']) ?> permanently? This cannot be undone.">
      <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="ids[]" value="<?= e($lead['public_id']) ?>">
      <button class="btn btn-danger-ghost" data-testid="lead-delete-button">Delete</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($lead['channel'] !== 'form'): ?>
  <div class="flash flash-info" data-testid="channel-verification-note">This lead was stored <strong>before</strong> the visitor was sent to <?= e(LH_CHANNELS[$lead['channel']]) ?>. Lead Hub cannot verify whether the visitor actually sent a message via <?= e(LH_CHANNELS[$lead['channel']]) ?>.</div>
<?php endif; ?>

<div class="lead-layout">
  <div class="stack-lg">
    <section class="panel" data-testid="lead-fields">
      <div class="panel-head"><h2>Submitted fields</h2><span class="muted small"><?= count(lh_leaves($fields)) ?> values</span></div>
      <?= render_kv($fields) ?>
    </section>

    <section class="panel">
      <div class="panel-head"><h2>Raw JSON</h2>
        <div class="row-gap"><button type="button" class="btn btn-sm" data-toggle="#raw-json" data-testid="toggle-raw-json">Show / hide</button>
        <button type="button" class="btn btn-sm" data-copy="#raw-json-pre" data-testid="copy-raw-json">Copy</button></div></div>
      <div id="raw-json" hidden><pre class="code" id="raw-json-pre" data-testid="raw-json"><?= e($raw) ?></pre></div>
    </section>

    <section class="panel" data-testid="lead-meta">
      <div class="panel-head"><h2>Submission details</h2></div>
      <dl class="meta">
        <dt>Website</dt><dd><?= e($lead['website_name']) ?> <span class="muted">(<?= e($lead['website_domain']) ?>)</span></dd>
        <dt>Channel</dt><dd><?= channel_badge($lead['channel']) ?></dd>
        <dt>Received</dt><dd><?= e(fmt_dt($lead['created_at'], 'd M Y, H:i:s')) ?> <span class="muted">(<?= e(config('timezone')) ?>)</span></dd>
        <dt>Source page</dt><dd class="break"><?= $lead['source_url'] ? render_scalar($lead['source_url']) : '—' ?></dd>
        <?php if ($lead['page_title']): ?><dt>Page title</dt><dd><?= e($lead['page_title']) ?></dd><?php endif; ?>
        <?php if ($lead['referrer']): ?><dt>Referrer</dt><dd class="break"><?= render_scalar($lead['referrer']) ?></dd><?php endif; ?>
        <dt>Origin</dt><dd><?= e($lead['origin'] ?: '—') ?></dd>
        <dt>IP address</dt><dd class="mono"><?= e($lead['ip_address'] ?: '—') ?></dd>
        <dt>User agent</dt><dd class="small break"><?= e($lead['user_agent'] ?: '—') ?></dd>
        <dt>Idempotency key</dt><dd class="mono small"><?= e($lead['idempotency_key'] ?: '—') ?></dd>
        <dt>Email notification</dt><dd data-testid="lead-notify-status"><?= $lead['notified_at'] ? 'Sent ' . e(fmt_dt($lead['notified_at'])) : ($lead['notify_error'] ? '<span class="due">Failed: ' . e($lead['notify_error']) . '</span>' : '<span class="muted">Not sent</span>') ?></dd>
        <dt>Duplicates merged</dt><dd data-testid="lead-duplicate-count"><?= (int) $lead['duplicate_count'] ?><?= $lead['last_duplicate_at'] ? ' · last ' . e(fmt_dt($lead['last_duplicate_at'])) : '' ?></dd>
      </dl>
    </section>
  </div>

  <aside class="stack-lg">
    <section class="panel">
      <div class="panel-head"><h2>Status</h2><span data-testid="lead-status-badge"><?= status_badge($lead['status']) ?></span></div>
      <form method="post" action="<?= e($self) ?>" class="status-form" data-testid="status-form">
        <?= csrf_field() ?><input type="hidden" name="action" value="status">
        <div class="seg">
          <?php foreach (LH_STATUSES as $k => $v): ?>
            <button type="submit" name="status" value="<?= e($k) ?>" class="seg-btn status-<?= e($k) ?> <?= $lead['status'] === $k ? 'on' : '' ?>" data-testid="status-option-<?= e($k) ?>"><?= e($v) ?></button>
          <?php endforeach; ?>
        </div>
      </form>
    </section>

    <section class="panel">
      <div class="panel-head"><h2>Follow-up</h2><?php if ($due): ?><span class="badge status-lost">Due</span><?php endif; ?></div>
      <p class="small <?= $due ? 'due' : 'muted' ?>" data-testid="lead-followup-value"><?= $lead['follow_up_at'] ? 'Scheduled for ' . e(fmt_dt($lead['follow_up_at'])) : 'No follow-up scheduled.' ?></p>
      <form method="post" action="<?= e($self) ?>" class="stack" data-testid="followup-form">
        <?= csrf_field() ?><input type="hidden" name="action" value="followup">
        <input type="datetime-local" name="follow_up_at" value="<?= e(fmt_dt($lead['follow_up_at'], 'Y-m-d\TH:i')) ?>" data-testid="followup-input">
        <div class="row-gap">
          <button class="btn btn-primary btn-sm" data-testid="followup-save-button">Save follow-up</button>
          <?php if ($lead['follow_up_at']): ?><button class="btn btn-sm" name="clear" value="1" formnovalidate data-testid="followup-clear-button">Clear</button><?php endif; ?>
        </div>
      </form>
    </section>

    <section class="panel" data-testid="notes-panel">
      <div class="panel-head"><h2>Notes &amp; activity</h2></div>
      <form method="post" action="<?= e($self) ?>" class="stack" data-testid="note-form">
        <?= csrf_field() ?><input type="hidden" name="action" value="note">
        <textarea name="body" rows="3" maxlength="5000" required placeholder="Add an internal note…" data-testid="note-input"></textarea>
        <button class="btn btn-primary btn-sm" data-testid="note-submit-button">Add note</button>
      </form>
      <ol class="timeline" data-testid="notes-list">
        <?php foreach ($notes as $n): ?>
          <li class="tl-<?= e($n['kind']) ?>"><div class="tl-body"><?= nl2br(e($n['body'])) ?></div>
            <div class="muted small"><?= e($n['author'] ?: 'Deleted user') ?> · <?= e(fmt_dt($n['created_at'])) ?></div></li>
        <?php endforeach; ?>
        <li class="tl-created"><div class="tl-body">Lead received via <?= e(LH_CHANNELS[$lead['channel']]) ?></div><div class="muted small"><?= e(fmt_dt($lead['created_at'])) ?></div></li>
      </ol>
    </section>
  </aside>
</div>
<?php
page_footer();
