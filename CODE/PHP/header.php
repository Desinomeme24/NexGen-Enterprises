<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config.php';
require_once __DIR__ . '/ar_helper.php';
require_once __DIR__ . '/tenant_helper.php';

/* SESSION SECURITY: enforce 10-minute timeout on protected user pages using header */
enforceSessionTimeout();

$displayName  = $_SESSION['username'] ?? 'Client';
$fullName     = $_SESSION['full_name'] ?? 'Client';
$profileImage = !empty($_SESSION['profile_image']) ? $_SESSION['profile_image'] : 'uploads/default.png';
$currentPage  = basename($_SERVER['PHP_SELF']);
$role         = $_SESSION['role'] ?? 'employee';
$isOwner      = $role === 'owner';
$isEmployee   = $role === 'employee';
$canInventory = (int)($_SESSION['can_inventory'] ?? 0) === 1;
$canSales = (int)($_SESSION['can_sales'] ?? 0) === 1;
$canSalesAnalytics = (int)($_SESSION['can_sales_analytics'] ?? 0) === 1;
$canAccountsReceivable = nxArEnabled();
$hasAnyBusinessModule = $canInventory || $canSales || $canSalesAnalytics || $canAccountsReceivable;
$roleLabel = $isOwner ? 'Owner' : ($isEmployee ? 'Employee' : ucwords(str_replace('_', ' ', (string)$role)));
$workspaceOptions = in_array($role, ['owner', 'employee'], true)
    ? nxGetAccessibleWorkspaces($conn, (int)($_SESSION['user_id'] ?? 0), $role)
    : [];
$activeWorkspace = in_array($role, ['owner', 'employee'], true)
    ? nxGetActiveWorkspace($conn)
    : [];
$workspaceCount = count($workspaceOptions);
$activeWorkspaceLabel = !empty($activeWorkspace)
    ? trim((string)$activeWorkspace['business_name'] . ' — ' . (string)$activeWorkspace['branch_name'])
    : '';
$workspaceCsrfToken = generateCsrfToken('workspace_action');

$showCategoryOpen = in_array(
    $currentPage,
    ['inventory_management.php', 'sales_recording.php', 'sales_analytics.php', 'accounts_receivable.php'],
    true
);
?>
<script>
window.NEXGEN_PATHS = <?php echo json_encode([
    'app' => nxAppUrl(),
    'code' => nxCodeUrl(),
    'project' => nxProjectUrl(),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>

<aside class="sidebar" id="sidebar" aria-hidden="true">
    <div class="sidebar-top">
        <button class="close-sidebar" id="closeSidebar" type="button" aria-label="Close navigation menu">&times;</button>
    </div>

    <div class="profile-section">
        <form action="<?php echo e(nxAppUrl('update_profile_image.php')); ?>" method="POST" enctype="multipart/form-data" class="profile-edit-form">
            <input type="hidden" name="csrf_token" value="<?php echo e(generateCsrfToken('profile_image_form')); ?>">
            <div class="profile-image-wrapper">
                <img src="<?php echo e(nxAppUrl($profileImage)); ?>" alt="Profile" class="profile-img">
                <label for="new_profile_image" class="edit-profile-btn">✎</label>
                <input type="file" name="new_profile_image" id="new_profile_image" accept="image/*" hidden>
            </div>
            <button type="submit" class="hidden-upload-btn" id="submitProfileBtn">Upload</button>
        </form>

        <h2><?php echo htmlspecialchars($displayName); ?></h2>
        <p class="profile-fullname"><?php echo htmlspecialchars($fullName); ?></p>
        <span class="role-badge"><?php echo htmlspecialchars($roleLabel); ?></span>
    </div>

    <nav class="sidebar-menu">
        <a href="<?php echo e(nxAppUrl('dashboard.php')); ?>" class="menu-item <?php echo $currentPage === 'dashboard.php' ? 'active' : ''; ?>">
            <i class="bi bi-house-door-fill"></i>
            <span><?php echo $isOwner ? 'Owner Dashboard' : 'Employee Dashboard'; ?></span>
        </a>

        <a href="<?php echo e(nxAppUrl('about_us.php')); ?>" class="menu-item <?php echo $currentPage === 'about_us.php' ? 'active' : ''; ?>">
            <i class="bi bi-info-circle-fill"></i>
            <span>About Us</span>
        </a>

        <?php if ($hasAnyBusinessModule): ?>
        <div class="dropdown-section">
            <button class="dropdown-btn <?php echo $showCategoryOpen ? 'active-btn' : ''; ?>" id="categoryToggle" type="button" aria-controls="categoryMenu" aria-expanded="<?php echo $showCategoryOpen ? 'true' : 'false'; ?>">
                <span class="d-flex align-items-center gap-3">
                    <i class="bi bi-grid-1x2-fill"></i>
                    <span>Categories</span>
                </span>
                <span id="dropdownArrow">▼</span>
            </button>

            <div class="dropdown-content <?php echo $showCategoryOpen ? 'show' : ''; ?>" id="categoryMenu" aria-hidden="<?php echo $showCategoryOpen ? 'false' : 'true'; ?>">
                <?php if ($canInventory): ?>
                <a href="<?php echo e(nxAppUrl('inventory_management.php')); ?>" class="<?php echo $currentPage === 'inventory_management.php' ? 'active-sub active-submenu' : ''; ?>">
                    <i class="bi bi-box-seam-fill"></i>
                    <span>Inventory Management</span>
                </a>
                <?php endif; ?>

                <?php if ($canSales): ?>
                    <a href="<?php echo e(nxAppUrl('sales_recording.php')); ?>" class="<?php echo $currentPage === 'sales_recording.php' ? 'active-sub active-submenu' : ''; ?>">
                        <i class="bi bi-receipt-cutoff"></i>
                        <span>Sales</span>
                    </a>
                <?php endif; ?>

                <?php if ($canSalesAnalytics): ?>
                    <a href="<?php echo e(nxAppUrl('sales_analytics.php')); ?>" class="<?php echo $currentPage === 'sales_analytics.php' ? 'active-sub active-submenu' : ''; ?>">
                        <i class="bi bi-bar-chart-line-fill"></i>
                        <span>Sales Analytics</span>
                    </a>
                <?php endif; ?>

                <?php if ($canAccountsReceivable): ?>
                    <a href="<?php echo e(nxAppUrl('accounts_receivable.php')); ?>" class="<?php echo $currentPage === 'accounts_receivable.php' ? 'active-sub active-submenu' : ''; ?>">
                        <i class="bi bi-wallet2"></i>
                        <span>Accounts Receivable</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <a href="<?php echo e(nxAppUrl('settings.php')); ?>" class="menu-item <?php echo $currentPage === 'settings.php' ? 'active' : ''; ?>">
            <i class="bi bi-gear-wide-connected"></i>
            <span>Settings</span>
        </a>

        <a href="#" class="menu-item" onclick="event.preventDefault(); openLogoutModal(event)">
            <i class="bi bi-box-arrow-right"></i>
            <span>Log Out</span>
        </a>
    </nav>
</aside>

<div class="overlay" id="overlay" aria-hidden="true"></div>

<header class="topbar">
    <div class="topbar-left">
        <button class="menu-btn" id="openSidebar" type="button" aria-controls="sidebar" aria-expanded="false" aria-label="Open navigation menu">
            <span class="menu-btn-icon" aria-hidden="true">
                <span></span>
                <span></span>
                <span></span>
            </span>
        </button>
    </div>

    <div class="topbar-right">
        <?php if (!empty($activeWorkspace)): ?>
            <div class="workspace-topbar-control">
                <?php if ($workspaceCount > 1): ?>
                    <form action="<?php echo e(nxAppUrl('workspace_action.php')); ?>" method="POST" class="workspace-switch-form" data-workspace-switch-form>
                        <input type="hidden" name="csrf_token" value="<?php echo e($workspaceCsrfToken); ?>">
                        <input type="hidden" name="action" value="switch_workspace">
                        <input type="hidden" name="return_to" value="<?php echo e($currentPage); ?>">

                        <div class="workspace-picker" data-workspace-picker>
                            <button
                                type="button"
                                class="workspace-picker-toggle"
                                id="headerWorkspaceToggle"
                                aria-haspopup="listbox"
                                aria-expanded="false"
                                aria-controls="headerWorkspaceList"
                                title="<?php echo e($activeWorkspaceLabel); ?>"
                                data-workspace-toggle
                            >
                                <span class="workspace-picker-toggle-label" data-workspace-current-label><?php echo e($activeWorkspaceLabel); ?></span>
                                <i class="bi bi-chevron-down workspace-picker-chevron" aria-hidden="true"></i>
                            </button>

                            <div
                                class="workspace-picker-list<?php echo $workspaceCount >= 5 ? ' is-scrollable' : ''; ?>"
                                id="headerWorkspaceList"
                                role="listbox"
                                aria-labelledby="headerWorkspaceToggle"
                                data-workspace-list
                                hidden
                            >
                            <?php foreach ($workspaceOptions as $workspace): ?>
                                <?php
                                    $workspaceId = (int)$workspace['business_id'];
                                    $workspaceLabel = trim((string)$workspace['business_name'] . ' — ' . (string)$workspace['branch_name']);
                                    $isActiveWorkspace = $workspaceId === (int)$activeWorkspace['business_id'];
                                ?>
                                <button
                                    type="submit"
                                    name="business_id"
                                    value="<?php echo $workspaceId; ?>"
                                    class="workspace-picker-option<?php echo $isActiveWorkspace ? ' is-selected' : ''; ?>"
                                    role="option"
                                    aria-selected="<?php echo $isActiveWorkspace ? 'true' : 'false'; ?>"
                                    tabindex="-1"
                                    title="<?php echo e($workspaceLabel); ?>"
                                    data-workspace-option
                                >
                                    <span class="workspace-picker-option-label"><?php echo e($workspaceLabel); ?></span>
                                    <i class="bi bi-check2 workspace-picker-option-check" aria-hidden="true"></i>
                                </button>
                            <?php endforeach; ?>
                            </div>
                        </div>

                        <noscript>
                            <label for="headerWorkspaceFallbackSelect" class="visually-hidden">Active business branch</label>
                            <select name="business_id" id="headerWorkspaceFallbackSelect" class="workspace-fallback-select" aria-label="Active business branch">
                                <?php foreach ($workspaceOptions as $workspace): ?>
                                    <option value="<?php echo (int)$workspace['business_id']; ?>" <?php echo (int)$workspace['business_id'] === (int)$activeWorkspace['business_id'] ? 'selected' : ''; ?>>
                                        <?php echo e($workspace['business_name'] . ' — ' . $workspace['branch_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="workspace-switch-btn">Switch</button>
                        </noscript>
                    </form>
                <?php else: ?>
                    <div class="workspace-current-badge" title="<?php echo e($activeWorkspace['business_code'] . ' / ' . $activeWorkspace['branch_code']); ?>">
                        <i class="bi bi-shop"></i>
                        <span><?php echo e($activeWorkspace['business_name'] . ' — ' . $activeWorkspace['branch_name']); ?></span>
                    </div>
                <?php endif; ?>
                <?php if ($isOwner): ?>
                    <a href="<?php echo e(nxAppUrl('settings.php?panel=workspace-panel')); ?>" class="workspace-manage-link" title="Manage businesses and branches" aria-label="Manage businesses and branches">
                        <i class="bi bi-plus-circle"></i>
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <button class="theme-toggle" id="themeToggle" type="button" aria-label="Switch to light mode" aria-pressed="false">
            <span class="theme-toggle-thumb">
                <i class="bi bi-moon-stars-fill" id="themeIcon"></i>
            </span>
        </button>
    </div>
</header>

<div class="logout-modal-overlay" id="logoutModal">
    <div class="logout-modal-card">
        <div class="logout-modal-icon">
            <i class="bi bi-box-arrow-right"></i>
        </div>

        <h3>Log out?</h3>
        <p>You’re about to end your current session.</p>

       <form action="<?php echo e(nxAppUrl('logout.php')); ?>" method="POST" class="logout-form">
    <input type="hidden" name="csrf_token" value="<?php echo e(generateCsrfToken('logout_form')); ?>">
    <button type="button" class="logout-cancel-btn" onclick="closeLogoutModal()">Cancel</button>
    <button type="submit" class="logout-confirm-btn">Log Out</button>
</form>
    </div>
</div>

<style>
    .logout-trigger {
        width: 100%;
        border: none;
        background: transparent;
        text-align: left;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 12px;
        color: inherit;
        font: inherit;
    }

    .logout-modal-overlay {
        position: fixed;
        inset: 0;
        background: rgba(6, 15, 31, 0.6);
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
        opacity: 0;
        visibility: hidden;
        pointer-events: none;
        transition: all 0.25s ease;
        z-index: 99999;
    }

    .logout-modal-overlay.show {
        opacity: 1;
        visibility: visible;
        pointer-events: auto;
    }

    .logout-modal-card {
        width: 100%;
        max-width: 380px;
        background: rgba(255, 255, 255, 0.1);
        border: 1px solid rgba(227, 178, 126, 0.22);
        border-radius: 20px;
        box-shadow: 0 20px 45px rgba(0, 0, 0, 0.32);
        backdrop-filter: blur(18px);
        -webkit-backdrop-filter: blur(18px);
        padding: 28px 24px 22px;
        text-align: center;
        transform: translateY(20px) scale(0.96);
        transition: all 0.25s ease;
    }

    .logout-modal-overlay.show .logout-modal-card {
        transform: translateY(0) scale(1);
    }

    .logout-modal-icon {
        width: 64px;
        height: 64px;
        margin: 0 auto 14px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, var(--copper-500, #c6864c), var(--ink-900, #0a1a33));
        color: #fff;
        font-size: 26px;
        box-shadow: 0 10px 25px rgba(198, 134, 76, 0.35);
    }

    .logout-modal-card h3 {
        margin: 0 0 8px;
        color: #ffffff;
        font-family: var(--font-display, inherit);
        font-size: 1.4rem;
        font-weight: 700;
    }

    .logout-modal-card p {
        margin: 0 0 22px;
        color: rgba(255, 255, 255, 0.8);
        font-size: 0.97rem;
        line-height: 1.5;
    }

    .logout-form {
        display: flex;
        gap: 12px;
        justify-content: center;
    }

    .logout-cancel-btn,
    .logout-confirm-btn {
        border: none;
        border-radius: 12px;
        padding: 12px 18px;
        font-size: 0.95rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s ease;
        min-width: 120px;
    }

    .logout-cancel-btn {
        background: rgba(255, 255, 255, 0.12);
        color: #ffffff;
        border: 1px solid rgba(255, 255, 255, 0.16);
    }

    .logout-cancel-btn:hover {
        background: rgba(255, 255, 255, 0.2);
        transform: translateY(-1px);
    }

    .logout-confirm-btn {
        background: linear-gradient(135deg, var(--copper-500, #c6864c), #a8703c);
        color: #ffffff;
        box-shadow: 0 8px 18px rgba(198, 134, 76, 0.35);
    }

    .logout-confirm-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 12px 22px rgba(198, 134, 76, 0.42);
    }

    @media (max-width: 480px) {
        .logout-form {
            flex-direction: column;
        }

        .logout-cancel-btn,
        .logout-confirm-btn {
            width: 100%;
        }
    }
</style>

<script>
    function openLogoutModal() {
        const modal = document.getElementById('logoutModal');
        if (modal) {
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }
    }

    function closeLogoutModal() {
        const modal = document.getElementById('logoutModal');
        if (modal) {
            modal.classList.remove('show');
            document.body.style.overflow = '';
        }
    }

    document.addEventListener('click', function (e) {
        const modal = document.getElementById('logoutModal');
        if (e.target === modal) {
            closeLogoutModal();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            closeLogoutModal();
        }
    });

    /* SESSION SECURITY: client-side inactivity auto logout */
    (function () {
        const timeoutMs = <?php echo (int)SESSION_TIMEOUT_SECONDS * 1000; ?>;
        let inactivityTimer = null;
        let lastPingAt = 0;
        const pingThrottleMs = 60000;

        function triggerTimeoutLogout() {
            window.location.href = <?php echo json_encode(nxAppUrl('logout.php?timeout=1')); ?>;
        }

        function resetInactivityTimer() {
            clearTimeout(inactivityTimer);
            inactivityTimer = setTimeout(triggerTimeoutLogout, timeoutMs);

            // Keep $_SESSION['last_activity'] in sync with real user activity
            // (not just full page/AJAX requests) so users actively filling out
            // a long form aren't logged out - and don't lose unsaved data -
            // while the client-side timer above still shows them as active.
            const now = Date.now();
            if (now - lastPingAt >= pingThrottleMs) {
                lastPingAt = now;
                fetch(<?php echo json_encode(nxAppUrl('session_ping.php')); ?>, { method: 'POST', credentials: 'same-origin' }).catch(function () {});
            }
        }

        ['click', 'mousemove', 'keydown', 'scroll', 'touchstart'].forEach(function (eventName) {
            document.addEventListener(eventName, resetInactivityTimer, { passive: true });
        });

        resetInactivityTimer();
    })();
</script>
