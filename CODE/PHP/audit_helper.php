<?php
/**
 * @param mysqli $conn   Your MySQLi connection object (from config.php)
 */
function set_audit_context(mysqli $conn): void
{
    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 'NULL';

    // Safely resolve IP even behind a proxy
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['HTTP_X_REAL_IP']
        ?? $_SERVER['REMOTE_ADDR']
        ?? '';

    // Take only the first IP if comma-separated (X-Forwarded-For)
    $ip = trim(explode(',', $ip)[0]);
    $ip = $conn->real_escape_string($ip);

    if ($userId === 'NULL') {
        $conn->query("SET @audit_user_id = NULL, @audit_ip = '{$ip}'");
    } else {
        $conn->query("SET @audit_user_id = {$userId}, @audit_ip = '{$ip}'");
    }
}