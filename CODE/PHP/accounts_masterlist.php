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

function hasColumn(mysqli $conn, string $table, string $column): bool
{
    $sql = "
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result && $result->num_rows > 0;
    $stmt->close();

    return $exists;
}

function initialsFromName(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $parts = array_filter($parts);

    if (empty($parts)) {
        return '?';
    }

    if (count($parts) === 1) {
        return strtoupper(mb_substr($parts[0], 0, 2));
    }

    $first = mb_substr(reset($parts), 0, 1);
    $last  = mb_substr(end($parts), 0, 1);

    return strtoupper($first . $last);
}

function renderAccountAvatar(array $account, string $className): void
{
    $name = (string)($account['full_name'] ?? 'User');
    $imagePath = trim((string)($account['profile_image'] ?? ''));
    ?>
    <div class="account-avatar <?php echo e($className); ?>" title="<?php echo e($name); ?>">
        <span class="account-avatar-initials" aria-hidden="true">
            <?php echo e(initialsFromName($name)); ?>
        </span>
        <?php if ($imagePath !== ''): ?>
            <img
                src="<?php echo e($imagePath); ?>"
                alt=""
                loading="lazy"
                onerror="this.remove();"
            >
        <?php endif; ?>
    </div>
    <?php
}

function statusBadgeClass(string $status): string
{
    return match (strtolower($status)) {
        'active' => 'badge-active-state',
        'inactive' => 'badge-inactive-state',
        'terminated' => 'badge-terminated-state',
        default => 'badge-inactive-state',
    };
}

$profileImage = !empty($_SESSION['profile_image']) ? $_SESSION['profile_image'] : 'uploads/default.png';
$fullName     = $_SESSION['full_name'] ?? 'System Administrator';

$search = trim($_GET['search'] ?? '');
$accounts = [];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$hasLastLoginAt = hasColumn($conn, 'users', 'last_login_at');
$hasProfileImage = hasColumn($conn, 'users', 'profile_image');
$activityExpr = $hasLastLoginAt
    ? "COALESCE(u.last_login_at, u.created_at)"
    : "u.created_at";
$profileImageExpr = $hasProfileImage
    ? "NULLIF(TRIM(u.profile_image), '')"
    : "NULL";

$where = " WHERE u.role IN ('owner', 'employee') ";
$params = [];
$types = "";

if ($search !== '') {
    $where .= " AND (
        u.employee_no LIKE ?
        OR u.full_name LIKE ?
        OR u.email LIKE ?
        OR u.phone LIKE ?
        OR u.role LIKE ?
    ) ";
    $like = "%{$search}%";
    $params = [$like, $like, $like, $like, $like];
    $types = "sssss";
}

$sql = "
    SELECT
        u.id,
        u.employee_no,
        u.full_name,
        u.email,
        u.phone,
        {$profileImageExpr} AS profile_image,
        CASE
            WHEN u.role = 'owner' THEN 'Owner'
            WHEN u.role = 'employee' THEN 'Employee'
            ELSE u.role
        END AS position,
        CASE
            WHEN u.account_status IN ('disabled', 'rejected') THEN 'terminated'
            WHEN {$activityExpr} < DATE_SUB(NOW(), INTERVAL 15 DAY) THEN 'inactive'
            ELSE 'active'
        END AS visibility_status,
        u.created_at
    FROM users u
    {$where}
    ORDER BY u.created_at DESC, u.id DESC
";

$stmt = $conn->prepare($sql);

if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $accounts[] = $row;
    }

    $stmt->close();
}

function renderAccountsTable(array $accounts): void
{
    ?>
    <div class="table-wrap accounts-table-wrap" id="accountsTableWrap">
        <table>
            <colgroup>
                <col style="width: 7%;">
                <col style="width: 20%;">
                <col style="width: 22%;">
                <col style="width: 14%;">
                <col style="width: 11%;">
                <col style="width: 10%;">
                <col style="width: 16%;">
            </colgroup>
            <thead>
                <tr>
                    <th>PROFILE</th>
                    <th>FULL NAME</th>
                    <th>EMAIL</th>
                    <th>PHONE</th>
                    <th>POSITION</th>
                    <th>STATUS</th>
                    <th>CREATED</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($accounts)): ?>
                <tr>
                    <td colspan="7">No account records found.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($accounts as $acc): ?>
                    <tr>
                        <td class="account-table-avatar-cell">
                            <?php renderAccountAvatar($acc, 'account-table-avatar'); ?>
                        </td>
                        <td class="account-name-col"><?php echo e($acc['full_name']); ?></td>
                        <td class="account-email-col"><?php echo e($acc['email']); ?></td>
                        <td class="account-phone-col"><?php echo e($acc['phone']); ?></td>
                        <td class="position-col"><?php echo e($acc['position']); ?></td>
                        <td class="status-col">
                            <span class="<?php echo e(statusBadgeClass((string)$acc['visibility_status'])); ?>">
                                <?php echo e(ucfirst((string)$acc['visibility_status'])); ?>
                            </span>
                        </td>
                        <td class="created-col">
                            <?php echo !empty($acc['created_at']) ? e(date('M d, Y h:i A', strtotime($acc['created_at']))) : '—'; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="accounts-cards-wrap" id="accountsCardsWrap">
        <?php if (empty($accounts)): ?>
            <div class="account-card-empty">No account records found.</div>
        <?php else: ?>
            <?php foreach ($accounts as $acc): ?>
                <div class="account-card">
                    <?php renderAccountAvatar($acc, 'account-card-avatar'); ?>
                    <div class="account-card-body">
                        <div class="account-card-top">
                            <span class="account-card-name"><?php echo e($acc['full_name']); ?></span>
                            <span class="position-pill"><?php echo e($acc['position']); ?></span>
                        </div>
                        <div class="account-card-meta-row">
                            <span class="account-card-phone">
                                <i class="bi bi-telephone-fill"></i>
                                <?php echo e($acc['phone']); ?>
                            </span>
                        </div>
                        <div class="account-card-bottom-row">
                            <span class="<?php echo e(statusBadgeClass((string)$acc['visibility_status'])); ?>">
                                <?php echo e(ucfirst((string)$acc['visibility_status'])); ?>
                            </span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php
}

if (
    !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
) {
    renderAccountsTable($accounts);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php include __DIR__ . '/nexgen_favicon/favicon.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accounts Masterlist - NextGen</title>
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
        .accounts-table-wrap {
            max-height: 460px;
            overflow-y: auto;
            overflow-x: auto;
            border-radius: 18px;
            position: relative;
            transition: opacity 0.15s ease;
        }

        .accounts-table-wrap.loading {
            opacity: 0.55;
            pointer-events: none;
        }

        .accounts-table-wrap table {
            width: 100%;
            min-width: 1100px;
            border-collapse: separate;
            border-spacing: 0;
            table-layout: fixed;
        }

        .accounts-table-wrap th,
        .accounts-table-wrap td {
            padding: 13px 16px;
            vertical-align: middle;
        }

        .accounts-table-wrap tbody tr {
            height: 66px;
        }

        .accounts-table-wrap thead th {
            position: sticky;
            top: 0;
            z-index: 5;
            background: rgba(8, 16, 50, 0.94);
            backdrop-filter: blur(8px);
        }

        html[data-theme="light"] .accounts-table-wrap thead th {
            background: rgba(214, 235, 255, 0.92);
        }

        .accounts-table-wrap::-webkit-scrollbar {
            width: 10px;
            height: 10px;
        }

        .accounts-table-wrap::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.25);
            border-radius: 999px;
        }

        .accounts-table-wrap::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 999px;
        }

        .badge-active-state,
        .badge-inactive-state,
        .badge-terminated-state {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 110px;
            padding: 7px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            white-space: nowrap;
        }

        .badge-active-state {
            background: rgba(71, 193, 117, 0.18);
            color: #90f0b3;
        }

        .badge-inactive-state {
            background: rgba(255, 193, 7, 0.18);
            color: #ffd86a;
        }

        .badge-terminated-state {
            background: rgba(255, 107, 107, 0.18);
            color: #ffb3b3;
        }

        html[data-theme="light"] .badge-active-state {
            background: rgba(16, 138, 92, 0.14);
            color: #0f7a52;
        }

        html[data-theme="light"] .badge-inactive-state {
            background: rgba(217, 155, 12, 0.14);
            color: #9a6a12;
        }

        html[data-theme="light"] .badge-terminated-state {
            background: rgba(200, 30, 77, 0.12);
            color: #c81e4d;
        }

        .created-col,
        .status-col,
        .position-col,
        .account-phone-col {
            white-space: nowrap;
        }

        .account-table-avatar-cell,
        .accounts-table-wrap th:first-child,
        .account-phone-col,
        .position-col,
        .status-col,
        .created-col {
            text-align: center;
        }

        .account-name-col,
        .account-email-col {
            overflow-wrap: anywhere;
        }

        .account-avatar {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
            overflow: hidden;
            border-radius: 50%;
            background: linear-gradient(145deg, #355fc4, #203b86);
            color: #ffffff;
            border: 2px solid rgba(143, 180, 255, 0.42);
            box-shadow: 0 6px 16px rgba(4, 12, 38, 0.28);
            font-weight: 800;
            letter-spacing: 0.3px;
        }

        .account-avatar-initials {
            position: relative;
            z-index: 1;
        }

        .account-avatar img {
            position: absolute;
            z-index: 2;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .account-table-avatar {
            width: 44px;
            height: 44px;
            margin: 0 auto;
            font-size: 13px;
        }

        #accountsSearchInput {
            flex: 1 1 auto !important;
            width: auto !important;
            max-width: none !important;
            min-width: 0;
        }

        #accountsFilterForm {
            display: flex;
            align-items: center;
            gap: 12px;
            width: 100%;
            padding-top: 20px !important;
        }

        #accountsFilterForm .btn {
            flex: 0 0 auto;
            white-space: nowrap;
        }

        @media (max-width: 768px) {
            #accountsFilterForm {
                display: flex !important;
                flex-direction: column !important;
                align-items: flex-start !important;
                gap: 12px !important;
            }

            #accountsSearchInput {
                flex: 0 0 auto !important;
                width: 100% !important;
                max-width: 100% !important;
            }

            #accountsFilterForm .btn {
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                gap: 8px !important;
                flex: 0 0 auto !important;
                width: auto !important;
                min-width: 0 !important;
                max-width: none !important;
            }
        }

        /* Mobile card list */
        .accounts-cards-wrap {
            display: none;
        }

        .account-card-empty {
            padding: 18px;
            text-align: center;
            opacity: 0.7;
        }

        .account-card {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 16px 18px;
            margin-bottom: 12px;
            border-radius: 16px;
            background: var(--card);
            border: 1px solid var(--line);
        }

        .account-card-avatar {
            flex: 0 0 auto;
            width: 46px;
            height: 46px;
            font-size: 15px;
            align-self: center;
        }

        .account-card-body {
            flex: 1 1 auto;
            min-width: 0;
        }

        .account-card-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
        }

        .account-card-name {
            font-weight: 800;
            font-size: 15px;
            line-height: 1.3;
        }

        .position-pill {
            flex: 0 0 auto;
            padding: 5px 12px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
            background: rgba(91, 141, 239, 0.14);
            color: #8fb4ff;
            border: 1px solid rgba(143, 180, 255, 0.4);
        }

        html[data-theme="light"] .position-pill {
            background: rgba(59, 130, 246, 0.12);
            color: #1d4ed8;
            border-color: rgba(59, 130, 246, 0.3);
        }

        .account-card-meta-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 6px;
            font-size: 13px;
            opacity: 0.75;
        }

        .account-card-phone {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }

        .account-card-bottom-row {
            margin-top: 10px;
        }

        @media (max-width: 768px) {
            .accounts-table-wrap {
                display: none;
            }

            .accounts-cards-wrap {
                display: block;
            }
        }
    </style>
</head>
<body>
<div class="admin-shell">
    <?php include 'admin_sidebar.php'; ?>

    <main class="admin-content">
        <div class="topbar">
            <div class="page-title">
                <h1><i class="bi bi-person-lines-fill" style="margin-right: 10px;"></i>Accounts Masterlist</h1>
            </div>
            
        </div>

        <?php if ($flash): ?>
            <div class="notice <?php echo e($flash['type']); ?>">
                <?php echo e($flash['message']); ?>
            </div>
        <?php endif; ?>

        <section class="panel">
            <div class="panel-header">
                <h2>Accounts Lists</h2>
            </div>

            <form method="GET" class="filters" id="accountsFilterForm" onsubmit="return false;">
                <input
                    class="input flex-3"
                    type="text"
                    name="search"
                    id="accountsSearchInput"
                    placeholder="Search fullname, email, phone, or position..."
                    value="<?php echo e($search); ?>"
                    autocomplete="off"
                >
                <button class="btn btn-silver" type="button" id="accountsResetButton"><i class="bi bi-arrow-repeat"></i> Reset</button>
            </form>

            <div id="accountsContainer">
                <?php renderAccountsTable($accounts); ?>
            </div>
        </section>
    </main>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('accountsFilterForm');
    const searchInput = document.getElementById('accountsSearchInput');
    const resetButton = document.getElementById('accountsResetButton');
    const container = document.getElementById('accountsContainer');

    let controller = null;

    function updateAccounts() {
        const params = new URLSearchParams(new FormData(form));

        if (controller) {
            controller.abort();
        }

        controller = new AbortController();

        const currentWrap = document.getElementById('accountsTableWrap');
        if (currentWrap) {
            currentWrap.classList.add('loading');
        }

        fetch('accounts_masterlist.php?' + params.toString(), {
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
            window.history.replaceState({}, '', 'accounts_masterlist.php' + (qs ? '?' + qs : ''));
        })
        .catch(function(error) {
            if (error.name !== 'AbortError') {
                console.error('Accounts masterlist live filter error:', error);
            }
        })
        .finally(function() {
            const newWrap = document.getElementById('accountsTableWrap');
            if (newWrap) {
                newWrap.classList.remove('loading');
            }
        });
    }

    if (searchInput) {
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                updateAccounts();
            }
        });
    }

    if (resetButton) {
        resetButton.addEventListener('click', function () {
            if (searchInput) {
                searchInput.value = '';
            }
            updateAccounts();
        });
    }
});
</script>
</body>
</html>
