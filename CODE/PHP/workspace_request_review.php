<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/resend_config.php';
require_once __DIR__ . '/tenant_helper.php';

if (
    empty($_SESSION['user_id'])
    || empty($_SESSION['role'])
    || $_SESSION['role'] !== 'system_admin'
) {
    header('Location: /NexGen/CODE/PHP/index.php');
    exit();
}

enforceSessionTimeout();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /NexGen/CODE/PHP/pending_requests.php');
    exit();
}

if (!validateCsrfToken('admin_request_action', $_POST['csrf_token'] ?? null)) {
    $_SESSION['flash'] = ['type' => 'notice-error', 'message' => 'Your session expired. Please try again.'];
    header('Location: /NexGen/CODE/PHP/pending_requests.php');
    exit();
}

$requestId = (int)($_POST['request_id'] ?? 0);
$decision = trim((string)($_POST['decision'] ?? ''));
$remarks = trim((string)($_POST['remarks'] ?? ''));
$adminId = (int)$_SESSION['user_id'];

if ($requestId <= 0 || !in_array($decision, ['approve', 'reject'], true)) {
    $_SESSION['flash'] = ['type' => 'notice-error', 'message' => 'Invalid workspace review action.'];
    header('Location: /NexGen/CODE/PHP/pending_requests.php');
    exit();
}

if ($decision === 'reject' && $remarks === '') {
    $_SESSION['flash'] = ['type' => 'notice-error', 'message' => 'A rejection reason is required.'];
    header('Location: /NexGen/CODE/PHP/view_workspace_request.php?id=' . $requestId);
    exit();
}

if (!nxWorkspaceSchemaReady($conn) || !nxWorkspaceRequestSchemaReady($conn)) {
    $_SESSION['flash'] = [
        'type' => 'notice-error',
        'message' => 'The required Multi-Tenancy v3 database migration is incomplete.',
    ];
    header('Location: /NexGen/CODE/PHP/view_workspace_request.php?id=' . $requestId);
    exit();
}

$conn->begin_transaction();

try {
    $stmt = $conn->prepare(
        "SELECT wr.*, u.full_name, u.username, u.email,
                u.role AS requester_role, u.account_status
         FROM workspace_requests wr
         INNER JOIN users u ON u.id = wr.requested_by
         WHERE wr.id = ?
         FOR UPDATE"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to read the workspace request.');
    }
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $request = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$request) {
        throw new RuntimeException('Workspace request not found.');
    }
    if ($request['request_status'] !== 'pending') {
        throw new RuntimeException('Only pending workspace requests can be reviewed.');
    }
    if ($request['requester_role'] !== 'owner' || $request['account_status'] !== 'active') {
        throw new RuntimeException('The requester is no longer an active SME owner.');
    }

    $reviewedAt = date('Y-m-d H:i:s');

    if ($decision === 'reject') {
        $stmt = $conn->prepare(
            "UPDATE workspace_requests
             SET request_status = 'rejected', admin_remarks = ?,
                 reviewed_by = ?, reviewed_at = ?
             WHERE id = ? AND request_status = 'pending'"
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare the rejection update.');
        }
        $stmt->bind_param('sisi', $remarks, $adminId, $reviewedAt, $requestId);
        if (!$stmt->execute() || $stmt->affected_rows !== 1) {
            throw new RuntimeException('The workspace request could not be rejected. It may already have been reviewed.');
        }
        $stmt->close();

        if (!logAdminActivitySecure(
            $conn,
            $adminId,
            'reject_workspace_request',
            'workspace_request',
            $requestId,
            'Rejected workspace request ' . $request['request_code']
        )) {
            throw new RuntimeException('Failed to save the administrator activity log.');
        }

        $conn->commit();
        $_SESSION['flash'] = ['type' => 'notice-success', 'message' => 'Workspace request rejected successfully.'];

        try {
            $emailBody =
                "Hello {$request['full_name']},\n\n" .
                "Your {$request['request_type']} request {$request['request_code']} was rejected.\n\n" .
                "Reason:\n{$remarks}\n\n" .
                "You may submit another business or branch request from Settings.\n\n" .
                "— NexGen System";

            nxSendResendEmail(
                (string)$request['email'],
                (string)($request['full_name'] ?: 'NexGen User'),
                'NexGen Workspace Request Rejected',
                $emailBody
            );
        } catch (Throwable $mailError) {
            error_log('Workspace rejection email failed: ' . $mailError->getMessage());
        }

        header('Location: /NexGen/CODE/PHP/pending_requests.php');
        exit();
    }

    $ownerId = (int)$request['requested_by'];
    $businessName = trim((string)$request['business_name']);
    $businessType = trim((string)$request['business_type']);
    $businessAddress = trim((string)$request['business_address']);
    $branchName = trim((string)$request['branch_name']);
    $businessEntityId = 0;
    $businessId = 0;
    $businessCode = '';
    $branchCode = '';

    if ($request['request_type'] === 'business') {
        $globalStmt = $conn->prepare(
            "SELECT be.business_name, be.business_type, b.business_address
             FROM business_entities be
             INNER JOIN businesses b ON b.business_entity_id = be.id
             WHERE be.status = 'active' AND b.branch_status = 'active'
             FOR UPDATE"
        );
        if (!$globalStmt) {
            throw new RuntimeException('Unable to repeat the duplicate business check.');
        }
        $globalStmt->execute();
        $globalResult = $globalStmt->get_result();
        while ($existing = $globalResult->fetch_assoc()) {
            if (
                nxNormalizeBusinessIdentity($existing['business_name']) === nxNormalizeBusinessIdentity($businessName)
                && nxNormalizeBusinessIdentity($existing['business_type']) === nxNormalizeBusinessIdentity($businessType)
                && nxNormalizeBusinessIdentity($existing['business_address']) === nxNormalizeBusinessIdentity($businessAddress)
            ) {
                $globalStmt->close();
                throw new RuntimeException('Approval blocked: an active business with the same name, type, and address already exists.');
            }
        }
        $globalStmt->close();

        if ((int)$request['separate_operations'] !== 1) {
            $ownedStmt = $conn->prepare(
                "SELECT be.business_name, be.business_type
                 FROM user_business_assignments uba
                 INNER JOIN business_entities be ON be.id = uba.business_entity_id
                 WHERE uba.user_id = ? AND uba.assignment_role = 'owner'
                   AND uba.status = 'active' AND be.status = 'active'
                 FOR UPDATE"
            );
            if (!$ownedStmt) {
                throw new RuntimeException('Unable to validate the owner\'s existing businesses.');
            }
            $ownedStmt->bind_param('i', $ownerId);
            $ownedStmt->execute();
            $ownedResult = $ownedStmt->get_result();
            while ($owned = $ownedResult->fetch_assoc()) {
                if (
                    nxNormalizeBusinessIdentity($owned['business_name']) === nxNormalizeBusinessIdentity($businessName)
                    && nxNormalizeBusinessIdentity($owned['business_type']) === nxNormalizeBusinessIdentity($businessType)
                ) {
                    $ownedStmt->close();
                    throw new RuntimeException('Approval blocked: this should be submitted as a branch unless separate operations are confirmed.');
                }
            }
            $ownedStmt->close();
        }

        $businessCode = nxGenerateUniqueWorkspaceCode($conn, 'business');
        $branchCode = nxGenerateUniqueWorkspaceCode($conn, 'branch');

        $stmt = $conn->prepare(
            "INSERT INTO business_entities
                (business_code, business_name, business_type, created_by, status)
             VALUES (?, ?, ?, ?, 'active')"
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare the approved business.');
        }
        $stmt->bind_param('sssi', $businessCode, $businessName, $businessType, $ownerId);
        if (!$stmt->execute()) {
            throw new RuntimeException('Unable to create the approved business: ' . $stmt->error);
        }
        $businessEntityId = (int)$stmt->insert_id;
        $stmt->close();

        $stmt = $conn->prepare(
            "INSERT INTO businesses
                (business_name, business_type, business_address, business_code,
                 business_entity_id, branch_code, branch_name, is_main_branch, branch_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, 'active')"
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare the approved main branch.');
        }
        $stmt->bind_param(
            'ssssiss',
            $businessName,
            $businessType,
            $businessAddress,
            $businessCode,
            $businessEntityId,
            $branchCode,
            $branchName
        );
        if (!$stmt->execute()) {
            throw new RuntimeException('Unable to create the approved main branch: ' . $stmt->error);
        }
        $businessId = (int)$stmt->insert_id;
        $stmt->close();

        $stmt = $conn->prepare(
            "INSERT INTO user_business_assignments
                (user_id, business_entity_id, assignment_role, is_default, status)
             VALUES (?, ?, 'owner', 0, 'active')
             ON DUPLICATE KEY UPDATE assignment_role = 'owner', status = 'active'"
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to assign the approved business to the owner.');
        }
        $stmt->bind_param('ii', $ownerId, $businessEntityId);
        if (!$stmt->execute()) {
            throw new RuntimeException('Unable to save the owner business assignment: ' . $stmt->error);
        }
        $stmt->close();
    } elseif ($request['request_type'] === 'branch') {
        $businessEntityId = (int)$request['business_entity_id'];
        $stmt = $conn->prepare(
            "SELECT be.business_code, be.business_name, be.business_type
             FROM business_entities be
             INNER JOIN user_business_assignments uba ON uba.business_entity_id = be.id
             WHERE be.id = ? AND uba.user_id = ?
               AND uba.assignment_role = 'owner'
               AND uba.status = 'active' AND be.status = 'active'
             LIMIT 1
             FOR UPDATE"
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to verify the parent business.');
        }
        $stmt->bind_param('ii', $businessEntityId, $ownerId);
        $stmt->execute();
        $entity = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$entity) {
            throw new RuntimeException('The owner no longer owns the selected active business.');
        }

        $businessCode = (string)$entity['business_code'];
        $businessName = (string)$entity['business_name'];
        $businessType = (string)$entity['business_type'];

        $stmt = $conn->prepare(
            "SELECT branch_name, business_address
             FROM businesses
             WHERE business_entity_id = ? AND branch_status = 'active'
             FOR UPDATE"
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to repeat the duplicate branch check.');
        }
        $stmt->bind_param('i', $businessEntityId);
        $stmt->execute();
        $branches = $stmt->get_result();
        while ($branch = $branches->fetch_assoc()) {
            if (nxNormalizeBusinessIdentity($branch['business_address']) === nxNormalizeBusinessIdentity($businessAddress)) {
                $stmt->close();
                throw new RuntimeException('Approval blocked: that address already belongs to branch ' . $branch['branch_name'] . '.');
            }
        }
        $stmt->close();

        $branchCode = nxGenerateUniqueWorkspaceCode($conn, 'branch');
        $stmt = $conn->prepare(
            "INSERT INTO businesses
                (business_name, business_type, business_address, business_code,
                 business_entity_id, branch_code, branch_name, is_main_branch, branch_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, 'active')"
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare the approved branch.');
        }
        $stmt->bind_param(
            'ssssiss',
            $businessName,
            $businessType,
            $businessAddress,
            $businessCode,
            $businessEntityId,
            $branchCode,
            $branchName
        );
        if (!$stmt->execute()) {
            throw new RuntimeException('Unable to create the approved branch: ' . $stmt->error);
        }
        $businessId = (int)$stmt->insert_id;
        $stmt->close();
    } else {
        throw new RuntimeException('The workspace request type is invalid.');
    }

    $stmt = $conn->prepare(
        "INSERT INTO user_branch_assignments
            (user_id, business_id, is_primary, status)
         VALUES (?, ?, 0, 'active')
         ON DUPLICATE KEY UPDATE status = 'active'"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to assign the approved branch to the owner.');
    }
    $stmt->bind_param('ii', $ownerId, $businessId);
    if (!$stmt->execute()) {
        throw new RuntimeException('Unable to save the owner branch assignment: ' . $stmt->error);
    }
    $stmt->close();

    $finalRemarks = $remarks !== '' ? $remarks : 'Approved by system administrator';
    $stmt = $conn->prepare(
        "UPDATE workspace_requests
         SET request_status = 'approved', admin_remarks = ?,
             reviewed_by = ?, reviewed_at = ?,
             approved_business_entity_id = ?, approved_business_id = ?,
             business_code = ?, branch_code = ?
         WHERE id = ? AND request_status = 'pending'"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the workspace request approval.');
    }
    $stmt->bind_param(
        'sisiissi',
        $finalRemarks,
        $adminId,
        $reviewedAt,
        $businessEntityId,
        $businessId,
        $businessCode,
        $branchCode,
        $requestId
    );
    if (!$stmt->execute() || $stmt->affected_rows !== 1) {
        throw new RuntimeException('The workspace approval was not saved. It may already have been reviewed.');
    }
    $stmt->close();

    if (!logAdminActivitySecure(
        $conn,
        $adminId,
        'approve_workspace_request',
        'workspace_request',
        $requestId,
        "Approved {$request['request_code']} and created {$businessCode} / {$branchCode}"
    )) {
        throw new RuntimeException('Failed to save the administrator activity log.');
    }

    $conn->commit();
    $_SESSION['flash'] = ['type' => 'notice-success', 'message' => 'Workspace request approved successfully.'];
    $_SESSION['last_activity'] = time();

    try {
        $emailBody =
            "Hello {$request['full_name']},\n\n" .
            "Your {$request['request_type']} request {$request['request_code']} has been approved.\n" .
            "Business code: {$businessCode}\n" .
            "Branch code: {$branchCode}\n\n" .
            "The approved workspace is now available from your Businesses & Branches settings.\n\n" .
            "— NexGen System";

        nxSendResendEmail(
            (string)$request['email'],
            (string)($request['full_name'] ?: 'NexGen User'),
            'NexGen Workspace Request Approved',
            $emailBody
        );
    } catch (Throwable $mailError) {
        error_log('Workspace approval email failed: ' . $mailError->getMessage());
    }

    header('Location: /NexGen/CODE/PHP/pending_requests.php');
    exit();
} catch (Throwable $e) {
    $conn->rollback();
    error_log(
        "Workspace review failed for request #{$requestId}, admin #{$adminId}: " . $e->getMessage()
    );
    $_SESSION['flash'] = ['type' => 'notice-error', 'message' => 'Workspace review failed: ' . $e->getMessage()];
    $_SESSION['last_activity'] = time();
    header('Location: /NexGen/CODE/PHP/view_workspace_request.php?id=' . $requestId);
    exit();
}
