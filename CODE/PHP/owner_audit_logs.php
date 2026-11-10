<?php
require_once __DIR__ . '/config.php';

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
    error_log('NexGen owner audit-log verification failed: ' . $conn->error);
    http_response_code(503);
    exit('Audit logs are temporarily unavailable.');
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

$search = trim((string)($_GET['search'] ?? ''));
$action = trim((string)($_GET['action'] ?? ''));
$params = [$ownerId];
$types = 'i';
$where = ' WHERE l.admin_id = ? ';

if ($action !== '') {
    $where .= ' AND l.action = ? ';
    $params[] = $action;
    $types .= 's';
}
if ($search !== '') {
    $where .= " AND (
        l.action LIKE ?
        OR l.target_type LIKE ?
        OR CAST(l.target_id AS CHAR) LIKE ?
        OR l.description LIKE ?
        OR DATE_FORMAT(l.created_at, '%b %d, %Y %h:%i %p') LIKE ?
    ) ";
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like);
    $types .= 'sssss';
}

$actions = [];
$actionStmt = $conn->prepare(
    'SELECT DISTINCT action FROM admin_logs WHERE admin_id = ? ORDER BY action ASC'
);
if (!$actionStmt) {
    error_log('NexGen owner audit-log action query failed: ' . $conn->error);
    http_response_code(503);
    exit('Audit logs are temporarily unavailable.');
}
$actionStmt->bind_param('i', $ownerId);
$actionStmt->execute();
$actionResult = $actionStmt->get_result();
while ($row = $actionResult->fetch_assoc()) {
    $actions[] = (string)$row['action'];
}
$actionStmt->close();

$logs = [];
$stmt = $conn->prepare(
    "SELECT l.id, l.admin_id, l.action, l.target_type, l.target_id,
            l.description, l.previous_hash, l.log_hash, l.ip_address,
            l.user_agent, l.created_at
     FROM admin_logs l
     {$where}
     ORDER BY l.created_at DESC, l.id DESC
     LIMIT 500"
);
if (!$stmt) {
    error_log('NexGen owner audit-log query failed: ' . $conn->error);
    http_response_code(503);
    exit('Audit logs are temporarily unavailable.');
}
$stmt->bind_param($types, ...$params);
if (!$stmt->execute()) {
    error_log('NexGen owner audit-log execution failed: ' . $stmt->error);
    $stmt->close();
    http_response_code(503);
    exit('Audit logs are temporarily unavailable.');
}
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $logs[] = $row;
}
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php include __DIR__ . '/nexgen_favicon/favicon.php'; ?>
    <?php include __DIR__ . '/theme_init.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Logs - NexGen</title>
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
                <span class="owner-module-eyebrow"><i class="bi bi-shield-check"></i> Accountability</span>
                <h1>Audit Logs</h1>
                <p>Review and verify actions performed from your owner account. Records are shown only to the account that created them.</p>
            </div>
        </div>

        <section class="owner-module-card" aria-label="Owner audit records">
            <div class="owner-module-toolbar">
                <form method="GET" action="<?php echo e(nxAppUrl('owner_audit_logs.php')); ?>">
                    <label class="visually-hidden" for="auditSearch">Search audit records</label>
                    <input id="auditSearch" name="search" value="<?php echo e($search); ?>" placeholder="Search action, target, details, or date" autocomplete="off">
                    <label class="visually-hidden" for="auditAction">Filter by action</label>
                    <select id="auditAction" name="action">
                        <option value="">All actions</option>
                        <?php foreach ($actions as $availableAction): ?>
                            <option value="<?php echo e($availableAction); ?>" <?php echo $action === $availableAction ? 'selected' : ''; ?>>
                                <?php echo e(ucwords(str_replace('_', ' ', $availableAction))); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button class="owner-module-button" type="submit"><i class="bi bi-search"></i> Filter</button>
                </form>
                <span class="owner-module-count"><?php echo count($logs); ?> recent record<?php echo count($logs) === 1 ? '' : 's'; ?></span>
            </div>

            <div class="owner-module-table-wrap">
                <table class="owner-module-table">
                    <thead>
                        <tr>
                            <th>Date &amp; time</th>
                            <th>Action</th>
                            <th>Target</th>
                            <th>Details</th>
                            <th>Integrity</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($logs === []): ?>
                        <tr>
                            <td colspan="5" class="owner-module-empty">
                                <i class="bi bi-journal-check" aria-hidden="true"></i>
                                No owner actions have been recorded yet.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <?php
                            $integrityValid = !empty($log['log_hash']) && verifyAdminLogRowIntegrity($log);
                            $target = trim((string)$log['target_type'] . ' #' . (int)$log['target_id']);
                            ?>
                            <tr>
                                <td data-label="Date &amp; time"><?php echo e(date('M d, Y h:i A', strtotime((string)$log['created_at']))); ?></td>
                                <td data-label="Action"><?php echo e(ucwords(str_replace('_', ' ', (string)$log['action']))); ?></td>
                                <td data-label="Target"><?php echo e($target); ?></td>
                                <td data-label="Details" class="owner-module-log-description"><?php echo e($log['description'] ?? ''); ?></td>
                                <td data-label="Integrity">
                                    <span class="owner-module-integrity <?php echo $integrityValid ? 'valid' : 'invalid'; ?>">
                                        <i class="bi <?php echo $integrityValid ? 'bi-patch-check-fill' : 'bi-exclamation-triangle-fill'; ?>" aria-hidden="true"></i>
                                        <?php echo $integrityValid ? 'Verified' : 'Check record'; ?>
                                    </span>
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
