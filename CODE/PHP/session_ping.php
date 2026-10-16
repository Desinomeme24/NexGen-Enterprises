<?php
/* Lightweight endpoint hit by client-side activity to refresh
   $_SESSION['last_activity'] without a full page reload. This keeps the
   server-side inactivity timeout (config.php: enforceSessionTimeout) in
   sync with the client-side one in header.php, so a user who is actively
   filling out a long form (e.g. the sales wizard) doesn't get silently
   logged out - and lose unsaved form data - just because they haven't
   triggered a full page/AJAX request in a while. */
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false]);
    exit();
}

$_SESSION['last_activity'] = time();
echo json_encode(['ok' => true]);
