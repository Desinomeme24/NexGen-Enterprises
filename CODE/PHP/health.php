<?php
/* Railway startup readiness check: the app needs both PHP and its database. */
require_once __DIR__ . '/config.php';

$isReady = false;
try {
    $isReady = $conn instanceof mysqli && $conn->query('SELECT 1') !== false;
} catch (Throwable $e) {
    error_log('NexGen readiness check failed: ' . $e->getMessage());
}

http_response_code($isReady ? 200 : 503);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

echo json_encode(
    ['status' => $isReady ? 'ok' : 'unavailable'],
    JSON_UNESCAPED_SLASHES
);
