<?php
/* Load config first so this recovery endpoint uses the same hardened session
   cookie settings as both login portals. */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/resend_config.php';
require_once __DIR__ . '/otp_security.php';

$_SESSION['login_portal'] = 'admin';

if (
    !isset($_SESSION['lockout_user_id'], $_SESSION['lockout_username'], $_SESSION['lockout_role']) ||
    $_SESSION['lockout_role'] !== 'system_admin'
) {
    $_SESSION['error'] = "No locked admin account found for OTP unlock.";
    header('Location: ' . NEXGEN_ADMIN_LOGIN_PATH);
    exit();
}

$adminId = (int)$_SESSION['lockout_user_id'];
$adminUsername = (string)$_SESSION['lockout_username'];

$stmt = $conn->prepare("SELECT id, username, full_name, email, role, locked_until, otp_code, otp_expires_at FROM users WHERE id = ? AND role = 'system_admin' LIMIT 1");
if (!$stmt) {
    $_SESSION['error'] = "Database error while checking locked admin account.";
    header('Location: ' . NEXGEN_ADMIN_LOGIN_PATH);
    exit();
}

$stmt->bind_param("i", $adminId);
$stmt->execute();
$result = $stmt->get_result();
$admin = $result->fetch_assoc();
$stmt->close();

if (!$admin) {
    $_SESSION['error'] = "Admin account not found.";
    header('Location: ' . NEXGEN_ADMIN_LOGIN_PATH);
    exit();
}

if (empty($admin['locked_until']) || strtotime((string)$admin['locked_until']) <= time()) {
    $_SESSION['success'] = "The admin account is no longer locked. You may log in again.";
    unset(
        $_SESSION['lockout_until_ts'],
        $_SESSION['lockout_username'],
        $_SESSION['lockout_user_id'],
        $_SESSION['lockout_role']
    );
    header('Location: ' . NEXGEN_ADMIN_LOGIN_PATH);
    exit();
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

$csrfAdminUnlock = generateCsrfToken('admin_unlock_otp');

if (!isset($_SESSION['admin_unlock_otp_sent']) || $_SESSION['admin_unlock_otp_sent'] !== $adminId) {
    $otp = (string) random_int(100000, 999999);
    $expiresAt = date('Y-m-d H:i:s', strtotime('+5 minutes'));

    try {
        $otpHash = nxHashOtp($otp);
    } catch (Throwable $e) {
        error_log('NexGen administrator unlock OTP hashing failed: ' . $e->getMessage());
        $_SESSION['error'] = "Unable to secure the administrator unlock code right now.";
        header('Location: ' . NEXGEN_ADMIN_LOGIN_PATH);
        exit();
    }

    $otpStmt = $conn->prepare("UPDATE users SET otp_code = ?, otp_expires_at = ? WHERE id = ? AND role = 'system_admin'");
    if (!$otpStmt) {
        $_SESSION['error'] = "Unable to prepare the administrator unlock code right now.";
        header('Location: ' . NEXGEN_ADMIN_LOGIN_PATH);
        exit();
    }

    $otpStmt->bind_param("ssi", $otpHash, $expiresAt, $adminId);
    $otpStored = $otpStmt->execute();
    $otpStmt->close();

    if (!$otpStored) {
        $_SESSION['error'] = "Unable to prepare the administrator unlock code right now.";
        header('Location: ' . NEXGEN_ADMIN_LOGIN_PATH);
        exit();
    }

    try {
        nxSendResendEmail(
            (string)$admin['email'],
            (string)($admin['full_name'] ?: $admin['username']),
            'NextGen Admin Unlock OTP',
            "Your NextGen admin unlock OTP is: {$otp}\n\nThis code will expire in 5 minutes.\n\nUse this OTP to unlock your locked admin account."
        );

        $_SESSION['admin_unlock_otp_sent'] = $adminId;
        $_SESSION['success'] = "A 6-digit OTP has been sent to the admin email.";
        header("Location: /NexGen/CODE/PHP/admin_unlock_otp.php");
        exit();
    } catch (Throwable $e) {
        error_log('NexGen administrator unlock email failed: ' . $e->getMessage());
        $_SESSION['error'] = "Failed to send admin unlock OTP email.";
        header('Location: ' . NEXGEN_ADMIN_LOGIN_PATH);
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken('admin_unlock_otp', $_POST['csrf_token'] ?? null)) {
        $_SESSION['error'] = "Your session expired. Please try again.";
        header("Location: /NexGen/CODE/PHP/admin_unlock_otp.php");
        exit();
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'verify_unlock_otp') {
        $otpInput = trim($_POST['otp_code'] ?? '');

        if (!preg_match('/^[0-9]{6}$/D', $otpInput)) {
            $_SESSION['error'] = "Please enter the complete 6-digit OTP.";
            header("Location: /NexGen/CODE/PHP/admin_unlock_otp.php");
            exit();
        }

        $checkStmt = $conn->prepare("SELECT otp_code, otp_expires_at FROM users WHERE id = ? AND role = 'system_admin' LIMIT 1");
        if (!$checkStmt) {
            $_SESSION['error'] = "Database error while verifying OTP.";
            header("Location: /NexGen/CODE/PHP/admin_unlock_otp.php");
            exit();
        }

        $checkStmt->bind_param("i", $adminId);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        $otpData = $checkResult->fetch_assoc();
        $checkStmt->close();

        if (!$otpData) {
            $_SESSION['error'] = "Admin OTP data not found.";
            header("Location: /NexGen/CODE/PHP/admin_unlock_otp.php");
            exit();
        }

        $storedOtp = (string)($otpData['otp_code'] ?? '');
        $expiresAt = (string)($otpData['otp_expires_at'] ?? '');

        if ($storedOtp === '' || $expiresAt === '') {
            $_SESSION['error'] = "No valid OTP found. Please resend OTP.";
            header("Location: /NexGen/CODE/PHP/admin_unlock_otp.php");
            exit();
        }

        if (strtotime($expiresAt) < time()) {
            $_SESSION['error'] = "The OTP has expired. Please resend OTP.";
            header("Location: /NexGen/CODE/PHP/admin_unlock_otp.php");
            exit();
        }

        if (!nxVerifyOtp($otpInput, $storedOtp)) {
            $_SESSION['error'] = "Invalid OTP code.";
            header("Location: /NexGen/CODE/PHP/admin_unlock_otp.php");
            exit();
        }

        $unlockStmt = $conn->prepare("
            UPDATE users
            SET failed_login_attempts = 0,
                locked_until = NULL,
                last_failed_login_at = NULL,
                otp_code = NULL,
                otp_expires_at = NULL
            WHERE id = ?
              AND role = 'system_admin'
        ");
        if (!$unlockStmt) {
            $_SESSION['error'] = "Unable to unlock the administrator account right now.";
            header("Location: /NexGen/CODE/PHP/admin_unlock_otp.php");
            exit();
        }

        $unlockStmt->bind_param("i", $adminId);
        $unlockSucceeded = $unlockStmt->execute() && $unlockStmt->affected_rows === 1;
        $unlockStmt->close();

        if (!$unlockSucceeded) {
            $_SESSION['error'] = "Unable to unlock the administrator account right now.";
            header("Location: /NexGen/CODE/PHP/admin_unlock_otp.php");
            exit();
        }

        session_regenerate_id(true);
        unset(
            $_SESSION['lockout_until_ts'],
            $_SESSION['lockout_username'],
            $_SESSION['lockout_user_id'],
            $_SESSION['lockout_role'],
            $_SESSION['admin_unlock_otp_sent']
        );

        $_SESSION['success'] = "Admin account unlocked successfully. You may now log in again.";
        header('Location: ' . NEXGEN_ADMIN_LOGIN_PATH);
        exit();
    }

    if ($action === 'resend_unlock_otp') {
        $otp = (string) random_int(100000, 999999);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+5 minutes'));

        try {
            $otpHash = nxHashOtp($otp);
        } catch (Throwable $e) {
            error_log('NexGen administrator unlock OTP rehash failed: ' . $e->getMessage());
            $_SESSION['error'] = "Unable to secure a new administrator unlock code right now.";
            header("Location: /NexGen/CODE/PHP/admin_unlock_otp.php");
            exit();
        }

        $otpStmt = $conn->prepare("UPDATE users SET otp_code = ?, otp_expires_at = ? WHERE id = ? AND role = 'system_admin'");
        if (!$otpStmt) {
            $_SESSION['error'] = "Unable to prepare a new administrator unlock code right now.";
            header("Location: /NexGen/CODE/PHP/admin_unlock_otp.php");
            exit();
        }

        $otpStmt->bind_param("ssi", $otpHash, $expiresAt, $adminId);
        $otpStored = $otpStmt->execute();
        $otpStmt->close();

        if (!$otpStored) {
            $_SESSION['error'] = "Unable to prepare a new administrator unlock code right now.";
            header("Location: /NexGen/CODE/PHP/admin_unlock_otp.php");
            exit();
        }

        try {
            nxSendResendEmail(
                (string)$admin['email'],
                (string)($admin['full_name'] ?: $admin['username']),
                'NextGen Admin Unlock OTP (Resent)',
                "Your new NextGen admin unlock OTP is: {$otp}\n\nThis code will expire in 5 minutes."
            );

            $_SESSION['success'] = "A new OTP has been sent to the admin email.";
            header("Location: /NexGen/CODE/PHP/admin_unlock_otp.php");
            exit();
        } catch (Throwable $e) {
            error_log('NexGen administrator unlock resend failed: ' . $e->getMessage());
            $_SESSION['error'] = "Failed to resend OTP.";
            header("Location: /NexGen/CODE/PHP/admin_unlock_otp.php");
            exit();
        }
    }

    if ($action === 'cancel_unlock') {
        unset($_SESSION['admin_unlock_otp_sent']);
        header('Location: ' . NEXGEN_ADMIN_LOGIN_PATH);
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php include __DIR__ . '/nexgen_favicon/favicon.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Unlock OTP - NextGen</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: Arial, sans-serif;
            background: linear-gradient(180deg, #13357f 0%, #0b2257 100%);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .otp-card {
            width: 100%;
            max-width: 460px;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 24px;
            padding: 30px 24px;
            box-shadow: 0 24px 50px rgba(0,0,0,0.30);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
        }

        .otp-logo {
            width: 74px;
            display: block;
            margin: 0 auto 14px;
        }

        .otp-card h1 {
            margin: 0 0 10px;
            text-align: center;
            font-size: 28px;
            font-weight: 900;
        }

        .otp-card p {
            margin: 0 0 18px;
            text-align: center;
            color: rgba(255,255,255,0.86);
            line-height: 1.6;
            font-size: 14px;
        }

        .otp-card label {
            display: block;
            margin-bottom: 8px;
            font-weight: 700;
            font-size: 14px;
        }

        .otp-card input {
            width: 100%;
            padding: 14px 16px;
            border-radius: 14px;
            border: 1px solid rgba(255,255,255,0.14);
            background: rgba(255,255,255,0.08);
            color: #fff;
            outline: none;
            font-size: 16px;
            letter-spacing: 3px;
            text-align: center;
            margin-bottom: 16px;
        }

        .otp-card input::placeholder {
            color: rgba(255,255,255,0.55);
            letter-spacing: normal;
        }

        .btn-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 8px;
        }

        .main-btn,
        .sub-btn {
            flex: 1;
            min-width: 120px;
            border: none;
            border-radius: 14px;
            padding: 13px 16px;
            font-weight: 800;
            cursor: pointer;
            transition: 0.2s ease;
            font-size: 14px;
        }

        .main-btn {
            background: #f7d98b;
            color: #17306b;
        }

        .main-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 20px rgba(247,217,139,0.24);
        }

        .sub-btn {
            background: rgba(255,255,255,0.14);
            color: #fff;
        }

        .sub-btn:hover {
            background: rgba(255,255,255,0.22);
        }

        .popup-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.50);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 99999;
            padding: 20px;
        }

        .popup-box {
            width: 100%;
            max-width: 360px;
            background: linear-gradient(180deg, #264ba6 0%, #1c3f99 100%);
            border: 1px solid rgba(255,255,255,0.10);
            border-radius: 22px;
            padding: 26px 22px;
            text-align: center;
            box-shadow: 0 22px 50px rgba(0,0,0,0.30);
        }

        .popup-icon {
            width: 58px;
            height: 58px;
            border-radius: 50%;
            margin: 0 auto 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255,255,255,0.12);
            color: #f7d98b;
            font-size: 24px;
            font-weight: 900;
        }

        .popup-box h3 {
            margin: 0 0 8px;
            font-size: 24px;
        }

        .popup-box p {
            margin: 0;
            text-align: center;
        }
    </style>
</head>
<body>

<?php if (!empty($popupMessage)): ?>
    <div class="popup-overlay" id="popupOverlay">
        <div class="popup-box" id="popupBox">
            <div class="popup-icon"><?php echo $popupType === 'success' ? '✓' : '!'; ?></div>
            <h3><?php echo $popupType === 'success' ? 'Success' : 'Error'; ?></h3>
            <p><?php echo htmlspecialchars($popupMessage); ?></p>
        </div>
    </div>
<?php endif; ?>

<div class="otp-card">
    <img src="/NexGen/IMAGES/NGlogo.png" alt="Logo" class="otp-logo">

    <h1>Admin Unlock OTP</h1>
    <p>
        The admin account <strong><?php echo htmlspecialchars($adminUsername); ?></strong> is currently locked.<br>
        Enter the 6-digit OTP sent to the admin email to unlock this account.
    </p>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfAdminUnlock); ?>">
        <input type="hidden" name="action" value="verify_unlock_otp">
        <label>6-Digit OTP</label>
        <input type="text" name="otp_code" maxlength="6" placeholder="Enter OTP" required>
        <div class="btn-row">
            <button type="submit" class="main-btn">Verify OTP</button>
        </div>
    </form>

    <div class="btn-row">
        <form method="POST" style="flex:1;">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfAdminUnlock); ?>">
            <input type="hidden" name="action" value="resend_unlock_otp">
            <button type="submit" class="sub-btn" style="width:100%;">Resend OTP</button>
        </form>

        <form method="POST" style="flex:1;">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfAdminUnlock); ?>">
            <input type="hidden" name="action" value="cancel_unlock">
            <button type="submit" class="sub-btn" style="width:100%;">Back</button>
        </form>
    </div>
</div>

<script>
const popupOverlay = document.getElementById("popupOverlay");
if (popupOverlay) {
    setTimeout(() => {
        popupOverlay.remove();
    }, 4000);
}
</script>
</body>
</html>
