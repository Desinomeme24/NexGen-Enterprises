<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/resend_config.php';
require_once __DIR__ . '/tenant_helper.php';
require_once __DIR__ . '/document_upload_security.php';

function nxApproveRequestWantsJson(): bool
{
    $requestedWith = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));

    return $requestedWith === 'xmlhttprequest' || str_contains($accept, 'application/json');
}

function nxApproveRequestRespond(
    bool $success,
    string $message,
    int $requestId = 0,
    int $httpStatus = 200,
    string $redirect = ''
): void {
    if (nxApproveRequestWantsJson()) {
        http_response_code($httpStatus);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, private, max-age=0');
        header('X-Content-Type-Options: nosniff');
        echo json_encode([
            'success' => $success,
            'message' => $message,
            'request_id' => $requestId,
            'status' => $success ? 'approved' : null,
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

/*
 * NexGen registration approval handler.
 * Revalidates the BPLO administrator against the database before any
 * registration is finalized.
 */

if (
    !isset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role'])
    || (
        (string)$_SESSION['role'] !== 'owner'
        && !nxIsAdminRole((string)$_SESSION['role'])
    )
) {
    $_SESSION['form_type'] = 'login';
    nxApproveRequestRespond(
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

/* Revalidate and repair a stale administrator ID stored in the session. */
$sessionAdminId = (int) $_SESSION['user_id'];
$sessionUsername = trim((string) $_SESSION['username']);

$isOwnerReviewer = (string)$_SESSION['role'] === 'owner';
$reviewerSql = $isOwnerReviewer
    ? "SELECT id, username, role, account_status
       FROM users
       WHERE id = ? AND role = 'owner'
       LIMIT 1"
    : "SELECT id, username, role, account_status
       FROM users
       WHERE (id = ? OR username = ?)
         AND role IN ('system_admin', 'super_admin')
       LIMIT 1";
$adminStmt = $conn->prepare($reviewerSql);

if (!$adminStmt) {
    nxApproveRequestRespond(false, 'Unable to verify the administrator account.', 0, 500);
}

if ($isOwnerReviewer) {
    $adminStmt->bind_param('i', $sessionAdminId);
} else {
    $adminStmt->bind_param('is', $sessionAdminId, $sessionUsername);
}
$adminStmt->execute();
$adminAccount = $adminStmt->get_result()->fetch_assoc();
$adminStmt->close();

if (!$adminAccount || $adminAccount['account_status'] !== 'active') {
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['form_type'] = 'login';
    nxApproveRequestRespond(
        false,
        'Your reviewer account could not be verified. Please log in again.',
        0,
        401,
        $isOwnerReviewer ? NEXGEN_CLIENT_LOGIN_PATH : NEXGEN_ADMIN_LOGIN_PATH
    );
}

/* Synchronize an old session value, such as user_id 0, with the real DB ID. */
$adminId = (int) $adminAccount['id'];
$_SESSION['user_id'] = $adminId;
$_SESSION['username'] = $adminAccount['username'];
$_SESSION['role'] = $adminAccount['role'];
$_SESSION['account_status'] = $adminAccount['account_status'];
$_SESSION['last_activity'] = time();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    nxApproveRequestRespond(false, 'Invalid request method.', 0, 405);
}

if (!validateCsrfToken('admin_request_action', $_POST['csrf_token'] ?? null)) {
    nxApproveRequestRespond(false, 'Your session expired. Please reopen the request and try again.', 0, 403);
}

$requestId = isset($_POST['request_id']) ? (int) $_POST['request_id'] : 0;
$remarks = trim((string) ($_POST['remarks'] ?? ''));

if ($requestId <= 0) {
    nxApproveRequestRespond(false, 'Invalid registration request ID.', 0, 422);
}

$remarksLength = function_exists('mb_strlen')
    ? mb_strlen($remarks, 'UTF-8')
    : strlen($remarks);
if ($remarksLength > 2000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $remarks)) {
    nxApproveRequestRespond(
        false,
        'Approval remarks must not exceed 2,000 characters or contain control characters.',
        $requestId,
        422
    );
}

$conn->begin_transaction();

try {
    $stmt = $conn->prepare(
        "SELECT * FROM registration_requests WHERE id = ? FOR UPDATE"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to read the request: ' . $conn->error);
    }

    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $request = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$request) {
        throw new RuntimeException('Registration request not found.');
    }

    if (!in_array($request['request_status'], ['pending', 'resubmit'], true)) {
        throw new RuntimeException('Only pending or resubmitted requests can be approved.');
    }
    $role = (string)($request['requested_role'] ?? '');
    if (
        ($isOwnerReviewer && ($role !== 'employee' || !nxOwnerCanReviewEmployeeRequest($conn, $adminId, $request)))
        || (!$isOwnerReviewer && $role !== 'owner')
    ) {
        throw new RuntimeException('This reviewer is not authorized to approve the requested account.');
    }
    if ($role === 'owner' && (string)($_POST['small_enterprise_confirmed'] ?? '') !== '1') {
        throw new RuntimeException('Confirm that the business qualifies as a small enterprise before approval.');
    }

    $stmt = $conn->prepare(
        'SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to check existing accounts: ' . $conn->error);
    }

    $stmt->bind_param('ss', $request['username'], $request['email']);
    $stmt->execute();
    $alreadyExists = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    if ($alreadyExists) {
        throw new RuntimeException('A user with the same username or email already exists.');
    }

    if (!in_array($role, ['owner', 'employee'], true)) {
        throw new RuntimeException('The requested role is invalid.');
    }
    if (
        empty($request['valid_id_path'])
        || nxDocumentUploadResolveReference((string)$request['valid_id_path'], 'valid_id') === null
    ) {
        throw new RuntimeException('The required ID photo is unavailable or has not been migrated to secure storage.');
    }
    if (
        $role === 'owner'
        && (
            empty($request['business_permit_path'])
            || nxDocumentUploadResolveReference((string)$request['business_permit_path'], 'business_permit') === null
        )
    ) {
        throw new RuntimeException('A verified business permit is required before approving an owner request.');
    }

    if (!nxWorkspaceSchemaReady($conn)) {
        throw new RuntimeException(
            'The multi-business and branch migration is incomplete. Complete the database migration before approving accounts.'
        );
    }

    $businessId = null;
    $businessEntityId = null;
    $businessCode = strtoupper(trim((string) ($request['business_code'] ?? '')));
    $branchCode = strtoupper(trim((string) ($request['branch_code'] ?? '')));
    $branchName = '';

    if ($role === 'owner') {
        if (trim((string) $request['business_name']) === '' ||
            trim((string) $request['business_type']) === '' ||
            trim((string) $request['business_address']) === '' ||
            ((string)$request['business_type'] === 'Others'
                && trim((string)($request['business_type_other'] ?? '')) === '')) {
            throw new RuntimeException('The owner request has incomplete SE business information.');
        }

        do {
            $businessCode = 'SE-' . strtoupper(bin2hex(random_bytes(3)));
            $codeStmt = $conn->prepare(
                'SELECT id FROM businesses WHERE business_code = ? LIMIT 1'
            );
            if (!$codeStmt) {
                throw new RuntimeException('Unable to generate a business code: ' . $conn->error);
            }
            $codeStmt->bind_param('s', $businessCode);
            $codeStmt->execute();
            $codeExists = $codeStmt->get_result()->num_rows > 0;
            $codeStmt->close();
        } while ($codeExists);

        $stmt = $conn->prepare(
            'INSERT INTO businesses
                (business_name, business_type, business_type_other, business_address, business_code)
             VALUES (?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to create the SE business: ' . $conn->error);
        }

        $businessTypeOther = (string)$request['business_type'] === 'Others'
            ? (string)($request['business_type_other'] ?? '')
            : null;
        $stmt->bind_param(
            'sssss',
            $request['business_name'],
            $request['business_type'],
            $businessTypeOther,
            $request['business_address'],
            $businessCode
        );

        if (!$stmt->execute()) {
            throw new RuntimeException('Failed to create the SE business: ' . $stmt->error);
        }

        $businessId = (int) $stmt->insert_id;
        $stmt->close();

        $stmt = $conn->prepare(
            'SELECT business_entity_id, business_code, branch_code, branch_name
             FROM businesses
             WHERE id = ?
             LIMIT 1'
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to read the approved owner workspace: ' . $conn->error);
        }
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $ownerWorkspace = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$ownerWorkspace) {
            throw new RuntimeException('The approved owner workspace could not be resolved.');
        }

        $businessEntityId = (int) $ownerWorkspace['business_entity_id'];
        $businessCode = (string) $ownerWorkspace['business_code'];
        $branchCode = (string) $ownerWorkspace['branch_code'];
        $branchName = (string) $ownerWorkspace['branch_name'];

        if ((string)$request['business_type'] === 'Others') {
            $typeStmt = $conn->prepare(
                'UPDATE business_entities SET business_type_other = ? WHERE id = ?'
            );
            if (!$typeStmt) {
                throw new RuntimeException('Unable to save the specified SE business type.');
            }
            $businessTypeOther = (string)($request['business_type_other'] ?? '');
            $typeStmt->bind_param('si', $businessTypeOther, $businessEntityId);
            if (!$typeStmt->execute()) {
                throw new RuntimeException('Unable to save the specified SE business type.');
            }
            $typeStmt->close();
        }
    } else {
        if ($businessCode === '' || $branchCode === '') {
            throw new RuntimeException('The employee request must contain both the SE business code and branch code.');
        }

        $stmt = $conn->prepare(
            "SELECT b.id, b.business_entity_id, b.branch_code, b.branch_name,
                    be.business_code, be.business_name
             FROM businesses b
             INNER JOIN business_entities be ON be.id = b.business_entity_id
             WHERE be.business_code = ?
               AND b.branch_code = ?
               AND be.status = 'active'
               AND b.branch_status = 'active'
             LIMIT 1"
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to verify the SE business and branch codes: ' . $conn->error);
        }
        $stmt->bind_param('ss', $businessCode, $branchCode);
        $stmt->execute();
        $workspace = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$workspace) {
            throw new RuntimeException(
                'The submitted SE business code and branch code no longer match an active branch.'
            );
        }

        $businessId = (int) $workspace['id'];
        $businessEntityId = (int) $workspace['business_entity_id'];
        $businessCode = (string) $workspace['business_code'];
        $branchCode = (string) $workspace['branch_code'];
        $branchName = (string) $workspace['branch_name'];

        /* signup_process.php already stores the exact branch ID. Treat a
           mismatch as an integrity problem instead of silently moving the
           employee to another branch during approval. */
        $requestedBusinessId = (int) ($request['business_id'] ?? 0);
        if ($requestedBusinessId > 0 && $requestedBusinessId !== $businessId) {
            throw new RuntimeException(
                'The stored employee branch does not match the submitted branch code. Review the request before approval.'
            );
        }

        $requestedEntityId = (int) ($request['business_entity_id'] ?? 0);
        if ($requestedEntityId > 0 && $requestedEntityId !== $businessEntityId) {
            throw new RuntimeException(
                'The stored employee business does not match the submitted business code. Review the request before approval.'
            );
        }
    }

    if ($businessId <= 0 || $businessEntityId <= 0 || $branchCode === '') {
        throw new RuntimeException('The approved business branch could not be resolved safely.');
    }

    $canInventory = 1;
    $canSales = 1;
    $canSalesAnalytics = $role === 'owner' ? 1 : 0;
    $approvedAt = date('Y-m-d H:i:s');
    $employeeNo = trim((string) ($request['employee_no'] ?? ''));
    $employeeNoForDb = $employeeNo !== '' ? $employeeNo : null;

    $stmt = $conn->prepare(
        "INSERT INTO users (
            business_id, username, full_name, employee_no, email, phone, address,
            otp_preference, password, role, account_status, profile_image,
            valid_id_path, business_permit_path, is_verified, can_inventory, can_sales,
            can_sales_analytics, approved_by, approved_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, 'email', ?, ?, 'active',
            'IMAGES/default-avatar.png', ?, ?, 1, ?, ?, ?, ?, ?
        )"
    );

    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the approved user account: ' . $conn->error);
    }

    $stmt->bind_param(
        'issssssssssiiiis',
        $businessId,
        $request['username'],
        $request['full_name'],
        $employeeNoForDb,
        $request['email'],
        $request['phone'],
        $request['address'],
        $request['password_hash'],
        $role,
        $request['valid_id_path'],
        $request['business_permit_path'],
        $canInventory,
        $canSales,
        $canSalesAnalytics,
        $adminId,
        $approvedAt
    );

    if (!$stmt->execute()) {
        throw new RuntimeException('Failed to create the approved user: ' . $stmt->error);
    }

    $newUserId = (int) $stmt->insert_id;
    $stmt->close();

    if ($role === 'owner') {
        nxSeedDefaultInventoryCategories(
            $conn,
            $businessId,
            (string)$request['business_type']
        );
    }

    /* Save the exact entity and branch at approval time. Employees receive
       only the submitted branch. Owners receive the new business entity and
       its initial main branch. All writes remain inside this transaction. */
    $assignmentRole = $role === 'owner' ? 'owner' : 'employee';
    $stmt = $conn->prepare(
        "INSERT INTO user_business_assignments
            (user_id, business_entity_id, assignment_role, is_default, status)
         VALUES (?, ?, ?, 1, 'active')
         ON DUPLICATE KEY UPDATE
            assignment_role = VALUES(assignment_role),
            is_default = 1,
            status = 'active'"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the business assignment: ' . $conn->error);
    }
    $stmt->bind_param('iis', $newUserId, $businessEntityId, $assignmentRole);
    if (!$stmt->execute()) {
        throw new RuntimeException('Failed to save the business assignment: ' . $stmt->error);
    }
    $stmt->close();

    $stmt = $conn->prepare(
        "INSERT INTO user_branch_assignments
            (user_id, business_id, is_primary, status)
         VALUES (?, ?, 1, 'active')
         ON DUPLICATE KEY UPDATE is_primary = 1, status = 'active'"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the branch assignment: ' . $conn->error);
    }
    $stmt->bind_param('ii', $newUserId, $businessId);
    if (!$stmt->execute()) {
        throw new RuntimeException('Failed to save the branch assignment: ' . $stmt->error);
    }
    $stmt->close();

    $finalRemarks = $remarks !== ''
        ? $remarks
        : ($isOwnerReviewer ? 'Approved by business owner' : 'Approved by system administrator');

    $stmt = $conn->prepare(
        "UPDATE registration_requests
         SET request_status = 'approved',
             admin_remarks = ?,
             reviewed_by = ?,
             reviewed_at = ?,
             business_code = ?,
             business_id = ?,
             business_entity_id = ?,
             branch_code = ?
         WHERE id = ? AND request_status IN ('pending', 'resubmit')"
    );

    if (!$stmt) {
        throw new RuntimeException('Unable to update the request status: ' . $conn->error);
    }

    $stmt->bind_param(
        'sissiisi',
        $finalRemarks,
        $adminId,
        $approvedAt,
        $businessCode,
        $businessId,
        $businessEntityId,
        $branchCode,
        $requestId
    );

    if (!$stmt->execute()) {
        throw new RuntimeException('Failed to update the request status: ' . $stmt->error);
    }

    if ($stmt->affected_rows !== 1) {
        throw new RuntimeException('The request status was not updated. It may have already been processed.');
    }
    $stmt->close();

    $description = "Approved request #{$requestId}" .
        (!empty($request['request_code']) ? " ({$request['request_code']})" : '') .
        " and created user #{$newUserId} for {$businessCode} / {$branchCode} ({$branchName})";

    if (!logAdminActivitySecure(
        $conn,
        $adminId,
        'approve_request',
        'registration_request',
        $requestId,
        $description
    )) {
        throw new RuntimeException('Failed to save the administrator activity log.');
    }

    $conn->commit();

    $_SESSION['last_activity'] = time();

    /* Email errors must never reverse or hide the successful approval. */
    try {
        $emailBody =
            "Hello,\n\n" .
            "Your NexGen account registration has been approved.\n" .
            ($role === 'owner'
                ? "Your SE business code is: {$businessCode}\n" .
                  "Your main branch code is: {$branchCode}\n"
                : "Your account is linked to SE code: {$businessCode}\n" .
                  "Your assigned branch is: {$branchName}\n" .
                  "Your branch code is: {$branchCode}\n") .
            "You may now log in using your registered username and password.\n\n" .
            "Thank you.\n— NexGen System";

        nxSendResendEmail(
            (string)$request['email'],
            (string)($request['full_name'] ?: 'NexGen User'),
            'NexGen Account Registration Approved',
            $emailBody
        );
    } catch (Throwable $mailError) {
        error_log(
            "Approval email failed for request #{$requestId}: " .
            $mailError->getMessage()
        );
    }

    nxApproveRequestRespond(
        true,
        $isOwnerReviewer
            ? 'Employee access request accepted successfully.'
            : 'Registration request approved successfully.',
        $requestId
    );
} catch (Throwable $e) {
    $conn->rollback();

    error_log(
        "Approval failed for request #{$requestId}, admin #{$adminId}: " .
        $e->getMessage()
    );

    $_SESSION['last_activity'] = time();
    nxApproveRequestRespond(
        false,
        'Approval failed: ' . $e->getMessage(),
        $requestId,
        422,
        nxAppUrl('view_request.php') . '?'
            . ($isOwnerReviewer ? 'owner_review=1&' : '')
            . 'id=' . $requestId
    );
}
