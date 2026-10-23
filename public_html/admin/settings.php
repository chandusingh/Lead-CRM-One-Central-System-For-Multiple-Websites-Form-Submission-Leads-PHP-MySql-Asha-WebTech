<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_bootstrap.php';
admin_init();
$user = require_admin();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_csrf();
    $action = post_str('action', 20);
    if ($action === 'save') {
        $errors = [];
        $host = post_str('smtp_host', 190);
        $port = (int) post_str('smtp_port', 6);
        $enc = post_str('smtp_encryption', 5);
        $fromEmail = strtolower(post_str('smtp_from_email', 190));
        $recips = post_str('notify_recipients', 1000);
        if ($host !== '' && !preg_match('/^[A-Za-z0-9.\-]+$/', $host)) $errors[] = 'SMTP host is invalid.';
        if ($port < 1 || $port > 65535) $errors[] = 'SMTP port must be between 1 and 65535.';
        if (!in_array($enc, ['ssl', 'tls', 'none'], true)) $errors[] = 'Choose an encryption type.';
        if ($fromEmail !== '' && !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'From email is invalid.';
        $bad = array_filter(preg_split('/[\s,;]+/', $recips) ?: [], static fn($r) => $r !== '' && !filter_var($r, FILTER_VALIDATE_EMAIL));
        if ($bad) $errors[] = 'Invalid recipient email(s): ' . implode(', ', $bad);
        if (isset($_POST['notify_enabled']) && ($host === '' || $fromEmail === '' || trim($recips) === '')) $errors[] = 'To enable notifications, fill SMTP host, From email and at least one recipient.';
        if ($errors) {
            foreach ($errors as $err) flash('error', $err);
        } else {
            setting_set('smtp_host', $host);
            setting_set('smtp_port', (string) $port);
            setting_set('smtp_encryption', $enc);
            setting_set('smtp_username', post_str('smtp_username', 190));
            setting_set('smtp_from_email', $fromEmail);
            setting_set('smtp_from_name', post_str('smtp_from_name', 120));
            setting_set('notify_recipients', implode(', ', array_filter(preg_split('/[\s,;]+/', strtolower($recips)) ?: [])));
            setting_set('notify_enabled', isset($_POST['notify_enabled']) ? '1' : '0');
            $pw = is_string($_POST['smtp_password'] ?? null) ? $_POST['smtp_password'] : '';
            if ($pw !== '') setting_set('smtp_password', $pw);
            if (isset($_POST['clear_password'])) setting_set('smtp_password', '');
            log_event('info', 'settings.smtp_updated', ['user_id' => (int) $user['id']]);
            flash('success', 'Email settings saved.');
        }
    } elseif ($action === 'test') {
        $to = strtolower(post_str('test_to', 190));
        $to = $to !== '' ? [$to] : notify_recipients();
        try {
            smtp_send(smtp_config(), $to, 'Test email from ' . config('app_name'),
                '<p style="font-family:Arial,sans-serif">SMTP is working. New leads will be emailed from <strong>' . e(config('app_name')) . '</strong>.</p>',
                'SMTP is working. New leads will be emailed from ' . config('app_name') . '.');
            log_event('info', 'settings.smtp_test_ok', ['user_id' => (int) $user['id']]);
            flash('success', 'Test email sent to ' . implode(', ', $to) . '.');
        } catch (Throwable $e) {
            log_event('warning', 'settings.smtp_test_failed', ['error' => $e->getMessage()]);
            flash('error', 'Test failed: ' . $e->getMessage());
        }
    }
    redirect('settings.php');
}

$c = smtp_config();
$enabled = setting('notify_enabled') === '1';
$hasPw = setting('smtp_password') !== '';
$recips = setting('notify_recipients');
$lastFail = db_one('SELECT public_id, notify_error FROM leads WHERE notify_error IS NOT NULL ORDER BY id DESC LIMIT 1');
$sent = (int) db_val('SELECT COUNT(*) FROM leads WHERE notified_at IS NOT NULL');

page_header('Email & SMTP', 'settings');
?>
<div class="page-head">
  <div><p class="eyebrow">Administration</p><h1>Email &amp; SMTP</h1>
    <p class="muted">Every new lead is emailed to the recipients below as soon as it is saved.</p></div>
  <span class="status-dot <?= $enabled ? 'ok' : 'off' ?>" data-testid="notify-status"><?= $enabled ? 'Notifications on' : 'Notifications off' ?></span>
</div>

<div class="lead-layout">
  <form method="post" action="settings.php" class="panel stack" data-testid="smtp-form" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="action" value="save">
    <div class="panel-head"><h2>SMTP server</h2></div>
    <div class="settings-grid">
      <label class="span-2">SMTP host<input name="smtp_host" value="<?= e($c['host']) ?>" placeholder="smtp.hostinger.com" data-testid="smtp-host-input"></label>
      <label>Port<input name="smtp_port" type="number" min="1" max="65535" value="<?= e((string) $c['port']) ?>" data-testid="smtp-port-input"></label>
      <label>Encryption<select name="smtp_encryption" data-testid="smtp-encryption-select">
        <?php foreach (['ssl' => 'SSL (port 465)', 'tls' => 'STARTTLS (port 587)', 'none' => 'None'] as $k => $v): ?>
          <option value="<?= $k ?>" <?= $c['encryption'] === $k ? 'selected' : '' ?>><?= $v ?></option>
        <?php endforeach; ?></select></label>
      <label>Username<input name="smtp_username" value="<?= e($c['username']) ?>" autocomplete="off" data-testid="smtp-username-input"></label>
      <label>Password<input name="smtp_password" type="password" autocomplete="new-password" placeholder="<?= $hasPw ? '•••••••• (saved — leave blank to keep)' : 'Not set' ?>" data-testid="smtp-password-input"></label>
      <label>From email<input name="smtp_from_email" type="email" value="<?= e($c['from_email']) ?>" placeholder="info@yourdomain.com" data-testid="smtp-from-email-input"></label>
      <label>From name<input name="smtp_from_name" value="<?= e($c['from_name']) ?>" data-testid="smtp-from-name-input"></label>
    </div>
    <div class="panel-head"><h2>Lead notifications</h2></div>
    <label>Send new leads to <span class="muted small">— comma separated</span><input name="notify_recipients" value="<?= e($recips) ?>" placeholder="you@example.com, sales@example.com" data-testid="notify-recipients-input"></label>
    <label class="check"><input type="checkbox" name="notify_enabled" value="1" <?= $enabled ? 'checked' : '' ?> data-testid="notify-enabled-checkbox"> Email me every new lead</label>
    <?php if ($hasPw): ?><label class="check"><input type="checkbox" name="clear_password" value="1"> Remove saved SMTP password</label><?php endif; ?>
    <div class="row-gap"><button class="btn btn-primary" data-testid="smtp-save-button">Save settings</button></div>
  </form>

  <aside class="stack-lg">
    <form method="post" action="settings.php" class="panel stack" data-testid="smtp-test-form">
      <?= csrf_field() ?><input type="hidden" name="action" value="test">
      <div class="panel-head"><h2>Send a test email</h2></div>
      <label>Send to<input name="test_to" type="email" placeholder="<?= e(notify_recipients()[0] ?? 'you@example.com') ?>" data-testid="smtp-test-to-input"></label>
      <button class="btn" data-testid="smtp-test-button">Send test email</button>
      <p class="small muted">Uses the saved settings. Save first if you changed anything.</p>
    </form>
    <section class="panel">
      <div class="panel-head"><h2>Delivery</h2></div>
      <p class="small"><strong data-testid="notify-sent-count"><?= $sent ?></strong> lead email(s) sent.</p>
      <?php if ($lastFail): ?><p class="small due">Last failure (<a href="lead.php?id=<?= e(urlencode($lastFail['public_id'])) ?>"><?= e($lastFail['public_id']) ?></a>): <?= e($lastFail['notify_error']) ?></p><?php endif; ?>
      <p class="small muted">Emails are sent after the lead is safely stored and the website has received its response, so a slow mail server never delays or blocks lead capture. The SMTP password is encrypted in the database.</p>
    </section>
  </aside>
</div>
<?php
page_footer();
