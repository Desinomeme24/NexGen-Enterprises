<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/document_upload_security.php';

function nxDocumentDeliveryFail(int $status, int $adminId = 0, int $requestId = 0, string $slot = 'unknown'): void
{
    nxDocumentUploadLog('document.access_denied', [
        'user_id' => $adminId,
        'role' => $adminId > 0 ? 'system_admin' : 'unauthenticated',
        'request_id' => $requestId,
        'slot' => $slot,
    ]);
    http_response_code($status);
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    exit;
}

$adminId = (int)($_SESSION['user_id'] ?? 0);
if ($adminId <= 0 || ($_SESSION['role'] ?? '') !== 'system_admin') {
    nxDocumentDeliveryFail(403, $adminId);
}

enforceSessionTimeout();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    nxDocumentDeliveryFail(405, $adminId);
}

$requestId = filter_var($_GET['request_id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$slot = (string)($_GET['slot'] ?? 'valid_id');
if (!is_int($requestId) || !in_array($slot, ['valid_id', 'business_permit'], true)) {
    nxDocumentDeliveryFail(404, $adminId, is_int($requestId) ? $requestId : 0, $slot);
}

try {
    $adminStmt = $conn->prepare(
        "SELECT id
         FROM users
         WHERE id = ? AND role = 'system_admin' AND account_status = 'active'
         LIMIT 1"
    );
    if (!$adminStmt) {
        throw new RuntimeException('Administrator authorization lookup failed.');
    }
    $adminStmt->bind_param('i', $adminId);
    $adminStmt->execute();
    $activeAdmin = (bool)$adminStmt->get_result()->fetch_assoc();
    $adminStmt->close();
    if (!$activeAdmin) {
        nxDocumentDeliveryFail(403, $adminId, $requestId, $slot);
    }

    $documentColumn = $slot === 'valid_id' ? 'valid_id_path' : 'business_permit_path';
    $stmt = $conn->prepare(
        "SELECT {$documentColumn} AS document_reference, request_status
         FROM registration_requests
         WHERE id = ?
         LIMIT 1"
    );
    if (!$stmt) {
        throw new RuntimeException('Document authorization lookup failed.');
    }
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $request = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} catch (Throwable $error) {
    error_log('NexGen private document lookup failed: ' . $error->getMessage());
    nxDocumentDeliveryFail(404, $adminId, $requestId, $slot);
}

if (
    !$request
    || (string)$request['request_status'] === 'rejected'
    || empty($request['document_reference'])
) {
    nxDocumentDeliveryFail(404, $adminId, $requestId, $slot);
}

$temporaryDocument = null;
try {
    $temporaryDocument = nxReadSensitiveDocument((string)$request['document_reference'], $slot);
    if ($temporaryDocument === null) {
        nxDocumentDeliveryFail(404, $adminId, $requestId, $slot);
    }

    nxDocumentUploadLog('document.accessed', [
        'user_id' => $adminId,
        'role' => 'system_admin',
        'request_id' => $requestId,
        'slot' => $slot,
    ]);

    $downloadName = 'registration_' . $requestId . '_' . $slot . '.' . $temporaryDocument['extension'];
    session_write_close();
    header('Content-Type: ' . $temporaryDocument['mime']);
    header('Content-Length: ' . (string)filesize($temporaryDocument['path']));
    header('Content-Disposition: ' . $temporaryDocument['disposition'] . '; filename="' . $downloadName . '"');
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Cross-Origin-Resource-Policy: same-origin');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    readfile($temporaryDocument['path']);
} catch (Throwable $error) {
    error_log('NexGen private document delivery failed: ' . $error->getMessage());
    nxDocumentUploadLog('document.access_denied', [
        'user_id' => $adminId,
        'role' => 'system_admin',
        'request_id' => $requestId,
        'slot' => $slot,
    ]);
    if (!headers_sent()) {
        http_response_code(404);
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
    }
} finally {
    if ($temporaryDocument !== null && is_file((string)$temporaryDocument['path'])) {
        unlink((string)$temporaryDocument['path']);
    }
}
exit;
