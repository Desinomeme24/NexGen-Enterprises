<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/tenant_helper.php';

function nxValidationRespond(bool $success, string $message, int $requestId = 0): void
{
    $isJsonRequest = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
        || str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
    if ($isJsonRequest) {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, private, max-age=0');
        header('X-Content-Type-Options: nosniff');
        echo json_encode([
            'success' => $success,
            'message' => $message,
            'request_id' => $requestId,
            'status' => $success ? 'validated' : null,
        ], JSON_UNESCAPED_SLASHES);
        exit();
    }

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
    || !nxIsSuperAdminRole((string)$_SESSION['role'])
) {
    nxValidationRespond(false, 'Only the BPLO super admin can validate registration requests.');
}

enforceSessionTimeout();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    nxValidationRespond(false, 'Invalid request method.');
}
if (!validateCsrfToken('bplo_validate_request', $_POST['csrf_token'] ?? null)) {
    nxValidationRespond(false, 'Your session expired. Reopen the request and try again.');
}

$requestId = filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$remarks = trim((string)($_POST['remarks'] ?? ''));
$remarksLength = function_exists('mb_strlen')
    ? mb_strlen($remarks, 'UTF-8')
    : strlen($remarks);

if (!is_int($requestId)) {
    nxValidationRespond(false, 'Invalid registration request ID.');
}
if (
    $remarksLength < 3
    || $remarksLength > 2000
    || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $remarks)
) {
    nxValidationRespond(false, 'Enter BPLO verification notes between 3 and 2,000 characters.', $requestId);
}
if ((string)($_POST['small_enterprise_confirmed'] ?? '') !== '1') {
    nxValidationRespond(false, 'Confirm the business meets the small-enterprise scope before validation.', $requestId);
}

$superAdminId = (int)$_SESSION['user_id'];
$transactionStarted = false;

try {
    $adminStmt = $conn->prepare(
        "SELECT id
         FROM users
         WHERE id = ? AND role IN ('system_admin', 'super_admin') AND account_status = 'active'
         LIMIT 1"
    );
    if (!$adminStmt) {
        throw new RuntimeException('Unable to verify the BPLO account.');
    }
    $adminStmt->bind_param('i', $superAdminId);
    $adminStmt->execute();
    $isActive = (bool)$adminStmt->get_result()->fetch_assoc();
    $adminStmt->close();
    if (!$isActive) {
        nxValidationRespond(false, 'Your BPLO account is no longer active.', $requestId);
    }

    if (!$conn->begin_transaction()) {
        throw new RuntimeException('Unable to begin the validation transaction.');
    }
    $transactionStarted = true;

    $requestStmt = $conn->prepare(
        "SELECT id, request_code, request_status
         FROM registration_requests
         WHERE id = ?
         FOR UPDATE"
    );
    if (!$requestStmt) {
        throw new RuntimeException('Unable to load the registration request.');
    }
    $requestStmt->bind_param('i', $requestId);
    $requestStmt->execute();
    $request = $requestStmt->get_result()->fetch_assoc();
    $requestStmt->close();

    if (!$request) {
        throw new RuntimeException('Registration request not found.');
    }
    if ((string)$request['request_status'] !== 'pending') {
        throw new RuntimeException('Only requests awaiting BPLO validation can be validated.');
    }

    $validatedAt = date('Y-m-d H:i:s');
    $updateStmt = $conn->prepare(
        "UPDATE registration_requests
         SET request_status = 'validated',
             validation_remarks = ?,
             validated_by = ?,
             validated_at = ?
         WHERE id = ? AND request_status = 'pending'"
    );
    if (!$updateStmt) {
        throw new RuntimeException('Unable to prepare the validation update.');
    }
    $updateStmt->bind_param('sisi', $remarks, $superAdminId, $validatedAt, $requestId);
    if (!$updateStmt->execute() || $updateStmt->affected_rows !== 1) {
        throw new RuntimeException('The request changed before validation could be saved.');
    }
    $updateStmt->close();

    $requestCode = trim((string)($request['request_code'] ?? ''));
    $description = "Validated registration request #{$requestId}"
        . ($requestCode !== '' ? " ({$requestCode})" : '')
        . '; sent to San Andres admin for final review.';
    if (!logAdminActivitySecure(
        $conn,
        $superAdminId,
        'validate_registration_request',
        'registration_request',
        $requestId,
        $description
    )) {
        throw new RuntimeException('Unable to record the BPLO validation action.');
    }

    if (!$conn->commit()) {
        throw new RuntimeException('Unable to commit the validation result.');
    }
    $transactionStarted = false;
    nxValidationRespond(true, 'Request validated and added to the San Andres admin queue.', $requestId);
} catch (Throwable $error) {
    if ($transactionStarted) {
        $conn->rollback();
    }
    error_log('NexGen registration validation failed: ' . $error->getMessage());
    nxValidationRespond(false, $error->getMessage(), is_int($requestId) ? $requestId : 0);
}
