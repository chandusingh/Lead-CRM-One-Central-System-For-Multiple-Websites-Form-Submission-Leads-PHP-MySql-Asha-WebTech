<?php
declare(strict_types=1);

// Tiny heartbeat so an open CRM tab keeps its session alive. Returns 204 (signed in) or 401.
require dirname(__DIR__) . '/_bootstrap.php';
admin_init();
header('Cache-Control: no-store');
lh_rm_restore();
http_response_code(current_user() ? 204 : 401);
