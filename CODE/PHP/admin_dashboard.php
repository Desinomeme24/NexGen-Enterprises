<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/tenant_helper.php';

/* SESSION SECURITY: config.php starts the hardened session before any output. */
enforceSessionTimeout();

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . NEXGEN_ADMIN_LOGIN_PATH);
    exit();
}

if (($_SESSION['role'] ?? '') !== 'system_admin') {
    header('Location: ' . nxAppUrl('dashboard.php'));
    exit();
}

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function adminDashboardCount(mysqli $conn, string $sql): int
{
    try {
        $result = $conn->query($sql);
        if (!$result) {
            return 0;
        }

        return (int)($result->fetch_assoc()['total'] ?? 0);
    } catch (Throwable $e) {
        error_log('NexGen admin dashboard count failed: ' . $e->getMessage());
        return 0;
    }
}

$popupMessage = '';
$popupType = '';

if (isset($_SESSION['success'])) {
    $popupMessage = (string)$_SESSION['success'];
    $popupType = 'success';
    unset($_SESSION['success']);
} elseif (isset($_SESSION['error'])) {
    $popupMessage = (string)$_SESSION['error'];
    $popupType = 'error';
    unset($_SESSION['error']);
}

$adminName = (string)($_SESSION['full_name'] ?? 'System Administrator');

/* -------------------------------------------------------------------------
   CURRENT ADMIN MODULE METRICS
   ------------------------------------------------------------------------- */
$pendingAccountRequests = adminDashboardCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM registration_requests
     WHERE request_status = 'pending'"
);

$resubmitAccountRequests = adminDashboardCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM registration_requests
     WHERE request_status = 'resubmit'"
);

$workspaceRequestsReady = false;
try {
    $workspaceRequestsReady = nxWorkspaceRequestSchemaReady($conn);
} catch (Throwable $e) {
    error_log('NexGen workspace-request dashboard check failed: ' . $e->getMessage());
}

$pendingWorkspaceRequests = $workspaceRequestsReady
    ? adminDashboardCount(
        $conn,
        "SELECT COUNT(*) AS total
         FROM workspace_requests
         WHERE request_status = 'pending'"
    )
    : 0;

$pendingReviewTotal = $pendingAccountRequests + $pendingWorkspaceRequests;

$managedUsers = adminDashboardCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM users
     WHERE role IN ('owner', 'employee')"
);

/* An enabled account is recently active when its latest successful login or
   approval/creation timestamp is within the last seven days. */
$recentlyActiveUsers = adminDashboardCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM users
     WHERE role IN ('owner', 'employee')
       AND account_status = 'active'
       AND COALESCE(last_login_at, approved_at, created_at) IS NOT NULL
       AND COALESCE(last_login_at, approved_at, created_at) > DATE_SUB(NOW(), INTERVAL 7 DAY)"
);

$lockedUsers = adminDashboardCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM users
     WHERE role IN ('owner', 'employee')
       AND locked_until IS NOT NULL
       AND locked_until > NOW()"
);

$activeBusinesses = adminDashboardCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM business_entities
     WHERE status = 'active'"
);

$activeBranches = adminDashboardCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM businesses
     WHERE branch_status = 'active'"
);

$adminLogEntries = adminDashboardCount(
    $conn,
    'SELECT COUNT(*) AS total FROM admin_logs'
);

$activeUserRate = $managedUsers > 0
    ? min(100, (int)round(($recentlyActiveUsers / $managedUsers) * 100))
    : 0;
$branchesPerBusiness = $activeBusinesses > 0
    ? number_format($activeBranches / $activeBusinesses, 1)
    : '0.0';

$currentHour = (int)date('G');
$greeting = $currentHour < 12
    ? 'Good morning'
    : ($currentHour < 18 ? 'Good afternoon' : 'Good evening');

if ($pendingReviewTotal === 0) {
    $reviewLoadLabel = 'Queue clear';
    $reviewLoadTone = 'clear';
} elseif ($pendingReviewTotal >= 10) {
    $reviewLoadLabel = 'High review load';
    $reviewLoadTone = 'high';
} else {
    $reviewLoadLabel = 'Review queue active';
    $reviewLoadTone = 'active';
}

/* -------------------------------------------------------------------------
   COMBINED MULTI-TENANCY V3 REVIEW QUEUE
   Account requests and owner-created business/branch requests are normalized
   into one newest-first list. Each row still opens its correct review module.
   ------------------------------------------------------------------------- */
$reviewQueue = [];

try {
    $accountResult = $conn->query(
        "SELECT id, request_code, full_name, username, requested_role,
                business_name, business_type, business_code, branch_code,
                possible_duplicate, created_at
         FROM registration_requests
         WHERE request_status = 'pending'
         ORDER BY created_at DESC
         LIMIT 10"
    );

    if ($accountResult) {
        while ($request = $accountResult->fetch_assoc()) {
            $isOwner = ($request['requested_role'] ?? '') === 'owner';
            $codeSummary = trim(
                (string)($request['business_code'] ?? '') . ' / ' .
                (string)($request['branch_code'] ?? ''),
                " /\t\n\r\0\x0B"
            );

            $reviewQueue[] = [
                'id' => (int)$request['id'],
                'source' => 'account',
                'source_label' => 'Account Request',
                'request_label' => $isOwner ? 'SME Owner' : 'Employee / Staff',
                'requester_name' => (string)$request['full_name'],
                'username' => (string)$request['username'],
                'workspace_name' => (string)($request['business_name'] ?? ''),
                'workspace_detail' => $isOwner
                    ? (string)($request['business_type'] ?? 'New owner business')
                    : ($codeSummary !== '' ? $codeSummary : 'Branch assignment pending'),
                'integrity_label' => !empty($request['possible_duplicate'])
                    ? 'Review duplicate'
                    : 'No match found',
                'integrity_tone' => !empty($request['possible_duplicate']) ? 'danger' : 'success',
                'created_at' => (string)$request['created_at'],
                'view_url' => 'view_request.php?id=' . (int)$request['id'],
            ];
        }
    }
} catch (Throwable $e) {
    error_log('NexGen account review queue failed: ' . $e->getMessage());
}

if ($workspaceRequestsReady) {
    try {
        $workspaceResult = $conn->query(
            "SELECT wr.id, wr.request_code, wr.request_type, wr.business_name,
                    wr.business_type, wr.business_address, wr.branch_name,
                    wr.created_at, u.full_name, u.username
             FROM workspace_requests wr
             INNER JOIN users u ON u.id = wr.requested_by
             WHERE wr.request_status = 'pending'
             ORDER BY wr.created_at DESC
             LIMIT 10"
        );

        if ($workspaceResult) {
            while ($request = $workspaceResult->fetch_assoc()) {
                $isBranch = ($request['request_type'] ?? '') === 'branch';
                $reviewQueue[] = [
                    'id' => (int)$request['id'],
                    'source' => 'workspace',
                    'source_label' => 'Workspace Request',
                    'request_label' => $isBranch ? 'New Branch' : 'New Business',
                    'requester_name' => (string)$request['full_name'],
                    'username' => (string)$request['username'],
                    'workspace_name' => (string)($request['business_name'] ?? ''),
                    'workspace_detail' => $isBranch
                        ? (string)($request['branch_name'] ?? 'Branch name pending')
                        : (string)($request['business_type'] ?? 'Business type pending'),
                    'integrity_label' => 'Recheck on approval',
                    'integrity_tone' => 'info',
                    'created_at' => (string)$request['created_at'],
                    'view_url' => 'view_workspace_request.php?id=' . (int)$request['id'],
                ];
            }
        }
    } catch (Throwable $e) {
        error_log('NexGen workspace review queue failed: ' . $e->getMessage());
    }
}

usort($reviewQueue, static function (array $left, array $right): int {
    $leftTime = strtotime((string)$left['created_at']) ?: 0;
    $rightTime = strtotime((string)$right['created_at']) ?: 0;
    return $rightTime <=> $leftTime;
});
$reviewQueue = array_slice($reviewQueue, 0, 10);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php include __DIR__ . '/nexgen_favicon/favicon.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Admin Dashboard - NexGen</title>
    <script>
        /* THEME: apply saved preference before first paint to avoid a flash. */
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
    <link rel="stylesheet" href="/NexGen/CODE/STYLE/admin_module.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        .dashboard-heading {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 22px;
            width: 100%;
        }

        .dashboard-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
            color: var(--gold, #ffdd55);
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }

        .dashboard-live-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--success, #34d399);
            box-shadow: 0 0 0 5px rgba(52, 211, 153, 0.12);
            animation: dashboardLivePulse 2s ease-in-out infinite;
        }

        @keyframes dashboardLivePulse {
            50% { box-shadow: 0 0 0 9px rgba(52, 211, 153, 0); }
        }

        .dashboard-heading h1 { margin-bottom: 6px; }
        .dashboard-heading p {
            max-width: 760px;
            margin: 0;
            color: var(--muted, #aab4cf);
            line-height: 1.55;
        }

        .dashboard-date {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            flex: 0 0 auto;
            padding: 11px 15px;
            border: 1px solid var(--glass-border, rgba(255, 255, 255, 0.12));
            border-radius: 999px;
            background: var(--glass, rgba(255, 255, 255, 0.06));
            box-shadow: var(--shadow, 0 10px 30px rgba(3, 3, 30, 0.4));
            color: var(--muted, #aab4cf);
            font-size: 0.82rem;
            font-weight: 750;
        }

        .dashboard-date i { color: var(--gold, #ffdd55); }

        .dashboard-bento-grid {
            display: grid;
            grid-template-columns: minmax(300px, 1.3fr) repeat(2, minmax(210px, 1fr));
            grid-template-rows: repeat(2, minmax(200px, auto));
            gap: 16px;
            margin: 4px 0 24px;
        }

        .dashboard-bento-card {
            position: relative;
            min-width: 0;
            overflow: hidden;
            padding: 22px;
            border: 1px solid var(--glass-border, rgba(125, 211, 252, 0.22));
            border-radius: 22px;
            background: var(--glass, linear-gradient(180deg, rgba(59, 130, 246, 0.1), rgba(11, 31, 115, 0.16)));
            box-shadow: var(--shadow, 0 10px 30px rgba(3, 3, 30, 0.4));
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            transition: transform 0.22s ease, border-color 0.22s ease, box-shadow 0.22s ease;
        }

        .dashboard-bento-card:hover {
            transform: translateY(-3px);
            border-color: rgba(125, 211, 252, 0.38);
            box-shadow: var(--shadow-lg, 0 20px 50px rgba(3, 3, 30, 0.5));
        }

        .dashboard-card-header {
            position: relative;
            z-index: 2;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 18px;
        }

        .dashboard-card-label {
            color: var(--muted, #b9d4f5);
            font-size: 0.74rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .dashboard-card-icon {
            display: grid;
            place-items: center;
            width: 40px;
            height: 40px;
            flex: 0 0 auto;
            border: 1px solid rgba(125, 211, 252, 0.18);
            border-radius: 13px;
            background: rgba(59, 130, 246, 0.12);
            color: #79b8ff;
            font-size: 1.08rem;
        }

        .dashboard-review-spotlight {
            grid-row: span 2;
            display: flex;
            flex-direction: column;
            min-height: 416px;
            background:
                radial-gradient(circle at 92% 12%, rgba(255, 221, 85, 0.18), transparent 30%),
                radial-gradient(circle at 10% 95%, rgba(59, 130, 246, 0.24), transparent 38%),
                linear-gradient(145deg, rgba(11, 31, 115, 0.94), rgba(5, 11, 36, 0.94));
        }

        .dashboard-review-spotlight::after {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 42%;
            height: 3px;
            background: linear-gradient(90deg, transparent, var(--gold, #ffdd55));
        }

        .dashboard-load-chip {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 10px;
            border-radius: 999px;
            font-size: 0.7rem;
            font-weight: 800;
            white-space: nowrap;
        }

        .dashboard-load-chip.tone-clear {
            background: rgba(52, 211, 153, 0.13);
            color: #73e4b9;
        }
        .dashboard-load-chip.tone-active {
            background: rgba(125, 211, 252, 0.13);
            color: #9cddff;
        }
        .dashboard-load-chip.tone-high {
            background: rgba(239, 68, 68, 0.14);
            color: #ff9aa3;
        }

        .dashboard-review-number {
            position: relative;
            z-index: 2;
            margin-top: 8px;
            color: #fff;
            font-family: var(--font-display, 'Poppins', sans-serif);
            font-size: clamp(4.2rem, 7vw, 6.7rem);
            font-weight: 900;
            letter-spacing: -0.07em;
            line-height: 0.86;
        }

        .dashboard-review-title {
            position: relative;
            z-index: 2;
            margin: 16px 0 5px;
            color: #fff;
            font-family: var(--font-display, 'Poppins', sans-serif);
            font-size: 1.22rem;
            font-weight: 800;
        }

        .dashboard-review-copy {
            position: relative;
            z-index: 2;
            margin: 0;
            color: rgba(238, 245, 255, 0.72);
            font-size: 0.84rem;
            line-height: 1.55;
        }

        .dashboard-review-breakdown {
            position: relative;
            z-index: 2;
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 9px;
            margin-top: auto;
            padding-top: 22px;
        }

        .dashboard-breakdown-item {
            min-width: 0;
            padding: 12px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.055);
        }

        .dashboard-breakdown-item strong {
            display: block;
            color: #fff;
            font-family: var(--font-display, 'Poppins', sans-serif);
            font-size: 1.25rem;
            line-height: 1;
        }

        .dashboard-breakdown-item span {
            display: block;
            margin-top: 6px;
            color: rgba(238, 245, 255, 0.62);
            font-size: 0.67rem;
            font-weight: 700;
            line-height: 1.3;
        }

        .dashboard-review-action {
            position: relative;
            z-index: 2;
            display: inline-flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            width: 100%;
            margin-top: 12px;
            padding: 12px 14px;
            border: 1px solid rgba(255, 221, 85, 0.32);
            border-radius: 14px;
            background: rgba(255, 221, 85, 0.1);
            color: #ffea8c;
            text-decoration: none;
            font-size: 0.78rem;
            font-weight: 800;
            transition: background 0.18s ease, border-color 0.18s ease;
        }

        .dashboard-review-action:hover,
        .dashboard-review-action:focus-visible {
            border-color: rgba(255, 221, 85, 0.62);
            background: rgba(255, 221, 85, 0.16);
            outline: none;
        }

        .dashboard-activity-card {
            display: flex;
            flex-direction: column;
        }

        .dashboard-activity-content {
            display: flex;
            align-items: center;
            gap: 18px;
            margin-top: auto;
        }

        .dashboard-activity-ring {
            --activity-rate: 0;
            position: relative;
            display: grid;
            place-items: center;
            width: 104px;
            height: 104px;
            flex: 0 0 auto;
            border-radius: 50%;
            background: conic-gradient(#34d399 calc(var(--activity-rate) * 1%), rgba(127, 149, 196, 0.18) 0);
        }

        .dashboard-activity-ring::before {
            content: '';
            position: absolute;
            inset: 9px;
            border-radius: 50%;
            background: var(--panel, #0e1b3d);
            box-shadow: inset 0 0 0 1px var(--line, rgba(255, 255, 255, 0.1));
        }

        .dashboard-ring-value {
            position: relative;
            z-index: 1;
            color: var(--white, #fff);
            font-family: var(--font-display, 'Poppins', sans-serif);
            font-size: 1.25rem;
            font-weight: 900;
        }

        .dashboard-activity-copy strong {
            display: block;
            color: var(--white, #fff);
            font-family: var(--font-display, 'Poppins', sans-serif);
            font-size: 1.8rem;
            line-height: 1;
        }

        .dashboard-activity-copy span,
        .dashboard-activity-copy small {
            display: block;
            margin-top: 6px;
            color: var(--muted, #b9d4f5);
            font-size: 0.77rem;
            line-height: 1.4;
        }

        .dashboard-tenancy-card {
            display: flex;
            flex-direction: column;
        }

        .dashboard-tenancy-values {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            align-items: center;
            gap: 12px;
            margin-top: auto;
        }

        .dashboard-tenancy-value strong {
            display: block;
            color: var(--white, #fff);
            font-family: var(--font-display, 'Poppins', sans-serif);
            font-size: 2rem;
            line-height: 1;
        }

        .dashboard-tenancy-value span {
            display: block;
            margin-top: 7px;
            color: var(--muted, #b9d4f5);
            font-size: 0.72rem;
            font-weight: 700;
        }

        .dashboard-tenancy-divider {
            width: 1px;
            height: 48px;
            background: var(--line, rgba(255, 255, 255, 0.1));
        }

        .dashboard-tenancy-density {
            margin-top: 18px;
            padding: 10px 12px;
            border-radius: 12px;
            background: rgba(172, 139, 255, 0.1);
            color: #c7b5ff;
            font-size: 0.73rem;
            font-weight: 750;
        }

        .dashboard-security-card {
            grid-column: 2 / 4;
            display: grid;
            grid-template-columns: minmax(190px, 0.75fr) minmax(280px, 1.25fr);
            gap: 24px;
            align-items: center;
        }

        .dashboard-security-summary {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .dashboard-security-orb {
            display: grid;
            place-items: center;
            width: 68px;
            height: 68px;
            flex: 0 0 auto;
            border: 1px solid <?php echo $lockedUsers > 0 ? 'rgba(239, 68, 68, 0.34)' : 'rgba(52, 211, 153, 0.30)'; ?>;
            border-radius: 20px;
            background: <?php echo $lockedUsers > 0 ? 'rgba(239, 68, 68, 0.12)' : 'rgba(52, 211, 153, 0.11)'; ?>;
            color: <?php echo $lockedUsers > 0 ? '#ff8f98' : '#67dfb2'; ?>;
            font-size: 1.65rem;
        }

        .dashboard-security-count strong {
            display: block;
            color: var(--white, #fff);
            font-family: var(--font-display, 'Poppins', sans-serif);
            font-size: 2rem;
            line-height: 1;
        }

        .dashboard-security-count span {
            display: block;
            margin-top: 6px;
            color: var(--muted, #b9d4f5);
            font-size: 0.76rem;
            line-height: 1.4;
        }

        .dashboard-readiness-list {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 9px;
        }

        .dashboard-readiness-item {
            min-width: 0;
            padding: 12px;
            border: 1px solid var(--line, rgba(255, 255, 255, 0.1));
            border-radius: 13px;
            background: rgba(59, 130, 246, 0.055);
        }

        .dashboard-readiness-status {
            display: flex;
            align-items: center;
            gap: 7px;
            color: var(--white, #fff);
            font-size: 0.72rem;
            font-weight: 800;
        }

        .dashboard-status-dot {
            width: 7px;
            height: 7px;
            flex: 0 0 auto;
            border-radius: 50%;
            background: var(--success, #34d399);
            box-shadow: 0 0 10px rgba(52, 211, 153, 0.48);
        }

        .dashboard-status-dot.warning {
            background: var(--warning, #fbbf24);
            box-shadow: 0 0 10px rgba(251, 191, 36, 0.42);
        }

        .dashboard-readiness-item small {
            display: block;
            margin-top: 6px;
            color: var(--muted, #b9d4f5);
            font-size: 0.65rem;
            line-height: 1.35;
        }

        .dashboard-section { margin-top: 22px; }

        .dashboard-schema-warning {
            display: flex;
            align-items: flex-start;
            gap: 11px;
            margin: 0 0 16px;
            padding: 13px 15px;
            border: 1px solid rgba(242, 199, 92, 0.38);
            border-radius: 14px;
            background: rgba(242, 199, 92, 0.09);
            color: var(--white, #fff);
            font-size: 0.84rem;
            line-height: 1.5;
        }

        .dashboard-schema-warning i {
            color: var(--gold, #f2c75c);
            font-size: 1.05rem;
        }

        .dashboard-requests-table-wrap {
            max-height: 520px;
            overflow: auto;
            border-radius: 18px;
        }

        .dashboard-requests-table-wrap table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        .dashboard-requests-table-wrap thead th {
            position: sticky;
            top: 0;
            z-index: 5;
            background: rgba(8, 16, 50, 0.96);
            backdrop-filter: blur(8px);
        }

        .dashboard-requester strong,
        .dashboard-workspace strong {
            display: block;
            color: var(--white, #fff);
        }

        .dashboard-requester small,
        .dashboard-workspace small {
            display: block;
            margin-top: 3px;
            color: var(--muted, #aab4cf);
        }

        .dashboard-source-badge,
        .dashboard-integrity-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 9px;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 800;
            white-space: nowrap;
        }

        .dashboard-source-badge.source-account {
            background: rgba(91, 156, 255, 0.14);
            color: #82b8ff;
        }
        .dashboard-source-badge.source-workspace {
            background: rgba(172, 139, 255, 0.14);
            color: #c1a9ff;
        }
        .dashboard-integrity-badge.tone-success {
            background: rgba(88, 213, 162, 0.13);
            color: #79e2b7;
        }
        .dashboard-integrity-badge.tone-danger {
            background: rgba(255, 122, 133, 0.13);
            color: #ff9ca4;
        }
        .dashboard-integrity-badge.tone-info {
            background: rgba(80, 213, 232, 0.13);
            color: #80e6f4;
        }

        .dashboard-empty-state {
            display: grid;
            place-items: center;
            min-height: 190px;
            padding: 30px;
            text-align: center;
            color: var(--muted, #aab4cf);
        }
        .dashboard-empty-state i {
            margin-bottom: 10px;
            color: #58d5a2;
            font-size: 2rem;
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
            background: var(--panel, #0d1b43);
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
            border-bottom: 1px solid var(--line, rgba(255, 255, 255, 0.12));
            background: var(--panel-2, #14285b);
        }

        .request-view-modal-header h2 {
            margin: 0;
            color: var(--text, #fff);
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
            border: 1px solid var(--line, rgba(255, 255, 255, 0.12));
            border-radius: 11px;
            background: var(--card);
            color: var(--text, #fff);
            cursor: pointer;
            font-size: 20px;
            transition: 0.18s ease;
        }

        .request-view-close:hover,
        .request-view-close:focus-visible {
            border-color: var(--gold);
            color: var(--gold);
            transform: translateY(-1px);
            outline: none;
        }

        .request-view-frame-wrap {
            position: relative;
            flex: 1 1 auto;
            min-height: 0;
            background: var(--bg, #07112f);
        }

        .request-view-frame {
            width: 100%;
            height: 100%;
            display: block;
            border: 0;
            background: var(--bg, #07112f);
            opacity: 1;
            transition: opacity 0.16s ease;
        }

        .request-view-frame-wrap.loading .request-view-frame { opacity: 0; }

        .request-view-loading {
            position: absolute;
            inset: 0;
            z-index: 2;
            display: none;
            place-items: center;
            color: var(--muted, #aab4cf);
            font-size: 14px;
            font-weight: 750;
        }

        .request-view-frame-wrap.loading .request-view-loading { display: grid; }
        .request-view-loading i {
            margin-right: 8px;
            color: var(--gold);
            font-size: 20px;
        }

        html[data-theme="light"] .dashboard-review-spotlight {
            background:
                radial-gradient(circle at 92% 12%, rgba(255, 221, 85, 0.30), transparent 30%),
                radial-gradient(circle at 10% 95%, rgba(59, 130, 246, 0.18), transparent 38%),
                linear-gradient(145deg, rgba(235, 244, 255, 0.98), rgba(255, 255, 255, 0.98));
        }
        html[data-theme="light"] .dashboard-review-number,
        html[data-theme="light"] .dashboard-review-title,
        html[data-theme="light"] .dashboard-breakdown-item strong {
            color: #0b1f73;
        }
        html[data-theme="light"] .dashboard-review-copy,
        html[data-theme="light"] .dashboard-breakdown-item span {
            color: #536786;
        }
        html[data-theme="light"] .dashboard-breakdown-item {
            border-color: rgba(25, 73, 138, 0.13);
            background: rgba(255, 255, 255, 0.68);
        }
        html[data-theme="light"] .dashboard-review-action {
            border-color: rgba(160, 112, 12, 0.25);
            background: rgba(255, 221, 85, 0.20);
            color: #73500a;
        }
        html[data-theme="light"] .dashboard-review-action:hover,
        html[data-theme="light"] .dashboard-review-action:focus-visible {
            border-color: rgba(160, 112, 12, 0.44);
            background: rgba(255, 221, 85, 0.30);
        }
        html[data-theme="light"] .dashboard-tenancy-density { color: #6648bd; }
        html[data-theme="light"] .dashboard-readiness-item {
            background: rgba(59, 130, 246, 0.055);
        }
        html[data-theme="light"] .dashboard-requests-table-wrap thead th {
            background: rgba(214, 235, 255, 0.96);
        }
        html[data-theme="light"] .dashboard-heading p,
        html[data-theme="light"] .dashboard-requester small,
        html[data-theme="light"] .dashboard-workspace small {
            color: #536786;
        }
        html[data-theme="light"] .dashboard-requester strong,
        html[data-theme="light"] .dashboard-workspace strong,
        html[data-theme="light"] .dashboard-empty-state strong {
            color: #10254b !important;
        }

        @media (max-width: 1250px) {
            .dashboard-bento-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                grid-template-rows: auto;
            }
            .dashboard-review-spotlight {
                grid-column: 1 / 3;
                grid-row: auto;
                min-height: 370px;
            }
            .dashboard-security-card { grid-column: 1 / 3; }
        }

        @media (max-width: 980px) {
            .dashboard-heading {
                flex-direction: column;
                gap: 16px;
            }
            .dashboard-security-card {
                grid-template-columns: 1fr;
                align-items: start;
            }
        }

        @media (max-width: 700px) {
            .dashboard-heading h1 {
                font-size: clamp(1.45rem, 7vw, 2rem);
                overflow-wrap: anywhere;
            }

            .dashboard-eyebrow {
                margin-bottom: 6px;
                font-size: 0.66rem;
            }

            .dashboard-date {
                width: 100%;
                min-height: 44px;
                justify-content: center;
                padding: 10px 12px;
                text-align: center;
                white-space: normal;
            }

            .dashboard-bento-grid {
                grid-template-columns: 1fr;
                gap: 12px;
                margin-bottom: 18px;
            }

            .dashboard-review-spotlight,
            .dashboard-security-card { grid-column: auto; }

            .dashboard-bento-card {
                padding: 18px;
                border-radius: 18px;
            }

            .dashboard-review-spotlight {
                min-height: 0;
                padding: 20px 18px 18px;
            }

            .dashboard-card-header {
                align-items: flex-start;
                margin-bottom: 14px;
            }

            .dashboard-review-number {
                margin-top: 2px;
                font-size: clamp(3.6rem, 22vw, 5.2rem);
            }

            .dashboard-review-title {
                margin-top: 13px;
                font-size: 1.05rem;
            }

            .dashboard-review-breakdown {
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 7px;
                padding-top: 18px;
            }

            .dashboard-breakdown-item {
                padding: 10px 8px;
                text-align: center;
            }

            .dashboard-breakdown-item strong { font-size: 1.1rem; }
            .dashboard-breakdown-item span { font-size: 0.62rem; }

            .dashboard-review-action {
                min-height: 44px;
                margin-top: 10px;
            }

            .dashboard-readiness-list { grid-template-columns: 1fr; }

            .dashboard-activity-content {
                justify-content: flex-start;
                gap: 16px;
            }

            .dashboard-activity-ring {
                width: 92px;
                height: 92px;
            }

            .dashboard-security-card { gap: 18px; }
            .dashboard-security-orb {
                width: 58px;
                height: 58px;
                border-radius: 17px;
                font-size: 1.4rem;
            }

            .dashboard-section { margin-top: 16px; }
            .dashboard-section .panel-header {
                align-items: stretch;
                gap: 12px;
            }

            .dashboard-section .panel-header > div { min-width: 0; }
            .dashboard-section .panel-header .btn {
                width: 100%;
                min-height: 44px;
            }

            .dashboard-schema-warning {
                padding: 12px;
                font-size: 0.78rem;
            }

            /* Dashboard-specific request cards. The shared admin stylesheet
               uses a different grid for account-only request tables. */
            .admin-dashboard-content .dashboard-requests-table-wrap[data-mobile-cards] {
                max-height: none;
                overflow: visible;
                border: 0;
                border-radius: 0;
            }

            .admin-dashboard-content .dashboard-requests-table-wrap[data-mobile-cards] tr {
                width: 100%;
                min-width: 0;
                grid-template-columns: minmax(0, 1fr) auto;
                grid-template-areas:
                    "source number"
                    "requester requester"
                    "workspace workspace"
                    "type integrity"
                    "submitted submitted"
                    "action action";
                gap: 10px 12px;
                padding: 15px;
                border-color: var(--glass-border, rgba(125, 211, 252, 0.22));
                background: var(--glass, rgba(59, 130, 246, 0.08));
                box-shadow: 0 8px 22px rgba(3, 3, 30, 0.18);
            }

            .admin-dashboard-content .dashboard-requests-table-wrap[data-mobile-cards] tbody tr:nth-child(even) {
                background: var(--glass, rgba(59, 130, 246, 0.08));
            }

            .admin-dashboard-content .dashboard-requests-table-wrap[data-mobile-cards] td {
                min-width: 0;
                text-align: left;
                overflow-wrap: anywhere;
            }

            .admin-dashboard-content .dashboard-requests-table-wrap td[data-label="No."] {
                grid-area: number;
                align-self: center;
                justify-self: end;
                color: var(--faint, #7f95c4);
                font-size: 0.76rem;
                font-weight: 800;
            }

            .admin-dashboard-content .dashboard-requests-table-wrap td[data-label="No."]::before {
                content: "#";
                display: inline;
            }

            .admin-dashboard-content .dashboard-requests-table-wrap td[data-label="Request Source"] {
                grid-area: source;
                align-self: center;
            }

            .admin-dashboard-content .dashboard-requests-table-wrap td[data-label="Requester"] {
                grid-area: requester;
                padding-top: 11px;
                border-top: 1px solid var(--line, rgba(255, 255, 255, 0.1));
            }

            .admin-dashboard-content .dashboard-requests-table-wrap td[data-label="Requester"] strong {
                font-size: 1rem;
                line-height: 1.35;
            }

            .admin-dashboard-content .dashboard-requests-table-wrap td[data-label="Request Type"] {
                grid-area: type;
                align-self: center;
                color: var(--text, #eef5ff);
                font-weight: 700;
            }

            .admin-dashboard-content .dashboard-requests-table-wrap td[data-label="Request Type"]::before,
            .admin-dashboard-content .dashboard-requests-table-wrap td[data-label="Submitted"]::before {
                display: block;
                margin-bottom: 3px;
                color: var(--faint, #7f95c4);
                font-size: 0.62rem;
                font-weight: 800;
                letter-spacing: 0.06em;
                text-transform: uppercase;
            }

            .admin-dashboard-content .dashboard-requests-table-wrap td[data-label="Request Type"]::before {
                content: "Request type";
            }

            .admin-dashboard-content .dashboard-requests-table-wrap td[data-label="Business / Branch"] {
                grid-area: workspace;
            }

            .admin-dashboard-content .dashboard-requests-table-wrap td[data-label="Integrity Review"] {
                grid-area: integrity;
                align-self: end;
                justify-self: end;
            }

            .admin-dashboard-content .dashboard-requests-table-wrap td[data-label="Submitted"] {
                grid-area: submitted;
                justify-self: stretch;
                padding-top: 10px;
                border-top: 1px solid var(--line, rgba(255, 255, 255, 0.1));
                color: var(--muted, #b9d4f5);
                text-align: left;
                white-space: normal;
            }

            .admin-dashboard-content .dashboard-requests-table-wrap td[data-label="Submitted"]::before {
                content: "Submitted";
            }

            .admin-dashboard-content .dashboard-requests-table-wrap td[data-label="Action"] {
                grid-area: action;
                margin-top: 0;
                padding-top: 0;
                border-top: 0;
            }

            .admin-dashboard-content .dashboard-requests-table-wrap td[data-label="Action"] .btn {
                min-height: 44px;
            }

            .request-view-overlay { padding: 0; }
            .request-view-dialog {
                width: 100%;
                height: 100vh;
                height: 100dvh;
                max-height: none;
                border: 0;
                border-radius: 0;
            }
            .request-view-modal-header {
                min-height: 58px;
                padding:
                    calc(10px + env(safe-area-inset-top))
                    max(12px, env(safe-area-inset-right))
                    10px
                    max(16px, env(safe-area-inset-left));
            }
        }

        @media (max-width: 430px) {
            .dashboard-card-header { flex-wrap: wrap; }
            .dashboard-load-chip {
                max-width: 100%;
                white-space: normal;
            }

            .dashboard-activity-content { align-items: flex-start; }
            .dashboard-activity-copy strong { font-size: 1.55rem; }
            .dashboard-tenancy-values { gap: 9px; }
            .dashboard-tenancy-value strong { font-size: 1.7rem; }

            .admin-dashboard-content .dashboard-requests-table-wrap[data-mobile-cards] tr {
                grid-template-areas:
                    "source number"
                    "requester requester"
                    "workspace workspace"
                    "type type"
                    "integrity integrity"
                    "submitted submitted"
                    "action action";
                padding: 13px;
            }

            .admin-dashboard-content .dashboard-requests-table-wrap td[data-label="Integrity Review"] {
                justify-self: start;
            }

            .dashboard-source-badge,
            .dashboard-integrity-badge {
                max-width: 100%;
                white-space: normal;
            }
        }

        @media (hover: none) and (pointer: coarse) {
            .dashboard-bento-card:hover { transform: none; }
            .dashboard-review-action,
            .dashboard-section .btn,
            .request-view-close { min-height: 44px; }
        }

        @media (prefers-reduced-motion: reduce) {
            .dashboard-live-dot { animation: none; }
            .dashboard-bento-card { transition: none; }
        }
    </style>
</head>
<body>

<?php if ($popupMessage !== ''): ?>
    <div class="popup-overlay" id="popupOverlay">
        <div class="popup-box <?php echo e($popupType); ?>" id="popupBox">
            <div class="popup-icon"><?php echo $popupType === 'success' ? '✓' : '!'; ?></div>
            <h3><?php echo $popupType === 'success' ? 'Success' : 'Error'; ?></h3>
            <p><?php echo e($popupMessage); ?></p>
        </div>
    </div>
<?php endif; ?>

<div class="admin-shell">
    <?php include __DIR__ . '/admin_sidebar.php'; ?>

    <main class="admin-content admin-dashboard-content">
        <div class="topbar">
            <div class="dashboard-heading">
                <div class="page-title">
                    <span class="dashboard-eyebrow">
                        <span class="dashboard-live-dot" aria-hidden="true"></span>
                        Live system overview
                    </span>
                    <h1><?php echo e($greeting); ?>, <?php echo e($adminName); ?></h1>
                </div>
                <div class="dashboard-date">
                    <i class="bi bi-calendar3"></i>
                    <?php echo e(date('l, F d, Y')); ?>
                </div>
            </div>
        </div>

        <section class="dashboard-bento-grid" aria-label="System overview">
            <article class="dashboard-bento-card dashboard-review-spotlight">
                <div class="dashboard-card-header">
                    <span class="dashboard-card-label">Review workload</span>
                    <span class="dashboard-load-chip tone-<?php echo e($reviewLoadTone); ?>">
                        <i class="bi bi-circle-fill" aria-hidden="true"></i>
                        <?php echo e($reviewLoadLabel); ?>
                    </span>
                </div>

                <strong class="dashboard-review-number"><?php echo $pendingReviewTotal; ?></strong>
                <h2 class="dashboard-review-title">Awaiting administrator review</h2>
                <div class="dashboard-review-breakdown" aria-label="Review request breakdown">
                    <div class="dashboard-breakdown-item">
                        <strong><?php echo $pendingAccountRequests; ?></strong>
                        <span>Account requests</span>
                    </div>
                    <div class="dashboard-breakdown-item">
                        <strong><?php echo $pendingWorkspaceRequests; ?></strong>
                        <span>Workspace requests</span>
                    </div>
                    <div class="dashboard-breakdown-item">
                        <strong><?php echo $resubmitAccountRequests; ?></strong>
                        <span>Awaiting resubmission</span>
                    </div>
                </div>

                <a class="dashboard-review-action" href="#reviewQueue">
                    <span>Review the latest requests</span>
                    <i class="bi bi-arrow-down" aria-hidden="true"></i>
                </a>
            </article>

            <article class="dashboard-bento-card dashboard-activity-card">
                <div class="dashboard-card-header">
                    <span class="dashboard-card-label">7-day activity</span>
                    <span class="dashboard-card-icon"><i class="bi bi-person-check" aria-hidden="true"></i></span>
                </div>
                <div class="dashboard-activity-content">
                    <div
                        class="dashboard-activity-ring"
                        style="--activity-rate: <?php echo $activeUserRate; ?>;"
                        role="img"
                        aria-label="<?php echo $activeUserRate; ?> percent of managed accounts were recently active"
                    >
                        <span class="dashboard-ring-value"><?php echo $activeUserRate; ?>%</span>
                    </div>
                    <div class="dashboard-activity-copy">
                        <strong><?php echo $recentlyActiveUsers; ?></strong>
                        <span>recently active users</span>
                        <small>of <?php echo $managedUsers; ?> managed SME account(s)</small>
                    </div>
                </div>
            </article>

            <article class="dashboard-bento-card dashboard-tenancy-card">
                <div class="dashboard-card-header">
                    <span class="dashboard-card-label">Tenant footprint</span>
                    <span class="dashboard-card-icon"><i class="bi bi-buildings" aria-hidden="true"></i></span>
                </div>
                <div class="dashboard-tenancy-values">
                    <div class="dashboard-tenancy-value">
                        <strong><?php echo $activeBusinesses; ?></strong>
                        <span>Active businesses</span>
                    </div>
                    <span class="dashboard-tenancy-divider" aria-hidden="true"></span>
                    <div class="dashboard-tenancy-value">
                        <strong><?php echo $activeBranches; ?></strong>
                        <span>Active branches</span>
                    </div>
                </div>
                <div class="dashboard-tenancy-density">
                    <i class="bi bi-diagram-3" aria-hidden="true"></i>
                    <?php echo e($branchesPerBusiness); ?> branches per active business
                </div>
            </article>

            <article class="dashboard-bento-card dashboard-security-card">
                <div class="dashboard-security-summary">
                    <span class="dashboard-security-orb"><i class="bi bi-shield-lock" aria-hidden="true"></i></span>
                    <div class="dashboard-security-count">
                        <strong><?php echo $lockedUsers; ?></strong>
                        <span><?php echo $lockedUsers === 1 ? 'account is currently locked' : 'accounts are currently locked'; ?></span>
                    </div>
                </div>

                <div class="dashboard-readiness-list" aria-label="Security and system readiness">
                    <div class="dashboard-readiness-item">
                        <span class="dashboard-readiness-status">
                            <span class="dashboard-status-dot" aria-hidden="true"></span>
                            Session protection
                        </span>
                        <small>Hardened timeout controls are enabled.</small>
                    </div>
                    <div class="dashboard-readiness-item">
                        <span class="dashboard-readiness-status">
                            <span class="dashboard-status-dot<?php echo $workspaceRequestsReady ? '' : ' warning'; ?>" aria-hidden="true"></span>
                            Workspace workflow
                        </span>
                        <small><?php echo $workspaceRequestsReady ? 'Request schema is ready.' : 'Database migration is required.'; ?></small>
                    </div>
                    <div class="dashboard-readiness-item">
                        <span class="dashboard-readiness-status">
                            <span class="dashboard-status-dot" aria-hidden="true"></span>
                            Audit trail
                        </span>
                        <small><?php echo $adminLogEntries; ?> administrative action(s) recorded.</small>
                    </div>
                </div>
            </article>
        </section>

        <section class="panel dashboard-section" id="reviewQueue">
            <div class="panel-header">
                <div>
                    <h2>Review Queue</h2>
                    <p style="margin:5px 0 0; color:var(--muted, #aab4cf); font-size:0.84rem;">
                        The latest account and workspace requests requiring a decision, newest first.
                    </p>
                </div>
                <a class="btn btn-gold" href="pending_requests.php">
                    <i class="bi bi-person-workspace"></i> Full Request History
                </a>
            </div>
            <div class="panel-body">
                <?php if (!$workspaceRequestsReady): ?>
                    <div class="dashboard-schema-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        <span>The workspace-request migration is not installed in the current database, so business and branch requests cannot be counted yet. Account requests remain available.</span>
                    </div>
                <?php endif; ?>

                <?php if (empty($reviewQueue)): ?>
                    <div class="dashboard-empty-state">
                        <div>
                            <i class="bi bi-check-circle"></i>
                            <strong style="display:block; color:var(--white, #fff);">Review queue is clear</strong>
                            <span>No pending account, business, or branch requests were found.</span>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="table-wrap dashboard-requests-table-wrap" data-mobile-cards>
                        <table>
                            <thead>
                                <tr>
                                    <th>NO.</th>
                                    <th>Request Source</th>
                                    <th>Requester</th>
                                    <th>Request Type</th>
                                    <th>Business / Branch</th>
                                    <th>Integrity Review</th>
                                    <th>Submitted</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php $queueTotal = count($reviewQueue); ?>
                            <?php foreach ($reviewQueue as $index => $request): ?>
                                <tr>
                                    <td data-label="No."><?php echo $queueTotal - $index; ?></td>
                                    <td data-label="Request Source">
                                        <span class="dashboard-source-badge source-<?php echo e($request['source']); ?>">
                                            <i class="bi <?php echo $request['source'] === 'workspace' ? 'bi-buildings' : 'bi-person-plus'; ?>"></i>
                                            <?php echo e($request['source_label']); ?>
                                        </span>
                                    </td>
                                    <td data-label="Requester" class="dashboard-requester">
                                        <strong><?php echo e($request['requester_name']); ?></strong>
                                        <small>@<?php echo e($request['username']); ?></small>
                                    </td>
                                    <td data-label="Request Type"><?php echo e($request['request_label']); ?></td>
                                    <td data-label="Business / Branch" class="dashboard-workspace">
                                        <strong><?php echo e($request['workspace_name'] !== '' ? $request['workspace_name'] : 'Not assigned'); ?></strong>
                                        <small><?php echo e($request['workspace_detail']); ?></small>
                                    </td>
                                    <td data-label="Integrity Review">
                                        <span class="dashboard-integrity-badge tone-<?php echo e($request['integrity_tone']); ?>">
                                            <i class="bi <?php
                                                echo $request['integrity_tone'] === 'danger'
                                                    ? 'bi-exclamation-triangle-fill'
                                                    : ($request['integrity_tone'] === 'success' ? 'bi-shield-check' : 'bi-arrow-repeat');
                                            ?>"></i>
                                            <?php echo e($request['integrity_label']); ?>
                                        </span>
                                    </td>
                                    <td data-label="Submitted">
                                        <?php echo e(date('M d, Y h:i A', strtotime((string)$request['created_at']))); ?>
                                    </td>
                                    <td data-label="Action">
                                        <a
                                            class="btn btn-silver js-dashboard-request-view"
                                            href="<?php echo e($request['view_url']); ?>"
                                            data-request-title="<?php echo e($request['source_label']); ?>"
                                        >
                                            <i class="bi bi-eye-fill"></i> View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </main>
</div>

<div class="request-view-overlay" id="requestViewOverlay" aria-hidden="true">
    <div class="request-view-dialog" role="dialog" aria-modal="true" aria-labelledby="requestViewModalTitle">
        <div class="request-view-modal-header">
            <h2 id="requestViewModalTitle">Request Details</h2>
            <button type="button" class="request-view-close" id="requestViewClose" aria-label="Close request details">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        </div>
        <div class="request-view-frame-wrap" id="requestViewFrameWrap">
            <div class="request-view-loading" id="requestViewLoading" role="status">
                <span><i class="bi bi-arrow-repeat"></i> Loading request...</span>
            </div>
            <iframe class="request-view-frame" id="requestViewFrame" title="Request details"></iframe>
        </div>
    </div>
</div>

<script src="/NexGen/CODE/JS/admin_module.js"></script>
<script>
    /* Keep request details inside the dashboard, matching Pending Requests. */
    document.addEventListener('DOMContentLoaded', function () {
        const reviewQueue = document.getElementById('reviewQueue');
        const overlay = document.getElementById('requestViewOverlay');
        const closeButton = document.getElementById('requestViewClose');
        const frame = document.getElementById('requestViewFrame');
        const frameWrap = document.getElementById('requestViewFrameWrap');
        const modalTitle = document.getElementById('requestViewModalTitle');
        let lastTrigger = null;
        let previousBodyOverflow = '';

        function closeRequestView() {
            if (!overlay || !frame) return;

            overlay.classList.remove('show');
            overlay.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = previousBodyOverflow;

            window.setTimeout(function () {
                if (!overlay.classList.contains('show')) {
                    frame.removeAttribute('src');
                }
            }, 220);

            if (lastTrigger) lastTrigger.focus();
        }

        function openRequestView(link) {
            if (!overlay || !frame || !frameWrap || !closeButton) {
                window.location.href = link.href;
                return;
            }

            const requestUrl = new URL(link.href, window.location.href);
            requestUrl.searchParams.set('embedded', '1');
            lastTrigger = link;
            previousBodyOverflow = document.body.style.overflow;
            modalTitle.textContent = link.dataset.requestTitle || 'Request Details';
            frameWrap.classList.add('loading');
            overlay.classList.add('show');
            overlay.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
            frame.src = requestUrl.toString();
            closeButton.focus();
        }

        if (reviewQueue) {
            reviewQueue.addEventListener('click', function (event) {
                const link = event.target.closest('.js-dashboard-request-view');
                if (!link || event.defaultPrevented) return;
                if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;

                event.preventDefault();
                openRequestView(link);
            });
        }

        if (closeButton) closeButton.addEventListener('click', closeRequestView);
        if (overlay) {
            overlay.addEventListener('click', function (event) {
                if (event.target === overlay) closeRequestView();
            });
        }

        if (frame) {
            frame.addEventListener('load', function () {
                if (!frame.hasAttribute('src')) return;

                try {
                    if (frame.contentWindow.location.pathname.endsWith('/pending_requests.php')) {
                        closeRequestView();
                        return;
                    }
                } catch (error) {
                    console.error('Unable to inspect the loaded request view:', error);
                }

                frameWrap.classList.remove('loading');
            });
        }

        window.addEventListener('message', function (event) {
            if (event.origin !== window.location.origin) return;
            if (!event.data || event.data.type !== 'nexgen-close-request-view') return;
            closeRequestView();
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && overlay && overlay.classList.contains('show')) {
                event.preventDefault();
                closeRequestView();
            }
        });
    });

    /* SESSION SECURITY: client-side inactivity auto logout. */
    (function () {
        const timeoutMs = <?php echo (int)SESSION_TIMEOUT_SECONDS * 1000; ?>;
        let inactivityTimer = null;

        function triggerTimeoutLogout() {
            window.location.href = '/NexGen/CODE/PHP/logout.php?timeout=1';
        }

        function resetInactivityTimer() {
            clearTimeout(inactivityTimer);
            inactivityTimer = setTimeout(triggerTimeoutLogout, timeoutMs);
        }

        ['click', 'mousemove', 'keydown', 'scroll', 'touchstart'].forEach(function (eventName) {
            document.addEventListener(eventName, resetInactivityTimer, { passive: true });
        });

        resetInactivityTimer();
    })();
</script>
</body>
</html>
