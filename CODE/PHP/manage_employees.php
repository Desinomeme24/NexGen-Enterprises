<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/tenant_helper.php';

enforceSessionTimeout();

$ownerId = (int)($_SESSION['user_id'] ?? 0);
if ($ownerId <= 0 || (string)($_SESSION['role'] ?? '') !== 'owner') {
    header('Location: ' . nxAppUrl('dashboard.php'));
    exit();
}

$ownerStmt = $conn->prepare(
    "SELECT id
     FROM users
     WHERE id = ? AND role = 'owner' AND account_status = 'active'
     LIMIT 1"
);
if (!$ownerStmt) {
    error_log('NexGen Manage Employees owner verification failed: ' . $conn->error);
    http_response_code(503);
    exit('Employee management is temporarily unavailable.');
}
$ownerStmt->bind_param('i', $ownerId);
$ownerStmt->execute();
$activeOwner = (bool)$ownerStmt->get_result()->fetch_assoc();
$ownerStmt->close();
if (!$activeOwner) {
    header('Location: ' . nxAppUrl('dashboard.php'));
    exit();
}

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'employee_access_action') {
    $csrfToken = $_POST['csrf_token'] ?? null;
    if (!is_string($csrfToken) || !validateCsrfToken('manage_employees_action', $csrfToken)) {
        $_SESSION['flash'] = [
            'type' => 'notice-error',
            'message' => 'Your session expired. Please try again.',
        ];
    } else {
        $employeeIdInput = $_POST['employee_id'] ?? '';
        $employeeAction = $_POST['employee_action'] ?? '';
        $employeeId = is_string($employeeIdInput) && ctype_digit($employeeIdInput)
            ? (int)$employeeIdInput
            : 0;

        if (
            $employeeId <= 0
            || !is_string($employeeAction)
            || !in_array($employeeAction, ['disable_employee', 'enable_employee', 'unlock_employee'], true)
        ) {
            $_SESSION['flash'] = [
                'type' => 'notice-error',
                'message' => 'Invalid employee access action.',
            ];
        } else {
            $transactionOpen = false;
            try {
                $ownedEntityIds = nxGetOwnedBusinessEntityIds($conn, $ownerId);
                if ($ownedEntityIds === []) {
                    throw new DomainException('Employee account was not found in your business.');
                }
                if (!$conn->begin_transaction()) {
                    throw new RuntimeException('Unable to begin the employee account update.');
                }
                $transactionOpen = true;

                $placeholders = implode(',', array_fill(0, count($ownedEntityIds), '?'));
                $targetStmt = $conn->prepare(
                    "SELECT u.id, u.username, u.account_status, u.locked_until
                     FROM users u
                     INNER JOIN businesses b ON b.id = u.business_id
                     INNER JOIN business_entities be ON be.id = b.business_entity_id
                     WHERE u.id = ?
                       AND u.role = 'employee'
                       AND be.id IN ({$placeholders})
                     LIMIT 1
                     FOR UPDATE"
                );
                if (!$targetStmt) {
                    throw new RuntimeException('Unable to prepare the employee account lookup: ' . $conn->error);
                }
                $targetParams = array_merge([$employeeId], $ownedEntityIds);
                $targetStmt->bind_param('i' . str_repeat('i', count($ownedEntityIds)), ...$targetParams);
                if (!$targetStmt->execute()) {
                    throw new RuntimeException('Unable to load the employee account: ' . $targetStmt->error);
                }
                $targetEmployee = $targetStmt->get_result()->fetch_assoc();
                $targetStmt->close();

                if (!$targetEmployee) {
                    throw new DomainException('Employee account was not found in your business.');
                }

                $storedAccountStatus = strtolower(trim((string)$targetEmployee['account_status']));
                $isLocked = isUserLocked($targetEmployee);
                $targetStatus = '';

                if ($employeeAction === 'unlock_employee') {
                    if (!$isLocked) {
                        throw new DomainException('This employee account is not currently locked.');
                    }

                    $updateStmt = $conn->prepare(
                        "UPDATE users
                         SET failed_login_attempts = 0,
                             locked_until = NULL,
                             last_failed_login_at = NULL
                         WHERE id = ? AND role = 'employee'"
                    );
                    if (!$updateStmt) {
                        throw new RuntimeException('Unable to prepare the employee unlock: ' . $conn->error);
                    }
                    $updateStmt->bind_param('i', $employeeId);
                    $activityAction = 'unlock_employee_account';
                    $successMessage = 'Employee account unlocked and failed login attempts reset.';
                    $activityVerb = 'Unlocked';
                } else {
                    if ($isLocked) {
                        throw new DomainException('Unlock this employee account before changing its access status.');
                    }

                    if ($employeeAction === 'disable_employee') {
                        if ($storedAccountStatus !== 'active') {
                            throw new DomainException('This employee account is no longer active.');
                        }
                        $targetStatus = 'disabled';
                        $activityAction = 'disable_employee_account';
                        $successMessage = 'Employee account disabled.';
                        $activityVerb = 'Disabled';
                    } else {
                        if (!in_array($storedAccountStatus, ['inactive', 'disabled'], true)) {
                            throw new DomainException('This employee account is not disabled.');
                        }
                        $targetStatus = 'active';
                        $activityAction = 'enable_employee_account';
                        $successMessage = 'Employee account enabled.';
                        $activityVerb = 'Enabled';
                    }

                    $updateStmt = $conn->prepare(
                        "UPDATE users
                         SET account_status = ?
                         WHERE id = ? AND role = 'employee' AND account_status = ?"
                    );
                    if (!$updateStmt) {
                        throw new RuntimeException('Unable to prepare the employee access update: ' . $conn->error);
                    }
                    $updateStmt->bind_param('sis', $targetStatus, $employeeId, $storedAccountStatus);
                }

                if (!$updateStmt->execute()) {
                    throw new RuntimeException('Unable to update the employee account: ' . $updateStmt->error);
                }
                if ($updateStmt->affected_rows !== 1) {
                    $updateStmt->close();
                    throw new DomainException('The employee account changed. Refresh the page and try again.');
                }
                $updateStmt->close();

                $description = "{$activityVerb} employee account #{$employeeId} ({$targetEmployee['username']}).";
                if (!logAdminActivitySecure(
                    $conn,
                    $ownerId,
                    $activityAction,
                    'user',
                    $employeeId,
                    $description
                )) {
                    throw new RuntimeException('Unable to record the employee account action.');
                }
                if (!$conn->commit()) {
                    throw new RuntimeException('Unable to complete the employee account update.');
                }
                $transactionOpen = false;
                $_SESSION['flash'] = [
                    'type' => 'notice-success',
                    'message' => $successMessage,
                ];
            } catch (DomainException $error) {
                if ($transactionOpen) {
                    $conn->rollback();
                }
                $_SESSION['flash'] = [
                    'type' => 'notice-error',
                    'message' => $error->getMessage(),
                ];
            } catch (Throwable $error) {
                if ($transactionOpen) {
                    $conn->rollback();
                }
                error_log('NexGen Manage Employees access action failed: ' . $error->getMessage());
                $_SESSION['flash'] = [
                    'type' => 'notice-error',
                    'message' => 'Unable to update the employee account. Please try again.',
                ];
            }
        }
    }

    header('Location: ' . nxAppUrl('manage_employees.php'));
    exit();
}

$employeeActionToken = generateCsrfToken('manage_employees_action');

$status = (string)($_GET['status'] ?? 'all');
$status = in_array($status, ['all', 'pending', 'resubmit'], true) ? $status : 'all';
$search = trim((string)($_GET['search'] ?? ''));
$employeeStatus = (string)($_GET['employee_status'] ?? 'all');
$employeeStatus = in_array($employeeStatus, ['all', 'active', 'inactive', 'disabled'], true)
    ? $employeeStatus
    : 'all';
$employeeSearch = trim((string)($_GET['employee_search'] ?? ''));
$employeeBranchId = ctype_digit((string)($_GET['employee_branch'] ?? '0'))
    ? (int)($_GET['employee_branch'] ?? 0)
    : 0;
$requests = [];
$employees = [];
$branches = [];

$employeeActivitySql = "CASE
    WHEN LOWER(COALESCE(NULLIF(TRIM(u.account_status), ''), 'inactive')) <> 'active'
        THEN LOWER(COALESCE(NULLIF(TRIM(u.account_status), ''), 'inactive'))
    WHEN COALESCE(u.last_login_at, u.approved_at, u.created_at) <= DATE_SUB(NOW(), INTERVAL 7 DAY)
        THEN 'inactive'
    ELSE 'active'
END";

try {
    $ownedEntityIds = nxGetOwnedBusinessEntityIds($conn, $ownerId);
    if ($ownedEntityIds !== []) {
        $placeholders = implode(',', array_fill(0, count($ownedEntityIds), '?'));
        $sql =
            "SELECT rr.id, rr.request_code, rr.full_name, rr.username, rr.email,
                    rr.business_code, rr.branch_code, rr.request_status, rr.created_at,
                    be.business_name, b.branch_name
             FROM registration_requests rr
             INNER JOIN businesses b
                ON b.id = rr.business_id
               AND (rr.business_entity_id IS NULL OR b.business_entity_id = rr.business_entity_id)
             INNER JOIN business_entities be
                ON be.id = b.business_entity_id
             WHERE rr.requested_role = 'employee'
               AND rr.request_status IN ('pending', 'resubmit')
               AND be.id IN ({$placeholders})
               AND be.business_code = rr.business_code
               AND b.branch_code = rr.branch_code";
        $params = $ownedEntityIds;
        $types = str_repeat('i', count($ownedEntityIds));

        if ($status !== 'all') {
            $sql .= ' AND rr.request_status = ?';
            $params[] = $status;
            $types .= 's';
        }
        if ($search !== '') {
            $sql .= ' AND (rr.full_name LIKE ? OR rr.username LIKE ? OR rr.email LIKE ? OR be.business_name LIKE ? OR b.branch_name LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like, $like);
            $types .= 'sssss';
        }
        $sql .= ' ORDER BY rr.created_at DESC, rr.id DESC';

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare the employee request list: ' . $conn->error);
        }
        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) {
            throw new RuntimeException('Unable to load employee requests: ' . $stmt->error);
        }
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $requests[] = $row;
        }
        $stmt->close();

        $branchStmt = $conn->prepare(
            "SELECT b.id, b.branch_name, b.branch_code, be.business_name
             FROM businesses b
             INNER JOIN business_entities be ON be.id = b.business_entity_id
             WHERE be.id IN ({$placeholders})
             ORDER BY be.business_name ASC, b.is_main_branch DESC, b.branch_name ASC"
        );
        if (!$branchStmt) {
            throw new RuntimeException('Unable to prepare the owner branch list: ' . $conn->error);
        }
        $branchStmt->bind_param(str_repeat('i', count($ownedEntityIds)), ...$ownedEntityIds);
        if (!$branchStmt->execute()) {
            throw new RuntimeException('Unable to load owner branches: ' . $branchStmt->error);
        }
        $branchResult = $branchStmt->get_result();
        while ($row = $branchResult->fetch_assoc()) {
            $branches[] = $row;
        }
        $branchStmt->close();

        $employeeSql =
            "SELECT u.id, u.full_name, u.username, u.employee_no, u.email, u.phone,
                    u.last_login_at, u.account_status, u.locked_until,
                    {$employeeActivitySql} AS activity_status,
                    be.business_name, be.business_code, b.branch_name, b.branch_code
             FROM users u
             INNER JOIN businesses b ON b.id = u.business_id
             INNER JOIN business_entities be ON be.id = b.business_entity_id
             WHERE u.role = 'employee'
               AND be.id IN ({$placeholders})";
        $employeeParams = $ownedEntityIds;
        $employeeTypes = str_repeat('i', count($ownedEntityIds));

        if ($employeeSearch !== '') {
            $employeeSql .= ' AND (
                u.full_name LIKE ? OR u.username LIKE ? OR u.employee_no LIKE ?
                OR u.email LIKE ? OR u.phone LIKE ? OR be.business_name LIKE ?
                OR b.branch_name LIKE ? OR be.business_code LIKE ? OR b.branch_code LIKE ?
            )';
            $employeeLike = '%' . $employeeSearch . '%';
            array_push(
                $employeeParams,
                $employeeLike,
                $employeeLike,
                $employeeLike,
                $employeeLike,
                $employeeLike,
                $employeeLike,
                $employeeLike,
                $employeeLike,
                $employeeLike
            );
            $employeeTypes .= 'sssssssss';
        }
        if ($employeeBranchId > 0) {
            $employeeSql .= ' AND b.id = ?';
            $employeeParams[] = $employeeBranchId;
            $employeeTypes .= 'i';
        }
        if ($employeeStatus !== 'all') {
            $employeeSql .= " AND ({$employeeActivitySql}) = ?";
            $employeeParams[] = $employeeStatus;
            $employeeTypes .= 's';
        }
        $employeeSql .= ' ORDER BY be.business_name ASC, b.branch_name ASC, u.full_name ASC, u.id ASC';

        $employeeStmt = $conn->prepare($employeeSql);
        if (!$employeeStmt) {
            throw new RuntimeException('Unable to prepare the employee directory: ' . $conn->error);
        }
        $employeeStmt->bind_param($employeeTypes, ...$employeeParams);
        if (!$employeeStmt->execute()) {
            throw new RuntimeException('Unable to load enrolled employees: ' . $employeeStmt->error);
        }
        $employeeResult = $employeeStmt->get_result();
        while ($row = $employeeResult->fetch_assoc()) {
            $employees[] = $row;
        }
        $employeeStmt->close();
    }
} catch (Throwable $error) {
    error_log('NexGen Manage Employees lookup failed: ' . $error->getMessage());
    http_response_code(503);
    exit('Employee management is temporarily unavailable. Please try again later.');
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php include __DIR__ . '/nexgen_favicon/favicon.php'; ?>
    <?php include __DIR__ . '/theme_init.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Employees - NexGen</title>
    <link rel="stylesheet" href="<?php echo e(nxCodeUrl('STYLE/header.css')); ?>">
    <link rel="stylesheet" href="<?php echo e(nxCodeUrl('STYLE/owner_modules.css')); ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body>
<div class="owner-module-shell">
    <?php include __DIR__ . '/header.php'; ?>

    <main class="owner-module-main">
        <div class="owner-module-heading">
            <h1><i class="bi bi-people-fill"></i> Manage Employees</h1>
            <div>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="owner-module-card" role="status" style="padding:14px 18px;margin-bottom:18px;">
                <?php echo e($flash['message'] ?? ''); ?>
            </div>
        <?php endif; ?>

        <section class="owner-module-card" aria-labelledby="employeeRequestsHeading">
            <div class="owner-module-section-heading">
                <div>
                    <h2 id="employeeRequestsHeading"><i class="bi bi-person-lines-fill"></i> Access Requests</h2>
                   
                </div>
            </div>
            <div class="owner-module-toolbar">
                <form method="GET" action="<?php echo e(nxAppUrl('manage_employees.php')); ?>" data-auto-submit-filters>
                    <input type="hidden" name="employee_search" value="<?php echo e($employeeSearch); ?>">
                    <input type="hidden" name="employee_status" value="<?php echo e($employeeStatus); ?>">
                    <input type="hidden" name="employee_branch" value="<?php echo $employeeBranchId; ?>">
                    <label class="visually-hidden" for="employeeRequestSearch">Search employee requests</label>
                    <input id="employeeRequestSearch" name="search" value="<?php echo e($search); ?>" placeholder="Search name, account, or branch" autocomplete="off">
                    <label class="visually-hidden" for="employeeRequestStatus">Filter by status</label>
                    <select id="employeeRequestStatus" name="status">
                        <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All open requests</option>
                        <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="resubmit" <?php echo $status === 'resubmit' ? 'selected' : ''; ?>>Resubmission</option>
                    </select>
                </form>
                <span class="owner-module-count"><?php echo count($requests); ?> request<?php echo count($requests) === 1 ? '' : 's'; ?></span>
            </div>

            <div class="owner-module-table-wrap owner-employee-request-table-wrap">
                <table class="owner-module-table owner-employee-request-table">
                    <thead>
                        <tr>
                            <th>Applicant</th>
                            <th>Business / Branch</th>
                            <th>Status</th>
                            <th>Submitted</th>
                            <th>Review</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($requests === []): ?>
                        <tr>
                            <td colspan="5" class="owner-module-empty">
                                <i class="bi bi-check2-circle" aria-hidden="true"></i>
                                No employee requests match this filter.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($requests as $request): ?>
                            <tr class="owner-employee-request-card">
                                <td data-label="Applicant">
                                    <strong><?php echo e($request['full_name']); ?></strong>
                                    <span class="owner-module-muted">@<?php echo e($request['username']); ?> · <?php echo e($request['email']); ?></span>
                                </td>
                                <td data-label="Business / Branch">
                                    <?php echo e($request['business_name']); ?>
                                    <span class="owner-module-muted"><?php echo e($request['branch_name']); ?> · <?php echo e($request['business_code']); ?> / <?php echo e($request['branch_code']); ?></span>
                                </td>
                                <td data-label="Status">
                                    <span class="owner-module-status <?php echo e($request['request_status']); ?>">
                                        <i class="bi <?php echo $request['request_status'] === 'resubmit' ? 'bi-arrow-repeat' : 'bi-hourglass-split'; ?>" aria-hidden="true"></i>
                                        <?php echo e($request['request_status']); ?>
                                    </span>
                                </td>
                                <td data-label="Submitted"><?php echo e(date('M d, Y', strtotime((string)$request['created_at']))); ?></td>
                                <td data-label="Review">
                                    <a class="owner-module-link" href="<?php echo e(nxAppUrl('view_request.php?owner_review=1&id=' . (int)$request['id'])); ?>">
                                        Review <i class="bi bi-arrow-up-right"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="owner-module-card owner-module-directory" aria-labelledby="enrolledEmployeesHeading">
            <div class="owner-module-section-heading">
                <div>
                    <h2 id="enrolledEmployeesHeading"><i class="bi bi-people-fill" aria-hidden="true"></i> Enrolled Employees</h2>
               
                </div>
            </div>
            <div class="owner-module-toolbar">
                <form method="GET" action="<?php echo e(nxAppUrl('manage_employees.php')); ?>" data-auto-submit-filters>
                    <input type="hidden" name="search" value="<?php echo e($search); ?>">
                    <input type="hidden" name="status" value="<?php echo e($status); ?>">
                    <label class="visually-hidden" for="enrolledEmployeeSearch">Search enrolled employees</label>
                    <input id="enrolledEmployeeSearch" name="employee_search" value="<?php echo e($employeeSearch); ?>" placeholder="Search employee, ID, or branch" autocomplete="off">
                    <label class="visually-hidden" for="enrolledEmployeeStatus">Filter enrolled employees by status</label>
                    <select id="enrolledEmployeeStatus" name="employee_status">
                        <option value="all" <?php echo $employeeStatus === 'all' ? 'selected' : ''; ?>>All employees</option>
                        <option value="active" <?php echo $employeeStatus === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $employeeStatus === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        <option value="disabled" <?php echo $employeeStatus === 'disabled' ? 'selected' : ''; ?>>Disabled accounts</option>
                    </select>
                    <label class="visually-hidden" for="enrolledEmployeeBranch">Filter enrolled employees by branch</label>
                    <select id="enrolledEmployeeBranch" name="employee_branch">
                        <option value="0" <?php echo $employeeBranchId === 0 ? 'selected' : ''; ?>>All business branches</option>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?php echo (int)$branch['id']; ?>" <?php echo $employeeBranchId === (int)$branch['id'] ? 'selected' : ''; ?>>
                                <?php echo e($branch['business_name'] . ' — ' . $branch['branch_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <span class="owner-module-count"><?php echo count($employees); ?> employee<?php echo count($employees) === 1 ? '' : 's'; ?></span>
            </div>

            <div class="owner-module-table-wrap owner-employee-table-wrap">
                <table class="owner-module-table owner-employee-table">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Business / Branch</th>
                            <th>Employee ID</th>
                            <th>Email / Phone</th>
                            <th>Last Sign-in</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($employees === []): ?>
                        <tr>
                            <td colspan="6" class="owner-module-empty">
                                <i class="bi bi-people" aria-hidden="true"></i>
                                No enrolled employees match this filter.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($employees as $employee): ?>
                            <?php
                            $activityStatus = strtolower((string)$employee['activity_status']);
                            $storedAccountStatus = strtolower(trim((string)$employee['account_status']));
                            $isLocked = isUserLocked($employee);
                            $statusClass = $isLocked
                                ? 'locked'
                                : (in_array($activityStatus, ['active', 'inactive'], true) ? $activityStatus : 'disabled');
                            $statusLabel = $isLocked
                                ? 'Locked Out'
                                : ucwords(str_replace('_', ' ', $activityStatus));
                            $statusIcon = $isLocked
                                ? 'bi-lock-fill'
                                : ($statusClass === 'active'
                                    ? 'bi-check-circle-fill'
                                    : ($statusClass === 'inactive' ? 'bi-clock-history' : 'bi-slash-circle-fill'));
                            $canManageAccount = $isLocked
                                || in_array($storedAccountStatus, ['active', 'inactive', 'disabled'], true);
                            ?>
                            <tr class="owner-employee-card">
                                <td data-label="Employee">
                                    <strong><?php echo e($employee['full_name']); ?></strong>
                                    <span class="owner-module-muted">@<?php echo e($employee['username']); ?></span>
                                </td>
                                <td data-label="Business / Branch">
                                    <strong><?php echo e($employee['business_name']); ?></strong>
                                    <span class="owner-module-muted">
                                        <?php echo e($employee['branch_name']); ?>
                                        · <?php echo e($employee['business_code']); ?> / <?php echo e($employee['branch_code']); ?>
                                    </span>
                                </td>
                                <td data-label="Employee ID"><?php echo e($employee['employee_no'] ?: '—'); ?></td>
                                <td data-label="Email / Phone">
                                    <?php echo e($employee['email']); ?>
                                    <span class="owner-module-muted"><?php echo e($employee['phone'] ?: 'No phone number'); ?></span>
                                </td>
                                <td data-label="Last Sign-in">
                                    <?php echo !empty($employee['last_login_at'])
                                        ? e(date('M d, Y h:i A', strtotime((string)$employee['last_login_at'])))
                                        : 'Not Yet Logged In'; ?>
                                </td>
                                <td data-label="Status">
                                    <div class="owner-employee-status-actions">
                                        <span class="owner-module-status <?php echo e($statusClass); ?>">
                                            <i class="bi <?php echo e($statusIcon); ?>" aria-hidden="true"></i>
                                            <?php echo e($statusLabel); ?>
                                        </span>
                                        <?php if ($canManageAccount): ?>
                                            <button
                                                class="owner-employee-action-trigger"
                                                type="button"
                                                data-employee-action-trigger
                                                data-employee-id="<?php echo (int)$employee['id']; ?>"
                                                data-employee-name="<?php echo e($employee['full_name']); ?>"
                                                data-account-status="<?php echo e($storedAccountStatus); ?>"
                                                data-account-locked="<?php echo $isLocked ? 'true' : 'false'; ?>"
                                                aria-label="Manage access for <?php echo e($employee['full_name']); ?>"
                                                aria-haspopup="dialog">
                                                <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <div class="owner-employee-access-modal" data-employee-access-modal hidden>
        <section
            class="owner-employee-access-dialog"
            role="dialog"
            aria-modal="true"
            aria-labelledby="employeeAccessModalTitle"
            aria-describedby="employeeAccessModalDescription"
            tabindex="-1">
            <button class="owner-employee-access-close" type="button" data-modal-close aria-label="Close dialog">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
            <h2 id="employeeAccessModalTitle" data-modal-title>Manage employee access</h2>
            <p id="employeeAccessModalDescription" data-modal-description></p>
            <form method="POST" action="<?php echo e(nxAppUrl('manage_employees.php')); ?>" data-employee-access-form>
                <input type="hidden" name="csrf_token" value="<?php echo e($employeeActionToken); ?>">
                <input type="hidden" name="action" value="employee_access_action">
                <input type="hidden" name="employee_action" data-modal-action>
                <input type="hidden" name="employee_id" data-modal-employee-id>
                <div class="owner-employee-access-buttons">
                    <button class="owner-employee-access-cancel" type="button" data-modal-close>Cancel</button>
                    <button class="owner-employee-access-confirm" type="submit" data-modal-confirm></button>
                </div>
            </form>
        </section>
    </div>
</div>
<script src="<?php echo e(nxCodeUrl('JS/header.js')); ?>" defer></script>
<script>
(() => {
    const scrollKey = 'nexgen.manageEmployees.filterScroll';
    const filterForms = document.querySelectorAll('form[data-auto-submit-filters]');
    const accessModal = document.querySelector('[data-employee-access-modal]');
    const accessDialog = accessModal.querySelector('[role="dialog"]');
    const accessForm = accessModal.querySelector('[data-employee-access-form]');
    const modalTitle = accessModal.querySelector('[data-modal-title]');
    const modalDescription = accessModal.querySelector('[data-modal-description]');
    const modalAction = accessModal.querySelector('[data-modal-action]');
    const modalEmployeeId = accessModal.querySelector('[data-modal-employee-id]');
    const modalConfirm = accessModal.querySelector('[data-modal-confirm]');
    let activeActionTrigger = null;

    const closeAccessModal = () => {
        accessModal.hidden = true;
        document.body.classList.remove('owner-employee-modal-open');
        if (activeActionTrigger) {
            activeActionTrigger.focus();
            activeActionTrigger = null;
        }
    };

    document.querySelectorAll('[data-employee-action-trigger]').forEach((trigger) => {
        trigger.addEventListener('click', () => {
            const accountStatus = trigger.dataset.accountStatus;
            const isLocked = trigger.dataset.accountLocked === 'true';
            let action;
            let actionLabel;
            let description;

            if (isLocked) {
                action = 'unlock_employee';
                actionLabel = 'Unlock Account';
                description = 'Unlock this employee account and reset its failed login attempts. This will not change whether the account is enabled or disabled.';
            } else if (accountStatus === 'active') {
                action = 'disable_employee';
                actionLabel = 'Disable Account';
                description = 'This employee will no longer be able to sign in until the account is enabled again.';
            } else if (accountStatus === 'inactive' || accountStatus === 'disabled') {
                action = 'enable_employee';
                actionLabel = 'Enable Account';
                description = 'This restores sign-in access for the employee account.';
            } else {
                return;
            }

            activeActionTrigger = trigger;
            modalTitle.textContent = actionLabel + ' for ' + (trigger.dataset.employeeName || 'this employee') + '?';
            modalDescription.textContent = description;
            modalAction.value = action;
            modalEmployeeId.value = trigger.dataset.employeeId;
            modalConfirm.textContent = actionLabel;
            modalConfirm.dataset.actionKind = action;
            accessModal.hidden = false;
            document.body.classList.add('owner-employee-modal-open');
            accessDialog.focus();
        });
    });

    accessModal.querySelectorAll('[data-modal-close]').forEach((button) => {
        button.addEventListener('click', closeAccessModal);
    });

    accessModal.addEventListener('click', (event) => {
        if (event.target === accessModal) {
            closeAccessModal();
        }
    });

    accessForm.addEventListener('submit', () => {
        modalConfirm.disabled = true;
        modalConfirm.textContent = 'Processing...';
    });

    document.addEventListener('keydown', (event) => {
        if (accessModal.hidden) {
            return;
        }
        if (event.key === 'Escape') {
            closeAccessModal();
            return;
        }
        if (event.key !== 'Tab') {
            return;
        }

        const focusableElements = Array.from(accessDialog.querySelectorAll(
            'button:not(:disabled), input:not([type="hidden"]):not(:disabled), [tabindex]:not([tabindex="-1"])'
        ));
        if (focusableElements.length === 0) {
            event.preventDefault();
            accessDialog.focus();
            return;
        }
        const firstElement = focusableElements[0];
        const lastElement = focusableElements[focusableElements.length - 1];
        if (!accessDialog.contains(document.activeElement)) {
            event.preventDefault();
            firstElement.focus();
            return;
        }
        if (event.shiftKey && (document.activeElement === firstElement || document.activeElement === accessDialog)) {
            event.preventDefault();
            lastElement.focus();
        } else if (!event.shiftKey && document.activeElement === lastElement) {
            event.preventDefault();
            firstElement.focus();
        }
    });

    filterForms.forEach((form) => {
        form.addEventListener('submit', () => {
            try {
                sessionStorage.setItem(
                    scrollKey,
                    JSON.stringify({ x: window.scrollX, y: window.scrollY })
                );
            } catch (error) {
                console.warn('Could not preserve the Manage Employees scroll position.', error);
            }
        });

        form.querySelectorAll('select').forEach((select) => {
            select.addEventListener('change', () => form.requestSubmit());
        });
    });

    window.addEventListener('pageshow', () => {
        let savedPosition;
        try {
            savedPosition = sessionStorage.getItem(scrollKey);
            sessionStorage.removeItem(scrollKey);
        } catch (error) {
            console.warn('Could not restore the Manage Employees scroll position.', error);
            return;
        }

        if (!savedPosition) {
            return;
        }

        try {
            const position = JSON.parse(savedPosition);
            if (!Number.isFinite(position.x) || !Number.isFinite(position.y)) {
                return;
            }

            document.documentElement.style.setProperty('scroll-behavior', 'auto', 'important');
            window.scrollTo(position.x, position.y);
            window.requestAnimationFrame(() => {
                document.documentElement.style.removeProperty('scroll-behavior');
            });
        } catch (error) {
            console.warn('Could not restore the Manage Employees scroll position.', error);
        }
    }, { once: true });
})();
</script>
</body>
</html>
