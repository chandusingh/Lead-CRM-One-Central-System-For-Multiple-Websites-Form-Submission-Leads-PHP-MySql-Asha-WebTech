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
  <section class="login-form-side">
    <div class="mx-auto w-full max-w-md pt-2 sm:pt-6 lg:hidden">
      <a href="<?= e(lh_base_path()) ?>/admin/" aria-label="Asha WebTech IT Solution Pvt Ltd"><img class="login-logo" src="<?= e(lh_base_path()) ?>/assets/logo-full.png" alt="Asha WebTech IT Solution Pvt Ltd" width="172" height="108"></a>
    </div>
    <div class="login-card mx-auto flex w-full max-w-md flex-1 flex-col justify-center py-10">
      <h1>Enter your OTP</h1>
      <p class="login-sub">Code sent to <strong class="font-medium text-gray-800"><?= e(lh_otp_mask((string) ($p['mobile'] ?? ''))) ?></strong>. It is valid for <?= (int) (LH_OTP_TTL / 60) ?> minutes.</p>
      <?php if ($error): ?>
        <div class="flash flash-error" role="alert" data-testid="otp-error"><?= e($error) ?></div>
      <?php elseif ($notice): ?>
        <div class="flash flash-success" role="status" data-testid="otp-notice"><?= e($notice) ?></div>
      <?php endif; ?>
      <form method="post" action="verify_otp.php" class="stack" data-testid="otp-form">
        <?= csrf_field() ?><input type="hidden" name="action" value="verify">
        <label>6-digit code <input type="text" name="otp" inputmode="numeric" pattern="[0-9]*" maxlength="6" minlength="6" autocomplete="one-time-code" required autofocus placeholder="••••••" class="!h-14 text-center !text-2xl tracking-[.45em]" data-testid="otp-input"></label>
        <button type="submit" class="btn btn-primary btn-block" data-testid="otp-submit-button">Verify &amp; sign in</button>
      </form>
      <form method="post" action="verify_otp.php" class="stack mt-3">
        <?= csrf_field() ?><input type="hidden" name="action" value="resend">
        <button type="submit" class="btn btn-block" id="resend-btn" data-testid="otp-resend-button" <?= $resendIn > 0 ? 'disabled' : '' ?>>Resend code<?= $resendIn > 0 ? ' (' . $resendIn . 's)' : '' ?></button>
      </form>
      <p class="mt-5 text-sm"><a href="login.php">&larr; Back to sign in</a></p>
    </div>
  </section>
  <section class="login-cover" aria-hidden="true">
    <div class="pointer-events-none absolute top-0 right-0 w-full max-w-62.5 xl:max-w-112.5"><img src="<?= e(lh_base_path()) ?>/assets/grid-shape.svg" alt="" width="450" height="254"></div>
    <div class="pointer-events-none absolute bottom-0 left-0 w-full max-w-62.5 rotate-180 xl:max-w-112.5"><img src="<?= e(lh_base_path()) ?>/assets/grid-shape.svg" alt="" width="450" height="254"></div>
    <div class="login-cover-card">
      <div class="mb-8 rounded-2xl bg-white p-5 shadow-theme-xl"><img src="<?= e(lh_base_path()) ?>/assets/logo-full.png" alt="" width="190" height="119" class="h-auto w-44"></div>
      <h2 class="mb-3 text-title-md font-semibold text-white">One more step to keep your leads safe.</h2>
      <p class="text-sm leading-6 text-gray-400">We sent a 6-digit code by SMS. Enter it to finish signing in.</p>
    </div>
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
