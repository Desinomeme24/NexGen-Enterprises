<?php
require_once __DIR__ . '/config.php';

$fpPortal = nxNormalizeLoginPortal(
    $_POST['portal']
        ?? $_GET['portal']
        ?? $_SESSION['fp_portal']
        ?? $_SESSION['login_portal']
        ?? 'client'
);
$_SESSION['fp_portal'] = $fpPortal;

date_default_timezone_set('Asia/Manila');

/* AJAX callers (the in-modal forgot-password wizard on index.php) send this
   header and get a JSON reply instead of the classic flash+redirect, so the
   same lookup logic can drive both the standalone pages and the modal. */
$isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

function fpLookupRedirect(
    string $message,
    string $type = 'error',
    string $page = 'forgot_password.php',
    array $ajaxExtra = []
): void {
    global $isAjax;

    if ($isAjax) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(array_merge(
            ['success' => $type !== 'error', 'message' => $message],
            $ajaxExtra
        ));
        exit();
    }

    $_SESSION[$type] = $message;
    header('Location: ' . nxAppUrl($page));
    exit();
}

/*
|--------------------------------------------------------------------------
| VALIDATE REQUEST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fpLookupRedirect('Invalid request.');
}

$email = trim($_POST['email'] ?? '');

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fpLookupRedirect('Please enter a valid email address.');
}

/* Start each lookup clean - do not carry over a previous attempt's account. */
unset(
    $_SESSION['fp_candidates'],
    $_SESSION['fp_selected_user_id'],
    $_SESSION['fp_verified_candidate_ids'],
    $_SESSION['fp_verified_portal'],
    $_SESSION['fp_verified_at'],
    $_SESSION['fp_email'],
    $_SESSION['fp_no_match']
);

/*
|--------------------------------------------------------------------------
| FIND ALL ACCOUNTS UNDER THIS EMAIL
|--------------------------------------------------------------------------
| An SME owner can have several branch accounts registered under one
| Gmail, so this can legitimately match more than one row.
*/

$portalRoleFilter = $fpPortal === 'admin'
    ? "u.role = 'system_admin'"
    : "u.role IN ('owner', 'employee')";

$stmt = $conn->prepare(
    "SELECT u.id, u.username, b.business_name
     FROM users u
     LEFT JOIN businesses b ON b.id = u.business_id
     WHERE u.email = ?
       AND u.account_status = 'active'
       AND {$portalRoleFilter}"
);

if (!$stmt) {
    error_log('Forgot password lookup prepare error: ' . $conn->error);
    fpLookupRedirect('Unable to process your request right now. Please try again.');
}

$stmt->bind_param('s', $email);
$stmt->execute();
$accounts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* Same neutral message whether the email exists or not - do not reveal it. */
if (count($accounts) === 0) {
    $_SESSION['fp_no_match'] = true;
    $_SESSION['fp_email'] = $email;
    fpLookupRedirect(
        'If an account matches that email, an OTP will be sent to it.',
        'success',
        'send_forgot_otp.php'
    );
}

$_SESSION['fp_no_match'] = false;
$_SESSION['fp_email'] = $email;
$_SESSION['fp_selected_user_id'] = (int) $accounts[0]['id'];

/* Keep account details server-side until the email OTP has been verified. */
$candidates = [];
if (count($accounts) > 1) {
    foreach ($accounts as $account) {
        $candidates[(int) $account['id']] = [
            'id' => (int) $account['id'],
            'masked_username' => nxMaskUsername((string) $account['username']),
            'business_name' => (string) ($account['business_name'] ?? ''),
        ];
    }
    $_SESSION['fp_candidates'] = $candidates;
}

fpLookupRedirect(
    'If an account matches that email, an OTP will be sent to it.',
    'success',
    'send_forgot_otp.php'
);
