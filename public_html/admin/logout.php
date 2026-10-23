<?php
declare(strict_types=1);

require dirname(__DIR__) . '/_bootstrap.php';
admin_init();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    redirect('index.php');
}
require_csrf();
if ($u = current_user()) {
    log_event('info', 'auth.logout', ['user_id' => (int) $u['id']]);
}
logout_user();
lh_rm_clear();
redirect('login.php');
