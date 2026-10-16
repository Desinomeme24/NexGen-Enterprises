<?php
session_start();
include 'config.php';

date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['user_id'])) {
    header("Location: /NexGen/CODE/PHP/index.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    $_SESSION['error'] = "Invalid request.";
    header("Location: /NexGen/CODE/PHP/settings.php");
    exit();
}

/* SECURITY: CSRF validation */
if (!validateCsrfToken('change_password_direct_form', $_POST['csrf_token'] ?? '')) {
    $_SESSION['error'] = "Invalid or expired password form token.";
    header("Location: /NexGen/CODE/PHP/settings.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$current_password = trim($_POST['current_password'] ?? '');
$new_password = trim($_POST['new_password'] ?? '');
$confirm_new_password = trim($_POST['confirm_new_password'] ?? '');

if ($current_password === '' || $new_password === '' || $confirm_new_password === '') {
    $_SESSION['error'] = "Please complete all password fields.";
    header("Location: /NexGen/CODE/PHP/settings.php");
    exit();
}

if ($new_password !== $confirm_new_password) {
    $_SESSION['error'] = "New passwords do not match.";
    header("Location: /NexGen/CODE/PHP/settings.php");
    exit();
}

$sql = "SELECT password FROM users WHERE id = ? LIMIT 1";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    $_SESSION['error'] = "Database error: failed to prepare password lookup.";
    header("Location: /NexGen/CODE/PHP/settings.php");
    exit();
}

$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    $_SESSION['error'] = "User account not found.";
    header("Location: /NexGen/CODE/PHP/settings.php");
    exit();
}

if (!password_verify($current_password, $user['password'])) {
    $_SESSION['error'] = "Current password is incorrect.";
    header("Location: /NexGen/CODE/PHP/settings.php");
    exit();
}

$new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);

$update_sql = "UPDATE users SET password = ? WHERE id = ?";
$update_stmt = $conn->prepare($update_sql);

if (!$update_stmt) {
    $_SESSION['error'] = "Database error: failed to prepare password update.";
    header("Location: /NexGen/CODE/PHP/settings.php");
    exit();
}

$update_stmt->bind_param("si", $new_password_hash, $user_id);

if ($update_stmt->execute()) {
    $_SESSION['success'] = "Password changed successfully.";
} else {
    $_SESSION['error'] = "Failed to update password.";
}

$update_stmt->close();

header("Location: /NexGen/CODE/PHP/settings.php");
exit();
?>