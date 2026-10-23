<?php
declare(strict_types=1);

// Handles delete / change-status for one or many leads (single-lead buttons post here too).
require dirname(__DIR__) . '/_bootstrap.php';
admin_init();
$user = require_login();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect('leads.php');
}
require_csrf();

$return = post_str('return', 600);
if (!preg_match('/^leads\.php(\?[A-Za-z0-9_=&%.\-+:]*)?$/', $return)) {
    $return = 'leads.php';
}

$action = post_str('action', 20);
$ids = [];
foreach (is_array($_POST['ids'] ?? null) ? $_POST['ids'] : [] as $id) {
    $id = is_string($id) ? strtoupper(trim($id)) : '';
    if (preg_match('/^LH-\d{6}-[A-Z0-9]{8}$/', $id)) {
        $ids[$id] = $id;
    }
}
$ids = array_slice(array_values($ids), 0, 200);

if (!$ids) {
    flash('error', 'Select at least one lead first.');
    redirect($return);
}

$in = implode(',', array_fill(0, count($ids), '?'));
$leads = db_all("SELECT id, public_id, status FROM leads WHERE public_id IN ($in)", $ids);
if (!$leads) {
    flash('error', 'The selected leads no longer exist.');
    redirect($return);
}

if ($action === 'delete') {
    if (!is_admin()) {
        flash('error', 'Only administrators can delete leads.');
        redirect($return);
    }
    $dbIds = array_map(static fn($l) => (int) $l['id'], $leads);
    $in2 = implode(',', array_fill(0, count($dbIds), '?'));
    $pdo = db();
    $pdo->beginTransaction();
    db_exec("DELETE FROM lead_notes WHERE lead_id IN ($in2)", $dbIds);
    db_exec("DELETE FROM leads WHERE id IN ($in2)", $dbIds);
    $pdo->commit();
    foreach ($leads as $l) {
        log_event('info', 'lead.deleted', ['lead_id' => $l['public_id'], 'user_id' => (int) $user['id']]);
    }
    flash('success', count($leads) === 1 ? 'Lead ' . $leads[0]['public_id'] . ' deleted.' : count($leads) . ' leads deleted.');
    // A deleted lead's own page no longer exists, so single deletes always go back to the inbox.
    redirect(count($ids) === 1 ? 'leads.php' : $return);
}

if ($action === 'status') {
    $new = post_str('status', 20);
    if (!isset(LH_STATUSES[$new])) {
        flash('error', 'Choose a valid status.');
        redirect($return);
    }
    $now = utc_now();
    $pdo = db();
    $pdo->beginTransaction();
    $changed = 0;
    foreach ($leads as $l) {
        if ($l['status'] === $new) continue;
        db_exec('UPDATE leads SET status = ?, updated_at = ? WHERE id = ?', [$new, $now, (int) $l['id']]);
        db_exec('INSERT INTO lead_notes (lead_id, user_id, kind, body, created_at) VALUES (?, ?, \'status\', ?, ?)', [
            (int) $l['id'], (int) $user['id'], 'Status changed from ' . LH_STATUSES[$l['status']] . ' to ' . LH_STATUSES[$new], $now,
        ]);
        $changed++;
    }
    $pdo->commit();
    log_event('info', 'lead.bulk_status', ['count' => $changed, 'status' => $new, 'user_id' => (int) $user['id']]);
    flash('success', $changed . ' lead' . ($changed === 1 ? '' : 's') . ' marked ' . LH_STATUSES[$new] . '.');
    redirect($return);
}

flash('error', 'Unknown action.');
redirect($return);
