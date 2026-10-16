<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/otp_security.php';

date_default_timezone_set('Asia/Manila');

function nxVerifyPasswordOtpRedirect(string $message, string $type = 'error'): void
{
    $_SESSION[$type] = $message;
    header('Location: ' . nxAppUrl('settings.php?panel=security-panel'));
    exit();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . nxLoginPathForRole((string)($_SESSION['role'] ?? '')));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    nxVerifyPasswordOtpRedirect('Invalid request.');
}

if (!validateCsrfToken('verify_password_otp_form', $_POST['csrf_token'] ?? '')) {
    nxVerifyPasswordOtpRedirect('Invalid or expired OTP verification form token.');
}

$userId = (int)$_SESSION['user_id'];
$otpCode = trim((string)($_POST['otp_code'] ?? ''));
$pendingPasswordHash = (string)($_SESSION['pending_new_password'] ?? '');

if (!preg_match('/^[0-9]{6}$/D', $otpCode)) {
    nxVerifyPasswordOtpRedirect('Please enter the complete 6-digit OTP.');
}

if ($pendingPasswordHash === '') {
    nxVerifyPasswordOtpRedirect('No pending password change found. Please request a new OTP.');
}

$stmt = $conn->prepare(
    "SELECT otp_code, otp_expires_at
     FROM users
     WHERE id = ? AND account_status = 'active'
     LIMIT 1"
);

if (!$stmt) {
    error_log('Settings OTP verification lookup prepare error: ' . $conn->error);
    nxVerifyPasswordOtpRedirect('Unable to process your request right now.');
}

$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user || empty($user['otp_code']) || empty($user['otp_expires_at'])) {
    nxVerifyPasswordOtpRedirect('No valid OTP found. Please request a new OTP.');
}

if (strtotime((string)$user['otp_expires_at']) < time()) {
    nxVerifyPasswordOtpRedirect('OTP has expired. Please request a new one.');
}

if (!nxVerifyOtp($otpCode, (string)$user['otp_code'])) {
    nxVerifyPasswordOtpRedirect('Invalid OTP code.');
}

$updateStmt = $conn->prepare(
    'UPDATE users
     SET password = ?, otp_code = NULL, otp_expires_at = NULL
     WHERE id = ?'
);

if (!$updateStmt) {
    error_log('Settings password update prepare error: ' . $conn->error);
    nxVerifyPasswordOtpRedirect('Failed to update password.');
}

$updateStmt->bind_param('si', $pendingPasswordHash, $userId);
$passwordUpdated = $updateStmt->execute();
if (!$passwordUpdated) {
    error_log('Settings password update error: ' . $updateStmt->error);
}
$updateStmt->close();

if (!$passwordUpdated) {
    nxVerifyPasswordOtpRedirect('Failed to update password.');
}

unset(
    $_SESSION['pending_new_password'],
    $_SESSION['otp_new_password_plain'],
    $_SESSION['otp_confirm_password_plain']
);

nxVerifyPasswordOtpRedirect('Password changed successfully.', 'success');
