<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/tenant_helper.php';
require_once __DIR__ . '/ar_helper.php';

/* SECURITY: config.php applies the hardened cookie settings and starts the
   session before any authenticated dashboard logic runs. */
enforceSessionTimeout();

if (!function_exists('dashboardInvalidateSession')) {
    function dashboardInvalidateSession(string $message): void
    {
        $loginPath = nxLoginPathForRole((string)($_SESSION['role'] ?? ''));
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        /* Do not reuse the identifier of the invalidated session when creating
           the short-lived flash-message session for the login page. */
        session_id('');
        session_start();
        if ($message !== '') {
            $_SESSION['error'] = $message;
        }
        $_SESSION['form_type'] = 'login';

        header('Location: ' . $loginPath);
        exit();
    }
}

if (empty($_SESSION['user_id'])) {
    header('Location: ' . NEXGEN_CLIENT_LOGIN_PATH);
    exit();
}

/* SECURITY: refresh status, role, and permissions from the database on every
   dashboard request. Revocations therefore take effect without waiting for
   the user to sign out and back in. */
$userId = (int)$_SESSION['user_id'];
$account = null;

try {
    $accountStmt = $conn->prepare(
        'SELECT username, full_name, profile_image, role, account_status,
                can_inventory, can_sales, can_sales_analytics,
                can_accounts_receivable
         FROM users
         WHERE id = ?
         LIMIT 1'
    );

    if (!$accountStmt) {
        throw new RuntimeException('Unable to prepare the dashboard account check.');
    }

    $accountStmt->bind_param('i', $userId);
    $accountStmt->execute();
    $account = $accountStmt->get_result()->fetch_assoc() ?: null;
    $accountStmt->close();
} catch (Throwable $e) {
    error_log('NexGen dashboard account validation failed: ' . $e->getMessage());
    http_response_code(503);
    exit('The dashboard is temporarily unavailable. Please try again.');
}

if (!$account || (string)$account['account_status'] !== 'active') {
    dashboardInvalidateSession('Your account is no longer active. Please contact the administrator.');
}

$role = (string)$account['role'];

$_SESSION['username'] = (string)$account['username'];
$_SESSION['full_name'] = (string)$account['full_name'];
$_SESSION['profile_image'] = !empty($account['profile_image'])
    ? (string)$account['profile_image']
    : 'uploads/default.png';
$_SESSION['role'] = $role;
$_SESSION['account_status'] = (string)$account['account_status'];
$_SESSION['can_inventory'] = (int)$account['can_inventory'];
$_SESSION['can_sales'] = (int)$account['can_sales'];
$_SESSION['can_sales_analytics'] = (int)$account['can_sales_analytics'];
$_SESSION['can_accounts_receivable'] = (int)$account['can_accounts_receivable'];

if ($role === 'system_admin') {
    header('Location: ' . nxAppUrl('admin_dashboard.php'));
    exit();
}

if (!in_array($role, ['owner', 'employee'], true)) {
    dashboardInvalidateSession('Your account role is not permitted to access this dashboard.');
}

/* MULTI-TENANCY: the dashboard is the safe landing page for an unassigned
   account. Do not call nxRequireBusinessId() here because that helper redirects
   missing workspaces back to dashboard.php and creates an infinite loop. */
$activeWorkspace = nxInitializeUserWorkspace($conn);
$businessId = (int)($activeWorkspace['business_id'] ?? 0);
$hasActiveWorkspace = $businessId > 0;

if (!$hasActiveWorkspace) {
    unset(
        $_SESSION['business_id'],
        $_SESSION['branch_id'],
        $_SESSION['business_entity_id'],
        $_SESSION['business_code'],
        $_SESSION['business_name'],
        $_SESSION['business_type'],
        $_SESSION['branch_code'],
        $_SESSION['branch_name'],
        $_SESSION['branch_address']
    );
}

$isOwner = $role === 'owner';
$canInventory = $hasActiveWorkspace && (int)$account['can_inventory'] === 1;
$canSales = $hasActiveWorkspace && (int)$account['can_sales'] === 1;
$canSalesAnalytics = $hasActiveWorkspace && (int)$account['can_sales_analytics'] === 1;
$canAccountsReceivable = $hasActiveWorkspace
    && (int)$account['can_accounts_receivable'] === 1
    && nxArEnabled();
$hasAnyBusinessModule = $canInventory || $canSales || $canSalesAnalytics || $canAccountsReceivable;

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

if (!$hasActiveWorkspace && $popupMessage === '') {
    $popupMessage = 'Your account is not connected to an active SME business branch. Please contact the administrator.';
    $popupType = 'error';
}

$pageTitle = $isOwner ? 'Owner Dashboard' : 'Employee Dashboard';
$pageSubtitle = $isOwner
    ? 'Manage inventory levels and view sales analytics.'
    : 'Manage inventory and record daily sales.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php include __DIR__ . '/nexgen_favicon/favicon.php'; ?>
    <?php include __DIR__ . '/theme_init.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($pageTitle); ?> - NexGen</title>
    <link rel="stylesheet" href="/NexGen/CODE/STYLE/dashboard.css">
    <link rel="stylesheet" href="/NexGen/CODE/STYLE/header.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
</head>
<body>

<?php if (!empty($popupMessage)): ?>
    <div class="popup-overlay" id="popupOverlay">
        <div class="popup-box <?php echo $popupType; ?>" id="popupBox">
            <div class="popup-icon">
                <?php echo $popupType === 'success' ? '✓' : '!'; ?>
            </div>
            <h3><?php echo $popupType === 'success' ? 'Success' : 'Error'; ?></h3>
            <p><?php echo e($popupMessage); ?></p>
        </div>
    </div>
<?php endif; ?>

<div class="dashboard-page">

    <?php include __DIR__ . '/header.php'; ?>

    <section class="top-video-area" id="topVideoArea">
        <div class="hero-bg-circuit" aria-hidden="true">
            <div class="circuit-orb orb-1"></div>
            <div class="circuit-orb orb-2"></div>
            <div class="circuit-orb orb-3"></div>
            <div class="circuit-orb orb-4"></div>
            <div class="circuit-flow"></div>
            <div class="circuit-grid"></div>
            <div class="circuit-pulses-layer" id="circuitPulses"></div>
        </div>

        <div class="hero-scroll-indicator">
            <span>Scroll Down</span>
            <i class="bi bi-chevron-double-down"></i>
        </div>

        <section class="hero-section modern-hero-section" id="home-section">
            <div class="modern-hero-shell" id="heroShell">
                <div class="modern-hero-badge">
                    <i class="bi bi-stars"></i>
                    <span><?php echo $isOwner ? 'Owner Workspace' : 'Employee Workspace'; ?></span>
                </div>

                <div class="modern-hero-text">
                    <h1><?php echo $isOwner ? 'Manage Your Business Smarter' : 'Work Faster Every Day'; ?></h1>
                    <p><?php echo e($pageSubtitle); ?></p>

                    <div class="modern-hero-actions">
                        <a href="#module-section" class="hero-btn modern-hero-btn">Open Modules</a>
                        <a href="/NexGen/CODE/PHP/about_us.php" class="hero-btn modern-hero-btn secondary-btn">Learn More</a>
                    </div>
                </div>
            </div>
        </section>

        <section class="module-section modern-module-section" id="module-section">
            <div class="module-section-header module-reveal">
                <span class="module-kicker">Business Tools</span>
                <h2><?php echo $isOwner ? 'Owner Modules' : 'Employee Modules'; ?></h2>
                <p>
                    <?php
                    if (!$hasActiveWorkspace) {
                        echo 'This account is not connected to an active business branch yet.';
                    } elseif (!$hasAnyBusinessModule) {
                        echo 'No business modules are currently assigned to this account.';
                    } elseif ($isOwner) {
                        echo 'Access your assigned business tools in one modern workspace.';
                    } else {
                        echo 'Access your assigned daily business tools in one clean workspace.';
                    }
                    ?>
                </p>
            </div>

            <div class="modern-module-grid">
                <?php if ($canInventory): ?>
                <a href="/NexGen/CODE/PHP/inventory_management.php" class="modern-module-card module-reveal">
                    <div class="module-card-glow"></div>
                    <div class="module-card-icon">
                        <i class="bi bi-box-seam-fill"></i>
                    </div>
                    <div class="module-card-content">
                        <h3>Inventory Management</h3>
                        <p>Manage products, monitor stock levels, update item details, and track inventory movement.</p>
                        <span class="module-card-link">Open Module <i class="bi bi-arrow-right"></i></span>
                    </div>
                </a>
                <?php endif; ?>

                <?php if ($canSales): ?>
                    <a href="/NexGen/CODE/PHP/sales_recording.php" class="modern-module-card module-reveal">
                        <div class="module-card-glow"></div>
                        <div class="module-card-icon">
                            <i class="bi bi-receipt-cutoff"></i>
                        </div>
                        <div class="module-card-content">
                            <h3>Sales</h3>
                            <p>Record transactions, process daily sales, and manage order and payment details quickly.</p>
                            <span class="module-card-link">Open Module <i class="bi bi-arrow-right"></i></span>
                        </div>
                    </a>
                <?php endif; ?>

                <?php if ($canSalesAnalytics): ?>
                    <a href="/NexGen/CODE/PHP/sales_analytics.php" class="modern-module-card module-reveal">
                        <div class="module-card-glow"></div>
                        <div class="module-card-icon">
                            <i class="bi bi-bar-chart-line-fill"></i>
                        </div>
                        <div class="module-card-content">
                            <h3>Sales Analytics</h3>
                            <p>View trends, monitor business performance, and analyze revenue insights with visual reports.</p>
                            <span class="module-card-link">Open Module <i class="bi bi-arrow-right"></i></span>
                        </div>
                    </a>
                <?php endif; ?>

                <?php if ($canAccountsReceivable): ?>
                <a href="<?php echo htmlspecialchars(nxAppUrl('accounts_receivable.php'), ENT_QUOTES, 'UTF-8'); ?>" class="modern-module-card module-reveal">
                    <div class="module-card-glow"></div>
                    <div class="module-card-icon">
                        <i class="bi bi-wallet2"></i>
                    </div>
                    <div class="module-card-content">
                        <h3>Accounts Receivable</h3>
                        <p>Monitor unpaid balances, overdue accounts, and customer receivable follow-ups.</p>
                        <span class="module-card-link">Open Module <i class="bi bi-arrow-right"></i></span>
                    </div>
                </a>
                <?php endif; ?>
            </div>
        </section>
    </section>

    <div class="why-section-toggle-wrap">
        <button type="button" class="why-section-toggle-btn" id="whySectionToggleBtn" aria-expanded="false" aria-controls="why-section">
            <span>Why NextGen?</span>
            <i class="bi bi-chevron-down" id="whySectionToggleIcon"></i>
        </button>
    </div>

    <section class="why-section" id="why-section">
        <div class="why-top-shape"></div>
        <div class="why-inner">
            <div class="why-header-line why-reveal why-reveal-up">
                <span class="why-line"></span>
                <h2>WHY US?</h2>
            </div>

            <div class="why-outline-text why-reveal why-reveal-up">WHY US</div>
            <div class="why-highlight-box why-reveal why-reveal-up">WHY NEXGEN FOR SMEs?</div>

            <div class="why-content">
                <div class="why-left why-reveal why-reveal-left">
                    <img src="/NexGen/IMAGES/whyus.jpg" alt="Why Us Image">
                </div>

                <div class="why-right why-reveal why-reveal-right">
                    <div class="why-icon-text">
                        <div class="why-description">
                            <p><strong>Next Gen</strong> is designed to provide Small and Medium Enterprises (SMEs) with a secure, efficient, and intelligent platform for managing inventory, sales transactions, and business operations.</p>
                            <p>We prioritize accuracy, usability, reliability, and real-time access to business information to help enterprises operate more effectively.</p>
                            <p class="why-closing">Choose NexGen — where smarter business management meets intelligent technology.</p>
                        </div>

                        <button type="button" class="why-toggle-btn" id="whyToggleBtn" aria-expanded="false" aria-controls="whyExtra">
                            <span>More About Us</span>
                            <i class="bi bi-chevron-down" id="whyToggleIcon"></i>
                        </button>

                        <div class="why-extra" id="whyExtra">
                            <p>Founded to support growing businesses, NexGen brings together inventory management, sales recording, sales analytics, accounts receivable monitoring, and an AI-powered chatbot assistant into one centralized web-based platform.</p>
                            <p>Our system reduces manual work, improves data accuracy, streamlines daily operations, and provides real-time business insights, allowing business owners to focus on making informed decisions and achieving sustainable growth.</p>
                        </div>
                    </div>

                    <a href="/NexGen/CODE/PHP/about_us.php" class="learn-btn">Learn More</a>
                </div>
            </div>
        </div>
    </section>

    <footer class="footer-section" id="footer-section">
        <div class="footer-wave-wrap" aria-hidden="true">
            <svg class="wave-back" viewBox="0 0 2400 100" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M0,40 C150,70 350,10 600,40 C850,70 1050,10 1200,40 C1350,70 1550,10 1800,40 C2050,70 2250,10 2400,40 L2400,100 L0,100 Z"></path>
            </svg>
            <svg class="wave-mid" viewBox="0 0 2400 100" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M0,30 C150,50 350,10 600,30 C850,50 1050,10 1200,30 C1350,50 1550,10 1800,30 C2050,50 2250,10 2400,30 L2400,100 L0,100 Z"></path>
            </svg>
            <svg class="wave-front" viewBox="0 0 2400 100" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M0,25 C150,38 350,12 600,25 C850,38 1050,12 1200,25 C1350,38 1550,12 1800,25 C2050,38 2250,12 2400,25 L2400,100 L0,100 Z"></path>
            </svg>
            <svg class="wave-crest" viewBox="0 0 2400 100" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M0,40 C150,70 350,10 600,40 C850,70 1050,10 1200,40 C1350,70 1550,10 1800,40 C2050,70 2250,10 2400,40"></path>
            </svg>
        </div>
        <div class="footer-top-line"></div>

        <div class="footer-logo">
            <img src="/NexGen/IMAGES/NGlogo.png" alt="NexGen Logo">
        </div>

        <div class="footer-social">
            <a href="https://www.facebook.com/profile.php?id=61587577520854" target="_blank" rel="noopener" aria-label="NexGen on Facebook"><i class="bi bi-facebook"></i></a>
            <a href="https://www.instagram.com/nexgen.enterprises26?igsh=MTB5bDNod3lubjhjdw==" target="_blank" rel="noopener" aria-label="NexGen on Instagram"><i class="bi bi-instagram"></i></a>
            <a href="https://x.com/NexGenX2026" target="_blank" rel="noopener" aria-label="NexGen on X"><i class="bi bi-twitter-x"></i></a>
            <a href="https://www.tiktok.com/@nexgen.enterprise4?_r=1&_t=ZS-98Vx2jSAL3q" target="_blank" rel="noopener" aria-label="NexGen on TikTok"><i class="bi bi-tiktok"></i></a>
        </div>

        <div class="footer-copy">
            &copy; 2026 NexGen. All rights reserved.
        </div>
    </footer>

</div>

<?php include __DIR__ . '/chatbot.php'; ?>
<script src="/NexGen/CODE/JS/header.js"></script>
<script src="/NexGen/CODE/JS/dashboard.js"></script>
</body>
</html>
