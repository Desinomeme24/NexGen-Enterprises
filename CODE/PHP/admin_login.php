<?php
require_once __DIR__ . '/config.php';

/* An authenticated account should never be shown another login form. */
if (!empty($_SESSION['user_id'])) {
    enforceSessionTimeout();

    $authenticatedRole = (string)($_SESSION['role'] ?? '');
    $authenticatedTarget = $authenticatedRole === 'system_admin'
        ? nxAppUrl('admin_dashboard.php')
        : nxAppUrl('dashboard.php');

    header('Location: ' . $authenticatedTarget);
    exit();
}

/* Preserve the originating portal for password/unlock recovery pages. */
$_SESSION['login_portal'] = 'admin';

/* The administrator portal has independent CSRF and CAPTCHA state. */
$adminCaptcha = generateImageCaptcha('admin_login_form');
$adminCsrfToken = generateCsrfToken('admin_login_form');

$flashMessage = '';
$flashType = '';
$showAttemptPopup = false;
$attemptsLeft = null;
$attemptPopupText = '';
$showLockCountdown = false;
$lockoutUntilTs = 0;
$lockoutUsername = '';
$hasAdminUnlockContext = (string)($_SESSION['lockout_role'] ?? '') === 'system_admin'
    && (int)($_SESSION['lockout_user_id'] ?? 0) > 0
    && trim((string)($_SESSION['lockout_username'] ?? '')) !== '';

if (
    isset($_SESSION['lockout_until_ts'])
    && (int)$_SESSION['lockout_until_ts'] > time()
    && (string)($_SESSION['lockout_role'] ?? '') === 'system_admin'
) {
    $showLockCountdown = true;
    $lockoutUntilTs = (int)$_SESSION['lockout_until_ts'];
    $lockoutUsername = (string)($_SESSION['lockout_username'] ?? '');
} elseif ($hasAdminUnlockContext) {
    /* A permanently locked administrator has no short cooldown timestamp but
       must retain this minimal identity so OTP unlock can load the account. */
    $lockoutUsername = (string)$_SESSION['lockout_username'];
    unset($_SESSION['lockout_until_ts']);
} else {
    unset(
        $_SESSION['lockout_until_ts'],
        $_SESSION['lockout_username'],
        $_SESSION['lockout_user_id'],
        $_SESSION['lockout_role']
    );
}

if (!empty($_SESSION['attempt_warning'])) {
    $showAttemptPopup = true;
    $attemptsLeft = isset($_SESSION['attempts_left'])
        ? (int)$_SESSION['attempts_left']
        : null;
    $attemptPopupText = (string)($_SESSION['error'] ?? 'Your login attempt was not successful.');
    unset($_SESSION['attempt_warning'], $_SESSION['attempts_left']);
}

if (isset($_SESSION['success'])) {
    $flashMessage = (string)$_SESSION['success'];
    $flashType = 'success';
    unset($_SESSION['success']);
}

if (isset($_SESSION['error'])) {
    $flashMessage = (string)$_SESSION['error'];
    $flashType = 'error';
    unset($_SESSION['error'], $_SESSION['form_type']);
}

/* Do not hold PHP's session lock while the CAPTCHA images load. */
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php include __DIR__ . '/nexgen_favicon/favicon.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>NexGen Administrator Login</title>
    <style>
        :root {
            color-scheme: dark;
            --page: #06102b;
            --page-deep: #030817;
            --panel: rgba(11, 28, 68, 0.88);
            --panel-strong: #0d2455;
            --line: rgba(155, 190, 255, 0.18);
            --line-strong: rgba(155, 190, 255, 0.34);
            --text: #f8fbff;
            --muted: #aebdda;
            --accent: #f7ca84;
            --accent-strong: #edb94d;
            --blue: #4c89ff;
            --danger: #ff9d9d;
            --danger-bg: rgba(239, 68, 68, 0.13);
            --success: #9ee6b3;
            --success-bg: rgba(34, 197, 94, 0.13);
            --shadow: 0 30px 90px rgba(0, 0, 0, 0.42);
        }

        *, *::before, *::after { box-sizing: border-box; }

        html, body { min-height: 100%; }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at 12% 18%, rgba(47, 100, 215, 0.26), transparent 32%),
                radial-gradient(circle at 88% 82%, rgba(30, 93, 198, 0.20), transparent 34%),
                linear-gradient(145deg, var(--page), var(--page-deep));
            overflow-x: hidden;
        }

        body::before {
            content: "";
            position: fixed;
            inset: 0;
            pointer-events: none;
            opacity: 0.32;
            background-image:
                linear-gradient(rgba(105, 151, 235, 0.08) 1px, transparent 1px),
                linear-gradient(90deg, rgba(105, 151, 235, 0.08) 1px, transparent 1px);
            background-size: 46px 46px;
            mask-image: linear-gradient(to bottom, black, transparent 92%);
        }

        button, input { font: inherit; }
        button, a { -webkit-tap-highlight-color: transparent; }
        [hidden] { display: none !important; }

        .admin-page {
            position: relative;
            z-index: 1;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 20px;
        }

        .admin-shell {
            width: min(540px, 100%);
            overflow: hidden;
            border: 1px solid var(--line);
            border-radius: 24px;
            background: var(--panel);
            box-shadow: var(--shadow);
            backdrop-filter: blur(22px);
        }

        .login-panel {
            display: flex;
            align-items: center;
            padding: 36px 42px 32px;
            background: transparent;
        }

        .login-content { width: 100%; max-width: 430px; margin: 0 auto; }

        .login-kicker {
            margin: 0 0 22px;
            color: var(--accent);
            font-size: 0.74rem;
            font-weight: 800;
            letter-spacing: 0.14em;
            text-transform: uppercase;
        }

        .login-content h1 {
            font-size: 25px;
            letter-spacing: 0.14em;
            font-family: "Century Gothic";
        }

        .login-subtitle {
            margin: 10px 0 26px;
            color: var(--muted);
            font-size: 0.9rem;
            line-height: 1.6;
        }

        .flash {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 18px;
            padding: 12px 14px;
            border: 1px solid;
            border-radius: 13px;
            font-size: 0.82rem;
            line-height: 1.5;
        }

        .flash.error { color: var(--danger); background: var(--danger-bg); border-color: rgba(255, 130, 130, 0.28); }
        .flash.success { color: var(--success); background: var(--success-bg); border-color: rgba(121, 230, 154, 0.27); }

        .field { margin-bottom: 14px; }

        .field label {
            display: block;
            margin-bottom: 7px;
            color: #dce7fb;
            font-size: 0.78rem;
            font-weight: 700;
        }

        .input-wrap { position: relative; }

        .input-wrap input {
            width: 100%;
            min-height: 50px;
            padding: 0 48px 0 46px;
            border: 1px solid var(--line);
            border-radius: 14px;
            outline: none;
            background: rgba(4, 14, 39, 0.74);
            color: var(--text);
            transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
        }

        .input-wrap input::placeholder { color: #7183a4; }
        .input-wrap input:focus { border-color: var(--blue); box-shadow: 0 0 0 4px rgba(76, 137, 255, 0.13); background: rgba(5, 17, 47, 0.92); }

        .field-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            width: 18px;
            height: 18px;
            transform: translateY(-50%);
            color: #8196bd;
            pointer-events: none;
        }

        .field-icon svg { width: 100%; height: 100%; fill: none; stroke: currentColor; stroke-width: 1.8; }

        .password-toggle {
            position: absolute;
            right: 9px;
            top: 50%;
            width: 36px;
            height: 36px;
            transform: translateY(-50%);
            border: 0;
            border-radius: 10px;
            background: transparent;
            color: #8da0c2;
            cursor: pointer;
        }

        .password-toggle:hover, .password-toggle:focus-visible { color: white; background: rgba(255, 255, 255, 0.07); outline: none; }

        .captcha-control {
            width: 100%;
            min-height: 58px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            margin: 4px 0 15px;
            padding: 10px 14px;
            border: 1px solid var(--line);
            border-radius: 14px;
            background: rgba(4, 14, 39, 0.62);
            color: var(--text);
            text-align: left;
            cursor: pointer;
            transition: border-color 0.2s ease, background 0.2s ease;
        }

        .captcha-control:hover, .captcha-control:focus-visible { border-color: var(--line-strong); background: rgba(8, 23, 57, 0.85); outline: none; }
        .captcha-control.is-verified { border-color: rgba(79, 219, 129, 0.42); }

        .captcha-left { display: flex; align-items: center; gap: 11px; }

        .captcha-check {
            display: grid;
            place-items: center;
            width: 25px;
            height: 25px;
            flex: 0 0 25px;
            border: 2px solid #8196bd;
            border-radius: 7px;
            color: transparent;
            font-weight: 900;
        }

        .captcha-control.is-verified .captcha-check { border-color: #4ade80; background: #22a857; color: white; }
        .captcha-copy strong { display: block; font-size: 0.82rem; }
        .captcha-copy small { display: block; margin-top: 2px; color: var(--muted); font-size: 0.7rem; }
        .captcha-shield { color: var(--blue); font-size: 1.35rem; }

        .login-actions { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 15px; }

        .text-link {
            color: #a9bce1;
            font-size: 0.78rem;
            text-decoration: none;
        }

        .text-link:hover, .text-link:focus-visible { color: white; text-decoration: underline; outline: none; }

        button.text-link {
            padding: 0;
            border: 0;
            background: transparent;
            cursor: pointer;
        }

        .submit-btn {
            width: 100%;
            min-height: 50px;
            border: 0;
            border-radius: 14px;
            background: linear-gradient(135deg, var(--accent), var(--accent-strong));
            color: #17213b;
            font-weight: 850;
            cursor: pointer;
            box-shadow: 0 14px 30px rgba(237, 185, 77, 0.17);
            transition: transform 0.18s ease, box-shadow 0.18s ease, filter 0.18s ease;
        }

        .submit-btn:hover { transform: translateY(-1px); filter: brightness(1.04); box-shadow: 0 17px 34px rgba(237, 185, 77, 0.23); }
        .submit-btn:active { transform: translateY(0); }

        .portal-note {
            margin: 16px 0 0;
            padding-top: 15px;
            border-top: 1px solid var(--line);
            color: #7f91b2;
            font-size: 0.72rem;
            line-height: 1.55;
            text-align: center;
        }

        .portal-note a { color: #b8c9ea; }

        .overlay {
            position: fixed;
            inset: 0;
            z-index: 50;
            display: grid;
            place-items: center;
            padding: 20px;
            background: rgba(1, 5, 16, 0.76);
            backdrop-filter: blur(9px);
        }

        .dialog {
            width: min(520px, 100%);
            max-height: calc(100vh - 40px);
            overflow: auto;
            border: 1px solid var(--line-strong);
            border-radius: 22px;
            background: #0b1b42;
            box-shadow: 0 30px 90px rgba(0, 0, 0, 0.55);
        }

        .dialog-header { display: flex; justify-content: space-between; gap: 16px; padding: 22px 22px 12px; }
        .dialog-header h3 { margin: 0; font-size: 1.15rem; }
        .dialog-header p { margin: 6px 0 0; color: var(--muted); font-size: 0.8rem; line-height: 1.5; }

        .dialog-close {
            width: 34px;
            height: 34px;
            flex: 0 0 34px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.05);
            color: white;
            cursor: pointer;
        }

        .captcha-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; padding: 10px 22px 18px; }
        .captcha-tile { position: relative; display: block; aspect-ratio: 1; overflow: hidden; border: 2px solid transparent; border-radius: 12px; cursor: pointer; background: #06102b; }
        .captcha-tile input { position: absolute; opacity: 0; pointer-events: none; }
        .captcha-tile img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .captcha-tile span { position: absolute; top: 7px; right: 7px; display: grid; place-items: center; width: 23px; height: 23px; border: 2px solid white; border-radius: 7px; background: rgba(2, 8, 23, 0.56); color: transparent; font-size: 0.75rem; font-weight: 900; }
        .captcha-tile:has(input:checked) { border-color: var(--accent); }
        .captcha-tile input:checked ~ span { background: var(--accent); color: #14203b; border-color: var(--accent); }

        .dialog-actions { display: flex; justify-content: flex-end; gap: 10px; padding: 16px 22px 22px; border-top: 1px solid var(--line); }
        .dialog-btn { min-height: 42px; padding: 0 17px; border: 1px solid var(--line); border-radius: 11px; background: rgba(255, 255, 255, 0.06); color: white; font-weight: 750; cursor: pointer; }
        .dialog-btn.primary { border-color: transparent; background: var(--accent); color: #17213b; }

        .notice-dialog { width: min(430px, 100%); padding: 28px; text-align: center; }
        .notice-icon { display: grid; place-items: center; width: 58px; height: 58px; margin: 0 auto 16px; border-radius: 18px; background: rgba(76, 137, 255, 0.14); color: #8ab3ff; font-size: 1.55rem; }
        .notice-dialog h3 { margin: 0 0 9px; }
        .notice-dialog p { margin: 0; color: var(--muted); font-size: 0.86rem; line-height: 1.65; }
        .notice-dialog .dialog-actions { justify-content: center; margin: 22px -28px -28px; }
        .timer { display: block; margin: 14px 0 2px; color: var(--accent); font-size: 2.15rem; font-weight: 850; letter-spacing: 0.06em; }

        .forgot-dialog { width: min(510px, 100%); }
        .forgot-body { padding: 8px 22px 24px; }
        .forgot-step[hidden] { display: none !important; }

        .forgot-alert {
            display: none;
            margin-bottom: 14px;
            padding: 11px 13px;
            border: 1px solid;
            border-radius: 12px;
            font-size: 0.78rem;
            line-height: 1.5;
        }

        .forgot-alert.show { display: block; }
        .forgot-alert.error { color: var(--danger); background: var(--danger-bg); border-color: rgba(255, 130, 130, 0.28); }
        .forgot-alert.success { color: var(--success); background: var(--success-bg); border-color: rgba(121, 230, 154, 0.27); }

        .forgot-field { margin-bottom: 13px; }
        .forgot-field label { display: block; margin-bottom: 7px; color: #dce7fb; font-size: 0.78rem; font-weight: 700; }
        .forgot-field .input-wrap input { padding-left: 16px; }
        .forgot-field .input-wrap.has-toggle input { padding-right: 48px; }

        .forgot-submit {
            width: 100%;
            min-height: 48px;
            margin-top: 3px;
            border: 0;
            border-radius: 14px;
            background: linear-gradient(135deg, var(--accent), var(--accent-strong));
            color: #17213b;
            font-weight: 850;
            cursor: pointer;
        }

        .forgot-submit:disabled, .forgot-link-btn:disabled { cursor: wait; opacity: 0.62; }
        .forgot-account-list { display: grid; gap: 9px; margin-bottom: 15px; }

        .forgot-account-option {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 12px 13px;
            border: 1px solid var(--line);
            border-radius: 13px;
            background: rgba(4, 14, 39, 0.62);
            color: var(--text);
            cursor: pointer;
        }

        .forgot-account-option:has(input:checked) { border-color: var(--accent); background: rgba(247, 202, 132, 0.09); }
        .forgot-account-option input { accent-color: var(--accent-strong); }
        .forgot-account-option .account-business { display: block; margin-top: 3px; color: var(--muted); font-size: 0.7rem; }

        .forgot-email-note { margin: 0 0 16px; color: var(--muted); font-size: 0.8rem; line-height: 1.5; }
        .forgot-email-note strong { color: var(--text); overflow-wrap: anywhere; }

        .forgot-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-top: 16px;
            padding-top: 14px;
            border-top: 1px solid var(--line);
            color: var(--muted);
            font-size: 0.75rem;
        }

        .forgot-link-btn {
            padding: 0;
            border: 0;
            background: transparent;
            color: #b8c9ea;
            font-weight: 750;
            cursor: pointer;
        }

        .forgot-link-btn:hover, .forgot-link-btn:focus-visible { color: white; text-decoration: underline; outline: none; }
        .forgot-otp-input { letter-spacing: 0.35em; text-align: center; font-size: 1.1rem; font-weight: 800; }

        @media (max-width: 820px) {
            .admin-page { padding: 18px; }
            .login-panel { padding: 34px 32px 30px; }
        }

        @media (max-width: 560px) {
            .admin-page { padding: 14px; }
            .admin-shell { border-radius: 20px; }
            .login-panel { padding: 28px 20px 24px; }
            .login-actions { align-items: flex-start; flex-direction: column; gap: 8px; }
            .captcha-grid { gap: 5px; padding-inline: 15px; }
            .dialog-header, .dialog-actions { padding-left: 16px; padding-right: 16px; }
            .forgot-body { padding-left: 16px; padding-right: 16px; }
            .forgot-footer { align-items: flex-start; flex-direction: column; }
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { scroll-behavior: auto !important; transition-duration: 0.01ms !important; }
        }
    </style>
</head>
<body>
<main class="admin-page">
    <section class="admin-shell" aria-label="NexGen administrator authentication">
        <div class="login-panel">
            <div class="login-content">
                <h1 class="login-kicker">Administrator portal</h1>
            
                <?php if ($flashMessage !== ''): ?>
                    <div class="flash <?php echo e($flashType); ?>" role="alert">
                        <span aria-hidden="true"><?php echo $flashType === 'success' ? '✓' : '!'; ?></span>
                        <span><?php echo e($flashMessage); ?></span>
                    </div>
                <?php endif; ?>

                <form action="<?php echo e(nxAppUrl('login_process.php')); ?>" method="POST" id="adminLoginForm" novalidate>
                    <input type="hidden" name="login_portal" value="admin">
                    <input type="hidden" name="csrf_token" value="<?php echo e($adminCsrfToken); ?>">

                    <div class="field">
                        
                        <div class="input-wrap">
                            <span class="field-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.5"></circle><path d="M4.5 20c1.2-3.8 4.1-5.8 7.5-5.8s6.3 2 7.5 5.8"></path></svg>
                            </span>
                            <input type="text" name="username" id="adminUsername" required maxlength="50" autocomplete="username" placeholder="Enter administrator username" value="<?php echo e($lockoutUsername); ?>">
                        </div>
                    </div>

                    <div class="field">
                      
                        <div class="input-wrap">
                            <span class="field-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="10" rx="2"></rect><path d="M8 10V7.5a4 4 0 0 1 8 0V10"></path></svg>
                            </span>
                            <input type="password" name="password" id="adminPassword" required maxlength="128" autocomplete="current-password" placeholder="Enter your password">
                            <button class="password-toggle" type="button" id="passwordToggle" aria-label="Show password" aria-pressed="false">◉</button>
                        </div>
                    </div>

                    <button class="captcha-control" type="button" id="captchaTrigger" aria-haspopup="dialog" aria-controls="captchaDialog">
                        <span class="captcha-left">
                            <span class="captcha-check" aria-hidden="true">✓</span>
                            <span class="captcha-copy">
                                <strong id="captchaStatus">Verify you are human</strong>
                                <small>Complete the image challenge</small>
                            </span>
                        </span>
                        <span class="captcha-shield" aria-hidden="true">◆</span>
                    </button>

                    <div class="login-actions">
                        <button class="text-link" type="button" id="adminForgotTrigger">Forgot password?</button>
                        <a class="text-link" href="<?php echo e(nxAppUrl('admin_unlock_otp.php')); ?>">Unlock administrator account</a>
                    </div>

                    <button class="submit-btn" type="submit" id="adminSubmit">Continue to Admin Dashboard</button>
                </form>
                                <p class="portal-note">Not an administrator? <a href="<?php echo e(NEXGEN_CLIENT_LOGIN_PATH); ?>">Return to the client portal</a>.</p>
            </div>
        </div>
    </section>
</main>

<div class="overlay" id="adminForgotDialog" role="dialog" aria-modal="true" aria-labelledby="adminForgotTitle" hidden>
    <div class="dialog forgot-dialog">
        <div class="dialog-header">
            <div>
                <h3 id="adminForgotTitle">Administrator password recovery</h3>
                <p>Reset access for a NexGen system administrator account.</p>
            </div>
            <button class="dialog-close" type="button" id="adminForgotClose" aria-label="Close password recovery">×</button>
        </div>

        <div class="forgot-body">
            <section class="forgot-step" id="adminForgotEmailStep">
                <div class="forgot-alert" id="adminForgotEmailAlert" role="status" aria-live="polite"></div>
                <form id="adminForgotEmailForm" novalidate>
                    <div class="forgot-field">
                        <label for="adminForgotEmail">Administrator email</label>
                        <div class="input-wrap">
                            <input type="email" id="adminForgotEmail" maxlength="254" autocomplete="email" placeholder="Enter your administrator email" required>
                        </div>
                    </div>
                    <button class="forgot-submit" type="submit" id="adminForgotEmailSubmit">Continue</button>
                </form>
                <div class="forgot-footer">
                    <span>Restricted to system administrators.</span>
                    <button class="forgot-link-btn" type="button" id="adminForgotBackToLogin">Back to login</button>
                </div>
            </section>

            <section class="forgot-step" id="adminForgotSelectStep" hidden>
                <p class="forgot-email-note">Email ownership is verified. Choose the administrator account to reset.</p>
                <div class="forgot-alert" id="adminForgotSelectAlert" role="status" aria-live="polite"></div>
                <form id="adminForgotSelectForm" novalidate>
                    <div class="forgot-account-list" id="adminForgotAccountList"></div>
                    <button class="forgot-submit" type="submit" id="adminForgotSelectSubmit">Choose account</button>
                </form>
                <div class="forgot-footer">
                    <span>Only eligible administrator accounts are listed.</span>
                    <button class="forgot-link-btn" type="button" data-admin-forgot-restart>Start over</button>
                </div>
            </section>

            <section class="forgot-step" id="adminForgotOtpStep" hidden>
                <p class="forgot-email-note">If an account matches, an OTP will be sent to the email address entered.</p>
                <div class="forgot-alert" id="adminForgotOtpAlert" role="status" aria-live="polite"></div>
                <form id="adminForgotResetForm" novalidate>
                    <div class="forgot-field">
                        <label for="adminForgotOtp">One-time password</label>
                        <div class="input-wrap">
                            <input class="forgot-otp-input" type="text" id="adminForgotOtp" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" placeholder="000000" required>
                        </div>
                    </div>
                    <div class="forgot-field">
                        <label for="adminForgotNewPassword">New password</label>
                        <div class="input-wrap">
                            <input type="password" id="adminForgotNewPassword" minlength="12" maxlength="64" autocomplete="new-password" placeholder="Create a strong password" required>
                        </div>
                    </div>
                    <div class="forgot-field">
                        <label for="adminForgotConfirmPassword">Confirm new password</label>
                        <div class="input-wrap">
                            <input type="password" id="adminForgotConfirmPassword" minlength="12" maxlength="64" autocomplete="new-password" placeholder="Repeat your new password" required>
                        </div>
                    </div>
                    <button class="forgot-submit" type="submit" id="adminForgotResetSubmit">Reset password</button>
                </form>
                <div class="forgot-footer">
                    <button class="forgot-link-btn" type="button" id="adminForgotResend">Resend OTP</button>
                    <button class="forgot-link-btn" type="button" data-admin-forgot-restart>Start over</button>
                </div>
            </section>
        </div>
    </div>
</div>

<div class="overlay" id="captchaDialog" role="dialog" aria-modal="true" aria-labelledby="captchaTitle" hidden>
    <div class="dialog">
        <div class="dialog-header">
            <div>
                <h3 id="captchaTitle">Security verification</h3>
                <?php if (!empty($adminCaptcha['success'])): ?>
                    <p>Select every image containing <strong><?php echo e($adminCaptcha['target_label']); ?></strong>.</p>
                <?php else: ?>
                    <p><?php echo e($adminCaptcha['message'] ?? 'The security challenge is unavailable.'); ?></p>
                <?php endif; ?>
            </div>
            <button class="dialog-close" type="button" id="captchaClose" aria-label="Close security verification">×</button>
        </div>

        <?php if (!empty($adminCaptcha['success'])): ?>
            <div class="captcha-grid">
                <?php foreach ($adminCaptcha['items'] as $item): ?>
                    <label class="captcha-tile">
                        <input type="checkbox" name="captcha_selection[]" value="<?php echo e($item['id']); ?>" form="adminLoginForm">
                        <img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==" data-captcha-src="<?php echo e($item['relative_path']); ?>" alt="CAPTCHA option">
                        <span aria-hidden="true">✓</span>
                    </label>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="dialog-actions">
            <button class="dialog-btn" type="button" id="captchaCancel">Cancel</button>
            <button class="dialog-btn primary" type="button" id="captchaVerify" <?php echo empty($adminCaptcha['success']) ? 'disabled' : ''; ?>>Verify selection</button>
        </div>
    </div>
</div>

<div class="overlay" id="noticeDialog" role="alertdialog" aria-modal="true" aria-labelledby="noticeTitle" hidden>
    <div class="dialog notice-dialog">
        <div class="notice-icon" aria-hidden="true">!</div>
        <h3 id="noticeTitle">Security check required</h3>
        <p id="noticeMessage">Complete the CAPTCHA before signing in.</p>
        <div class="dialog-actions">
            <button class="dialog-btn primary" type="button" id="noticeOkay">OK</button>
        </div>
    </div>
</div>

<div class="overlay" id="lockoutDialog" role="alertdialog" aria-modal="true" aria-labelledby="lockoutTitle" hidden>
    <div class="dialog notice-dialog">
        <div class="notice-icon" aria-hidden="true">⌛</div>
        <h3 id="lockoutTitle">Administrator account temporarily locked</h3>
        <p id="lockoutMessage">Please wait until the cooldown ends before trying this account again.</p>
        <strong class="timer" id="lockoutTimer">00:00</strong>
        <div class="dialog-actions">
            <button class="dialog-btn" type="button" id="lockoutBack">Use another account</button>
            <a class="dialog-btn primary" href="<?php echo e(nxAppUrl('admin_unlock_otp.php')); ?>" style="display:inline-grid;place-items:center;text-decoration:none;">Unlock with OTP</a>
        </div>
    </div>
</div>

<div class="overlay" id="attemptDialog" role="alertdialog" aria-modal="true" aria-labelledby="attemptTitle" hidden>
    <div class="dialog notice-dialog">
        <div class="notice-icon" aria-hidden="true">!</div>
        <h3 id="attemptTitle">Login attempt unsuccessful</h3>
        <p id="attemptMessage"></p>
        <div class="dialog-actions">
            <button class="dialog-btn primary" type="button" id="attemptOkay">Try again</button>
        </div>
    </div>
</div>

<script>
(() => {
    'use strict';

    const form = document.getElementById('adminLoginForm');
    const username = document.getElementById('adminUsername');
    const password = document.getElementById('adminPassword');
    const passwordToggle = document.getElementById('passwordToggle');
    const captchaTrigger = document.getElementById('captchaTrigger');
    const captchaStatus = document.getElementById('captchaStatus');
    const captchaDialog = document.getElementById('captchaDialog');
    const captchaClose = document.getElementById('captchaClose');
    const captchaCancel = document.getElementById('captchaCancel');
    const captchaVerify = document.getElementById('captchaVerify');
    const noticeDialog = document.getElementById('noticeDialog');
    const noticeMessage = document.getElementById('noticeMessage');
    const noticeOkay = document.getElementById('noticeOkay');
    const lockoutDialog = document.getElementById('lockoutDialog');
    const lockoutTimer = document.getElementById('lockoutTimer');
    const lockoutMessage = document.getElementById('lockoutMessage');
    const lockoutBack = document.getElementById('lockoutBack');
    const attemptDialog = document.getElementById('attemptDialog');
    const attemptMessage = document.getElementById('attemptMessage');
    const attemptOkay = document.getElementById('attemptOkay');
    const adminForgotTrigger = document.getElementById('adminForgotTrigger');
    const adminForgotDialog = document.getElementById('adminForgotDialog');
    const adminForgotClose = document.getElementById('adminForgotClose');
    const adminForgotBackToLogin = document.getElementById('adminForgotBackToLogin');
    const adminForgotEmailForm = document.getElementById('adminForgotEmailForm');
    const adminForgotEmail = document.getElementById('adminForgotEmail');
    const adminForgotEmailAlert = document.getElementById('adminForgotEmailAlert');
    const adminForgotEmailSubmit = document.getElementById('adminForgotEmailSubmit');
    const adminForgotSelectForm = document.getElementById('adminForgotSelectForm');
    const adminForgotAccountList = document.getElementById('adminForgotAccountList');
    const adminForgotSelectAlert = document.getElementById('adminForgotSelectAlert');
    const adminForgotSelectSubmit = document.getElementById('adminForgotSelectSubmit');
    const adminForgotResetForm = document.getElementById('adminForgotResetForm');
    const adminForgotOtp = document.getElementById('adminForgotOtp');
    const adminForgotNewPassword = document.getElementById('adminForgotNewPassword');
    const adminForgotConfirmPassword = document.getElementById('adminForgotConfirmPassword');
    const adminForgotOtpAlert = document.getElementById('adminForgotOtpAlert');
    const adminForgotResetSubmit = document.getElementById('adminForgotResetSubmit');
    const adminForgotResend = document.getElementById('adminForgotResend');
    const adminForgotSteps = {
        email: document.getElementById('adminForgotEmailStep'),
        select: document.getElementById('adminForgotSelectStep'),
        otp: document.getElementById('adminForgotOtpStep')
    };

    const adminForgotEndpoints = Object.freeze({
        lookup: <?php echo json_encode(nxAppUrl('forgot_password_lookup.php'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        sendOtp: <?php echo json_encode(nxAppUrl('send_forgot_otp.php'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        reset: <?php echo json_encode(nxAppUrl('process_reset_password.php'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
    });
    const adminForgotPortal = 'admin';

    let captchaVerified = false;
    let lastFocusedElement = null;
    let adminForgotCooldownInterval = null;

    const lockoutActive = <?php echo $showLockCountdown ? 'true' : 'false'; ?>;
    const lockoutUntil = <?php echo (int)$lockoutUntilTs; ?>;
    const lockedUsername = <?php echo json_encode($lockoutUsername, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const showAttempt = <?php echo $showAttemptPopup ? 'true' : 'false'; ?>;
    const attemptText = <?php echo json_encode($attemptPopupText, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const attemptsLeft = <?php echo $attemptsLeft === null ? 'null' : (int)$attemptsLeft; ?>;

    function openDialog(dialog) {
        if (!dialog) return;
        lastFocusedElement = document.activeElement;
        dialog.hidden = false;
        document.body.style.overflow = 'hidden';
        const focusable = dialog.querySelector('button, a, input');
        if (focusable) focusable.focus();
    }

    function closeDialog(dialog) {
        if (!dialog) return;
        dialog.hidden = true;
        document.body.style.overflow = document.querySelector('.overlay:not([hidden])') ? 'hidden' : '';
        if (lastFocusedElement && typeof lastFocusedElement.focus === 'function') {
            lastFocusedElement.focus();
        }
    }

    function showAdminForgotStep(name) {
        Object.keys(adminForgotSteps).forEach((key) => {
            if (adminForgotSteps[key]) adminForgotSteps[key].hidden = key !== name;
        });

        const focusTarget = name === 'email' ? adminForgotEmail : (name === 'otp' ? adminForgotOtp : null);
        if (focusTarget) window.setTimeout(() => focusTarget.focus(), 50);
    }

    function setAdminForgotAlert(element, message, type) {
        if (!element) return;
        element.textContent = message;
        element.className = 'forgot-alert show ' + type;
    }

    function clearAdminForgotAlert(element) {
        if (!element) return;
        element.textContent = '';
        element.className = 'forgot-alert';
    }

    function resetAdminForgotWizard() {
        if (adminForgotCooldownInterval !== null) {
            window.clearInterval(adminForgotCooldownInterval);
            adminForgotCooldownInterval = null;
        }
        if (adminForgotEmailForm) adminForgotEmailForm.reset();
        if (adminForgotSelectForm) adminForgotSelectForm.reset();
        if (adminForgotResetForm) adminForgotResetForm.reset();
        if (adminForgotAccountList) adminForgotAccountList.replaceChildren();
        clearAdminForgotAlert(adminForgotEmailAlert);
        clearAdminForgotAlert(adminForgotSelectAlert);
        clearAdminForgotAlert(adminForgotOtpAlert);
        if (adminForgotResend) {
            adminForgotResend.disabled = false;
            adminForgotResend.textContent = 'Resend OTP';
        }
        showAdminForgotStep('email');
    }

    function startAdminForgotCooldown(seconds) {
        if (!adminForgotResend) return;
        if (adminForgotCooldownInterval !== null) window.clearInterval(adminForgotCooldownInterval);

        let remaining = Math.max(0, Number.parseInt(seconds, 10) || 0);
        if (remaining === 0) {
            adminForgotResend.disabled = false;
            adminForgotResend.textContent = 'Resend OTP';
            return;
        }

        adminForgotResend.disabled = true;
        adminForgotResend.textContent = 'Resend OTP (' + remaining + 's)';
        adminForgotCooldownInterval = window.setInterval(() => {
            remaining -= 1;
            if (remaining <= 0) {
                window.clearInterval(adminForgotCooldownInterval);
                adminForgotCooldownInterval = null;
                adminForgotResend.disabled = false;
                adminForgotResend.textContent = 'Resend OTP';
                return;
            }
            adminForgotResend.textContent = 'Resend OTP (' + remaining + 's)';
        }, 1000);
    }

    function renderAdminForgotAccounts(candidates) {
        if (!adminForgotAccountList) return;
        adminForgotAccountList.replaceChildren();

        candidates.forEach((candidate) => {
            const label = document.createElement('label');
            label.className = 'forgot-account-option';

            const input = document.createElement('input');
            input.type = 'radio';
            input.name = 'selected_user_id';
            input.value = String(Number(candidate.id));
            input.required = true;

            const copy = document.createElement('span');
            copy.textContent = candidate.masked_username || 'Administrator account';

            if (candidate.business_name) {
                const business = document.createElement('span');
                business.className = 'account-business';
                business.textContent = candidate.business_name;
                copy.appendChild(business);
            }

            label.append(input, copy);
            adminForgotAccountList.appendChild(label);
        });
    }

    function adminForgotPost(url, formData) {
        return fetch(url, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        }).then((response) => {
            if (!response.ok) throw new Error('Password recovery request failed.');
            return response.json();
        });
    }

    function requestAdminForgotOtp(formData, alertElement) {
        if (!formData.has('portal')) formData.append('portal', adminForgotPortal);

        return adminForgotPost(adminForgotEndpoints.sendOtp, formData)
            .then((data) => {
                if (!data.success) {
                    if (data.restart) {
                        resetAdminForgotWizard();
                        setAdminForgotAlert(adminForgotEmailAlert, data.message || 'Please start again.', 'error');
                        return;
                    }
                    if (typeof data.cooldown_remaining === 'number') {
                        showAdminForgotStep('otp');
                        setAdminForgotAlert(adminForgotOtpAlert, data.message || 'Please wait before requesting another OTP.', 'error');
                        startAdminForgotCooldown(data.cooldown_remaining);
                        return;
                    }
                    setAdminForgotAlert(alertElement, data.message || 'Unable to send the OTP.', 'error');
                    return;
                }

                if (data.account_selected) {
                    clearAdminForgotAlert(adminForgotOtpAlert);
                    showAdminForgotStep('otp');
                    return data;
                }
                clearAdminForgotAlert(adminForgotOtpAlert);
                showAdminForgotStep('otp');
                startAdminForgotCooldown(data.cooldown || 60);
                return data;
            })
            .catch(() => {
                setAdminForgotAlert(alertElement, 'Something went wrong. Please try again.', 'error');
            });
    }

    function loadCaptchaImages() {
        if (!captchaDialog) return;
        captchaDialog.querySelectorAll('img[data-captcha-src]').forEach((image) => {
            if (image.dataset.loaded === '1') return;
            image.src = image.dataset.captchaSrc;
            image.dataset.loaded = '1';
        });
    }

    function resetCaptchaSelection() {
        if (!captchaDialog) return;
        captchaDialog.querySelectorAll('input[type="checkbox"]').forEach((box) => {
            box.checked = false;
        });
        captchaVerified = false;
        captchaTrigger.classList.remove('is-verified');
        captchaStatus.textContent = 'Verify you are human';
    }

    function currentUsernameIsLocked() {
        if (!lockoutActive || !lockedUsername || !username) return false;
        if (lockoutUntil <= Math.floor(Date.now() / 1000)) return false;
        return username.value.trim().toLowerCase() === lockedUsername.toLowerCase();
    }

    function showNotice(message) {
        if (noticeMessage) noticeMessage.textContent = message;
        openDialog(noticeDialog);
    }

    if (passwordToggle && password) {
        passwordToggle.addEventListener('click', () => {
            const reveal = password.type === 'password';
            password.type = reveal ? 'text' : 'password';
            passwordToggle.setAttribute('aria-pressed', reveal ? 'true' : 'false');
            passwordToggle.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');
        });
    }

    if (adminForgotTrigger) {
        adminForgotTrigger.addEventListener('click', () => {
            resetAdminForgotWizard();
            openDialog(adminForgotDialog);
        });
    }

    if (adminForgotClose) {
        adminForgotClose.addEventListener('click', () => {
            closeDialog(adminForgotDialog);
            resetAdminForgotWizard();
        });
    }

    if (adminForgotBackToLogin) {
        adminForgotBackToLogin.addEventListener('click', () => {
            closeDialog(adminForgotDialog);
            resetAdminForgotWizard();
            if (username) username.focus();
        });
    }

    document.querySelectorAll('[data-admin-forgot-restart]').forEach((button) => {
        button.addEventListener('click', resetAdminForgotWizard);
    });

    if (adminForgotOtp) {
        adminForgotOtp.addEventListener('input', () => {
            adminForgotOtp.value = adminForgotOtp.value.replace(/[^0-9]/g, '').slice(0, 6);
        });
    }

    if (adminForgotEmailForm) {
        adminForgotEmailForm.addEventListener('submit', (event) => {
            event.preventDefault();
            clearAdminForgotAlert(adminForgotEmailAlert);

            if (!adminForgotEmailForm.checkValidity()) {
                adminForgotEmailForm.reportValidity();
                return;
            }

            const formData = new FormData();
            formData.append('email', adminForgotEmail.value.trim());
            formData.append('portal', adminForgotPortal);
            adminForgotEmailSubmit.disabled = true;
            adminForgotEmailSubmit.textContent = 'Please wait...';

            adminForgotPost(adminForgotEndpoints.lookup, formData)
                .then((data) => {
                    if (!data.success) {
                        setAdminForgotAlert(adminForgotEmailAlert, data.message || 'Unable to look up that account.', 'error');
                        return;
                    }
                    setAdminForgotAlert(adminForgotEmailAlert, data.message || 'If an administrator account matches that email, an OTP will be sent.', 'success');
                    return requestAdminForgotOtp(new FormData(), adminForgotEmailAlert);
                })
                .catch(() => {
                    setAdminForgotAlert(adminForgotEmailAlert, 'Something went wrong. Please try again.', 'error');
                })
                .finally(() => {
                    adminForgotEmailSubmit.disabled = false;
                    adminForgotEmailSubmit.textContent = 'Continue';
                });
        });
    }

    if (adminForgotSelectForm) {
        adminForgotSelectForm.addEventListener('submit', (event) => {
            event.preventDefault();
            clearAdminForgotAlert(adminForgotSelectAlert);

            const selected = adminForgotSelectForm.querySelector('input[name="selected_user_id"]:checked');
            if (!selected) {
                setAdminForgotAlert(adminForgotSelectAlert, 'Please select an administrator account.', 'error');
                return;
            }

            const formData = new FormData();
            formData.append('selected_user_id', selected.value);
            adminForgotSelectSubmit.disabled = true;
            adminForgotSelectSubmit.textContent = 'Please wait...';

            requestAdminForgotOtp(formData, adminForgotSelectAlert).then((data) => {
                if (data && data.account_selected && adminForgotResetForm) {
                    adminForgotResetForm.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
                }
            }).finally(() => {
                adminForgotSelectSubmit.disabled = false;
                adminForgotSelectSubmit.textContent = 'Choose account';
            });
        });
    }

    if (adminForgotResetForm) {
        adminForgotResetForm.addEventListener('submit', (event) => {
            event.preventDefault();
            clearAdminForgotAlert(adminForgotOtpAlert);

            if (!adminForgotResetForm.checkValidity()) {
                adminForgotResetForm.reportValidity();
                return;
            }
            if (adminForgotNewPassword.value !== adminForgotConfirmPassword.value) {
                setAdminForgotAlert(adminForgotOtpAlert, 'Passwords do not match.', 'error');
                return;
            }

            const formData = new FormData();
            formData.append('otp_code', adminForgotOtp.value.trim());
            formData.append('new_password', adminForgotNewPassword.value);
            formData.append('confirm_new_password', adminForgotConfirmPassword.value);
            formData.append('portal', adminForgotPortal);
            adminForgotResetSubmit.disabled = true;
            adminForgotResetSubmit.textContent = 'Please wait...';

            adminForgotPost(adminForgotEndpoints.reset, formData)
                .then((data) => {
                    if (data.selection_required) {
                        renderAdminForgotAccounts(Array.isArray(data.candidates) ? data.candidates : []);
                        setAdminForgotAlert(adminForgotSelectAlert, data.message || 'Email ownership verified. Choose which account to reset.', 'success');
                        showAdminForgotStep('select');
                        return;
                    }
                    if (!data.success) {
                        if (data.restart) {
                            resetAdminForgotWizard();
                            setAdminForgotAlert(adminForgotEmailAlert, data.message || 'Please start again.', 'error');
                            return;
                        }
                        setAdminForgotAlert(adminForgotOtpAlert, data.message || 'Unable to reset the password.', 'error');
                        return;
                    }

                    setAdminForgotAlert(adminForgotOtpAlert, data.message || 'Password reset successful. You may now log in.', 'success');
                    window.setTimeout(() => {
                        closeDialog(adminForgotDialog);
                        resetAdminForgotWizard();
                        if (username) username.focus();
                    }, 1200);
                })
                .catch(() => {
                    setAdminForgotAlert(adminForgotOtpAlert, 'Something went wrong. Please try again.', 'error');
                })
                .finally(() => {
                    adminForgotResetSubmit.disabled = false;
                    adminForgotResetSubmit.textContent = 'Reset password';
                });
        });
    }

    if (adminForgotResend) {
        adminForgotResend.addEventListener('click', () => {
            if (adminForgotResend.disabled) return;
            clearAdminForgotAlert(adminForgotOtpAlert);
            requestAdminForgotOtp(new FormData(), adminForgotOtpAlert);
        });
    }

    if (captchaTrigger) {
        captchaTrigger.addEventListener('click', () => {
            if (currentUsernameIsLocked()) {
                openDialog(lockoutDialog);
                return;
            }
            loadCaptchaImages();
            openDialog(captchaDialog);
        });
    }

    if (captchaClose) captchaClose.addEventListener('click', () => { resetCaptchaSelection(); closeDialog(captchaDialog); });
    if (captchaCancel) captchaCancel.addEventListener('click', () => { resetCaptchaSelection(); closeDialog(captchaDialog); });

    if (captchaDialog) {
        captchaDialog.addEventListener('change', (event) => {
            if (!event.target.matches('input[type="checkbox"]')) return;
            captchaVerified = false;
            captchaTrigger.classList.remove('is-verified');
            captchaStatus.textContent = 'Verify your selection';
        });
    }

    if (captchaVerify) {
        captchaVerify.addEventListener('click', () => {
            const checked = captchaDialog.querySelectorAll('input[type="checkbox"]:checked').length;
            if (checked < 1) {
                showNotice('Select the requested CAPTCHA images before continuing.');
                return;
            }
            captchaVerified = true;
            captchaTrigger.classList.add('is-verified');
            captchaStatus.textContent = 'Security check completed';
            closeDialog(captchaDialog);
        });
    }

    if (noticeOkay) noticeOkay.addEventListener('click', () => closeDialog(noticeDialog));
    if (lockoutBack) {
        lockoutBack.addEventListener('click', () => {
            const stillLocked = currentUsernameIsLocked();
            closeDialog(lockoutDialog);
            if (stillLocked && username) username.value = '';
            if (username) username.focus();
        });
    }
    if (attemptOkay) attemptOkay.addEventListener('click', () => closeDialog(attemptDialog));

    if (form) {
        form.addEventListener('submit', (event) => {
            if (!form.checkValidity()) {
                event.preventDefault();
                form.reportValidity();
                return;
            }
            if (currentUsernameIsLocked()) {
                event.preventDefault();
                openDialog(lockoutDialog);
                return;
            }
            if (!captchaVerified) {
                event.preventDefault();
                showNotice('Complete the CAPTCHA before signing in.');
            }
        });
    }

    if (username) {
        username.addEventListener('input', () => {
            if (!currentUsernameIsLocked() && lockoutDialog && !lockoutDialog.hidden) {
                closeDialog(lockoutDialog);
            }
        });
    }

    if (lockoutActive && lockoutUntil > 0) {
        if (currentUsernameIsLocked()) openDialog(lockoutDialog);

        let timerInterval = null;
        const updateTimer = () => {
            const remaining = Math.max(0, lockoutUntil - Math.floor(Date.now() / 1000));
            const minutes = Math.floor(remaining / 60);
            const seconds = remaining % 60;
            if (lockoutTimer) lockoutTimer.textContent = String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');

            if (remaining <= 0) {
                if (timerInterval !== null) window.clearInterval(timerInterval);
                if (lockoutMessage) lockoutMessage.textContent = 'The cooldown has ended. You may close this message and try again.';
                if (lockoutBack) lockoutBack.textContent = 'Try login again';
            }
        };

        updateTimer();
        if (lockoutUntil > Math.floor(Date.now() / 1000)) {
            timerInterval = window.setInterval(updateTimer, 1000);
        }
    }

    if (showAttempt && attemptDialog) {
        if (attemptMessage) {
            attemptMessage.textContent = attemptText || (attemptsLeft === null
                ? 'The administrator login attempt was unsuccessful.'
                : String(attemptsLeft) + ' attempt(s) remain.');
        }
        openDialog(attemptDialog);
    }

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        [adminForgotDialog, captchaDialog, noticeDialog, attemptDialog].forEach((dialog) => {
            if (dialog && !dialog.hidden) closeDialog(dialog);
        });
    });
})();
</script>
</body>
</html>
