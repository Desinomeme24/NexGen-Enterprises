<?php
session_start();
require_once("config.php");
require_once __DIR__ . '/tenant_helper.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: /NexGen/CODE/PHP/index.php");
    exit();
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'system_admin') {
    header("Location: /NexGen/CODE/PHP/dashboard.php");
    exit();
}

if (!function_exists('e')) {
    function e($str) {
        return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
    }
}

function moduleAccessSummary(array $user): string
{
    $modules = [];

    if (!empty($user['can_inventory'])) {
        $modules[] = 'Inventory';
    }

    if (!empty($user['can_sales'])) {
        $modules[] = 'Sales';
    }

    if (!empty($user['can_sales_analytics'])) {
        $modules[] = 'Sales Analytics';
    }

    if (!empty($user['can_accounts_receivable'])) {
        $modules[] = 'Accounts Receivable';
    }

    return empty($modules) ? 'No Access' : implode(', ', $modules);
}

/* ACTIVITY INDICATOR:
   An enabled account becomes visually inactive after seven days without a
   successful login. This is deliberately separate from access control so the
   user can still sign in; login_process.php then refreshes last_login_at and
   the indicator automatically returns to Active. */
function getUserActivityIndicator(array $user): array
{
    $storedStatus = strtolower(trim((string)($user['account_status'] ?? '')));

    if ($storedStatus !== 'active') {
        return [
            'status' => $storedStatus !== '' ? $storedStatus : 'inactive',
            'note' => '',
            'title' => 'Account access status: ' . ($storedStatus !== '' ? ucfirst($storedStatus) : 'Inactive')
        ];
    }

    $lastLogin = trim((string)($user['last_login_at'] ?? ''));
    $reference = $lastLogin !== ''
        ? $lastLogin
        : (string)($user['approved_at'] ?? $user['created_at'] ?? '');
    $referenceTimestamp = $reference !== '' ? strtotime($reference) : false;
    $inactiveCutoff = time() - (7 * 24 * 60 * 60);

    if ($referenceTimestamp !== false && $referenceTimestamp <= $inactiveCutoff) {
        $note = $lastLogin === '' ? 'Never logged in' : '7+ days idle';
        $title = $lastLogin === ''
            ? 'No successful login within seven days of account activation.'
            : 'Last successful login: ' . date('M d, Y h:i A', $referenceTimestamp);

        return [
            'status' => 'inactive',
            'note' => $note,
            'title' => $title
        ];
    }

    return [
        'status' => 'active',
        'note' => '',
        'title' => $lastLogin === ''
            ? 'Account is within its initial seven-day activity period.'
            : 'Last successful login: ' . date('M d, Y h:i A', $referenceTimestamp)
    ];
}

function getDefaultModulePermissions(string $role): array
{
    switch ($role) {
        case 'owner':
            return [1, 1, 1, 1];
        case 'employee':
            return [1, 1, 0, 1];
        default:
            return [0, 0, 0, 0];
    }
}

$profileImage = !empty($_SESSION['profile_image']) ? $_SESSION['profile_image'] : 'uploads/default.png';
$fullName     = $_SESSION['full_name'] ?? 'System Administrator';
$adminId      = (int) $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| BULK ACTIONS
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_module_action') {
    if (!validateCsrfToken('manage_users_action', $_POST['csrf_token'] ?? null)) {
        $_SESSION['error'] = 'Your session expired. Please try again.';
        header("Location: manage_users.php");
        exit();
    }
    $bulkAction  = trim($_POST['bulk_action'] ?? '');
    $selectedIds = $_POST['selected_user_ids'] ?? [];

    $selectedIds = array_values(array_unique(array_filter(array_map('intval', (array)$selectedIds))));

    try {
        if (empty($selectedIds)) {
            throw new Exception('Please select at least one user first.');
        }

        if (!in_array($bulkAction, ['grant_access', 'revoke_access'], true)) {
            throw new Exception('Please choose a valid action.');
        }

        $conn->begin_transaction();

        $selectStmt = $conn->prepare("SELECT id, username, role FROM users WHERE id = ? FOR UPDATE");
        if (!$selectStmt) {
            throw new Exception('Failed to prepare selected user lookup: ' . $conn->error);
        }

        $updateStmt = $conn->prepare("
            UPDATE users
            SET
                can_inventory = ?,
                can_sales = ?,
                can_sales_analytics = ?,
                can_accounts_receivable = ?
            WHERE id = ?
              AND role <> 'system_admin'
        ");
        if (!$updateStmt) {
            throw new Exception('Failed to prepare selected user update: ' . $conn->error);
        }

        $processedCount = 0;
        $processedUsers = [];

        foreach ($selectedIds as $userId) {
            $selectStmt->bind_param("i", $userId);
            $selectStmt->execute();
            $result = $selectStmt->get_result();
            $user = $result->fetch_assoc();

            if (!$user) {
                continue;
            }

            if ($user['role'] === 'system_admin') {
                continue;
            }

            if ($bulkAction === 'grant_access') {
                [$canInventory, $canSales, $canSalesAnalytics, $canAccountsReceivable] = getDefaultModulePermissions($user['role']);
            } else {
                $canInventory = 0;
                $canSales = 0;
                $canSalesAnalytics = 0;
                $canAccountsReceivable = 0;
            }

            $updateStmt->bind_param(
                "iiiii",
                $canInventory,
                $canSales,
                $canSalesAnalytics,
                $canAccountsReceivable,
                $userId
            );

            if (!$updateStmt->execute()) {
                throw new Exception('Failed to update selected users: ' . $updateStmt->error);
            }

            $processedCount++;
            $processedUsers[] = $user['username'];
        }

        $selectStmt->close();
        $updateStmt->close();

        if ($processedCount === 0) {
            throw new Exception('No valid non-admin user accounts were selected.');
        }

        $description = ($bulkAction === 'grant_access'
            ? 'Granted default module access to: '
            : 'Revoked module access from: ')
            . implode(', ', $processedUsers);

        $actionName = $bulkAction;
        logAdminActivitySecure($conn, $adminId, $actionName, 'user_batch', 0, $description);

        $conn->commit();

        $_SESSION['success'] = $bulkAction === 'grant_access'
            ? "Access granted successfully to {$processedCount} selected user(s)."
            : "Access revoked successfully from {$processedCount} selected user(s).";

        header("Location: manage_users.php");
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = $e->getMessage();
        header("Location: manage_users.php");
        exit();
    }
}

/*
|--------------------------------------------------------------------------
| ADMIN UNLOCK ACCOUNT
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unlock_user') {
    if (!validateCsrfToken('manage_users_action', $_POST['csrf_token'] ?? null)) {
        $_SESSION['error'] = 'Your session expired. Please try again.';
        header("Location: manage_users.php");
        exit();
    }
    $userId = (int)($_POST['user_id'] ?? 0);

    try {
        if ($userId <= 0) {
            throw new Exception('Invalid user ID.');
        }

        $stmt = $conn->prepare("SELECT id, username, role FROM users WHERE id = ? AND role <> 'system_admin' LIMIT 1");
        if (!$stmt) {
            throw new Exception('Failed to prepare user lookup: ' . $conn->error);
        }

        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $targetUser = $result->fetch_assoc();
        $stmt->close();

        if (!$targetUser) {
            throw new Exception('User not found.');
        }

        $stmt = $conn->prepare("
            UPDATE users
            SET failed_login_attempts = 0,
                locked_until = NULL,
                last_failed_login_at = NULL
            WHERE id = ?
              AND role <> 'system_admin'
        ");
        if (!$stmt) {
            throw new Exception('Failed to prepare unlock update: ' . $conn->error);
        }

        $stmt->bind_param("i", $userId);

        if (!$stmt->execute()) {
            throw new Exception('Failed to unlock user account: ' . $stmt->error);
        }
        $stmt->close();

       $description = "Unlocked user account #{$userId} ({$targetUser['username']}) and restored the full 5-attempt security allowance.";

        logAdminActivitySecure($conn, $adminId, 'unlock_user_account', 'user', $userId, $description);

       $_SESSION['success'] = 'User account unlocked successfully. The account now has 5 login attempts available again.';
        header('Location: manage_users.php?edit=' . $userId);
        exit();

    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
        header('Location: manage_users.php' . ($userId > 0 ? '?edit=' . $userId : ''));
        exit();
    }
}

/*
|--------------------------------------------------------------------------
| SINGLE USER UPDATE
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_user') {
    if (!validateCsrfToken('manage_users_action', $_POST['csrf_token'] ?? null)) {
        $_SESSION['error'] = 'Your session expired. Please try again.';
        header("Location: manage_users.php");
        exit();
    }
    $userId                = (int)($_POST['user_id'] ?? 0);
    $userFullName          = trim($_POST['full_name'] ?? '');
    $username              = trim($_POST['username'] ?? '');
    $email                 = trim($_POST['email'] ?? '');
    $phone                 = trim($_POST['phone'] ?? '');
    $address               = trim($_POST['address'] ?? '');
    $role                  = trim($_POST['role'] ?? '');
    $accountStatus         = trim($_POST['account_status'] ?? '');
    $isVerified            = isset($_POST['is_verified']) ? (int)$_POST['is_verified'] : 0;

    $canInventory          = isset($_POST['can_inventory']) ? 1 : 0;
    $canSales              = isset($_POST['can_sales']) ? 1 : 0;
    $canSalesAnalytics     = isset($_POST['can_sales_analytics']) ? 1 : 0;
    $canAccountsReceivable = isset($_POST['can_accounts_receivable']) ? 1 : 0;

    try {
        if ($userId <= 0) {
            throw new Exception('Invalid user ID.');
        }

        if ($userFullName === '' || $username === '' || $email === '' || $role === '' || $accountStatus === '') {
            throw new Exception('Please fill in all required fields.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Please enter a valid email address.');
        }

        $allowedRoles = ['owner', 'employee'];
        if (!in_array($role, $allowedRoles, true)) {
            throw new Exception('Invalid role selected.');
        }

        $allowedStatuses = ['active', 'disabled'];
        if (!in_array($accountStatus, $allowedStatuses, true)) {
            throw new Exception('Invalid account status selected.');
        }

        $conn->begin_transaction();

        $stmt = $conn->prepare("SELECT id, username, email, role, account_status, business_id FROM users WHERE id = ? FOR UPDATE");
        if (!$stmt) {
            throw new Exception('Failed to read user record: ' . $conn->error);
        }

        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $currentResult = $stmt->get_result();
        $currentUser = $currentResult->fetch_assoc();
        $stmt->close();

        if (!$currentUser) {
            throw new Exception('User not found.');
        }

        if ($currentUser['role'] === 'system_admin') {
            throw new Exception('System Administrator accounts cannot be modified from Manage Users.');
        }

        $stmt = $conn->prepare("SELECT id FROM users WHERE (username = ? OR email = ?) AND id <> ? LIMIT 1");
        if (!$stmt) {
            throw new Exception('Failed to validate duplicate username/email: ' . $conn->error);
        }

        $stmt->bind_param("ssi", $username, $email, $userId);
        $stmt->execute();
        $duplicateResult = $stmt->get_result();

        if ($duplicateResult->num_rows > 0) {
            $stmt->close();
            throw new Exception('Username or email already exists for another account.');
        }
        $stmt->close();

        $stmt = $conn->prepare("
            UPDATE users
            SET
                full_name = ?,
                username = ?,
                email = ?,
                phone = ?,
                address = ?,
                role = ?,
                account_status = ?,
                is_verified = ?,
                can_inventory = ?,
                can_sales = ?,
                can_sales_analytics = ?,
                can_accounts_receivable = ?
            WHERE id = ?
              AND role <> 'system_admin'
        ");

        if (!$stmt) {
            throw new Exception('Failed to prepare user update: ' . $conn->error);
        }

        $stmt->bind_param(
            "sssssssiiiiii",
            $userFullName,
            $username,
            $email,
            $phone,
            $address,
            $role,
            $accountStatus,
            $isVerified,
            $canInventory,
            $canSales,
            $canSalesAnalytics,
            $canAccountsReceivable,
            $userId
        );

        if (!$stmt->execute()) {
            throw new Exception('Failed to update user account: ' . $stmt->error);
        }
        $stmt->close();

        /* BUSINESS INTEGRITY: Manage Users may update the account role and
           permissions, but it must never move an account to another business
           or branch. Existing assignment rows are preserved exactly. Only
           their role label is synchronized when Owner/Employee is changed. */
        if (nxWorkspaceSchemaReady($conn)) {
            $assignmentRole = $role === 'owner' ? 'owner' : 'employee';
            $assignmentStmt = $conn->prepare(
                "UPDATE user_business_assignments
                 SET assignment_role = ?
                 WHERE user_id = ? AND status = 'active'"
            );
            if (!$assignmentStmt) {
                throw new Exception('Failed to synchronize the existing business assignment role.');
            }
            $assignmentStmt->bind_param('si', $assignmentRole, $userId);
            if (!$assignmentStmt->execute()) {
                throw new Exception('Failed to synchronize the existing business assignment role.');
            }
            $assignmentStmt->close();
        }

        $workspaceLog = 'preserved business/branch assignment (business_id: ' . (int)($currentUser['business_id'] ?? 0) . ')';
        $description = "Updated user #{$userId} ({$username}) - role: {$role}, workspace: {$workspaceLog}, status: {$accountStatus}, verified: {$isVerified}, inventory: {$canInventory}, sales: {$canSales}, analytics: {$canSalesAnalytics}, accounts_receivable: {$canAccountsReceivable}";

        logAdminActivitySecure($conn, $adminId, 'update_user_permissions', 'user', $userId, $description);

        $conn->commit();
        $_SESSION['success'] = 'User account and module access updated successfully.';
        header('Location: manage_users.php');
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = $e->getMessage();
        header('Location: manage_users.php' . ($userId > 0 ? '?edit=' . $userId : ''));
        exit();
    }
}

/*
|--------------------------------------------------------------------------
| FLASH
|--------------------------------------------------------------------------
*/
$popupMessage = "";
$popupType = "";

if (isset($_SESSION['success'])) {
    $popupMessage = $_SESSION['success'];
    $popupType = "success";
    unset($_SESSION['success']);
} elseif (isset($_SESSION['error'])) {
    $popupMessage = $_SESSION['error'];
    $popupType = "error";
    unset($_SESSION['error']);
}

/*
|--------------------------------------------------------------------------
| FILTERS
|--------------------------------------------------------------------------
*/
$search       = trim($_GET['search'] ?? '');
$roleFilter   = trim($_GET['role_filter'] ?? 'all');
$statusFilter = trim($_GET['status_filter'] ?? 'all');
$editId       = (int)($_GET['edit'] ?? 0);

$roles = [];
$statusSet = ['inactive' => true];
$statuses = [];

$roleResult = $conn->query("SELECT DISTINCT role FROM users WHERE role IS NOT NULL AND role <> '' AND role <> 'system_admin' ORDER BY role ASC");
if ($roleResult) {
    while ($row = $roleResult->fetch_assoc()) {
        $roles[] = $row['role'];
    }
}

$statusResult = $conn->query("SELECT DISTINCT account_status FROM users WHERE account_status IS NOT NULL AND account_status <> '' ORDER BY account_status ASC");
if ($statusResult) {
    while ($row = $statusResult->fetch_assoc()) {
        $status = strtolower(trim((string)$row['account_status']));
        if ($status !== '') {
            $statusSet[$status] = true;
        }
    }
}

foreach (['active', 'inactive', 'disabled', 'pending', 'rejected'] as $status) {
    if (isset($statusSet[$status])) {
        $statuses[] = $status;
        unset($statusSet[$status]);
    }
}

foreach (array_keys($statusSet) as $status) {
    $statuses[] = $status;
}

$where = " WHERE u.role <> 'system_admin' ";
$params = [];
$types = "";

if ($search !== '') {
    $where .= " AND (u.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR be.business_name LIKE ? OR b.branch_name LIKE ? OR be.business_code LIKE ? OR b.branch_code LIKE ?) ";
    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "ssssssss";
}

if ($roleFilter !== '' && $roleFilter !== 'all') {
    $where .= " AND u.role = ? ";
    $params[] = $roleFilter;
    $types .= "s";
}

if ($statusFilter === 'inactive') {
    $where .= " AND (
        u.account_status = 'inactive'
        OR (
            u.account_status = 'active'
            AND (
                COALESCE(u.last_login_at, u.approved_at, u.created_at) IS NULL
                OR COALESCE(u.last_login_at, u.approved_at, u.created_at) <= DATE_SUB(NOW(), INTERVAL 7 DAY)
            )
        )
    ) ";
} elseif ($statusFilter === 'active') {
    $where .= " AND u.account_status = 'active'
        AND COALESCE(u.last_login_at, u.approved_at, u.created_at) IS NOT NULL
        AND COALESCE(u.last_login_at, u.approved_at, u.created_at) > DATE_SUB(NOW(), INTERVAL 7 DAY) ";
} elseif ($statusFilter !== '' && $statusFilter !== 'all') {
    $where .= " AND u.account_status = ? ";
    $params[] = $statusFilter;
    $types .= "s";
}

$users = [];
$sql = "
    SELECT
        u.id,
        u.full_name,
        u.username,
        u.email,
        u.phone,
        u.address,
        u.role,
        u.account_status,
        u.last_login_at,
        u.approved_at,
        u.created_at,
        u.is_verified,
        u.can_inventory,
        u.can_sales,
        u.can_sales_analytics,
        u.can_accounts_receivable,
        u.failed_login_attempts,
        u.locked_until,
        u.business_id,
        be.business_name,
        b.branch_name,
        be.business_code,
        b.branch_code
    FROM users u
    LEFT JOIN businesses b ON b.id = u.business_id
    LEFT JOIN business_entities be ON be.id = b.business_entity_id
    {$where}
    ORDER BY u.id DESC
";
$stmt = $conn->prepare($sql);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    $stmt->close();
}

$editUser = null;
if ($editId > 0) {
    $stmt = $conn->prepare("
        SELECT
            u.id,
            u.full_name,
            u.username,
            u.email,
            u.phone,
            u.address,
            u.role,
            u.account_status,
            u.is_verified,
            u.can_inventory,
            u.can_sales,
            u.can_sales_analytics,
            u.can_accounts_receivable,
            u.failed_login_attempts,
            u.locked_until,
            u.business_id,
            be.business_name,
            b.branch_name,
            be.business_code,
            b.branch_code
        FROM users u
        LEFT JOIN businesses b ON b.id = u.business_id
        LEFT JOIN business_entities be ON be.id = b.business_entity_id
        WHERE u.id = ?
          AND u.role <> 'system_admin'
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param("i", $editId);
        $stmt->execute();
        $result = $stmt->get_result();
        $editUser = $result->fetch_assoc();
        $stmt->close();

        if ($editId > 0 && !$editUser) {
            $_SESSION['error'] = 'System Administrator accounts are excluded from Manage Users.';
            header("Location: manage_users.php");
            exit();
        }
    }
}

function renderManageUsersTable(array $users, string $search, string $roleFilter, string $statusFilter): void
{
    ?>
    <div id="manageUsersContainer">
        <div class="table-wrap manage-users-table-wrap" id="manageUsersTableWrap" data-mobile-cards>
                <table>
                    <colgroup>
                        <col style="width: 200px;">
                        <col style="width: 110px;">
                        <col style="width: 240px;">
                        <col style="width: 120px;">
                        <col style="width: 90px;">
                        <col style="width: 100px;">
                        <col style="width: 110px;">
                        <col style="width: 220px;">
                        <col style="width: 100px;">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>USERNAME</th>
                            <th>ROLE</th>
                            <th>BUSINESS</th>
                            <th>VERIFIED</th>
                            <th>FAILED</th>
                            <th>LOCK</th>
                            <th>STATUS</th>
                            <th>MODULE ACCESS</th>
                            <th>ACTION</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="9">No user accounts found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users as $user): ?>
                            <?php
                            $isLocked = !empty($user['locked_until']) && strtotime((string)$user['locked_until']) > time();
                            $activityIndicator = getUserActivityIndicator($user);
                            $effectiveStatus = $activityIndicator['status'];
                            ?>
                            <tr>
                                <td class="table-primary-username" data-label="Username"><?php echo e($user['username']); ?></td>
                                <td class="table-role" data-label="Role"><?php echo e(ucwords(str_replace('_', ' ', $user['role']))); ?></td>
                                <td class="table-business" data-label="Business">
                                    <?php if (!empty($user['business_id'])): ?>
                                        <strong><?php echo e($user['business_name'] ?? ('Workspace #' . (int)$user['business_id'])); ?></strong><br>
                                        <small><?php echo e(($user['branch_name'] ?? 'Main Branch') . ' [' . ($user['business_code'] ?? '—') . ' / ' . ($user['branch_code'] ?? '—') . ']'); ?></small>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td class="table-verified" data-label="Verified">
                                    <span class="mini-badge <?php echo (int)$user['is_verified'] === 1 ? 'yes' : 'no'; ?>">
                                        <?php echo (int)$user['is_verified'] === 1 ? 'Verified' : 'Not Verified'; ?>
                                    </span>
                                </td>
                                <td class="table-failed" data-label="Failed">
                                    <span class="mini-badge <?php echo (int)$user['failed_login_attempts'] > 0 ? 'no' : 'yes'; ?>">
                                        <?php echo (int)$user['failed_login_attempts']; ?>
                                    </span>
                                </td>
                                <td class="table-lock" data-label="Lock">
                                    <span class="mini-badge <?php echo $isLocked ? 'no' : 'yes'; ?>">
                                        <?php echo $isLocked ? 'Locked' : 'Open'; ?>
                                    </span>
                                </td>
                                <td class="table-status" data-label="Status">
                                    <span class="activity-status-stack">
                                        <span
                                            class="badge badge-<?php echo e($effectiveStatus); ?>"
                                            title="<?php echo e($activityIndicator['title']); ?>"
                                        >
                                            <?php echo e(ucfirst($effectiveStatus)); ?>
                                        </span>
                                        <?php if ($activityIndicator['note'] !== ''): ?>
                                            <small class="activity-status-note"><?php echo e($activityIndicator['note']); ?></small>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td class="table-modules modules-cell" data-label="Module Access"><?php echo e(moduleAccessSummary($user)); ?></td>
                                <td class="table-action action-cell" data-label="Action">
                                    <a class="btn btn-silver edit-user-btn" href="manage_users.php?<?php echo http_build_query([
                                        'search' => $search,
                                        'role_filter' => $roleFilter,
                                        'status_filter' => $statusFilter,
                                        'edit' => (int)$user['id']
                                    ]); ?>">Edit</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
    </div>
    <?php
}

if (
    !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
) {
    renderManageUsersTable($users, $search, $roleFilter, $statusFilter);
    exit();
}

$csrfManageUsers = generateCsrfToken('manage_users_action');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php include __DIR__ . '/nexgen_favicon/favicon.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - NextGen</title>
    <script>
        /* THEME: apply saved preference before first paint to avoid a flash.
           Personalization now lives on the Settings page and writes to the
           shared 'nexgen-theme' key, so every admin page reads that same key. */
        (function () {
            try {
                var saved = localStorage.getItem('nexgen-theme');
                if (saved === 'light' || saved === 'dark') {
                    document.documentElement.setAttribute('data-theme', saved);
                }
            } catch (e) {}
        })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800;900&family=Sora:wght@400;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/NexGen/CODE/STYLE/admin_module.css?v=20260826-responsive">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        /* =========================
           PAGE TITLE ICON
        ========================= */
        .page-title h1 {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-title-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            color: var(--gold);
            font-size: 30px;
        }

        @media (max-width: 700px) {
            .page-title-icon {
                font-size: 24px;
            }
        }

        .manage-users-table-wrap {
            max-height: 470px;
            overflow-y: auto;
            overflow-x: auto;
            border-radius: 18px;
            position: relative;
            transition: opacity 0.15s ease;
        }

        .manage-users-table-wrap.loading {
            opacity: 0.62;
            pointer-events: none;
        }

        .manage-users-table-wrap table {
            width: 100%;
            min-width: 1180px;
            border-collapse: separate;
            border-spacing: 0;
            table-layout: fixed;
        }

        .manage-users-table-wrap th,
        .manage-users-table-wrap td {
            text-align: center;
            vertical-align: middle;
        }

        .manage-users-table-wrap td.table-primary-username {
            text-align: left;
            padding-left: 20px;
            overflow-wrap: anywhere;
        }

        .manage-users-table-wrap thead th {
            position: sticky;
            top: 0;
            z-index: 5;
            background: rgba(8, 16, 50, 0.94);
            backdrop-filter: blur(8px);
        }

        html[data-theme="light"] .manage-users-table-wrap thead th {
            background: rgba(214, 235, 255, 0.92);
        }

        .manage-users-table-wrap::-webkit-scrollbar {
            width: 10px;
            height: 10px;
        }

        .manage-users-table-wrap::-webkit-scrollbar-thumb {
            background: rgba(59, 130, 246, 0.45);
            border-radius: 999px;
        }

        .manage-users-table-wrap::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 999px;
        }

        .toolbar-form {
            display: grid;
            grid-template-columns: minmax(280px, 1.8fr) 180px 180px 180px auto auto;
            gap: 12px;
            align-items: center;
            margin-bottom: 18px;
        }

        .toolbar-form .input,
        .toolbar-form .select,
        .toolbar-form .btn {
            width: 100%;
            box-sizing: border-box;
        }

        .toolbar-buttons {
            display: contents;
        }

        /* =========================
           RESPONSIVE TOOLBAR / TABLE
           (tablet: 2-up fields; phone: stacked full-width
           fields with the search bar spanning the top and
           Apply/Reset paired side by side)
        ========================= */
        @media (max-width: 900px) {
            .toolbar-form {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .toolbar-form .input {
                grid-column: 1 / -1;
            }
        }

        @media (max-width: 620px) {
            .toolbar-form {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .toolbar-form .input,
            .toolbar-form .select {
                min-height: 46px;
                font-size: 15px;
            }

            .toolbar-buttons {
                display: flex;
                gap: 10px;
            }

            .toolbar-buttons .btn {
                flex: 1 1 0;
                min-height: 46px;
            }
        }

        @media (max-width: 700px) {
            .manage-users-table-wrap {
                max-height: none;
                overflow: visible;
                border-radius: 0;
            }
        }

        .mini-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            white-space: nowrap;
            min-width: 58px;
        }

        .mini-badge.yes {
            background: rgba(70, 194, 126, 0.18);
            color: #8df0b3;
        }

        .mini-badge.no {
            background: rgba(255, 107, 107, 0.18);
            color: #ffb0b0;
        }

        .activity-status-stack {
            display: inline-flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 4px;
            max-width: 100%;
        }

        .activity-status-note {
            color: var(--muted);
            font-size: 10.5px;
            font-weight: 700;
            line-height: 1.2;
            white-space: nowrap;
        }

        html[data-theme="light"] .mini-badge.yes {
            background: rgba(16, 138, 92, 0.14);
            color: #0f7a52;
        }

        html[data-theme="light"] .mini-badge.no {
            background: rgba(200, 30, 77, 0.12);
            color: #c81e4d;
        }

        .modules-cell {
            line-height: 1.5;
            white-space: normal;
            word-break: break-word;
        }

        .action-cell {
            text-align: center;
        }

        .action-cell .btn {
            min-width: 90px;
        }

        .table-role,
        .table-business,
        .table-status,
        .table-verified,
        .table-failed,
        .table-lock,
        .table-action,
        .table-modules {
            text-align: center;
        }

        .table-role,
        .table-status,
        .table-verified,
        .table-failed,
        .table-lock,
        .table-action {
            white-space: nowrap;
        }

        .table-modules {
            word-break: break-word;
        }

        /* =========================
           EDIT MODAL — SECTIONED LAYOUT
        ========================= */
        .modal-section {
            margin-bottom: 26px;
        }

        .modal-section:last-of-type {
            margin-bottom: 0;
        }

        .modal-section-title {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0 0 16px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--line);
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: var(--gold);
        }

        .editor-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px 20px;
        }

        .editor-grid .full {
            grid-column: 1 / -1;
        }

        .field-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .field-group label {
            color: var(--muted);
            font-weight: 700;
            font-size: 11.5px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .field-group input,
        .field-group select,
        .field-group textarea {
            width: 100%;
            border: 1px solid var(--line-bright);
            border-radius: 12px;
            padding: 11px 14px;
            background: var(--card);
            color: var(--text);
            font-size: 13.5px;
            outline: none;
            box-sizing: border-box;
            transition: 0.18s ease;
        }

        .field-group input:hover,
        .field-group select:hover,
        .field-group textarea:hover {
            background: var(--card-hover);
        }

        .field-group input:focus,
        .field-group select:focus,
        .field-group textarea:focus {
            border-color: var(--gold);
            background: var(--card-hover);
            box-shadow: 0 0 0 3px var(--gold-soft);
        }

        .field-group textarea {
            min-height: 76px;
            resize: vertical;
        }

        .field-group input::placeholder,
        .field-group textarea::placeholder {
            color: var(--faint);
        }

        .field-group select option {
            color: #111;
        }

        .form-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 24px;
            padding-top: 18px;
            border-top: 1px solid var(--line);
        }

        .form-actions-primary {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn-ghost {
            background: transparent;
            border: 1px dashed var(--line-bright);
            color: var(--muted);
            border-radius: 12px;
            padding: 11px 16px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: 0.18s ease;
        }

        .btn-ghost:hover {
            border-color: var(--gold-dim);
            color: var(--text);
        }

        .access-box {
            background: transparent;
            border: none;
            border-radius: 0;
            padding: 0;
        }

        .access-box p {
            margin: 0 0 16px;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.6;
        }

        .access-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .check-card {
            display: flex;
            align-items: center;
            gap: 12px;
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 12px 14px;
            transition: 0.18s ease;
        }

        .check-card:has(input:checked) {
            background: var(--gold-soft);
            border-color: rgba(246, 203, 8, 0.4);
        }

        .check-card input[type="checkbox"] {
            width: 17px;
            height: 17px;
            accent-color: var(--gold-dim);
            cursor: pointer;
            flex-shrink: 0;
        }

        .check-card input[type="checkbox"]:disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }

        .check-card label {
            margin: 0;
            color: var(--text);
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
        }

        .role-note {
            margin-top: 12px;
            font-size: 12.5px;
            color: var(--muted);
            line-height: 1.6;
            background: var(--card);
            border-radius: 10px;
            padding: 10px 12px;
        }

        .security-panel {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
        }

        .security-stat {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 12px 14px;
        }

        .security-stat label {
            display: block;
            font-size: 10.5px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: var(--muted);
            font-weight: 700;
            margin-bottom: 4px;
        }

        .security-stat .value {
            font-size: 14px;
            font-weight: 700;
            color: var(--text);
        }

        .security-stat.is-locked .value {
            color: var(--danger);
        }

        .edit-modal-overlay,
        .custom-confirm-overlay,
        .custom-alert-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.50);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 99998;
            padding: 20px;
        }

        .edit-modal-overlay.show,
        .custom-confirm-overlay.show,
        .custom-alert-overlay.show {
            display: flex;
        }

        .edit-modal-box,
        .custom-confirm-box,
        .custom-alert-box {
            background: linear-gradient(180deg, rgba(59, 130, 246, 0.16) 0%, rgba(5, 11, 36, 0.96) 100%);
            border: 1px solid rgba(125, 211, 252, 0.28);
            border-radius: 24px;
            box-shadow: 0 22px 50px rgba(3, 3, 30, 0.5);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            color: var(--text);
        }

        html[data-theme="light"] .edit-modal-box,
        html[data-theme="light"] .custom-confirm-box,
        html[data-theme="light"] .custom-alert-box {
            background: linear-gradient(180deg, #ffffff 0%, #eaf4ff 100%);
            border-color: rgba(59, 130, 246, 0.25);
        }

        .edit-modal-box {
            width: min(940px, 96vw);
            max-height: 90vh;
            overflow-y: auto;
        }

        .custom-confirm-box,
        .custom-alert-box {
            width: 100%;
            max-width: 380px;
            padding: 26px 22px;
            text-align: center;
            color: var(--text);
        }

        .edit-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            padding: 20px 24px;
            border-bottom: 1px solid var(--line);
        }

        .edit-modal-header h2 {
            margin: 0;
            color: var(--text);
            font-size: 20px;
            font-weight: 800;
            font-family: var(--font-display);
        }

        .edit-modal-body {
            padding: 24px;
        }

        .modal-close-btn {
            appearance: none;
            border: 1px solid var(--line-bright);
            background: var(--card);
            color: var(--text);
            width: 34px;
            height: 34px;
            border-radius: 50%;
            font-size: 20px;
            line-height: 1;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: 0.18s ease;
        }

        .modal-close-btn:hover {
            background: var(--card-hover);
        }

        .custom-confirm-icon,
        .custom-alert-icon {
            width: 62px;
            height: 62px;
            margin: 0 auto 14px;
            border-radius: 50%;
            background: var(--gold-soft);
            border: 1px solid rgba(246, 203, 8, 0.35);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            color: var(--gold);
        }

        .custom-confirm-box h3,
        .custom-alert-box h3 {
            margin: 0 0 8px;
            font-size: 25px;
            font-weight: 800;
            color: var(--text);
            font-family: var(--font-display);
        }

        .custom-confirm-box p,
        .custom-alert-box p {
            margin: 0 0 22px;
            font-size: 15px;
            color: var(--muted);
            line-height: 1.5;
        }

        .custom-confirm-actions,
        .custom-alert-actions {
            display: flex;
            justify-content: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .custom-btn-cancel,
        .custom-btn-confirm,
        .custom-btn-ok {
            border: none;
            border-radius: 12px;
            padding: 11px 18px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            min-width: 120px;
            transition: 0.2s ease;
        }

        .custom-btn-cancel {
            background: rgba(59, 130, 246, 0.16);
            color: var(--text);
            border: 1px solid var(--glass-border);
        }

        .custom-btn-cancel:hover {
            background: rgba(59, 130, 246, 0.26);
        }

        .custom-btn-confirm,
        .custom-btn-ok {
            background: linear-gradient(135deg, #ffdd55 0%, #f6cb08 100%);
            color: #2b1a00;
        }

        .custom-btn-confirm:hover,
        .custom-btn-ok:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 20px rgba(246, 203, 8, 0.3);
        }

        @media (max-width: 1350px) {
            .toolbar-form {
                grid-template-columns: minmax(220px, 1fr) 170px 170px 170px auto auto;
            }
        }

        @media (max-width: 1100px) {
            .toolbar-form {
                grid-template-columns: 1fr 1fr;
            }

            .toolbar-form > * {
                min-width: 0;
            }
        }

        @media (max-width: 900px) {
            .editor-grid,
            .access-grid,
            .toolbar-form {
                grid-template-columns: 1fr;
            }

            .edit-modal-body {
                padding: 18px;
            }

            .edit-modal-header {
                padding: 18px;
            }

            .edit-modal-header h2 {
                font-size: 17px;
            }

            .security-panel {
                grid-template-columns: 1fr;
            }
        }

        /* =========================
           MOBILE CARD-STYLE TABLE
           (kept inline, alongside the copy in admin_module.css,
           so this page renders correctly even if that shared
           stylesheet is served from an old cached copy)
        ========================= */
        @media (max-width: 700px) {
            .manage-users-table-wrap[data-mobile-cards] {
                overflow: visible;
                border: none;
                background: transparent;
            }

            .manage-users-table-wrap[data-mobile-cards] table {
                min-width: 0;
                width: 100%;
                table-layout: auto;
            }

            .manage-users-table-wrap[data-mobile-cards] colgroup {
                display: none;
            }

            .manage-users-table-wrap[data-mobile-cards] thead {
                display: none;
            }

            .manage-users-table-wrap[data-mobile-cards] tbody {
                display: block;
                width: 100%;
            }

            /* -----------------------------------------------------------
               Centered user card. Email and Phone are intentionally absent
               from both the desktop table and this mobile layout.
            ----------------------------------------------------------- */
            .manage-users-table-wrap[data-mobile-cards] tr {
                display: grid;
                grid-template-columns: 1fr;
                grid-template-areas:
                    "username"
                    "business"
                    "role"
                    "status"
                    "verified"
                    "edit";
                column-gap: 12px;
                row-gap: 6px;
                align-items: center;
                background: var(--card);
                border: 1px solid var(--line-bright);
                border-radius: 14px;
                margin-bottom: 12px;
                padding: 16px 16px 14px;
            }

            .manage-users-table-wrap[data-mobile-cards] td {
                display: block;
                padding: 0;
                border-bottom: none;
                background: transparent !important;
                font-size: 13.5px;
                text-align: center;
                white-space: normal;
            }

            .manage-users-table-wrap[data-mobile-cards] td::before {
                content: none;
            }

            /* Hide every column not shown on the mobile card */
            .manage-users-table-wrap[data-mobile-cards] td[data-label="Failed"],
            .manage-users-table-wrap[data-mobile-cards] td[data-label="Lock"],
            .manage-users-table-wrap[data-mobile-cards] td[data-label="Module Access"] {
                display: none;
            }

            .manage-users-table-wrap[data-mobile-cards] td[data-label="Username"] {
                grid-area: username;
                font-size: 17px;
                font-weight: 700;
                letter-spacing: 0.2px;
                color: var(--text);
                text-align: center;
                padding-left: 0;
            }

            .manage-users-table-wrap[data-mobile-cards] td[data-label="Business"] {
                grid-area: business;
                margin-top: 8px;
                padding-top: 12px;
                border-top: 1px solid var(--line);
            }

            .manage-users-table-wrap[data-mobile-cards] td[data-label="Status"] {
                grid-area: status;
                justify-self: center;
            }

            .manage-users-table-wrap[data-mobile-cards] td[data-label="Status"] .badge {
                font-size: 11.5px;
                padding: 5px 12px;
            }

            .manage-users-table-wrap[data-mobile-cards] td[data-label="Role"] {
                grid-area: role;
                font-size: 13.5px;
                color: var(--text);
            }

            .manage-users-table-wrap[data-mobile-cards] td[data-label="Verified"] {
                grid-area: verified;
                justify-self: center;
            }

            .manage-users-table-wrap[data-mobile-cards] td[data-label="Verified"] .mini-badge {
                min-width: 0;
                padding: 5px 10px;
                font-size: 10.5px;
            }

            .manage-users-table-wrap[data-mobile-cards] td[data-label="Action"] {
                grid-area: edit;
                margin-top: 8px;
                padding-top: 12px;
                border-top: 1px solid var(--line);
            }

            .manage-users-table-wrap[data-mobile-cards] td[data-label="Action"] .btn {
                width: 100%;
            }
        }

        /* =========================
           RESPONSIVE PAGE SHELL
           Keeps the fixed hamburger from covering the page title.
        ========================= */
        @media (max-width: 1100px) {
            .admin-content .topbar {
                min-height: 46px;
                padding-left: 58px;
                box-sizing: border-box;
            }

            .admin-content .page-title,
            .admin-content .page-title h1 {
                min-width: 0;
                max-width: 100%;
            }

            .admin-content .page-title h1 {
                white-space: normal;
                overflow-wrap: anywhere;
            }
        }

        /* =========================
           RESPONSIVE MANAGE USERS
           Phones and small tablets use compact, flexible user cards.
        ========================= */
        @media (max-width: 768px) {
            .admin-content {
                width: 100%;
                min-width: 0;
                padding: 80px 14px 24px !important;
                overflow-x: hidden;
                box-sizing: border-box;
            }

            .admin-content .topbar {
                margin-bottom: 16px;
                padding-left: 0;
            }

            .admin-content .page-title h1 {
                gap: 8px;
                font-size: clamp(20px, 6.5vw, 24px);
                line-height: 1.2;
            }

            .admin-content .page-title-icon {
                font-size: 22px;
            }

            .admin-content .panel {
                width: 100%;
                min-width: 0;
                border-radius: 16px;
            }

            .admin-content .panel-header,
            .admin-content .panel-body {
                padding: 14px;
            }

            .toolbar-form {
                display: grid;
                grid-template-columns: minmax(0, 1fr) !important;
                gap: 10px;
                width: 100%;
                min-width: 0;
                margin-bottom: 14px;
            }

            .toolbar-form .input,
            .toolbar-form .select {
                width: 100%;
                min-width: 0;
                max-width: 100%;
                min-height: 44px;
                box-sizing: border-box;
            }

            .manage-users-table-wrap[data-mobile-cards] {
                width: 100%;
                min-width: 0;
                max-height: none;
                overflow: visible;
                border: 0;
                border-radius: 0;
                background: transparent;
            }

            .manage-users-table-wrap[data-mobile-cards] table {
                width: 100%;
                min-width: 0;
                table-layout: auto;
            }

            .manage-users-table-wrap[data-mobile-cards] colgroup,
            .manage-users-table-wrap[data-mobile-cards] thead {
                display: none;
            }

            .manage-users-table-wrap[data-mobile-cards] tbody {
                display: block;
                width: 100%;
                min-width: 0;
            }

            .manage-users-table-wrap[data-mobile-cards] tr {
                width: 100%;
                min-width: 0;
                display: grid;
                grid-template-columns: minmax(0, 1fr) auto;
                grid-template-areas:
                    "username status"
                    "business business"
                    "role verified"
                    "edit edit";
                gap: 9px 12px;
                align-items: center;
                box-sizing: border-box;
                margin: 0 0 12px;
                padding: 14px;
                border: 1px solid var(--line-bright);
                border-radius: 14px;
                background: var(--card);
            }

            .manage-users-table-wrap[data-mobile-cards] td {
                min-width: 0;
                padding: 0;
                border: 0;
                background: transparent !important;
                white-space: normal;
            }

            .manage-users-table-wrap[data-mobile-cards] td[data-label="Username"] {
                display: block !important;
                grid-area: username;
                padding: 0;
                text-align: left;
                font-size: 16px;
                font-weight: 800;
                line-height: 1.35;
                overflow-wrap: anywhere;
                color: var(--text);
            }

            .manage-users-table-wrap[data-mobile-cards] td[data-label="Business"] {
                display: block !important;
                grid-area: business;
                min-width: 0;
                margin: 2px 0 0;
                padding: 10px 0 0;
                border-top: 1px solid var(--line);
                text-align: left;
                line-height: 1.4;
                overflow-wrap: anywhere;
            }

            .manage-users-table-wrap[data-mobile-cards] td[data-label="Business"] strong,
            .manage-users-table-wrap[data-mobile-cards] td[data-label="Business"] small {
                display: block;
                max-width: 100%;
                overflow-wrap: anywhere;
            }

            .manage-users-table-wrap[data-mobile-cards] td[data-label="Business"] small {
                margin-top: 3px;
                color: var(--muted);
                font-size: 11.5px;
            }

            .manage-users-table-wrap[data-mobile-cards] td[data-label="Role"] {
                display: block !important;
                grid-area: role;
                text-align: left;
                font-size: 13px;
                color: var(--muted);
            }

            .manage-users-table-wrap[data-mobile-cards] td[data-label="Status"] {
                display: block !important;
                grid-area: status;
                justify-self: end;
            }

            .manage-users-table-wrap[data-mobile-cards] td[data-label="Verified"] {
                display: block !important;
                grid-area: verified;
                justify-self: end;
            }

            .manage-users-table-wrap[data-mobile-cards] td[data-label="Failed"],
            .manage-users-table-wrap[data-mobile-cards] td[data-label="Lock"],
            .manage-users-table-wrap[data-mobile-cards] td[data-label="Module Access"] {
                display: none !important;
            }

            .manage-users-table-wrap[data-mobile-cards] td[data-label="Action"] {
                display: block !important;
                grid-area: edit;
                width: 100%;
                margin-top: 2px;
                padding-top: 10px;
                border-top: 1px solid var(--line);
            }

            .manage-users-table-wrap[data-mobile-cards] td[data-label="Action"] .btn {
                width: 100%;
                min-width: 0;
                box-sizing: border-box;
            }

            .manage-users-table-wrap[data-mobile-cards] td[colspan] {
                display: block !important;
                grid-column: 1 / -1;
                padding: 16px;
                text-align: center;
            }
        }

        @media (max-width: 380px) {
            .admin-content {
                padding-right: 10px !important;
                padding-left: 10px !important;
            }

            .admin-content .topbar {
                padding-left: 0;
            }

            .admin-content .panel-header,
            .admin-content .panel-body,
            .manage-users-table-wrap[data-mobile-cards] tr {
                padding: 12px;
            }
        }
    </style>
</head>
<body>

<?php if (!empty($popupMessage)): ?>
    <div class="popup-overlay" id="popupOverlay">
        <div class="popup-box <?php echo $popupType; ?>" id="popupBox">
            <div class="popup-icon"><?php echo $popupType === 'success' ? '✓' : '!'; ?></div>
            <h3><?php echo $popupType === 'success' ? 'Success' : 'Error'; ?></h3>
            <p><?php echo htmlspecialchars($popupMessage); ?></p>
        </div>
    </div>
<?php endif; ?>

<div class="admin-shell">
    <?php include 'admin_sidebar.php'; ?>

    <main class="admin-content manage-users-content">
        <div class="topbar">
            <div class="page-title">
                <h1><span class="page-title-icon"><i class="bi bi-people-fill"></i></span>Manage Users</h1>
            </div>
        </div>

        <section class="panel">
            <div class="panel-header">
                <h2>User Accounts</h2>
            </div>
            <div class="panel-body">

                <form method="GET" class="toolbar-form" id="filterForm" onsubmit="return false;">
                    <input
                        class="input"
                        type="text"
                        name="search"
                        id="searchInput"
                        placeholder="Search by username, business, or branch."
                        value="<?php echo e($search); ?>"
                        autocomplete="off"
                    >

                    <select class="select" name="role_filter" id="roleFilter">
                        <option value="all">All Roles</option>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?php echo e($role); ?>" <?php echo $roleFilter === $role ? 'selected' : ''; ?>>
                                <?php echo e(ucwords(str_replace('_', ' ', $role))); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select class="select" name="status_filter" id="statusFilter">
                        <option value="all">All Statuses</option>
                        <?php foreach ($statuses as $status): ?>
                            <option value="<?php echo e($status); ?>" <?php echo $statusFilter === $status ? 'selected' : ''; ?>>
                                <?php echo e(ucfirst($status)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>

                <?php renderManageUsersTable($users, $search, $roleFilter, $statusFilter); ?>
            </div>
        </section> 
    </main>
</div>

<?php if ($editUser): ?>
<div class="edit-modal-overlay show" id="editModalOverlay">
    <div class="edit-modal-box">
        <div class="edit-modal-header">
            <h2>Edit User Account</h2>
            <button type="button" class="modal-close-btn" onclick="closeEditModal()">&times;</button>
        </div>

        <div class="edit-modal-body">
            <?php
                $isLocked = !empty($editUser['locked_until']) && strtotime((string)$editUser['locked_until']) > time();
                $isPermanentLock = $isLocked && (int)($editUser['failed_login_attempts'] ?? 0) >= 5;
            ?>
            <?php if ($isLocked): ?>
                <form method="POST" id="unlockUserForm" class="unlock-user-form" data-username="<?php echo e($editUser['username']); ?>" hidden>
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfManageUsers); ?>">
                    <input type="hidden" name="action" value="unlock_user">
                    <input type="hidden" name="user_id" value="<?php echo (int)$editUser['id']; ?>">
                </form>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo e($csrfManageUsers); ?>">
                <input type="hidden" name="action" value="update_user">
                <input type="hidden" name="user_id" value="<?php echo (int)$editUser['id']; ?>">

                <div class="modal-section">
                    <h3 class="modal-section-title">Account Details</h3>
                    <div class="editor-grid">
                        <div class="field-group">
                            <label for="full_name">Full Name</label>
                            <input type="text" id="full_name" name="full_name" value="<?php echo e($editUser['full_name']); ?>" required>
                        </div>

                        <div class="field-group">
                            <label for="username">Username</label>
                            <input type="text" id="username" name="username" value="<?php echo e($editUser['username']); ?>" required>
                        </div>

                        <div class="field-group">
                            <label for="email">Email</label>
                            <input type="email" id="email" name="email" value="<?php echo e($editUser['email']); ?>" required>
                        </div>

                        <div class="field-group">
                            <label for="phone">Phone</label>
                            <input type="text" id="phone" name="phone" value="<?php echo e($editUser['phone']); ?>">
                        </div>

                        <div class="field-group full">
                            <label for="address">Address</label>
                            <textarea id="address" name="address"><?php echo e($editUser['address']); ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="modal-section">
                    <h3 class="modal-section-title">Role &amp; Status</h3>
                    <div class="editor-grid">
                        <div class="field-group">
                            <label for="role">Role</label>
                            <select id="role" name="role" required onchange="handleRoleChange()">
                                <option value="owner" <?php echo $editUser['role'] === 'owner' ? 'selected' : ''; ?>>Owner</option>
                                <option value="employee" <?php echo $editUser['role'] === 'employee' ? 'selected' : ''; ?>>Employee</option>
                            </select>
                        </div>

                        <div class="field-group">
                            <label for="account_status">Account Status</label>
                            <select id="account_status" name="account_status" required>
                                <option value="active" <?php echo $editUser['account_status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="disabled" <?php echo $editUser['account_status'] === 'disabled' ? 'selected' : ''; ?>>Disabled</option>
                            </select>
                        </div>

                        <div class="field-group full">
                            <div class="check-card">
                                <input type="checkbox" id="is_verified" name="is_verified" value="1" <?php echo !empty($editUser['is_verified']) ? 'checked' : ''; ?>>
                                <label for="is_verified">Verified Account</label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-section">
                    <h3 class="modal-section-title">Module Access</h3>
                    <div class="access-box">
                        <p>
                            Saved module permissions are shown exactly as stored. Changing the role or selecting Reset to Role Default Access applies the recommended defaults for that role.
                        </p>

                        <div class="access-grid">
                            <div class="check-card">
                                <input type="checkbox" id="can_inventory" name="can_inventory" <?php echo !empty($editUser['can_inventory']) ? 'checked' : ''; ?>>
                                <label for="can_inventory">Inventory Management</label>
                            </div>

                            <div class="check-card">
                                <input type="checkbox" id="can_sales" name="can_sales" <?php echo !empty($editUser['can_sales']) ? 'checked' : ''; ?>>
                                <label for="can_sales">Sales</label>
                            </div>

                            <div class="check-card">
                                <input type="checkbox" id="can_sales_analytics" name="can_sales_analytics" <?php echo !empty($editUser['can_sales_analytics']) ? 'checked' : ''; ?>>
                                <label for="can_sales_analytics">Sales Analytics</label>
                            </div>

                            <div class="check-card">
                                <input type="checkbox" id="can_accounts_receivable" name="can_accounts_receivable" <?php echo !empty($editUser['can_accounts_receivable']) ? 'checked' : ''; ?>>
                                <label for="can_accounts_receivable">Accounts Receivable</label>
                            </div>
                        </div>

                        <div class="role-note" id="roleNote">
                            You can manage module access here based on the selected non-admin role.
                        </div>
                    </div>
                </div>

                <div class="modal-section">
                    <h3 class="modal-section-title">Security</h3>
                    <div class="security-panel">
                        <div class="security-stat">
                            <label>Failed Login Attempts</label>
                            <div class="value"><?php echo (int)($editUser['failed_login_attempts'] ?? 0); ?></div>
                        </div>
                        <div class="security-stat">
                            <label>Attempts Available</label>
                            <div class="value"><?php echo max(0, 5 - (int)($editUser['failed_login_attempts'] ?? 0)); ?></div>
                        </div>
                        <div class="security-stat<?php echo $isLocked ? ' is-locked' : ''; ?>">
                            <label>Lock Status</label>
                            <div class="value">
                                <?php
                                if ($isPermanentLock) {
                                    echo 'Locked — administrator unlock required';
                                } elseif ($isLocked) {
                                    echo 'Locked until ' . e(date('M d, Y h:i A', strtotime((string)$editUser['locked_until'])));
                                } else {
                                    echo 'Not locked';
                                }
                                ?>
                            </div>
                        </div>
                    </div>

                    <?php if ($isLocked): ?>
                        <button type="submit" form="unlockUserForm" class="btn btn-gold" style="margin-top: 12px;">Unlock Account</button>
                    <?php endif; ?>
                </div>

                <div class="form-actions">
                    <div class="form-actions-primary">
                        <button type="submit" class="btn btn-gold">Save Changes</button>
                        <button type="button" class="btn btn-silver" onclick="closeEditModal()">Cancel</button>
                    </div>
                    <button type="button" class="btn-ghost" onclick="applyRoleDefaults()">Reset to Role Default Access</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="custom-confirm-overlay" id="customConfirmOverlay">
    <div class="custom-confirm-box">
        <div class="custom-confirm-icon">?</div>
        <h3>Confirmation</h3>
        <p id="customConfirmMessage">Are you sure?</p>
        <div class="custom-confirm-actions">
            <button type="button" class="custom-btn-cancel" id="customConfirmCancel">Cancel</button>
            <button type="button" class="custom-btn-confirm" id="customConfirmOk">Confirm</button>
        </div>
    </div>
</div>

<div class="custom-alert-overlay" id="customAlertOverlay">
    <div class="custom-alert-box">
        <div class="custom-alert-icon">!</div>
        <h3>Notice</h3>
        <p id="customAlertMessage">Message</p>
        <div class="custom-alert-actions">
            <button type="button" class="custom-btn-ok" id="customAlertOk">OK</button>
        </div>
    </div>
</div>

<button type="button" id="backToTopBtn" class="back-to-top-btn" aria-label="Back to top" title="Back to top">
    <i class="bi bi-arrow-up"></i>
</button>

<script src="/NexGen/CODE/JS/admin_module.js"></script>
<script>
function setModuleCheckboxState(disabled) {
    const ids = [
        'can_inventory',
        'can_sales',
        'can_sales_analytics',
        'can_accounts_receivable'
    ];

    ids.forEach(function(id) {
        const el = document.getElementById(id);
        if (el) {
            el.disabled = disabled;
        }
    });
}

function syncRoleControls() {
    const role = document.getElementById('role');
    if (!role) return;

    setModuleCheckboxState(false);
}

function applyRoleDefaults() {
    const role = document.getElementById('role');
    if (!role) return;

    const canInventory = document.getElementById('can_inventory');
    const canSales = document.getElementById('can_sales');
    const canSalesAnalytics = document.getElementById('can_sales_analytics');
    const canAccountsReceivable = document.getElementById('can_accounts_receivable');

    if (!canInventory || !canSales || !canSalesAnalytics || !canAccountsReceivable) return;

    if (role.value === 'owner') {
        canInventory.checked = true;
        canSales.checked = true;
        canSalesAnalytics.checked = true;
        canAccountsReceivable.checked = true;
        setModuleCheckboxState(false);
    } else {
        canInventory.checked = true;
        canSales.checked = true;
        canSalesAnalytics.checked = false;
        canAccountsReceivable.checked = true;
        setModuleCheckboxState(false);
    }

    syncRoleControls();
    updateRoleNote();
}

function updateRoleNote() {
    const role = document.getElementById('role');
    const roleNote = document.getElementById('roleNote');

    if (!role || !roleNote) return;

    const moduleInputs = [
        ['can_inventory', 'Inventory'],
        ['can_sales', 'Sales'],
        ['can_sales_analytics', 'Sales Analytics'],
        ['can_accounts_receivable', 'Accounts Receivable']
    ];
    const selectedModules = moduleInputs
        .filter(function(module) {
            const input = document.getElementById(module[0]);
            return input && input.checked;
        })
        .map(function(module) {
            return module[1];
        });
    const roleLabel = role.value === 'owner' ? 'Owner' : 'Employee';

    roleNote.textContent = selectedModules.length > 0
        ? roleLabel + ' currently has access to: ' + selectedModules.join(', ') + '.'
        : roleLabel + ' currently has no module access selected.';
}

function handleRoleChange() {
    applyRoleDefaults();
}

function closeEditModal() {
    const overlay = document.getElementById('editModalOverlay');
    if (!overlay) return;

    overlay.classList.remove('show');
    overlay.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';

    const url = new URL(window.location.href);
    url.searchParams.delete('edit');
    window.history.replaceState({}, '', url.pathname + (url.search ? url.search : ''));
}

function bindEditModal() {
    const overlay = document.getElementById('editModalOverlay');
    if (!overlay) return;

    document.body.style.overflow = 'hidden';
    syncRoleControls();
    updateRoleNote();

    [
        'can_inventory',
        'can_sales',
        'can_sales_analytics',
        'can_accounts_receivable'
    ].forEach(function(id) {
        const permissionInput = document.getElementById(id);
        if (permissionInput && permissionInput.dataset.roleNoteBound !== '1') {
            permissionInput.dataset.roleNoteBound = '1';
            permissionInput.addEventListener('change', updateRoleNote);
        }
    });

    const closeButton = overlay.querySelector('.modal-close-btn');
    if (closeButton) {
        window.requestAnimationFrame(function() {
            closeButton.focus();
        });
    }
}

function openEditModal(editUrl, trigger) {
    if (!editUrl) return;

    if (trigger && trigger.getAttribute('aria-busy') === 'true') {
        return;
    }

    if (trigger) {
        trigger.setAttribute('aria-busy', 'true');
        trigger.dataset.originalText = trigger.textContent;
        trigger.textContent = 'Loading...';
    }

    fetch(editUrl, {
        headers: {
            'Accept': 'text/html'
        }
    })
    .then(function(response) {
        if (!response.ok) {
            throw new Error('Unable to load this user account.');
        }
        return response.text();
    })
    .then(function(html) {
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');
        const incomingModal = doc.getElementById('editModalOverlay');

        if (!incomingModal) {
            throw new Error('The user editor could not be loaded. Please try again.');
        }

        const existingModal = document.getElementById('editModalOverlay');
        if (existingModal) {
            existingModal.remove();
        }

        document.body.appendChild(document.importNode(incomingModal, true));

        const targetUrl = new URL(editUrl, window.location.href);
        window.history.replaceState({}, '', targetUrl.pathname + targetUrl.search);

        bindEditModal();
        rebindTableArea();
    })
    .catch(function(error) {
        showCustomAlert(error.message || 'Unable to open the user editor.');
    })
    .finally(function() {
        if (trigger && trigger.isConnected) {
            trigger.removeAttribute('aria-busy');
            trigger.textContent = trigger.dataset.originalText || 'Edit';
            delete trigger.dataset.originalText;
        }
    });
}

function showCustomConfirm(message, onConfirm) {
    const overlay = document.getElementById('customConfirmOverlay');
    const messageBox = document.getElementById('customConfirmMessage');
    const okBtn = document.getElementById('customConfirmOk');
    const cancelBtn = document.getElementById('customConfirmCancel');

    if (!overlay || !messageBox || !okBtn || !cancelBtn) return;

    messageBox.textContent = message;
    overlay.classList.add('show');
    document.body.style.overflow = 'hidden';

    const close = function() {
        overlay.classList.remove('show');
        document.body.style.overflow = '';
        okBtn.onclick = null;
        cancelBtn.onclick = null;
    };

    cancelBtn.onclick = close;
    okBtn.onclick = function() {
        close();
        if (typeof onConfirm === 'function') {
            onConfirm();
        }
    };
}

function showCustomAlert(message) {
    const overlay = document.getElementById('customAlertOverlay');
    const messageBox = document.getElementById('customAlertMessage');

    if (!overlay || !messageBox) return;

    messageBox.textContent = message;
    overlay.classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeCustomAlert() {
    const overlay = document.getElementById('customAlertOverlay');
    if (!overlay) return;

    overlay.classList.remove('show');
    document.body.style.overflow = '';
}

function rebindTableArea() {
    document.querySelectorAll('.edit-user-btn').forEach(function(button) {
        if (button.dataset.editBound === '1') return;
        button.dataset.editBound = '1';

        button.addEventListener('click', function(e) {
            e.preventDefault();
            openEditModal(button.href, button);
        });
    });

    document.querySelectorAll('.unlock-user-form').forEach(function(form) {
        if (form.dataset.unlockBound === '1') return;
        form.dataset.unlockBound = '1';

        form.addEventListener('submit', function(e) {
            if (form.dataset.confirmed === '1') {
                form.dataset.confirmed = '0';
                return true;
            }

            e.preventDefault();

            const username = form.getAttribute('data-username') || 'this user';

            showCustomConfirm(
                'Are you sure you want to unlock the account of ' + username + '? This will restore the full 5-attempt security allowance.',
                function() {
                    form.dataset.confirmed = '1';
                    form.submit();
                }
            );
        });
    });
}

document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('filterForm');
    const searchInput = document.getElementById('searchInput');
    const roleFilter = document.getElementById('roleFilter');
    const statusFilter = document.getElementById('statusFilter');
    const alertOk = document.getElementById('customAlertOk');

    let controller = null;

    function updateUsers() {
        if (!form) return;

        const params = new URLSearchParams(new FormData(form));

        if (controller) {
            controller.abort();
        }

        controller = new AbortController();

        const currentWrap = document.getElementById('manageUsersTableWrap');
        if (currentWrap) {
            currentWrap.classList.add('loading');
        }

        fetch('manage_users.php?' + params.toString(), {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            signal: controller.signal
        })
        .then(function(response) {
            return response.text();
        })
        .then(function(html) {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const newContainer = doc.getElementById('manageUsersContainer');

            if (newContainer) {
                const oldContainer = document.getElementById('manageUsersContainer');
                oldContainer.outerHTML = newContainer.outerHTML;
                const qs = params.toString();
                window.history.replaceState({}, '', 'manage_users.php' + (qs ? '?' + qs : ''));
                rebindTableArea();
            }
        })
        .catch(function(error) {
            if (error.name !== 'AbortError') {
                console.error('Manage users live filter error:', error);
            }
        })
        .finally(function() {
            const newWrap = document.getElementById('manageUsersTableWrap');
            if (newWrap) newWrap.classList.remove('loading');
        });
    }

    if (searchInput) {
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                updateUsers();
            }
        });
    }

    if (roleFilter) {
        roleFilter.addEventListener('change', updateUsers);
    }

    if (statusFilter) {
        statusFilter.addEventListener('change', updateUsers);
    }

    if (alertOk) {
        alertOk.addEventListener('click', closeCustomAlert);
    }

    bindEditModal();
    rebindTableArea();
});

document.addEventListener('click', function(e) {
    const confirmModal = document.getElementById('customConfirmOverlay');
    const alertModal = document.getElementById('customAlertOverlay');

    if (e.target === confirmModal) {
        confirmModal.classList.remove('show');
        document.body.style.overflow = '';
    }

    if (e.target === alertModal) {
        closeCustomAlert();
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeEditModal();
        closeCustomAlert();
        const confirmModal = document.getElementById('customConfirmOverlay');
        if (confirmModal) {
            confirmModal.classList.remove('show');
            document.body.style.overflow = '';
        }
    }
});

(function() {
    const backToTopBtn = document.getElementById('backToTopBtn');
    if (!backToTopBtn) return;

    function toggleBackToTop() {
        if (window.scrollY > 300) {
            backToTopBtn.classList.add('show');
        } else {
            backToTopBtn.classList.remove('show');
        }
    }

    window.addEventListener('scroll', toggleBackToTop, { passive: true });
    toggleBackToTop();

    backToTopBtn.addEventListener('click', function() {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
})();
</script>
</body>
</html>
