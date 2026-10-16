<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/resend_config.php';

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
    string $redirect = '/NexGen/CODE/PHP/pending_requests.php'
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
    header('Location: ' . $redirect);
    exit;
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'system_admin') {
    nxRejectRequestRespond(
        false,
        'Your administrator session is no longer valid. Please log in again.',
        0,
        401,
        '/NexGen/CODE/PHP/index.php?open=login'
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
    $adminIsActive = (bool)$adminStmt->get_result()->fetch_assoc();
    $adminStmt->close();

    if (!$adminIsActive) {
        nxRejectRequestRespond(
            false,
            'Your administrator account is no longer active.',
            $requestId,
            403,
            '/NexGen/CODE/PHP/index.php?open=login'
        );
    }

    $conn->begin_transaction();

    $stmt = $conn->prepare(
        "SELECT id, request_code, full_name, email, request_status
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
        throw new RuntimeException('Unable to record the administrator action.');
    }

    $conn->commit();
    $_SESSION['last_activity'] = time();
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
        "Your NexGen account registration was rejected by the administrator.\n\n" .
        "Reason:\n{$remarks}\n\n" .
        "If you need assistance, please contact the administrator.\n\n" .
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
