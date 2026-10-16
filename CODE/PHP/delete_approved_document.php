<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/document_upload_security.php';

function nxApprovedDocumentDeleteRespond(bool $success, string $message, int $requestId): void
{
    $_SESSION['flash'] = [
        'type' => $success ? 'notice-success' : 'notice-error',
        'message' => $message,
    ];

    $destination = nxAppUrl('pending_requests.php');
    if ($requestId > 0) {
        $destination = nxAppUrl('view_request.php') . '?id=' . $requestId;
    }
    header('Location: ' . $destination);
    exit();
}

if (
    !isset($_SESSION['user_id'], $_SESSION['role'])
    || $_SESSION['role'] !== 'system_admin'
) {
    nxApprovedDocumentDeleteRespond(
        false,
        'Your administrator session is no longer valid. Please log in again.',
        0
    );
}

enforceSessionTimeout();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    nxApprovedDocumentDeleteRespond(false, 'Invalid request method.', 0);
}

if (!validateCsrfToken('admin_delete_document', $_POST['csrf_token'] ?? null)) {
    nxApprovedDocumentDeleteRespond(
        false,
        'Your session expired. Please reopen the request and try again.',
        0
    );
}

$requestId = filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$slot = (string)($_POST['slot'] ?? '');
if (!is_int($requestId) || !in_array($slot, ['valid_id', 'business_permit'], true)) {
    nxApprovedDocumentDeleteRespond(false, 'Invalid document selection.', 0);
}

$adminId = (int)$_SESSION['user_id'];
$pathField = $slot === 'valid_id' ? 'valid_id_path' : 'business_permit_path';
$reference = '';
$transactionStarted = false;

try {
    $adminStmt = $conn->prepare(
        "SELECT id
         FROM users
         WHERE id = ? AND role = 'system_admin' AND account_status = 'active'
         LIMIT 1"
    );
    if (!$adminStmt) {
        throw new RuntimeException('Unable to verify the administrator account.');
    }
    $adminStmt->bind_param('i', $adminId);
    $adminStmt->execute();
    $activeAdmin = (bool)$adminStmt->get_result()->fetch_assoc();
    $adminStmt->close();
    if (!$activeAdmin) {
        nxApprovedDocumentDeleteRespond(
            false,
            'Your administrator account is no longer active.',
            $requestId
        );
    }

    if (!$conn->begin_transaction()) {
        throw new RuntimeException('Unable to start document access revocation.');
    }
    $transactionStarted = true;
    $requestStmt = $conn->prepare(
        "SELECT request_status, {$pathField} AS document_reference
         FROM registration_requests
         WHERE id = ?
         FOR UPDATE"
    );
    if (!$requestStmt) {
        throw new RuntimeException('Unable to read the registration request.');
    }
    $requestStmt->bind_param('i', $requestId);
    $requestStmt->execute();
    $request = $requestStmt->get_result()->fetch_assoc();
    $requestStmt->close();

    if (!$request || (string)$request['request_status'] !== 'approved') {
        throw new RuntimeException('Only approved-request documents can be deleted here.');
    }
    $reference = (string)($request['document_reference'] ?? '');
    if ($reference === '') {
        throw new RuntimeException('This document has already been removed.');
    }

    $clearRequestStmt = $conn->prepare(
        "UPDATE registration_requests
         SET {$pathField} = NULL
         WHERE id = ? AND request_status = 'approved' AND {$pathField} = ?"
    );
    if (!$clearRequestStmt) {
        throw new RuntimeException('Unable to prepare document access revocation.');
    }
    $clearRequestStmt->bind_param('is', $requestId, $reference);
    if (!$clearRequestStmt->execute() || $clearRequestStmt->affected_rows !== 1) {
        throw new RuntimeException('Unable to revoke document access.');
    }
    $clearRequestStmt->close();

    $clearUserStmt = $conn->prepare(
        "UPDATE users SET {$pathField} = NULL WHERE {$pathField} = ?"
    );
    if (!$clearUserStmt) {
        throw new RuntimeException('Unable to prepare approved-user document access revocation.');
    }
    $clearUserStmt->bind_param('s', $reference);
    if (!$clearUserStmt->execute()) {
        throw new RuntimeException('Unable to revoke approved-user document access.');
    }
    $clearUserStmt->close();

    $description = "Removed approved {$slot} document from registration request #{$requestId}";
    if (!logAdminActivitySecure(
        $conn,
        $adminId,
        'delete_approved_document',
        'registration_request',
        $requestId,
        $description
    )) {
        throw new RuntimeException('Unable to record the administrator action.');
    }

    if (!$conn->commit()) {
        throw new RuntimeException('Unable to commit document access revocation.');
    }
    $transactionStarted = false;
} catch (Throwable $error) {
    if ($transactionStarted) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackError) {
            error_log('NexGen approved-document deletion rollback failed.');
        }
    }
    error_log('NexGen approved-document deletion failed: ' . $error->getMessage());
    nxApprovedDocumentDeleteRespond(
        false,
        'The document could not be removed. Please retry or contact an operator.',
        $requestId
    );
}

$deleted = false;
try {
    $deleted = nxDeleteSensitiveDocument($reference, $slot);
} catch (Throwable $error) {
    error_log('NexGen approved-document storage cleanup failed: ' . $error->getMessage());
}

nxDocumentUploadLog($deleted ? 'document.admin_deleted' : 'document.admin_delete_cleanup_failed', [
    'user_id' => $adminId,
    'role' => 'system_admin',
    'request_id' => $requestId,
    'slot' => $slot,
]);

if (!$deleted) {
    nxApprovedDocumentDeleteRespond(
        false,
        'Document access was revoked, but secure storage cleanup could not be confirmed. Contact an operator.',
        $requestId
    );
}

nxApprovedDocumentDeleteRespond(true, 'The approved document was securely removed.', $requestId);
