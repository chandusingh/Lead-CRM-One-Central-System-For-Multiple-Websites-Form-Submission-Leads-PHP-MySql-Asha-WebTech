<?php
declare(strict_types=1);

// Tiny heartbeat so an open CRM tab keeps its session alive. Returns 204 (signed in) or 401.
require dirname(__DIR__) . '/_bootstrap.php';
admin_init();
header('Cache-Control: no-store');
if (!current_user() && ($rmUser = lh_rm_user())) {
    login_user($rmUser);
}
http_response_code(current_user() ? 204 : 401);
