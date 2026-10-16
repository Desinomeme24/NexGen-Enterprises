<?php
/* config.php starts the hardened session before any authentication state is
   read or written. Starting a session before loading it would bypass those
   cookie controls. */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/tenant_helper.php';

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    $_SESSION['error'] = "Invalid request.";
    $_SESSION['form_type'] = 'login';
    header('Location: ' . NEXGEN_CLIENT_LOGIN_PATH);
    exit();
}

/* PORTAL ISOLATION: the submitted portal selects a distinct CSRF/CAPTCHA
   namespace and redirect target. Missing or invented portal values are
   rejected instead of silently falling back to another authentication path. */
$loginPortal = strtolower(trim((string)($_POST['login_portal'] ?? '')));

if (!in_array($loginPortal, ['admin', 'client'], true)) {
    $_SESSION['error'] = 'Invalid login portal request. Please open the login page again.';
    $_SESSION['form_type'] = 'login';
    header('Location: ' . NEXGEN_CLIENT_LOGIN_PATH);
    exit();
}

$loginPage = $loginPortal === 'admin'
    ? NEXGEN_ADMIN_LOGIN_PATH
    : NEXGEN_CLIENT_LOGIN_PATH;
$loginFormKey = $loginPortal === 'admin'
    ? 'admin_login_form'
    : 'client_login_form';

/* SECURITY: CSRF validation */
if (!validateCsrfToken($loginFormKey, $_POST['csrf_token'] ?? '')) {
    $_SESSION['error'] = "Invalid or expired login form token.";
    $_SESSION['form_type'] = 'login';
    header('Location: ' . $loginPage);
    exit();
}

$username = trim($_POST['username'] ?? '');
$password = trim($_POST['password'] ?? '');
$captchaSelection = $_POST['captcha_selection'] ?? [];

if ($username === '' || $password === '') {
    $_SESSION['error'] = "Please enter your username and password.";
    $_SESSION['form_type'] = 'login';
    header('Location: ' . $loginPage);
    exit();
}

/*
|--------------------------------------------------------------------------
| STEP 1: CHECK USER ACCOUNT IN users TABLE FIRST
|--------------------------------------------------------------------------
*/
$sql = "SELECT id, username, full_name, email, password, profile_image, role, account_status,
               can_inventory, can_sales, can_sales_analytics, can_accounts_receivable,
               failed_login_attempts, locked_until, business_id
        FROM users
        WHERE username = ?
        LIMIT 1";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    error_log('NexGen login query preparation failed: ' . $conn->error);
    $_SESSION['error'] = "The login service is temporarily unavailable. Please try again.";
    $_SESSION['form_type'] = 'login';
    header('Location: ' . $loginPage);
    exit();
}

$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    $stmt->close();

    /* Registration status belongs only to the client portal. The isolated
       administrator endpoint never exposes signup/request information. */
    if ($loginPortal === 'client') {
        $requestSql = "SELECT username, request_status, admin_remarks, full_name
                       FROM registration_requests
                       WHERE username = ?
                       ORDER BY id DESC
                       LIMIT 1";

        $requestStmt = $conn->prepare($requestSql);

        if ($requestStmt) {
            $requestStmt->bind_param("s", $username);
            $requestStmt->execute();
            $requestResult = $requestStmt->get_result();
            $requestData = $requestResult->fetch_assoc();
            $requestStmt->close();

            if ($requestData) {
                $requestStatus = strtolower(trim((string)($requestData['request_status'] ?? '')));
                $remarks = trim((string)($requestData['admin_remarks'] ?? ''));

                switch ($requestStatus) {
                    case 'pending':
                        $_SESSION['error'] = "Your account request is still pending admin approval. Please wait for confirmation.";
                        $_SESSION['form_type'] = 'login';
                        header('Location: ' . $loginPage);
                        exit();

                    case 'rejected':
                        $_SESSION['error'] = $remarks !== ''
                            ? "Your account request has been rejected. Reason: " . $remarks
                            : "Your account request has been rejected by the admin. Please contact the administrator for more details.";
                        $_SESSION['form_type'] = 'login';
                        header('Location: ' . $loginPage);
                        exit();

                    case 'resubmit':
                        $_SESSION['error'] = $remarks !== ''
                            ? "Your account request needs resubmission. Please update the following: " . $remarks
                            : "Your account request needs to be resubmitted. Please review your submitted information and try again.";
                        $_SESSION['form_type'] = 'login';
                        header('Location: ' . $loginPage);
                        exit();
                }
            }
        }
    }

    $_SESSION['error'] = "Invalid username or password.";
    $_SESSION['form_type'] = 'login';
    header('Location: ' . $loginPage);
    exit();
}

$user = $result->fetch_assoc();
$stmt->close();

/* SERVER-SIDE ROLE BOUNDARY: UI separation alone is not a security control.
   Reject an account whose stored role does not belong to the submitted portal
   before lockout or session state can cross between the two login routes. */
$portalRoleAllowed = $loginPortal === 'admin'
    ? (string)$user['role'] === 'system_admin'
    : in_array((string)$user['role'], ['owner', 'employee'], true);

if (!$portalRoleAllowed) {
    $_SESSION['error'] = 'Invalid username or password.';
    $_SESSION['form_type'] = 'login';
    unset(
        $_SESSION['lockout_until_ts'],
        $_SESSION['lockout_username'],
        $_SESSION['lockout_user_id'],
        $_SESSION['lockout_role'],
        $_SESSION['attempt_warning'],
        $_SESSION['attempts_left']
    );
    header('Location: ' . $loginPage);
    exit();
}

/* SECURITY: Check current lock first */
if (isUserLocked($user)) {
    $lockUntilTs = strtotime((string)$user['locked_until']);
    $isPermanentLock = (int)($user['failed_login_attempts'] ?? 0) >= 5;

    $_SESSION['form_type'] = 'login';

    if ($isPermanentLock) {
        $_SESSION['error'] = "This account was automatically locked after 5 consecutive failed login attempts. Please contact the system administrator to unlock it.";
        unset($_SESSION['lockout_until_ts'], $_SESSION['attempt_warning'], $_SESSION['attempts_left']);

        if ((string)$user['role'] === 'system_admin') {
            /* Preserve only the administrator identity needed by the separate
               OTP-unlock page. The password and OTP are never stored here. */
            $_SESSION['lockout_username'] = (string)$user['username'];
            $_SESSION['lockout_user_id'] = (int)$user['id'];
            $_SESSION['lockout_role'] = 'system_admin';
        } else {
            unset(
                $_SESSION['lockout_username'],
                $_SESSION['lockout_user_id'],
                $_SESSION['lockout_role']
            );
        }
    } else {
        $_SESSION['error'] = "Three consecutive login attempts failed. This account is temporarily locked for 1 minute; 2 attempts remain after the cooldown.";
        $_SESSION['lockout_until_ts'] = $lockUntilTs;
        $_SESSION['lockout_username'] = $user['username'];
        $_SESSION['lockout_user_id'] = (int)$user['id'];
        $_SESSION['lockout_role'] = (string)$user['role'];
    }

    header('Location: ' . $loginPage);
    exit();
}

/*
|--------------------------------------------------------------------------
| HELPER: record failed attempt and optionally lock account
|--------------------------------------------------------------------------
*/
function recordFailedAttempt(mysqli $conn, array $user, string $reason = 'Invalid username or password.'): void
{
    $temporaryLockThreshold = 3;
    $maximumAttempts = 5;
    $newAttempts = min($maximumAttempts, ((int)$user['failed_login_attempts']) + 1);
    $attemptsLeft = max(0, $maximumAttempts - $newAttempts);
    $lockedUntil = null;
    $isPermanentLock = false;

    if ($newAttempts >= $maximumAttempts) {
        /* Preserve the current schema while making the fifth consecutive
           failure administrator-unlock-only. */
        $lockedUntil = '9999-12-31 23:59:59';
        $isPermanentLock = true;
    } elseif ($newAttempts === $temporaryLockThreshold) {
        $lockedUntil = date('Y-m-d H:i:s', strtotime('+1 minutes'));
    }

    $updateFailSql = "UPDATE users
                      SET failed_login_attempts = ?, locked_until = ?, last_failed_login_at = NOW()
                      WHERE id = ?";
    $updateFailStmt = $conn->prepare($updateFailSql);

    if ($updateFailStmt) {
        $updateFailStmt->bind_param("isi", $newAttempts, $lockedUntil, $user['id']);
        $updateFailStmt->execute();
        $updateFailStmt->close();
    }

    $_SESSION['form_type'] = 'login';

    if ($isPermanentLock) {
        $_SESSION['error'] = "This account has been automatically locked after 5 consecutive failed login attempts. Please contact the system administrator to unlock it.";
        unset($_SESSION['lockout_until_ts'], $_SESSION['attempt_warning'], $_SESSION['attempts_left']);

        if ((string)$user['role'] === 'system_admin') {
            $_SESSION['lockout_username'] = (string)$user['username'];
            $_SESSION['lockout_user_id'] = (int)$user['id'];
            $_SESSION['lockout_role'] = 'system_admin';
        } else {
            unset(
                $_SESSION['lockout_username'],
                $_SESSION['lockout_user_id'],
                $_SESSION['lockout_role']
            );
        }
    } elseif ($lockedUntil !== null) {
        $_SESSION['error'] = "Three consecutive login attempts failed. Your account is temporarily locked for 1 minute; 2 attempts remain after the cooldown.";
        $_SESSION['lockout_until_ts'] = strtotime($lockedUntil);
        $_SESSION['lockout_username'] = $user['username'];
        $_SESSION['lockout_user_id'] = (int)$user['id'];
        $_SESSION['lockout_role'] = (string)$user['role'];
        unset($_SESSION['attempt_warning'], $_SESSION['attempts_left']);
    } else {
        $_SESSION['error'] = $reason . ' ' . $attemptsLeft . ' failed attempt(s) remain before the account is automatically locked.';
        $_SESSION['attempt_warning'] = true;
        $_SESSION['attempts_left'] = $attemptsLeft;
        unset(
            $_SESSION['lockout_until_ts'],
            $_SESSION['lockout_username'],
            $_SESSION['lockout_user_id'],
            $_SESSION['lockout_role']
        );
    }
}

/*
|--------------------------------------------------------------------------
| CAPTCHA: wrong captcha also counts as failed attempt
|--------------------------------------------------------------------------
*/
if (!validateImageCaptchaSelection($loginFormKey, (array)$captchaSelection)) {
    recordFailedAttempt(
        $conn,
        $user,
        "Incorrect captcha selection."
    );
    header('Location: ' . $loginPage);
    exit();
}

/*
|--------------------------------------------------------------------------
| STEP 3: VERIFY PASSWORD
|--------------------------------------------------------------------------
*/
if (!password_verify($password, $user['password'])) {
    recordFailedAttempt(
        $conn,
        $user,
        "Invalid username or password."
    );
    header('Location: ' . $loginPage);
    exit();
}

/* SECURITY: Reset attempts after successful login */
$resetAttemptSql = "UPDATE users
                    SET failed_login_attempts = 0,
                        locked_until = NULL,
                        last_failed_login_at = NULL
                    WHERE id = ?";
$resetAttemptStmt = $conn->prepare($resetAttemptSql);
if ($resetAttemptStmt) {
    $resetAttemptStmt->bind_param("i", $user['id']);
    $resetAttemptStmt->execute();
    $resetAttemptStmt->close();
}

unset(
    $_SESSION['lockout_until_ts'],
    $_SESSION['lockout_username'],
    $_SESSION['lockout_user_id'],
    $_SESSION['lockout_role'],
    $_SESSION['attempt_warning'],
    $_SESSION['attempts_left']
);

/*
|--------------------------------------------------------------------------
| STEP 4: CHECK ACCOUNT STATUS IN users TABLE
|--------------------------------------------------------------------------
*/
if ($user['account_status'] !== 'active') {
    $status = strtolower(trim((string)$user['account_status']));
    $message = "Your account is not active yet. Please wait for admin approval.";

    switch ($status) {
        case 'pending':
            $message = "Your account request is still pending admin approval. Please wait for confirmation.";
            break;

        case 'rejected':
            $message = "Your account request has been rejected by the admin. Please contact the administrator for more details.";
            break;

        case 'resubmit':
            $message = "Your account request needs to be resubmitted. Please review your submitted information and try again.";
            break;

        case 'inactive':
            $message = "Your account is currently inactive. Please contact the administrator for assistance.";
            break;

        case 'disabled':
            $message = "Your account has been disabled. Please contact the administrator for assistance.";
            break;

        default:
            $message = "Your account is currently unavailable. Please contact the administrator for assistance.";
            break;
    }

    $_SESSION['error'] = $message;
    $_SESSION['form_type'] = 'login';
    header('Location: ' . $loginPage);
    exit();
}

/*
|--------------------------------------------------------------------------
| STEP 5: LOGIN SESSION
|--------------------------------------------------------------------------
*/
/* Prevent session fixation by issuing a new identifier only after the
   credentials, portal role, CAPTCHA, lockout, and account status pass. */
session_regenerate_id(true);

$_SESSION['user_id'] = $user['id'];
$_SESSION['username'] = $user['username'];
$_SESSION['full_name'] = $user['full_name'];
$_SESSION['profile_image'] = !empty($user['profile_image']) ? $user['profile_image'] : 'uploads/default.png';
$_SESSION['role'] = $user['role'];
$_SESSION['account_status'] = $user['account_status'];
$_SESSION['can_inventory'] = (int)($user['can_inventory'] ?? 0);
$_SESSION['can_sales'] = (int)($user['can_sales'] ?? 0);
$_SESSION['can_sales_analytics'] = (int)($user['can_sales_analytics'] ?? 0);
$_SESSION['can_accounts_receivable'] = (int)($user['can_accounts_receivable'] ?? 0);
$_SESSION['login_portal'] = $loginPortal;

/* TENANT ISOLATION: always set business_id fresh from the authenticated
   user's own row, overwriting anything left over from a previous session.
   Without this, logging into a second account without formally logging out
   first (e.g. navigating straight back to the login form) would silently
   inherit the previous account's business_id via nxRequireBusinessId()'s
   session cache, showing/writing the wrong business's data. 0 = system_admin
   (no business), matching nxRequireBusinessId()'s convention. */
$_SESSION['business_id'] = (int)($user['business_id'] ?? 0);

/* MULTI-BUSINESS / BRANCH WORKSPACE:
   Resolve the account's default authorized branch after authentication. The
   helper also bootstraps legacy users from users.business_id, so accounts that
   existed before the migration keep working without manual reassignment. */
if (in_array($user['role'], ['owner', 'employee'], true)) {
    $activeWorkspace = nxInitializeUserWorkspace($conn, true);

    if (empty($activeWorkspace)) {
        session_unset();
        session_destroy();
        session_id('');
        session_start();
        $_SESSION['error'] = 'Your account is not assigned to an active business branch. Please contact the administrator.';
        $_SESSION['form_type'] = 'login';
        header('Location: ' . $loginPage);
        exit();
    }
}

/* SESSION SECURITY: start inactivity timer after successful login */
$_SESSION['last_activity'] = time();
if (defined('SESSION_TIMEOUT_SECONDS')) {
    $_SESSION['session_timeout_seconds'] = SESSION_TIMEOUT_SECONDS;
}

$_SESSION['success'] = "Login successful. Welcome back, " . $user['username'] . "!";

/* update last login BEFORE redirect */
$updateLastLogin = $conn->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?");
if ($updateLastLogin) {
    $updateLastLogin->bind_param("i", $user['id']);
    $updateLastLogin->execute();
    $updateLastLogin->close();
}

/* AUDIT: record the successful login */
$auditLogId = logAuthActivity(
    $conn,
    (int)$user['id'],
    (string)$user['username'],
    (string)$user['role'],
    'login'
);

if ($auditLogId === null) {
    /*
     * Authentication has already passed all existing security/business
     * checks. Keep that behavior intact, but make the missing audit write
     * visible in server logs instead of silently losing the session audit.
     */
    error_log('NexGen successful login could not create its audit record for user ID ' . (int)$user['id']);
} else {
    $_SESSION['audit_log_id'] = $auditLogId;
}

/* Decide redirect target only after the portal/role boundary has passed. */
$redirectUrl = $loginPage;

if ($user['role'] === 'system_admin') {
    $redirectUrl = nxAppUrl('admin_dashboard.php');
} elseif (in_array($user['role'], ['owner', 'employee'], true)) {
    $redirectUrl = nxAppUrl('dashboard.php');
} else {
    session_unset();
    session_destroy();
    session_id('');
    session_start();

    $_SESSION['error'] = "Invalid user role.";
    $_SESSION['form_type'] = 'login';
    header('Location: ' . $loginPage);
    exit();
}

$loginSuccessRedirectUrl = $redirectUrl;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php include __DIR__ . '/nexgen_favicon/favicon.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Successful - NexGen</title>
    <meta http-equiv="refresh" content="5;url=<?php echo htmlspecialchars($loginSuccessRedirectUrl, ENT_QUOTES, 'UTF-8'); ?>">
    <?php include 'theme_init.php'; ?>
    <?php
        /* Keep success-page values isolated from variables declared, changed,
           or unset by included theme files. The authenticated session is the
           source of truth at this point in the login flow. */
        $loginSuccessDisplayName = trim((string)($_SESSION['full_name'] ?? ''));
        if ($loginSuccessDisplayName === '') {
            $loginSuccessDisplayName = (string)($_SESSION['username'] ?? 'User');
        }

        $loginSuccessProfileImage = trim((string)($_SESSION['profile_image'] ?? ''));
        if ($loginSuccessProfileImage === '') {
            $loginSuccessProfileImage = nxAppUrl('uploads/default.png');
        }

        $loginSuccessRole = (string)($_SESSION['role'] ?? '');
        $loginSuccessRedirectUrl = $loginSuccessRole === 'system_admin'
            ? nxAppUrl('admin_dashboard.php')
            : nxAppUrl('dashboard.php');
    ?>

    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">

    <style>
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {
            --bg-1: #07133e;
            --bg-2: #020719;
            --grid-line: rgba(79, 133, 235, 0.10);
            --pulse: #4d89ff;
            --star: #74a9ff;

            --card-top: rgba(54, 82, 146, 0.74);
            --card-bottom: rgba(17, 43, 105, 0.78);
            --card-border: rgba(179, 203, 255, 0.30);
            --card-shadow: 0 28px 70px rgba(0, 0, 0, 0.42);

            --text: #ffffff;
            --muted: rgba(232, 239, 252, 0.72);
            --accent: #5590ff;
            --accent-soft: #7aa7ff;
            --dot-off: rgba(91, 143, 238, 0.32);
            --gold: #f7ca84;
        }

        html[data-theme="light"] {
            --bg-1: #eef7ff;
            --bg-2: #dbeeff;
            --grid-line: rgba(56, 112, 183, 0.075);
            --pulse: #3b82f6;
            --star: #4b8bf4;

            --card-top: rgba(191, 224, 255, 0.84);
            --card-bottom: rgba(163, 210, 255, 0.66);
            --card-border: rgba(72, 133, 205, 0.50);
            --card-shadow: 0 28px 70px rgba(56, 115, 180, 0.20);

            --text: #102b5e;
            --muted: rgba(35, 76, 123, 0.70);
            --accent: #426ff3;
            --accent-soft: #71a1ff;
            --dot-off: rgba(66, 111, 243, 0.22);
            --gold: #c58a2f;
        }

        html,
        body {
            width: 100%;
            height: 100%;
            overflow: hidden;
            font-family: "DM Sans", Arial, sans-serif;
            background: var(--bg-2);
        }

        body {
            color: var(--text);
        }

        /* =========================================================
           ANIMATED BACKGROUND
           ========================================================= */
        .success-bg {
            position: fixed;
            inset: 0;
            overflow: hidden;
            z-index: 0;
            background:
                radial-gradient(circle at 12% 18%, rgba(45, 117, 255, 0.13), transparent 30%),
                radial-gradient(circle at 87% 82%, rgba(74, 144, 255, 0.10), transparent 34%),
                linear-gradient(155deg, var(--bg-1), var(--bg-2));
        }

        .success-grid {
            position: absolute;
            inset: -8%;
            background-image:
                linear-gradient(var(--grid-line) 1px, transparent 1px),
                linear-gradient(90deg, var(--grid-line) 1px, transparent 1px);
            background-size: 54px 54px;
            animation: gridDrift 18s linear infinite;
        }

        @keyframes gridDrift {
            from { transform: translate3d(0, 0, 0); }
            to   { transform: translate3d(54px, 54px, 0); }
        }

        .success-bg::before,
        .success-bg::after {
            content: "";
            position: absolute;
            border-radius: 50%;
            filter: blur(4px);
            opacity: 0.7;
            pointer-events: none;
        }

        .success-bg::before {
            width: 520px;
            height: 520px;
            left: -180px;
            top: -180px;
            background: radial-gradient(circle, rgba(76, 135, 255, 0.16), transparent 68%);
            animation: glowFloatA 12s ease-in-out infinite alternate;
        }

        .success-bg::after {
            width: 620px;
            height: 620px;
            right: -220px;
            bottom: -260px;
            background: radial-gradient(circle, rgba(44, 119, 255, 0.13), transparent 68%);
            animation: glowFloatB 15s ease-in-out infinite alternate;
        }

        @keyframes glowFloatA {
            to { transform: translate(90px, 70px) scale(1.08); }
        }

        @keyframes glowFloatB {
            to { transform: translate(-90px, -60px) scale(1.10); }
        }

        .signal-layer {
            position: absolute;
            inset: 0;
            overflow: hidden;
        }

        .signal-dot {
            position: absolute;
            width: 4px;
            height: 4px;
            border-radius: 50%;
            background: var(--pulse);
            box-shadow: 0 0 10px 2px var(--pulse);
            opacity: 0;
            animation: signalFade linear forwards;
        }

        @keyframes signalFade {
            0%   { opacity: 0; }
            12%  { opacity: 0.9; }
            80%  { opacity: 0.85; }
            100% { opacity: 0; }
        }

        .ambient-star {
            position: absolute;
            width: 4px;
            height: 4px;
            border-radius: 50%;
            background: var(--star);
            box-shadow: 0 0 10px var(--star);
            opacity: 0.18;
            animation: starBlink 4.8s ease-in-out infinite;
        }

        .ambient-star:nth-child(1) { left: 9%; top: 17%; animation-delay: .2s; }
        .ambient-star:nth-child(2) { left: 79%; top: 21%; animation-delay: 1.1s; }
        .ambient-star:nth-child(3) { left: 18%; top: 83%; animation-delay: 2.2s; }
        .ambient-star:nth-child(4) { left: 88%; top: 70%; animation-delay: 3.0s; }
        .ambient-star:nth-child(5) { left: 56%; top: 12%; animation-delay: 1.8s; }
        .ambient-star:nth-child(6) { left: 64%; top: 89%; animation-delay: .8s; }

        @keyframes starBlink {
            0%, 100% { opacity: .12; transform: scale(.75); }
            50%      { opacity: .75; transform: scale(1.35); }
        }

        .success-scrim {
            position: fixed;
            inset: 0;
            z-index: 1;
            background: rgba(5, 11, 33, 0.14);
            backdrop-filter: blur(1.5px);
        }

        html[data-theme="light"] .success-scrim {
            background: rgba(255, 255, 255, 0.10);
        }

        /* =========================================================
           CENTER CARD
           ========================================================= */
        .success-stage {
            position: fixed;
            inset: 0;
            z-index: 2;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 22px;
        }

        .success-card {
            position: relative;
            width: min(90vw, 350px);
            min-height: 0;
            height: auto;
            padding: 32px 28px 28px;
            border-radius: 28px;
            text-align: center;

            background:
                radial-gradient(circle at 50% 0%, rgba(255,255,255,.11), transparent 38%),
                linear-gradient(165deg, var(--card-top), var(--card-bottom));

            border: 1px solid var(--card-border);

            box-shadow:
                var(--card-shadow),
                inset 0 1px 0 rgba(255,255,255,.20);

            backdrop-filter: blur(22px) saturate(145%);
            -webkit-backdrop-filter: blur(22px) saturate(145%);

            overflow: hidden;
            opacity: 0;
            transform: translateY(24px) scale(.985);
            animation: cardEnter .72s cubic-bezier(.22, 1, .36, 1) forwards;
        }

        .success-card::before {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 38%;
            background: linear-gradient(180deg, rgba(255,255,255,.12), transparent);
            pointer-events: none;
        }

        .success-card::after {
            content: "";
            position: absolute;
            width: 180px;
            height: 180px;
            right: -80px;
            bottom: -90px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(77, 137, 255, .14), transparent 70%);
            pointer-events: none;
        }

        @keyframes cardEnter {
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        /* =========================================================
           ID BADGE
           ========================================================= */
        .id-badge {
            position: relative;
            width: 150px;
            margin: 0 auto 24px;
            z-index: 2;
        }

        .badge-clip {
            width: 46px;
            height: 38px;
            margin: 0 auto -12px;
            border-radius: 9px 9px 0 0;
            background: linear-gradient(180deg, #5ca1ff, #2f72e9);
            box-shadow: 0 -8px 18px rgba(44, 121, 255, .28);
            position: relative;
            z-index: 2;
        }

        .badge-clip::after {
            content: "";
            position: absolute;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background: rgba(25, 57, 111, .72);
            left: 50%;
            top: 11px;
            transform: translateX(-50%);
        }

        .badge-shell {
            position: relative;
            height: 160px;
            border-radius: 21px 21px 20px 20px;
            background: linear-gradient(180deg, #f8fbff 0%, #dce8f9 66%, #183977 66%, #183977 100%);
            border: 1px solid rgba(255,255,255,.75);
            box-shadow: 0 14px 32px rgba(0,0,0,.27);
            overflow: visible;
        }

        .profile-ring {
            position: absolute;
            left: 50%;
            top: 23px;
            transform: translateX(-50%);
            width: 88px;
            height: 88px;
            border-radius: 50%;
            padding: 6px;
            background: rgba(255,255,255,.98);
            box-shadow: 0 7px 18px rgba(0,0,0,.20);
        }

        .profile-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
            display: block;
            background: #dce8f9;
        }

        .barcode {
            position: absolute;
            bottom: 17px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            align-items: flex-end;
            gap: 3px;
        }

        .barcode span {
            display: block;
            width: 4px;
            background: #fff;
            border-radius: 1px;
        }

        .barcode span:nth-child(1) { height: 20px; }
        .barcode span:nth-child(2) { height: 27px; }
        .barcode span:nth-child(3) { height: 22px; }
        .barcode span:nth-child(4) { height: 28px; }
        .barcode span:nth-child(5) { height: 23px; }
        .barcode span:nth-child(6) { height: 27px; }

        .verify-badge {
            position: absolute;
            right: -17px;
            bottom: 4px;
            width: 66px;
            height: 66px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f6f9ff;
            box-shadow: 0 9px 21px rgba(0,0,0,.20);
            animation: verifyPop .55s cubic-bezier(.34,1.56,.64,1) .38s both;
        }

        .verify-badge::before {
            content: "";
            position: absolute;
            inset: 8px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4d91ff, #2f70e6);
        }

        .verify-badge svg {
            position: relative;
            z-index: 2;
            width: 33px;
            height: 33px;
            fill: none;
            stroke: #fff;
            stroke-width: 2.7;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        @keyframes verifyPop {
            from { opacity: 0; transform: scale(.25) rotate(-22deg); }
            to   { opacity: 1; transform: scale(1) rotate(0); }
        }

        /* =========================================================
           TEXT + LOADING
           ========================================================= */
        .success-name {
            position: relative;
            z-index: 2;
            margin-bottom: 5px;
            font-family: "Sora", Arial, sans-serif;
            font-size: 29px;
            line-height: 1.15;
            font-weight: 800;
            color: var(--text);
            letter-spacing: -0.04em;
            text-shadow: 0 3px 20px rgba(0,0,0,.15);
        }

        .success-title {
            position: relative;
            z-index: 2;
            margin-bottom: 7px;
            font-family: "Sora", Arial, sans-serif;
            font-size: 15px;
            font-weight: 700;
            color: var(--text);
        }

        .workspace-message,
        .redirect-message {
            position: relative;
            z-index: 2;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.5;
        }

        .workspace-message {
            margin-bottom: 2px;
        }

        .redirect-message {
            margin-bottom: 13px;
            font-size: 12px;
            opacity: .9;
        }

        .loading-dots {
            position: relative;
            z-index: 2;
            height: 18px;
            margin-bottom: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 11px;
        }

        .loading-dots span {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: var(--dot-off);
            animation: dotPulse 1.15s ease-in-out infinite;
        }

        .loading-dots span:nth-child(2) {
            animation-delay: .16s;
        }

        .loading-dots span:nth-child(3) {
            animation-delay: .32s;
        }

        @keyframes dotPulse {
            0%, 100% {
                opacity: .35;
                transform: scale(.85);
                box-shadow: none;
            }
            50% {
                opacity: 1;
                transform: scale(1.12);
                background: var(--accent);
                box-shadow: 0 0 14px rgba(80, 143, 255, .55);
            }
        }
@media (max-width: 520px) {
            .success-card {
                width: min(91vw, 340px);
                padding: 28px 24px 25px;
                border-radius: 25px;
            }

            .success-name {
                font-size: 27px;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .success-grid,
            .success-bg::before,
            .success-bg::after,
            .ambient-star,
            .loading-dots span {
                animation: none !important;
            }
        }
    </style>
</head>
<body>

<?php
    $isSmeWorkspace = in_array($loginSuccessRole, ['owner', 'employee'], true);

    if ($isSmeWorkspace) {
        $workspaceMessage = 'Preparing your workspace...';
        $redirectMessage = 'Redirecting to your dashboard...';
    } else {
        $workspaceMessage = 'Preparing your administrator workspace...';
        $redirectMessage = 'Redirecting to your admin dashboard...';
    }
?>

<div class="success-bg" aria-hidden="true">
    <div class="success-grid"></div>
    <div class="signal-layer" id="signalLayer"></div>

    <span class="ambient-star"></span>
    <span class="ambient-star"></span>
    <span class="ambient-star"></span>
    <span class="ambient-star"></span>
    <span class="ambient-star"></span>
    <span class="ambient-star"></span>
</div>

<div class="success-scrim" aria-hidden="true"></div>

<main class="success-stage">
    <section class="success-card" aria-live="polite">

        <div class="id-badge" aria-hidden="true">
            <div class="badge-clip"></div>

            <div class="badge-shell">
                <div class="profile-ring">
                    <img
                        class="profile-img"
                        src="<?php echo htmlspecialchars($loginSuccessProfileImage, ENT_QUOTES, 'UTF-8'); ?>"
                        alt=""
                        onerror="this.src='<?php echo e(nxAppUrl('uploads/default.png')); ?>'"
                    >
                </div>

                <div class="barcode">
                    <span></span><span></span><span></span>
                    <span></span><span></span><span></span>
                </div>
            </div>

            <div class="verify-badge">
                <svg viewBox="0 0 24 24">
                    <polyline points="20 6 9 17 4 12"></polyline>
                </svg>
            </div>
        </div>

        <h1 class="success-name">
            <?php echo htmlspecialchars($loginSuccessDisplayName, ENT_QUOTES, 'UTF-8'); ?>
        </h1>

        <div class="success-title">Login successful!</div>

        <p class="workspace-message">
            <?php echo htmlspecialchars($workspaceMessage, ENT_QUOTES, 'UTF-8'); ?>
        </p>

        <p class="redirect-message">
            <?php echo htmlspecialchars($redirectMessage, ENT_QUOTES, 'UTF-8'); ?>
        </p>

        <div class="loading-dots" aria-label="Loading">
            <span></span>
            <span></span>
            <span></span>
        </div>
</section>
</main>

<script>
(function () {
    const layer = document.getElementById("signalLayer");
    if (!layer) return;

    const GRID = 54;

    function spawnSignal() {
        const dot = document.createElement("span");
        dot.className = "signal-dot";

        const cols = Math.ceil(window.innerWidth / GRID);
        const rows = Math.ceil(window.innerHeight / GRID);

        const horizontal = Math.random() < 0.5;
        const steps = 3 + Math.floor(Math.random() * 6);

        let x;
        let y;
        let dx;
        let dy;

        if (horizontal) {
            y = Math.floor(Math.random() * rows) * GRID;
            x = Math.floor(Math.random() * Math.max(1, cols - steps)) * GRID;
            dx = steps * GRID;
            dy = 0;
        } else {
            x = Math.floor(Math.random() * cols) * GRID;
            y = Math.floor(Math.random() * Math.max(1, rows - steps)) * GRID;
            dx = 0;
            dy = steps * GRID;
        }

        dot.style.left = x + "px";
        dot.style.top = y + "px";

        const duration = Math.max(1500, steps * 430);

        dot.style.animationDuration = duration + "ms";
        layer.appendChild(dot);

        if (dot.animate) {
            dot.animate(
                [
                    { transform: "translate3d(0,0,0)" },
                    { transform: "translate3d(" + dx + "px," + dy + "px,0)" }
                ],
                {
                    duration: duration,
                    easing: "linear"
                }
            );
        }

        window.setTimeout(function () {
            dot.remove();
        }, duration + 100);
    }

    for (let i = 0; i < 12; i++) {
        window.setTimeout(spawnSignal, i * 130);
    }

    window.setInterval(spawnSignal, 420);
})();

/* Reliable redirect in addition to the meta-refresh fallback. */
window.setTimeout(function () {
    window.location.replace(
        "<?php echo htmlspecialchars($loginSuccessRedirectUrl, ENT_QUOTES, 'UTF-8'); ?>"
    );
}, 5000);
</script>

</body>
</html>