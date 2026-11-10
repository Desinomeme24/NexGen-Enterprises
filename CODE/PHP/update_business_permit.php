<?php
require_once __DIR__ . '/config.php';

function nxBusinessPermitRedirect(bool $success, string $message): void
{
    $_SESSION[$success ? 'success' : 'error'] = $message;
    header('Location: ' . nxAppUrl('manage_users.php'));
    exit();
}

if (
    !isset($_SESSION['user_id'], $_SESSION['role'])
    || !nxIsSuperAdminRole((string)$_SESSION['role'])
) {
    nxBusinessPermitRedirect(false, 'Only the BPLO administrator can update business permit status.');
}

enforceSessionTimeout();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    nxBusinessPermitRedirect(false, 'Invalid request method.');
}

$businessEntityId = filter_var($_POST['business_entity_id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if (!is_int($businessEntityId)) {
    nxBusinessPermitRedirect(false, 'Invalid business selection.');
}
if (!validateCsrfToken('business_permit_' . $businessEntityId, $_POST['csrf_token'] ?? null)) {
    nxBusinessPermitRedirect(false, 'Your session expired. Reload Manage Users and try again.');
}

$permitStatus = strtolower(trim((string)($_POST['permit_payment_status'] ?? '')));
if (!in_array($permitStatus, ['paid', 'unpaid'], true)) {
    nxBusinessPermitRedirect(false, 'Select a valid business permit payment status.');
}

$superAdminId = (int)$_SESSION['user_id'];
$transactionStarted = false;
try {
    $accountStmt = $conn->prepare(
        "SELECT id
         FROM users
         WHERE id = ? AND role IN ('system_admin', 'super_admin') AND account_status = 'active'
         LIMIT 1"
    );
    if (!$accountStmt) {
        throw new RuntimeException('Unable to verify the BPLO account.');
    }
    $accountStmt->bind_param('i', $superAdminId);
    $accountStmt->execute();
    $activeSuperAdmin = (bool)$accountStmt->get_result()->fetch_assoc();
    $accountStmt->close();
    if (!$activeSuperAdmin) {
        nxBusinessPermitRedirect(false, 'Your BPLO account is no longer active.');
    }

    if (!$conn->begin_transaction()) {
        throw new RuntimeException('Unable to begin the permit status update.');
    }
    $transactionStarted = true;

    $businessStmt = $conn->prepare(
        "SELECT id, business_name, permit_payment_status, permit_unpaid_since
         FROM business_entities
         WHERE id = ?
         FOR UPDATE"
    );
    if (!$businessStmt) {
        throw new RuntimeException('Unable to load the business permit record.');
    }
    $businessStmt->bind_param('i', $businessEntityId);
    $businessStmt->execute();
    $business = $businessStmt->get_result()->fetch_assoc();
    $businessStmt->close();
    if (!$business) {
        throw new RuntimeException('Business not found.');
    }

    $previousStatus = (string)$business['permit_payment_status'];
    $previousUnpaidSince = $business['permit_unpaid_since'] ?? null;
    if ($permitStatus === 'unpaid') {
        $unpaidSince = $previousStatus === 'unpaid' && !empty($previousUnpaidSince)
            ? (string)$previousUnpaidSince
            : date('Y-m-d H:i:s');
    } else {
        $unpaidSince = null;
    }

    if ($permitStatus !== $previousStatus || $unpaidSince !== $previousUnpaidSince) {
        $updateStmt = $conn->prepare(
            "UPDATE business_entities
             SET permit_payment_status = ?, permit_unpaid_since = ?
             WHERE id = ?"
        );
        if (!$updateStmt) {
            throw new RuntimeException('Unable to prepare the permit status update.');
        }
        $updateStmt->bind_param('ssi', $permitStatus, $unpaidSince, $businessEntityId);
        if (!$updateStmt->execute() || $updateStmt->affected_rows !== 1) {
            throw new RuntimeException('The permit status could not be updated.');
        }
        $updateStmt->close();

        $description = "Set business #{$businessEntityId} ({$business['business_name']}) permit payment status to {$permitStatus}"
            . ($permitStatus === 'unpaid' ? " as of {$unpaidSince}; 30-day grace period started." : '; access restored.');
        if (!logAdminActivitySecure(
            $conn,
            $superAdminId,
            'update_business_permit_status',
            'business_entity',
            $businessEntityId,
            $description
        )) {
            throw new RuntimeException('Unable to record the permit status change.');
        }
    }

    if (!$conn->commit()) {
        throw new RuntimeException('Unable to commit the permit status change.');
    }
    $transactionStarted = false;
    nxBusinessPermitRedirect(true, $permitStatus === 'paid'
        ? 'Business marked as paid; access is active.'
        : 'Business marked as unpaid; the 30-day grace period has started or continues.');
} catch (Throwable $error) {
    if ($transactionStarted) {
        $conn->rollback();
    }
    error_log('NexGen business permit status update failed: ' . $error->getMessage());
    nxBusinessPermitRedirect(false, $error->getMessage());
}
