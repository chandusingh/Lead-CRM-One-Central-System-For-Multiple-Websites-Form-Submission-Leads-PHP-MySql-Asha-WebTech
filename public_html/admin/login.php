<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_bootstrap.php';
admin_init();
if (!current_user() && ($rmUser = lh_rm_user())) {
    // Session was lost but the persistent login cookie is valid: sign straight back in.
    login_user($rmUser);
}
if (current_user()) {
    redirect('index.php');
}

$error = null;
$email = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_csrf();
    $email = strtolower(post_str('email', 190));
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $win = (int) config('login.window');
    $max = (int) config('login.max_attempts');
    $pairBucket = 'login:' . client_ip() . '|' . $email;
    $ipBucket = 'login_ip:' . client_ip();

    if (!rate_limit_check($pairBucket, $max, $win) || !rate_limit_check($ipBucket, $max * 4, $win)) {
        $error = 'Too many failed attempts. Please wait 15 minutes and try again.';
        log_event('warning', 'auth.login_throttled', ['ip' => client_ip()]);
    } else {
        $user = $email !== '' ? db_one('SELECT * FROM users WHERE email = ?', [$email]) : null;
        $hash = $user['password_hash'] ?? password_hash(random_bytes(8), PASSWORD_DEFAULT);
        if ($user && (int) $user['is_active'] === 1 && password_verify($password, $hash)) {
            if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                db_exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), (int) $user['id']]);
            }
            rate_limit_clear($pairBucket);
            login_user($user);
            lh_rm_issue($user);
            log_event('info', 'auth.login', ['user_id' => (int) $user['id']]);
            redirect('index.php');
        }
        rate_limit($pairBucket, $max, $win);
        rate_limit($ipBucket, $max * 4, $win);
        log_event('warning', 'auth.login_failed', ['email' => $email, 'ip' => client_ip()]);
        $error = 'Invalid email or password.';
    }
}

page_header('Sign in');
?>
<div class="login-wrap">
  <section class="login-aside">
    <div class="brand brand-lg"><?= brand_block() ?></div>
    <h1 class="login-title">Every enquiry,<br>from every website,<br>in one place.</h1>
    <p class="muted">Asha WebTech Lead CRM — one central inbox for every website: forms, WhatsApp and email enquiries.</p>
  </section>
  <section class="login-card">
    <h2>Sign in to your CRM</h2>
    <?php if ($error): ?>
      <div class="flash flash-error" role="alert" data-testid="login-error"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="post" action="login.php" class="stack" data-testid="login-form">
      <?= csrf_field() ?>
      <label>Username or email<input type="text" name="email" value="<?= e($email) ?>" required autocomplete="username" autofocus data-testid="login-email-input"></label>
      <label>Password<input type="password" name="password" required autocomplete="current-password" data-testid="login-password-input"></label>
      <button type="submit" class="btn btn-primary btn-block" data-testid="login-submit-button">Sign in</button>
    </form>
  </section>
</div>
<?php
page_footer();
