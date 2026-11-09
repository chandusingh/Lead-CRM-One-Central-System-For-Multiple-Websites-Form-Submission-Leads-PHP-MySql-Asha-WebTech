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
        // Dummy hash keeps timing similar for unknown emails (hex: bcrypt rejects NUL bytes on PHP 8.4+).
        $hash = (string) ($user['password_hash'] ?? password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT));
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
  <section class="login-form-side">
    <div class="mx-auto w-full max-w-md pt-2 sm:pt-6 lg:hidden">
      <a href="<?= e(lh_base_path()) ?>/admin/" aria-label="Asha WebTech IT Solution Pvt Ltd"><img class="login-logo" src="<?= e(lh_base_path()) ?>/assets/logo-full.png" alt="Asha WebTech IT Solution Pvt Ltd" width="172" height="108"></a>
    </div>
    <div class="login-card mx-auto flex w-full max-w-md flex-1 flex-col justify-center py-10">
      <h1>Sign in</h1>
      <p class="login-sub">Enter your email and password to open the Lead CRM.</p>
      <?php if ($error): ?>
        <div class="flash flash-error" role="alert" data-testid="login-error"><?= e($error) ?></div>
      <?php endif; ?>
      <form method="post" action="login.php" class="stack" data-testid="login-form">
        <?= csrf_field() ?>
        <label>Email <input type="email" name="email" value="<?= e($email) ?>" required autocomplete="username" autofocus placeholder="you@company.com" data-testid="login-email-input"></label>
        <label>Password <span class="relative block"><input type="password" name="password" required autocomplete="current-password" placeholder="Enter your password" class="pr-12" data-testid="login-password-input"><button type="button" data-toggle-password aria-label="Show password" class="absolute top-1/2 right-4 -translate-y-1/2 cursor-pointer text-gray-500 hover:text-gray-700"><svg class="size-5" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M10.0002 13.8619C7.23361 13.8619 4.86803 12.1372 3.92328 9.70241C4.86804 7.26761 7.23361 5.54297 10.0002 5.54297C12.7667 5.54297 15.1323 7.26762 16.0771 9.70243C15.1323 12.1372 12.7667 13.8619 10.0002 13.8619ZM10.0002 4.04297C6.48191 4.04297 3.49489 6.30917 2.4155 9.4593C2.3615 9.61687 2.3615 9.78794 2.41549 9.94552C3.49488 13.0957 6.48191 15.3619 10.0002 15.3619C13.5184 15.3619 16.5055 13.0957 17.5849 9.94555C17.6389 9.78797 17.6389 9.6169 17.5849 9.45932C16.5055 6.30919 13.5184 4.04297 10.0002 4.04297ZM9.99151 7.84413C8.96527 7.84413 8.13333 8.67606 8.13333 9.70231C8.13333 10.7286 8.96527 11.5605 9.99151 11.5605H10.0064C11.0326 11.5605 11.8646 10.7286 11.8646 9.70231C11.8646 8.67606 11.0326 7.84413 10.0064 7.84413H9.99151Z" fill="currentColor"/></svg></button></span></label>
        <button type="submit" class="btn btn-primary btn-block" data-testid="login-submit-button">Sign in</button>
      </form>
    </div>
  </section>
  <section class="login-cover" aria-hidden="true">
    <div class="pointer-events-none absolute top-0 right-0 w-full max-w-62.5 xl:max-w-112.5"><img src="<?= e(lh_base_path()) ?>/assets/grid-shape.svg" alt="" width="450" height="254"></div>
    <div class="pointer-events-none absolute bottom-0 left-0 w-full max-w-62.5 rotate-180 xl:max-w-112.5"><img src="<?= e(lh_base_path()) ?>/assets/grid-shape.svg" alt="" width="450" height="254"></div>
    <div class="login-cover-card">
      <div class="mb-8 rounded-2xl bg-white p-5 shadow-theme-xl"><img src="<?= e(lh_base_path()) ?>/assets/logo-full.png" alt="" width="190" height="119" class="h-auto w-44"></div>
      <h2 class="mb-3 text-title-md font-semibold text-white">Every enquiry, from every website, in one place.</h2>
      <p class="text-sm leading-6 text-gray-400">Asha WebTech IT Solution Pvt Ltd Lead CRM &mdash; one central inbox for forms, WhatsApp and email enquiries.</p>
      <ul class="mt-6 flex flex-col gap-2.5 text-left text-sm text-gray-300">
        <li class="flex items-center gap-2.5"><span class="grid size-5 shrink-0 place-items-center rounded-full bg-white/10 text-brand-300"><svg width="12" height="12" viewBox="0 0 16 16" fill="none"><path d="m3.5 8.5 3 3 6-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>Every website, one inbox</li>
        <li class="flex items-center gap-2.5"><span class="grid size-5 shrink-0 place-items-center rounded-full bg-white/10 text-brand-300"><svg width="12" height="12" viewBox="0 0 16 16" fill="none"><path d="m3.5 8.5 3 3 6-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>Follow-ups and status tracking</li>
        <li class="flex items-center gap-2.5"><span class="grid size-5 shrink-0 place-items-center rounded-full bg-white/10 text-brand-300"><svg width="12" height="12" viewBox="0 0 16 16" fill="none"><path d="m3.5 8.5 3 3 6-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>Instant email alerts for new leads</li>
      </ul>
    </div>
  </section>
</div>
<?php
page_footer();
