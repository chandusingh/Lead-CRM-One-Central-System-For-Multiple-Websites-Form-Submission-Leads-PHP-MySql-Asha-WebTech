<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_bootstrap.php';
admin_init();
lh_rm_restore();
if (current_user()) {
    redirect('index.php');
}
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$p = $_SESSION['lh_otp'] ?? null;
if (!is_array($p) || (int) ($p['until'] ?? 0) < time() || empty($p['uid'])) {
    unset($_SESSION['lh_otp']);
    redirect('login.php');
}
$uid = (int) $p['uid'];
$user = db_one('SELECT * FROM users WHERE id = ? AND is_active = 1', [$uid]);
if (!$user) {
    unset($_SESSION['lh_otp']);
    redirect('login.php');
}

$error = null;
$notice = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_csrf();
    $action = post_str('action', 10);

    if ($action === 'resend') {
        $wait = LH_OTP_RESEND_WAIT - (time() - (int) ($p['sent'] ?? 0));
        if ($wait > 0) {
            $error = 'Please wait ' . $wait . ' seconds before requesting another code.';
        } else {
            [$ok, $why] = lh_otp_start($user, true);
            if ($ok) {
                $notice = 'A new code has been sent.';
                $p = $_SESSION['lh_otp'];
            } else {
                $error = $why;
            }
        }
    } else {
        $code = preg_replace('/\D+/', '', post_str('otp', 20)) ?? '';
        if ((int) ($p['exp'] ?? 0) < time()) {
            $error = 'This code has expired. Tap "Resend code" to get a new one.';
        } elseif ((int) ($p['tries'] ?? 0) >= LH_OTP_MAX_TRIES) {
            unset($_SESSION['lh_otp']);
            log_event('warning', 'auth.otp_locked', ['user_id' => $uid, 'ip' => client_ip()]);
            redirect('login.php');
        } elseif (strlen($code) === 6 && hash_equals((string) $p['h'], lh_otp_hash($code, $uid))) {
            unset($_SESSION['lh_otp']);
            login_user($user);
            lh_rm_issue($user);
            log_event('info', 'auth.login', ['user_id' => $uid, 'otp' => true]);
            redirect('index.php');
        } else {
            $_SESSION['lh_otp']['tries'] = (int) $p['tries'] + 1;
            $p['tries'] = $_SESSION['lh_otp']['tries'];
            log_event('warning', 'auth.otp_failed', ['user_id' => $uid, 'ip' => client_ip()]);
            if ($p['tries'] >= LH_OTP_MAX_TRIES) {
                unset($_SESSION['lh_otp']);
                redirect('login.php');
            }
            $error = 'That code is not correct. ' . (LH_OTP_MAX_TRIES - $p['tries']) . ' attempt(s) left.';
        }
    }
}

$resendIn = max(0, LH_OTP_RESEND_WAIT - (time() - (int) ($p['sent'] ?? 0)));

page_header('Verify sign-in');
?>
<div class="login-wrap">
  <section class="login-aside">
    <a class="brand-logo" href="<?= e(lh_base_path()) ?>/admin/" aria-label="Asha WebTech IT Solution Pvt Ltd"><img class="login-logo" src="<?= e(lh_base_path()) ?>/assets/logo-full.png" alt="Asha WebTech IT Solution Pvt Ltd" width="300" height="188"></a>
    <h1 class="login-title">One more step<br>to keep your<br>leads safe.</h1>
    <p class="muted">We sent a 6-digit code by SMS. It is valid for <?= (int) (LH_OTP_TTL / 60) ?> minutes.</p>
  </section>
  <section class="login-card">
    <h2>Enter your OTP</h2>
    <p class="muted small">Code sent to <strong><?= e(lh_otp_mask((string) ($p['mobile'] ?? ''))) ?></strong></p>
    <?php if ($error): ?>
      <div class="flash flash-error" role="alert" data-testid="otp-error"><?= e($error) ?></div>
    <?php elseif ($notice): ?>
      <div class="flash flash-success" role="status" data-testid="otp-notice"><?= e($notice) ?></div>
    <?php endif; ?>
    <form method="post" action="verify_otp.php" class="stack" data-testid="otp-form">
      <?= csrf_field() ?><input type="hidden" name="action" value="verify">
      <label>6-digit code<input type="text" name="otp" inputmode="numeric" pattern="[0-9]*" maxlength="6" minlength="6" autocomplete="one-time-code" required autofocus style="letter-spacing:.4em;font-size:1.4rem;text-align:center" data-testid="otp-input"></label>
      <button type="submit" class="btn btn-primary btn-block" data-testid="otp-submit-button">Verify &amp; sign in</button>
    </form>
    <form method="post" action="verify_otp.php" class="stack" style="margin-top:.75rem">
      <?= csrf_field() ?><input type="hidden" name="action" value="resend">
      <button type="submit" class="btn btn-block" id="resend-btn" data-testid="otp-resend-button" <?= $resendIn > 0 ? 'disabled' : '' ?>>Resend code<?= $resendIn > 0 ? ' (' . $resendIn . 's)' : '' ?></button>
    </form>
    <p class="small muted" style="margin-top:.75rem"><a href="login.php">&larr; Back to sign in</a></p>
  </section>
</div>
<script>
(function () {
  var left = <?= (int) $resendIn ?>, b = document.getElementById('resend-btn');
  if (b && left > 0) {
    var t = setInterval(function () {
      left--;
      if (left <= 0) { clearInterval(t); b.disabled = false; b.textContent = 'Resend code'; }
      else b.textContent = 'Resend code (' + left + 's)';
    }, 1000);
  }
  var i = document.querySelector('[data-testid="otp-input"]');
  if (i) i.addEventListener('input', function () { i.value = i.value.replace(/\D+/g, ''); });
})();
</script>
<?php
page_footer();
