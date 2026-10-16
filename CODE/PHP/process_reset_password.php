<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/otp_security.php';

$fpPortal = nxNormalizeLoginPortal(
    $_POST['portal']
        ?? $_GET['portal']
        ?? $_SESSION['fp_portal']
        ?? $_SESSION['login_portal']
        ?? 'client'
);
$_SESSION['fp_portal'] = $fpPortal;
$forgotStartPage = 'forgot_password.php?portal=' . rawurlencode($fpPortal);
$successLoginPage = $fpPortal === 'admin' ? 'admin_login.php' : 'index.php';

date_default_timezone_set('Asia/Manila');

/* AJAX callers (the in-modal forgot-password wizard on index.php) send this
   header and get a JSON reply instead of the classic flash+redirect. */
$isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

function prpRespond(bool $success, string $message, string $type, string $page, array $ajaxExtra = []): void
{
    global $isAjax;

    if ($isAjax) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(array_merge(['success' => $success, 'message' => $message], $ajaxExtra));
        exit();
    }

    $_SESSION[$type] = $message;
    header('Location: ' . nxAppUrl($page));
    exit();
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    prpRespond(false, "Invalid request.", 'error', $forgotStartPage, ['restart' => true]);
}

$userId = isset($_SESSION['fp_selected_user_id']) ? (int) $_SESSION['fp_selected_user_id'] : 0;
$otp_code = trim($_POST['otp_code'] ?? '');
$new_password = trim($_POST['new_password'] ?? '');
$confirm_new_password = trim($_POST['confirm_new_password'] ?? '');

$verifiedAt = (int) ($_SESSION['fp_verified_at'] ?? 0);
$verifiedIds = $_SESSION['fp_verified_candidate_ids'] ?? [];
$emailVerified = $verifiedAt > 0
    && time() - $verifiedAt <= 600
    && ($_SESSION['fp_verified_portal'] ?? '') === $fpPortal
    && in_array($userId, $verifiedIds, true);

if ($userId <= 0) {
    prpRespond(false, 'The OTP is invalid or expired. Please start again.', 'error', 'reset_password.php');
}

$stmt = $conn->prepare(
    "SELECT id, role, otp_code, otp_expires_at
     FROM users
     WHERE id = ? AND account_status = 'active'
     LIMIT 1"
);
if (!$stmt) {
    error_log('Password reset account lookup prepare failed: ' . $conn->error);
    prpRespond(false, "Unable to process your request right now.", 'error', $forgotStartPage, ['restart' => true]);
}
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user || !nxRoleMatchesLoginPortal((string)($user['role'] ?? ''), $fpPortal)) {
    prpRespond(false, 'The OTP is invalid or expired. Please start again.', 'error', 'reset_password.php');
}

if (!$emailVerified && !preg_match('/^[0-9]{6}$/D', $otp_code)) {
    prpRespond(false, 'The OTP is invalid or expired. Please start again.', 'error', 'reset_password.php');
}

if (!$emailVerified) {
    if (
        empty($user['otp_code']) ||
        empty($user['otp_expires_at']) ||
        strtotime((string) $user['otp_expires_at']) < time()
    ) {
        prpRespond(false, 'The OTP is invalid or expired. Please start again.', 'error', 'reset_password.php');
    }

    $otpAccountRateLimit = nxConsumeSecurityRateLimit(
        $conn,
        'password_reset_otp_account',
        (string) $userId,
        5,
        600,
        600
    );
    $otpIpRateLimit = nxConsumeSecurityRateLimit(
        $conn,
        'password_reset_otp_ip',
        (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
        20,
        600,
        600
    );

    if (!$otpAccountRateLimit['configured'] || !$otpIpRateLimit['configured']) {
        error_log('Password reset OTP rate limiting is not configured.');
        prpRespond(false, 'The OTP is invalid or expired. Please start again.', 'error', 'reset_password.php');
    }

    if (!$otpAccountRateLimit['allowed'] || !$otpIpRateLimit['allowed']) {
        $invalidateStmt = $conn->prepare(
            'UPDATE users SET otp_code = NULL, otp_expires_at = NULL WHERE id = ?'
        );
        if (!$invalidateStmt) {
            error_log('Password reset OTP invalidation prepare failed: ' . $conn->error);
        } else {
            $invalidateStmt->bind_param('i', $userId);
            $invalidateStmt->execute();
            $invalidateStmt->close();
        }

        prpRespond(false, 'The OTP is invalid or expired. Please start again.', 'error', 'reset_password.php');
    }

    if (!nxVerifyOtp($otp_code, (string) $user['otp_code'])) {
        prpRespond(false, 'The OTP is invalid or expired. Please start again.', 'error', 'reset_password.php');
    }

    $clearOtpStmt = $conn->prepare(
        'UPDATE users SET otp_code = NULL, otp_expires_at = NULL WHERE id = ?'
    );
    if (!$clearOtpStmt) {
        error_log('Password reset OTP consumption prepare failed: ' . $conn->error);
        prpRespond(false, 'Unable to verify the OTP right now.', 'error', 'reset_password.php');
    }
    $clearOtpStmt->bind_param('i', $userId);
    $otpConsumed = $clearOtpStmt->execute();
    if (!$otpConsumed) {
        error_log('Password reset OTP consumption failed: ' . $clearOtpStmt->error);
    }
    $clearOtpStmt->close();

    if (!$otpConsumed) {
        prpRespond(false, 'Unable to verify the OTP right now.', 'error', 'reset_password.php');
    }

    $verifiedCandidateIds = array_map('intval', array_keys($_SESSION['fp_candidates'] ?? []));
    if (!$verifiedCandidateIds) {
        $verifiedCandidateIds = [$userId];
    }
    if (!in_array($userId, $verifiedCandidateIds, true)) {
        prpRespond(false, 'The OTP is invalid or expired. Please start again.', 'error', $forgotStartPage, ['restart' => true]);
    }

    $_SESSION['fp_verified_candidate_ids'] = $verifiedCandidateIds;
    $_SESSION['fp_verified_portal'] = $fpPortal;
    $_SESSION['fp_verified_at'] = time();
    $verifiedIds = $verifiedCandidateIds;
    $emailVerified = true;
}

if ($emailVerified && count($verifiedIds) > 1 && !empty($_SESSION['fp_candidates'])) {
    prpRespond(
        true,
        'Email ownership verified. Choose the account to reset.',
        'success',
        'forgot_password_select.php',
        [
            'selection_required' => true,
            'candidates' => array_values($_SESSION['fp_candidates']),
        ]
    );
}

if ($new_password === '' || $confirm_new_password === '') {
    prpRespond(false, 'Please complete all fields.', 'error', 'reset_password.php');
}

if ($new_password !== $confirm_new_password) {
    prpRespond(false, 'Passwords do not match.', 'error', 'reset_password.php');
}

if (!isStrongPassword($new_password)) {
    prpRespond(
        false,
        'Use 12 to 64 characters with uppercase, lowercase, number, and special character.',
        'error',
        'reset_password.php'
    );
}

try {
    $new_password_hash = nxHashPassword($new_password);
} catch (Throwable $e) {
    error_log('Password reset hashing failed: ' . $e->getMessage());
    prpRespond(false, "Unable to secure the new password right now.", 'error', 'reset_password.php');
}

$update_sql = "UPDATE users SET password = ?, otp_code = NULL, otp_expires_at = NULL WHERE id = ?";
$update_stmt = $conn->prepare($update_sql);
if (!$update_stmt) {
    error_log('Password reset update prepare failed: ' . $conn->error);
    prpRespond(false, "Failed to reset password.", 'error', 'reset_password.php');
}
$update_stmt->bind_param("si", $new_password_hash, $userId);
$passwordUpdated = $update_stmt->execute();
$update_stmt->close();

if ($passwordUpdated) {
    $_SESSION['login_portal'] = $fpPortal;
    unset(
        $_SESSION['fp_selected_user_id'],
        $_SESSION['fp_candidates'],
        $_SESSION['fp_email'],
        $_SESSION['fp_portal'],
        $_SESSION['fp_no_match'],
        $_SESSION['fp_verified_candidate_ids'],
        $_SESSION['fp_verified_portal'],
        $_SESSION['fp_verified_at']
    );
    prpRespond(true, "Password reset successful. You may now log in.", 'success', $successLoginPage);
} else {
    prpRespond(false, "Failed to reset password.", 'error', 'reset_password.php');
}
?>
