<?php
require_once __DIR__ . '/config.php';

enforceSessionTimeout();

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . nxLoginPathForPortal((string)($_SESSION['login_portal'] ?? 'client')));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = 'Invalid request.';
    header('Location: ' . nxAppUrl('settings.php'));
    exit();
}

if (!validateCsrfToken('update_account_form', $_POST['csrf_token'] ?? '')) {
    $_SESSION['error'] = 'Invalid or expired account form token.';
    header('Location: ' . nxAppUrl('settings.php'));
    exit();
}

$_SESSION['error'] = 'Personal information is view-only in Account Settings.';
header('Location: ' . nxAppUrl('settings.php'));
exit();