<?php
session_start();
require_once("config.php");

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

$profileImage = !empty($_SESSION['profile_image']) ? $_SESSION['profile_image'] : 'uploads/default.png';
$fullName     = $_SESSION['full_name'] ?? 'System Administrator';

$search = trim($_GET['search'] ?? '');
$action = trim($_GET['action'] ?? '');

$where = " WHERE 1=1 ";
$params = [];
$types = "";

if ($search !== '') {
    /* Search only the Action, Target, and Date & Time values shown in the log table. */
    $where .= " AND (
        l.action LIKE ?
        OR l.target_type LIKE ?
        OR CAST(l.target_id AS CHAR) LIKE ?
        OR CONCAT(COALESCE(l.target_type, ''), ' #', COALESCE(CAST(l.target_id AS CHAR), '')) LIKE ?
        OR DATE_FORMAT(l.created_at, '%Y-%m-%d %H:%i:%s') LIKE ?
        OR DATE_FORMAT(l.created_at, '%b %d, %Y') LIKE ?
        OR DATE_FORMAT(l.created_at, '%M %d, %Y') LIKE ?
        OR DATE_FORMAT(l.created_at, '%h:%i %p') LIKE ?
        OR DATE_FORMAT(l.created_at, '%b %d, %Y %h:%i %p') LIKE ?
    ) ";

    $like = "%{$search}%";
    for ($i = 0; $i < 9; $i++) {
        $params[] = $like;
    }
    $types .= str_repeat('s', 9);
}

if ($action !== '') {
    $where .= " AND l.action = ? ";
    $params[] = $action;
    $types .= "s";
}

$logs = [];
$actions = [];

$actionSql = "SELECT DISTINCT action FROM admin_logs ORDER BY action ASC";
$actionResult = $conn->query($actionSql);
if ($actionResult) {
    while ($row = $actionResult->fetch_assoc()) {
        $actions[] = $row['action'];
    }
}

$sql = "
    SELECT 
        l.id,
        l.admin_id,
        l.action,
        l.target_type,
        l.target_id,
        l.description,
        l.previous_hash,
        l.log_hash,
        l.ip_address,
        l.user_agent,
        l.created_at,
        u.full_name AS admin_name,
        u.username AS admin_username
    FROM admin_logs l
    LEFT JOIN users u ON l.admin_id = u.id
    $where
    ORDER BY l.created_at DESC, l.id DESC
";

$stmt = $conn->prepare($sql);
if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $logs[] = $row;
    }
    $stmt->close();
}

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

function renderAdminLogsTable(array $logs): void
{
    ?>
    <div class="table-wrap logs-table-wrap" id="adminLogsTableWrap">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Admin</th>
                    <th>Action</th>
                    <th>Target</th>
                    <th>Integrity</th>
                    <th>Date &amp; Time</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($logs)): ?>
                <tr>
                    <td colspan="7">No admin log records found.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($logs as $log): ?>
                    <?php
                    $isValidIntegrity = !empty($log['log_hash']) && verifyAdminLogRowIntegrity($log);
                    $targetLabel = trim(($log['target_type'] ?? '') . ' #' . (int)($log['target_id'] ?? 0));
                    ?>
                    <tr>
                        <td><?php echo (int)$log['id']; ?></td>
                        <td>
                            <div class="log-admin-cell">
                                <span class="log-admin-name"><?php echo e($log['admin_name'] ?? 'Unknown Admin'); ?></span>
                                <span class="log-admin-username">@<?php echo e($log['admin_username'] ?? 'N/A'); ?></span>
                            </div>
                        </td>
                        <td><span class="log-action-chip"><?php echo e($log['action']); ?></span></td>
                        <td><?php echo e($targetLabel); ?></td>
                        <td>
                            <span class="mini-badge <?php echo $isValidIntegrity ? 'yes' : 'no'; ?>">
                                <?php echo $isValidIntegrity ? 'Valid' : 'Legacy/Check'; ?>
                            </span>
                        </td>
                        <td><?php echo !empty($log['created_at']) ? e(date('M d, Y h:i A', strtotime($log['created_at']))) : '—'; ?></td>
                        <td>
                            <button
                                type="button"
                                class="btn btn-silver log-details-btn"
                                data-id="<?php echo (int)$log['id']; ?>"
                                data-admin="<?php echo e($log['admin_name'] ?? 'Unknown Admin'); ?>"
                                data-username="<?php echo e($log['admin_username'] ?? 'N/A'); ?>"
                                data-action="<?php echo e($log['action']); ?>"
                                data-target="<?php echo e($targetLabel); ?>"
                                data-description="<?php echo e($log['description'] ?? ''); ?>"
                                data-integrity="<?php echo $isValidIntegrity ? 'Valid' : 'Legacy/Check'; ?>"
                                data-ip="<?php echo e($log['ip_address'] ?? '—'); ?>"
                                data-agent="<?php echo e($log['user_agent'] ?? '—'); ?>"
                                data-date="<?php echo !empty($log['created_at']) ? e(date('M d, Y h:i A', strtotime($log['created_at']))) : '—'; ?>"
                            >
                                <i class="bi bi-eye"></i> View
                            </button>
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
    renderAdminLogsTable($logs);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php include __DIR__ . '/nexgen_favicon/favicon.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Logs - NextGen</title>
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
        .logs-table-wrap {
            max-height: 500px;
            overflow-y: auto;
            overflow-x: auto;
            border-radius: 18px;
            position: relative;
            transition: opacity 0.15s ease;
        }

        .logs-table-wrap.loading {
            opacity: 0.55;
            pointer-events: none;
        }

        .logs-table-wrap table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        .logs-table-wrap thead th {
            position: sticky;
            top: 0;
            z-index: 5;
            background: rgba(8, 16, 50, 0.94);
            backdrop-filter: blur(8px);
        }

        html[data-theme="light"] .logs-table-wrap thead th {
            background: rgba(214, 235, 255, 0.92);
        }

        .logs-table-wrap::-webkit-scrollbar {
            width: 10px;
            height: 10px;
        }

        .logs-table-wrap::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.25);
            border-radius: 999px;
        }

        .logs-table-wrap::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 999px;
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
            min-width: 90px;
        }

        .mini-badge.yes {
            background: rgba(70, 194, 126, 0.18);
            color: #8df0b3;
        }

        .mini-badge.no {
            background: rgba(255, 107, 107, 0.18);
            color: #ffb0b0;
        }

        html[data-theme="light"] .mini-badge.yes {
            background: rgba(16, 138, 92, 0.14);
            color: #0f7a52;
        }

        html[data-theme="light"] .mini-badge.no {
            background: rgba(200, 30, 77, 0.12);
            color: #c81e4d;
        }

        .log-admin-cell {
            display: flex;
            flex-direction: column;
            gap: 2px;
            line-height: 1.3;
        }

        .log-admin-name {
            font-weight: 600;
            color: var(--white);
        }

        .log-admin-username {
            font-size: 11px;
            color: var(--muted);
        }

        .log-action-chip {
            display: inline-block;
            padding: 3px 9px;
            border-radius: 8px;
            background: var(--card);
            border: 1px solid var(--line);
            font-size: 11.5px;
            font-weight: 600;
            color: var(--text);
            white-space: nowrap;
        }

        .log-details-btn {
            padding: 6px 12px !important;
            font-size: 12px !important;
        }

        /* LOG DETAIL MODAL */
        .log-detail-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transition: 0.22s ease;
            z-index: 99999;
            padding: 20px;
        }

        .log-detail-overlay.show {
            opacity: 1;
            visibility: visible;
            pointer-events: auto;
        }

        .log-detail-box {
            width: 100%;
            max-width: 560px;
            max-height: 84vh;
            overflow-y: auto;
            background: linear-gradient(180deg, rgba(59, 130, 246, 0.16) 0%, rgba(5, 11, 36, 0.96) 100%);
            border-radius: 18px;
            padding: 24px;
            box-shadow: 0 20px 45px rgba(0, 0, 0, 0.3);
            color: var(--text);
            border: 1px solid rgba(125, 211, 252, 0.28);
            transform: translateY(12px) scale(0.97);
            transition: 0.2s ease;
        }

        html[data-theme="light"] .log-detail-box {
            background: linear-gradient(180deg, #ffffff 0%, #eaf4ff 100%);
            border-color: rgba(59, 130, 246, 0.25);
        }

        .log-detail-overlay.show .log-detail-box {
            transform: translateY(0) scale(1);
        }

        .log-detail-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 16px;
            border-bottom: 1px solid var(--line);
            padding-bottom: 14px;
        }

        .log-detail-header h3 {
            font-size: 18px;
            font-weight: 800;
            margin-bottom: 4px;
        }

        .log-detail-header p {
            font-size: 12.5px;
            color: var(--muted);
        }

        .log-detail-close {
            background: var(--card);
            border: none;
            color: var(--text);
            width: 32px;
            height: 32px;
            border-radius: 999px;
            cursor: pointer;
            flex-shrink: 0;
            font-size: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .log-detail-close:hover {
            background: var(--card-hover);
        }

        .log-detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px 18px;
            margin-bottom: 16px;
        }

        .log-detail-field {
            min-width: 0;
        }

        .log-detail-field.full {
            grid-column: 1 / -1;
        }

        .log-detail-field label {
            display: block;
            font-size: 10.5px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: var(--muted);
            margin-bottom: 4px;
            font-weight: 700;
        }

        .log-detail-field div {
            font-size: 13.5px;
            word-break: break-word;
            line-height: 1.45;
        }

        .log-detail-description {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 12px 14px;
            font-size: 13.5px;
            line-height: 1.55;
            white-space: pre-wrap;
            word-break: break-word;
        }

        @media (max-width: 520px) {
            .log-detail-grid {
                grid-template-columns: 1fr;
            }
        }

        .page-title h1 {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-title h1 i.bi-pc-display-horizontal {
            font-size: 0.8em;
        }

        /* Keep search bar + action dropdown on the same row, at all widths.
           admin_module.css has a @media (max-width: 992px) rule that sets
           .filters { flex-direction: column }. CSS cascades per-property,
           so without an explicit flex-direction override here, that rule
           wins on smaller screens even though width/flex are overridden
           below. flex-direction is set explicitly (with !important) so it
           beats that breakpoint regardless of source order. */
        .filters {
            display: flex !important;
            flex-direction: row !important;
            flex-wrap: nowrap !important;
            align-items: center !important;
            gap: 8px;
        }

        .filters .input.flex-1 {
            flex: 1 1 auto !important;
            width: auto !important;
            min-width: 0;
        }

        .filters .select.w-240 {
            flex: 0 0 auto !important;
            width: 120px !important;
        }

        .filters .btn {
            flex: 0 0 auto !important;
            width: auto !important;
            padding-left: 10px !important;
            padding-right: 10px !important;
        }

        @media (min-width: 640px) {
            .filters {
                gap: 12px;
            }

            .filters .select.w-240 {
                width: 200px;
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

    <main class="admin-content">
        <div class="topbar">
            <div class="page-title">
                <h1><i class="bi bi-pc-display-horizontal"></i> Admin Logs</h1>
            </div>
            
        </div>

        <section class="panel">
            <div class="panel-header">
                <h2>Activity Log Records</h2>
            </div>
            <div class="panel-body">
                <form method="GET" class="filters" id="logsFilterForm" onsubmit="return false;">
                    <input
                        class="input flex-1"
                        type="text"
                        name="search"
                        id="logsSearchInput"
                        placeholder="Search action, target, ID, or date/time..."
                        value="<?php echo e($search); ?>"
                        autocomplete="off"
                        aria-label="Search admin logs by action, target, ID, or date and time"
                    >

                    <select class="select w-240" name="action" id="logsActionFilter">
                        <option value="">All Actions</option>
                        <?php foreach ($actions as $act): ?>
                            <option value="<?php echo e($act); ?>" <?php echo $action === $act ? 'selected' : ''; ?>>
                                <?php echo e($act); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button class="btn btn-silver" type="button" id="logsResetButton">Reset</button>
                </form>

                <div id="adminLogsContainer">
                    <?php renderAdminLogsTable($logs); ?>
                </div>
            </div>
        </section>
           
    </main>
</div>

<div class="log-detail-overlay" id="logDetailOverlay">
    <div class="log-detail-box">
        <div class="log-detail-header">
            <div>
                <h3 id="logDetailTitle">Log #0</h3>
                <p id="logDetailAction">—</p>
            </div>
            <button type="button" class="log-detail-close" id="logDetailClose" aria-label="Close">&times;</button>
        </div>

        <div class="log-detail-grid">
            <div class="log-detail-field">
                <label>Admin</label>
                <div id="logDetailAdmin">—</div>
            </div>
            <div class="log-detail-field">
                <label>Username</label>
                <div id="logDetailUsername">—</div>
            </div>
            <div class="log-detail-field">
                <label>Target</label>
                <div id="logDetailTarget">—</div>
            </div>
            <div class="log-detail-field">
                <label>Integrity</label>
                <div id="logDetailIntegrity">—</div>
            </div>
            <div class="log-detail-field">
                <label>Date &amp; Time</label>
                <div id="logDetailDate">—</div>
            </div>
            <div class="log-detail-field">
                <label>IP Address</label>
                <div id="logDetailIp">—</div>
            </div>
            <div class="log-detail-field full">
                <label>User Agent</label>
                <div id="logDetailAgent">—</div>
            </div>
            <div class="log-detail-field full">
                <label>Description</label>
                <div class="log-detail-description" id="logDetailDescription">—</div>
            </div>
        </div>
    </div>
</div>

<script src="/NexGen/CODE/JS/admin_module.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('logsFilterForm');
    const searchInput = document.getElementById('logsSearchInput');
    const actionFilter = document.getElementById('logsActionFilter');
    const resetButton = document.getElementById('logsResetButton');
    const container = document.getElementById('adminLogsContainer');

    let controller = null;

    function updateLogs() {
        const params = new URLSearchParams(new FormData(form));

        if (controller) {
            controller.abort();
        }

        controller = new AbortController();

        const currentWrap = document.getElementById('adminLogsTableWrap');
        if (currentWrap) {
            currentWrap.classList.add('loading');
        }

        fetch('admin_logs.php?' + params.toString(), {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            signal: controller.signal
        })
        .then(function(response) {
            return response.text();
        })
        .then(function(html) {
            container.innerHTML = html;
            const qs = params.toString();
            window.history.replaceState({}, '', 'admin_logs.php' + (qs ? '?' + qs : ''));
        })
        .catch(function(error) {
            if (error.name !== 'AbortError') {
                console.error('Admin logs live filter error:', error);
            }
        })
        .finally(function() {
            const newWrap = document.getElementById('adminLogsTableWrap');
            if (newWrap) {
                newWrap.classList.remove('loading');
            }
        });
    }

    if (searchInput) {
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                updateLogs();
            }
        });
    }

    if (actionFilter) {
        actionFilter.addEventListener('change', updateLogs);
    }

    if (resetButton) {
        resetButton.addEventListener('click', function () {
            if (searchInput) {
                searchInput.value = '';
            }
            if (actionFilter) {
                actionFilter.value = '';
            }
            updateLogs();
        });
    }

    /* LOG DETAIL MODAL */
    const overlay = document.getElementById('logDetailOverlay');
    const closeBtn = document.getElementById('logDetailClose');

    function openLogDetail(btn) {
        document.getElementById('logDetailTitle').textContent = 'Log #' + btn.dataset.id;
        document.getElementById('logDetailAction').textContent = btn.dataset.action || '—';
        document.getElementById('logDetailAdmin').textContent = btn.dataset.admin || '—';
        document.getElementById('logDetailUsername').textContent = '@' + (btn.dataset.username || 'N/A');
        document.getElementById('logDetailTarget').textContent = btn.dataset.target || '—';
        document.getElementById('logDetailIntegrity').textContent = btn.dataset.integrity || '—';
        document.getElementById('logDetailDate').textContent = btn.dataset.date || '—';
        document.getElementById('logDetailIp').textContent = btn.dataset.ip || '—';
        document.getElementById('logDetailAgent').textContent = btn.dataset.agent || '—';
        document.getElementById('logDetailDescription').textContent = btn.dataset.description || 'No description provided.';

        overlay.classList.add('show');
        document.body.style.overflow = 'hidden';
    }

    function closeLogDetail() {
        overlay.classList.remove('show');
        document.body.style.overflow = '';
    }

    container.addEventListener('click', function(e) {
        const btn = e.target.closest('.log-details-btn');
        if (btn) {
            openLogDetail(btn);
        }
    });

    if (closeBtn) {
        closeBtn.addEventListener('click', closeLogDetail);
    }

    if (overlay) {
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) {
                closeLogDetail();
            }
        });
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeLogDetail();
        }
    });
});
</script>
</body>
</html>