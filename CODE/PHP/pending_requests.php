<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/tenant_helper.php';

enforceSessionTimeout();

if (!isset($_SESSION['user_id'])) {
    header("Location: /NexGen/CODE/PHP/index.php");
    exit();
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'system_admin') {
    header("Location: /NexGen/CODE/PHP/dashboard.php");
    exit();
}

if (!function_exists('e')) {
    function e($str): string
    {
        return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
    }
}

function statusIcon(string $status): string {
    switch ($status) {
        case 'approved':
        case 'active':
            return 'bi-check-circle-fill';
        case 'rejected':
            return 'bi-x-circle-fill';
        case 'resubmit':
            return 'bi-arrow-repeat';
        case 'pending':
        default:
            return 'bi-hourglass-split';
    }
}

function requestStatusLabel(array $request): string {
    $status = (string)($request['request_status'] ?? 'pending');
    return $status === 'resubmit' ? 'Resubmission' : ucfirst($status);
}

$profileImage = !empty($_SESSION['profile_image']) ? $_SESSION['profile_image'] : 'uploads/default.png';
$fullName     = $_SESSION['full_name'] ?? 'System Administrator';

$status = $_GET['status'] ?? 'all';
$search = trim($_GET['search'] ?? '');

$allowedStatuses = ['all', 'pending', 'approved', 'rejected', 'resubmit'];
if (!in_array($status, $allowedStatuses, true)) {
    $status = 'all';
}

$accountWhere = " WHERE 1=1 ";
$accountParams = [];
$accountTypes = "";

if ($status !== 'all') {
    $accountWhere .= " AND request_status = ? ";
    $accountParams[] = $status;
    $accountTypes .= "s";
}

if ($search !== '') {
    $accountWhere .= " AND (full_name LIKE ? OR username LIKE ? OR business_name LIKE ?) ";
    $like = "%{$search}%";
    for ($i = 0; $i < 3; $i++) {
        $accountParams[] = $like;
    }
    $accountTypes .= "sss";
}

$requests = [];
$sql = "SELECT * FROM registration_requests {$accountWhere}";
$stmt = $conn->prepare($sql);
if ($stmt) {
    if (!empty($accountParams)) {
        $stmt->bind_param($accountTypes, ...$accountParams);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row['request_source'] = 'account';
        $row['source_label'] = $row['requested_role'] === 'owner'
            ? 'Owner Account'
            : 'Employee Account';
        $requests[] = $row;
    }
    $stmt->close();
}

if (nxWorkspaceRequestSchemaReady($conn) && $status !== 'resubmit') {
    $workspaceWhere = " WHERE 1=1 ";
    $workspaceParams = [];
    $workspaceTypes = "";

    if ($status !== 'all') {
        $workspaceWhere .= " AND wr.request_status = ? ";
        $workspaceParams[] = $status;
        $workspaceTypes .= "s";
    }

    if ($search !== '') {
        $workspaceWhere .= " AND (
            u.full_name LIKE ? OR u.username LIKE ? OR wr.business_name LIKE ?
        ) ";
        $like = "%{$search}%";
        for ($i = 0; $i < 3; $i++) {
            $workspaceParams[] = $like;
        }
        $workspaceTypes .= "sss";
    }

    $workspaceSql =
        "SELECT wr.*, u.full_name, u.username, u.email,
                'owner' AS requested_role,
                0 AS possible_duplicate,
                NULL AS duplicate_reason
         FROM workspace_requests wr
         INNER JOIN users u ON u.id = wr.requested_by
         {$workspaceWhere}";
    $workspaceStmt = $conn->prepare($workspaceSql);
    if ($workspaceStmt) {
        if (!empty($workspaceParams)) {
            $workspaceStmt->bind_param($workspaceTypes, ...$workspaceParams);
        }
        $workspaceStmt->execute();
        $workspaceResult = $workspaceStmt->get_result();
        while ($row = $workspaceResult->fetch_assoc()) {
            $row['request_source'] = 'workspace';
            $row['source_label'] = $row['request_type'] === 'business'
                ? 'New Business'
                : 'New Branch';
            $requests[] = $row;
        }
        $workspaceStmt->close();
    }
}

usort($requests, static function (array $left, array $right): int {
    return strcmp((string)$right['created_at'], (string)$left['created_at']);
});

function renderPendingRequestsTable(array $requests): void
{
    ?>
    <div class="table-wrap pending-requests-table-wrap" id="pendingRequestsTableWrap" data-mobile-cards>
        <table>
            <thead>
                <tr>
                    <th>NO.</th>
                    <th>Applicant</th>
                    <th>Request Type</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Business / Branch</th>
                    <th>Status</th>
                    <th>Submitted</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($requests)): ?>
                <tr>
                    <td colspan="9" class="pending-empty-row">No matching account or workspace requests found.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($requests as $index => $row): ?>
                    <tr class="pending-request-row">
                        <td data-label="No." class="pending-request-number"><?php echo count($requests) - (int)$index; ?></td>
                        <td data-label="Applicant" class="pending-request-applicant">
                            <strong><?php echo e($row['full_name']); ?></strong>
                        </td>
                        <td data-label="Request Type" class="pending-request-type"><?php echo e($row['source_label']); ?></td>
                        <td data-label="Username" class="pending-request-username">@<?php echo e($row['username']); ?></td>
                        <td data-label="Email" class="pending-request-email"><?php echo e($row['email']); ?></td>
                        <td data-label="Business / Branch" class="pending-request-business">
                            <strong><?php echo e($row['business_name']); ?></strong><br>
                            <small>
                                <?php if ($row['request_source'] === 'workspace'): ?>
                                    <?php echo e($row['branch_name'] . ' — ' . $row['business_address']); ?>
                                <?php elseif ($row['requested_role'] === 'employee'): ?>
                                    <?php echo e(($row['business_code'] ?: 'No business code') . ' / ' . ($row['branch_code'] ?: 'No branch code')); ?>
                                <?php else: ?>
                                    <?php echo e($row['business_type'] . ' — ' . $row['business_address']); ?>
                                <?php endif; ?>
                            </small>
                        </td>
                        <td data-label="Status" class="pending-request-status">
                            <span class="badge badge-<?php echo e($row['request_status']); ?>">
                                <i class="bi <?php echo e(statusIcon($row['request_status'])); ?>"></i>
                                <?php echo e(requestStatusLabel($row)); ?>
                            </span>
                        </td>
                        <td data-label="Submitted" class="pending-request-submitted"><?php echo e(date('M d, Y h:i A', strtotime($row['created_at']))); ?></td>
                        <td data-label="Action" class="pending-request-action">
                            <a
                                class="btn btn-silver js-request-view"
                                href="<?php echo $row['request_source'] === 'workspace' ? 'view_workspace_request.php' : 'view_request.php'; ?>?id=<?php echo (int)$row['id']; ?>"
                                data-request-title="<?php echo e($row['source_label']); ?>"
                            >
                                <i class="bi bi-eye-fill"></i> View
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

if (
    !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
) {
    renderPendingRequestsTable($requests);
    exit();
}

$flash = $_SESSION['flash'] ?? null;
if (!$flash && isset($_SESSION['success'])) {
    $flash = ['type' => 'notice-success', 'message' => (string)$_SESSION['success']];
}
if (!$flash && isset($_SESSION['error'])) {
    $flash = ['type' => 'notice-error', 'message' => (string)$_SESSION['error']];
}
unset($_SESSION['flash'], $_SESSION['success'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php include __DIR__ . '/nexgen_favicon/favicon.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Pending Requests - NexGen</title>
    <script>
        /* THEME: apply saved preference before first paint to avoid a flash */
        (function () {
            try {
                var saved = localStorage.getItem('nexgen-theme');
                if (saved === 'light' || saved === 'dark') {
                    document.documentElement.setAttribute('data-theme', saved);
                }
            } catch (e) {}
        })();
    </script>
    <link rel="stylesheet" href="/NexGen/CODE/STYLE/admin_module.css">

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <style>
        .pending-requests-table-wrap {
            max-height: 460px;
            overflow-y: auto;
            overflow-x: auto;
            border-radius: 18px;
            position: relative;
            transition: opacity 0.15s ease;
        }

        @media (max-width: 700px) {
            .pending-requests-table-wrap {
                max-height: none;
                overflow: visible;
            }
        }

        .pending-requests-table-wrap.loading {
            opacity: 0.62;
            pointer-events: none;
        }

        .pending-requests-table-wrap table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        .pending-requests-table-wrap thead th {
            position: sticky;
            top: 0;
            z-index: 5;
            background: rgba(8, 16, 50, 0.94);
            backdrop-filter: blur(8px);
        }

        html[data-theme="light"] .pending-requests-table-wrap thead th {
            background: rgba(214, 235, 255, 0.92);
        }

        .pending-requests-table-wrap::-webkit-scrollbar {
            width: 10px;
            height: 10px;
        }

        .pending-requests-table-wrap::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.25);
            border-radius: 999px;
        }

        .pending-requests-table-wrap::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 999px;
        }

        .request-view-overlay {
            position: fixed;
            inset: 0;
            z-index: 100000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: rgba(2, 8, 28, 0.72);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transition: opacity 0.2s ease, visibility 0.2s ease;
        }

        .request-view-overlay.show {
            opacity: 1;
            visibility: visible;
            pointer-events: auto;
        }

        .request-view-dialog {
            width: min(1180px, 100%);
            height: min(900px, calc(100vh - 48px));
            display: flex;
            flex-direction: column;
            overflow: hidden;
            border: 1px solid rgba(125, 211, 252, 0.30);
            border-radius: 22px;
            background: var(--panel);
            box-shadow: 0 28px 80px rgba(0, 0, 0, 0.46);
            transform: translateY(14px) scale(0.985);
            transition: transform 0.2s ease;
        }

        .request-view-overlay.show .request-view-dialog {
            transform: translateY(0) scale(1);
        }

        .request-view-modal-header {
            flex: 0 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            min-height: 64px;
            padding: 14px 18px 14px 22px;
            border-bottom: 1px solid var(--line);
            background: var(--panel-2);
        }

        .request-view-modal-header h2 {
            margin: 0;
            color: var(--text);
            font-size: 18px;
            font-weight: 800;
        }

        .request-view-close {
            width: 38px;
            height: 38px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
            border: 1px solid var(--line);
            border-radius: 11px;
            background: var(--card);
            color: var(--text);
            cursor: pointer;
            font-size: 20px;
            transition: 0.18s ease;
        }

        .request-view-close:hover {
            border-color: var(--gold);
            color: var(--gold);
            transform: translateY(-1px);
        }

        .request-view-frame-wrap {
            position: relative;
            flex: 1 1 auto;
            min-height: 0;
            background: var(--bg);
        }

        .request-view-frame {
            width: 100%;
            height: 100%;
            display: block;
            border: 0;
            background: var(--bg);
            opacity: 1;
            transition: opacity 0.16s ease;
        }

        .request-view-frame-wrap.loading .request-view-frame {
            opacity: 0;
        }

        .request-view-loading {
            position: absolute;
            inset: 0;
            z-index: 2;
            display: none;
            place-items: center;
            color: var(--muted);
            font-size: 14px;
            font-weight: 750;
        }

        .request-view-frame-wrap.loading .request-view-loading {
            display: grid;
        }

        .request-view-loading i {
            margin-right: 8px;
            color: var(--gold);
            font-size: 20px;
        }

        @media (max-width: 700px) {
            .request-view-overlay {
                padding: 0;
            }

            .request-view-dialog {
                width: 100%;
                height: 100%;
                max-height: none;
                border: 0;
                border-radius: 0;
            }

            .request-view-modal-header {
                min-height: 58px;
                padding: 10px 12px 10px 16px;
            }
        }

        .logout-confirm-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.45);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transition: 0.25s ease;
            z-index: 99999;
            padding: 20px;
        }

        .logout-confirm-overlay.show {
            opacity: 1;
            visibility: visible;
            pointer-events: auto;
        }

        .logout-confirm-box {
            width: 100%;
            max-width: 360px;
            background: linear-gradient(180deg, #1f3c88 0%, #1a3578 100%);
            border-radius: 20px;
            padding: 26px 22px;
            box-shadow: 0 20px 45px rgba(0, 0, 0, 0.30);
            text-align: center;
            color: #fff;
            border: 1px solid rgba(255, 255, 255, 0.10);
            transform: translateY(15px) scale(0.96);
            transition: 0.25s ease;
        }

        .logout-confirm-overlay.show .logout-confirm-box {
            transform: translateY(0) scale(1);
        }

        .logout-confirm-icon {
            width: 62px;
            height: 62px;
            margin: 0 auto 14px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.12);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            color: #f7d98b;
        }

        .logout-confirm-box h3 {
            margin: 0 0 8px;
            font-size: 25px;
            font-weight: 800;
            color: #fff;
        }

        .logout-confirm-box p {
            margin: 0 0 22px;
            font-size: 15px;
            color: rgba(255, 255, 255, 0.88);
            line-height: 1.5;
        }

        .logout-confirm-actions {
            display: flex;
            justify-content: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .logout-btn-cancel,
        .logout-btn-confirm {
            border: none;
            border-radius: 12px;
            padding: 11px 18px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            min-width: 120px;
            transition: 0.2s ease;
        }

        .logout-btn-cancel {
            background: rgba(255, 255, 255, 0.14);
            color: #fff;
        }

        .logout-btn-cancel:hover {
            background: rgba(255, 255, 255, 0.22);
        }

        .logout-btn-confirm {
            background: #f7d98b;
            color: #17306b;
        }

        .logout-btn-confirm:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 20px rgba(247, 217, 139, 0.25);
        }

        @media (max-width: 768px) {
            .filters {
                display: flex !important;
                flex-direction: row !important;
                flex-wrap: wrap !important;
                align-items: center !important;
                gap: 12px;
            }

            /* Search bar always gets its own full-width row */
            .filters .input.flex-1 {
                flex: 1 1 100% !important;
                width: 100% !important;
                max-width: 100% !important;
                height: 46px !important;
                min-height: 46px !important;
                max-height: 46px !important;
            }

            /* Status select and Reset button share the next row:
               select flexes to fill the leftover space, Reset stays
               only as wide as its label needs. */
            .filters .select.w-240 {
                flex: 1 1 auto !important;
                width: auto !important;
                max-width: 100% !important;
                min-width: 0 !important;
                height: 46px !important;
                min-height: 46px !important;
                max-height: 46px !important;
            }

            .filters .btn {
                flex: 0 0 auto !important;
                width: auto !important;
                max-width: 100% !important;
                white-space: nowrap;
            }

            .pending-requests-table-wrap {
                max-height: none;
            }

            .pending-requests-table-wrap table {
                min-width: 640px;
            }
        }

        /* Keep the Business / Branch field aligned with the other mobile-card fields. */
        @media (max-width: 700px) {
            .pending-requests-table-wrap td[data-label="Business / Branch"],
            .pending-requests-table-wrap td[data-label="Business / Branch"] strong,
            .pending-requests-table-wrap td[data-label="Business / Branch"] small {
                text-align: left !important;
            }

            .pending-requests-table-wrap td[data-label="Business / Branch"] strong,
            .pending-requests-table-wrap td[data-label="Business / Branch"] small {
                display: block;
                width: 100%;
            }
        }

        /* =========================
           PENDING REQUESTS PAGE
        ========================= */
        .pending-requests-content {
            min-width: 0;
        }

        .pending-page-heading {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .pending-page-heading > i {
            display: grid;
            place-items: center;
            width: 46px;
            height: 46px;
            flex: 0 0 46px;
            border: 1px solid var(--glass-border);
            border-radius: 14px;
            background: var(--card);
            color: var(--gold);
            font-size: 1.25rem;
        }

        .pending-page-heading h1 {
            min-width: 0;
            overflow-wrap: anywhere;
        }

        .pending-filter-note {
            flex: 1 1 100%;
            margin: -2px 0 2px;
            color: var(--faint);
            font-size: 0.72rem;
            line-height: 1.4;
        }

        .pending-requests-table-wrap th:first-child,
        .pending-requests-table-wrap td:first-child {
            text-align: left;
        }

        .pending-request-applicant strong,
        .pending-request-business strong {
            color: var(--text);
        }

        .pending-request-business small {
            color: var(--muted);
            line-height: 1.4;
        }

        .pending-request-username {
            color: var(--gold);
            font-weight: 750;
        }

        .pending-request-email {
            overflow-wrap: anywhere;
        }

        .pending-empty-row {
            padding: 34px 18px !important;
            color: var(--muted);
            text-align: center !important;
        }

        .request-toast-region {
            position: fixed;
            top: max(18px, env(safe-area-inset-top));
            right: max(18px, env(safe-area-inset-right));
            z-index: 120000;
            display: grid;
            gap: 10px;
            width: min(390px, calc(100vw - 36px));
            pointer-events: none;
        }

        .request-toast {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr) auto;
            align-items: start;
            gap: 11px;
            padding: 14px;
            border: 1px solid var(--glass-border);
            border-radius: 15px;
            background: rgba(8, 20, 58, 0.97);
            color: #eef5ff;
            box-shadow: 0 18px 44px rgba(0, 0, 0, 0.36);
            opacity: 0;
            transform: translateY(-10px);
            transition: opacity 0.2s ease, transform 0.2s ease;
            pointer-events: auto;
        }

        html[data-theme="light"] .request-toast {
            background: rgba(255, 255, 255, 0.98);
            color: #16224a;
        }

        .request-toast.show {
            opacity: 1;
            transform: translateY(0);
        }

        .request-toast.is-success { border-color: rgba(52, 211, 153, 0.46); }
        .request-toast.is-error { border-color: rgba(239, 68, 68, 0.48); }

        .request-toast-icon {
            color: var(--success);
            font-size: 1.1rem;
        }

        .request-toast.is-error .request-toast-icon { color: #ff8f98; }

        .request-toast-message {
            min-width: 0;
            font-size: 0.83rem;
            font-weight: 700;
            line-height: 1.45;
            overflow-wrap: anywhere;
        }

        .request-toast-close {
            width: 30px;
            height: 30px;
            display: grid;
            place-items: center;
            border: 0;
            border-radius: 9px;
            background: transparent;
            color: inherit;
            cursor: pointer;
            opacity: 0.72;
        }

        .request-toast-close:hover,
        .request-toast-close:focus-visible {
            background: var(--card-hover);
            opacity: 1;
            outline: none;
        }

        @media (max-width: 1100px) {
            .admin-content.pending-requests-content {
                padding-top: calc(84px + env(safe-area-inset-top));
                padding-right: max(22px, env(safe-area-inset-right));
                padding-bottom: calc(28px + env(safe-area-inset-bottom));
                padding-left: max(22px, env(safe-area-inset-left));
            }
        }

        @media (max-width: 700px) {
            .admin-content.pending-requests-content {
                padding-top: calc(80px + env(safe-area-inset-top));
                padding-right: max(12px, env(safe-area-inset-right));
                padding-bottom: calc(22px + env(safe-area-inset-bottom));
                padding-left: max(12px, env(safe-area-inset-left));
            }

            .pending-page-heading {
                align-items: flex-start;
            }

            .pending-page-heading > i {
                width: 42px;
                height: 42px;
                flex-basis: 42px;
                border-radius: 12px;
            }

            .pending-page-heading h1 {
                font-size: clamp(1.35rem, 7vw, 1.8rem);
                line-height: 1.22;
            }

            .pending-requests-content .panel {
                border-radius: 18px;
            }

            .pending-requests-content .panel-header,
            .pending-requests-content .panel-body {
                padding: 14px;
            }

            .pending-requests-content .filters {
                gap: 10px;
            }

            .pending-filter-note {
                order: 2;
            }

            .pending-requests-content .filters .select { order: 3; }
            .pending-requests-content .filters .btn { order: 4; min-height: 46px; }

            .pending-requests-content .pending-requests-table-wrap[data-mobile-cards] {
                width: 100%;
                min-width: 0;
                max-height: none;
                overflow: visible;
                border: 0;
                border-radius: 0;
                background: transparent;
            }

            .pending-requests-content .pending-requests-table-wrap[data-mobile-cards] table {
                width: 100%;
                min-width: 0;
                table-layout: auto;
            }

            .pending-requests-content .pending-requests-table-wrap[data-mobile-cards] thead {
                display: none;
            }

            .pending-requests-content .pending-requests-table-wrap[data-mobile-cards] tbody {
                display: block;
                width: 100%;
            }

            .pending-requests-content .pending-requests-table-wrap[data-mobile-cards] tr {
                width: 100%;
                min-width: 0;
                display: grid;
                grid-template-columns: minmax(0, 1fr) auto;
                grid-template-areas:
                    "number status"
                    "applicant applicant"
                    "username username"
                    "email email"
                    "business business"
                    "type submitted"
                    "action action";
                gap: 7px 12px;
                margin: 0 0 12px;
                padding: 15px;
                border: 1px solid var(--glass-border);
                border-radius: 16px;
                background: var(--glass);
                box-shadow: 0 9px 24px rgba(3, 3, 30, 0.18);
            }

            .pending-requests-content .pending-requests-table-wrap[data-mobile-cards] tbody tr:nth-child(even) {
                background: var(--glass);
            }

            .pending-requests-content .pending-requests-table-wrap[data-mobile-cards] td {
                min-width: 0;
                display: block;
                padding: 0;
                border: 0;
                background: transparent !important;
                text-align: left;
                overflow-wrap: anywhere;
            }

            .pending-requests-content .pending-request-number {
                grid-area: number;
                align-self: center;
                color: var(--faint);
                font-size: 0.72rem;
                font-weight: 850;
                letter-spacing: 0.05em;
                text-transform: uppercase;
            }

            .pending-requests-content .pending-request-number::before {
                content: "No. ";
                display: inline;
            }

            .pending-requests-content .pending-request-status {
                grid-area: status;
                align-self: center;
                justify-self: end;
            }

            .pending-requests-content .pending-request-applicant {
                grid-area: applicant;
                padding-top: 10px !important;
                border-top: 1px solid var(--line) !important;
            }

            .pending-requests-content .pending-request-applicant strong {
                display: block;
                font-family: var(--font-display);
                font-size: 1.04rem;
                line-height: 1.35;
            }

            .pending-requests-content .pending-request-username {
                grid-area: username;
                font-size: 0.78rem;
            }

            .pending-requests-content .pending-request-email {
                grid-area: email;
                color: var(--muted);
                font-size: 0.75rem;
            }

            .pending-requests-content .pending-request-business {
                grid-area: business;
                margin-top: 4px;
                padding: 11px 0 !important;
                border-top: 1px solid var(--line) !important;
                border-bottom: 1px solid var(--line) !important;
            }

            .pending-requests-content .pending-request-business strong {
                font-size: 0.9rem;
            }

            .pending-requests-content .pending-request-business small {
                margin-top: 4px;
                font-size: 0.7rem;
            }

            .pending-requests-content .pending-request-type {
                grid-area: type;
                align-self: end;
                color: var(--text);
                font-size: 0.73rem;
                font-weight: 750;
            }

            .pending-requests-content .pending-request-submitted {
                grid-area: submitted;
                align-self: end;
                justify-self: end;
                color: var(--muted);
                font-size: 0.68rem;
                text-align: right;
                white-space: normal;
            }

            .pending-requests-content .pending-request-type::before,
            .pending-requests-content .pending-request-submitted::before {
                display: block;
                margin-bottom: 2px;
                color: var(--faint);
                font-size: 0.58rem;
                font-weight: 800;
                letter-spacing: 0.05em;
                text-transform: uppercase;
            }

            .pending-requests-content .pending-request-type::before { content: "Request type"; }
            .pending-requests-content .pending-request-submitted::before { content: "Submitted"; }

            .pending-requests-content .pending-request-action {
                grid-area: action;
                margin-top: 4px;
                padding-top: 10px !important;
                border-top: 1px solid var(--line) !important;
            }

            .pending-requests-content .pending-request-action .btn {
                width: 100%;
                min-height: 44px;
            }

            .pending-requests-content .pending-empty-row {
                grid-column: 1 / -1;
                padding: 24px 12px !important;
            }

            .request-toast-region {
                top: calc(10px + env(safe-area-inset-top));
                right: max(10px, env(safe-area-inset-right));
                width: calc(100vw - max(20px, env(safe-area-inset-left) + env(safe-area-inset-right)));
            }

            .request-view-dialog {
                height: 100vh;
                height: 100dvh;
            }

            .request-view-modal-header {
                padding:
                    calc(10px + env(safe-area-inset-top))
                    max(12px, env(safe-area-inset-right))
                    10px
                    max(16px, env(safe-area-inset-left));
            }
        }

        @media (max-width: 430px) {
            .pending-requests-content .pending-requests-table-wrap[data-mobile-cards] tr {
                grid-template-areas:
                    "number status"
                    "applicant applicant"
                    "username username"
                    "email email"
                    "business business"
                    "type type"
                    "submitted submitted"
                    "action action";
                padding: 13px;
            }

            .pending-requests-content .pending-request-submitted {
                justify-self: start;
                text-align: left;
            }
        }

        @media (hover: none) and (pointer: coarse) {
            .request-view-close,
            .request-toast-close {
                min-width: 44px;
                min-height: 44px;
            }
        }
    </style>
</head>
<body>
<div class="admin-shell">
    <?php include 'admin_sidebar.php'; ?>

    <main class="admin-content pending-requests-content">
        <div class="topbar">
            <div class="page-title pending-page-heading">
                <i class="bi bi-person-workspace" aria-hidden="true"></i>
                <h1>Account &amp; Workspace Requests</h1>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="notice <?php echo e($flash['type']); ?>" role="status">
                <?php echo e($flash['message']); ?>
            </div>
        <?php endif; ?>

        <section class="panel">
            <div class="panel-header">
                <h2>All Requests</h2>
            </div>
            <div class="panel-body">
                <form method="GET" class="filters pending-request-filters" id="requestFilterForm">
                    <div class="pending-search-control">
                        <div class="pending-search-shell">
                            <i class="bi bi-search" aria-hidden="true"></i>
                            <input
                                class="input pending-search-input"
                                type="search"
                                name="search"
                                id="requestSearchInput"
                                placeholder="Search applicant, username, or business..."
                                value="<?php echo e($search); ?>"
                                autocomplete="off"
                                aria-describedby="pendingSearchHelp"
                            >
                        </div>
                    </div>
                    <select class="select w-240" name="status" id="requestStatusFilter" aria-label="Filter requests by status">
                        <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                        <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="approved" <?php echo $status === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="rejected" <?php echo $status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        <option value="resubmit" <?php echo $status === 'resubmit' ? 'selected' : ''; ?>>Resubmission</option>
                    </select>
                    <button class="btn btn-silver" type="button" id="pendingResetButton">Reset</button>
                </form>

                <div id="pendingRequestsContainer">
                    <?php renderPendingRequestsTable($requests); ?>
                </div>
            </div>
        </section>
    </main>
</div>

<div class="request-toast-region" id="requestToastRegion" aria-live="polite" aria-atomic="true"></div>

<div class="request-view-overlay" id="requestViewOverlay" aria-hidden="true">
    <div class="request-view-dialog" role="dialog" aria-modal="true" aria-labelledby="requestViewModalTitle">
        <div class="request-view-modal-header">
            <h2 id="requestViewModalTitle">Request Details</h2>
            <button type="button" class="request-view-close" id="requestViewClose" aria-label="Close request details">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        </div>
        <div class="request-view-frame-wrap" id="requestViewFrameWrap">
            <div class="request-view-loading" id="requestViewLoading" role="status" aria-live="polite">
                <span><i class="bi bi-arrow-repeat"></i> <span id="requestViewLoadingText">Loading request details...</span></span>
            </div>
            <iframe class="request-view-frame" id="requestViewFrame" title="Request details"></iframe>
        </div>
    </div>
</div>

<div class="logout-confirm-overlay" id="logoutConfirmOverlay">
    <div class="logout-confirm-box">
        <div class="logout-confirm-icon">⇦</div>
        <h3>Log out?</h3>
        <p>Are you sure you want to log out of your account?</p>

        <div class="logout-confirm-actions">
            <button type="button" class="logout-btn-cancel" onclick="closeLogoutModal()">Cancel</button>
            <form action="/NexGen/CODE/PHP/logout.php" method="POST" style="margin:0;">
    <input type="hidden" name="csrf_token" value="<?php echo e(generateCsrfToken('logout_form')); ?>">
    <button type="submit" class="logout-btn-confirm">Log Out</button>
</form>
        </div>
    </div>
</div>

<button type="button" id="backToTopBtn" class="back-to-top-btn" aria-label="Back to top" title="Back to top">
    <i class="bi bi-arrow-up"></i>
</button>

<script src="/NexGen/CODE/JS/admin_module.js"></script>
<script>
function openLogoutModal(event) {
    if (event) event.preventDefault();
    const modal = document.getElementById('logoutConfirmOverlay');
    if (modal) {
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
    }
}

function closeLogoutModal() {
    const modal = document.getElementById('logoutConfirmOverlay');
    if (modal) {
        modal.classList.remove('show');
        document.body.style.overflow = '';
    }
}

document.addEventListener('click', function(e) {
    const modal = document.getElementById('logoutConfirmOverlay');
    if (e.target === modal) {
        closeLogoutModal();
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeLogoutModal();
    }
});

document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('requestFilterForm');
    const searchInput = document.getElementById('requestSearchInput');
    const statusFilter = document.getElementById('requestStatusFilter');
    const resetButton = document.getElementById('pendingResetButton');
    const container = document.getElementById('pendingRequestsContainer');
    const requestViewOverlay = document.getElementById('requestViewOverlay');
    const requestViewClose = document.getElementById('requestViewClose');
    const requestViewFrame = document.getElementById('requestViewFrame');
    const requestViewFrameWrap = document.getElementById('requestViewFrameWrap');
    const requestViewModalTitle = document.getElementById('requestViewModalTitle');
    const requestViewLoadingText = document.getElementById('requestViewLoadingText');
    const toastRegion = document.getElementById('requestToastRegion');

    let controller = null;
    let submittedSearch = searchInput ? searchInput.value.trim() : '';
    let lastRequestViewTrigger = null;
    let previousBodyOverflow = '';
    let requestActionInFlight = false;

    function setRequestViewBusy(message, isAction) {
        if (!requestViewFrameWrap) return;

        requestViewFrameWrap.classList.add('loading');
        requestViewFrameWrap.classList.toggle('processing', isAction === true);
        if (requestViewLoadingText) {
            requestViewLoadingText.textContent = message || 'Processing request...';
        }
    }

    function clearRequestViewBusy() {
        if (!requestViewFrameWrap) return;

        requestViewFrameWrap.classList.remove('loading', 'processing');
        if (requestViewClose) requestViewClose.disabled = false;
        if (requestViewLoadingText) {
            requestViewLoadingText.textContent = 'Loading request details...';
        }
    }

    function actionProcessingMessage(form, submitter) {
        let action = '';
        try {
            const data = new FormData(form);
            action = String(data.get('decision') || data.get('action_type') || '').toLowerCase();
        } catch (error) {}

        if (!action && submitter) {
            action = String(submitter.value || submitter.textContent || '').toLowerCase();
        }

        if (action.indexOf('reject') !== -1) return 'Rejecting request...';
        if (action.indexOf('resubmit') !== -1) return 'Saving resubmission details...';
        return 'Approving request...';
    }

    function beginRequestActionProcessing(message) {
        requestActionInFlight = true;
        if (requestViewClose) requestViewClose.disabled = true;
        setRequestViewBusy(message || 'Processing request...', true);
    }

    function markWorkspaceFormProcessing(form, submitter) {
        const button = submitter || form.querySelector('button[type="submit"], input[type="submit"]');
        const message = actionProcessingMessage(form, button);

        form.setAttribute('aria-busy', 'true');
        if (button) {
            button.classList.add('nexgen-action-processing');
            button.setAttribute('aria-busy', 'true');
            if (button.tagName === 'BUTTON') {
                button.textContent = message;
            }
        }
        beginRequestActionProcessing(message);
    }

    function monitorWorkspaceActionForms(frameDocument) {
        if (!frameDocument || !requestViewFrame || !requestViewFrame.contentWindow) return;

        const frameWindow = requestViewFrame.contentWindow;
        frameDocument.querySelectorAll('form').forEach(function(form) {
            const action = String(form.getAttribute('action') || '');
            const isWorkspaceAction = action.indexOf('workspace_request_review.php') !== -1
                || form.querySelector('[name="decision"]');
            if (!isWorkspaceAction || form.dataset.nexgenProcessingBound === '1') return;

            form.dataset.nexgenProcessingBound = '1';
            form.addEventListener('submit', function(event) {
                Promise.resolve().then(function() {
                    if (!event.defaultPrevented) {
                        markWorkspaceFormProcessing(form, event.submitter || null);
                    }
                });
            });

            try {
                const nativeSubmit = frameWindow.HTMLFormElement.prototype.submit;
                Object.defineProperty(form, 'submit', {
                    configurable: true,
                    value: function() {
                        markWorkspaceFormProcessing(form, null);
                        return nativeSubmit.call(form);
                    }
                });
            } catch (error) {
                console.warn('Unable to attach the workspace processing indicator:', error);
            }
        });
    }

    function showRequestToast(message, type) {
        if (!toastRegion || !message) return;

        const toast = document.createElement('div');
        const icon = document.createElement('i');
        const messageBox = document.createElement('div');
        const closeButton = document.createElement('button');
        const isSuccess = type === 'success';

        toast.className = 'request-toast ' + (isSuccess ? 'is-success' : 'is-error');
        toast.setAttribute('role', isSuccess ? 'status' : 'alert');

        icon.className = 'request-toast-icon bi ' + (isSuccess ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill');
        icon.setAttribute('aria-hidden', 'true');

        messageBox.className = 'request-toast-message';
        messageBox.textContent = message;

        closeButton.type = 'button';
        closeButton.className = 'request-toast-close';
        closeButton.setAttribute('aria-label', 'Dismiss notification');
        closeButton.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';

        function removeToast() {
            toast.classList.remove('show');
            window.setTimeout(function() { toast.remove(); }, 200);
        }

        closeButton.addEventListener('click', removeToast);
        toast.append(icon, messageBox, closeButton);
        toastRegion.appendChild(toast);
        window.requestAnimationFrame(function() { toast.classList.add('show'); });
        window.setTimeout(removeToast, 5000);
    }

    function updatePendingRequests() {
        if (!form || !container) return Promise.resolve();

        const formData = new FormData(form);
        formData.set('search', submittedSearch);
        const params = new URLSearchParams(formData);

        if (controller) {
            controller.abort();
        }

        controller = new AbortController();

        const currentWrap = document.getElementById('pendingRequestsTableWrap');
        if (currentWrap) currentWrap.classList.add('loading');
        container.setAttribute('aria-busy', 'true');

        return fetch('pending_requests.php?' + params.toString(), {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            signal: controller.signal
        })
        .then(function(response) {
            if (response.redirected && response.url.indexOf('/index.php') !== -1) {
                window.location.href = response.url;
                throw new Error('Administrator session expired.');
            }
            if (!response.ok) {
                throw new Error('Unable to refresh the request list.');
            }
            return response.text();
        })
        .then(function(html) {
            container.innerHTML = html;
            const qs = params.toString();
            window.history.replaceState({}, '', 'pending_requests.php' + (qs ? '?' + qs : ''));
        })
        .catch(function(error) {
            if (error.name !== 'AbortError') {
                console.error('Pending requests live filter error:', error);
                showRequestToast(error.message || 'Unable to refresh the request list.', 'error');
            }
        })
        .finally(function() {
            container.removeAttribute('aria-busy');
            const newWrap = document.getElementById('pendingRequestsTableWrap');
            if (newWrap) newWrap.classList.remove('loading');
        });
    }

    function closeRequestView() {
        if (!requestViewOverlay || !requestViewFrame) return;
        if (requestActionInFlight) {
            showRequestToast('Please wait until the request action finishes.', 'error');
            return;
        }

        requestViewOverlay.classList.remove('show');
        requestViewOverlay.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = previousBodyOverflow;
        requestActionInFlight = false;
        clearRequestViewBusy();

        window.setTimeout(function() {
            if (!requestViewOverlay.classList.contains('show')) {
                requestViewFrame.removeAttribute('src');
            }
        }, 220);

        if (lastRequestViewTrigger) {
            lastRequestViewTrigger.focus();
        }
    }

    function openRequestView(link) {
        if (!requestViewOverlay || !requestViewFrame || !requestViewFrameWrap) {
            window.location.href = link.href;
            return;
        }

        const requestUrl = new URL(link.href, window.location.href);
        requestUrl.searchParams.set('embedded', '1');

        lastRequestViewTrigger = link;
        previousBodyOverflow = document.body.style.overflow;
        requestViewModalTitle.textContent = link.dataset.requestTitle || 'Request Details';
        setRequestViewBusy('Loading request details...', false);
        requestViewOverlay.classList.add('show');
        requestViewOverlay.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        requestViewFrame.src = requestUrl.toString();
        requestViewClose.focus();
    }

    if (container) {
        container.addEventListener('click', function(event) {
            const link = event.target.closest('.js-request-view');
            if (!link || event.defaultPrevented) return;
            if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;

            event.preventDefault();
            openRequestView(link);
        });
    }

    if (requestViewClose) {
        requestViewClose.addEventListener('click', closeRequestView);
    }

    if (requestViewOverlay) {
        requestViewOverlay.addEventListener('click', function(event) {
            if (event.target === requestViewOverlay) {
                closeRequestView();
            }
        });
    }

    if (requestViewFrame) {
        requestViewFrame.addEventListener('load', function() {
            if (!requestViewFrame.hasAttribute('src')) return;

            try {
                const framePath = requestViewFrame.contentWindow.location.pathname;
                const frameDocument = requestViewFrame.contentDocument;
                if (framePath.endsWith('/pending_requests.php')) {
                    const actionNotice = frameDocument
                        ? frameDocument.querySelector('.notice')
                        : null;
                    if (actionNotice) {
                        showRequestToast(
                            actionNotice.textContent.trim(),
                            actionNotice.classList.contains('notice-success') ? 'success' : 'error'
                        );
                    } else {
                        showRequestToast('Workspace request updated successfully.', 'success');
                    }
                    requestActionInFlight = false;
                    closeRequestView();
                    updatePendingRequests();
                    return;
                }

                if (requestActionInFlight) {
                    const actionNotice = frameDocument
                        ? frameDocument.querySelector('main.admin-content > .notice, .admin-content > .notice')
                        : null;
                    showRequestToast(
                        actionNotice
                            ? actionNotice.textContent.trim()
                            : 'The request returned without completing. Review the displayed information and try again.',
                        actionNotice && actionNotice.classList.contains('notice-success') ? 'success' : 'error'
                    );
                    requestActionInFlight = false;
                }

                clearRequestViewBusy();
                if (framePath.endsWith('/view_workspace_request.php')) {
                    monitorWorkspaceActionForms(frameDocument);
                }
            } catch (error) {
                console.error('Unable to inspect the loaded request view:', error);
                requestActionInFlight = false;
                clearRequestViewBusy();
            }
        });
    }

    window.addEventListener('message', function(event) {
        if (event.origin !== window.location.origin) return;
        if (requestViewFrame && event.source !== requestViewFrame.contentWindow) return;
        if (!event.data) return;

        if (event.data.type === 'nexgen-close-request-view') {
            closeRequestView();
            return;
        }

        if (event.data.type === 'nexgen-request-action-processing') {
            beginRequestActionProcessing(String(event.data.message || 'Processing request...'));
            return;
        }

        if (event.data.type === 'nexgen-request-action-processing-end') {
            requestActionInFlight = false;
            clearRequestViewBusy();
            if (event.data.message) {
                showRequestToast(String(event.data.message), 'error');
            }
            return;
        }

        if (event.data.type === 'nexgen-request-action-result') {
            const succeeded = event.data.success === true;
            showRequestToast(
                String(event.data.message || (succeeded ? 'Request updated successfully.' : 'The request could not be updated.')),
                succeeded ? 'success' : 'error'
            );

            if (succeeded) {
                requestActionInFlight = false;
                closeRequestView();
                updatePendingRequests();
            } else {
                requestActionInFlight = false;
                clearRequestViewBusy();
            }
        }
    });

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && requestViewOverlay && requestViewOverlay.classList.contains('show')) {
            event.preventDefault();
            closeRequestView();
        }
    });

    if (form) {
        form.addEventListener('submit', function(event) {
            event.preventDefault();
            submittedSearch = searchInput ? searchInput.value.trim() : '';
            updatePendingRequests();
        });
    }

    if (statusFilter) {
        statusFilter.addEventListener('change', updatePendingRequests);
    }

    if (resetButton) {
        resetButton.addEventListener('click', function () {
            if (searchInput) {
                searchInput.value = '';
            }
            submittedSearch = '';
            if (statusFilter) {
                statusFilter.value = 'all';
            }
            updatePendingRequests();
        });
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
