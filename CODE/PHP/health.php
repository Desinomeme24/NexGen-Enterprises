<?php
/* Railway startup health check. Keep this endpoint independent of sessions and
   MySQL so it reports whether Apache/PHP can serve the application process. */
http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

echo json_encode(['status' => 'ok'], JSON_UNESCAPED_SLASHES);
