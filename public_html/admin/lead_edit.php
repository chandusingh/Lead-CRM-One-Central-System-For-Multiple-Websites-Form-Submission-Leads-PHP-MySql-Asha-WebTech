<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_bootstrap.php';
admin_init();
$user = require_login();

/** Unique public lead id in the same format the API uses: LH-YYMMDD-XXXXXXXX */
function le_new_public_id(): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    for ($try = 0; $try < 10; $try++) {
        $suffix = '';
        for ($i = 0; $i < 8; $i++) {
            $suffix .= $alphabet[random_int(0, 35)];
        }
        $id = 'LH-' . gmdate('ymd') . '-' . $suffix;
        if (!db_val('SELECT id FROM leads WHERE public_id = ?', [$id])) {
            return $id;
        }
    }
    throw new RuntimeException('Could not generate a unique lead ID.');
}

$pid = strtoupper(get_str('id', 30));
$lead = $pid !== '' ? db_one('SELECT * FROM leads WHERE public_id = ?', [$pid]) : null;
if ($pid !== '' && !$lead) {
    http_response_code(404);
    page_header('Lead not found', 'leads');
    echo '<section class="panel empty"><h1>Lead not found</h1><p class="muted">No lead with that ID exists.</p><a class="btn" href="leads.php">Back to inbox</a></section>';
    page_footer();
    exit;
}
$isNew = $lead === null;
$websites = db_all('SELECT id, name, domain, is_active FROM websites ORDER BY name');

// ---- Current values (form defaults) ----
$payload = $lead ? (json_decode((string) $lead['payload'], true) ?: []) : [];
if (!is_array($payload)) {
    $payload = [];
}
$v = [
    'website_id' => $lead ? (int) $lead['website_id'] : (int) (get_str('website') ?: ($websites[0]['id'] ?? 0)),
    'channel' => $lead ? $lead['channel'] : 'form',
    'status' => $lead ? $lead['status'] : 'new',
    'name' => $lead ? (string) $lead['name'] : '',
    'email' => $lead ? (string) $lead['email'] : '',
    'phone' => $lead ? (string) $lead['phone'] : '',
    'follow_up' => $lead && $lead['follow_up_at'] ? fmt_dt($lead['follow_up_at'], 'Y-m-d\TH:i') : '',
];
$rows = [];
foreach ($payload as $k => $val) {
    $isScalar = is_scalar($val) || $val === null;
    $rows[] = [
        'k' => (string) $k,
        't' => $isScalar ? 's' : 'j',
        'v' => $isScalar ? (is_bool($val) ? ($val ? 'true' : 'false') : (string) ($val ?? '')) : json_encode($val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
    ];
}
$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_csrf();
    $siteIds = array_map('intval', array_column($websites, 'id'));
    $v['website_id'] = (int) post_str('website_id', 10);
    $v['channel'] = post_str('channel', 20);
    $v['status'] = post_str('status', 20);
    $v['name'] = post_str('name', 190);
    $v['email'] = strtolower(post_str('email', 190));
    $v['phone'] = post_str('phone', 50);
    $v['follow_up'] = post_str('follow_up_at', 25);

    if (!in_array($v['website_id'], $siteIds, true)) $errors[] = 'Choose a website.';
    if (!isset(LH_CHANNELS[$v['channel']])) $errors[] = 'Choose a valid channel.';
    if (!isset(LH_STATUSES[$v['status']])) $errors[] = 'Choose a valid status.';
    if ($v['email'] !== '' && !filter_var($v['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Email address is not valid.';
    $followUtc = null;
    if ($v['follow_up'] !== '') {
        $followUtc = local_to_utc($v['follow_up']);
        if ($followUtc === null) $errors[] = 'Follow-up date/time is not valid.';
    }

    // Submitted fields (dynamic key / value rows)
    $keys = is_array($_POST['f_key'] ?? null) ? array_values($_POST['f_key']) : [];
    $vals = is_array($_POST['f_val'] ?? null) ? array_values($_POST['f_val']) : [];
    $types = is_array($_POST['f_type'] ?? null) ? array_values($_POST['f_type']) : [];
    $rows = [];
    $newPayload = [];
    foreach (array_slice($keys, 0, 80) as $i => $k) {
        $k = is_string($k) ? trim(mb_substr($k, 0, 100)) : '';
        $val = is_string($vals[$i] ?? null) ? mb_substr(str_replace("\r\n", "\n", $vals[$i]), 0, 10000) : '';
        $t = ($types[$i] ?? 's') === 'j' ? 'j' : 's';
        if ($k === '') continue;
        $rows[] = ['k' => $k, 't' => $t, 'v' => $val];
        if ($t === 'j') {
            $dec = json_decode($val, true);
            $newPayload[$k] = json_last_error() === JSON_ERROR_NONE ? $dec : $val;
        } else {
            $newPayload[$k] = $val;
        }
    }
    if ($v['name'] === '' && $v['email'] === '' && $v['phone'] === '' && !array_filter($newPayload, static fn($x) => $x !== '' && $x !== null)) {
        $errors[] = 'Enter at least a name, email, phone or one field value.';
    }

    if (!$errors) {
        // Keep payload copies of name/email/phone in step when the column was edited and the payload key was left untouched.
        if ($lead) {
            foreach (['name', 'email', 'phone'] as $col) {
                $old = (string) $lead[$col];
                if ($old !== '' && $old !== $v[$col]) {
                    foreach ($newPayload as $k => $val) {
                        if (is_string($val) && $val === $old && ($payload[$k] ?? null) === $old) {
                            $newPayload[$k] = $v[$col];
                        }
                    }
                }
            }
        }
        $json = json_encode((object) $newPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $now = utc_now();
        $pdo = db();
        $pdo->beginTransaction();
        $goto = null;
        try {
            if ($isNew) {
                $leadRef = le_new_public_id();
                // notified_at is pre-set so the e-mail notifier never alerts for a lead a team member typed in themselves.
                db_exec(
                    'INSERT INTO leads (public_id, website_id, channel, name, email, phone, payload, status, fingerprint, follow_up_at, origin, notified_at, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$leadRef, $v['website_id'], $v['channel'], $v['name'] ?: null, $v['email'] ?: null, $v['phone'] ?: null, $json, $v['status'],
                     hash('sha256', 'manual|' . $leadRef . '|' . bin2hex(random_bytes(8))), $followUtc, 'CRM (added manually)', $now, $now, $now]
                );
                $leadId = (int) db_val('SELECT id FROM leads WHERE public_id = ?', [$leadRef]);
                db_exec('INSERT INTO lead_notes (lead_id, user_id, kind, body, created_at) VALUES (?, ?, \'note\', ?, ?)', [$leadId, (int) $user['id'], 'Lead added manually', $now]);
                $okMsg = 'Lead ' . $leadRef . ' created.';
                $logEvt = 'lead.created';
            } else {
                $leadRef = (string) $lead['public_id'];
                $leadId = (int) $lead['id'];
                db_exec(
                    'UPDATE leads SET website_id = ?, channel = ?, name = ?, email = ?, phone = ?, payload = ?, status = ?, follow_up_at = ?, updated_at = ? WHERE id = ?',
                    [$v['website_id'], $v['channel'], $v['name'] ?: null, $v['email'] ?: null, $v['phone'] ?: null, $json, $v['status'], $followUtc, $now, $leadId]
                );
                $ins = 'INSERT INTO lead_notes (lead_id, user_id, kind, body, created_at) VALUES (?, ?, ?, ?, ?)';
                if ($v['status'] !== $lead['status']) {
                    db_exec($ins, [$leadId, (int) $user['id'], 'status', 'Status changed from ' . LH_STATUSES[$lead['status']] . ' to ' . LH_STATUSES[$v['status']], $now]);
                }
                if (($followUtc ?: '') !== ($lead['follow_up_at'] ?: '')) {
                    db_exec($ins, [$leadId, (int) $user['id'], 'follow_up', $followUtc ? 'Follow-up set for ' . fmt_dt($followUtc) : 'Follow-up cleared', $now]);
                }
                db_exec($ins, [$leadId, (int) $user['id'], 'note', 'Lead details edited', $now]);
                $okMsg = 'Lead updated.';
                $logEvt = 'lead.updated';
            }
            $pdo->commit();
            log_event('info', $logEvt, ['lead_id' => $leadRef, 'user_id' => (int) $user['id']]);
            flash('success', $okMsg);
            $goto = 'lead.php?id=' . urlencode($leadRef);
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            log_event('error', 'lead.save_failed', ['error' => $ex->getMessage()]);
            $errors[] = 'Could not save the lead. Please try again.';
        }
        if ($goto !== null) {
            redirect($goto);
        }
    }
}

$self = 'lead_edit.php' . ($isNew ? '' : '?id=' . urlencode($lead['public_id']));
page_header($isNew ? 'Add lead' : 'Edit ' . $lead['public_id'], 'leads');
?>
<a class="back-link" href="<?= $isNew ? 'leads.php' : 'lead.php?id=' . e(urlencode($lead['public_id'])) ?>">&larr; <?= $isNew ? 'All leads' : 'Back to lead' ?></a>
<div class="page-head">
  <div>
    <p class="eyebrow mono"><?= $isNew ? 'New lead' : e($lead['public_id']) ?></p>
    <h1><?= $isNew ? 'Add a lead' : 'Edit lead' ?></h1>
    <?php if ($isNew): ?><p class="muted">Enter a lead manually — for example a phone call, walk-in or referral.</p><?php endif; ?>
  </div>
</div>

<?php if ($errors): ?>
  <div class="flash flash-error" role="alert" data-testid="lead-form-errors"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" action="<?= e($self) ?>" class="stack-lg" data-testid="lead-form">
  <?= csrf_field() ?>
  <section class="panel">
    <div class="panel-head"><h2>Contact &amp; pipeline</h2></div>
    <div class="settings-grid">
      <label>Name<input type="text" name="name" value="<?= e($v['name']) ?>" maxlength="190" autocomplete="off" data-testid="lead-name-input"></label>
      <label>Email<input type="email" name="email" value="<?= e($v['email']) ?>" maxlength="190" inputmode="email" autocomplete="off" data-testid="lead-email-input"></label>
      <label>Phone<input type="tel" name="phone" value="<?= e($v['phone']) ?>" maxlength="50" inputmode="tel" autocomplete="off" data-testid="lead-phone-input"></label>
      <label>Website
        <select name="website_id" required data-testid="lead-website-select">
          <?php foreach ($websites as $w): ?><option value="<?= (int) $w['id'] ?>" <?= $v['website_id'] === (int) $w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?><?= (int) $w['is_active'] ? '' : ' (inactive)' ?></option><?php endforeach; ?>
        </select></label>
      <label>Channel
        <select name="channel" data-testid="lead-channel-select">
          <?php foreach (LH_CHANNELS as $k => $label): ?><option value="<?= e($k) ?>" <?= $v['channel'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select></label>
      <label>Status
        <select name="status" data-testid="lead-status-select">
          <?php foreach (LH_STATUSES as $k => $label): ?><option value="<?= e($k) ?>" <?= $v['status'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select></label>
      <label class="span-2">Follow-up <span class="muted small">— optional, <?= e(config('timezone')) ?> time</span>
        <input type="datetime-local" name="follow_up_at" value="<?= e($v['follow_up']) ?>" data-testid="lead-followup-input"></label>
    </div>
  </section>

  <section class="panel">
    <div class="panel-head"><h2>Submitted fields</h2><button type="button" class="btn btn-sm" data-add-field="#field-rows" data-testid="add-field-button">+ Add field</button></div>
    <p class="small muted">These are the form values shown on the lead page and in the inbox columns. Leave a value empty to keep the field blank; clear the field name to remove the row.</p>
    <div id="field-rows" class="field-rows" data-testid="field-rows">
      <?php foreach ($rows as $r): ?>
        <div class="field-row">
          <input type="text" name="f_key[]" value="<?= e($r['k']) ?>" placeholder="Field name" maxlength="100" aria-label="Field name">
          <textarea name="f_val[]" rows="<?= $r['t'] === 'j' ? 4 : 1 ?>" placeholder="Value" aria-label="Field value" class="<?= $r['t'] === 'j' ? 'mono' : '' ?>"><?= e($r['v']) ?></textarea>
          <input type="hidden" name="f_type[]" value="<?= e($r['t']) ?>">
          <button type="button" class="btn btn-sm btn-danger-ghost" data-remove-field aria-label="Remove field">Remove</button>
        </div>
      <?php endforeach; ?>
    </div>
    <template id="field-row-template">
      <div class="field-row">
        <input type="text" name="f_key[]" placeholder="Field name (e.g. message)" maxlength="100" aria-label="Field name">
        <textarea name="f_val[]" rows="1" placeholder="Value" aria-label="Field value"></textarea>
        <input type="hidden" name="f_type[]" value="s">
        <button type="button" class="btn btn-sm btn-danger-ghost" data-remove-field aria-label="Remove field">Remove</button>
      </div>
    </template>
  </section>

  <div class="row-gap form-actions">
    <button class="btn btn-primary" data-testid="lead-save-button"><?= $isNew ? 'Create lead' : 'Save changes' ?></button>
    <a class="btn" href="<?= $isNew ? 'leads.php' : 'lead.php?id=' . e(urlencode($lead['public_id'])) ?>">Cancel</a>
  </div>
</form>

<?php if (!$isNew && is_admin()): ?>
<section class="panel danger-zone" data-testid="lead-danger-zone">
  <div class="panel-head"><h2>Delete lead</h2></div>
  <p class="small">Permanently removes this lead together with its notes and activity. This cannot be undone.</p>
  <form method="post" action="leads_bulk.php" data-confirm="Delete lead <?= e($lead['public_id']) ?> permanently? This cannot be undone.">
    <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="ids[]" value="<?= e($lead['public_id']) ?>">
    <button class="btn btn-danger" data-testid="lead-delete-button">Delete this lead</button>
  </form>
</section>
<?php endif; ?>
<?php
page_footer();
