<?php
require_once __DIR__ . '/config.php';

enforceSessionTimeout();

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . nxLoginPathForPortal((string)($_SESSION['login_portal'] ?? 'client')));
    exit();
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    $_SESSION['error'] = "Invalid request.";
    header('Location: ' . nxAppUrl('dashboard.php'));
    exit();
}

if (!validateCsrfToken('profile_image_form', $_POST['csrf_token'] ?? '')) {
    $_SESSION['error'] = 'Invalid or expired profile image form token.';
    header('Location: ' . nxAppUrl('dashboard.php'));
    exit();
}

$user_id = (int)$_SESSION['user_id'];
if (isset($_POST['remove_profile_image'])) {
    $getOldStmt = $conn->prepare('SELECT profile_image FROM users WHERE id = ? LIMIT 1');
    $getOldStmt->bind_param('i', $user_id);
    $getOldStmt->execute();
    $oldPath = (string)($getOldStmt->get_result()->fetch_assoc()['profile_image'] ?? '');
    $getOldStmt->close();

    $removeStmt = $conn->prepare('UPDATE users SET profile_image = ? WHERE id = ?');
    $defaultImage = 'IMAGES/default-avatar.png';
    $removeStmt->bind_param('si', $defaultImage, $user_id);
    if ($removeStmt->execute()) {
        $_SESSION['profile_image'] = $defaultImage;
        $_SESSION['success'] = 'Profile picture removed.';
        if (str_starts_with($oldPath, 'uploads/profile_')) {
            $oldFile = rtrim(nxPublicUploadDirectory(), "\\/")
                . DIRECTORY_SEPARATOR . basename($oldPath);
            if (is_file($oldFile)) {
                @unlink($oldFile);
            }
        }
    } else {
        $_SESSION['error'] = 'Failed to remove profile picture.';
    }
    $removeStmt->close();
    header('Location: ' . nxAppUrl('dashboard.php'));
    exit();
}

if (!isset($_FILES['new_profile_image']) || $_FILES['new_profile_image']['error'] !== 0) {
    $_SESSION['error'] = "Please select an image first.";
    header('Location: ' . nxAppUrl('dashboard.php'));
    exit();
}

$profileFile = $_FILES['new_profile_image'];

[$isValidUpload, $scanResult] = nxValidateSecureUpload($profileFile, [
    'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
    'allowed_mime_types' => [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp'
    ],
    'max_size' => 5 * 1024 * 1024,
    'require_image' => true,
    'allow_pdf' => false
]);

if (!$isValidUpload) {
    $_SESSION['error'] = "Profile image upload blocked: " . $scanResult;
    header('Location: ' . nxAppUrl('dashboard.php'));
    exit();
}

$file_ext = strtolower(pathinfo($profileFile['name'], PATHINFO_EXTENSION));
$target_dir = rtrim(nxPublicUploadDirectory(), "\\/") . DIRECTORY_SEPARATOR;

if (!is_dir($target_dir)) {
    if (!mkdir($target_dir, 0750, true) && !is_dir($target_dir)) {
        $_SESSION['error'] = 'The profile image directory is unavailable.';
        header('Location: ' . nxAppUrl('dashboard.php'));
        exit();
    }
}

$new_file_name = 'profile_' . bin2hex(random_bytes(12)) . '.' . $file_ext;
$target_file = $target_dir . $new_file_name;
$db_file_path = "uploads/" . $new_file_name;

if (!move_uploaded_file($profileFile['tmp_name'], $target_file)) {
    $_SESSION['error'] = "Failed to upload image.";
    header('Location: ' . nxAppUrl('dashboard.php'));
    exit();
}
@chmod($target_file, 0640);

$getOldSql = "SELECT profile_image FROM users WHERE id = ? LIMIT 1";
$getOldStmt = $conn->prepare($getOldSql);
$getOldStmt->bind_param("i", $user_id);
$getOldStmt->execute();
$getOldResult = $getOldStmt->get_result();
$oldUser = $getOldResult->fetch_assoc();
$getOldStmt->close();

$sql = "UPDATE users SET profile_image = ? WHERE id = ?";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    @unlink($target_file);
    $_SESSION['error'] = "Prepare failed: " . $conn->error;
    header('Location: ' . nxAppUrl('dashboard.php'));
    exit();
}

$stmt->bind_param("si", $db_file_path, $user_id);

if ($stmt->execute()) {
    $_SESSION['profile_image'] = $db_file_path;
    $_SESSION['success'] = "Profile picture updated successfully.";

    $oldPath = $oldUser['profile_image'] ?? '';
    if (str_starts_with((string)$oldPath, 'uploads/profile_')) {
        $oldFullPath = rtrim(nxPublicUploadDirectory(), "\\/")
            . DIRECTORY_SEPARATOR . basename($oldPath);
        if (is_file($oldFullPath)) {
            @unlink($oldFullPath);
        }
    }
} else {
    @unlink($target_file);
    $_SESSION['error'] = "Failed to update profile picture.";
}

$stmt->close();
header('Location: ' . nxAppUrl('dashboard.php'));
exit();
?>