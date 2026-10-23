<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_bootstrap.php';
admin_init();
$user = require_login();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_csrf();
    $current = is_string($_POST['current_password'] ?? null) ? $_POST['current_password'] : '';
    $new = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
    $confirm = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';
    $bucket = 'pwchange:' . $user['id'];
    $hash = (string) db_val('SELECT password_hash FROM users WHERE id = ?', [(int) $user['id']]);

    if (!rate_limit_check($bucket, 5, 900)) {
        flash('error', 'Too many attempts. Please wait 15 minutes.');
    } elseif (!password_verify($current, $hash)) {
        rate_limit($bucket, 5, 900);
        log_event('warning', 'auth.password_change_failed', ['user_id' => (int) $user['id']]);
        flash('error', 'Your current password is incorrect.');
    } elseif (mb_strlen($new) < 10) {
        flash('error', 'New password must be at least 10 characters.');
    } elseif (!hash_equals($new, $confirm)) {
        flash('error', 'New password and confirmation do not match.');
    } elseif (password_verify($new, $hash)) {
        flash('error', 'New password must be different from the current one.');
    } else {
        db_exec('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), utc_now(), (int) $user['id']]);
        rate_limit_clear($bucket);
        session_regenerate_id(true);
        lh_rm_issue(['id' => (int) $user['id']]);
        log_event('info', 'auth.password_changed', ['user_id' => (int) $user['id']]);
        flash('success', 'Password changed successfully.');
    }
    redirect('account.php');
}

page_header('Change password', 'account');
?>
<div class="page-head"><div><p class="eyebrow">Account</p><h1>Change password</h1>
  <p class="muted">Signed in as <strong><?= e($user['email']) ?></strong> (<?= e($user['role']) ?>)</p></div></div>

<form method="post" action="account.php" class="panel stack narrow" data-testid="change-password-form" autocomplete="off">
  <?= csrf_field() ?>
  <label>Current password<input type="password" name="current_password" required autocomplete="current-password" data-testid="current-password-input"></label>
  <label>New password <span class="muted small">— at least 10 characters</span><input type="password" name="new_password" required minlength="10" autocomplete="new-password" data-testid="new-password-input"></label>
  <label>Confirm new password<input type="password" name="confirm_password" required minlength="10" autocomplete="new-password" data-testid="confirm-password-input"></label>
  <div class="row-gap"><button class="btn btn-primary" data-testid="change-password-submit">Update password</button></div>
</form>
<?php
page_footer();
