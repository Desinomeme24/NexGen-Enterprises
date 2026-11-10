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
    exit('Employee requests are temporarily unavailable.');
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

$status = (string)($_GET['status'] ?? 'all');
$status = in_array($status, ['all', 'pending', 'resubmit'], true) ? $status : 'all';
$search = trim((string)($_GET['search'] ?? ''));
$requests = [];

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
    }
} catch (Throwable $error) {
    error_log('NexGen Manage Employees request lookup failed: ' . $error->getMessage());
    http_response_code(503);
    exit('Employee requests are temporarily unavailable. Please try again later.');
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
            <div>
                <span class="owner-module-eyebrow"><i class="bi bi-people-fill"></i> Team access</span>
                <h1>Manage Employees</h1>
                <p>Review employee applications for your business branches. Verify each applicant's business and branch codes before granting access.</p>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="owner-module-card" role="status" style="padding:14px 18px;margin-bottom:18px;">
                <?php echo e($flash['message'] ?? ''); ?>
            </div>
        <?php endif; ?>

        <section class="owner-module-card" aria-label="Employee requests">
            <div class="owner-module-toolbar">
                <form method="GET" action="<?php echo e(nxAppUrl('manage_employees.php')); ?>">
                    <label class="visually-hidden" for="employeeRequestSearch">Search employee requests</label>
                    <input id="employeeRequestSearch" name="search" value="<?php echo e($search); ?>" placeholder="Search name, account, or branch" autocomplete="off">
                    <label class="visually-hidden" for="employeeRequestStatus">Filter by status</label>
                    <select id="employeeRequestStatus" name="status">
                        <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All open requests</option>
                        <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="resubmit" <?php echo $status === 'resubmit' ? 'selected' : ''; ?>>Resubmission</option>
                    </select>
                    <button class="owner-module-button" type="submit"><i class="bi bi-search"></i> Filter</button>
                </form>
                <span class="owner-module-count"><?php echo count($requests); ?> request<?php echo count($requests) === 1 ? '' : 's'; ?></span>
            </div>

            <div class="owner-module-table-wrap">
                <table class="owner-module-table">
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
                            <tr>
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
                                        Review <i class="bi bi-arrow-up-right" aria-hidden="true"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>
<script src="<?php echo e(nxCodeUrl('JS/header.js')); ?>" defer></script>
</body>
</html>
