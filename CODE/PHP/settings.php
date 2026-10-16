<?php
/* Load config before reading session state so this page uses the same hardened
   cookie settings and role-aware timeout routing as both login portals. */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/tenant_helper.php';

enforceSessionTimeout();

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . nxLoginPathForPortal((string)($_SESSION['login_portal'] ?? 'client')));
    exit();
}

$user_id = $_SESSION['user_id'];
$role = (string)($_SESSION['role'] ?? '');
$isWorkspaceUser = in_array($role, ['owner', 'employee'], true);
$isOwner = $role === 'owner';
$workspaceSchemaReady = nxWorkspaceSchemaReady($conn);
$workspaceRequestSchemaReady = nxWorkspaceRequestSchemaReady($conn);
$workspaces = $isWorkspaceUser ? nxGetAccessibleWorkspaces($conn, (int)$user_id, $role) : [];
$activeWorkspace = $isWorkspaceUser ? nxGetActiveWorkspace($conn) : [];
$pendingWorkspaceRequest = $isOwner && $workspaceRequestSchemaReady
    ? nxGetPendingWorkspaceRequest($conn, (int)$user_id)
    : null;
$ownerEntities = [];

if ($isOwner) {
    foreach ($workspaces as $workspace) {
        $entityId = (int)($workspace['business_entity_id'] ?? 0);
        if ($entityId > 0 && !isset($ownerEntities[$entityId])) {
            $ownerEntities[$entityId] = [
                'id' => $entityId,
                'business_name' => $workspace['business_name'],
                'business_type' => $workspace['business_type'],
                'business_code' => $workspace['business_code'],
            ];
        }
    }
}

$sql = "SELECT username, full_name, email, phone, address, profile_image, business_id FROM users WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

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

$profileImage = !empty($user['profile_image']) ? $user['profile_image'] : 'uploads/default.png';

$backLink = "/NexGen/CODE/PHP/dashboard.php";
if (isset($_SESSION['role']) && $_SESSION['role'] === 'system_admin') {
    $backLink = "/NexGen/CODE/PHP/admin_dashboard.php";
}

/* SECURITY: Generate CSRF tokens for settings forms */
$directPasswordCsrfToken = generateCsrfToken('change_password_direct_form');
$requestOtpCsrfToken = generateCsrfToken('request_password_change_form');
$verifyOtpCsrfToken = generateCsrfToken('verify_password_otp_form');
$workspaceCsrfToken = generateCsrfToken('workspace_action');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php include __DIR__ . '/nexgen_favicon/favicon.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - NextGen</title>
    <?php include("theme_init.php"); ?>
    <link rel="stylesheet" href="<?php echo e(nxCodeUrl('STYLE/settings.css')); ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        .workspace-summary,
        .workspace-form-card {
            border: 1px solid rgba(121, 151, 218, 0.28);
            border-radius: 16px;
            padding: 18px;
            margin-bottom: 18px;
            background: rgba(91, 126, 205, 0.08);
        }
        .workspace-summary h3,
        .workspace-form-card h3 { margin: 0 0 8px; }
        .workspace-code-row { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
        .workspace-code-chip {
            display: inline-flex;
            gap: 6px;
            align-items: center;
            padding: 7px 10px;
            border-radius: 999px;
            background: rgba(93, 128, 214, 0.16);
            font-size: 0.86rem;
        }
        .workspace-divider { height: 1px; background: rgba(121, 151, 218, 0.22); margin: 22px 0; }
        .workspace-rule-note { font-size: 0.9rem; line-height: 1.55; opacity: 0.82; }
        .workspace-check { display: flex; align-items: flex-start; gap: 10px; margin: 10px 0 16px; }
        .workspace-check input { width: auto; margin-top: 4px; }
        .workspace-request-pending {
            border-color: rgba(247, 200, 115, 0.5);
            background: rgba(247, 200, 115, 0.10);
        }
        .workspace-request-pending .workspace-code-chip {
            background: rgba(247, 200, 115, 0.16);
        }
        .workspace-request-status {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            margin-top: 10px;
            font-size: 0.88rem;
            font-weight: 700;
            color: #f7c873;
        }
        html[data-theme="light"] .workspace-request-status { color: #93610c; }
    </style>
</head>
<body class="settings-body">

<?php if (!empty($popupMessage)): ?>
    <div class="popup-overlay" id="popupOverlay">
        <div class="popup-box <?php echo $popupType; ?>" id="popupBox">
            <div class="popup-icon"><?php echo $popupType === 'success' ? '✓' : '!'; ?></div>
            <h3><?php echo $popupType === 'success' ? 'Success' : 'Error'; ?></h3>
            <p><?php echo htmlspecialchars($popupMessage); ?></p>
        </div>
    </div>
<?php endif; ?>

<div class="settings-page">

    <aside class="settings-sidebar">
        <div class="sidebar-profile-card">
            <div class="profile-section">
                <form action="<?php echo e(nxAppUrl('update_profile_image.php')); ?>" method="POST" enctype="multipart/form-data" class="profile-edit-form" id="profileImageForm">
                    <input type="hidden" name="csrf_token" value="<?php echo e(generateCsrfToken('profile_image_form')); ?>">
                    <div class="profile-image-wrapper">
                        <img src="<?php echo e(nxAppUrl($profileImage)); ?>" alt="Profile" class="sidebar-profile-img">
                        <label for="new_profile_image" class="profile-edit-btn"><i class="bi bi-camera-fill"></i></label>
                        <input type="file" name="new_profile_image" id="new_profile_image" accept="image/*" hidden>
                    </div>
                </form>

                <div class="sidebar-profile-info">
                    <h2><?php echo htmlspecialchars($user['username'] ?? 'No Username'); ?></h2>
                    <p class="profile-fullname"><?php echo htmlspecialchars($user['full_name'] ?? 'No Name'); ?></p>
                </div>
            </div>
        </div>

        <nav class="settings-nav">
            <button class="nav-item active" data-target="account-panel" type="button"><i class="bi bi-person"></i><span>Account</span></button>
            <?php if ($isWorkspaceUser): ?>
                <button class="nav-item" data-target="workspace-panel" type="button"><i class="bi bi-buildings"></i><span>Businesses &amp; Branches</span></button>
            <?php endif; ?>
            <button class="nav-item" data-target="personalization-panel" type="button"><i class="bi bi-palette"></i><span>Personalization</span></button>
            <button class="nav-item" data-target="security-panel" type="button"><i class="bi bi-shield-lock"></i><span>Security &amp; Access</span></button>
        </nav>

        <div class="settings-sidebar-footer">
            <div class="legal-links">
                <a href="/NexGen/CODE/PHP/privacy_policy.php" class="back-link">
                    <i class="bi bi-file-earmark-text"></i> Privacy Policy
                </a>
                <a href="/NexGen/CODE/PHP/privacy_policy.php#cookie-notice" class="back-link">
                    <i class="bi bi-cookie"></i> Cookie Notice
                </a>
            </div>
            <div class="back-link-wrap">
                <a href="<?php echo $backLink; ?>" class="back-link back-to-dashboard"><i class="bi bi-arrow-left"></i> Back to Dashboard</a>
            </div>
        </div>
    </aside>

    <main class="settings-content">
        <section class="settings-panel active-panel" id="account-panel">
            <div class="panel-header">
                <h1>Account Settings</h1>
                <p class="panel-subtitle">View your personal information and how it's displayed.</p>
            </div>

            <div class="account-form">
                <div class="form-group full">
                    <label for="accountFullName">Name</label>
                    <input type="text" id="accountFullName" value="<?php echo e($user['full_name'] ?? ''); ?>" readonly>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="accountEmail">Email</label>
                        <input type="email" id="accountEmail" value="<?php echo e($user['email'] ?? ''); ?>" readonly>
                    </div>

                    <div class="form-group">
                        <label for="accountPhone">Phone Number</label>
                        <input type="text" id="accountPhone" value="<?php echo e($user['phone'] ?? ''); ?>" readonly>
                    </div>
                </div>

                <div class="form-group full">
                    <label for="accountAddress">Address</label>
                    <textarea id="accountAddress" readonly><?php echo e($user['address'] ?? ''); ?></textarea>
                </div>

                <div class="form-group full">
                    <label>OTP Verification Method</label>
                    <div class="info-chip"><i class="bi bi-envelope-check"></i> Email only</div>
                </div>
            </div>
        </section>

        <?php if ($isWorkspaceUser): ?>
        <section class="settings-panel" id="workspace-panel">
            <div class="panel-header panel-header-accent">
                <h2>Businesses &amp; Branches</h2>
                <span class="panel-accent-bar"></span>
                <p class="panel-subtitle">Use one account to work in the correct business and branch.</p>
            </div>

            <?php if (!$workspaceSchemaReady): ?>
                <div class="workspace-summary">
                    <h3>Migration required</h3>
                    <p>Run the provided multi-business and branch SQL migration before using these controls.</p>
                </div>
            <?php elseif (empty($activeWorkspace)): ?>
                <div class="workspace-summary">
                    <h3>No active workspace</h3>
                    <p>Your account is not assigned to an active business branch. Contact the administrator.</p>
                </div>
            <?php else: ?>
                <div class="workspace-summary">
                    <h3><?php echo e($activeWorkspace['business_name']); ?> — <?php echo e($activeWorkspace['branch_name']); ?></h3>
                    <p><?php echo e($activeWorkspace['business_type']); ?><br><?php echo e($activeWorkspace['business_address']); ?></p>
                    <div class="workspace-code-row">
                        <span class="workspace-code-chip"><strong>Business:</strong> <?php echo e($activeWorkspace['business_code']); ?></span>
                        <span class="workspace-code-chip"><strong>Branch:</strong> <?php echo e($activeWorkspace['branch_code']); ?></span>
                    </div>
                </div>

                <?php if (count($workspaces) > 1): ?>
                    <form action="/NexGen/CODE/PHP/workspace_action.php" method="POST" class="account-form workspace-form-card" id="workspaceSwitchForm">
                        <input type="hidden" name="csrf_token" value="<?php echo e($workspaceCsrfToken); ?>">
                        <input type="hidden" name="action" value="switch_workspace">
                        <input type="hidden" name="return_to" value="settings.php">
                        <h3>Switch workspace</h3>
                        <div class="form-group full">
                            <label for="settingsWorkspaceSelect">Business and branch</label>
                            <select name="business_id" id="settingsWorkspaceSelect" required>
                                <?php foreach ($workspaces as $workspace): ?>
                                    <option
                                        value="<?php echo (int)$workspace['business_id']; ?>"
                                        title="<?php echo e($workspace['business_name'] . ' — ' . $workspace['branch_name'] . ' [' . $workspace['business_code'] . ' / ' . $workspace['branch_code'] . ']'); ?>"
                                        <?php echo (int)$workspace['business_id'] === (int)$activeWorkspace['business_id'] ? 'selected' : ''; ?>>
                                        <?php echo e($workspace['business_name'] . ' — ' . $workspace['branch_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-actions-left"><button type="submit" class="btn btn-save">Switch Workspace</button></div>
                    </form>
                <?php endif; ?>

                <?php if ($isOwner): ?>
                    <div class="workspace-divider"></div>
                    <?php if (!$workspaceRequestSchemaReady): ?>
                        <div class="workspace-summary">
                            <h3>Approval migration required</h3>
                            <p>Run the Multi-Tenancy v3 SQL migration before submitting a new business or branch request.</p>
                        </div>
                    <?php elseif ($pendingWorkspaceRequest): ?>
                        <div class="workspace-summary workspace-request-pending">
                            <h3><i class="bi bi-hourglass-split"></i> Request awaiting administrator review</h3>
                            <p>
                                You cannot submit another business or branch request until this request is approved or rejected.
                            </p>
                            <div class="workspace-code-row">
                                <span class="workspace-code-chip"><strong>Request:</strong> <?php echo e($pendingWorkspaceRequest['request_code']); ?></span>
                                <span class="workspace-code-chip"><strong>Type:</strong> <?php echo e($pendingWorkspaceRequest['request_type'] === 'business' ? 'New Business' : 'New Branch'); ?></span>
                                <span class="workspace-code-chip"><strong>Submitted:</strong> <?php echo e(date('M d, Y h:i A', strtotime($pendingWorkspaceRequest['created_at']))); ?></span>
                            </div>
                            <span class="workspace-request-status"><i class="bi bi-clock-history"></i> Pending</span>
                        </div>
                    <?php else: ?>
                    <form action="/NexGen/CODE/PHP/workspace_action.php" method="POST" class="account-form workspace-form-card" id="addBusinessForm">
                        <input type="hidden" name="csrf_token" value="<?php echo e($workspaceCsrfToken); ?>">
                        <input type="hidden" name="action" value="add_business">
                        <input type="hidden" name="return_to" value="settings.php">
                        <h3><i class="bi bi-plus-circle"></i> Request another business</h3>
                        <div class="form-group full">
                            <label for="newBusinessName">Business name</label>
                            <input type="text" name="business_name" id="newBusinessName" maxlength="150" required>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="newBusinessType">SME type</label>
                                <select name="business_type" id="newBusinessType" required>
                                    <option value="">Select business type</option>
                                    <option value="Hardware / Construction Supplies">Hardware / Construction Supplies</option>
                                    <option value="Mini Grocery / Sari-Sari Store">Mini Grocery / Sari-Sari Store</option>
                                    <option value="Pharmacy / Drugstore">Pharmacy / Drugstore</option>
                                    <option value="School / Office Supplies">School / Office Supplies</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="newMainBranchName">First branch name</label>
                                <input type="text" name="branch_name" id="newMainBranchName" value="Main Branch" maxlength="150" required>
                            </div>
                        </div>
                        <div class="form-group full">
                            <label for="newBusinessAddress">Business/branch address</label>
                            <textarea name="business_address" id="newBusinessAddress" required></textarea>
                        </div>
                        <label class="workspace-check">
                            <input type="checkbox" name="separate_operations" value="1">
                            <span>This workspace must use separate operations and accounting, even if its name and SME type match one of my existing businesses.</span>
                        </label>
                        <div class="form-actions-left"><button type="submit" class="btn btn-save">Submit Business Request</button></div>
                    </form>

                    <form action="/NexGen/CODE/PHP/workspace_action.php" method="POST" class="account-form workspace-form-card" id="addBranchForm">
                        <input type="hidden" name="csrf_token" value="<?php echo e($workspaceCsrfToken); ?>">
                        <input type="hidden" name="action" value="add_branch">
                        <input type="hidden" name="return_to" value="settings.php">
                        <h3><i class="bi bi-diagram-3"></i> Request a branch</h3>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="branchBusinessEntity">Existing business</label>
                                <select name="business_entity_id" id="branchBusinessEntity" required>
                                    <?php foreach ($ownerEntities as $entity): ?>
                                        <option value="<?php echo (int)$entity['id']; ?>">
                                            <?php echo e($entity['business_name'] . ' [' . $entity['business_code'] . ']'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="newBranchName">Branch name</label>
                                <input type="text" name="branch_name" id="newBranchName" maxlength="150" required>
                            </div>
                        </div>
                        <div class="form-group full">
                            <label for="newBranchAddress">Branch address</label>
                            <textarea name="branch_address" id="newBranchAddress" required></textarea>
                        </div>
                        <div class="form-actions-left"><button type="submit" class="btn btn-save">Submit Branch Request</button></div>
                    </form>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <section class="settings-panel" id="personalization-panel">
            <div class="panel-header panel-header-accent">
                <h2>Personalization</h2>
                <span class="panel-accent-bar"></span>
                <p class="panel-subtitle">Choose how NexGen looks.</p>
            </div>

            <div class="theme-card-group" id="themeChoiceGroup">
                <button type="button" class="theme-card" data-theme-choice="dark">
                    <div class="theme-preview-row">
                        <span class="theme-swatch theme-swatch-dark"></span>
                        <span class="theme-swatch theme-swatch-gold"></span>
                        <span class="theme-preview-strip theme-preview-strip-dark"></span>
                    </div>
                    <div class="theme-card-label"><i class="bi bi-moon-stars-fill"></i> Dark</div>
                </button>

                <button type="button" class="theme-card" data-theme-choice="light">
                    <div class="theme-preview-row">
                        <span class="theme-swatch theme-swatch-light"></span>
                        <span class="theme-swatch theme-swatch-gold"></span>
                        <span class="theme-preview-strip theme-preview-strip-light"></span>
                    </div>
                    <div class="theme-card-label"><i class="bi bi-sun-fill"></i> Light</div>
                </button>
            </div>
        </section>

        <section class="settings-panel" id="security-panel">
            <div class="panel-header">
                <h2>Security &amp; Access</h2>
                <p class="panel-subtitle">Choose how you'd like to change your password.</p>
            </div>

            <div class="password-box">

                <div class="segmented-control" id="securityChoiceGroup">
                    <button type="button" class="segmented-btn" data-method="current-password-method">
                        <i class="bi bi-key"></i> Use Current Password
                    </button>
                    <button type="button" class="segmented-btn" data-method="otp-method">
                        <i class="bi bi-envelope-check"></i> Send OTP to Email
                    </button>
                </div>

                <div class="security-method" id="current-password-method">
                    <div class="method-card">
                        <div class="method-card-header">
                            <div class="method-icon"><i class="bi bi-key-fill"></i></div>
                            <div>
                                <h3>Change with Current Password</h3>
                                <p>Verify it's you using your existing password.</p>
                            </div>
                        </div>

                        <form action="<?php echo e(nxAppUrl('change_password_direct.php')); ?>" method="POST" class="password-form" id="directPasswordForm">
                            <input type="hidden" name="csrf_token" value="<?php echo e($directPasswordCsrfToken); ?>">

                            <div class="form-group full">
                                <label>Current Password</label>
                                <input type="password" name="current_password" required>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>New Password</label>
                                    <input type="password" name="new_password" minlength="12" maxlength="64" required>
                                </div>

                                <div class="form-group">
                                    <label>Confirm New Password</label>
                                    <input type="password" name="confirm_new_password" minlength="12" maxlength="64" required>
                                </div>
                            </div>

                            <div class="form-actions-left">
                                <button type="submit" class="btn btn-save">Change Password</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="security-method" id="otp-method">
                    <div class="method-card">
                        <div class="method-card-header">
                            <div class="method-icon"><i class="bi bi-envelope-check-fill"></i></div>
                            <div>
                                <h3>Change via Email OTP</h3>
                                <p>We'll send a one-time code to verify it's you.</p>
                            </div>
                        </div>

                        <div class="step-flow">
                            <div>
                                <div class="step-label"><span class="step-badge">1</span> Request a code</div>

                                <form action="<?php echo e(nxAppUrl('request_password_change.php')); ?>" method="POST" class="password-form" id="otpRequestForm">
                                    <input type="hidden" name="csrf_token" value="<?php echo e($requestOtpCsrfToken); ?>">

                                    <div class="form-group full">
                                        <label>Email Verification</label>
                                        <div class="info-chip"><i class="bi bi-envelope"></i> <?php echo htmlspecialchars($user['email'] ?? ''); ?></div>
                                    </div>

                                    <div class="form-row">
                                        <div class="form-group">
                                            <label>New Password</label>
                                            <input type="password" name="new_password" id="otpNewPassword" minlength="12" maxlength="64" autocomplete="new-password" required>
                                        </div>

                                        <div class="form-group">
                                            <label>Confirm New Password</label>
                                            <input type="password" name="confirm_new_password" id="otpConfirmPassword" minlength="12" maxlength="64" autocomplete="new-password" required>
                                        </div>
                                    </div>

                                    <div class="form-actions-left">
                                        <button type="submit" class="btn btn-send-otp">Send OTP to Email</button>
                                    </div>
                                </form>
                            </div>

                            <div>
                                <div class="step-label"><span class="step-badge">2</span> Verify &amp; confirm</div>

                                <form action="<?php echo e(nxAppUrl('verify_password_otp.php')); ?>" method="POST" class="otp-form" id="otpVerifyForm">
                                    <input type="hidden" name="csrf_token" value="<?php echo e($verifyOtpCsrfToken); ?>">

                                    <div class="form-group full">
                                        <label>Enter OTP sent to your email</label>
                                        <input type="text" name="otp_code" minlength="6" maxlength="6" inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code" placeholder="Enter the 6-digit OTP" required>
                                    </div>

                                    <div class="form-actions-left">
                                        <button type="submit" class="btn btn-save">Verify OTP &amp; Change Password</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </section>
    </main>
</div>

<script src="/NexGen/CODE/JS/settings.js"></script>
</body>
</html>
