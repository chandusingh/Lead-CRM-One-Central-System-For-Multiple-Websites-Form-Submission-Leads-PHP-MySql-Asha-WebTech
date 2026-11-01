<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/_bootstrap.php';
require LH_ROOT . '/inc/api.php';

// Open API: any website may submit with a valid API key (no Origin allow-list).
// CORS is answered here so browsers on any domain can post.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');
header('Access-Control-Max-Age: 86400');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Hide the browser's Origin/Referer from the legacy origin check in inc/api.php, so it
// never rejects a site. Requires websites.allow_no_origin = 1 (set by the SQL migration).
$_SERVER['LH_ORIGIN_SEEN'] = $_SERVER['HTTP_ORIGIN'] ?? '';
unset($_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_REFERER']);

api_handle();
