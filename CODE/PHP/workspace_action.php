<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/tenant_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['user_id'])) {
    header('Location: /NexGen/CODE/PHP/index.php');
    exit();
}

if (!validateCsrfToken('workspace_action', $_POST['csrf_token'] ?? null)) {
    $_SESSION['error'] = 'Your workspace form expired. Please try again.';
    header('Location: /NexGen/CODE/PHP/settings.php');
    exit();
}

function nxWorkspaceRedirectTarget(?string $requested): string
{
    $allowed = [
        'dashboard.php',
        'inventory_management.php',
        'sales_recording.php',
        'sales_analytics.php',
        'accounts_receivable.php',
        'settings.php',
    ];

    $page = basename((string)$requested);

    return in_array($page, $allowed, true)
        ? '/NexGen/CODE/PHP/' . $page
        : '/NexGen/CODE/PHP/dashboard.php';
}

function nxWorkspaceFail(string $message, string $returnTo): void
{
    $_SESSION['error'] = $message;
    header('Location: ' . $returnTo);
    exit();
}

function nxWorkspaceTextLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

$returnTo = nxWorkspaceRedirectTarget($_POST['return_to'] ?? 'dashboard.php');
$action = trim((string)($_POST['action'] ?? ''));
$userId = (int)$_SESSION['user_id'];
$role = (string)($_SESSION['role'] ?? '');

if (!nxWorkspaceSchemaReady($conn)) {
    nxWorkspaceFail(
        'The business-and-branch database migration must be run before using workspace management.',
        $returnTo
    );
}

try {
    if ($action === 'switch_workspace') {
        $businessId = (int)($_POST['business_id'] ?? 0);
        if ($businessId <= 0) {
            throw new RuntimeException('Please select a valid business branch.');
        }

        $workspace = nxSetActiveWorkspace($conn, $businessId);
        $label = trim((string)$workspace['business_name'] . ' — ' . (string)$workspace['branch_name']);
        $_SESSION['success'] = 'Workspace switched to ' . $label . '.';

        logActivity(
            $conn,
            $userId,
            (string)($_SESSION['username'] ?? ''),
            $role,
            'switch_workspace',
            'business_branch',
            $businessId,
            'Switched active workspace to ' . $label
        );

        header('Location: ' . $returnTo);
        exit();
    }

    if ($role !== 'owner') {
        throw new RuntimeException('Only an SME owner can request another business or branch.');
    }

    if (!nxWorkspaceRequestSchemaReady($conn)) {
        throw new RuntimeException(
            'The Multi-Tenancy v3 approval migration must be run before submitting business or branch requests.'
        );
    }

    $pendingRequest = nxGetPendingWorkspaceRequest($conn, $userId);
    if ($pendingRequest) {
        throw new RuntimeException(
            'You already have a pending request (' . $pendingRequest['request_code'] .
            '). Please wait for the administrator\'s review before submitting another request.'
        );
    }

    $allowedBusinessTypes = [
        'Hardware / Construction Supplies',
        'Mini Grocery / Sari-Sari Store',
        'Pharmacy / Drugstore',
        'School / Office Supplies',
    ];

    $requestType = '';
    $entityId = null;
    $businessName = '';
    $businessType = '';
    $businessAddress = '';
    $branchName = '';
    $separateOperations = 0;

    if ($action === 'add_business') {
        $requestType = 'business';
        $businessName = trim((string)($_POST['business_name'] ?? ''));
        $businessType = trim((string)($_POST['business_type'] ?? ''));
        $businessAddress = trim((string)($_POST['business_address'] ?? ''));
        $branchName = trim((string)($_POST['branch_name'] ?? 'Main Branch'));
        $separateOperations = isset($_POST['separate_operations']) ? 1 : 0;

        if ($businessName === '' || $businessType === '' || $businessAddress === '') {
            throw new RuntimeException('Business name, SME type, and business address are required.');
        }
        if (!in_array($businessType, $allowedBusinessTypes, true)) {
            throw new RuntimeException('Please select a valid SME type.');
        }
        if ($branchName === '') {
            $branchName = 'Main Branch';
        }
        if (nxWorkspaceTextLength($businessName) > 150 || nxWorkspaceTextLength($branchName) > 150) {
            throw new RuntimeException('Business and branch names must not exceed 150 characters.');
        }

        $globalStmt = $conn->prepare(
            "SELECT be.business_name, be.business_type, b.business_address, b.branch_name
             FROM business_entities be
             INNER JOIN businesses b ON b.business_entity_id = be.id
             WHERE be.status = 'active' AND b.branch_status = 'active'"
        );
        if (!$globalStmt) {
            throw new RuntimeException('Unable to run the duplicate business check.');
        }
        $globalStmt->execute();
        $globalResult = $globalStmt->get_result();
        while ($existing = $globalResult->fetch_assoc()) {
            $sameName = nxNormalizeBusinessIdentity($existing['business_name']) === nxNormalizeBusinessIdentity($businessName);
            $sameType = nxNormalizeBusinessIdentity($existing['business_type']) === nxNormalizeBusinessIdentity($businessType);
            $sameAddress = nxNormalizeBusinessIdentity($existing['business_address']) === nxNormalizeBusinessIdentity($businessAddress);

            if ($sameName && $sameType && $sameAddress) {
                $globalStmt->close();
                throw new RuntimeException(
                    'Possible duplicate: that exact business name, SME type, and address already exist. Review the existing workspace instead.'
                );
            }
        }
        $globalStmt->close();

        $ownedStmt = $conn->prepare(
            "SELECT be.business_name, be.business_type
             FROM user_business_assignments uba
             INNER JOIN business_entities be ON be.id = uba.business_entity_id
             WHERE uba.user_id = ? AND uba.assignment_role = 'owner'
               AND uba.status = 'active' AND be.status = 'active'"
        );
        if (!$ownedStmt) {
            throw new RuntimeException('Unable to check your existing businesses.');
        }
        $ownedStmt->bind_param('i', $userId);
        $ownedStmt->execute();
        $ownedResult = $ownedStmt->get_result();
        $sameIdentityFound = false;
        while ($owned = $ownedResult->fetch_assoc()) {
            if (
                nxNormalizeBusinessIdentity($owned['business_name']) === nxNormalizeBusinessIdentity($businessName)
                && nxNormalizeBusinessIdentity($owned['business_type']) === nxNormalizeBusinessIdentity($businessType)
            ) {
                $sameIdentityFound = true;
                break;
            }
        }
        $ownedStmt->close();

        if ($sameIdentityFound && !$separateOperations) {
            throw new RuntimeException(
                'This name and SME type already belong to one of your businesses. Use Add Branch for another location, or confirm separate operations.'
            );
        }
    } elseif ($action === 'add_branch') {
        $requestType = 'branch';
        $entityId = (int)($_POST['business_entity_id'] ?? 0);
        $branchName = trim((string)($_POST['branch_name'] ?? ''));
        $businessAddress = trim((string)($_POST['branch_address'] ?? ''));

        if ($entityId <= 0 || $branchName === '' || $businessAddress === '') {
            throw new RuntimeException('Select a business and provide the new branch name and address.');
        }
        if (nxWorkspaceTextLength($branchName) > 150) {
            throw new RuntimeException('The branch name must not exceed 150 characters.');
        }

        $entityStmt = $conn->prepare(
            "SELECT be.id, be.business_name, be.business_type
             FROM business_entities be
             INNER JOIN user_business_assignments uba ON uba.business_entity_id = be.id
             WHERE be.id = ? AND uba.user_id = ?
               AND uba.assignment_role = 'owner'
               AND uba.status = 'active' AND be.status = 'active'
             LIMIT 1"
        );
        if (!$entityStmt) {
            throw new RuntimeException('Unable to verify the selected business.');
        }
        $entityStmt->bind_param('ii', $entityId, $userId);
        $entityStmt->execute();
        $entity = $entityStmt->get_result()->fetch_assoc();
        $entityStmt->close();

        if (!$entity) {
            throw new RuntimeException('You do not own the selected business.');
        }

        $businessName = (string)$entity['business_name'];
        $businessType = (string)$entity['business_type'];

        $branchesStmt = $conn->prepare(
            "SELECT branch_name, business_address
             FROM businesses
             WHERE business_entity_id = ? AND branch_status = 'active'"
        );
        if (!$branchesStmt) {
            throw new RuntimeException('Unable to check existing branches.');
        }
        $branchesStmt->bind_param('i', $entityId);
        $branchesStmt->execute();
        $branchesResult = $branchesStmt->get_result();
        while ($branch = $branchesResult->fetch_assoc()) {
            if (nxNormalizeBusinessIdentity($branch['business_address']) === nxNormalizeBusinessIdentity($businessAddress)) {
                $branchesStmt->close();
                throw new RuntimeException(
                    'Possible duplicate: that address already belongs to branch ' . $branch['branch_name'] . '.'
                );
            }
        }
        $branchesStmt->close();
    } else {
        throw new RuntimeException('Invalid workspace action.');
    }

    $requestCode = nxGenerateWorkspaceRequestCode($conn);

    $conn->begin_transaction();
    try {
        $insert = $conn->prepare(
            "INSERT INTO workspace_requests
                (request_code, requested_by, request_type, business_entity_id,
                 business_name, business_type, business_address, branch_name,
                 separate_operations, request_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
        );
        if (!$insert) {
            throw new RuntimeException('Unable to prepare the workspace request.');
        }
        $insert->bind_param(
            'sisissssi',
            $requestCode,
            $userId,
            $requestType,
            $entityId,
            $businessName,
            $businessType,
            $businessAddress,
            $branchName,
            $separateOperations
        );
        if (!$insert->execute()) {
            $insertError = $insert->error;
            $insertErrno = (int)$insert->errno;
            $insert->close();
            if ($insertErrno === 1062) {
                throw new RuntimeException(
                    'You already have a pending request. Please wait for the administrator\'s review before submitting another request.',
                    1062
                );
            }
            throw new RuntimeException('Unable to submit the workspace request: ' . $insertError, $insertErrno);
        }
        $requestId = (int)$insert->insert_id;
        $insert->close();

        logActivity(
            $conn,
            $userId,
            (string)($_SESSION['username'] ?? ''),
            $role,
            'submit_workspace_request',
            'workspace_request',
            $requestId,
            'Submitted ' . $requestType . ' request ' . $requestCode . ' for administrator review'
        );

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    $_SESSION['success'] =
        "Request {$requestCode} submitted for administrator review. You cannot submit another business or branch request while this request is pending.";
    header('Location: /NexGen/CODE/PHP/settings.php?panel=workspace-panel');
    exit();
} catch (Throwable $e) {
    $message = $e->getMessage();
    if ((int)$e->getCode() === 1062 && stripos($message, 'uq_workspace_one_pending_per_account') !== false) {
        $message = 'You already have a pending request. Please wait for the administrator\'s review before submitting another request.';
    }
    nxWorkspaceFail($message, $returnTo);
}
