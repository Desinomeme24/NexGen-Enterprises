<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/resend_config.php';
require_once __DIR__ . '/otp_security.php';

date_default_timezone_set('Asia/Manila');

const NX_SETTINGS_OTP_TTL_SECONDS = 600;
const NX_SETTINGS_OTP_RESEND_COOLDOWN_SECONDS = 60;

function nxPasswordChangeRedirect(string $message, string $type = 'error'): void
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
    nxPasswordChangeRedirect('Invalid request.');
}

if (!validateCsrfToken('request_password_change_form', $_POST['csrf_token'] ?? '')) {
    nxPasswordChangeRedirect('Invalid or expired OTP request form token.');
}

$userId = (int)$_SESSION['user_id'];
$newPassword = trim((string)($_POST['new_password'] ?? ''));
$confirmNewPassword = trim((string)($_POST['confirm_new_password'] ?? ''));

if ($newPassword === '' || $confirmNewPassword === '') {
    nxPasswordChangeRedirect('Please complete all password fields.');
}

if ($newPassword !== $confirmNewPassword) {
    nxPasswordChangeRedirect('New passwords do not match.');
}

if (!isStrongPassword($newPassword)) {
    nxPasswordChangeRedirect(
        'Use 12 to 64 characters with uppercase, lowercase, number, and special character.'
    );
}

$stmt = $conn->prepare(
    "SELECT email, full_name, otp_expires_at
     FROM users
     WHERE id = ? AND account_status = 'active'
     LIMIT 1"
);

if (!$stmt) {
    error_log('Settings OTP user lookup prepare error: ' . $conn->error);
    nxPasswordChangeRedirect('Unable to process your request right now.');
}

$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    nxPasswordChangeRedirect('User account not found.');
}

if (!empty($user['otp_expires_at'])) {
    $expiryTimestamp = strtotime((string)$user['otp_expires_at']);
    if ($expiryTimestamp !== false) {
        $lastSentAt = $expiryTimestamp - NX_SETTINGS_OTP_TTL_SECONDS;
        $secondsSinceSend = time() - $lastSentAt;

        if ($secondsSinceSend < NX_SETTINGS_OTP_RESEND_COOLDOWN_SECONDS) {
            $wait = NX_SETTINGS_OTP_RESEND_COOLDOWN_SECONDS - $secondsSinceSend;
            nxPasswordChangeRedirect(
                "Please wait {$wait} second" . ($wait === 1 ? '' : 's') . ' before requesting another OTP.'
            );
        }
    }
}

try {
    $pendingPasswordHash = nxHashPassword($newPassword);
} catch (Throwable $e) {
    error_log('Settings password hashing failed: ' . $e->getMessage());
    nxPasswordChangeRedirect('Unable to secure the new password right now.');
}

$otpCode = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

try {
    $otpHash = nxHashOtp($otpCode);
} catch (Throwable $e) {
    error_log('Settings OTP hashing failed: ' . $e->getMessage());
    nxPasswordChangeRedirect('Unable to secure the OTP right now. Please try again.');
}

$otpExpiresAt = date('Y-m-d H:i:s', time() + NX_SETTINGS_OTP_TTL_SECONDS);
$recipientName = trim((string)($user['full_name'] ?? ''));
if ($recipientName === '') {
    $recipientName = 'NexGen User';
}

$emailBody =
    "Hello {$recipientName},\n\n" .
    "Your NexGen password change OTP is: {$otpCode}\n\n" .
    "This OTP will expire in 10 minutes.\n\n" .
    'If you did not request this password change, you can ignore this email.';

try {
    nxSendResendEmail(
        (string)$user['email'],
        $recipientName,
        'NexGen Password Change OTP',
        $emailBody
    );
} catch (Throwable $e) {
    error_log('Settings password-change Resend error for user ID ' . $userId . ': ' . $e->getMessage());
    nxPasswordChangeRedirect("We couldn't send the OTP right now. Please try again in a moment.");
}

$updateStmt = $conn->prepare(
    "UPDATE users
     SET otp_code = ?,
         otp_expires_at = ?,
         otp_preference = 'email'
     WHERE id = ?"
);

if (!$updateStmt) {
    error_log('Settings OTP update prepare error: ' . $conn->error);
    nxPasswordChangeRedirect(
        'The email was sent, but the password change request could not be saved. Please request a new OTP.'
    );
}

$updateStmt->bind_param('ssi', $otpHash, $otpExpiresAt, $userId);
$otpSaved = $updateStmt->execute();
if (!$otpSaved) {
    error_log('Settings OTP update error: ' . $updateStmt->error);
}
$updateStmt->close();

if (!$otpSaved) {
    nxPasswordChangeRedirect(
        'The email was sent, but the password change request could not be saved. Please request a new OTP.'
    );
}

$_SESSION['pending_new_password'] = $pendingPasswordHash;
unset($_SESSION['otp_new_password_plain'], $_SESSION['otp_confirm_password_plain']);
nxPasswordChangeRedirect('OTP sent to your email address.', 'success');
