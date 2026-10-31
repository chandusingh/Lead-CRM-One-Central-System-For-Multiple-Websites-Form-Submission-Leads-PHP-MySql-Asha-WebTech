<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_bootstrap.php';
admin_init();
lh_rm_restore(); // session lost but the persistent login cookie is valid: sign straight back in
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
            $user = db_one('SELECT * FROM users WHERE id = ?', [(int) $user['id']]) ?: $user; // fresh hash after a rehash
            if (lh_otp_ready()) {
                // Password is right: second step is the SMS one-time code. Not signed in yet.
                [$sent, $why] = lh_otp_start($user);
                if ($sent) {
                    redirect('verify_otp.php');
                }
                $error = $why;
            } else {
                login_user($user);
                lh_rm_issue($user);
                log_event('info', 'auth.login', ['user_id' => (int) $user['id']]);
                redirect('index.php');
            }
        } else {
            rate_limit($pairBucket, $max, $win);
            rate_limit($ipBucket, $max * 4, $win);
            log_event('warning', 'auth.login_failed', ['email' => $email, 'ip' => client_ip()]);
            $error = 'Invalid email or password.';
        }
    }
}

page_header('Sign in');
?>
<div class="login-wrap">
  <section class="login-aside">
    <a class="brand-logo" href="<?= e(lh_base_path()) ?>/admin/" aria-label="Asha WebTech IT Solution Pvt Ltd"><img class="login-logo" src="<?= e(lh_base_path()) ?>/assets/logo-full.png" alt="Asha WebTech IT Solution Pvt Ltd" width="300" height="188"></a>
    <h1 class="login-title">Every enquiry,<br>from every website,<br>in one place.</h1>
    <p class="muted">Asha WebTech IT Solution Pvt Ltd Lead CRM — one central inbox for every website: forms, WhatsApp and email enquiries.</p>
    <ul class="login-points"><li>Every website, one inbox</li><li>Follow-ups and status tracking</li><li>Instant email alerts for new leads</li></ul>
  </section>
  <section class="login-card">
    <h2>Sign in to your CRM</h2>
    <?php if ($error): ?>
      <div class="flash flash-error" role="alert" data-testid="login-error"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="post" action="login.php" class="stack" data-testid="login-form">
      <?= csrf_field() ?>
      <label>Email<input type="email" name="email" value="<?= e($email) ?>" required autocomplete="username" autofocus data-testid="login-email-input"></label>
      <label>Password<input type="password" name="password" required autocomplete="current-password" data-testid="login-password-input"></label>
      <button type="submit" class="btn btn-primary btn-block" data-testid="login-submit-button">Sign in</button>
    </form>
  </section>
</div>
<?php
page_footer();
