<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/resend_config.php';
require_once __DIR__ . '/document_upload_security.php';
require_once __DIR__ . '/tenant_helper.php';

function nxRejectRequestWantsJson(): bool
{
    $requestedWith = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));

    return $requestedWith === 'xmlhttprequest' || str_contains($accept, 'application/json');
}

function nxRejectRequestRespond(
    bool $success,
    string $message,
    int $requestId = 0,
    int $httpStatus = 200,
    string $redirect = ''
): void {
    if (nxRejectRequestWantsJson()) {
        http_response_code($httpStatus);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, private, max-age=0');
        header('X-Content-Type-Options: nosniff');
        echo json_encode([
            'success' => $success,
            'message' => $message,
            'request_id' => $requestId,
            'status' => $success ? 'rejected' : null,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $_SESSION['flash'] = [
        'type' => $success ? 'notice-success' : 'notice-error',
        'message' => $message,
    ];
    $defaultRedirect = (string)($_SESSION['role'] ?? '') === 'owner'
        ? nxAppUrl('manage_employees.php')
        : nxAppUrl('pending_requests.php');
    header('Location: ' . ($redirect !== '' ? $redirect : $defaultRedirect));
    exit;
}

if (
    !isset($_SESSION['user_id'])
    || (
        (string)($_SESSION['role'] ?? '') !== 'owner'
        && !nxIsAdminRole((string)($_SESSION['role'] ?? ''))
    )
) {
    nxRejectRequestRespond(
        false,
        'Your reviewer session is no longer valid. Please log in again.',
        0,
        401,
        (string)($_SESSION['role'] ?? '') === 'owner'
            ? NEXGEN_CLIENT_LOGIN_PATH
            : NEXGEN_ADMIN_LOGIN_PATH
    );
}

enforceSessionTimeout();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    nxRejectRequestRespond(false, 'Invalid request method.', 0, 405);
}

if (!validateCsrfToken('admin_request_action', $_POST['csrf_token'] ?? null)) {
    nxRejectRequestRespond(false, 'Your session expired. Please reopen the request and try again.', 0, 403);
}

$requestId = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
$remarks = trim((string)($_POST['remarks'] ?? ''));
$adminId = (int)$_SESSION['user_id'];
$isOwnerReviewer = (string)$_SESSION['role'] === 'owner';

if ($requestId <= 0) {
    nxRejectRequestRespond(false, 'Invalid registration request ID.', 0, 422);
}

$remarksLength = function_exists('mb_strlen')
    ? mb_strlen($remarks, 'UTF-8')
    : strlen($remarks);

if (
    $remarksLength < 3
    || $remarksLength > 2000
    || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $remarks)
) {
    nxRejectRequestRespond(false, 'Provide a rejection reason between 3 and 2,000 characters.', $requestId, 422);
}

$requestData = null;

try {
    $reviewerSql = $isOwnerReviewer
        ? "SELECT id FROM users WHERE id = ? AND role = 'owner' AND account_status = 'active' LIMIT 1"
        : "SELECT id FROM users WHERE id = ? AND role IN ('system_admin', 'super_admin') AND account_status = 'active' LIMIT 1";
    $adminStmt = $conn->prepare($reviewerSql);
    if (!$adminStmt) {
        throw new RuntimeException('Unable to verify the reviewer account.');
    }
    $adminStmt->bind_param('i', $adminId);
    $adminStmt->execute();
    $adminIsActive = (bool)$adminStmt->get_result()->fetch_assoc();
    $adminStmt->close();

    if (!$adminIsActive) {
        nxRejectRequestRespond(
            false,
            'Your reviewer account is no longer active.',
            $requestId,
            403,
            $isOwnerReviewer ? NEXGEN_CLIENT_LOGIN_PATH : NEXGEN_ADMIN_LOGIN_PATH
        );
    }

    $conn->begin_transaction();

    $stmt = $conn->prepare(
        "SELECT id, request_code, full_name, email, request_status,
                requested_role, business_id, business_entity_id, business_code, branch_code,
                valid_id_path, business_permit_path
         FROM registration_requests
         WHERE id = ?
         FOR UPDATE"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to read the registration request.');
    }

    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $requestData = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$requestData) {
        throw new RuntimeException('Registration request not found.');
    }

    if (!in_array((string)$requestData['request_status'], ['pending', 'resubmit'], true)) {
        throw new RuntimeException('This request is already finalized and can no longer be rejected.');
    }
    if (
        ($isOwnerReviewer && !nxOwnerCanReviewEmployeeRequest($conn, $adminId, $requestData))
        || (!$isOwnerReviewer && (string)$requestData['requested_role'] !== 'owner')
    ) {
        throw new RuntimeException('This reviewer is not authorized to reject the requested account.');
    }

    $reviewedAt = date('Y-m-d H:i:s');
    $stmt = $conn->prepare(
        "UPDATE registration_requests
         SET request_status = 'rejected',
             admin_remarks = ?,
             reviewed_by = ?,
             reviewed_at = ?
         WHERE id = ?
           AND request_status IN ('pending', 'resubmit')"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the rejection update.');
    }

    $stmt->bind_param('sisi', $remarks, $adminId, $reviewedAt, $requestId);
    if (!$stmt->execute() || $stmt->affected_rows !== 1) {
        throw new RuntimeException('The request status changed before the rejection could be saved.');
    }
    $stmt->close();

    $description = "Rejected request #{$requestId}" .
        (!empty($requestData['request_code']) ? " ({$requestData['request_code']})" : '');

    if (!logAdminActivitySecure(
        $conn,
        $adminId,
        'reject_request',
        'registration_request',
        $requestId,
        $description
    )) {
        throw new RuntimeException('Unable to record the reviewer action.');
    }

    $conn->commit();
    $_SESSION['last_activity'] = time();

    foreach ([
        ['valid_id_path', 'valid_id'],
        ['business_permit_path', 'business_permit'],
    ] as [$pathField, $slot]) {
        $reference = (string)($requestData[$pathField] ?? '');
        if ($reference === '') {
            continue;
        }

        try {
            $documentRemoved = nxDeleteSensitiveDocument($reference, $slot);
        } catch (Throwable $cleanupError) {
            $documentRemoved = false;
            error_log('NexGen rejected-document cleanup failed: ' . $cleanupError->getMessage());
        }
        if (!$documentRemoved) {
            nxDocumentUploadLog('rejection.cleanup_failed', [
                'user_id' => $adminId,
                'role' => (string)($_SESSION['role'] ?? 'system_admin'),
                'request_id' => $requestId,
                'slot' => $slot,
            ]);
            continue;
        }

        try {
            $clearReference = $conn->prepare(
                "UPDATE registration_requests
                 SET {$pathField} = NULL
                 WHERE id = ? AND request_status = 'rejected' AND {$pathField} = ?"
            );
            if (!$clearReference) {
                throw new RuntimeException('Unable to clear a deleted document reference.');
            }
            $clearReference->bind_param('is', $requestId, $reference);
            if (!$clearReference->execute()) {
                throw new RuntimeException('Unable to clear a deleted document reference.');
            }
            $clearReference->close();
        } catch (Throwable $cleanupError) {
            nxDocumentUploadLog('rejection.reference_cleanup_failed', [
                'user_id' => $adminId,
                'role' => (string)($_SESSION['role'] ?? 'system_admin'),
                'request_id' => $requestId,
                'slot' => $slot,
            ]);
            error_log('NexGen rejected-document reference cleanup failed: ' . $cleanupError->getMessage());
        }
    }
} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $rollbackError) {
        error_log('NexGen rejection rollback failed: ' . $rollbackError->getMessage());
    }

    error_log(
        "NexGen rejection failed for request #{$requestId}, admin #{$adminId}: " .
        $e->getMessage()
    );

    nxRejectRequestRespond(false, 'Rejection failed: ' . $e->getMessage(), $requestId, 422);
}

/* A mail failure must not reverse the committed rejection. */
try {
    $emailBody =
        "Hello,\n\n" .
        "Your NexGen account registration was rejected by the " .
        ($isOwnerReviewer ? 'business owner' : 'administrator') . ".\n\n" .
        "Reason:\n{$remarks}\n\n" .
        "If you need assistance, please contact the reviewer.\n\n" .
        "Thank you.\n— NexGen System";

    nxSendResendEmail(
        (string)$requestData['email'],
        (string)($requestData['full_name'] ?: 'NexGen User'),
        'NexGen Account Registration Rejected',
        $emailBody
    );
} catch (Throwable $mailError) {
    error_log(
        "Rejection email failed for request #{$requestId}: " .
        $mailError->getMessage()
    );
}

nxRejectRequestRespond(
    true,
    'Registration request rejected successfully.',
    $requestId
);
