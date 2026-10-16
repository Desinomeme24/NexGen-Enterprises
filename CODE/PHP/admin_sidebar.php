<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('e')) {
    function e($str) {
        return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
    }
}

$profileImage  = !empty($_SESSION['profile_image']) ? $_SESSION['profile_image'] : 'uploads/default.png';
$adminFullName = $_SESSION['full_name'] ?? 'System Administrator';
$currentPage   = basename($_SERVER['PHP_SELF']);
$pendingRequestCount = 0;

if (isset($conn) && $conn instanceof mysqli) {
    $accountCountResult = $conn->query(
        "SELECT COUNT(*) AS total
         FROM registration_requests
         WHERE request_status IN ('pending', 'resubmit')"
    );
    if ($accountCountResult instanceof mysqli_result) {
        $pendingRequestCount += (int)($accountCountResult->fetch_assoc()['total'] ?? 0);
    }

    $workspaceTableResult = $conn->query(
        "SELECT COUNT(*) AS total
         FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'workspace_requests'"
    );
    $workspaceTableExists = $workspaceTableResult instanceof mysqli_result
        && (int)($workspaceTableResult->fetch_assoc()['total'] ?? 0) === 1;

    if ($workspaceTableExists) {
        $workspaceCountResult = $conn->query(
            "SELECT COUNT(*) AS total
             FROM workspace_requests
             WHERE request_status = 'pending'"
        );
        if ($workspaceCountResult instanceof mysqli_result) {
            $pendingRequestCount += (int)($workspaceCountResult->fetch_assoc()['total'] ?? 0);
        }
    }
}

$pendingPageActive = in_array(
    $currentPage,
    ['pending_requests.php', 'view_request.php', 'view_workspace_request.php'],
    true
);
?>

<button type="button" class="mobile-nav-toggle" id="mobileNavToggle" aria-label="Open menu" aria-expanded="false" aria-controls="adminSidebar">
    <i class="bi bi-list"></i>
</button>
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<aside class="admin-sidebar admin-sidebar-custom" id="adminSidebar">
    <div class="admin-profile" id="adminProfile">
        <button
            type="button"
            class="brand-box admin-profile-toggle"
            id="adminProfileToggle"
            aria-expanded="false"
            aria-controls="adminProfileDropdown"
        >
            <span class="avatar-ring">
                <img src="/NexGen/CODE/PHP/<?php echo e($profileImage); ?>" alt="Profile">
                <span class="status-dot" title="Online"></span>
            </span>
            <span class="brand-meta">
                <span class="admin-profile-name"><?php echo e($adminFullName); ?></span>
                <span class="admin-profile-role">System Administrator</span>
            </span>
            <i class="bi bi-chevron-down admin-profile-chevron" aria-hidden="true"></i>
        </button>

        <div class="admin-profile-dropdown" id="adminProfileDropdown" role="menu" aria-hidden="true">
            <a
                href="/NexGen/CODE/PHP/settings.php"
                class="admin-profile-action <?php echo $currentPage === 'settings.php' ? 'active' : ''; ?>"
                role="menuitem"
            >
                <i class="bi bi-gear"></i>
                <span>Settings</span>
            </a>
            <button type="button" class="admin-profile-action admin-profile-logout" id="adminProfileLogout" role="menuitem">
                <i class="bi bi-box-arrow-right"></i>
                <span>Log Out</span>
            </button>
        </div>
    </div>

    <nav class="admin-menu admin-menu-custom">
        <div class="admin-menu-main">
            <a href="admin_dashboard.php" class="<?php echo $currentPage === 'admin_dashboard.php' ? 'active' : ''; ?>"><i class="bi bi-house"></i><span>Dashboard</span></a>
            <a href="pending_requests.php" class="<?php echo $pendingPageActive ? 'active' : ''; ?>">
                <i class="bi bi-clock-history"></i><span>Pending Requests</span>
                <?php if ($pendingRequestCount > 0): ?>
                    <span class="admin-pending-count" aria-label="<?php echo (int)$pendingRequestCount; ?> pending requests">
                        <?php echo $pendingRequestCount > 99 ? '99+' : (int)$pendingRequestCount; ?>
                    </span>
                <?php endif; ?>
            </a>
            <a href="manage_users.php" class="<?php echo $currentPage === 'manage_users.php' ? 'active' : ''; ?>"><i class="bi bi-people"></i><span>Manage Users</span></a>
            <a href="accounts_masterlist.php" class="<?php echo $currentPage === 'accounts_masterlist.php' ? 'active' : ''; ?>"><i class="bi bi-list-ul"></i><span>Accounts Masterlist</span></a>
            <a href="admin_logs.php" class="<?php echo $currentPage === 'admin_logs.php' ? 'active' : ''; ?>"><i class="bi bi-file-earmark-text"></i><span>Admin Logs</span></a>
        </div>
    </nav>
</aside>

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

<style>
.admin-sidebar-custom {
    display: flex;
    flex-direction: column;
    min-height: 100vh;
    height: 100vh;
    box-sizing: border-box;
    padding-bottom: 22px;
}

.admin-profile {
    position: relative;
    margin-bottom: 20px;
    z-index: 80;
}

.admin-profile-toggle {
    width: 100%;
    margin-bottom: 0;
    border: 0;
    border-bottom: 1px solid var(--line);
    background: transparent;
    color: inherit;
    font: inherit;
    text-align: left;
    cursor: pointer;
    transition: background 0.18s ease, border-color 0.18s ease;
}

.admin-profile-toggle:hover,
.admin-profile-toggle:focus-visible,
.admin-profile.open .admin-profile-toggle {
    background: var(--card);
    border-color: var(--glass-border);
    border-radius: 14px;
    outline: none;
}

.admin-profile-name,
.admin-profile-role {
    display: block;
}

.admin-profile-name {
    font-family: var(--font-display);
    font-size: 15px;
    font-weight: 700;
    line-height: 1.2;
    word-break: break-word;
    color: var(--white);
}

.admin-profile-role {
    margin-top: 3px;
    font-size: 11.5px;
    font-weight: 700;
    letter-spacing: 0.4px;
    text-transform: uppercase;
    color: var(--gold);
    word-break: break-word;
}

.admin-profile-chevron {
    margin-left: auto;
    flex-shrink: 0;
    color: var(--muted);
    font-size: 13px;
    transition: transform 0.2s ease, color 0.2s ease;
}

.admin-profile.open .admin-profile-chevron {
    transform: rotate(180deg);
    color: var(--gold);
}

.admin-profile-dropdown {
    position: absolute;
    top: calc(100% + 8px);
    left: 0;
    right: 0;
    display: grid;
    gap: 6px;
    padding: 8px;
    border: 1px solid var(--glass-border);
    border-radius: 14px;
    background: rgba(5, 11, 36, 0.98);
    box-shadow: 0 18px 38px rgba(2, 4, 15, 0.46);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
    transform: translateY(-8px) scale(0.98);
    transform-origin: top center;
    transition: opacity 0.18s ease, transform 0.18s ease, visibility 0.18s ease;
}

html[data-theme="light"] .admin-profile-dropdown {
    background: rgba(255, 255, 255, 0.98);
    box-shadow: 0 18px 38px rgba(11, 31, 115, 0.18);
}

.admin-profile.open .admin-profile-dropdown {
    opacity: 1;
    visibility: visible;
    pointer-events: auto;
    transform: translateY(0) scale(1);
}

.admin-profile-action {
    width: 100%;
    min-height: 42px;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    box-sizing: border-box;
    border: 1px solid transparent;
    border-radius: 10px;
    background: transparent;
    color: var(--text);
    font: inherit;
    font-size: 13.5px;
    font-weight: 650;
    text-align: left;
    text-decoration: none;
    cursor: pointer;
    transition: background 0.16s ease, border-color 0.16s ease, color 0.16s ease;
}

.admin-profile-action i {
    width: 18px;
    flex-shrink: 0;
    text-align: center;
    font-size: 16px;
}

.admin-profile-action:hover,
.admin-profile-action:focus-visible,
.admin-profile-action.active {
    background: var(--glass);
    border-color: var(--glass-border);
    color: var(--gold);
    outline: none;
}

.admin-profile-logout {
    color: #ff9d9d;
}

.admin-profile-logout:hover,
.admin-profile-logout:focus-visible {
    background: var(--danger-soft);
    border-color: rgba(255, 107, 107, 0.22);
    color: #ff6b6b;
}

.admin-menu-custom {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-height: 0;
    margin-top: 18px;
}

.admin-menu-main {
    display: flex;
    flex-direction: column;
    justify-content: flex-start;
    flex: 1;
    min-height: 0;
    gap: 8px;
}

.admin-menu-main a {
    display: flex;
    align-items: center;
    gap: 10px;
    min-height: 44px;
    box-sizing: border-box;
}

.admin-menu-main a i {
    font-size: 16px;
    width: 18px;
    text-align: center;
    flex-shrink: 0;
}

.admin-menu-main a .admin-pending-count {
    margin-left: auto;
    min-width: 22px;
    height: 22px;
    padding: 0 6px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    box-sizing: border-box;
    border-radius: 999px;
    background: #f7c873;
    color: #342100;
    font-size: 11px;
    font-weight: 800;
    line-height: 1;
}

.logout-confirm-overlay {
    position: fixed;
    inset: 0;
    background: rgba(2, 4, 15, 0.55);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
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
    max-width: 340px;
    background: linear-gradient(180deg, rgba(59, 130, 246, 0.16) 0%, rgba(5, 11, 36, 0.96) 100%);
    border-radius: 20px;
    padding: 24px 20px;
    box-shadow: 0 20px 45px rgba(3, 3, 30, 0.5);
    text-align: center;
    color: #fff;
    border: 1px solid rgba(125, 211, 252, 0.28);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    transform: translateY(10px) scale(0.98);
    transition: 0.2s ease;
}

html[data-theme="light"] .logout-confirm-box {
    background: linear-gradient(180deg, #ffffff 0%, #eaf4ff 100%);
    color: #16224a;
    border-color: rgba(59, 130, 246, 0.25);
}

.logout-confirm-overlay.show .logout-confirm-box {
    transform: translateY(0) scale(1);
}

.logout-confirm-icon {
    width: 50px;
    height: 50px;
    margin: 0 auto 12px;
    border-radius: 50%;
    background: rgba(246, 203, 8, 0.14);
    border: 1px solid rgba(246, 203, 8, 0.35);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    color: #ffdd55;
}

.logout-confirm-box h3 {
    margin: 0 0 6px;
    font-family: 'Poppins', 'Sora', sans-serif;
    font-size: 19px;
    font-weight: 700;
    color: inherit;
}

.logout-confirm-box p {
    margin: 0 0 18px;
    font-size: 13.5px;
    color: rgba(255, 255, 255, 0.8);
    line-height: 1.5;
}

html[data-theme="light"] .logout-confirm-box p {
    color: rgba(22, 34, 74, 0.72);
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
    border-radius: 8px;
    padding: 10px 18px;
    font-size: 13.5px;
    font-weight: 600;
    cursor: pointer;
    min-width: 110px;
    transition: 0.15s ease;
}

.logout-btn-cancel {
    background: rgba(59, 130, 246, 0.16);
    color: inherit;
    border: 1px solid rgba(125, 211, 252, 0.25);
}

.logout-btn-cancel:hover {
    background: rgba(59, 130, 246, 0.26);
}

.logout-btn-confirm {
    background: linear-gradient(135deg, #ffdd55 0%, #f6cb08 100%);
    color: #2b1a00;
}

.logout-btn-confirm:hover {
    transform: translateY(-1px);
    box-shadow: 0 10px 20px rgba(246, 203, 8, 0.3);
}

@media (max-height: 760px) {
    .admin-sidebar-custom {
        height: auto;
        min-height: 100vh;
    }

    .admin-menu-main {
        justify-content: flex-start;
        gap: 8px;
    }

}
</style>

<script>
function openLogoutModal(event) {
    event.preventDefault();
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

function setAdminProfileMenu(isOpen) {
    const profile = document.getElementById('adminProfile');
    const toggle = document.getElementById('adminProfileToggle');
    const dropdown = document.getElementById('adminProfileDropdown');

    if (!profile || !toggle || !dropdown) return;

    profile.classList.toggle('open', isOpen);
    toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    dropdown.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
}

function closeAdminProfileMenu() {
    setAdminProfileMenu(false);
}

document.addEventListener('click', function(e) {
    const modal = document.getElementById('logoutConfirmOverlay');
    const profile = document.getElementById('adminProfile');

    if (e.target === modal) {
        closeLogoutModal();
    }

    if (profile && !profile.contains(e.target)) {
        closeAdminProfileMenu();
    }
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeLogoutModal();
        closeAdminProfileMenu();
        closeMobileSidebar();
    }
});

/* MOBILE SIDEBAR: hamburger toggle + overlay for small screens */
function openMobileSidebar() {
    const sidebar = document.getElementById('adminSidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const toggle = document.getElementById('mobileNavToggle');
    if (sidebar) sidebar.classList.add('mobile-open');
    if (overlay) overlay.classList.add('show');
    if (toggle) toggle.setAttribute('aria-expanded', 'true');
    document.body.style.overflow = 'hidden';
}

function closeMobileSidebar() {
    const sidebar = document.getElementById('adminSidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const toggle = document.getElementById('mobileNavToggle');
    if (sidebar) sidebar.classList.remove('mobile-open');
    if (overlay) overlay.classList.remove('show');
    if (toggle) toggle.setAttribute('aria-expanded', 'false');
    closeAdminProfileMenu();
    document.body.style.overflow = '';
}

document.addEventListener('DOMContentLoaded', function() {
    const toggleBtn = document.getElementById('mobileNavToggle');
    const overlay = document.getElementById('sidebarOverlay');
    const sidebar = document.getElementById('adminSidebar');
    const profileToggle = document.getElementById('adminProfileToggle');
    const profileDropdown = document.getElementById('adminProfileDropdown');
    const profileLogout = document.getElementById('adminProfileLogout');

    if (profileToggle) {
        profileToggle.addEventListener('click', function() {
            const isOpen = profileToggle.getAttribute('aria-expanded') === 'true';
            setAdminProfileMenu(!isOpen);
        });
    }

    if (profileDropdown) {
        profileDropdown.querySelectorAll('a').forEach(function(link) {
            link.addEventListener('click', function() {
                closeAdminProfileMenu();
                closeMobileSidebar();
            });
        });
    }

    if (profileLogout) {
        profileLogout.addEventListener('click', function(e) {
            closeAdminProfileMenu();
            closeMobileSidebar();
            openLogoutModal(e);
        });
    }

    if (toggleBtn) {
        toggleBtn.addEventListener('click', function() {
            if (sidebar && sidebar.classList.contains('mobile-open')) {
                closeMobileSidebar();
            } else {
                openMobileSidebar();
            }
        });
    }

    if (overlay) {
        overlay.addEventListener('click', closeMobileSidebar);
    }

    if (sidebar) {
        sidebar.querySelectorAll('.admin-menu-main a').forEach(function(link) {
            link.addEventListener('click', closeMobileSidebar);
        });
    }
});
</script>
