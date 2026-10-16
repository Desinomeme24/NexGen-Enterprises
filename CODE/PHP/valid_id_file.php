<?php
require_once __DIR__ . '/config.php';

/* Compatibility guard: the shared resolver lives in the hardened config.php,
   but keeping the same containment checks here prevents a fatal error during a
   staged deployment where this endpoint is copied before config.php. */
function nxValidIdControllerResolvePath(string $storedReference): ?string
{
    if (function_exists('nxResolveValidIdPath')) {
        return nxResolveValidIdPath($storedReference);
    }

    if (str_starts_with($storedReference, 'private-valid-id:')) {
        $filename = substr($storedReference, strlen('private-valid-id:'));
        $configuredParent = trim((string)(getenv('NEXGEN_PRIVATE_UPLOAD_DIR') ?: ''));
        $baseDirectory = $configuredParent !== ''
            ? rtrim($configuredParent, "\\/") . DIRECTORY_SEPARATOR . 'valid_ids'
            : dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'nexgen_private'
                . DIRECTORY_SEPARATOR . 'valid_ids';
    } elseif (str_starts_with($storedReference, 'uploads/valid_ids/')) {
        $filename = basename($storedReference);
        $baseDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'uploads'
            . DIRECTORY_SEPARATOR . 'valid_ids';
    } else {
        return null;
    }

    if (!preg_match('/^valid_id_[a-f0-9]{24}\.(?:jpe?g|png|gif|webp|pdf)$/', $filename)) {
        return null;
    }

    $realBase = realpath($baseDirectory);
    $realFile = realpath($baseDirectory . DIRECTORY_SEPARATOR . $filename);
    if ($realBase === false || $realFile === false || !is_file($realFile)) {
        return null;
    }

    $basePrefix = rtrim($realBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    return str_starts_with($realFile, $basePrefix) ? $realFile : null;
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'system_admin') {
    http_response_code(403);
    exit;
}

enforceSessionTimeout();

$requestId = filter_input(INPUT_GET, 'request_id', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if (!is_int($requestId)) {
    http_response_code(404);
    exit;
}

try {
    $stmt = $conn->prepare(
        'SELECT valid_id_path FROM registration_requests WHERE id = ? LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the valid-ID lookup.');
    }

    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} catch (Throwable $e) {
    error_log('NexGen valid-ID delivery error: ' . $e->getMessage());
    http_response_code(404);
    exit;
}

$filePath = $row ? nxValidIdControllerResolvePath((string)$row['valid_id_path']) : null;
if ($filePath === null) {
    http_response_code(404);
    exit;
}

$extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
$allowedTypes = [
    'jpg' => ['image/jpeg', 'inline'],
    'jpeg' => ['image/jpeg', 'inline'],
    'png' => ['image/png', 'inline'],
    // Legacy formats remain readable, but new signup uploads no longer accept them.
    'gif' => ['image/gif', 'inline'],
    'webp' => ['image/webp', 'inline'],
    'pdf' => ['application/pdf', 'attachment'],
];
if (!isset($allowedTypes[$extension])) {
    http_response_code(415);
    exit;
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$detectedMime = $finfo ? finfo_file($finfo, $filePath) : false;
if ($finfo) {
    finfo_close($finfo);
}

[$expectedMime, $disposition] = $allowedTypes[$extension];
if (!is_string($detectedMime) || !hash_equals($expectedMime, $detectedMime)) {
    http_response_code(415);
    exit;
}

$downloadName = 'registration_' . $requestId . '_document.' . $extension;
session_write_close();
header('Content-Type: ' . $expectedMime);
header('Content-Length: ' . (string)filesize($filePath));
header('Content-Disposition: ' . $disposition . '; filename="' . $downloadName . '"');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Cross-Origin-Resource-Policy: same-origin');
header("Content-Security-Policy: default-src 'none'; sandbox");
readfile($filePath);
exit;
