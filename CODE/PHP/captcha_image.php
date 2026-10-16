<?php
/* Lightweight CAPTCHA delivery. This endpoint deliberately does not include
   config.php because doing so would open a MySQL connection for every image. */
require_once __DIR__ . '/filesystem_paths.php';

$forceHttps = filter_var((string)(getenv('NEXGEN_FORCE_HTTPS') ?: '0'), FILTER_VALIDATE_BOOLEAN);
$isHttps = $forceHttps
    || (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
    || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_secure', $isHttps ? '1' : '0');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_path', '/');
    session_start();
}

$formKey = trim((string)($_GET['form'] ?? ''));
$imageToken = trim((string)($_GET['id'] ?? ''));

if (
    !preg_match('/^[a-z0-9_-]{1,50}$/', $formKey)
    || !preg_match('/^[a-f0-9]{32}$/', $imageToken)
    || empty($_SESSION['image_captcha'][$formKey])
) {
    session_write_close();
    http_response_code(404);
    exit;
}

$captcha = $_SESSION['image_captcha'][$formKey];
$storedPath = (string)($captcha['image_paths'][$imageToken] ?? '');
$expiresAt = (int)($captcha['expires_at'] ?? 0);
session_write_close();

if ($expiresAt < time() || $storedPath === '') {
    http_response_code(404);
    exit;
}

$baseDirectory = nxCaptchaImageDirectory();
$imagePath = realpath($storedPath);
if ($baseDirectory === null || $imagePath === false || !is_file($imagePath)) {
    http_response_code(404);
    exit;
}

$basePrefix = rtrim($baseDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
if (!str_starts_with($imagePath, $basePrefix)) {
    http_response_code(404);
    exit;
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = $finfo ? finfo_file($finfo, $imagePath) : false;
if ($finfo) {
    finfo_close($finfo);
}

if (!is_string($mime) || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    http_response_code(415);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($imagePath));
header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Cross-Origin-Resource-Policy: same-origin');
header("Content-Security-Policy: default-src 'none'; sandbox");
readfile($imagePath);
exit;
