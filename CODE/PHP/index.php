<?php
require_once __DIR__ . '/config.php';

if (isset($_SESSION['user_id'])) {
    enforceSessionTimeout();
} else {
    /* The public landing page always establishes the client recovery context,
       even if the same browser previously visited the administrator portal. */
    $_SESSION['login_portal'] = 'client';
    $_SESSION['fp_portal'] = 'client';
}

/* CAPTCHA: client authentication has its own challenge namespace so a token
   created on this portal cannot be replayed through the administrator portal. */
$loginCaptcha = generateImageCaptcha('client_login_form');
$signupCaptcha = generateImageCaptcha('signup_form');

/* SECURITY: Generate portal-specific CSRF tokens for login and signup forms. */
$loginCsrfToken = generateCsrfToken('client_login_form');
$signupCsrfToken = generateCsrfToken('signup_form');

$_SESSION['signup_privacy_ack_token'] = bin2hex(random_bytes(32));
unset($_SESSION['signup_privacy_acknowledged_token']);
$signupPrivacyAckToken = $_SESSION['signup_privacy_ack_token'];

$popupMessage = "";
$popupType = "";
$openLoginAfterLoad = false;
$openSignupAfterLoad = false;
$openForgotAfterLoad = false;

if (isset($_GET['open']) && $_GET['open'] === 'signup') {
    $openSignupAfterLoad = true;
}

if (isset($_GET['open']) && $_GET['open'] === 'login') {
    $openLoginAfterLoad = true;
}

if (isset($_GET['open']) && $_GET['open'] === 'forgot') {
    $openForgotAfterLoad = true;
}

/* LOCKOUT: Real-time countdown data */
$showLockCountdown = false;
$lockoutUntilTs = 0;
$lockoutUsername = '';
$lockoutUserId = 0;
$lockoutRole = '';
$lockoutIsAdmin = false;

/* ATTEMPT WARNING POPUP */
$showAttemptPopup = false;
$attemptsLeft = null;
$attemptPopupText = '';

if (isset($_SESSION['lockout_until_ts']) && (int)$_SESSION['lockout_until_ts'] > time()) {
    $showLockCountdown = true;
    $lockoutUntilTs = (int)$_SESSION['lockout_until_ts'];
    $lockoutUsername = (string)($_SESSION['lockout_username'] ?? '');
    $lockoutUserId = (int)($_SESSION['lockout_user_id'] ?? 0);
    $lockoutRole = (string)($_SESSION['lockout_role'] ?? '');
    $lockoutIsAdmin = ($lockoutRole === 'system_admin');
    $openLoginAfterLoad = true;
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
    $attemptsLeft = isset($_SESSION['attempts_left']) ? (int)$_SESSION['attempts_left'] : null;
    $attemptPopupText = (string)($_SESSION['error'] ?? 'Your login attempt was not successful.');
    $openLoginAfterLoad = true;
    unset($_SESSION['attempt_warning'], $_SESSION['attempts_left']);
}

if (isset($_SESSION['success'])) {
    $popupMessage = $_SESSION['success'];
    $popupType = "success";
    unset($_SESSION['success']);
}

if (isset($_SESSION['error'])) {
    $sessionError = $_SESSION['error'];

    if (!$showAttemptPopup && !$showLockCountdown) {
        $popupMessage = $sessionError;
        $popupType = "error";
    }

    if (isset($_SESSION['form_type']) && $_SESSION['form_type'] === 'login') {
        $openLoginAfterLoad = true;
    }

    if (isset($_SESSION['form_type']) && $_SESSION['form_type'] === 'signup') {
        $openSignupAfterLoad = true;
    }

    unset($_SESSION['error']);
    unset($_SESSION['form_type']);
}

/* Release PHP's per-session file lock before the browser requests CAPTCHA
   images. Otherwise concurrent image requests queue behind the page request. */
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<?php include __DIR__ . '/nexgen_favicon/favicon.php'; ?>
<?php
include __DIR__ . '/theme_init.php';
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
?>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NexGen — Where The Expertise Creates Excellence</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css">
<style>
  /* ========== CSS VARIABLES ========== */
  :root {
    --bg-deep: #0A1128;
    --bg-mid: #162447;
    --bg-panel: #2438BD;
    --bg-panel-light: #1F4068;
    --accent: #F7CA84;
    --accent-dim: #EAB749;
    --cyan: #007FFF;
    --pink: #70B8FF;
    --purple: #ADFFFF;
    --text-main: #FFFFFF;
    --text-muted: #C8CDD2;
    --text-faint: #7D8C9B;
    --line: rgba(200, 205, 210, 0.1);
    --line-bright: rgba(200, 205, 210, 0.2);
    --radius-lg: 28px;
    --radius-md: 18px;
    --radius-sm: 12px;
    --font-display: 'Sora', sans-serif;
    --font-body: 'Inter', sans-serif;

    /* ---- CAPTCHA (dark / default) ---- */
    --captcha-overlay-bg: rgba(0, 0, 0, 0.52);
    --captcha-box-grad-1: #1f3c88;
    --captcha-box-grad-2: #1a3578;
    --captcha-box-border: rgba(255, 255, 255, 0.12);
    --captcha-text: #ffffff;
    --captcha-text-soft: rgba(255, 255, 255, 0.78);
    --captcha-close-bg: rgba(255, 255, 255, 0.12);
    --captcha-close-bg-hover: rgba(255, 255, 255, 0.22);
    --captcha-item-bg: rgba(255, 255, 255, 0.08);
    --captcha-check-ring: rgba(255, 255, 255, 0.65);
    --captcha-check-bg: rgba(0, 0, 0, 0.55);
    --captcha-error-bg: rgba(255, 95, 95, 0.12);
    --captcha-error-text: #ffd9d9;
    --captcha-cancel-bg: rgba(255, 255, 255, 0.14);
    --captcha-cancel-bg-hover: rgba(255, 255, 255, 0.22);
    --captcha-cancel-text: #ffffff;
    --captcha-verify-bg: #f7d98b;
    --captcha-verify-text: #17306b;
    --captcha-verified-text: #aef3b8;
    --captcha-checkbox-card-bg: rgba(255, 255, 255, 0.08);
    --captcha-checkbox-card-border: rgba(255, 255, 255, 0.12);
    --captcha-checkbox-label: #ffffff;
    --captcha-checkbox-right: rgba(255, 255, 255, 0.72);
    --captcha-notice-overlay-bg: rgba(0, 0, 0, 0.60);
    --captcha-notice-grad-1: #264ba6;
    --captcha-notice-grad-2: #1c3f99;
    --captcha-notice-border: rgba(255, 255, 255, 0.12);
    --captcha-notice-icon-bg: rgba(255, 107, 107, 0.16);
    --captcha-notice-icon-text: #ffd2d2;
    --captcha-notice-text: rgba(255, 255, 255, 0.88);
  }

  * { margin: 0; padding: 0; box-sizing: border-box; }
  html { scroll-behavior: smooth; }
  body {
    background: var(--bg-deep);
    color: var(--text-main);
    font-family: var(--font-body);
    line-height: 1.6;
    overflow-x: hidden;
  }
  img { max-width: 100%; display: block; }
  a { color: inherit; text-decoration: none; }
  ul { list-style: none; }

  .wrap { max-width: 1240px; margin: 0 auto; padding: 0 32px; }

  h1, h2, h3, h4 {
    font-family: var(--font-display);
    font-weight: 700;
    letter-spacing: -0.02em;
    line-height: 1.1;
  }

  /* ========== CURSOR GLOW ========== */
  .cursor-glow {
    position: fixed;
    width: 400px;
    height: 400px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(247, 202, 132,0.06) 0%, transparent 70%);
    pointer-events: none;
    z-index: 9999;
    transform: translate(-50%, -50%);
    transition: opacity 0.3s ease;
    opacity: 0;
  }
  body:hover .cursor-glow { opacity: 1; }

  /* ========== PRELOADER ========== */
  .preloader {
    position: fixed;
    inset: 0;
    background: var(--bg-deep);
    z-index: 100000;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    gap: 20px;
    animation: preloaderAutoUnblock 0s 6s forwards;
  }
  /* Absolute last-resort fallback: even if every script above fails
     (JS disabled, CDN entirely unreachable, etc.), this pure-CSS rule
     stops the preloader from intercepting clicks 6s after page load. */
  @keyframes preloaderAutoUnblock {
    to { pointer-events: none; opacity: 0; visibility: hidden; }
  }
  .preloader-logo {
    font-family: var(--font-display);
    font-weight: 800;
    font-size: 36px;
    color: var(--accent-dim);
    opacity: 0;
  }
  .preloader-bar {
    width: 120px;
    height: 3px;
    background: var(--line);
    border-radius: 3px;
    overflow: hidden;
    opacity: 0;
  }
  .preloader-bar-inner {
    width: 0%;
    height: 100%;
    background: var(--accent);
    border-radius: 3px;
    transition: width 0.3s ease;
  }

  /* ========== NAVBAR ========== */
  header {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: 50;
    transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
  }
  header.scrolled {
    background: rgba(22, 36, 71, 0.92);
    backdrop-filter: blur(20px);
    -webkit-backdrop-filter: blur(20px);
    border-bottom: 1px solid var(--line);
    box-shadow: 0 4px 30px rgba(0, 0, 0, 0.05);
  }
  .nav {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px 32px;
    max-width: 1240px;
    margin: 0 auto;
    transition: padding 0.4s ease;
  }
  header.scrolled .nav { padding: 14px 32px; }

  .logo {
    display: flex;
    align-items: center;
    gap: 10px;
    font-family: var(--font-display);
    font-weight: 800;
    font-size: 22px;
    letter-spacing: -0.01em;
  }
  .logo-img { width: 38px; height: 38px; object-fit: contain; }

  .nav-links {
    display: flex;
    align-items: center;
    gap: 36px;
    font-size: 15px;
    font-weight: 500;
    color: var(--text-muted);
  }
  .nav-links a {
    position: relative;
    transition: color 0.3s ease;
  }
  .nav-links a::after {
    content: '';
    position: absolute;
    bottom: -4px;
    left: 0;
    width: 0;
    height: 2px;
    background: var(--accent);
    transition: width 0.3s cubic-bezier(0.16, 1, 0.3, 1);
  }
  .nav-links a:hover { color: var(--text-main); }
  .nav-links a:hover::after { width: 100%; }
  .nav-links .has-dropdown { display: flex; align-items: center; gap: 5px; position: relative; cursor: pointer; }
  .dropdown-menu {
    position: absolute;
    top: 100%;
    left: 50%;
    transform: translateX(-50%) translateY(10px);
    background: var(--bg-mid);
    border: 1px solid var(--line);
    border-radius: var(--radius-sm);
    padding: 15px;
    display: flex;
    gap: 15px;
    opacity: 0;
    visibility: hidden;
    transition: all 0.3s ease;
    box-shadow: 0 10px 30px rgba(0,0,0,0.3);
    z-index: 100;
  }
  .has-dropdown:hover .dropdown-menu,
  .has-dropdown.active .dropdown-menu {
    opacity: 1;
    visibility: visible;
    transform: translateX(-50%) translateY(0);
  }
  .dropdown-menu a {
    color: var(--text-muted);
    font-size: 20px;
    transition: color 0.3s ease, transform 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .dropdown-menu a:hover {
    color: var(--accent);
    transform: translateY(-3px);
  }
  .dropdown-menu a::after { display: none !important; }

  .nav-cta { display: flex; align-items: center; gap: 18px; }

.landing-theme-toggle {
  width: 44px;
  height: 44px;
  border-radius: 50%;
  border: 1px solid var(--line-bright);
  background: rgba(200, 205, 210, 0.06);
  color: var(--text-main);
  font-size: 16px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: 0.2s ease;
}
.landing-theme-toggle:hover {
  background: var(--accent);
  color: #0A1128;
  border-color: var(--accent);
}

  .nav-toggle {
    display: none;
    background: none;
    border: none;
    color: var(--text-main);
    font-size: 26px;
    cursor: pointer;
  }

  /* ========== BUTTONS ========== */
  .btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 16px 30px;
    border-radius: 999px;
    font-family: var(--font-display);
    font-weight: 600;
    font-size: 15px;
    border: none;
    cursor: pointer;
    position: relative;
    overflow: hidden;
    transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.3s ease, background 0.3s ease;
  }
  .btn::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, rgba(255,255,255,0.15), transparent);
    opacity: 0;
    transition: opacity 0.3s ease;
  }
  .btn:hover::before { opacity: 1; }

  .btn-primary {
    background: var(--accent);
    color: #0A1128;
    box-shadow: 0 10px 30px -8px rgba(247, 202, 132,0.4);
  }
  .btn-primary:hover {
    transform: translateY(-3px) scale(1.02);
    box-shadow: 0 20px 50px -10px rgba(247, 202, 132,0.5);
  }
  .btn-primary:active { transform: translateY(-1px) scale(0.98); }

  .btn-ghost {
    background: transparent;
    color: var(--text-main);
    border: 1px solid var(--line);
  }
  .btn-ghost:hover {
    background: rgba(200, 205, 210,0.06);
    border-color: rgba(200, 205, 210,0.2);
    transform: translateY(-2px);
  }

  .btn-glow {
    background: var(--accent);
    color: #0A1128;
    box-shadow: 0 0 0 0 rgba(247, 202, 132,0.4);
    animation: btnPulse 2.5s ease-in-out infinite;
    transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.3s ease, background 0.3s ease;
  }
  .btn-glow:hover {
    transform: translateY(-3px) scale(1.02);
    box-shadow: 0 20px 50px -10px rgba(247, 202, 132,0.5);
  }
  @keyframes btnPulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(247, 202, 132,0.4); }
    50% { box-shadow: 0 0 0 12px rgba(247, 202, 132,0); }
  }

  /* ========== EYEBROW ========== */
  .eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--accent);
  }
  .eyebrow::before {
    content: "";
    width: 20px; height: 2px;
    background: var(--accent);
    display: inline-block;
    border-radius: 2px;
  }

  /* ========== HERO ========== */
  .hero {
    position: relative;
    padding: 140px 0 100px;
    min-height: 100vh;
    display: flex;
    align-items: center;
    overflow: hidden;
  }

  .hero-bg {
    position: absolute;
    inset: 0;
    overflow: hidden;
  }
  .hero-bg .gradient-orb {
    position: absolute;
    border-radius: 50%;
    filter: blur(80px);
    opacity: 0.15;
  }
  .hero-bg .orb-1 {
    width: 500px; height: 500px;
    background: var(--cyan);
    top: -150px; right: -100px;
  }
  .hero-bg .orb-2 {
    width: 400px; height: 400px;
    background: var(--pink);
    bottom: -100px; left: 20%;
    opacity: 0.08;
  }
  .hero-bg .orb-3 {
    width: 300px; height: 300px;
    background: var(--purple);
    top: 40%; right: 30%;
    opacity: 0.06;
  }

  /* Grid lines background */
  .hero-bg .grid-bg {
    position: absolute;
    inset: 0;
    background-image:
      linear-gradient(var(--line) 1px, transparent 1px),
      linear-gradient(90deg, var(--line) 1px, transparent 1px);
    background-size: 60px 60px;
    opacity: 0.4;
    mask-image: radial-gradient(ellipse at 70% 50%, black 20%, transparent 70%);
    -webkit-mask-image: radial-gradient(ellipse at 70% 50%, black 20%, transparent 70%);
  }

  .hero-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 60px;
    align-items: center;
    position: relative;
    z-index: 1;
  }

  .hero-content { position: relative; }

  .hero-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--accent);
    margin-bottom: 20px;
    opacity: 0;
    transform: translateY(20px);
  }
  .hero-eyebrow .line {
    width: 24px; height: 2px;
    background: var(--accent);
    border-radius: 2px;
  }

  .hero h1 {
    font-size: clamp(40px, 5vw, 64px);
    margin-bottom: 24px;
  }
  .hero h1 .word {
    display: inline-block;
    overflow: hidden;
  }
  .hero h1 .word span {
    display: inline-block;
    transform: translateY(110%);
  }
  .hero h1 .highlight {
    background: linear-gradient(135deg, var(--accent), var(--cyan));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
  }

  .hero-lead {
    color: var(--text-muted);
    font-size: 17px;
    max-width: 440px;
    margin-bottom: 36px;
    opacity: 0;
    transform: translateY(20px);
  }

  .hero-actions {
    display: flex;
    align-items: center;
    gap: 24px;
    margin-bottom: 48px;
    flex-wrap: wrap;
    opacity: 0;
    transform: translateY(20px);
    position: relative;
    z-index: 10;
  }

  .play-link {
    display: flex;
    align-items: center;
    gap: 12px;
    font-weight: 600;
    font-size: 15px;
    color: var(--text-main);
    transition: transform 0.3s ease;
    padding: 0;
    border: 0;
    background: none;
    font-family: inherit;
    text-align: left;
    cursor: pointer;
  }
  .play-link:hover { transform: scale(1.05); }

  .video-player-dialog {
    width: min(92vw, 1440px, calc((100vh - 88px) * 16 / 9));
    width: min(92vw, 1440px, calc((100dvh - 88px) * 16 / 9));
    max-width: none;
    max-height: calc(100vh - 48px);
    max-height: calc(100dvh - 48px);
    margin: auto;
    padding: 0;
    overflow: visible;
    border: 0;
    background: transparent;
    color: #fff;
  }
  .video-player-dialog::backdrop {
    background: rgba(2, 7, 20, 0.74);
    -webkit-backdrop-filter: blur(8px);
    backdrop-filter: blur(8px);
  }
  .video-dialog-frame {
    position: relative;
    width: 100%;
    aspect-ratio: 16 / 9;
    border: 1px solid rgba(255, 255, 255, 0.22);
    border-radius: clamp(8px, 1.4vw, 18px);
    background: #000;
    box-shadow: 0 24px 90px rgba(0, 0, 0, 0.6);
  }
  .video-dialog-player {
    display: block;
    width: 100%;
    height: 100%;
    border-radius: inherit;
    background: #000;
    object-fit: contain;
  }
  .video-dialog-close {
    position: absolute;
    top: clamp(8px, 1.5vw, 16px);
    right: clamp(8px, 1.5vw, 16px);
    z-index: 1;
    display: grid;
    width: 44px;
    height: 44px;
    place-items: center;
    padding: 0;
    border: 1px solid rgba(255, 255, 255, 0.35);
    border-radius: 50%;
    background: rgba(5, 11, 28, 0.78);
    color: #fff;
    cursor: pointer;
    -webkit-tap-highlight-color: transparent;
  }
  .video-dialog-close:hover,
  .video-dialog-close:focus-visible {
    background: var(--accent);
    color: #0A1128;
  }
  .video-dialog-close:focus-visible,
  .play-link:focus-visible {
    outline: 3px solid var(--accent);
    outline-offset: 4px;
  }
  .video-dialog-message {
    min-height: 1.5em;
    margin: 10px 0 0;
    color: #fff;
    font-size: 14px;
    text-align: center;
  }
  .video-dialog-message:empty {
    display: none;
  }
  @media (max-width: 480px) {
    .video-player-dialog {
      width: min(96vw, calc((100dvh - 72px) * 16 / 9));
    }
    .video-dialog-close {
      width: 40px;
      height: 40px;
    }
  }
  @media (prefers-reduced-motion: reduce) {
    .play-link {
      transition: none;
    }
  }

  .play-circle {
    width: 52px; height: 52px;
    border-radius: 50%;
    border: 2px solid var(--line-bright);
    display: flex; align-items: center; justify-content: center;
    background: rgba(200, 205, 210,0.04);
    transition: all 0.3s ease;
    position: relative;
  }
  .play-circle::before {
    content: '';
    position: absolute;
    inset: -4px;
    border-radius: 50%;
    border: 1px solid rgba(247, 202, 132,0.3);
    animation: playRing 2s ease-in-out infinite;
  }
  @keyframes playRing {
    0%, 100% { transform: scale(1); opacity: 1; }
    50% { transform: scale(1.15); opacity: 0; }
  }
  .play-link:hover .play-circle {
    background: var(--accent);
    color: #0A1128;
    transform: scale(1.08);
    border-color: var(--accent);
  }

  /* Team join */
  .team-join {
    display: flex;
    align-items: center;
    gap: 18px;
    opacity: 0;
    transform: translateY(20px);
  }
  .team-join-label {
    font-size: 13px;
    color: var(--text-muted);
    max-width: 110px;
    line-height: 1.3;
    font-weight: 500;
  }
  .avatar-row { display: flex; align-items: center; }
  .avatar-placeholder {
    width: 46px; height: 46px;
    border-radius: 50%;
    border: 2px dashed rgba(200, 205, 210,0.35);
    background: var(--bg-panel-light);
    margin-left: -12px;
    overflow: hidden;
    display: flex; align-items: center; justify-content: center;
    color: var(--text-faint);
    transition: transform 0.3s ease, border-color 0.3s ease;
  }
  .avatar-placeholder:first-child { margin-left: 0; }
  .avatar-placeholder img { width: 100%; height: 100%; object-fit: cover; }
  .avatar-placeholder:hover { transform: translateY(-4px); border-color: var(--accent); }
  .avatar-add {
    width: 46px; height: 46px;
    border-radius: 50%;
    background: var(--accent);
    color: #0A1128;
    display: flex; align-items: center; justify-content: center;
    margin-left: -12px;
    font-size: 20px;
    font-weight: 700;
    border: 2px solid var(--bg-deep);
    transition: transform 0.3s ease;
    cursor: pointer;
  }
  .avatar-add:hover { transform: scale(1.1) rotate(90deg); }

  /* ========== HERO VISUAL ========== */
  .hero-visual {
    position: relative;
    height: 580px;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
  }

  .chart-container {
    position: relative;
    width: 100%;
    height: 100%;
  }

  .chart-svg {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
  }

  .bar {
    transform-box: fill-box;
    transform-origin: bottom;
  }

  .arrow-line {
    stroke-dasharray: 1500;
    stroke-dashoffset: 1500;
  }

  .arrow-line-glow {
    opacity: 0;
  }

  .arrow-head {
    opacity: 0;
  }

  .data-dot {
    opacity: 0;
  }

  .vol-bar {
    transform-box: fill-box;
    transform-origin: bottom;
  }

  .base-glow {
    animation: baseGlow 2s ease-in-out infinite;
  }
  @keyframes baseGlow {
    0%, 100% { opacity: 0.2; }
    50% { opacity: 0.5; }
  }

  .spark {
    position: absolute;
    border-radius: 50%;
    opacity: 0;
    z-index: 3;
  }
  .spark.cyan { background: var(--cyan); box-shadow: 0 0 12px 4px rgba(247, 202, 132,0.7); }
  .spark.pink { background: var(--cyan); box-shadow: 0 0 12px 4px rgba(247, 202, 132,0.5); }

  .analytics-card {
    position: absolute;
    top: 24px;
    left: 24px;
    z-index: 3;
    background: rgba(22, 36, 71,0.8);
    backdrop-filter: blur(16px);
    -webkit-backdrop-filter: blur(16px);
    border: 1px solid var(--line-bright);
    border-radius: var(--radius-md);
    padding: 20px 24px;
    max-width: 250px;
    opacity: 0;
    transform: translateX(-20px);
  }

  .live-dot {
    width: 8px; height: 8px;
    border-radius: 50%;
    background: var(--accent);
    display: inline-block;
    animation: livePulse 1.6s ease-in-out infinite;
  }
  @keyframes livePulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(247, 202, 132,0.6); }
    50% { box-shadow: 0 0 0 8px rgba(247, 202, 132,0); }
  }

  .phone-screen-tag {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: rgba(247, 202, 132,0.12);
    color: var(--accent);
    font-size: 10px;
    font-weight: 600;
    padding: 6px 12px;
    border-radius: 999px;
    margin-bottom: 14px;
  }

  .phone-headline {
    font-size: 12.5px;
    color: var(--text-muted);
    line-height: 1.5;
    margin-bottom: 14px;
  }

  .phone-amount {
    font-family: var(--font-display);
    font-weight: 800;
    font-size: 26px;
    color: var(--accent);
  }

  .stat-badge {
    position: absolute;
    right: 0;
    bottom: 40px;
    background: var(--bg-panel-light);
    border: 1px solid var(--line-bright);
    border-radius: var(--radius-md);
    padding: 24px 28px;
    text-align: center;
    z-index: 3;
    box-shadow: 0 20px 60px -16px rgba(0,0,0,0.6);
    opacity: 0;
    transform: translateY(20px) scale(0.9);
    backdrop-filter: blur(10px);
  }
  .stat-badge .num {
    font-family: var(--font-display);
    font-size: 34px;
    font-weight: 800;
    color: var(--accent);
  }
  .stat-badge .label {
    font-size: 12px;
    color: var(--text-muted);
    margin-top: 4px;
    line-height: 1.3;
  }

  /* ========== SCROLL INDICATOR ========== */
  .scroll-indicator {
    position: absolute;
    bottom: 40px;
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    opacity: 0;
    z-index: 2;
  }
  .scroll-indicator span {
    font-size: 11px;
    color: var(--text-faint);
    letter-spacing: 0.1em;
    text-transform: uppercase;
  }
  .scroll-mouse {
    width: 24px; height: 38px;
    border: 2px solid var(--text-faint);
    border-radius: 12px;
    position: relative;
  }
  .scroll-mouse::after {
    content: '';
    position: absolute;
    top: 6px;
    left: 50%;
    transform: translateX(-50%);
    width: 3px; height: 8px;
    background: var(--accent);
    border-radius: 3px;
    animation: scrollDot 1.5s ease-in-out infinite;
  }
  @keyframes scrollDot {
    0%, 100% { top: 6px; opacity: 1; }
    50% { top: 20px; opacity: 0.3; }
  }

  /* ========== MARQUEE ========== */
  .marquee-section {
    padding: 40px 0;
    overflow: hidden;
    border-top: 1px solid var(--line);
    border-bottom: 1px solid var(--line);
    background: rgba(22, 36, 71,0.4);
  }
  .marquee-track {
    display: flex;
    gap: 60px;
    animation: marquee 30s linear infinite;
    width: max-content;
  }
  .marquee-item {
    display: flex;
    align-items: center;
    gap: 14px;
    font-family: var(--font-display);
    font-size: 16px;
    font-weight: 600;
    color: var(--text-muted);
    white-space: nowrap;
    flex-shrink: 0;
  }
  .marquee-item .dot {
    width: 8px; height: 8px;
    border-radius: 50%;
    background: var(--accent);
  }
  @keyframes marquee {
    0% { transform: translateX(0); }
    100% { transform: translateX(-50%); }
  }

  /* ========== STATS COUNTER ========== */
  .stats-section {
    padding: 100px 0;
    position: relative;
  }
  .stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 30px;
  }
  .stat-card {
    text-align: center;
    padding: 40px 24px;
    border-radius: var(--radius-md);
    background: rgba(22, 36, 71,0.5);
    border: 1px solid var(--line);
    position: relative;
    overflow: hidden;
    transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1), border-color 0.3s ease;
  }
  .stat-card:hover {
    transform: translateY(-8px);
    border-color: rgba(247, 202, 132,0.3);
  }
  .stat-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    background: linear-gradient(90deg, var(--cyan), var(--accent), var(--pink));
    opacity: 0;
    transition: opacity 0.3s ease;
  }
  .stat-card:hover::before { opacity: 1; }
  .stat-card .stat-icon {
    width: 56px; height: 56px;
    border-radius: 14px;
    background: rgba(247, 202, 132,0.1);
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 20px;
    font-size: 24px;
    color: var(--accent);
  }
  .stat-card .stat-number {
    font-family: var(--font-display);
    font-size: 42px;
    font-weight: 800;
    color: var(--text-main);
    margin-bottom: 4px;
  }
  .stat-card .stat-number .suffix {
    color: var(--accent);
    font-size: 28px;
  }
  .stat-card .stat-label {
    font-size: 14px;
    color: var(--text-muted);
  }
  .stat-card .stat-desc {
    font-size: 12px;
    color: var(--text-muted);
    margin-top: 8px;
    line-height: 1.5;
    opacity: 0.8;
  }
  .stats-header {
    text-align: center;
    max-width: 680px;
    margin: 0 auto 50px;
  }
  .stats-header .eyebrow {
    display: inline-block;
    font-size: 13px;
    font-weight: 600;
    color: var(--accent);
    letter-spacing: 1.5px;
    text-transform: uppercase;
    margin-bottom: 12px;
  }
  .stats-header .eyebrow::before {
    content: '— ';
  }
  .stats-header h2 {
    font-family: var(--font-display);
    font-size: 36px;
    font-weight: 800;
    color: var(--text-main);
    line-height: 1.2;
    margin-bottom: 16px;
  }
  .stats-header p {
    font-size: 16px;
    color: var(--text-muted);
    line-height: 1.7;
  }

  /* ========== HOW IT WORKS / MODULES ========== */
  .how {
    padding: 100px 0;
  }
  .how-section-inner {
    text-align: center;
  }
  .how-header-full {
    max-width: 700px;
    margin: 0 auto 50px;
  }
  .how-header-full .eyebrow {
    display: inline-block;
    font-size: 13px;
    font-weight: 600;
    color: var(--accent);
    letter-spacing: 0.06em;
    text-transform: uppercase;
    margin-bottom: 12px;
  }
  .how-header-full .eyebrow::before {
    content: '';
    width: 20px; height: 2px;
    background: var(--accent);
    display: inline-block;
    vertical-align: middle;
    margin-right: 8px;
    border-radius: 2px;
  }
  .how-header-full h2 {
    font-family: var(--font-display);
    font-size: clamp(30px, 3.5vw, 44px);
    font-weight: 800;
    color: var(--text-main);
    line-height: 1.2;
    margin-bottom: 16px;
  }
  .how-header-full p {
    font-size: 16px;
    color: var(--text-muted);
    line-height: 1.6;
  }

  /* Flip Card Grid */
  .flip-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
  }

  /* Flip Card */
  .flip-card {
    perspective: 1000px;
    height: 260px;
    cursor: pointer;
  }
  .flip-inner {
    position: relative;
    width: 100%;
    height: 100%;
    transition: transform 0.7s cubic-bezier(0.4, 0, 0.2, 1);
    transform-style: preserve-3d;
  }
  .flip-card.is-flipped .flip-inner {
    transform: rotateY(180deg);
  }
  .flip-front, .flip-back {
    position: absolute;
    inset: 0;
    backface-visibility: hidden;
    -webkit-backface-visibility: hidden;
    border-radius: var(--radius-md);
    padding: 28px 22px;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    justify-content: center;
    background: rgba(22, 36, 71,0.6);
    border: 1px solid var(--line);
    overflow: hidden;
  }
  .flip-front::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, rgba(247, 202, 132,0.04), transparent);
    pointer-events: none;
  }
  .flip-back {
    transform: rotateY(180deg);
    background: rgba(22, 36, 71,0.8);
    border-color: rgba(247, 202, 132,0.3);
  }
  .flip-back::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(135deg, rgba(247, 202, 132,0.08), transparent);
    pointer-events: none;
  }

  .flip-icon {
    width: 52px; height: 52px;
    border-radius: 14px;
    background: rgba(247, 202, 132,0.12);
    color: var(--accent);
    display: flex; align-items: center; justify-content: center;
    margin-bottom: 18px;
    transition: transform 0.3s ease, background 0.3s ease;
  }
  .flip-card:hover .flip-icon {
    transform: scale(1.1);
    background: rgba(247, 202, 132,0.2);
  }
  .flip-icon svg { width: 24px; height: 24px; }

  .flip-front h4, .flip-back h4 {
    font-family: var(--font-display);
    font-size: 16px;
    font-weight: 700;
    color: var(--text-main);
    margin-bottom: 8px;
    line-height: 1.3;
  }
  .flip-back h4 {
    margin-bottom: 12px;
  }
  .flip-hint {
    font-size: 12px;
    color: var(--accent);
    opacity: 0.7;
    margin-top: 10px;
    transition: opacity 0.3s ease;
  }
  .flip-card:hover .flip-hint {
    opacity: 1;
  }
  .flip-back p {
    font-size: 13.5px;
    color: var(--text-muted);
    line-height: 1.65;
    margin-bottom: 12px;
  }
  .flip-hint-back {
    font-size: 11px;
    color: var(--accent);
    opacity: 0.6;
    margin-top: auto;
    align-self: center;
  }

  /* ========== FEATURES SECTION ========== */
  .features {
    padding: 100px 0;
    position: relative;
  }
  .gradient-text {
    background: linear-gradient(135deg, var(--accent), var(--cyan));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
  }

  /* ========== ABOUT US ========== */
  .about {
    padding: 120px 0;
    position: relative;
    overflow: hidden;
  }
  .about::before {
    content: '';
    position: absolute;
    top: 50%; left: -200px;
    width: 500px; height: 500px;
    background: radial-gradient(circle, rgba(247, 202, 132,0.06), transparent 60%);
    transform: translateY(-50%);
    pointer-events: none;
  }
  .about::after {
    content: '';
    position: absolute;
    top: 30%; right: -150px;
    width: 400px; height: 400px;
    background: radial-gradient(circle, rgba(247, 202, 132,0.05), transparent 60%);
    pointer-events: none;
  }

  .about-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 80px;
    align-items: center;
  }

  .about-left h2 {
    font-size: clamp(30px, 3.8vw, 46px);
    margin: 16px 0 20px;
    line-height: 1.15;
  }
  .about-lead {
    color: var(--text-muted);
    font-size: 16px;
    line-height: 1.75;
    margin-bottom: 48px;
    max-width: 480px;
  }

  .about-values {
    display: flex;
    flex-direction: column;
    gap: 28px;
    margin-bottom: 40px;
  }
  .value-item {
    display: flex;
    gap: 18px;
    align-items: flex-start;
    padding: 24px;
    border-radius: var(--radius-md);
    background: rgba(22, 36, 71,0.5);
    border: 1px solid var(--line);
    transition: border-color 0.3s ease, transform 0.3s ease;
  }
  .value-item:hover {
    border-color: rgba(247, 202, 132,0.2);
    transform: translateX(6px);
  }
  .value-icon {
    width: 48px; height: 48px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
  }
  .value-item:nth-child(1) .value-icon { background: rgba(247, 202, 132,0.12); color: var(--accent); }
  .value-item:nth-child(2) .value-icon { background: rgba(0, 127, 255, 0.12); color: var(--cyan); }
  .value-item h4 {
    font-size: 16px;
    margin-bottom: 6px;
  }
  .value-item p {
    color: var(--text-muted);
    font-size: 13.5px;
    line-height: 1.6;
  }

  .about-cta { margin-top: 8px; }

  /* Right visual */
  .about-right {
    position: relative;
  }
  .about-visual {
    position: relative;
    width: 100%;
    height: 460px;
  }

  /* Floating cards */
  .about-card {
    position: absolute;
    background: rgba(22, 36, 71, 0.8);
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
    border: 1px solid var(--line);
    border-radius: var(--radius-md);
    padding: 20px 22px;
    min-width: 180px;
    z-index: 2;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
    animation: floatCard 5s ease-in-out infinite;
  }
  .about-card-1 { top: 20px; left: 10px; animation-delay: 0s; }
  .about-card-2 { top: 120px; right: 0; animation-delay: 1.5s; }
  .about-card-3 { bottom: 30px; left: 40px; animation-delay: 3s; }

  @keyframes floatCard {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-12px); }
  }

  .about-card-icon {
    width: 40px; height: 40px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px;
    margin-bottom: 10px;
    position: relative;
    z-index: 2;
  }

  .about-lines-svg {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    z-index: 0;
    pointer-events: none;
  }
  .about-card-1 .about-card-icon { background: rgba(247, 202, 132,0.12); color: var(--accent); position: relative; }
  .about-card-2 .about-card-icon { background: rgba(0, 127, 255, 0.12); color: var(--cyan); position: relative; }
  .about-card-3 .about-card-icon { background: rgba(173, 255, 255, 0.12); color: var(--purple); position: relative; }

  .about-card-label {
    font-size: 13px;
    font-weight: 600;
    color: var(--text-main);
    margin-bottom: 10px;
    display: block;
  }
  .about-card-bar {
    height: 6px;
    background: rgba(200, 205, 210,0.08);
    border-radius: 3px;
    overflow: hidden;
  }
  .about-card-bar-fill {
    height: 100%;
    border-radius: 3px;
    transition: width 1.5s ease;
  }
  .about-card-1 .about-card-bar-fill { background: linear-gradient(90deg, var(--accent), var(--cyan)); }
  .about-card-2 .about-card-bar-fill { background: linear-gradient(90deg, var(--cyan), var(--purple)); }
  .about-card-3 .about-card-bar-fill { background: linear-gradient(90deg, var(--purple), var(--pink)); }

  /* Center orb */
  .about-orb {
    position: absolute;
    top: 50%; left: 50%;
    transform: translate(-50%, -50%);
    width: 120px; height: 120px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(247, 202, 132, 0.1), transparent 70%);
    display: flex; align-items: center; justify-content: center;
    z-index: 1;
    animation: orbPulse 4s ease-in-out infinite;
  }
  .about-orb-inner {
    width: 70px; height: 70px;
    border-radius: 50%;
    background: #0A1128;
    border: 1px solid rgba(247, 202, 132, 0.2);
    box-shadow: 0 10px 30px rgba(247, 202, 132, 0.1);
    display: flex; align-items: center; justify-content: center;
    font-size: 28px;
    color: var(--accent);
    animation: orbSpin 20s linear infinite;
  }
  @keyframes orbPulse {
    0%, 100% { transform: translate(-50%, -50%) scale(1); }
    50% { transform: translate(-50%, -50%) scale(1.05); }
  }
  @keyframes orbSpin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
  }



  /* ========== TRUST & SECURITY ========== */
  .trust-security {
    padding: 100px 0;
    position: relative;
    overflow: hidden;
  }
  .trust-security::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(180deg, transparent, rgba(22, 36, 71,0.8), transparent);
  }
  .trust-header {
    text-align: center;
    max-width: 600px;
    margin: 0 auto 64px;
    position: relative;
  }
  .trust-header h2 {
    font-size: clamp(28px, 3.5vw, 42px);
    margin: 16px 0 16px;
  }
  .trust-header p {
    color: var(--text-muted);
    font-size: 16px;
  }

  .trust-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 28px;
    position: relative;
  }
  .trust-card {
    padding: 36px 30px;
    border-radius: var(--radius-md);
    background: rgba(22, 36, 71,0.6);
    border: 1px solid var(--line);
    transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1), border-color 0.3s ease;
    position: relative;
  }
  .trust-card:hover {
    transform: translateY(-6px);
    border-color: rgba(247, 202, 132,0.2);
  }
  .trust-card .trust-icon {
    width: 52px; height: 52px;
    border-radius: 14px;
    background: linear-gradient(135deg, var(--cyan), var(--purple));
    display: flex; align-items: center; justify-content: center;
    font-size: 20px;
    color: #fff;
    margin-bottom: 20px;
  }
  .trust-card .trust-title {
    font-family: var(--font-display);
    font-weight: 700;
    font-size: 18px;
    margin-bottom: 12px;
  }
  .trust-card .trust-desc {
    font-size: 15px;
    color: var(--text-muted);
    line-height: 1.7;
    margin-bottom: 20px;
  }
  .trust-card .trust-tag {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    font-weight: 600;
    color: var(--accent);
    text-transform: uppercase;
    letter-spacing: 0.04em;
  }

  /* ========== CTA SECTION ========== */
  .cta-section {
    padding: 120px 0;
    position: relative;
  }
  .cta-box {
    background: linear-gradient(135deg, var(--bg-panel), var(--bg-panel-light));
    border: 1px solid var(--line);
    border-radius: 32px;
    padding: 80px 60px;
    text-align: center;
    position: relative;
    overflow: hidden;
  }
  .cta-box::before {
    content: '';
    position: absolute;
    top: -200px; left: 50%;
    transform: translateX(-50%);
    width: 600px; height: 600px;
    background: radial-gradient(circle, rgba(247, 202, 132,0.1), transparent 60%);
    pointer-events: none;
  }
  .cta-box::after {
    content: '';
    position: absolute;
    bottom: -100px; right: -100px;
    width: 300px; height: 300px;
    background: radial-gradient(circle, rgba(247, 202, 132,0.08), transparent 60%);
    pointer-events: none;
  }
  .cta-box > * { position: relative; z-index: 1; }
  .cta-box h2 {
    font-size: clamp(30px, 4vw, 48px);
    margin-bottom: 20px;
  }
  .cta-box p {
    color: var(--text-muted);
    font-size: 17px;
    max-width: 500px;
    margin: 0 auto 40px;
  }
  .cta-buttons {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 20px;
    flex-wrap: wrap;
  }

  /* ========== FOOTER ========== */
  footer {
    position: relative;
    padding: 56px 0 40px;
    background: rgba(10, 17, 40,0.8);
  }

  /* ========== ANIMATED WAVE DIVIDER ========== */
  .footer-wave-wrap {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 64px;
    transform: translateY(-98%);
    overflow: hidden;
    line-height: 0;
    pointer-events: none;
  }
  .footer-wave-wrap svg {
    position: absolute;
    bottom: 0;
    left: 0;
    width: 200%;
    height: 100%;
    display: block;
  }
  /* Furthest-back layer: faint liquid silver, largest swell, slowest drift */
  .wave-back {
    fill: rgba(200, 205, 210, 0.14);
    animation: waveFlow 18s linear infinite;
  }
  /* Middle layer: stronger liquid silver, tighter swell, opposite direction for a proper "waving" parallax */
  .wave-mid {
    fill: rgba(200, 205, 210, 0.32);
    animation: waveFlow 12s linear infinite reverse;
  }
  /* Front layer: exact footer color, smallest swell, fastest — this is what actually seals into the footer */
  .wave-front {
    fill: var(--bg-deep);
    animation: waveFlow 8s linear infinite;
  }
  /* Thin glowing liquid-silver crest line riding the very top wave */
  .wave-crest {
    fill: none;
    stroke: #C8CDD2;
    stroke-width: 3;
    opacity: 0.75;
    filter: drop-shadow(0 0 6px rgba(200, 205, 210, 0.65));
    animation: waveFlow 18s linear infinite;
  }
  @keyframes waveFlow {
    0% { transform: translateX(0); }
    100% { transform: translateX(-50%); }
  }
  @media (prefers-reduced-motion: reduce) {
    .wave-back, .wave-mid, .wave-front, .wave-crest { animation: none; }
  }

  /* Ambient glow behind the brand column, echoes the hero orbs */
  .footer-glow {
    position: absolute;
    top: -60px;
    left: -80px;
    width: 380px;
    height: 380px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(247, 202, 132, 0.10) 0%, transparent 70%);
    pointer-events: none;
    z-index: 0;
  }
  footer .wrap { position: relative; z-index: 1; }

  .footer-grid {
    display: grid;
    grid-template-columns: 1.5fr 1fr 1fr 1fr;
    gap: 48px;
    margin-bottom: 60px;
  }
  .footer-brand .logo {
    margin-bottom: 16px;
  }
  .footer-brand p {
    color: var(--text-muted);
    font-size: 14px;
    max-width: 280px;
    line-height: 1.7;
  }
  .footer-social {
    display: flex;
    gap: 12px;
    margin-top: 20px;
  }
  .footer-social a {
    width: 40px; height: 40px;
    border-radius: 10px;
    background: rgba(200, 205, 210,0.05);
    border: 1px solid var(--line);
    display: flex; align-items: center; justify-content: center;
    font-size: 16px;
    color: var(--text-muted);
    transition: all 0.3s ease;
  }
  .footer-social a:hover {
    background: var(--accent);
    color: #0A1128;
    border-color: var(--accent);
    animation: iconWave 0.6s ease;
  }
  @keyframes iconWave {
    0%, 100% { transform: translateY(0) rotate(0deg); }
    20% { transform: translateY(-6px) rotate(-14deg); }
    40% { transform: translateY(-2px) rotate(10deg); }
    60% { transform: translateY(-5px) rotate(-6deg); }
    80% { transform: translateY(0) rotate(3deg); }
  }

  /* ========== BACK TO TOP ========== */
  .back-to-top {
    position: fixed;
    right: 28px;
    bottom: 28px;
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: var(--accent);
    color: #0A1128;
    display: flex;
    align-items: center;
    justify-content: center;
    border: none;
    cursor: pointer;
    font-size: 18px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.35);
    opacity: 0;
    visibility: hidden;
    transform: translateY(16px);
    transition: opacity 0.3s ease, transform 0.3s ease, visibility 0.3s ease, background 0.3s ease;
    z-index: 400;
  }
  .back-to-top.show {
    opacity: 1;
    visibility: visible;
    transform: translateY(0);
    animation: backToTopFloat 2.4s ease-in-out 0.3s infinite;
  }
  .back-to-top:hover {
    background: var(--accent-dim);
  }
  @keyframes backToTopFloat {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-6px); }
  }
  .back-to-top.show:hover { animation-play-state: paused; }

  .footer-col h4 {
    font-size: 15px;
    margin-bottom: 20px;
    color: var(--text-main);
  }
  .footer-col ul {
    display: flex;
    flex-direction: column;
    gap: 12px;
  }
  .footer-col a {
    font-size: 14px;
    color: var(--text-muted);
    transition: color 0.3s ease, transform 0.3s ease;
    display: inline-block;
  }
  .footer-col a:hover {
    color: var(--accent);
    transform: translateX(4px);
  }

  .footer-contact {
    gap: 16px;
  }
  .footer-contact li {
    display: flex;
    align-items: flex-start;
    gap: 12px;
  }
  .footer-contact .contact-icon {
    width: 34px;
    height: 34px;
    flex-shrink: 0;
    border-radius: 50%;
    background: rgba(200, 205, 210, 0.05);
    border: 1px solid var(--line);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    color: var(--accent);
  }
  .footer-contact li span:last-child {
    font-size: 14px;
    color: var(--text-muted);
    line-height: 1.6;
    padding-top: 6px;
  }
  .footer-contact li a {
    color: var(--text-muted);
  }
  .footer-contact li a:hover {
    color: var(--accent);
    transform: none;
  }

  .footer-bottom {
    display: flex;
    align-items: center;
    justify-content: center;
    padding-top: 30px;
    border-top: 1px solid var(--line);
    font-size: 13px;
    color: var(--text-faint);
  }

  /* ========== SCROLL ANIMATIONS ========== */
  .reveal {
    opacity: 0;
    transform: translateY(40px);
  }
  .reveal.from-left { transform: translateX(-40px); }
  .reveal.from-right { transform: translateX(40px); }
  .reveal.from-scale { transform: scale(0.9); }

  /* ========== FLOATING PARTICLES ========== */
  .particles {
    position: fixed;
    inset: 0;
    pointer-events: none;
    z-index: 0;
    overflow: hidden;
  }
  .particle {
    position: absolute;
    border-radius: 50%;
    opacity: 0;
    animation: particleFloat linear infinite;
  }
  @keyframes particleFloat {
    0% { transform: translateY(100vh) rotate(0deg); opacity: 0; }
    10% { opacity: 1; }
    90% { opacity: 1; }
    100% { transform: translateY(-20vh) rotate(360deg); opacity: 0; }
  }

  /* ========== RESPONSIVE ========== */
  /* ========== TABLET (iPad / large Android tablets) ========== */
  @media (max-width: 1200px) {
    .flip-grid { grid-template-columns: repeat(3, 1fr); }
    .trust-grid { grid-template-columns: repeat(2, 1fr); }
  }

  @media (max-width: 980px) {
    .hero-grid { grid-template-columns: 1fr; }
    .hero-visual { height: 440px; margin-top: 30px; }
    .how-section-inner { padding: 0 10px; }
    .flip-grid { grid-template-columns: repeat(2, 1fr); gap: 16px; }
    .stats-grid { grid-template-columns: 1fr 1fr; }
    .about-grid { grid-template-columns: 1fr; gap: 40px; }
    .about-visual {
      height: auto;
      display: flex;
      flex-direction: column;
      gap: 16px;
    }
    .about-visual .about-orb,
    .about-visual .about-lines-svg { display: none; }
    .about-card {
      position: static;
      width: 100%;
      min-width: 0;
      animation: none;
      transform: none !important;
    }
    .trust-grid { grid-template-columns: 1fr; }
    .footer-grid { grid-template-columns: 1fr 1fr; }

    /* Scroll-triggered reveal animations are unreliable on mobile
       (address-bar resize throws off ScrollTrigger's measurements,
       which can leave elements stuck mid-transform or stuck at
       opacity:0). Show everything in its final position immediately. */
    .reveal,
    .reveal.from-left,
    .reveal.from-right,
    .reveal.from-scale {
      opacity: 1 !important;
      transform: none !important;
      scale: 1 !important;
    }

    /* ---- Mobile nav toggle + slide-in drawer ---- */
    .nav-toggle {
      display: flex;
      align-items: center;
      justify-content: center;
      width: 44px;
      height: 44px;
    }

    .nav-cta { gap: 10px; }
    .nav-cta .btn {
      padding: 10px 18px;
      font-size: 13px;
    }

    .nav-links {
      position: fixed;
      top: 0;
      right: 0;
      height: 100%;
      height: 100dvh;
      width: min(300px, 82vw);
      margin: 0;
      background: rgba(10, 17, 40, 0.98);
      backdrop-filter: blur(20px);
      -webkit-backdrop-filter: blur(20px);
      border-left: 1px solid var(--line-bright);
      box-shadow: -20px 0 50px rgba(0,0,0,0.35);
      flex-direction: column;
      align-items: flex-start;
      justify-content: flex-start;
      gap: 2px;
      padding: 100px 28px 40px;
      transform: translateX(100%);
      transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1);
      z-index: 90;
      overflow-y: auto;
      -webkit-overflow-scrolling: touch;
    }
    .nav-links.open { transform: translateX(0); }

    .nav-links li { width: 100%; }
    .nav-links a {
      display: block;
      width: 100%;
      padding: 16px 4px;
      font-size: 17px;
      border-bottom: 1px solid var(--line);
    }
    .nav-links a::after { display: none; }

    .nav-links .has-dropdown {
      flex-direction: column;
      align-items: flex-start;
      width: 100%;
    }
    .nav-links .has-dropdown > a { border-bottom: none; padding-bottom: 6px; }
    .has-dropdown .dropdown-menu {
      position: static;
      transform: none;
      opacity: 1;
      visibility: visible;
      display: none;
      box-shadow: none;
      border: none;
      background: transparent;
      padding: 4px 0 14px 4px;
      gap: 22px;
    }
    .nav-links .has-dropdown.active .dropdown-menu {
      display: flex;
      transform: none;
      flex-wrap: wrap;
    }

    .nav-overlay {
      position: fixed;
      inset: 0;
      background: rgba(5, 8, 20, 0.6);
      opacity: 0;
      visibility: hidden;
      transition: opacity 0.3s ease, visibility 0.3s ease;
      z-index: 80;
    }
    .nav-overlay.show { opacity: 1; visibility: visible; }

    body.nav-open { overflow: hidden; }
  }

  @media (max-width: 560px) {
    .flip-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
    .wrap { padding: 0 20px; }
    .nav { padding: 16px 20px; }
    .flip-card { height: 270px; }
    .flip-front, .flip-back { padding: 12px 10px; align-items: center; text-align: center; }
    .flip-icon { width: 34px; height: 34px; border-radius: 10px; margin-bottom: 10px; }
    .flip-icon svg { width: 18px; height: 18px; }
    .flip-front h4, .flip-back h4 { font-size: 12.5px; line-height: 1.25; margin-bottom: 6px; }
    .flip-back h4 { margin-bottom: 6px; }
    .flip-back p { font-size: 11px; line-height: 1.3; margin-bottom: 6px; }
    .flip-hint, .flip-hint-back { font-size: 10px; }
    .flip-hint-back { margin-top: 6px; }
    .stat-badge { right: 6px; bottom: 16px; padding: 16px 18px; }
    .analytics-card { top: 16px; left: 16px; padding: 14px 16px; max-width: 180px; }
    .analytics-card .phone-amount { font-size: 20px; }
    .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 14px; }
    .stat-card { padding: 24px 16px; }
    .stat-card .stat-icon { width: 44px; height: 44px; font-size: 18px; margin-bottom: 14px; }
    .stat-card .stat-number { font-size: 28px; }
    .stat-card .stat-label { font-size: 13px; }
    .stat-card .stat-desc { font-size: 11px; }
    .footer-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
      grid-template-areas:
        "brand brand"
        "menu modules"
        "contact .";
      gap: 32px 24px;
    }
    .footer-brand { grid-area: brand; }
    .footer-menu { grid-area: menu; min-width: 0; }
    .footer-modules { grid-area: modules; min-width: 0; }
    .footer-contact-col { grid-area: contact; min-width: 0; }
    .footer-contact li span:last-child {
      min-width: 0;
      overflow-wrap: anywhere;
    }
    .cta-box { padding: 50px 28px; }
    .hero { padding: 120px 0 80px; }
  }

  /* ========== SMALL PHONES (iPhone SE, compact Android) ========== */
  @media (max-width: 400px) {
    .wrap { padding: 0 16px; }
    .nav { padding: 14px 16px; }
    .hero h1 { font-size: clamp(32px, 9vw, 40px); }
    .hero-visual { height: 340px; }
    .modal-box { padding: 24px 18px; border-radius: 24px; }
    .signup-box { padding: 24px 18px; }
    .cta-box { padding: 40px 20px; }
    .nav-links { width: 88vw; padding: 90px 22px 32px; }
    .nav-cta .btn { padding: 8px 14px; font-size: 12px; }
  }

  /* ========== TOUCH DEVICES: disable hover-only interactions ========== */
  @media (hover: none) and (pointer: coarse) {
    .testimonial-card:hover,
    .trust-card:hover { transform: none; }
  }

  /* ========== LOGIN / SIGNUP MODAL (base, ported from old style.css) ========== */
  .modal {
    display: flex;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.35);
    justify-content: center;
    align-items: center;
    padding: 20px;
    z-index: 999;
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.35s ease, visibility 0.35s ease;
  }

  .modal.modal-open {
    opacity: 1;
    visibility: visible;
  }

  .modal.modal-closing {
    opacity: 0;
    visibility: hidden;
  }

  .modal-box {
    width: 100%;
    max-width: 760px;
    background: linear-gradient(to bottom, rgba(18, 101, 214, 0.78), rgba(1, 20, 70, 0.92));
    border: 2px solid rgba(168, 234, 255, 0.8);
    border-radius: 35px;
    padding: 30px 35px;
    position: relative;
    max-height: 90vh;
    overflow-y: auto;
    transform: scale(0.92);
    transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1);
  }

  .modal-open .modal-box {
    transform: scale(1);
  }

  .modal-closing .modal-box {
    transform: scale(0.92);
  }

  .signup-box { max-width: 820px; }

  .close-modal {
    position: absolute;
    top: 10px;
    right: 22px;
    font-size: 52px;
    color: white;
    background: none;
    border: none;
    cursor: pointer;
  }

  .modal-mini-logo { text-align: center; margin-bottom: 10px; }
  .modal-mini-logo img { width: 50px; }

  .modal-box h2 {
    text-align: center;
    font-size: 34px;
    font-weight: 900;
    margin-bottom: 15px;
  }

  .modal-box form { display: flex; flex-direction: column; }

  .modal-box label {
    margin-top: 10px;
    margin-bottom: 6px;
    font-size: 18px;
  }

  .modal-box input {
    width: 100%;
    padding: 15px;
    border-radius: 8px;
    border: none;
    background: #001f76;
    color: white;
    font-size: 16px;
  }

  .modal-box input[type="file"] { background: transparent; padding: 8px 0; }

  .silver-btn {
    margin: 24px auto 10px;
    border: none;
    border-radius: 40px;
    padding: 16px 45px;
    min-width: 230px;
    background: linear-gradient(to right, #c7c7c7, #f4f4f4, #bcbcbc);
    color: #222;
    font-size: 20px;
    font-family: Georgia, serif;
    cursor: pointer;
  }

  .modal-text { text-align: center; margin-top: 10px; font-size: 17px; }
  .modal-text a { color: white; font-weight: bold; text-decoration: none; }

  /* ========== SUCCESS / ERROR POPUP ========== */
  .popup-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.35);
    display: flex;
    justify-content: center;
    align-items: center;
    z-index: 99999;
    animation: popupFadeInBg 0.35s ease;
    cursor: pointer;
  }

  .popup-box {
    width: 90%;
    max-width: 420px;
    padding: 30px 25px;
    border-radius: 22px;
    text-align: center;
    color: white;
    box-shadow: 0 15px 40px rgba(0,0,0,0.35);
    transform: scale(0.7) translateY(30px);
    opacity: 0;
    animation: popupShow 0.5s ease forwards;
    backdrop-filter: blur(8px);
    cursor: default;
  }

  .popup-box.success {
    background: linear-gradient(to bottom, rgba(16, 160, 80, 0.95), rgba(8, 100, 50, 0.95));
    border: 2px solid rgba(170, 255, 200, 0.7);
  }

  .popup-box.error {
    background: linear-gradient(to bottom, rgba(220, 70, 70, 0.95), rgba(140, 20, 20, 0.95));
    border: 2px solid rgba(255, 180, 180, 0.7);
  }

  .popup-icon {
    width: 70px;
    height: 70px;
    margin: 0 auto 15px;
    border-radius: 50%;
    background: rgba(255,255,255,0.18);
    display: flex;
    justify-content: center;
    align-items: center;
    font-size: 38px;
    font-weight: bold;
  }

  .popup-box h3 { font-size: 28px; margin-bottom: 10px; }
  .popup-box p { font-size: 18px; line-height: 1.5; }

  .popup-hide { animation: popupHide 0.6s ease forwards !important; }
  .popup-overlay-hide { animation: popupFadeOutBg 0.6s ease forwards !important; }

  @keyframes popupShow {
    from { transform: scale(0.7) translateY(30px); opacity: 0; }
    to { transform: scale(1) translateY(0); opacity: 1; }
  }
  @keyframes popupHide {
    from { transform: scale(1) translateY(0); opacity: 1; }
    to { transform: scale(0.8) translateY(20px); opacity: 0; }
  }
  @keyframes popupFadeInBg { from { opacity: 0; } to { opacity: 1; } }
  @keyframes popupFadeOutBg { from { opacity: 1; } to { opacity: 0; } }

        .captcha-checkbox-wrap {
            margin-top: 14px;
            margin-bottom: 12px;
        }

        .captcha-checkbox-card {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            min-height: 74px;
            padding: 14px 16px;
            border-radius: 14px;
            background: var(--captcha-checkbox-card-bg);
            border: 1px solid var(--captcha-checkbox-card-border);
            transition: background 0.3s ease, border-color 0.3s ease;
        }

        .captcha-checkbox-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .captcha-checkbox-left input[type="checkbox"] {
            width: 22px;
            height: 22px;
            accent-color: #f7d98b;
            cursor: pointer;
            flex-shrink: 0;
        }

        .captcha-checkbox-left label {
            color: var(--captcha-checkbox-label);
            font-size: 15px;
            cursor: pointer;
            user-select: none;
        }

        .captcha-checkbox-right {
            text-align: center;
            font-size: 10px;
            color: var(--captcha-checkbox-right);
            line-height: 1.3;
            min-width: 68px;
        }

        .captcha-checkbox-right img,
        .captcha-checkbox-right svg {
            width: 28px;
            height: 28px;
            object-fit: contain;
            display: block;
            margin: 0 auto 4px;
            opacity: 0.9;
        }

        .captcha-popup-overlay {
            position: fixed;
            inset: 0;
            background: var(--captcha-overlay-bg);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transition: 0.25s ease;
            z-index: 999999;
            padding: 20px;
        }

        .captcha-popup-overlay.show {
            opacity: 1;
            visibility: visible;
            pointer-events: auto;
        }

        .captcha-popup-box {
            width: 100%;
            max-width: 560px;
            background: linear-gradient(180deg, var(--captcha-box-grad-1) 0%, var(--captcha-box-grad-2) 100%);
            border-radius: 22px;
            padding: 22px 20px 18px;
            box-shadow: 0 24px 50px rgba(0, 0, 0, 0.20);
            border: 1px solid var(--captcha-box-border);
            color: var(--captcha-text);
            transform: translateY(14px) scale(0.97);
            transition: 0.25s ease, background 0.3s ease, color 0.3s ease, border-color 0.3s ease;
        }

        .captcha-popup-overlay.show .captcha-popup-box {
            transform: translateY(0) scale(1);
        }

        .captcha-popup-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 14px;
        }

        .captcha-popup-header h3 {
            margin: 0;
            font-size: 22px;
            font-weight: 800;
            color: var(--captcha-text);
        }

        .captcha-popup-close {
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--captcha-close-bg);
            color: var(--captcha-text);
            font-size: 24px;
            cursor: pointer;
            transition: background 0.2s ease;
        }

        .captcha-popup-close:hover {
            background: var(--captcha-close-bg-hover);
        }

        .captcha-popup-title {
            margin-bottom: 12px;
            font-size: 15px;
            font-weight: 700;
            color: var(--captcha-text);
        }

        .captcha-popup-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
        }

        .captcha-popup-item {
            position: relative;
            border-radius: 12px;
            overflow: hidden;
            border: 2px solid transparent;
            cursor: pointer;
            background: var(--captcha-item-bg);
        }

        .captcha-popup-item input {
            display: none;
        }

        .captcha-popup-item img {
            display: block;
            width: 100%;
            height: 110px;
            object-fit: cover;
        }

        .captcha-popup-item span {
            position: absolute;
            top: 8px;
            right: 8px;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: var(--captcha-check-bg);
            border: 2px solid var(--captcha-check-ring);
            box-sizing: border-box;
            transition: background 0.2s ease, border-color 0.2s ease;
        }

        .captcha-popup-item input:checked + img + span {
            background: var(--captcha-verify-bg);
            border-color: var(--captcha-verify-bg);
        }

        .captcha-help {
            margin-top: 10px;
            font-size: 13px;
            color: var(--captcha-text-soft);
        }

        .captcha-error-box {
            margin-top: 12px;
            padding: 10px 12px;
            border-radius: 12px;
            background: var(--captcha-error-bg);
            color: var(--captcha-error-text);
            font-size: 13px;
            line-height: 1.5;
        }

        .captcha-popup-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 16px;
            flex-wrap: wrap;
        }

        .captcha-btn-cancel,
        .captcha-btn-verify {
            border: none;
            border-radius: 12px;
            padding: 11px 18px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            min-width: 120px;
            transition: 0.2s ease;
        }

        .captcha-btn-cancel {
            background: var(--captcha-cancel-bg);
            color: var(--captcha-cancel-text);
        }

        .captcha-btn-cancel:hover {
            background: var(--captcha-cancel-bg-hover);
        }

        .captcha-btn-verify {
            background: var(--captcha-verify-bg);
            color: var(--captcha-verify-text);
        }

        .captcha-btn-verify:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 20px rgba(247, 217, 139, 0.25);
        }

        .captcha-verified-text {
            margin-top: 8px;
            font-size: 13px;
            color: var(--captcha-verified-text);
            display: none;
        }

        .captcha-verified-text.show {
            display: block;
        }

        .password-field-wrap {
            position: relative;
            width: 100%;
        }

        .password-field-wrap input {
            width: 100%;
            padding-right: 56px;
        }

        #signupModal input[type="password"]::-ms-reveal,
        #signupModal input[type="password"]::-ms-clear {
            display: none;
        }

        .password-toggle-btn {
            position: absolute;
            top: 50%;
            right: 10px;
            transform: translateY(-50%);
            width: 38px;
            height: 38px;
            border: 1px solid rgba(247, 217, 139, 0.35);
            outline: none;
            background: black;
            color: #f7d98b;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            border-radius: 12px;
            box-shadow: none;
            transition: background 0.22s ease, color 0.22s ease, transform 0.22s ease, box-shadow 0.22s ease, border-color 0.22s ease;
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
        }

        .password-toggle-btn:hover {
            background: linear-gradient(180deg, rgba(247, 217, 139, 0.26), rgba(212, 168, 77, 0.18));
            color: black;
            border-color: rgba(247, 217, 139, 0.55);
            box-shadow: none;
        }

        .password-toggle-btn:focus-visible {
            box-shadow: none;
            border-color: rgba(247, 217, 139, 0.72);
        }

        #signupModal .password-toggle-btn,
        #signupModal .password-toggle-btn:hover,
        #signupModal .password-toggle-btn:focus,
        #signupModal .password-toggle-btn:focus-visible,
        #signupModal .password-toggle-btn:active {
            all: unset !important;
            position: absolute !important;
            top: 50% !important;
            right: 18px !important;
            transform: translateY(-50%) !important;
            width: 20px !important;
            height: 20px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            padding: 0 !important;
            appearance: none !important;
            -webkit-appearance: none !important;
            background: transparent !important;
            background-color: transparent !important;
            background-image: none !important;
            border: 0 !important;
            border-radius: 0 !important;
            outline: 0 !important;
            box-shadow: none !important;
            filter: none !important;
            color: #f7d98b !important;
            cursor: pointer !important;
        }

        #signupModal .password-toggle-btn::before,
        #signupModal .password-toggle-btn::after {
            content: none !important;
            display: none !important;
            background: none !important;
            border: 0 !important;
            box-shadow: none !important;
        }

        #signupModal .password-toggle-btn .eye-icon,
        #signupModal .password-toggle-btn .eye-icon svg {
            background: none !important;
            border: 0 !important;
            box-shadow: none !important;
            filter: none !important;
        }

        #signupModal .password-toggle-btn .eye-icon svg {
            fill: none !important;
            stroke: currentColor !important;
        }

        .eye-icon {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .eye-icon svg {
            width: 20px;
            height: 20px;
            stroke: currentColor;
            fill: none;
            stroke-width: 1.9;
            stroke-linecap: round;
            stroke-linejoin: round;
            filter: drop-shadow(0 0 6px rgba(247, 217, 139, 0.18));
        }

        .lockout-popup-overlay,
        .attempt-popup-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.60);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000001;
            padding: 20px;
        }

        .lockout-popup-overlay.show,
        .attempt-popup-overlay.show {
            display: flex;
        }

        .lockout-popup-box,
        .attempt-popup-box {
            width: 100%;
            max-width: 460px;
            background: linear-gradient(180deg, #264ba6 0%, #1c3f99 100%);
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 24px;
            box-shadow: 0 24px 54px rgba(0, 0, 0, 0.35);
            color: #fff;
            text-align: center;
            padding: 28px 24px 24px;
        }

        .lockout-popup-icon,
        .attempt-popup-icon {
            width: 68px;
            height: 68px;
            margin: 0 auto 14px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            font-weight: 900;
        }

        .lockout-popup-icon {
            background: rgba(255, 107, 107, 0.16);
            color: #ffd2d2;
        }

        .attempt-popup-icon {
            background: rgba(247, 217, 139, 0.16);
            color: #f7d98b;
        }

        .lockout-popup-box h3,
        .attempt-popup-box h3 {
            margin: 0 0 10px;
            font-size: 26px;
            font-weight: 900;
        }

        .lockout-popup-box p,
        .attempt-popup-box p {
            margin: 0;
            font-size: 15px;
            line-height: 1.6;
            color: rgba(255,255,255,0.88);
        }

        .lockout-timer-wrap {
            margin: 18px 0 10px;
            padding: 16px 14px;
            border-radius: 18px;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.10);
        }

        .lockout-timer-label {
            font-size: 13px;
            color: rgba(255,255,255,0.75);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .lockout-timer {
            font-size: 34px;
            font-weight: 900;
            color: #f7d98b;
            letter-spacing: 1px;
            line-height: 1;
        }

        .lockout-popup-btn,
        .attempt-popup-btn {
            margin-top: 18px;
            border: none;
            border-radius: 12px;
            padding: 12px 18px;
            min-width: 150px;
            background: #f7d98b;
            color: #17306b;
            font-size: 14px;
            font-weight: 800;
            cursor: pointer;
            transition: 0.2s ease;
        }

        .lockout-popup-btn:hover:not(:disabled),
        .attempt-popup-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 20px rgba(247, 217, 139, 0.25);
        }

        .lockout-popup-btn:disabled {
            opacity: 0.55;
            cursor: not-allowed;
        }

        .lockout-popup-btn-secondary {
            background: rgba(255,255,255,0.14);
            color: #fff;
        }

        .lockout-popup-btn-secondary:hover {
            transform: translateY(-1px);
            background: rgba(255,255,255,0.22);
            box-shadow: none;
        }

        .login-lock-note {
            margin-top: 8px;
            font-size: 13px;
            color: #ffd8d8;
            display: none;
        }

        .login-lock-note.show {
            display: block;
        }

        .attempts-left-highlight {
            margin-top: 14px;
            font-size: 34px;
            font-weight: 900;
            color: #f7d98b;
            line-height: 1;
        }

        .admin-unlock-note {
            margin-top: 10px;
            font-size: 13px;
            color: rgba(255,255,255,0.78);
            line-height: 1.5;
        }

        .privacy-consent-wrap {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 14px;
            margin-bottom: 4px;
            color: var(--text-main);
            font-size: 13px;
            line-height: 1.5;
        }

        .privacy-consent-wrap input[type="checkbox"] {
            margin: 0;
            width: 16px;
            height: 16px;
            accent-color: #f7d98b;
            flex-shrink: 0;
            cursor: pointer;
        }

        .privacy-consent-wrap input[type="checkbox"]:disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }

        .privacy-consent-wrap label {
            color: var(--text-main);
            font-size: 13px;
            line-height: 1.5;
            cursor: pointer;
        }

        .privacy-consent-hint {
            margin: 0 0 12px;
            font-size: 12px;
            line-height: 1.5;
            color: var(--text-faint);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .privacy-consent-hint.is-cleared {
            color: #6bc98a;
        }

        .privacy-consent-hint .hint-icon {
            font-size: 13px;
            line-height: 1;
        }

        .privacy-policy-inline-note {
            margin-top: 8px;
            margin-bottom: 12px;
            padding: 12px 14px;
            border-radius: 14px;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.10);
            color: rgba(255,255,255,0.82);
            font-size: 12px;
            line-height: 1.6;
        }

        .privacy-policy-link {
            color: #f7d98b;
            font-weight: 700;
            text-decoration: none;
        }

        .privacy-policy-link:hover {
            text-decoration: underline;
        }

        .cookie-banner {
            position: fixed;
            left: 20px;
            right: 20px;
            bottom: 20px;
            z-index: 99999;
            background: rgba(8, 27, 84, 0.95);
            color: #fff;
            border-radius: 18px;
            padding: 16px 18px;
            box-shadow: 0 16px 40px rgba(0,0,0,0.28);
            display: none;
            gap: 12px;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            border: 1px solid rgba(255,255,255,0.12);
        }

        .cookie-banner.show {
            display: flex;
        }

        .cookie-banner-text {
            max-width: 760px;
            font-size: 13px;
            line-height: 1.6;
            color: rgba(255,255,255,0.92);
        }

        .cookie-banner-text strong {
            color: #f7d98b;
            font-size: 15px;
        }

        .cookie-banner-text a {
            color: #f7d98b;
            font-weight: 700;
            text-decoration: none;
        }

        .cookie-banner-text a:hover {
            text-decoration: underline;
        }

        .cookie-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .cookie-btn {
            border: none;
            border-radius: 10px;
            padding: 10px 14px;
            cursor: pointer;
            font-weight: 700;
            min-width: 100px;
        }

        .cookie-accept {
            background: #f7d98b;
            color: #12306b;
        }

        .cookie-decline {
            background: rgba(255,255,255,0.15);
            color: #fff;
        }
        .captcha-notice-overlay {
    position: fixed;
    inset: 0;
    background: var(--captcha-notice-overlay-bg);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 1000002;
    padding: 20px;
}

.captcha-notice-overlay.show {
    display: flex;
}

.captcha-notice-box {
    width: 100%;
    max-width: 420px;
    background: linear-gradient(180deg, var(--captcha-notice-grad-1) 0%, var(--captcha-notice-grad-2) 100%);
    border: 1px solid var(--captcha-notice-border);
    border-radius: 24px;
    box-shadow: 0 24px 54px rgba(0, 0, 0, 0.25);
    color: var(--captcha-text);
    text-align: center;
    padding: 28px 24px 24px;
    transition: background 0.3s ease, color 0.3s ease, border-color 0.3s ease;
}

.captcha-notice-icon {
    width: 68px;
    height: 68px;
    margin: 0 auto 14px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    font-weight: 900;
    background: var(--captcha-notice-icon-bg);
    color: var(--captcha-notice-icon-text);
}

.captcha-notice-box h3 {
    margin: 0 0 10px;
    font-size: 26px;
    font-weight: 900;
    color: var(--captcha-text);
}

.captcha-notice-box p {
    margin: 0;
    font-size: 15px;
    line-height: 1.6;
    color: var(--captcha-notice-text);
}

.captcha-notice-btn {
    margin-top: 18px;
    border: none;
    border-radius: 12px;
    padding: 12px 18px;
    min-width: 150px;
    background: var(--captcha-verify-bg);
    color: var(--captcha-verify-text);
    font-size: 14px;
    font-weight: 800;
    cursor: pointer;
    transition: 0.2s ease;
}

.captcha-notice-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 10px 20px rgba(247, 217, 139, 0.25);
}

  /* ========== MODERN SPLIT-PANEL LOGIN ========== */

  /* ========== MODERN SPLIT-PANEL LOGIN & SIGNUP ========== */
  .modal-box.login-modern-box, .modal-box.signup-modern-box {
    max-width: 900px;
    padding: 0;
    overflow: hidden;
    display: flex;
    flex-direction: row;
    background: #fff;
    border: none;
    box-shadow: 0 15px 35px rgba(0,0,0,0.2);
    border-radius: 28px;
  }
  .modal-box.login-modern-box { min-height: 500px; }
  .modal-box.signup-modern-box { max-width: 950px; }

  /* Panel transition for switching between login and signup */
  .login-info-panel, .signup-info-panel,
  .login-form-panel, .signup-form-panel {
    transition: transform 0.45s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.35s ease;
  }

  .modal-switch-enter-left {
    transform: translateX(-40px);
    opacity: 0;
  }
  .modal-switch-enter-right {
    transform: translateX(40px);
    opacity: 0;
  }
  .modal-switch-exit-left {
    transform: translateX(-40px);
    opacity: 0;
    position: absolute;
    pointer-events: none;
  }
  .modal-switch-exit-right {
    transform: translateX(40px);
    opacity: 0;
    position: absolute;
    pointer-events: none;
  }

  /* Shared Panel Styles */
  .login-form-panel, .signup-form-panel {
    flex: 1.2;
    padding: 30px 40px;
    background: #fff;
    color: #333;
    display: flex;
    flex-direction: column;
    justify-content: center;
  }
  .signup-form-panel { flex: 1.3; max-height: 85vh; overflow-y: auto; padding: 25px 35px; justify-content: flex-start; }

  .login-info-panel, .signup-info-panel {
    flex: 0.8;
    background: linear-gradient(135deg, var(--bg-panel), var(--bg-panel-light));
    color: #fff;
    padding: 30px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    text-align: center;
    position: relative;
  }
  .signup-info-panel { flex: 0.7; }

  .login-info-panel::before, .signup-info-panel::before {
    content: "";
    position: absolute;
    top: 0; left: 0; width: 100%; height: 100%;
    background: radial-gradient(circle at top right, rgba(255,255,255,0.1), transparent);
  }

  /* Specific Element Styles */
  .login-form-panel h2, .signup-form-panel h2 { font-size: 26px; margin-bottom: 15px; font-family: var(--font-display); font-weight: 800; }
  .login-info-panel h2, .signup-info-panel h2 { font-size: 26px; margin-bottom: 12px; color: #fff; font-family: var(--font-display); }
  .login-info-panel p, .signup-info-panel p { font-size: 13px; line-height: 20px; margin: 0 0 20px; opacity: 0.9; }

  /* Reuse existing helper classes */
  .glass-field { background: #f4f7f6; border-radius: 12px; margin-bottom: 12px; display: flex; align-items: center; border: 1px solid #eee; }
  .glass-field-icon { padding-left: 15px; color: #777; display: flex; align-items: center; }
  .glass-field-icon svg { width: 18px; height: 18px; fill: #777; }
  .glass-field input { background: transparent !important; margin: 0 !important; color: #333 !important; padding: 12px 15px !important; border: none !important; width: 100% !important; font-size: 14px !important; outline: none !important; box-shadow: none !important; }
  .glass-login-btn, .silver-btn { background: var(--bg-panel); color: #fff; border-radius: 25px; border: none; padding: 12px 45px; font-size: 13px; font-weight: 700; text-transform: uppercase; cursor: pointer; width: 100%; transition: all 0.3s ease; font-family: var(--font-display); }
  .glass-login-btn:hover, .silver-btn:hover { background: var(--bg-panel-light); transform: translateY(-2px); box-shadow: 0 5px 15px rgba(36, 56, 189, 0.3); }
  .btn-outline { position: relative; z-index: 1; background-color: transparent; border: 2px solid #fff; color: #fff; border-radius: 25px; padding: 12px 40px; font-size: 13px; font-weight: 700; text-transform: uppercase; cursor: pointer; transition: all 0.3s ease; font-family: var(--font-display); }
  .btn-outline:hover { background-color: #fff; color: var(--bg-panel); }

  /* Signup Grid */
  .signup-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
  .signup-grid .full-width { grid-column: span 2; }
  .signup-form-panel label { font-size: 12px; margin-bottom: 4px; display: block; font-weight: 600; color: #555; }

  .signup-form-panel input,
  .signup-form-panel select,
  .signup-form-panel textarea {
    background: #f4f7f6;
    border: 1px solid #d9dee3;
    border-radius: 8px;
    padding: 9px 12px;
    width: 100%;
    font-size: 13px;
    margin-bottom: 8px;
    color: #222 !important;
    caret-color: #222;
    font-family: var(--font-body);
    outline: none;
  }

  .signup-form-panel input::placeholder,
  .signup-form-panel textarea::placeholder { color: #777 !important; opacity: 1; }
  .signup-form-panel select,
  .signup-form-panel select option {
    color: #222 !important;
    background-color: #fff !important;
    /* Without this, browsers running in OS/browser dark mode auto-style the
       native <select> popup with their own dark palette — independent of
       our data-theme toggle — which fights the #222-on-white colors above
       and leaves some rows unreadable. Locking the control to light UA
       chrome keeps every option legible regardless of the visitor's OS
       theme. */
    color-scheme: light;
  }
  .signup-form-panel input:focus,
  .signup-form-panel select:focus,
  .signup-form-panel textarea:focus {
    border-color: #3554b8;
    background: #fff;
    box-shadow: 0 0 0 3px rgba(53, 84, 184, 0.12);
  }
  .signup-form-panel input:-webkit-autofill,
  .signup-form-panel input:-webkit-autofill:hover,
  .signup-form-panel input:-webkit-autofill:focus {
    -webkit-text-fill-color: #222 !important;
    caret-color: #222;
    box-shadow: 0 0 0 1000px #f4f7f6 inset !important;
  }
  .role-section-heading {
    margin: 8px 0 2px;
    padding: 10px 12px;
    border-radius: 8px;
    background: rgba(247, 202, 132, 0.12);
    border-left: 4px solid var(--accent);
    color: var(--text-main);
    font-size: 13px;
    font-weight: 700;
  }
  .field-help { display:block; margin-top:-3px; margin-bottom:8px; color:#777; font-size:11px; line-height:1.4; }
  .field-validation-error { display:block; margin-top:-3px; margin-bottom:8px; color:#b42318; font-size:11px; line-height:1.4; }
  .signup-form-panel input[type="file"] { color:#333 !important; padding:8px; }
  .signup-form-panel input[type="file"]::file-selector-button {
    margin-right:10px; padding:7px 12px; border:0; border-radius:6px;
    background:#2438bd; color:#fff; font-weight:600; cursor:pointer;
  }
  .signup-form-panel input[type="file"]::file-selector-button:hover { background:#1f4068; }
  .role-fields[hidden] { display:none !important; }

  @media (max-width: 768px) {
    .modal-box.login-modern-box, .modal-box.signup-modern-box { flex-direction: column; max-width: 450px; margin: 0 auto; }
    .login-info-panel, .signup-info-panel { display: none; }
    .signup-grid { grid-template-columns: 1fr; }
    .signup-grid .full-width { grid-column: span 1; }
  }

/* =========================================================
   REFERENCE DARK LOGIN / SIGNUP SKIN
   Visual-only override. Current form markup + logical sequence remain unchanged.
   ========================================================= */
/* ========== MODERN SPLIT-PANEL LOGIN & SIGNUP ==========
     Dark by default now (matches the rest of the site's default theme);
     a light-mode override further down restores the original white-card
     look for html[data-theme="light"]. */
  .modal-box.login-modern-box, .modal-box.signup-modern-box {
    max-width: 900px;
    padding: 0;
    overflow: hidden;
    display: flex;
    flex-direction: row;
    background: var(--bg-mid);
    border: 1px solid var(--line-bright);
    box-shadow: 0 15px 45px rgba(0,0,0,0.45);
    border-radius: 28px;
  }
  .modal-box.login-modern-box { min-height: 500px; }
  .modal-box.signup-modern-box { max-width: 950px; }

  /* Panel transition for switching between login and signup */
  .login-info-panel, .signup-info-panel,
  .login-form-panel, .signup-form-panel {
    transition: transform 0.45s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.35s ease;
  }

  .modal-switch-enter-left {
    transform: translateX(-40px);
    opacity: 0;
  }
  .modal-switch-enter-right {
    transform: translateX(40px);
    opacity: 0;
  }
  .modal-switch-exit-left {
    transform: translateX(-40px);
    opacity: 0;
    position: absolute;
    pointer-events: none;
  }
  .modal-switch-exit-right {
    transform: translateX(40px);
    opacity: 0;
    position: absolute;
    pointer-events: none;
  }

  /* Shared Panel Styles */
  .login-form-panel, .signup-form-panel {
    flex: 1.2;
    padding: 30px 40px;
    background: var(--bg-mid);
    color: var(--text-main);
    display: flex;
    flex-direction: column;
    justify-content: center;
  }
  .signup-form-panel { flex: 1.3; max-height: 85vh; overflow-y: auto; padding: 25px 35px; justify-content: flex-start; }

  .login-info-panel, .signup-info-panel {
    flex: 0.8;
    background: linear-gradient(150deg, #141c3f 0%, #05060d 100%);
    color: #fff;
    padding: 30px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    text-align: center;
    position: relative;
  }
  .signup-info-panel { flex: 0.7; }

  .login-info-panel::before, .signup-info-panel::before {
    content: "";
    position: absolute;
    top: 0; left: 0; width: 100%; height: 100%;
    background:
      radial-gradient(circle at top right, rgba(247, 202, 132, 0.18), transparent 60%),
      radial-gradient(circle at bottom left, rgba(36, 56, 189, 0.35), transparent 55%);
  }

  /* Specific Element Styles */
  .login-form-panel h2, .signup-form-panel h2 { font-size: 26px; margin-bottom: 15px; font-family: var(--font-display); font-weight: 800; color: var(--text-main); }
  .login-info-panel h2, .signup-info-panel h2 { font-size: 26px; margin-bottom: 12px; color: #fff; font-family: var(--font-display); }
  .login-info-panel p, .signup-info-panel p { font-size: 13px; line-height: 20px; margin: 0 0 20px; opacity: 0.9; }

  /* Reuse existing helper classes */
  .glass-field { background: rgba(255, 255, 255, 0.05); border-radius: 12px; margin-bottom: 12px; display: flex; align-items: center; border: 1px solid var(--line-bright); }
  .glass-field-icon { padding-left: 15px; color: var(--text-faint); display: flex; align-items: center; }
  .glass-field-icon svg { width: 18px; height: 18px; fill: var(--text-faint); }
  .glass-field input { background: transparent !important; margin: 0 !important; color: var(--text-main) !important; padding: 12px 15px !important; border: none !important; width: 100% !important; font-size: 14px !important; outline: none !important; box-shadow: none !important; }
  .glass-field:focus-within {
    border-color: var(--accent);
    box-shadow: 0 0 0 2px rgba(247, 202, 132, 0.2);
  }
  .glass-field input::placeholder { color: var(--text-faint); opacity: 1; }
  .glass-login-btn, .silver-btn { background: var(--bg-panel); color: #fff; border-radius: 25px; border: none; padding: 12px 45px; font-size: 13px; font-weight: 700; text-transform: uppercase; cursor: pointer; width: 100%; transition: all 0.3s ease; font-family: var(--font-display); }
  .glass-login-btn:hover, .silver-btn:hover { background: var(--bg-panel-light); transform: translateY(-2px); box-shadow: 0 5px 15px rgba(36, 56, 189, 0.3); }
  .btn-outline { position: relative; z-index: 1; background-color: transparent; border: 2px solid var(--accent); color: var(--accent); border-radius: 25px; padding: 12px 40px; font-size: 13px; font-weight: 700; text-transform: uppercase; cursor: pointer; transition: all 0.3s ease; font-family: var(--font-display); }
  .btn-outline:hover { background-color: var(--accent); color: #141c3f; box-shadow: 0 10px 24px rgba(247, 202, 132, 0.35); }

  /* Signup Grid */
  .signup-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
  .signup-grid .full-width { grid-column: span 2; }
  .signup-form-panel label { font-size: 12px; margin-bottom: 4px; display: block; font-weight: 600; color: var(--text-muted); }
  .signup-form-panel input, .signup-form-panel select { background: rgba(255, 255, 255, 0.05); border: 1px solid var(--line-bright); border-radius: 8px; padding: 8px 12px; width: 100%; font-size: 13px; margin-bottom: 8px; color: var(--text-main); }
  .signup-form-panel input::placeholder { color: var(--text-faint); opacity: 1; }
  .signup-form-panel select option { background: var(--bg-mid); color: var(--text-main); }
  .signup-form-panel input[type="file"] { color: var(--text-muted); }

  /* Inline-styled bits in the modal markup (close button, dividers, the
     password eye toggle, the manual-captcha card, the privacy-consent
     label, "Forgot Your Password?") were all hand-set to light-mode
     colors in the HTML itself. !important here beats those inline
     styles so they follow the theme too; the light-mode block further
     down re-asserts the original values for html[data-theme="light"]. */
  .login-modern-box .close-modal,
  .signup-modern-box .close-modal {
    color: var(--text-main) !important;
  }
  .login-modern-box .or-divider {
    color: var(--text-faint) !important;
  }
  .login-modern-box .password-toggle-btn {
    color: var(--text-faint) !important;
  }
  .login-modern-box .password-toggle-btn svg,
  .signup-modern-box .password-toggle-btn svg {
    fill: var(--text-faint) !important;
  }
  .login-modern-box .captcha-checkbox-card,
  .signup-modern-box .captcha-checkbox-card {
    background: rgba(255, 255, 255, 0.05) !important;
    border: 1px solid var(--line-bright) !important;
  }
  .login-modern-box .captcha-checkbox-left label,
  .signup-modern-box .captcha-checkbox-left label {
    color: var(--text-muted) !important;
  }
  .login-modern-box .captcha-checkbox-right,
  .signup-modern-box .captcha-checkbox-right {
    color: var(--text-faint) !important;
  }
  .signup-modern-box .privacy-consent-wrap label {
    color: var(--text-muted) !important;
  }
  .signup-modern-box .privacy-consent-wrap a {
    color: #f7d98b !important;
    font-weight: 700;
    text-decoration: underline;
    text-underline-offset: 2px;
  }
  .signup-modern-box .privacy-consent-wrap a:hover {
    color: #fff !important;
  }
  #signupSubmitBtn:disabled {
    cursor: not-allowed;
    opacity: 0.55;
    transform: none;
    box-shadow: none;
  }
  .login-modern-box [href*="forgot_password"] {
    color: var(--text-faint) !important;
  }

  @media (max-width: 768px) {
    .modal-box.login-modern-box, .modal-box.signup-modern-box { flex-direction: column; max-width: 450px; margin: 0 auto; }
    .login-info-panel, .signup-info-panel { display: none; }
    .signup-grid { grid-template-columns: 1fr; }
    .signup-grid .full-width { grid-column: span 1; }
  }

  

/* =========================================================
     LIGHT MODE
     This landing page was built dark-only. This section makes it
     react to the same "nexgen-theme" toggle as the rest of the app
     (set by header.css's topbar toggle / Settings > Personalization,
     read here via theme_init.php).

     Scope: the scrollable marketing page (nav, hero, marquee, stats,
     flip cards, features, about, testimonials, CTA, footer) gets the
     full treatment. The login/signup split-panel modal is dark by
     default now too (see the "MODERN SPLIT-PANEL LOGIN & SIGNUP"
     block above) — its light-mode override lives near the bottom of
     this section, restoring the original white-card look. The captcha/
     cookie/notice popups now also follow the toggle — light mode uses
     a sky-blue surface (not a flat white) to stay on-brand with the
     rest of the light theme.
  ========================= */
  html[data-theme="light"] {
    --bg-deep: #eaf4ff;
    --bg-mid: #d1e8fc;
    --text-main: #0b1f73;
    --text-muted: #2d4570;
  --text-faint: #425577;
    --line: rgba(11, 31, 115, 0.12);
    --line-bright: rgba(11, 31, 115, 0.2);

    /* ---- CAPTCHA (light / sky blue) ---- */
    --captcha-overlay-bg: rgba(9, 40, 74, 0.35);
    --captcha-box-grad-1: #eaf6ff;
    --captcha-box-grad-2: #bfe3fb;
    --captcha-box-border: rgba(11, 61, 107, 0.18);
    --captcha-text: #0b3d6b;
    --captcha-text-soft: rgba(11, 61, 107, 0.78);
    --captcha-close-bg: rgba(11, 61, 107, 0.08);
    --captcha-close-bg-hover: rgba(11, 61, 107, 0.16);
    --captcha-item-bg: rgba(11, 61, 107, 0.06);
    --captcha-check-ring: rgba(11, 61, 107, 0.4);
    --captcha-check-bg: rgba(255, 255, 255, 0.75);
    --captcha-error-bg: rgba(217, 48, 60, 0.10);
    --captcha-error-text: #a3212c;
    --captcha-cancel-bg: rgba(11, 61, 107, 0.08);
    --captcha-cancel-bg-hover: rgba(11, 61, 107, 0.16);
    --captcha-cancel-text: #0b3d6b;
    --captcha-verify-bg: #f7d98b;
    --captcha-verify-text: #17306b;
    --captcha-verified-text: #1f8a3a;
    --captcha-checkbox-card-bg: rgba(11, 61, 107, 0.05);
    --captcha-checkbox-card-border: rgba(11, 61, 107, 0.14);
    --captcha-checkbox-label: #0b3d6b;
    --captcha-checkbox-right: rgba(11, 61, 107, 0.62);
    --captcha-notice-overlay-bg: rgba(9, 40, 74, 0.45);
    --captcha-notice-grad-1: #eaf6ff;
    --captcha-notice-grad-2: #bfe3fb;
    --captcha-notice-border: rgba(11, 61, 107, 0.18);
    --captcha-notice-icon-bg: rgba(217, 48, 60, 0.10);
    --captcha-notice-icon-text: #a3212c;
    --captcha-notice-text: rgba(11, 61, 107, 0.82);
  }

  html[data-theme="light"] header.scrolled {
    background: rgba(255, 255, 255, 0.92);
    box-shadow: 0 4px 30px rgba(10, 20, 64, 0.08);
  }

  html[data-theme="light"] .hero h1 .highlight,
  html[data-theme="light"] .gradient-text {
    background: linear-gradient(135deg, #9a6a12, #0057cc);
    -webkit-background-clip: text;
    background-clip: text;
  }

  /* Cards read as layered "frosted glass on sky" surfaces instead of a
     flat fill: pale gradient base, soft blue ambient shadow, a thin
     glass-edge border, and — on the pieces that already had a hover
     mechanism for it — a gold-to-blue accent bar that sweeps in,
     echoing the same accent-bar signature the dashboard pages use. */
  html[data-theme="light"] .analytics-card {
    background: linear-gradient(160deg, #ffffff 0%, #eaf1fc 100%);
    border-color: rgba(57, 88, 134, 0.16);
    box-shadow: 0 20px 44px rgba(57, 88, 134, 0.16), inset 0 1px 0 rgba(255, 255, 255, 0.8);
  }
  html[data-theme="light"] .marquee-section {
    background: linear-gradient(90deg, #d5deef 0%, #b1c9ef 50%, #d5deef 100%);
  }
  html[data-theme="light"] .stat-card,
  html[data-theme="light"] .value-item,
  html[data-theme="light"] .about-card,
  html[data-theme="light"] .testimonial-card {
    background: linear-gradient(160deg, #ffffff 0%, #eaf1fc 100%);
    border: 1px solid rgba(57, 88, 134, 0.14);
    box-shadow: 0 14px 30px rgba(57, 88, 134, 0.12), inset 0 1px 0 rgba(255, 255, 255, 0.7);
  }
  html[data-theme="light"] .stat-card:hover,
  html[data-theme="light"] .testimonial-card:hover {
    border-color: rgba(154, 106, 18, 0.4);
    box-shadow: 0 22px 44px rgba(57, 88, 134, 0.18), inset 0 1px 0 rgba(255, 255, 255, 0.8);
  }
  html[data-theme="light"] .stat-card::before {
    background: linear-gradient(90deg, #9a6a12, #3b82f6, #9a6a12);
  }
  html[data-theme="light"] .stat-card .stat-icon {
    background: radial-gradient(circle at 30% 30%, rgba(154, 106, 18, 0.2), rgba(59, 130, 246, 0.12));
    box-shadow: inset 0 0 0 1px rgba(154, 106, 18, 0.2);
  }
  html[data-theme="light"] footer {
    background: linear-gradient(180deg, #f0f3fa 0%, #d5deef 100%);
  }

  html[data-theme="light"] .flip-front,
  html[data-theme="light"] .flip-back {
    border: 1px solid rgba(57, 88, 134, 0.16);
  }
  html[data-theme="light"] .flip-front {
    background: linear-gradient(160deg, #ffffff 0%, #eaf1fc 100%);
    box-shadow: 0 14px 30px rgba(57, 88, 134, 0.12), inset 0 1px 0 rgba(255, 255, 255, 0.7);
  }
  html[data-theme="light"] .flip-front::after {
    content: "";
    position: absolute;
    top: 0;
    left: 18px;
    right: 18px;
    height: 3px;
    border-radius: 0 0 4px 4px;
    background: linear-gradient(90deg, #9a6a12, #3b82f6);
    transform: scaleX(0);
    transform-origin: left;
    transition: transform 0.35s ease;
  }
  html[data-theme="light"] .flip-card:hover .flip-front::after {
    transform: scaleX(1);
  }
  html[data-theme="light"] .flip-back {
    background: linear-gradient(160deg, #dce8fb 0%, #b1c9ef 100%);
    border-color: rgba(57, 88, 134, 0.3);
    box-shadow: 0 16px 34px rgba(57, 88, 134, 0.16);
  }
  html[data-theme="light"] .flip-icon {
    background: radial-gradient(circle at 30% 30%, rgba(154, 106, 18, 0.22), rgba(59, 130, 246, 0.12));
    box-shadow: 0 6px 14px rgba(57, 88, 134, 0.18), inset 0 0 0 1px rgba(154, 106, 18, 0.2);
  }
  html[data-theme="light"] .flip-card:hover .flip-icon {
    box-shadow: 0 8px 18px rgba(154, 106, 18, 0.28), inset 0 0 0 1px rgba(154, 106, 18, 0.35);
  }

  html[data-theme="light"] .testimonials::before {
    background: linear-gradient(180deg, transparent, rgba(57, 88, 134, 0.08), transparent);
  }

  /* small "silvery line" accents scattered through hero/footer/buttons */
  html[data-theme="light"] .play-circle {
    background: rgba(11, 31, 115, 0.04);
  }
  html[data-theme="light"] .about-card-bar {
    background: rgba(11, 31, 115, 0.08);
  }
  html[data-theme="light"] .btn-ghost:hover {
    background: rgba(11, 31, 115, 0.06);
    border-color: rgba(11, 31, 115, 0.2);
  }
  html[data-theme="light"] .footer-social a {
    background: rgba(11, 31, 115, 0.05);
  }
  html[data-theme="light"] .footer-contact .contact-icon {
    background: rgba(11, 31, 115, 0.05);
  }

  html[data-theme="light"] .btn-ghost {
    color: var(--text-main);
  }
  html[data-theme="light"] .play-link {
    color: var(--text-main);
  }
  html[data-theme="light"] .nav-toggle {
    color: var(--text-main);
  }
html[data-theme="light"] .cta-box {
  background: linear-gradient(135deg, #3b82f6 0%, #0033ff 60%, #0b1f73 100%);
  border-color: rgba(255, 221, 85, 0.4);
  box-shadow: 0 24px 50px rgba(10, 20, 64, 0.18);
}
html[data-theme="light"] .cta-box h2 {
  color: #ffffff;
}
html[data-theme="light"] .cta-box p {
  color: rgba(255, 255, 255, 0.85);
}
html[data-theme="light"] .cta-box .btn-ghost {
  color: #ffffff;
  border-color: rgba(255, 255, 255, 0.4);
}
html[data-theme="light"] .cta-box .btn-ghost:hover {
  background: rgba(255, 255, 255, 0.14);
  border-color: rgba(255, 255, 255, 0.5);
}
html[data-theme="light"] .avatar-placeholder {
  color: rgba(255, 255, 255, 0.7);
}
html[data-theme="light"] {
  --accent-ink: #9a6a12;
}

/* accent text/icons on light or white backgrounds need the darker ink,
   not the pastel gold that was tuned for dark backgrounds */
html[data-theme="light"] .eyebrow,
html[data-theme="light"] .hero-eyebrow,
html[data-theme="light"] .stats-header .eyebrow,
html[data-theme="light"] .how-header-full .eyebrow,
html[data-theme="light"] .stat-card .stat-icon,
html[data-theme="light"] .stat-card .stat-number .suffix,
html[data-theme="light"] .flip-icon,
html[data-theme="light"] .flip-hint,
html[data-theme="light"] .flip-hint-back,
html[data-theme="light"] .phone-screen-tag,
html[data-theme="light"] .phone-amount,
html[data-theme="light"] .value-item:nth-child(1) .value-icon,
html[data-theme="light"] .testimonial-card .stars {
  color: var(--accent-ink);
}
html[data-theme="light"] .eyebrow::before,
html[data-theme="light"] .hero-eyebrow .line {
  background: var(--accent-ink);
}

/* stat-badge keeps its fixed dark-navy panel in both themes —
   its label text needs to stay light, not follow the page's text color */
html[data-theme="light"] .stat-badge .label {
  color: rgba(255, 255, 255, 0.75);
}

html[data-theme="light"] .wave-back {
  fill: rgba(11, 31, 115, 0.18);
}
html[data-theme="light"] .wave-mid {
  fill: rgba(11, 31, 115, 0.38);
}
html[data-theme="light"] .wave-crest {
  stroke: rgba(11, 31, 115, 0.7);
  stroke-width: 3;
  filter: drop-shadow(0 0 6px rgba(11, 31, 115, 0.35));
}

/* ---- LOGIN / SIGNUP MODAL — light mode ----
   Restores the original white-card design pixel-for-pixel, including
   the inline-styled bits the !important rules above target. */
html[data-theme="light"] .modal-box.login-modern-box,
html[data-theme="light"] .modal-box.signup-modern-box {
  background: #fff;
  border: none;
  box-shadow: 0 15px 35px rgba(0,0,0,0.2);
}
html[data-theme="light"] .login-form-panel,
html[data-theme="light"] .signup-form-panel {
  background: #fff;
  color: #333;
}
html[data-theme="light"] .login-form-panel h2,
html[data-theme="light"] .signup-form-panel h2 {
  color: #333;
}
html[data-theme="light"] .glass-field {
  background: #f4f7f6;
  border-color: #eee;
}
html[data-theme="light"] .glass-field:focus-within {
  border-color: #3554b8;
  box-shadow: 0 0 0 3px rgba(53, 84, 184, 0.12);
}
html[data-theme="light"] .glass-field-icon {
  color: #777;
}
html[data-theme="light"] .glass-field-icon svg {
  fill: #777;
}
html[data-theme="light"] .glass-field input {
  color: #333 !important;
}
html[data-theme="light"] .glass-field input::placeholder {
  color: #999;
}
html[data-theme="light"] .signup-form-panel label {
  color: #666;
}
html[data-theme="light"] .signup-form-panel input,
html[data-theme="light"] .signup-form-panel select {
  background: #f4f7f6;
  border-color: #eee;
  color: #333;
}
html[data-theme="light"] .signup-form-panel input::placeholder {
  color: #999;
}
html[data-theme="light"] .signup-form-panel select option {
  background: #fff;
  color: #333;
}
html[data-theme="light"] .signup-form-panel input[type="file"] {
  color: #666;
}
html[data-theme="light"] .login-modern-box .close-modal,
html[data-theme="light"] .signup-modern-box .close-modal {
  color: #333 !important;
}
html[data-theme="light"] .login-modern-box .or-divider {
  color: #777 !important;
}
html[data-theme="light"] .login-modern-box .password-toggle-btn {
  color: #777 !important;
}
html[data-theme="light"] .login-modern-box .password-toggle-btn svg,
html[data-theme="light"] .signup-modern-box .password-toggle-btn svg {
  fill: #777 !important;
}
html[data-theme="light"] .login-modern-box .captcha-checkbox-card,
html[data-theme="light"] .signup-modern-box .captcha-checkbox-card {
  background: #f9f9f9 !important;
  border: 1px solid #eee !important;
}
html[data-theme="light"] .login-modern-box .captcha-checkbox-left label,
html[data-theme="light"] .signup-modern-box .captcha-checkbox-left label {
  color: #555 !important;
}
html[data-theme="light"] .login-modern-box .captcha-checkbox-right,
html[data-theme="light"] .signup-modern-box .captcha-checkbox-right {
  color: #999 !important;
}
html[data-theme="light"] .signup-modern-box .privacy-consent-wrap label {
  color: #666 !important;
}
html[data-theme="light"] .login-modern-box [href*="forgot_password"] {
  color: #777 !important;
}
html[data-theme="light"] .login-info-panel,
html[data-theme="light"] .signup-info-panel {
  background: linear-gradient(135deg, var(--bg-panel), var(--bg-panel-light));
}
html[data-theme="light"] .login-info-panel::before,
html[data-theme="light"] .signup-info-panel::before {
  background: radial-gradient(circle at top right, rgba(255, 255, 255, 0.1), transparent);
}
html[data-theme="light"] .btn-outline {
  border-color: #fff;
  color: #fff;
}
html[data-theme="light"] .btn-outline:hover {
  background-color: #fff;
  color: var(--bg-panel);
  box-shadow: none;
}


/* Current index(3) keeps Trust & Security; make it follow the reference light theme too. */
html[data-theme="light"] .trust-security::before {
  background: linear-gradient(180deg, transparent, rgba(255,255,255,.58), transparent);
}
html[data-theme="light"] .trust-card {
  background: rgba(255,255,255,.72);
  border-color: rgba(11,31,115,.12);
  box-shadow: 0 16px 34px rgba(49,93,154,.08);
}
html[data-theme="light"] .trust-card .trust-title { color: #0b1f73; }
html[data-theme="light"] .trust-card .trust-desc { color: #2d4570; }

/* ========== FORGOT PASSWORD MODAL ========== */
.modal-box.forgot-modern-box {
  max-width: 460px;
  padding: 36px 34px;
  background: var(--bg-mid);
  border: 1px solid var(--line-bright);
  box-shadow: 0 15px 45px rgba(0,0,0,0.45);
  border-radius: 28px;
  color: var(--text-main);
}
.forgot-modern-box h2 {
  font-size: 26px;
  font-weight: 800;
  font-family: var(--font-display);
  text-align: center;
  margin-bottom: 6px;
}
.forgot-modern-box .forgot-subtext {
  text-align: center;
  font-size: 13px;
  color: var(--text-muted);
  margin-bottom: 20px;
  line-height: 1.5;
}
.forgot-modern-box .glass-field-icon { color: var(--text-faint); }
.forgot-modern-box .password-toggle-btn { color: var(--text-faint) !important; }
.forgot-modern-box .password-toggle-btn svg { fill: var(--text-faint) !important; }
.forgot-step { display: flex; flex-direction: column; }
.forgot-step[hidden] { display: none !important; }

.forgot-inline-alert {
  border-radius: 10px;
  padding: 10px 14px;
  font-size: 13px;
  margin-bottom: 14px;
  line-height: 1.4;
  display: none;
}
.forgot-inline-alert.show { display: block; }
.forgot-inline-alert.error { background: rgba(220,70,70,0.15); border: 1px solid rgba(220,70,70,0.4); color: #ff9a9a; }
.forgot-inline-alert.success { background: rgba(16,160,80,0.15); border: 1px solid rgba(16,160,80,0.4); color: #7be3ab; }

.forgot-account-list { display: flex; flex-direction: column; gap: 10px; margin-bottom: 18px; }
.forgot-account-option {
  display: flex; align-items: center; gap: 12px;
  padding: 13px 14px;
  border-radius: 12px;
  border: 1px solid var(--line-bright);
  background: rgba(255,255,255,0.05);
  cursor: pointer;
  font-size: 14px;
  color: var(--text-main);
  transition: border-color 0.2s ease, background 0.2s ease;
}
.forgot-account-option:hover { border-color: var(--accent-dim); }
.forgot-account-option input[type="radio"] { width: auto; accent-color: var(--accent-dim); }
.forgot-account-option .account-business { color: var(--text-faint); font-size: 12px; display: block; margin-top: 2px; }

.forgot-otp-input {
  letter-spacing: 6px;
  text-align: center;
  font-size: 20px;
  font-weight: 700;
}

.forgot-resend-row {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  margin-top: 4px;
  font-size: 13px;
  color: var(--text-muted);
}
.forgot-resend-btn {
  background: none; border: none; padding: 0;
  color: var(--accent-dim); font-weight: 700; cursor: pointer; font-size: 13px;
  font-family: var(--font-body);
}
.forgot-resend-btn:disabled { color: var(--text-faint); cursor: default; }

.forgot-step-links { display:flex; justify-content:center; gap:16px; margin-top:18px; font-size:13px; }
.forgot-step-links button {
  background:none; border:none; padding:0; cursor:pointer;
  color: var(--text-faint); font-weight:700; font-size:13px; text-decoration:none; font-family: var(--font-body);
}
.forgot-step-links button:hover { color: var(--text-main); }

.forgot-masked-email { text-align:center; font-size:13px; color:var(--text-muted); margin-bottom:16px; }
.forgot-masked-email strong { color: var(--text-main); }

@media (max-width: 480px) {
  .modal-box.forgot-modern-box { padding: 28px 22px; max-width: 92vw; }
}

html[data-theme="light"] .modal-box.forgot-modern-box {
  background: #fff;
  border: none;
  box-shadow: 0 15px 35px rgba(0,0,0,0.2);
  color: #333;
}
html[data-theme="light"] .forgot-modern-box .forgot-subtext,
html[data-theme="light"] .forgot-masked-email { color: #666; }
html[data-theme="light"] .forgot-masked-email strong { color: #222; }
html[data-theme="light"] .forgot-account-option {
  background: #f4f7f6;
  border-color: #eee;
  color: #333;
}
html[data-theme="light"] .forgot-account-option .account-business { color: #888; }
html[data-theme="light"] .forgot-resend-row { color: #777; }
html[data-theme="light"] .forgot-resend-btn { color: #3554b8; }
html[data-theme="light"] .forgot-resend-btn:disabled { color: #aaa; }
html[data-theme="light"] .forgot-step-links button { color: #777; }
html[data-theme="light"] .forgot-step-links button:hover { color: #333; }
html[data-theme="light"] .forgot-inline-alert.error { background: rgba(220,70,70,0.08); border-color: rgba(220,70,70,0.35); color:#a12525; }
html[data-theme="light"] .forgot-inline-alert.success { background: rgba(16,160,80,0.08); border-color: rgba(16,160,80,0.35); color:#0d7a3f; }

/* Keep every registration field readable in all interaction states.
   These rules intentionally come last so older light-form declarations do
   not turn focused or browser-autofilled fields white while dark mode is on. */
.signup-modern-box .signup-form-panel input:not([type="checkbox"]):not([type="radio"]):not([type="hidden"]),
.signup-modern-box .signup-form-panel select,
.signup-modern-box .signup-form-panel textarea {
  background-color: rgba(255, 255, 255, 0.05) !important;
  color: var(--text-main) !important;
  caret-color: var(--text-main);
  color-scheme: dark;
}

.signup-modern-box .signup-form-panel input:not([type="checkbox"]):not([type="radio"]):not([type="hidden"])::placeholder,
.signup-modern-box .signup-form-panel textarea::placeholder {
  color: var(--text-muted) !important;
  opacity: 0.78;
}

.signup-modern-box .signup-form-panel input:not([type="checkbox"]):not([type="radio"]):not([type="hidden"]):focus,
.signup-modern-box .signup-form-panel select:focus,
.signup-modern-box .signup-form-panel textarea:focus {
  background-color: rgba(255, 255, 255, 0.09) !important;
  color: var(--text-main) !important;
  border-color: rgba(247, 202, 132, 0.75);
  box-shadow: 0 0 0 3px rgba(247, 202, 132, 0.14);
}

.signup-modern-box .signup-form-panel select option {
  background-color: var(--bg-mid) !important;
  color: var(--text-main) !important;
}

.signup-modern-box .signup-form-panel input:-webkit-autofill,
.signup-modern-box .signup-form-panel input:-webkit-autofill:hover,
.signup-modern-box .signup-form-panel input:-webkit-autofill:focus,
.signup-modern-box .signup-form-panel input:autofill {
  -webkit-text-fill-color: var(--text-main) !important;
  caret-color: var(--text-main);
  -webkit-box-shadow: 0 0 0 1000px #1d2d50 inset !important;
  box-shadow: 0 0 0 1000px #1d2d50 inset !important;
}

.signup-modern-box .signup-form-panel input[type="file"] {
  color: var(--text-muted) !important;
}

html[data-theme="light"] .signup-modern-box .signup-form-panel input:not([type="checkbox"]):not([type="radio"]):not([type="hidden"]),
html[data-theme="light"] .signup-modern-box .signup-form-panel select,
html[data-theme="light"] .signup-modern-box .signup-form-panel textarea {
  background-color: #f4f7f6 !important;
  color: #333 !important;
  caret-color: #333;
  color-scheme: light;
}

html[data-theme="light"] .signup-modern-box .signup-form-panel input:not([type="checkbox"]):not([type="radio"]):not([type="hidden"])::placeholder,
html[data-theme="light"] .signup-modern-box .signup-form-panel textarea::placeholder {
  color: #777 !important;
  opacity: 1;
}

html[data-theme="light"] .signup-modern-box .signup-form-panel input:not([type="checkbox"]):not([type="radio"]):not([type="hidden"]):focus,
html[data-theme="light"] .signup-modern-box .signup-form-panel select:focus,
html[data-theme="light"] .signup-modern-box .signup-form-panel textarea:focus {
  background-color: #fff !important;
  color: #222 !important;
  border-color: #3554b8;
  box-shadow: 0 0 0 3px rgba(53, 84, 184, 0.12);
}

html[data-theme="light"] .signup-modern-box .signup-form-panel select option {
  background-color: #fff !important;
  color: #222 !important;
}

html[data-theme="light"] .signup-modern-box .signup-form-panel input:-webkit-autofill,
html[data-theme="light"] .signup-modern-box .signup-form-panel input:-webkit-autofill:hover,
html[data-theme="light"] .signup-modern-box .signup-form-panel input:-webkit-autofill:focus,
html[data-theme="light"] .signup-modern-box .signup-form-panel input:autofill {
  -webkit-text-fill-color: #222 !important;
  caret-color: #222;
  -webkit-box-shadow: 0 0 0 1000px #f4f7f6 inset !important;
  box-shadow: 0 0 0 1000px #f4f7f6 inset !important;
}

html[data-theme="light"] .signup-modern-box .signup-form-panel input[type="file"] {
  color: #666 !important;
}

/* ========== FINAL RESPONSIVE HEADER + AUTH MODALS ========== */
/* Keep off-canvas navigation and decorative content from widening the
   document on phones and tablets. */
@media (max-width: 980px) {
  html,
  body {
    width: 100%;
    max-width: 100%;
    overflow-x: hidden;
  }

  header,
  .nav,
  .hero,
  .hero-grid {
    width: 100%;
    max-width: 100%;
  }

  .nav {
    gap: 12px;
  }

  .logo {
    min-width: 0;
    flex: 0 1 auto;
    white-space: nowrap;
  }

  .nav-cta {
    min-width: 0;
    flex: 0 0 auto;
    margin-left: auto;
  }

  .nav-toggle {
    flex: 0 0 44px;
    padding: 0;
    line-height: 1;
  }

  .nav-links {
    max-width: 100vw;
  }

  .hero-grid > *,
  .hero-content {
    min-width: 0;
    max-width: 100%;
  }
}

/* Collapse the split authentication cards for portrait tablets and phones. */
@media (max-width: 820px) {
  .modal {
    padding: 16px;
    overflow-y: auto;
    overscroll-behavior: contain;
  }

  .modal-box.login-modern-box,
  .modal-box.signup-modern-box {
    width: 100%;
    max-width: 420px;
    min-height: 0;
    max-height: calc(100vh - 32px);
    max-height: calc(100dvh - 32px);
    margin: auto;
    flex-direction: column;
    border-radius: 22px;
  }

  .login-info-panel,
  .signup-info-panel {
    display: none;
  }

  .login-form-panel,
  .signup-form-panel {
    width: 100%;
    min-width: 0;
    flex: 0 0 auto;
    padding: 28px 24px 24px;
  }

  .signup-form-panel {
    max-height: calc(100vh - 32px);
    max-height: calc(100dvh - 32px);
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
  }

  .signup-grid {
    grid-template-columns: minmax(0, 1fr);
  }

  .signup-grid .full-width {
    grid-column: auto;
  }

  .signup-grid > * {
    min-width: 0;
  }

  .glass-field input,
  .signup-form-panel input,
  .signup-form-panel select,
  .signup-form-panel textarea {
    font-size: 16px !important;
  }

  .login-modern-box .close-modal,
  .signup-modern-box .close-modal {
    top: 6px !important;
    right: 8px !important;
    width: 44px;
    height: 44px;
    padding: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 36px;
    line-height: 1;
  }
}

/* Phone sizing: retain visible page gutters and compact modal spacing. */
@media (max-width: 560px) {
  .wrap {
    width: 100%;
    max-width: 100%;
    padding-right: 18px;
    padding-left: 18px;
  }

  .nav,
  header.scrolled .nav {
    padding: 14px 16px;
    gap: 8px;
  }

  .logo {
    gap: 7px;
    font-size: 20px;
  }

  .logo-img {
    width: 34px;
    height: 34px;
  }

  .nav-cta {
    gap: 7px;
  }

  .landing-theme-toggle,
  .nav-toggle {
    width: 40px;
    height: 40px;
    flex-basis: 40px;
  }

  .nav-cta .btn {
    padding: 9px 14px;
  }

  .team-join {
    flex-direction: column;
    align-items: flex-start;
    gap: 12px;
    max-width: 100%;
  }

  .team-join-label {
    max-width: none;
  }

  .modal {
    padding: 12px;
  }

  .modal-box.login-modern-box,
  .modal-box.signup-modern-box {
    max-width: 390px;
    max-height: calc(100vh - 24px);
    max-height: calc(100dvh - 24px);
    border-radius: 18px;
  }

  .login-form-panel,
  .signup-form-panel {
    padding: 24px 18px 20px;
  }

  .signup-form-panel {
    max-height: calc(100vh - 24px);
    max-height: calc(100dvh - 24px);
  }

  .login-form-panel h2,
  .signup-form-panel h2 {
    margin-bottom: 12px;
    font-size: 24px;
  }

  .captcha-checkbox-card {
    gap: 8px;
    min-width: 0;
    padding: 12px;
  }

  .captcha-checkbox-left {
    min-width: 0;
  }

  .captcha-checkbox-right {
    min-width: 56px;
  }
}

/* Extra room for compact Android phones and iPhone SE-sized screens. */
@media (max-width: 360px) {
  .wrap {
    padding-right: 14px;
    padding-left: 14px;
  }

  .nav,
  header.scrolled .nav {
    padding: 12px;
    gap: 6px;
  }

  .logo {
    gap: 6px;
    font-size: 18px;
  }

  .logo-img {
    width: 30px;
    height: 30px;
  }

  .nav-cta {
    gap: 6px;
  }

  .landing-theme-toggle,
  .nav-toggle {
    width: 38px;
    height: 38px;
    flex-basis: 38px;
  }

  .nav-cta .btn {
    padding: 8px 11px;
    font-size: 12px;
  }

  .login-form-panel,
  .signup-form-panel {
    padding: 22px 14px 18px;
  }

  .captcha-checkbox-card {
    padding: 10px;
  }

  .captcha-checkbox-left label {
    font-size: 12px !important;
  }

  .captcha-checkbox-right {
    min-width: 52px;
  }
}

</style>
</head>
<body>

<?php if (!empty($popupMessage)): ?>
    <div class="popup-overlay" id="popupOverlay">
        <div class="popup-box <?php echo $popupType; ?>" id="popupBox">
            <div class="popup-icon">
                <?php echo $popupType === "success" ? "✓" : "!"; ?>
            </div>
            <h3><?php echo $popupType === "success" ? "Success" : "Error"; ?></h3>
            <p><?php echo htmlspecialchars($popupMessage); ?></p>
        </div>
    </div>
<?php endif; ?>


    <div class="modal" id="loginModal" style="display:none;">
        <div class="modal-box login-modern-box">
            <button class="close-modal" id="closeLogin" type="button" style="color: #333; top: 15px; right: 20px; z-index: 10;">&times;</button>

            <!-- Sign In Modal: Info Left, Form Right -->
            <div class="login-info-panel">
                <h2>Hello, Friend!</h2>
                <p>Register with your personal details to use all of site features</p>
                <button class="btn-outline" id="goToSignup">Sign Up</button>
            </div>

            <div class="login-form-panel">
                <h2>Sign In</h2>
              
               
                <form action="<?php echo e(nxAppUrl('login_process.php')); ?>" method="POST" id="loginForm">
                    <input type="hidden" name="login_portal" value="client">
                    <input type="hidden" name="csrf_token" value="<?php echo e($loginCsrfToken); ?>">
                    <div class="glass-field">
                        <span class="glass-field-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.6"></circle><path d="M4.5 20c1.2-3.8 4.2-5.8 7.5-5.8s6.3 2 7.5 5.8"></path></svg></span>
                        <input type="text" name="username" id="loginUsername" placeholder="Username" required value="<?php echo htmlspecialchars($lockoutUsername); ?>">
                    </div>
                    <div class="glass-field password-field-wrap">
                        <span class="glass-field-icon"><svg viewBox="0 0 24 24"><rect x="5" y="10.5" width="14" height="9.5" rx="2.2"></rect><path d="M8 10.5V7.8a4 4 0 0 1 8 0v2.7"></path></svg></span>
                        <input type="password" name="password" id="loginPassword" placeholder="Password" required>
                        <button type="button" class="password-toggle-btn" data-target="loginPassword" style="background:none; border:none; color:#777; cursor:pointer; padding-right:15px;">
                            <span class="eye-icon eye-open"><svg viewBox="0 0 24 24" style="width:20px; height:20px; fill:#777;"><path d="M2 12s3.6-6 10-6 10 6 10 6-3.6 6-10 6-10-6-10-6Z"></path><circle cx="12" cy="12" r="3.2"></circle></svg></span>
                            <span class="eye-icon eye-closed" style="display:none;"><svg viewBox="0 0 24 24" style="width:20px; height:20px; fill:#777;"><path d="M3 3l18 18"></path><path d="M10.6 6.3A11.2 11.2 0 0 1 12 6c6.4 0 10 6 10 6a17.6 17.6 0 0 1-3.1 3.8"></path><path d="M6.7 6.8C4.1 8.5 2 12 2 12a17.3 17.3 0 0 0 10 6c1.4 0 2.7-.2 3.8-.7"></path><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"></path></svg></span>
                        </button>
                    </div>
                    <p class="login-lock-note" id="loginLockNote" style="color:#dc3545; font-size:12px; margin-bottom:10px; display:none;">This specific account is temporarily locked.</p>
                    <div class="captcha-checkbox-wrap" style="margin:10px 0;"><div class="captcha-checkbox-card"><div class="captcha-checkbox-left" style="gap:8px;"><input type="checkbox" id="loginRobotCheck"><label for="loginRobotCheck" style="font-size:13px; margin:0;">I'm not a robot</label></div><div class="captcha-checkbox-right"><img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAEQAAABECAYAAAA4E5OyAAAHCklEQVR42u3bW0wUVxgH8P+Zs4wLTllZhHKTFJB4qRoN9UJEJbaULbYa0tRbYh9qb4/2oSZtGtPWxjbpiw9N7IumVdNL1MRblcZGocULGpUoERNKDeiyuMRdwWFZZ3f29MHudpbLsrszZy8J5wVIltkzv/m+73znLBDGGCYaRy4q7PsWHyxmAl5j0Mvw8OIFSIKCPKsZJc9nomyWhNISCQvmzkTxrJnIkQi/CYwaJBIIAOw/95TtbfEhP4vfnO41nYHXFwAADCvP5jPkZ4CsAhLF64st2FBXgoZXylFUIJGkggQj5cuzCjeUe01n4FPHn4caYBhW2DMghw+1qyz4+MOFaKgrJ0kD4R0pkUDGA3I4fXhx7gx89m4FNjfOM3RCQrQv3F43jexYkwGnhyGZgwoEJQUiXK5hbPnkBhasO82u3XTENam+fpnFDZJKKCGY3Ay4XMNYVn8Ou76+xGLFqNl4FrpAtCheP1JiUIGgpNKM3b/0oHz1ETbeUx8Po3jl8VAh1wUCAI9khlQbJRKF1xdAcf0pREqhvn6ZvdT4G7JnmGDOEPSDvLdPZiduqzCbUs4EVCAozBKwrP4cWi72snEjo/YEAGC6SPQV1SDGnQGSkhhhKBXTUPv2n2EowcgozDGBCkT/KvPRDyMpjxGGkp+B2sZmdHQOMLfMQpERCQMAorq9/eeesj/+Vrl2q7wiZf0Hzch6LnPSyIga5PJdP/fWnSeK1xeA1zUcFUZUIO8f9qYlhhYllhER5NOfRli2yB9D2w/EegNGDyFSqpy6k5jldePLhZhTZIbDE8CDLi8ePPJhyBtICsiEt7v7uAf5WZR/dPiBvV+tJtrlsf22E/sO38XpNjeyp1Nkm4XkRsjlu352XxYMu+HJ2ny3/P+Wu6hAIg115eTUjw3E3rwBG6pz8aBfgRpgyQM5cMGYQur0MKyZLWDDQhrXhrCoQCIHv6sjzQdXw+EJJCSNxqRMtyPArtiB/Cz9GDvWZGB73bT/ZL3s0HV/XNBrVpYS++9W9ur2Ztzvl7mm0JgrH7uiQO/K4vQw7HpN1GAAOxvNZFuVKe6jg6ICiVz5tRbZZoFr+owBOXTdr2tlcXoYtlWZ8NbKsap6USRJIm3HGuDwBLihCKPTRW8BrbQK2Nk48TG9EZHy8+eL4fAE+IPc+MevK12GFIZvt5gnfd3ORjN5Y358hdYtM7b3QCcKswT+IJe6VF3R8cpsiorC6FrNPVszybYqE4aU6FFkWWaL6o+gs0/h1tGGVYseF4u7fgwpDO/UijH9zs5GM3k8MsKijYxVm5ox5A1wXWVM2jd8KMcPUmkVsPAFGvNj27M1k0QTGVUNZ/FoWOXetYau7nrCYgrf0emyoozPRN0yYys2NScEIyxCPE/1LWNl+cZPNpGRMQZE9sYPMqQwlOQKhkdGVcNZ3Bv0I9tEuLTtw4oauajOkgKgJPJKozI6bubNLTF2Z/zFN3+hZtEM1GeZME007tpPlcj3F/ps1263sxtXm+N+oycj0dXT9evXQ5KklD2CM42+KVEU47pQNL9ms9lSGiPiiZmRQ1XVtMBIGMi6devSAoM7SDpFBncQVVXTKjK4gqQrBjeQRGA0NTWxtABRVRWKonB9iteuXWMulwtHjx5lKQ9CKcX58+e5YdjtdtbV1QVRFKGqquGRwq2G8Hh6LpeLtbS0hJpHSikGBwcNReECQikNociyMX9/1d3dzZqamsZ00kajcFt2KX22ITt58iQ6Ojp0Tba1tZW1tbVNuK0wEiVsc6cNRyOHoiiwWq2orKxERUUFiSUqbt68CVVVQ8CTparFYoHNZiMpDaKdsKqqKCgoQH5+PnJycmCxWMLg3G43HA4Hent7QSmNCsJIlISCaCet/RqsOdpUixVi9PXz8vKwdu1akjI1ZLL6QimFKIoQRXHM93owgtcfGBiIq6YkBSRR6IODg2htbWWGgwTDOR1Rent70d3dzQwDUVUV8xYu496O86pVpaWlMa1spsmWS80ZKOtov5yQomsUhsViQU1NDTEkZUYf7iyaX0YWLK5Oi0jRs/SaJooMm80Gq9UadsFF88tSPlL0LLnjRshEGFqUBYur8fjx45TDUBQF5eXlcWOMCxIJQ4vy5ubtkCQpJVIo2AEvX74cS5cu1XUwFdapAkBxcXFMF+zo6GC3bt0ypKHSs0+qqakx5JQu6v/KjDRkWWZXr15Ff39/wmAURYEoiqiuro75IXIH0R7gtLe3c4MJpoYoiliyZElM/UVSQLQR09nZiZ6eHiiKomvDFkSglCIvLw9z5swxNCISAjI6avr6+uB0OuFyuUJFeCIc7TbBarUiNzcXRUVFXBESCjIRktvtDv08MjKCzMxMAIDZbIbFYknaZzpJAUnlIUwRTIFMgUyBTIEYN/4F2c+TyekuGRIAAAAASUVORK5CYII=" alt="reCAPTCHA" width="32" height="32" style="width:32px;height:32px;object-fit:contain;display:block;" aria-hidden="true"><span>Manual<br>Captcha</span></div></div><div class="captcha-verified-text" id="loginVerifiedText" style="color:#28a745; font-size:12px; margin-top:5px; display:none;">Captcha verified.</div></div>
                    <div style="text-align:center; margin:10px 0 20px;"><a href="<?php echo e(nxAppUrl('forgot_password.php?portal=client')); ?>" id="forgotPasswordLink" style="color:#777; font-size:13px; text-decoration:none;">Forgot Your Password?</a></div>
                    <button type="submit" class="glass-login-btn" id="loginSubmitBtn">Sign In</button>
                </form>
            </div>
        </div>
    </div>
    <div class="modal" id="signupModal" style="display:none;">
        <div class="modal-box signup-modern-box">
            <button class="close-modal" id="closeSignup" type="button" style="color: #333; top: 15px; right: 20px; z-index: 10;">&times;</button>

            <!-- Sign Up Modal: Form Left, Info Right -->
            <div class="signup-form-panel">
                <h2>Create Account</h2>
                <form action="/NexGen/CODE/PHP/signup_process.php" method="POST" enctype="multipart/form-data" id="signupForm">
                    <input type="hidden" name="csrf_token" value="<?php echo e($signupCsrfToken); ?>">
                    <input type="hidden" name="privacy_ack_token" value="<?php echo e($signupPrivacyAckToken); ?>">
                    <div class="signup-grid">
                        <div>
                            <label for="signupUsername">Username</label>
                            <input type="text" name="signup_username" id="signupUsername" required minlength="3" maxlength="50" pattern="[A-Za-z0-9_.-]{3,50}" placeholder="Choose a username" autocomplete="username">
                        </div>

                        <div>
                            <label for="requestedRole">Requested Role</label>
                            <select name="requested_role" id="requestedRole" required>
                                <option value="">Select role</option>
                                <option value="owner">SME Owner</option>
                                <option value="employee">Employee / Staff</option>
                            </select>
                        </div>

                        <div class="full-width">
                            <label for="fullName">Full Name</label>
                            <input type="text" name="fullname" id="fullName" required minlength="2" maxlength="100" placeholder="Enter your complete name" autocomplete="name">
                        </div>

                        <div>
                            <label for="signupEmail">Email Address</label>
                            <input type="email" name="email" id="signupEmail" required maxlength="100" placeholder="example@email.com" autocomplete="email">
                        </div>

                        <div>
                            <label for="signupPhone">Phone Number</label>
                            <input type="tel" name="phone" id="signupPhone" required placeholder="09XXXXXXXXX" autocomplete="tel" inputmode="numeric" minlength="11" maxlength="11" pattern="[0-9]{11}" title="Please enter an 11-digit Philippine phone number using digits only.">
                        </div>

                        <div class="full-width">
                            <label for="homeAddress">Personal Address</label>
                            <input type="text" name="address" id="homeAddress" required minlength="5" maxlength="500" placeholder="Enter your residential address" autocomplete="street-address">
                        </div>

                        <div class="full-width role-fields owner-fields" hidden>
                            <div class="role-section-heading">SME Business Information</div>
                        </div>

                        <div class="full-width role-fields owner-fields" hidden>
                            <label for="businessName">Business Name</label>
                            <input type="text" name="business_name" id="businessName" minlength="2" maxlength="150" placeholder="Enter the business name">
                        </div>

                        <div class="role-fields owner-fields" hidden>
                            <label for="businessType">Business Type</label>
                            <select name="business_type" id="businessType">
                                <option value="">Select business type</option>
                                <option value="Hardware / Construction Supplies">Hardware / Construction Supplies</option>
                                <option value="Mini Grocery / Sari-Sari Store">Mini Grocery / Sari-Sari Store</option>
                                <option value="Pharmacy / Drugstore">Pharmacy / Drugstore</option>
                                <option value="School / Office Supplies">School / Office Supplies</option>
                            </select>
                        </div>

                        <div class="role-fields owner-fields" hidden>
                            <label for="businessAddress">Business Address</label>
                            <input type="text" name="business_address" id="businessAddress" minlength="5" maxlength="500" placeholder="Enter the complete business address">
                        </div>

                        <div class="full-width role-fields employee-fields" hidden>
                            <div class="role-section-heading">SME Employment Information</div>
                        </div>

                        <div class="role-fields employee-fields" hidden>
                            <label for="businessCode">SME Business Code</label>
                            <input type="text" name="business_code" id="businessCode" pattern="[A-Za-z0-9-]{3,20}" placeholder="Code provided by the SME owner" maxlength="20">
                        </div>

                        <div class="role-fields employee-fields" hidden>
                            <label for="branchCode">Branch Code</label>
                            <input type="text" name="branch_code" id="branchCode" pattern="[A-Za-z0-9-]{3,20}" placeholder="Code for the branch where you work" maxlength="20">
                            <small class="field-help">Ask the owner for both the business code and your specific branch code.</small>
                        </div>

                        <div class="role-fields employee-fields" hidden>
                            <label for="employeeNumber">Employee Number</label>
                            <input type="text" name="employee_no" id="employeeNumber" pattern="[A-Za-z0-9._-]{1,50}" placeholder="Enter your employee number" maxlength="50">
                        </div>

                        <div>
                            <label for="signupPassword">Password</label>
                            <div class="password-field-wrap">
                                <input type="password" name="signup_password" id="signupPassword" required minlength="12" maxlength="64" placeholder="Enter password" autocomplete="new-password" aria-describedby="signupPasswordHelp signupPasswordError">
                                <button type="button" class="password-toggle-btn" data-target="signupPassword" aria-label="Show or hide password">
                                    <span class="eye-icon eye-open"><svg viewBox="0 0 24 24"><path d="M2 12s3.6-6 10-6 10 6 10 6-3.6 6-10 6-10-6-10-6Z"></path><circle cx="12" cy="12" r="3.2"></circle></svg></span>
                                    <span class="eye-icon eye-closed" style="display:none;"><svg viewBox="0 0 24 24"><path d="M3 3l18 18"></path><path d="M10.6 6.3A11.2 11.2 0 0 1 12 6c6.4 0 10 6 10 6a17.6 17.6 0 0 1-3.1 3.8"></path><path d="M6.7 6.8C4.1 8.5 2 12 2 12a17.3 17.3 0 0 0 10 6c1.4 0 2.7-.2 3.8-.7"></path><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"></path></svg></span>
                                </button>
                            </div>
                            <small class="field-help" id="signupPasswordHelp">Use 12–64 characters with uppercase, lowercase, a number, and a special character.</small>
                            <small class="field-validation-error" id="signupPasswordError" aria-live="polite"></small>
                        </div>

                        <div>
                            <label for="signupConfirmPassword">Confirm Password</label>
                            <div class="password-field-wrap">
                                <input type="password" name="confirm_password" id="signupConfirmPassword" required minlength="12" maxlength="64" placeholder="Repeat password" autocomplete="new-password" aria-describedby="signupConfirmPasswordError">
                                <button type="button" class="password-toggle-btn" data-target="signupConfirmPassword" aria-label="Show or hide password">
                                    <span class="eye-icon eye-open"><svg viewBox="0 0 24 24"><path d="M2 12s3.6-6 10-6 10 6 10 6-3.6 6-10 6-10-6-10-6Z"></path><circle cx="12" cy="12" r="3.2"></circle></svg></span>
                                    <span class="eye-icon eye-closed" style="display:none;"><svg viewBox="0 0 24 24"><path d="M3 3l18 18"></path><path d="M10.6 6.3A11.2 11.2 0 0 1 12 6c6.4 0 10 6 10 6a17.6 17.6 0 0 1-3.1 3.8"></path><path d="M6.7 6.8C4.1 8.5 2 12 2 12a17.3 17.3 0 0 0 10 6c1.4 0 2.7-.2 3.8-.7"></path><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"></path></svg></span>
                                </button>
                            </div>
                            <small class="field-validation-error" id="signupConfirmPasswordError" aria-live="polite"></small>
                        </div>

                        <div class="full-width">
                            <label for="validId">Government ID Photo</label>
                            <input type="file" name="valid_id" id="validId" accept="image/jpeg,image/png,image/heic,image/heif,.jpg,.jpeg,.png,.heic,.heif" required>
                            <small class="field-help">Accepted: JPG, PNG, HEIC, or HEIF. JPG/PNG up to 5 MB; HEIC/HEIF up to 10 MB before conversion.</small>
                        </div>

                        <div class="full-width role-fields owner-fields" hidden>
                            <label for="businessPermit">Business Permit</label>
                            <input type="file" name="business_permit" id="businessPermit" accept="image/jpeg,image/png,image/heic,image/heif,application/pdf,.jpg,.jpeg,.png,.heic,.heif,.pdf">
                            <small class="field-help">Required for SME Owners. Accepted: PDF up to 10 MB; JPG/PNG up to 5 MB; HEIC/HEIF up to 10 MB before conversion.</small>
                        </div>
                    </div>
                    <p class="field-help signup-document-notice">Privacy notice: NexGen uses these documents only to review your registration. They are malware-scanned, sanitized, encrypted in private storage, and available only to authorized administrators. If your request is rejected, the stored documents and their encryption keys are deleted immediately. We record the notice version and your consent time.</p>
                    <div class="privacy-consent-wrap" id="signupPrivacyWrap">
                        <input type="checkbox" name="privacy_consent" id="privacy_consent" value="1" required disabled>
                        <label for="privacy_consent">I agree to the <a href="<?php echo e(nxAppUrl('privacy_policy.php') . '?return_to=signup&ack_token=' . rawurlencode($signupPrivacyAckToken)); ?>" target="_blank" rel="noopener" id="privacyPolicyLink">Privacy Policy</a></label>
                    </div>
                    <p class="privacy-consent-hint" id="privacyConsentHint"><span class="hint-icon">ℹ️</span><span id="privacyConsentHintText">Read the Privacy Policy and click “I understand” to enable consent.</span></p>
                    <div class="captcha-checkbox-wrap" style="margin:10px 0;"><div class="captcha-checkbox-card"><div class="captcha-checkbox-left" style="gap:8px;"><input type="checkbox" id="signupRobotCheck"><label for="signupRobotCheck" style="font-size:13px; margin:0;">I'm not a robot</label></div><div class="captcha-checkbox-right"><img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAEQAAABECAYAAAA4E5OyAAAHCklEQVR42u3bW0wUVxgH8P+Zs4wLTllZhHKTFJB4qRoN9UJEJbaULbYa0tRbYh9qb4/2oSZtGtPWxjbpiw9N7IumVdNL1MRblcZGocULGpUoERNKDeiyuMRdwWFZZ3f29MHudpbLsrszZy8J5wVIltkzv/m+73znLBDGGCYaRy4q7PsWHyxmAl5j0Mvw8OIFSIKCPKsZJc9nomyWhNISCQvmzkTxrJnIkQi/CYwaJBIIAOw/95TtbfEhP4vfnO41nYHXFwAADCvP5jPkZ4CsAhLF64st2FBXgoZXylFUIJGkggQj5cuzCjeUe01n4FPHn4caYBhW2DMghw+1qyz4+MOFaKgrJ0kD4R0pkUDGA3I4fXhx7gx89m4FNjfOM3RCQrQv3F43jexYkwGnhyGZgwoEJQUiXK5hbPnkBhasO82u3XTENam+fpnFDZJKKCGY3Ay4XMNYVn8Ou76+xGLFqNl4FrpAtCheP1JiUIGgpNKM3b/0oHz1ETbeUx8Po3jl8VAh1wUCAI9khlQbJRKF1xdAcf0pREqhvn6ZvdT4G7JnmGDOEPSDvLdPZiduqzCbUs4EVCAozBKwrP4cWi72snEjo/YEAGC6SPQV1SDGnQGSkhhhKBXTUPv2n2EowcgozDGBCkT/KvPRDyMpjxGGkp+B2sZmdHQOMLfMQpERCQMAorq9/eeesj/+Vrl2q7wiZf0Hzch6LnPSyIga5PJdP/fWnSeK1xeA1zUcFUZUIO8f9qYlhhYllhER5NOfRli2yB9D2w/EegNGDyFSqpy6k5jldePLhZhTZIbDE8CDLi8ePPJhyBtICsiEt7v7uAf5WZR/dPiBvV+tJtrlsf22E/sO38XpNjeyp1Nkm4XkRsjlu352XxYMu+HJ2ny3/P+Wu6hAIg115eTUjw3E3rwBG6pz8aBfgRpgyQM5cMGYQur0MKyZLWDDQhrXhrCoQCIHv6sjzQdXw+EJJCSNxqRMtyPArtiB/Cz9GDvWZGB73bT/ZL3s0HV/XNBrVpYS++9W9ur2Ztzvl7mm0JgrH7uiQO/K4vQw7HpN1GAAOxvNZFuVKe6jg6ICiVz5tRbZZoFr+owBOXTdr2tlcXoYtlWZ8NbKsap6USRJIm3HGuDwBLihCKPTRW8BrbQK2Nk48TG9EZHy8+eL4fAE+IPc+MevK12GFIZvt5gnfd3ORjN5Y358hdYtM7b3QCcKswT+IJe6VF3R8cpsiorC6FrNPVszybYqE4aU6FFkWWaL6o+gs0/h1tGGVYseF4u7fgwpDO/UijH9zs5GM3k8MsKijYxVm5ox5A1wXWVM2jd8KMcPUmkVsPAFGvNj27M1k0QTGVUNZ/FoWOXetYau7nrCYgrf0emyoozPRN0yYys2NScEIyxCPE/1LWNl+cZPNpGRMQZE9sYPMqQwlOQKhkdGVcNZ3Bv0I9tEuLTtw4oauajOkgKgJPJKozI6bubNLTF2Z/zFN3+hZtEM1GeZME007tpPlcj3F/ps1263sxtXm+N+oycj0dXT9evXQ5KklD2CM42+KVEU47pQNL9ms9lSGiPiiZmRQ1XVtMBIGMi6devSAoM7SDpFBncQVVXTKjK4gqQrBjeQRGA0NTWxtABRVRWKonB9iteuXWMulwtHjx5lKQ9CKcX58+e5YdjtdtbV1QVRFKGqquGRwq2G8Hh6LpeLtbS0hJpHSikGBwcNReECQikNociyMX9/1d3dzZqamsZ00kajcFt2KX22ITt58iQ6Ojp0Tba1tZW1tbVNuK0wEiVsc6cNRyOHoiiwWq2orKxERUUFiSUqbt68CVVVQ8CTparFYoHNZiMpDaKdsKqqKCgoQH5+PnJycmCxWMLg3G43HA4Hent7QSmNCsJIlISCaCet/RqsOdpUixVi9PXz8vKwdu1akjI1ZLL6QimFKIoQRXHM93owgtcfGBiIq6YkBSRR6IODg2htbWWGgwTDOR1Rent70d3dzQwDUVUV8xYu496O86pVpaWlMa1spsmWS80ZKOtov5yQomsUhsViQU1NDTEkZUYf7iyaX0YWLK5Oi0jRs/SaJooMm80Gq9UadsFF88tSPlL0LLnjRshEGFqUBYur8fjx45TDUBQF5eXlcWOMCxIJQ4vy5ubtkCQpJVIo2AEvX74cS5cu1XUwFdapAkBxcXFMF+zo6GC3bt0ypKHSs0+qqakx5JQu6v/KjDRkWWZXr15Ff39/wmAURYEoiqiuro75IXIH0R7gtLe3c4MJpoYoiliyZElM/UVSQLQR09nZiZ6eHiiKomvDFkSglCIvLw9z5swxNCISAjI6avr6+uB0OuFyuUJFeCIc7TbBarUiNzcXRUVFXBESCjIRktvtDv08MjKCzMxMAIDZbIbFYknaZzpJAUnlIUwRTIFMgUyBTIEYN/4F2c+TyekuGRIAAAAASUVORK5CYII=" alt="reCAPTCHA" width="32" height="32" style="width:32px;height:32px;object-fit:contain;display:block;" aria-hidden="true"><span>Manual<br>Captcha</span></div></div><div class="captcha-verified-text" id="signupVerifiedText" style="color:#28a745; font-size:12px; margin-top:5px; display:none;">Captcha verified.</div></div>
                    <button type="submit" class="silver-btn" id="signupSubmitBtn" disabled>Submit Request</button>
                </form>
            </div>

            <div class="signup-info-panel">
                <h2>Welcome Back!</h2>
                <p>To keep connected with us please login with your personal info</p>
                <button class="btn-outline" id="goToLogin">Sign In</button>
            </div>
        </div>
    </div>
    <div class="modal" id="forgotModal" style="display:none;">
        <div class="modal-box forgot-modern-box">
            <button class="close-modal" id="closeForgot" type="button" style="color: var(--text-main); top: 15px; right: 20px; z-index: 10;">&times;</button>

            <div class="forgot-step" id="forgotStepEmail" data-step="email">
                <h2>Forgot Password</h2>
                <p class="forgot-subtext">Enter your email address to look up your account.</p>
                <div class="forgot-inline-alert" id="forgotEmailAlert"></div>
                <form id="forgotEmailForm">
                    <div class="glass-field">
                        <span class="glass-field-icon"><svg viewBox="0 0 24 24" style="fill:none; stroke:currentColor; stroke-width:1.8; stroke-linecap:round; stroke-linejoin:round;"><rect x="3" y="5" width="18" height="14" rx="2"></rect><path d="m4 7 8 6 8-6"></path></svg></span>
                        <input type="email" id="forgotEmailInput" required placeholder="Enter your email address" autocomplete="email">
                    </div>
                    <button type="submit" class="glass-login-btn" id="forgotEmailSubmitBtn">Continue</button>
                </form>
                <div class="forgot-step-links">
                    <button type="button" id="forgotBackToLoginFromEmail">Back to Login</button>
                </div>
            </div>

            <div class="forgot-step" id="forgotStepSelect" data-step="select" hidden>
                <h2>Select Account</h2>
                <p class="forgot-subtext">Email ownership is verified. Choose which account to reset.</p>
                <div class="forgot-inline-alert" id="forgotSelectAlert"></div>
                <form id="forgotSelectForm">
                    <div class="forgot-account-list" id="forgotAccountList"></div>
                    <button type="submit" class="glass-login-btn" id="forgotSelectSubmitBtn">Choose Account</button>
                </form>
                <div class="forgot-step-links">
                    <button type="button" id="forgotStartOverFromSelect">Start Over</button>
                </div>
            </div>

            <div class="forgot-step" id="forgotStepOtp" data-step="otp" hidden>
                <h2>Reset Password</h2>
                <p class="forgot-masked-email">If an account matches, a code will be sent to the email address entered.</p>
                <div class="forgot-inline-alert" id="forgotOtpAlert"></div>
                <form id="forgotResetForm">
                    <div class="glass-field">
                        <span class="glass-field-icon"><svg viewBox="0 0 24 24" style="fill:none; stroke:currentColor; stroke-width:1.8; stroke-linecap:round; stroke-linejoin:round;"><rect x="4" y="4" width="16" height="16" rx="3"></rect><path d="M9 4v16M15 4v16M4 9h16M4 15h16"></path></svg></span>
                        <input type="text" id="forgotOtpInput" class="forgot-otp-input" maxlength="6" inputmode="numeric" pattern="[0-9]*" required placeholder="000000" autocomplete="one-time-code">
                    </div>
                    <div class="glass-field password-field-wrap">
                        <span class="glass-field-icon"><svg viewBox="0 0 24 24"><rect x="5" y="10.5" width="14" height="9.5" rx="2.2"></rect><path d="M8 10.5V7.8a4 4 0 0 1 8 0v2.7"></path></svg></span>
                        <input type="password" id="forgotNewPassword" required placeholder="New password" autocomplete="new-password">
                        <button type="button" class="password-toggle-btn" data-target="forgotNewPassword" style="background:none; border:none; color:#777; cursor:pointer; padding-right:15px;" aria-label="Show or hide password">
                            <span class="eye-icon eye-open"><svg viewBox="0 0 24 24" style="width:20px; height:20px; fill:#777;"><path d="M2 12s3.6-6 10-6 10 6 10 6-3.6 6-10 6-10-6-10-6Z"></path><circle cx="12" cy="12" r="3.2"></circle></svg></span>
                            <span class="eye-icon eye-closed" style="display:none;"><svg viewBox="0 0 24 24" style="width:20px; height:20px; fill:#777;"><path d="M3 3l18 18"></path><path d="M10.6 6.3A11.2 11.2 0 0 1 12 6c6.4 0 10 6 10 6a17.6 17.6 0 0 1-3.1 3.8"></path><path d="M6.7 6.8C4.1 8.5 2 12 2 12a17.3 17.3 0 0 0 10 6c1.4 0 2.7-.2 3.8-.7"></path><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"></path></svg></span>
                        </button>
                    </div>
                    <div class="glass-field password-field-wrap">
                        <span class="glass-field-icon"><svg viewBox="0 0 24 24"><rect x="5" y="10.5" width="14" height="9.5" rx="2.2"></rect><path d="M8 10.5V7.8a4 4 0 0 1 8 0v2.7"></path></svg></span>
                        <input type="password" id="forgotConfirmPassword" required placeholder="Confirm new password" autocomplete="new-password">
                        <button type="button" class="password-toggle-btn" data-target="forgotConfirmPassword" style="background:none; border:none; color:#777; cursor:pointer; padding-right:15px;" aria-label="Show or hide password">
                            <span class="eye-icon eye-open"><svg viewBox="0 0 24 24" style="width:20px; height:20px; fill:#777;"><path d="M2 12s3.6-6 10-6 10 6 10 6-3.6 6-10 6-10-6-10-6Z"></path><circle cx="12" cy="12" r="3.2"></circle></svg></span>
                            <span class="eye-icon eye-closed" style="display:none;"><svg viewBox="0 0 24 24" style="width:20px; height:20px; fill:#777;"><path d="M3 3l18 18"></path><path d="M10.6 6.3A11.2 11.2 0 0 1 12 6c6.4 0 10 6 10 6a17.6 17.6 0 0 1-3.1 3.8"></path><path d="M6.7 6.8C4.1 8.5 2 12 2 12a17.3 17.3 0 0 0 10 6c1.4 0 2.7-.2 3.8-.7"></path><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"></path></svg></span>
                        </button>
                    </div>
                    <button type="submit" class="glass-login-btn" id="forgotResetSubmitBtn">Reset Password</button>
                </form>
                <div class="forgot-resend-row">
                    <span>Didn't get the code?</span>
                    <button type="button" class="forgot-resend-btn" id="forgotResendBtn">Resend OTP</button>
                </div>
                <div class="forgot-step-links">
                    <button type="button" id="forgotStartOverFromOtp">Start Over</button>
                </div>
            </div>
        </div>
    </div>
    <div class="cookie-banner" id="cookieBanner">
    <div class="cookie-banner-text">
        <strong>Cookie Consent</strong><br>
        This system uses essential cookies or browser storage for login session handling, inactivity timeout,
        security settings, and access control. By continuing to use NextGen, you acknowledge this use.
        Read our
        <a href="/NexGen/CODE/PHP/privacy_policy.php?return_to=login">Privacy Policy</a>.
    </div>

    <div class="cookie-actions">
        <button class="cookie-btn cookie-accept" id="acceptCookiesBtn" type="button">Accept</button>
        <button class="cookie-btn cookie-decline" id="declineCookiesBtn" type="button">Decline</button>
    </div>
</div>

<div class="captcha-popup-overlay" id="loginCaptchaPopup">
    <div class="captcha-popup-box">
        <div class="captcha-popup-header">
            <h3>Verify Login</h3>
            <button type="button" class="captcha-popup-close" id="closeLoginCaptchaPopup">&times;</button>
        </div>

        <?php if ($loginCaptcha['success']): ?>
            <div class="captcha-popup-title">
                Select all images with <strong><?php echo htmlspecialchars($loginCaptcha['target_label']); ?></strong>
            </div>

            <div class="captcha-popup-grid" id="loginCaptchaGrid">
                <?php foreach ($loginCaptcha['items'] as $item): ?>
                    <label class="captcha-popup-item">
                        <input type="checkbox" name="captcha_selection[]" value="<?php echo htmlspecialchars($item['id']); ?>" form="loginForm">
                        <img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==" data-captcha-src="<?php echo htmlspecialchars($item['relative_path']); ?>" alt="Captcha image">
                        <span></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <p class="captcha-help">Choose only the matching images, then click Verify.</p>

            <div class="captcha-popup-actions">
                <button type="button" class="captcha-btn-cancel" id="cancelLoginCaptcha">Cancel</button>
                <button type="button" class="captcha-btn-verify" id="verifyLoginCaptcha">Verify</button>
            </div>
        <?php else: ?>
            <div class="captcha-error-box"><?php echo htmlspecialchars($loginCaptcha['message']); ?></div>
        <?php endif; ?>
    </div>
</div>

<div class="captcha-popup-overlay" id="signupCaptchaPopup">
    <div class="captcha-popup-box">
        <div class="captcha-popup-header">
            <h3>Verify Signup</h3>
            <button type="button" class="captcha-popup-close" id="closeSignupCaptchaPopup">&times;</button>
        </div>

        <?php if ($signupCaptcha['success']): ?>
            <div class="captcha-popup-title">
                Select all images with <strong><?php echo htmlspecialchars($signupCaptcha['target_label']); ?></strong>
            </div>

            <div class="captcha-popup-grid" id="signupCaptchaGrid">
                <?php foreach ($signupCaptcha['items'] as $item): ?>
                    <label class="captcha-popup-item">
                        <input type="checkbox" name="captcha_selection[]" value="<?php echo htmlspecialchars($item['id']); ?>" form="signupForm">
                        <img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==" data-captcha-src="<?php echo htmlspecialchars($item['relative_path']); ?>" alt="Captcha image">
                        <span></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <p class="captcha-help">Choose only the matching images, then click Verify.</p>

            <div class="captcha-popup-actions">
                <button type="button" class="captcha-btn-cancel" id="cancelSignupCaptcha">Cancel</button>
                <button type="button" class="captcha-btn-verify" id="verifySignupCaptcha">Verify</button>
            </div>
        <?php else: ?>
            <div class="captcha-error-box"><?php echo htmlspecialchars($signupCaptcha['message']); ?></div>
        <?php endif; ?>
    </div>
</div>

<div class="lockout-popup-overlay" id="lockoutPopup">
    <div class="lockout-popup-box">
        <div class="lockout-popup-icon">!</div>
        <h3>Account Locked</h3>
        <p id="lockoutMessage">
            Too many failed login attempts. Please wait until the countdown reaches zero before trying again.
        </p>

        <div class="lockout-timer-wrap">
            <div class="lockout-timer-label">Time Remaining</div>
            <div class="lockout-timer" id="lockoutTimer">15:00</div>
        </div>

        <div class="admin-unlock-note" id="adminUnlockNote" style="display:none;">
            Admin account detected. You can use OTP to unlock this locked admin account securely.
        </div>

        <div style="display:flex; gap:10px; justify-content:center; flex-wrap:wrap; margin-top:18px;">
            <button type="button" class="lockout-popup-btn lockout-popup-btn-secondary" id="lockoutBackBtn">Back</button>
            <button type="button" class="lockout-popup-btn lockout-popup-btn-secondary" id="adminOtpUnlockBtn" style="display:none;">Use OTP to Unlock</button>
            <button type="button" class="lockout-popup-btn" id="lockoutOkayBtn" disabled>Wait for Unlock</button>
        </div>
    </div>
</div>

<div class="attempt-popup-overlay" id="attemptPopup">
    <div class="attempt-popup-box">
        <div class="attempt-popup-icon">!</div>
        <h3>Login Warning</h3>
        <p id="attemptPopupMessage">Your login attempt failed.</p>
        <div class="attempts-left-highlight" id="attemptsLeftHighlight">2</div>
        <p style="margin-top: 10px;">failed attempt(s) remaining before the account is automatically locked.</p>
        <button type="button" class="attempt-popup-btn" id="attemptPopupBtn">OK</button>
    </div>
</div>
<div class="captcha-notice-overlay" id="captchaNoticePopup">
    <div class="captcha-notice-box">
        <div class="captcha-notice-icon">!</div>
        <h3>Captcha Required</h3>
        <p id="captchaNoticeMessage">Please select captcha images first.</p>
        <button type="button" class="captcha-notice-btn" id="captchaNoticeOkBtn">OK</button>
    </div>
</div>


<!-- Cursor Glow -->
<div class="cursor-glow" id="cursorGlow"></div>

<!-- Floating Particles -->
<div class="particles" id="particles"></div>

<!-- Preloader -->
<div class="preloader" id="preloader">
  <div class="preloader-logo">NexGen</div>
  <div class="preloader-bar"><div class="preloader-bar-inner" id="preloaderBar"></div></div>
</div>

<!-- ========== HEADER ========== -->
<header id="header">
  <nav class="nav">
    <div class="logo">
      <img src="../../IMAGES/NGlogo.png" alt="NexGen logo" class="logo-img">
      NexGen
    </div>
    <ul class="nav-links" id="navLinks">
      <li><a href="#home">Home</a></li>
      <li><a href="#about">About Us</a></li>
      <li><a href="#services">Services</a></li>
      <li class="has-dropdown">
        <a href="javascript:void(0)">Pages ▾</a>
        <div class="dropdown-menu">
          <a href="https://www.facebook.com/share/1CuKN9qY3g/" title="Facebook"><i class="fab fa-facebook-f"></i></a>
          <a href="https://x.com/NexGenX2026" title="Twitter"><i class="fab fa-x-twitter"></i></a>
          <a href="https://www.instagram.com/nexgen.enterprises26?igsh=MTB5bDNod3lubjhjdw==" title="Instagram"><i class="fab fa-instagram"></i></a>
          <a href="https://www.tiktok.com/@nexgen.enterprise4?_r=1&_t=ZS-98Vx2jSAL3q
" title="TikTok"><i class="fab fa-tiktok"></i></a>
        </div>
      </li>
      <li><a href="#footer">Contact Us</a></li>
    </ul>
    <div class="nav-cta">
      <button type="button" id="landingThemeToggle" class="landing-theme-toggle" aria-label="Switch to light mode" aria-pressed="false">
        <i class="bi bi-moon-stars-fill" id="landingThemeIcon"></i>
      </button>
      <button type="button" id="openLoginBtn" class="btn btn-primary magnetic-btn">Log In</button>
      <button class="nav-toggle" id="navToggle" aria-label="Open menu" aria-controls="navLinks" aria-expanded="false">☰</button>
    </div>
  </nav>
  <div class="nav-overlay" id="navOverlay"></div>
</header>

<!-- ========== HERO ========== -->
<section class="hero" id="home">
  <div class="hero-bg">
    <div class="gradient-orb orb-1"></div>
    <div class="gradient-orb orb-2"></div>
    <div class="gradient-orb orb-3"></div>
    <div class="grid-bg"></div>
  </div>

  <div class="wrap hero-grid">
    <div class="hero-content">
      <div class="hero-eyebrow">
        <span class="line"></span>
        Welcome To NexGen
      </div>
      <h1>
        <span class="word"><span>Where</span></span>
        <span class="word"><span>The</span></span><br>
        <span class="word"><span>Expertise</span></span>
        <span class="word"><span>Creates</span></span><br>
        <span class="word"><span class="highlight">Excellence</span></span>
      </h1>
      <p class="hero-lead">
        NexGen brings inventory, sales, analytics, and receivables together
        in one connected system — so your business runs on clear numbers,
        not guesswork.
      </p>
      <div class="hero-actions">
        <button type="button" id="openLoginArea" class="btn btn-primary magnetic-btn">Sign Up Now!</button>
        <button type="button" class="play-link" id="openVideoPlayer" aria-haspopup="dialog" aria-controls="videoPlayerDialog">
          <span class="play-circle">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
          </span>
          Watch Video
        </button>
      </div>
      <div class="team-join">
        <span class="team-join-label">Join Our Team Now!</span>
        <div class="avatar-row">
          <span class="avatar-placeholder">
            <img src="../../IMAGES/matt.jpg" alt="Team member photo">
          </span>
          <span class="avatar-placeholder">
            <img src="../../IMAGES/viona.jpg" alt="Team member photo">
          </span>
          <span class="avatar-placeholder">
            <img src="../../IMAGES/isla.jpg" alt="Team member photo">
          </span>
          <span class="avatar-placeholder">
            <img src="../../IMAGES/sed.jpg" alt="Team member photo">
          </span>
          <span class="avatar-placeholder">
            <img src="../../IMAGES/wina.jpg" alt="Team member photo">
          </span>
          <span class="avatar-add" title="Upload a photo">+</span>
        </div>
      </div>
    </div>

    <div class="hero-visual">
      <div class="chart-container">
        <svg class="chart-svg" viewBox="0 0 700 450" preserveAspectRatio="none">
          <defs>
            <!-- Cyan gradient for volume bars -->
            <linearGradient id="volCyan" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stop-color="#007FFF" stop-opacity="0.9"/>
              <stop offset="100%" stop-color="#007FFF" stop-opacity="0.15"/>
            </linearGradient>
            <!-- Arrow glow gradient -->
            <linearGradient id="arrowGlow" x1="0" y1="0" x2="1" y2="0">
              <stop offset="0%" stop-color="#007FFF" stop-opacity="0.3"/>
              <stop offset="100%" stop-color="#007FFF" stop-opacity="1"/>
            </linearGradient>
            <!-- Trend line gradient -->
            <linearGradient id="trendCyan" x1="0" y1="0" x2="1" y2="0">
              <stop offset="0%" stop-color="#007FFF" stop-opacity="0.4"/>
              <stop offset="100%" stop-color="#007FFF"/>
            </linearGradient>
            <!-- Arrowhead gradient -->
            <linearGradient id="arrowHead" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stop-color="#ADFFFF"/>
              <stop offset="100%" stop-color="#2438BD"/>
            </linearGradient>
            <!-- Glow filter -->
            <filter id="cyanGlow" x="-50%" y="-50%" width="200%" height="200%">
              <feGaussianBlur stdDeviation="6" result="blur"/>
              <feComposite in="SourceGraphic" in2="blur" operator="over"/>
            </filter>
            <filter id="cyanGlowStrong" x="-50%" y="-50%" width="200%" height="200%">
              <feGaussianBlur stdDeviation="10" result="blur"/>
              <feMerge>
                <feMergeNode in="blur"/>
                <feMergeNode in="SourceGraphic"/>
              </feMerge>
            </filter>
            <!-- Subtle grid pattern -->
            <pattern id="tinyGrid" width="20" height="20" patternUnits="userSpaceOnUse">
              <path d="M 20 0 L 0 0 0 20" fill="none" stroke="rgba(247, 202, 132,0.04)" stroke-width="0.5"/>
            </pattern>
          </defs>

          <!-- Background grid -->
          <rect width="700" height="450" fill="url(#tinyGrid)" opacity="0.6"/>

          <!-- Horizontal grid lines -->
          <line class="grid-line" x1="0" y1="80" x2="700" y2="80" style="stroke:rgba(247, 202, 132,0.06);stroke-width:1;stroke-dasharray:4 8"/>
          <line class="grid-line" x1="0" y1="160" x2="700" y2="160" style="stroke:rgba(247, 202, 132,0.06);stroke-width:1;stroke-dasharray:4 8"/>
          <line class="grid-line" x1="0" y1="240" x2="700" y2="240" style="stroke:rgba(247, 202, 132,0.06);stroke-width:1;stroke-dasharray:4 8"/>
          <line class="grid-line" x1="0" y1="320" x2="700" y2="320" style="stroke:rgba(247, 202, 132,0.06);stroke-width:1;stroke-dasharray:4 8"/>

          <!-- ===== VOLUME BARS (behind everything, short bars at bottom) ===== -->
          <g class="volume-bars">
            <rect class="vol-bar" x="10" y="340" width="18" height="60" rx="2" fill="url(#volCyan)" opacity="0.25"/>
            <rect class="vol-bar" x="40" y="350" width="18" height="50" rx="2" fill="url(#volCyan)" opacity="0.25"/>
            <rect class="vol-bar" x="70" y="335" width="18" height="65" rx="2" fill="url(#volCyan)" opacity="0.3"/>
            <rect class="vol-bar" x="100" y="320" width="18" height="80" rx="2" fill="url(#volCyan)" opacity="0.3"/>
            <rect class="vol-bar" x="130" y="310" width="18" height="90" rx="2" fill="url(#volCyan)" opacity="0.3"/>
            <rect class="vol-bar" x="160" y="325" width="18" height="75" rx="2" fill="url(#volCyan)" opacity="0.35"/>
            <rect class="vol-bar" x="190" y="300" width="18" height="100" rx="2" fill="url(#volCyan)" opacity="0.35"/>
            <rect class="vol-bar" x="220" y="295" width="18" height="105" rx="2" fill="url(#volCyan)" opacity="0.35"/>
            <rect class="vol-bar" x="250" y="305" width="18" height="95" rx="2" fill="url(#volCyan)" opacity="0.4"/>
            <rect class="vol-bar" x="280" y="285" width="18" height="115" rx="2" fill="url(#volCyan)" opacity="0.4"/>
            <rect class="vol-bar" x="310" y="275" width="18" height="125" rx="2" fill="url(#volCyan)" opacity="0.4"/>
            <rect class="vol-bar" x="340" y="290" width="18" height="110" rx="2" fill="url(#volCyan)" opacity="0.45"/>
            <rect class="vol-bar" x="370" y="260" width="18" height="140" rx="2" fill="url(#volCyan)" opacity="0.45"/>
            <rect class="vol-bar" x="400" y="250" width="18" height="150" rx="2" fill="url(#volCyan)" opacity="0.45"/>
            <rect class="vol-bar" x="430" y="265" width="18" height="135" rx="2" fill="url(#volCyan)" opacity="0.5"/>
            <rect class="vol-bar" x="460" y="235" width="18" height="165" rx="2" fill="url(#volCyan)" opacity="0.5"/>
            <rect class="vol-bar" x="490" y="225" width="18" height="175" rx="2" fill="url(#volCyan)" opacity="0.5"/>
            <rect class="vol-bar" x="520" y="240" width="18" height="160" rx="2" fill="url(#volCyan)" opacity="0.55"/>
            <rect class="vol-bar" x="550" y="210" width="18" height="190" rx="2" fill="url(#volCyan)" opacity="0.55"/>
            <rect class="vol-bar" x="580" y="195" width="18" height="205" rx="2" fill="url(#volCyan)" opacity="0.6"/>
            <rect class="vol-bar" x="610" y="205" width="18" height="195" rx="2" fill="url(#volCyan)" opacity="0.6"/>
            <rect class="vol-bar" x="640" y="180" width="18" height="220" rx="2" fill="url(#volCyan)" opacity="0.65"/>
            <rect class="vol-bar" x="670" y="165" width="18" height="235" rx="2" fill="url(#volCyan)" opacity="0.65"/>
          </g>

          <!-- ===== CANDLESTICK / MAIN BARS (rising left to right) ===== -->
          <g class="main-bars">
            <!-- Bar 1 -->
            <rect class="bar" x="25" y="290" width="32" height="110" rx="3" fill="url(#volCyan)" filter="url(#cyanGlow)" opacity="0.6"/>
            <!-- Bar 2 -->
            <rect class="bar" x="75" y="260" width="32" height="140" rx="3" fill="url(#volCyan)" filter="url(#cyanGlow)" opacity="0.65"/>
            <!-- Bar 3 -->
            <rect class="bar" x="125" y="230" width="32" height="170" rx="3" fill="url(#volCyan)" filter="url(#cyanGlow)" opacity="0.7"/>
            <!-- Bar 4 (dip) -->
            <rect class="bar" x="175" y="250" width="32" height="150" rx="3" fill="url(#volCyan)" filter="url(#cyanGlow)" opacity="0.65"/>
            <!-- Bar 5 -->
            <rect class="bar" x="225" y="210" width="32" height="190" rx="3" fill="url(#volCyan)" filter="url(#cyanGlow)" opacity="0.72"/>
            <!-- Bar 6 -->
            <rect class="bar" x="275" y="195" width="32" height="205" rx="3" fill="url(#volCyan)" filter="url(#cyanGlow)" opacity="0.75"/>
            <!-- Bar 7 (small dip) -->
            <rect class="bar" x="325" y="215" width="32" height="185" rx="3" fill="url(#volCyan)" filter="url(#cyanGlow)" opacity="0.72"/>
            <!-- Bar 8 -->
            <rect class="bar" x="375" y="175" width="32" height="225" rx="3" fill="url(#volCyan)" filter="url(#cyanGlow)" opacity="0.78"/>
            <!-- Bar 9 -->
            <rect class="bar" x="425" y="150" width="32" height="250" rx="3" fill="url(#volCyan)" filter="url(#cyanGlow)" opacity="0.82"/>
            <!-- Bar 10 -->
            <rect class="bar" x="475" y="130" width="32" height="270" rx="3" fill="url(#volCyan)" filter="url(#cyanGlow)" opacity="0.85"/>
            <!-- Bar 11 (small dip) -->
            <rect class="bar" x="525" y="145" width="32" height="255" rx="3" fill="url(#volCyan)" filter="url(#cyanGlow)" opacity="0.82"/>
            <!-- Bar 12 -->
            <rect class="bar" x="575" y="110" width="32" height="290" rx="3" fill="url(#volCyan)" filter="url(#cyanGlow)" opacity="0.88"/>
            <!-- Bar 13 -->
            <rect class="bar" x="625" y="90" width="32" height="310" rx="3" fill="url(#volCyan)" filter="url(#cyanGlowStrong)" opacity="0.9"/>
            <!-- Bar 14 (tallest) -->
            <rect class="bar" x="670" y="70" width="32" height="330" rx="3" fill="url(#volCyan)" filter="url(#cyanGlowStrong)" opacity="0.95"/>
          </g>

          <!-- ===== GLOWING ARROW TREND LINE ===== -->
          <!-- Outer glow line -->
          <path class="arrow-line-glow" d="M40,295 L90,265 L140,235 L190,255 L240,215 L290,200 L340,220 L390,180 L440,155 L490,135 L540,150 L590,115 L640,95 L690,72" 
                fill="none" stroke="rgba(247, 202, 132,0.25)" stroke-width="12" stroke-linecap="round" stroke-linejoin="round" filter="url(#cyanGlow)" opacity="0.5"/>
          
          <!-- Main trend line -->
          <path class="arrow-line" d="M40,295 L90,265 L140,235 L190,255 L240,215 L290,200 L340,220 L390,180 L440,155 L490,135 L540,150 L590,115 L640,95 L690,72" 
                fill="none" stroke="url(#trendCyan)" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" filter="url(#cyanGlowStrong)"/>

          <!-- Arrowhead at the end -->
          <polygon class="arrow-head" points="690,72 710,55 705,78" fill="url(#arrowHead)" filter="url(#cyanGlowStrong)"/>

          <!-- Data point dots along the line -->
          <circle class="data-dot" cx="90" cy="265" r="3" fill="#007FFF" filter="url(#cyanGlow)" opacity="0.7"/>
          <circle class="data-dot" cx="190" cy="255" r="3" fill="#007FFF" filter="url(#cyanGlow)" opacity="0.7"/>
          <circle class="data-dot" cx="290" cy="200" r="3" fill="#007FFF" filter="url(#cyanGlow)" opacity="0.7"/>
          <circle class="data-dot" cx="390" cy="180" r="3" fill="#007FFF" filter="url(#cyanGlow)" opacity="0.7"/>
          <circle class="data-dot" cx="490" cy="135" r="3" fill="#007FFF" filter="url(#cyanGlow)" opacity="0.7"/>
          <circle class="data-dot" cx="590" cy="115" r="3" fill="#007FFF" filter="url(#cyanGlow)" opacity="0.7"/>
          <circle class="data-dot" cx="690" cy="72" r="4" fill="#ADFFFF" filter="url(#cyanGlowStrong)"/>

          <!-- ===== PERSPECTIVE FLOOR GRID (like the reference) ===== -->
          <g class="floor-grid" opacity="0.12">
            <path d="M0,380 Q175,365 350,380 Q525,395 700,380" fill="none" stroke="#F7CA84" stroke-width="1"/>
            <path d="M0,395 Q175,382 350,395 Q525,408 700,395" fill="none" stroke="#F7CA84" stroke-width="1"/>
            <path d="M0,410 Q175,400 350,410 Q525,420 700,410" fill="none" stroke="#F7CA84" stroke-width="1"/>
            <path d="M0,425 Q175,418 350,425 Q525,432 700,425" fill="none" stroke="#F7CA84" stroke-width="1"/>
            <path d="M0,440 Q175,435 350,440 Q525,445 700,440" fill="none" stroke="#F7CA84" stroke-width="0.5"/>
          </g>

          <!-- Base glow line at bottom of chart -->
          <line class="base-glow" x1="0" y1="400" x2="700" y2="400" stroke="#F7CA84" stroke-width="2" opacity="0.3" filter="url(#cyanGlow)"/>
        </svg>

        <div class="analytics-card">
          <span class="phone-screen-tag">
            <span class="live-dot"></span>
            Live Overview
          </span>
          <p class="phone-headline">Guiding your financial journey to elevating your business destiny.</p>
        </div>
      </div>
      <div class="stat-badge">
        <div class="num">24<span>/7</span></div>
        <div class="label">Availability</div>
      </div>
    </div>
  </div>

  <div class="scroll-indicator">
    <span>Scroll</span>
    <div class="scroll-mouse"></div>
  </div>
</section>

<dialog class="video-player-dialog" id="videoPlayerDialog" aria-label="NexGen introduction video">
  <div class="video-dialog-frame">
    <button type="button" class="video-dialog-close" id="closeVideoPlayer" aria-label="Close video">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">
        <path d="m6 6 12 12M18 6 6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
      </svg>
    </button>
    <video class="video-dialog-player" id="landingPageVideo" controls playsinline preload="metadata">
      <source src="../../VIDEOS/nexgen-intro.mp4" type="video/mp4">
      Your browser does not support HTML video.
    </video>
  </div>
  <p class="video-dialog-message" id="videoPlayerMessage" role="status" aria-live="polite"></p>
</dialog>

<!-- ========== MARQUEE ========== -->
<section class="marquee-section">
  <div class="marquee-track">
    <div class="marquee-item"><span class="dot"></span> Inventory Management</div>
    <div class="marquee-item"><span class="dot"></span> Sales Pipeline</div>
    <div class="marquee-item"><span class="dot"></span> Real-time Analytics</div>
    <div class="marquee-item"><span class="dot"></span> Accounts Receivable</div>
    <div class="marquee-item"><span class="dot"></span> Smart Dashboards</div>
    <div class="marquee-item"><span class="dot"></span> Automated Reports</div>
    <div class="marquee-item"><span class="dot"></span> Cloud Connected</div>
    <div class="marquee-item"><span class="dot"></span> Enterprise Ready</div>
    <!-- Duplicate for seamless loop -->
    <div class="marquee-item"><span class="dot"></span> Inventory Management</div>
    <div class="marquee-item"><span class="dot"></span> Sales Pipeline</div>
    <div class="marquee-item"><span class="dot"></span> Real-time Analytics</div>
    <div class="marquee-item"><span class="dot"></span> Accounts Receivable</div>
    <div class="marquee-item"><span class="dot"></span> Smart Dashboards</div>
    <div class="marquee-item"><span class="dot"></span> Automated Reports</div>
    <div class="marquee-item"><span class="dot"></span> Cloud Connected</div>
    <div class="marquee-item"><span class="dot"></span> Enterprise Ready</div>
  </div>
</section>

<!-- ========== STATS ========== -->
<section class="stats-section">
  <div class="wrap">
    <div class="stats-header reveal from-left">
      <span class="eyebrow">Built for Modern Business</span>
      <h2>Powering Your Operations from Day One</h2>
      <p>NexGen was founded in 2026 with a clear mission — to give businesses an all-in-one platform for inventory, sales, analytics, and receivables.</p>
    </div>
    <div class="stats-grid">
      <div class="stat-card reveal from-scale">
        <div class="stat-icon"><i class="fas fa-code-branch"></i></div>
        <div class="stat-number"><span class="counter" data-target="4">0</span></div>
        <div class="stat-label">Core Business Modules</div>
        <div class="stat-desc">Inventory, sales, analytics, and receivables in one platform.</div>
      </div>
      <div class="stat-card reveal from-scale">
        <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
        <div class="stat-number"><span class="counter" data-target="1">0</span></div>
        <div class="stat-label">Centralized Workspace</div>
        <div class="stat-desc">View important business activity and performance from one dashboard.</div>
      </div>
      <div class="stat-card reveal from-scale">
        <div class="stat-icon"><i class="fas fa-comments"></i></div>
        <div class="stat-number"><span class="counter" data-target="1">0</span></div>
        <div class="stat-label">Business Assistant</div>
        <div class="stat-desc">Get quick answers about inventory, sales, analytics, and receivables.</div>
      </div>
      <div class="stat-card reveal from-scale">
        <div class="stat-icon"><i class="fas fa-shield-halved"></i></div>
        <div class="stat-number"><span class="counter" data-target="1">0</span></div>
        <div class="stat-label">Protected User Access</div>
        <div class="stat-desc">Authentication, CAPTCHA, OTP, and permissions help protect account access.</div>
      </div>
    </div>
  </div>
</section>

<!-- ========== HOW IT WORKS ========== -->
<section class="how" id="services">
  <div class="wrap">
    <div class="how-section-inner">
      <div class="how-header-full">
        <span class="eyebrow">Our Modules</span>
        <h2>Everything Your Business Runs On</h2>
        <p>Click each card to explore. Four connected modules give you one clear view of your operations.</p>
      </div>
        <div class="flip-grid">
          <!-- Card 1: Inventory -->
          <div class="flip-card" data-flip>
            <div class="flip-inner">
              <div class="flip-front">
                <div class="flip-icon">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 8l-9-5-9 5 9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/></svg>
                </div>
                <h4>Inventory Management</h4>
                <span class="flip-hint">Click to explore</span>
              </div>
              <div class="flip-back">
                <h4>Inventory Management</h4>
                <p>Track stock levels, movements, and reorder points in real time across every location. Get automated alerts when inventory runs low and optimize your supply chain.</p>
                <span class="flip-hint-back">Click to go back</span>
              </div>
            </div>
          </div>

          <!-- Card 2: Sales -->
          <div class="flip-card" data-flip>
            <div class="flip-inner">
              <div class="flip-front">
                <div class="flip-icon">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                </div>
                <h4>Sales Pipeline</h4>
                <span class="flip-hint">Click to explore</span>
              </div>
              <div class="flip-back">
                <h4>Sales Pipeline</h4>
                <p>Manage orders, quotations, and invoicing from a single streamlined sales pipeline. Close deals faster with automated follow-ups and real-time deal tracking.</p>
                <span class="flip-hint-back">Click to go back</span>
              </div>
            </div>
          </div>

          <!-- Card 3: Analytics -->
          <div class="flip-card" data-flip>
            <div class="flip-inner">
              <div class="flip-front">
                <div class="flip-icon">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 3v18h18"/><rect x="7" y="12" width="3" height="6"/><rect x="12" y="8" width="3" height="10"/><rect x="17" y="5" width="3" height="13"/></svg>
                </div>
                <h4>Real-time Analytics</h4>
                <span class="flip-hint">Click to explore</span>
              </div>
              <div class="flip-back">
                <h4>Real-time Analytics</h4>
                <p>Turn transaction data into dashboards and reports that guide smarter decisions. Visualize trends, forecast demand, and identify opportunities instantly.</p>
                <span class="flip-hint-back">Click to go back</span>
              </div>
            </div>
          </div>

          <!-- Card 4: Accounts Receivable -->
          <div class="flip-card" data-flip>
            <div class="flip-inner">
              <div class="flip-front">
                <div class="flip-icon">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18"/><path d="M8 14h8"/></svg>
                </div>
                <h4>Accounts Receivable</h4>
                <span class="flip-hint">Click to explore</span>
              </div>
              <div class="flip-back">
                <h4>Accounts Receivable</h4>
                <p>Monitor outstanding balances, automate reminders, and speed up collections. Reduce days sales outstanding and improve your cash flow visibility.</p>
                <span class="flip-hint-back">Click to go back</span>
              </div>
            </div>
          </div>
        </div>
    </div>
  </div>
</section>

<!-- ========== ABOUT US ========== -->
<section class="about" id="about">
  <div class="wrap">
    <div class="about-grid">
      <!-- Left Panel -->
      <div class="about-left reveal">
        <span class="eyebrow">About Us</span>
        <h2>We Build What Businesses <span class="gradient-text">Actually Need</span></h2>
        <p class="about-lead">Founded in 2026, NexGen was born from a simple observation — most businesses juggle disconnected tools for inventory, sales, analytics, and receivables. We built a unified platform so you stop guessing and start growing.</p>

        <div class="about-values">
          <div class="value-item reveal">
            <div class="value-icon"><i class="fas fa-eye"></i></div>
            <div>
              <h4>Our Vision</h4>
              <p>To become the standard operating system for modern businesses — one platform that connects every operational decision to real data.</p>
            </div>
          </div>
          <div class="value-item reveal">
            <div class="value-icon"><i class="fas fa-flag"></i></div>
            <div>
              <h4>Our Mission</h4>
              <p>Empower businesses of all sizes with intelligent, integrated tools that eliminate guesswork and accelerate growth from day one.</p>
            </div>
          </div>
        </div>

        <a href="#quote" class="btn btn-primary about-cta"><i class="fas fa-rocket"></i> Join Our Journey</a>
      </div>

      <!-- Right Panel - Visual -->
      <div class="about-right reveal">
        <div class="about-visual">
          <!-- SVG for dynamic connecting lines -->
          <svg class="about-lines-svg" viewBox="0 0 100 100" preserveAspectRatio="none">
            <line class="about-line-1" x1="0" y1="0" x2="50" y2="50" stroke="rgba(247, 202, 132,0.3)" stroke-width="1.5" stroke-dasharray="4 4"/>
            <circle class="about-line-dot-1" cx="50" cy="50" r="5" fill="rgba(247, 202, 132,0.6)"/>
            <line class="about-line-2" x1="0" y1="0" x2="50" y2="50" stroke="rgba(0, 127, 255, 0.3)" stroke-width="1.5" stroke-dasharray="4 4"/>
            <circle class="about-line-dot-2" cx="50" cy="50" r="5" fill="rgba(0, 127, 255, 0.6)"/>
            <line class="about-line-3" x1="0" y1="0" x2="50" y2="50" stroke="rgba(173, 255, 255, 0.3)" stroke-width="1.5" stroke-dasharray="4 4"/>
            <circle class="about-line-dot-3" cx="50" cy="50" r="5" fill="rgba(173, 255, 255, 0.6)"/>
          </svg>
          <!-- Animated floating cards -->
          <div class="about-card about-card-1">
            <div class="about-card-icon" data-card="1">
              <i class="fas fa-chart-line"></i>
            </div>
            <span class="about-card-label">Real-time Data</span>
            <div class="about-card-bar"><div class="about-card-bar-fill" style="width:78%"></div></div>
          </div>
          <div class="about-card about-card-2">
            <div class="about-card-icon" data-card="2">
              <i class="fas fa-users"></i>
            </div>
            <span class="about-card-label">Team Aligned</span>
            <div class="about-card-bar"><div class="about-card-bar-fill" style="width:92%"></div></div>
          </div>
          <div class="about-card about-card-3">
            <div class="about-card-icon" data-card="3">
              <i class="fas fa-clock"></i>
            </div>
            <span class="about-card-label">Save Hours</span>
            <div class="about-card-bar"><div class="about-card-bar-fill" style="width:65%"></div></div>
          </div>
          <!-- Center orb -->
          <div class="about-orb" id="aboutOrb">
            <div class="about-orb-inner">
              <i class="fas fa-atom"></i>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ========== TRUST & SECURITY ========== -->
<section class="trust-security" id="security">
  <div class="wrap">
    <div class="trust-header reveal">
      <span class="eyebrow">Built To Protect</span>
      <h2>Security You Can Trust</h2>
      <p>NexGen is built with account protection baked in from day one — not bolted on as an afterthought.</p>
    </div>

    <div class="trust-grid">
      <div class="trust-card reveal">
        <div class="trust-icon"><i class="fas fa-shield-halved"></i></div>
        <div class="trust-title">CSRF Protection</div>
        <p class="trust-desc">Every login and signup form is validated with a unique, per-session token, blocking cross-site request forgery attempts before they ever reach your account.</p>
        <span class="trust-tag"><i class="fas fa-check"></i> Active on every form</span>
      </div>

      <div class="trust-card reveal">
        <div class="trust-icon" style="background:linear-gradient(135deg, var(--pink), var(--accent));"><i class="fas fa-robot"></i></div>
        <div class="trust-title">CAPTCHA Verification</div>
        <p class="trust-desc">A live image CAPTCHA runs on both login and signup, keeping bots and automated scripts from spamming or brute-forcing your accounts.</p>
        <span class="trust-tag"><i class="fas fa-check"></i> Bot-resistant by default</span>
      </div>

      <div class="trust-card reveal">
        <div class="trust-icon" style="background:linear-gradient(135deg, var(--purple), var(--cyan));"><i class="fas fa-lock"></i></div>
        <div class="trust-title">Smart Lockout System</div>
        <p class="trust-desc">Repeated failed logins trigger a temporary, timed lockout with a real-time countdown, stopping brute-force attempts in their tracks.</p>
        <span class="trust-tag"><i class="fas fa-check"></i> Real-time protection</span>
      </div>
    </div>
  </div>
</section>

<!-- ========== CTA ========== -->
<section class="cta-section" id="quote">
  <div class="wrap">
    <div class="cta-box reveal from-scale">
      <span class="eyebrow">Ready To Start?</span>
      <h2>Transform Your Business<br>With NexGen Today</h2>
      <p>Join hundreds of companies already using NexGen to streamline operations and accelerate growth.</p>
      <div class="cta-buttons">
        <button type="button" id="openLoginFromBot" class="btn btn-primary btn-glow magnetic-btn">Let's Get Started</button>
        <a href="#demo" class="btn btn-ghost magnetic-btn">Come In!</a>
      </div>
    </div>
  </div>
</section>

<!-- ========== FOOTER ========== -->
<footer id="footer">
  <div class="footer-wave-wrap" aria-hidden="true">
    <svg class="wave-back" viewBox="0 0 2400 100" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">
      <path d="M0,40 C150,70 350,10 600,40 C850,70 1050,10 1200,40 C1350,70 1550,10 1800,40 C2050,70 2250,10 2400,40 L2400,100 L0,100 Z"></path>
    </svg>
    <svg class="wave-mid" viewBox="0 0 2400 100" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">
      <path d="M0,30 C150,50 350,10 600,30 C850,50 1050,10 1200,30 C1350,50 1550,10 1800,30 C2050,50 2250,10 2400,30 L2400,100 L0,100 Z"></path>
    </svg>
    <svg class="wave-front" viewBox="0 0 2400 100" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">
      <path d="M0,25 C150,38 350,12 600,25 C850,38 1050,12 1200,25 C1350,38 1550,12 1800,25 C2050,38 2250,12 2400,25 L2400,100 L0,100 Z"></path>
    </svg>
    <svg class="wave-crest" viewBox="0 0 2400 100" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">
      <path d="M0,40 C150,70 350,10 600,40 C850,70 1050,10 1200,40 C1350,70 1550,10 1800,40 C2050,70 2250,10 2400,40"></path>
    </svg>
  </div>
  <div class="footer-glow" aria-hidden="true"></div>
  <div class="wrap">
    <div class="footer-grid">
      <div class="footer-brand">
        <div class="logo">
          <img src="../../IMAGES/NGlogo.png" alt="NexGen logo" class="logo-img">
          NexGen
        </div>
        <p>Bringing inventory, sales, analytics, and receivables together in one connected system for smarter business operations.</p>
        <div class="footer-social">
          <a href="https://www.facebook.com/share/1CuKN9qY3g/" target="_blank" rel="noopener"><i class="fab fa-facebook-f"></i></a>
          <a href="https://www.instagram.com/nexgen.enterprises26?igsh=MTB5bDNod3lubjhjdw==" target="_blank" rel="noopener"><i class="fab fa-instagram"></i></a>
          <a href="https://x.com/NexGenX2026" target="_blank" rel="noopener"><i class="fab fa-x-twitter"></i></a>
          <a href="https://www.tiktok.com/@nexgen.enterprise4?_r=1&_t=ZS-98Vx2jSAL3q" target="_blank" rel="noopener"><i class="fab fa-tiktok"></i></a>
        </div>
      </div>
      <div class="footer-col footer-menu">
        <h4>Menu</h4>
        <ul>
          <li><a href="#home">Home</a></li>
          <li><a href="#about">About Us</a></li>
          <li><a href="#">Services</a></li>
          <li><a href="#">Pages</a></li>
        </ul>
      </div>
      <div class="footer-col footer-modules">
        <h4>Modules</h4>
        <ul>
          <li><a href="#">Inventory</a></li>
          <li><a href="#">Sales</a></li>
          <li><a href="#">Analytics</a></li>
          <li><a href="#">Receivables</a></li>
        </ul>
      </div>
      <div class="footer-col footer-contact-col">
        <h4>Contact Us</h4>
        <ul class="footer-contact">
          <li>
            <span class="contact-icon"><i class="fas fa-envelope"></i></span>
            <span><a href="#">nexgeneration2026@gmail.com</a></span>
          </li>
          <li>
            <span class="contact-icon"><i class="fas fa-phone"></i></span>
            <span><a href="#">0909 439 9525</a></span>
          </li>
          <li>
            <span class="contact-icon"><i class="fas fa-location-dot"></i></span>
            <span>Cainta, Rizal</span>
          </li>
        </ul>
      </div>
    </div>
    <div class="footer-bottom">
      <span>&copy; 2026 NexGen. All rights reserved.</span>
      
    </div>
  </div>
</footer>

<button type="button" class="back-to-top" id="backToTop" aria-label="Back to top">
  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5"/><path d="M5 12l7-7 7 7"/></svg>
</button>

<!-- ========== GSAP ========== -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/ScrollTrigger.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/ScrollToPlugin.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/animejs/3.2.2/anime.min.js"></script>

<script>
// ========== PRELOADER ==========
document.addEventListener('DOMContentLoaded', () => {
  const preloader = document.getElementById('preloader');
  const bar = document.getElementById('preloaderBar');
  const logo = document.querySelector('.preloader-logo');

  let hidden = false;
  function hidePreloader() {
    if (hidden || !preloader) return;
    hidden = true;
    /* Stop blocking clicks immediately, regardless of whether the
       fade animation runs or GSAP is even available. */
    preloader.style.pointerEvents = 'none';
    preloader.style.opacity = '0';
    setTimeout(() => {
      preloader.style.display = 'none';
      if (typeof startHeroAnimations === 'function') {
        try { startHeroAnimations(); } catch (err) { console.error(err); }
      }
    }, 500);
  }

  /* Hard safety net: no matter what happens with GSAP, the CDN,
     or the progress interval, the preloader is force-removed after
     4s so it can never permanently trap clicks on the page. */
  const safetyTimeout = setTimeout(hidePreloader, 4000);

  try {
    gsap.to(logo, { opacity: 1, duration: 0.6, ease: 'power2.out' });
    gsap.to('.preloader-bar', { opacity: 1, duration: 0.3, delay: 0.2 });

    let progress = 0;
    const interval = setInterval(() => {
      progress += Math.random() * 30 + 10;
      if (progress > 100) progress = 100;
      if (bar) bar.style.width = progress + '%';
      if (progress >= 100) {
        clearInterval(interval);
        setTimeout(() => {
          clearTimeout(safetyTimeout);
          gsap.to(preloader, {
            opacity: 0,
            duration: 0.5,
            onStart: () => { if (preloader) preloader.style.pointerEvents = 'none'; },
            onComplete: hidePreloader
          });
        }, 400);
      }
    }, 200);
  } catch (err) {
    /* GSAP failed to load (blocked CDN, network issue, etc.) —
       fall back to the plain-JS hide instead of leaving the
       preloader stuck on screen. */
    console.error('Preloader animation failed, hiding immediately:', err);
    hidePreloader();
  }
});

// ========== CURSOR GLOW ==========
const glow = document.getElementById('cursorGlow');
document.addEventListener('mousemove', (e) => {
  gsap.to(glow, {
    left: e.clientX,
    top: e.clientY,
    duration: 0.6,
    ease: 'power2.out'
  });
});

// ========== PARTICLES ==========
const particlesContainer = document.getElementById('particles');
for (let i = 0; i < 20; i++) {
  const p = document.createElement('div');
  p.classList.add('particle');
  const size = Math.random() * 3 + 1;
  const colors = ['var(--cyan)', 'var(--pink)', 'var(--accent)', 'var(--purple)'];
  p.style.cssText = `
    width: ${size}px;
    height: ${size}px;
    background: ${colors[Math.floor(Math.random() * colors.length)]};
    left: ${Math.random() * 100}%;
    animation-duration: ${Math.random() * 20 + 15}s;
    animation-delay: ${Math.random() * 15}s;
  `;
  particlesContainer.appendChild(p);
}

// ========== NAVBAR SCROLL ==========
gsap.registerPlugin(ScrollTrigger);

const header = document.getElementById('header');
ScrollTrigger.create({
  start: 'top -80',
  onUpdate: (self) => {
    if (self.direction === 1 && self.scroll() > 80) {
      header.classList.add('scrolled');
    } else if (self.scroll() <= 80) {
      header.classList.remove('scrolled');
    }
  }
});

// ========== MAGNETIC BUTTONS ==========
document.querySelectorAll('.magnetic-btn').forEach(btn => {
  btn.addEventListener('mousemove', (e) => {
    const rect = btn.getBoundingClientRect();
    const x = e.clientX - rect.left - rect.width / 2;
    const y = e.clientY - rect.top - rect.height / 2;
    gsap.to(btn, { x: x * 0.3, y: y * 0.3, duration: 0.3, ease: 'power2.out' });
  });
  btn.addEventListener('mouseleave', () => {
    gsap.to(btn, { x: 0, y: 0, duration: 0.5, ease: 'elastic.out(1, 0.3)' });
  });
  // Ensure click works even with magnetic effect
  btn.style.cursor = 'pointer';
  btn.style.pointerEvents = 'auto';
});

// ========== HERO ANIMATIONS ==========
function startHeroAnimations() {
  const tl = gsap.timeline({ defaults: { ease: 'expo.out' } });

  tl
    .to('.hero-eyebrow', { opacity: 1, y: 0, duration: 1.4, ease: 'power4.out' })
    .to('.hero h1 .word span', {
      y: '0%',
      duration: 1.8,
      stagger: 0.18,
      ease: 'expo.out'
    }, '-=1.0')
    .to('.hero-lead', { opacity: 1, y: 0, duration: 1.4, ease: 'power3.out' }, '-=1.2')
    .to('.hero-actions', { opacity: 1, y: 0, scale: 1, duration: 1.4, ease: 'back.out(1.2)' }, '-=1.1')
    .to('.team-join', { opacity: 1, y: 0, duration: 1.4 }, '-=1.1')
    .to('.hero-visual', { opacity: 1, scale: 1, duration: 2, ease: 'expo.out' }, '-=1.4')
    .add(() => animateChart(), '-=0.4')
    .add(() => animateSparks(), '-=0.3')
    .add(() => {
      gsap.to('.analytics-card', { opacity: 1, x: 0, duration: 0.8, ease: 'power3.out' });
      gsap.to('.stat-badge', { opacity: 1, y: 0, scale: 1, duration: 0.7, ease: 'back.out(1.4)' });
    }, '-=0.5')
    .to('.scroll-indicator', { opacity: 1, duration: 0.5 }, '-=0.2');

  // Parallax on hero visual
  gsap.to('.hero-visual', {
    y: 30,
    scrollTrigger: {
      trigger: '.hero',
      start: 'top top',
      end: 'bottom top',
      scrub: 1
    }
  });

  // Fade scroll indicator
  gsap.to('.scroll-indicator', {
    opacity: 0,
    scrollTrigger: {
      trigger: '.hero',
      start: 'bottom -100',
      end: 'bottom top',
      scrub: 0.5
    }
  });
}

// ========== CONTINUOUS CHART ANIMATION ==========
function animateChart() {
  gsap.registerPlugin(ScrollTrigger);

  // Define the data pattern for main bars (height, y position)
  // Pattern that trends upward but with realistic dips
  const barPattern = [
    { h: 110, y: 290, op: 0.6 },
    { h: 140, y: 260, op: 0.65 },
    { h: 170, y: 230, op: 0.7 },
    { h: 150, y: 250, op: 0.65 },
    { h: 190, y: 210, op: 0.72 },
    { h: 205, y: 195, op: 0.75 },
    { h: 185, y: 215, op: 0.72 },
    { h: 225, y: 175, op: 0.78 },
    { h: 250, y: 150, op: 0.82 },
    { h: 270, y: 130, op: 0.85 },
    { h: 255, y: 145, op: 0.82 },
    { h: 290, y: 110, op: 0.88 },
    { h: 310, y: 90,  op: 0.9 },
    { h: 330, y: 70,  op: 0.95 }
  ];

  const volPattern = [
    { h: 60, y: 340 }, { h: 50, y: 350 }, { h: 65, y: 335 },
    { h: 80, y: 320 }, { h: 90, y: 310 }, { h: 75, y: 325 },
    { h: 100, y: 300 }, { h: 105, y: 295 }, { h: 95, y: 305 },
    { h: 115, y: 285 }, { h: 125, y: 275 }, { h: 110, y: 290 },
    { h: 140, y: 260 }, { h: 150, y: 250 }, { h: 135, y: 265 },
    { h: 165, y: 235 }, { h: 175, y: 225 }, { h: 160, y: 240 },
    { h: 190, y: 210 }, { h: 205, y: 195 }, { h: 195, y: 205 },
    { h: 220, y: 180 }, { h: 235, y: 165 }
  ];

  const bars = document.querySelectorAll('.bar');
  const volBars = document.querySelectorAll('.vol-bar');
  const totalBars = bars.length;
  const totalVolBars = volBars.length;

  // ===== INITIAL ENTRANCE ANIMATION =====
  // Volume bars enter first
  volBars.forEach((bar, i) => {
    const height = parseFloat(bar.getAttribute('height'));
    const y = parseFloat(bar.getAttribute('y'));
    bar.setAttribute('height', '0');
    bar.setAttribute('y', y + height);
    gsap.to(bar, {
      attr: { height: height, y: y },
      duration: 0.6,
      delay: i * 0.03,
      ease: 'power3.out'
    });
  });

  // Main bars enter with stagger
  bars.forEach((bar, i) => {
    const height = parseFloat(bar.getAttribute('height'));
    const y = parseFloat(bar.getAttribute('y'));
    bar.setAttribute('height', '0');
    bar.setAttribute('y', y + height);
    gsap.to(bar, {
      attr: { height: height, y: y },
      duration: 0.8,
      delay: 0.3 + i * 0.08,
      ease: 'elastic.out(1, 0.5)'
    });
  });

  // Arrow line entrance
  gsap.to('.arrow-line-glow', { opacity: 0.5, duration: 1.5, delay: 1.2 });
  gsap.to('.arrow-line', {
    strokeDashoffset: 0,
    duration: 2.5,
    delay: 0.8,
    ease: 'power2.inOut'
  });
  gsap.to('.arrow-head', { opacity: 1, duration: 0.6, delay: 3.0, ease: 'back.out(2)' });
  document.querySelectorAll('.data-dot').forEach((dot, i) => {
    gsap.to(dot, { opacity: 0.7, duration: 0.4, delay: 1.2 + i * 0.25 });
  });

  // ===== CONTINUOUS LIVE MOVEMENT =====
  // After initial animation completes, start continuous updates
  // Bars continuously pulse/grow between their base height and taller — never disappear
  
  // 1. Each main bar continuously grows taller and shrinks back (like a live chart)
  bars.forEach((bar, i) => {
    const baseHeight = parseFloat(bar.getAttribute('height'));
    const baseY = parseFloat(bar.getAttribute('y'));
    const growthAmount = baseHeight * 0.25; // grow up to 25% taller
    const tallerHeight = baseHeight + growthAmount;
    const tallerY = baseY - growthAmount;
    
    // After entrance, start continuous pulse
    gsap.to(bar, {
      attr: { height: tallerHeight, y: tallerY },
      duration: 1.5 + i * 0.1,
      repeat: -1,
      yoyo: true,
      ease: 'sine.inOut',
      delay: 3.5 + i * 0.15
    });
  });

  // 2. Volume bars also continuously pulse
  volBars.forEach((bar, i) => {
    const baseHeight = parseFloat(bar.getAttribute('height'));
    const baseY = parseFloat(bar.getAttribute('y'));
    const growthAmount = baseHeight * 0.3; // grow up to 30% taller
    const tallerHeight = baseHeight + growthAmount;
    const tallerY = baseY - growthAmount;
    
    gsap.to(bar, {
      attr: { height: tallerHeight, y: tallerY },
      duration: 1.2 + i * 0.05,
      repeat: -1,
      yoyo: true,
      ease: 'sine.inOut',
      delay: 3 + i * 0.1
    });
  });

  // 3. Trend line continuous draw-and-erase effect (like scanning)
  function scanTrendLine() {
    const line = document.querySelector('.arrow-line');
    const dashArray = parseFloat(getComputedStyle(line).strokeDasharray) || 1500;
    
    // Quick scan: dashoffset goes from 0 to full then back
    gsap.fromTo(line, {
      strokeDashoffset: 0
    }, {
      strokeDashoffset: dashArray,
      duration: 4,
      ease: 'power2.inOut',
      onComplete: () => {
        gsap.fromTo(line, {
          strokeDashoffset: dashArray
        }, {
          strokeDashoffset: 0,
          duration: 3,
          ease: 'power2.inOut',
          onComplete: scanTrendLine
        });
      }
    });
  }
  gsap.delayedCall(4.5, scanTrendLine);

  // 4. Arrow glow pulses continuously
  gsap.to('.arrow-line-glow', {
    opacity: 0.7,
    duration: 2,
    repeat: -1,
    yoyo: true,
    ease: 'sine.inOut',
    delay: 4
  });

  // 5. Arrow line stroke width pulses
  gsap.to('.arrow-line', {
    strokeWidth: 6,
    duration: 1.5,
    repeat: -1,
    yoyo: true,
    ease: 'sine.inOut',
    delay: 4
  });

  // 6. Data dots pulse
  document.querySelectorAll('.data-dot').forEach((dot, i) => {
    gsap.to(dot, {
      r: 5,
      duration: 1.2,
      repeat: -1,
      yoyo: true,
      ease: 'sine.inOut',
      delay: 4.5 + i * 0.2
    });
  });

  // 7. Arrowhead glow pulse
  gsap.to('.arrow-head', {
    opacity: 0.7,
    duration: 1.5,
    repeat: -1,
    yoyo: true,
    ease: 'sine.inOut',
    delay: 4
  });
}

function animateSparks() {
  // Reduce spark count for cleaner look matching reference
  const sparks = document.querySelectorAll('.spark');
  sparks.forEach((spark, i) => {
    gsap.fromTo(spark, {
      opacity: 0,
      y: 0,
      scale: 0.5
    }, {
      opacity: 0.8,
      y: -40 - Math.random() * 30,
      scale: 1,
      duration: 1.8 + Math.random() * 1.5,
      repeat: -1,
      delay: i * 0.5,
      ease: 'power1.out',
      onRepeat: () => {
        gsap.set(spark, { y: 0, opacity: 0 });
      }
    });
  });
}

// ========== SCROLL REVEALS ==========
gsap.utils.toArray('.reveal').forEach((el, i) => {
  gsap.fromTo(el, {
    opacity: 0,
    y: el.classList.contains('from-left') ? -40 : el.classList.contains('from-right') ? 40 : 40,
    scale: el.classList.contains('from-scale') ? 0.92 : 1
  }, {
    opacity: 1,
    y: 0,
    scale: 1,
    duration: 0.9,
    ease: 'power3.out',
    scrollTrigger: {
      trigger: el,
      start: 'top 85%',
      toggleActions: 'play none none reverse'
    }
  });
});

// ========== COUNTER ANIMATION ==========
const counterObserver = new IntersectionObserver((entries) => {
  entries.forEach(entry => {
    if (entry.isIntersecting) {
      const counter = entry.target;
      const target = parseInt(counter.dataset.target);
      const duration = 2000;
      const start = performance.now();

      const animate = (now) => {
        const elapsed = now - start;
        const progress = Math.min(elapsed / duration, 1);
        const eased = 1 - Math.pow(1 - progress, 3); // easeOutCubic
        counter.textContent = Math.floor(target * eased);
        if (progress < 1) requestAnimationFrame(animate);
        else counter.textContent = target;
      };
      requestAnimationFrame(animate);
      counterObserver.unobserve(counter);
    }
  });
}, { threshold: 0.5 });

document.querySelectorAll('.counter').forEach(el => counterObserver.observe(el));

// ========== FEATURES STAGGER ==========
gsap.utils.toArray('.feature-card').forEach((card, i) => {
  gsap.fromTo(card, {
    opacity: 0,
    y: 40
  }, {
    opacity: 1,
    y: 0,
    duration: 0.7,
    delay: i * 0.12,
    ease: 'power3.out',
    scrollTrigger: {
      trigger: card,
      start: 'top 85%',
      toggleActions: 'play none none reverse'
    }
  });
});

// ========== TRUST & SECURITY STAGGER ==========
gsap.utils.toArray('.trust-card').forEach((card, i) => {
  gsap.fromTo(card, {
    opacity: 0,
    y: 30,
    scale: 0.95
  }, {
    opacity: 1,
    y: 0,
    scale: 1,
    duration: 0.7,
    delay: i * 0.15,
    ease: 'power3.out',
    scrollTrigger: {
      trigger: card,
      start: 'top 85%',
      toggleActions: 'play none none reverse'
    }
  });
});

// ========== STAT CARDS HOVER ==========
document.querySelectorAll('.stat-card').forEach(card => {
  card.addEventListener('mouseenter', () => {
    gsap.to(card.querySelector('.stat-icon'), {
      scale: 1.1,
      rotation: -5,
      duration: 0.3,
      ease: 'power2.out'
    });
  });
  card.addEventListener('mouseleave', () => {
    gsap.to(card.querySelector('.stat-icon'), {
      scale: 1,
      rotation: 0,
      duration: 0.3,
      ease: 'power2.out'
    });
  });
});

// ========== SMOOTH SCROLL ==========
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
  anchor.addEventListener('click', (e) => {
    e.preventDefault();
    const target = document.querySelector(anchor.getAttribute('href'));
    if (target) {
      gsap.to(window, {
        scrollTo: { y: target, offsetY: 80 },
        duration: 1,
        ease: 'power3.inOut'
      });
    }
  });
});

// ========== SECTION HEADERS PARALLAX ==========
gsap.utils.toArray('.features-header, .trust-header').forEach(header => {
  gsap.fromTo(header, {
    opacity: 0,
    y: 30
  }, {
    opacity: 1,
    y: 0,
    duration: 0.8,
    ease: 'power3.out',
    scrollTrigger: {
      trigger: header,
      start: 'top 80%',
      toggleActions: 'play none none reverse'
    }
  });
});

// ========== FLIP CARD ENTRANCE ANIMATION (Anime.js) ==========
const flipCards = document.querySelectorAll('.flip-card');
// Cards 0,1 slide from left; Cards 2,3 slide from right
flipCards.forEach((card, i) => {
  const isFromLeft = i < 2;
  card.style.opacity = '0';
  card.style.transform = isFromLeft ? 'translateX(-120px)' : 'translateX(120px)';
});

const howTrigger = document.querySelector('.how');
if (howTrigger) {
  gsap.registerPlugin(ScrollTrigger);
  ScrollTrigger.create({
    trigger: howTrigger,
    start: 'top 75%',
    once: true,
    onEnter: () => {
      flipCards.forEach((card, i) => {
        anime({
          targets: card,
          opacity: [0, 1],
          translateX: [i < 2 ? -120 : 120, 0],
          scale: [0.95, 1],
          delay: i * 200,
          duration: 1000,
          easing: 'easeOutExpo'
        });
      });
    }
  });
}

// ========== FLIP CARD CLICK TO FLIP (Anime.js powered) ==========
document.querySelectorAll('.flip-card').forEach(card => {
  const inner = card.querySelector('.flip-inner');
  let flipped = false;

  card.addEventListener('click', () => {
    flipped = !flipped;
    const targetRotate = flipped ? 180 : 0;
    const currentRotate = flipped ? 0 : 180;

    anime({
      targets: inner,
      rotateY: [currentRotate, targetRotate],
      scale: [1, 1.06, 1],
      duration: 700,
      easing: 'easeOutElastic(1, .6)'
    });
  });
});

// ========== DYNAMIC ABOUT LINES ==========
function updateAboutLines() {
  const svg = document.querySelector('.about-lines-svg');
  const aboutVisual = document.querySelector('.about-visual');
  const orb = document.getElementById('aboutOrb');
  
  if (!svg || !aboutVisual || !orb) return;

  // Get orb center position
  const orbRect = orb.getBoundingClientRect();
  const visualRect = aboutVisual.getBoundingClientRect();
  const orbCenterX = orbRect.left - visualRect.left + orbRect.width / 2;
  const orbCenterY = orbRect.top - visualRect.top + orbRect.height / 2;

  // Update each line
  for (let i = 1; i <= 3; i++) {
    const cardIcon = document.querySelector(`[data-card="${i}"]`);
    const line = document.querySelector(`.about-line-${i}`);
    const dot = document.querySelector(`.about-line-dot-${i}`);
    
    if (cardIcon && line && dot) {
      // Get card icon center position
      const iconRect = cardIcon.getBoundingClientRect();
      const iconCenterX = iconRect.left - visualRect.left + iconRect.width / 2;
      const iconCenterY = iconRect.top - visualRect.top + iconRect.height / 2;

      // Update line coordinates (SVG uses 0-100 viewBox)
      const x1 = (iconCenterX / visualRect.width) * 100;
      const y1 = (iconCenterY / visualRect.height) * 100;
      const x2 = (orbCenterX / visualRect.width) * 100;
      const y2 = (orbCenterY / visualRect.height) * 100;

      line.setAttribute('x1', x1);
      line.setAttribute('y1', y1);
      line.setAttribute('x2', x2);
      line.setAttribute('y2', y2);

      // Update dot position
      dot.setAttribute('cx', x2);
      dot.setAttribute('cy', y2);
    }
  }
}

// Update lines on load, then only while the About section is visible —
// running this forced-reflow loop forever in the background was a real
// performance drain that could make the whole page (including buttons)
// feel sluggish the longer a tab stayed open.
window.addEventListener('load', () => {
  updateAboutLines();

  let aboutLinesInterval = null;
  const aboutVisualEl = document.querySelector('.about-visual');

  function startAboutLinesLoop() {
    if (aboutLinesInterval) return;
    aboutLinesInterval = setInterval(updateAboutLines, 50);
  }
  function stopAboutLinesLoop() {
    if (!aboutLinesInterval) return;
    clearInterval(aboutLinesInterval);
    aboutLinesInterval = null;
  }

  if (aboutVisualEl && 'IntersectionObserver' in window) {
    const observer = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          updateAboutLines();
          startAboutLinesLoop();
        } else {
          stopAboutLinesLoop();
        }
      });
    }, { threshold: 0.1 });
    observer.observe(aboutVisualEl);
  } else {
    // Fallback if IntersectionObserver isn't available
    startAboutLinesLoop();
  }
});

// Also update on resize
window.addEventListener('resize', updateAboutLines);
</script>


<!-- ========== LOGIN / SIGNUP SYSTEM JS ========== -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const loginModal = document.getElementById("loginModal");
    const signupModal = document.getElementById("signupModal");
    const forgotModal = document.getElementById("forgotModal");

    const openLoginBtn = document.getElementById("openLoginBtn");
    const openLoginArea = document.getElementById("openLoginArea");
    const openLoginFromMenu = document.getElementById("openLoginFromMenu");
    const openLoginFromBot = document.getElementById("openLoginFromBot");

    const closeLogin = document.getElementById("closeLogin");
    const closeSignup = document.getElementById("closeSignup");
    const closeForgot = document.getElementById("closeForgot");

    const goToSignup = document.getElementById("goToSignup");
    const goToLogin = document.getElementById("goToLogin");
    const forgotPasswordLink = document.getElementById("forgotPasswordLink");

    /* Guards against race conditions from rapid/overlapping clicks:
       every modal action bumps this token, and any in-flight
       setTimeout from an earlier action checks it before touching
       the DOM. This stops a stale callback (e.g. from a fast
       double-click) from hiding a modal a newer click just opened —
       which is what was requiring a page refresh to recover from. */
    let modalActionToken = 0;

    window.openLoginModal = function() {
        const token = ++modalActionToken;
        /* Close signup first with animation */
        if (signupModal && signupModal.classList.contains('modal-open')) {
            signupModal.classList.remove('modal-open');
            signupModal.classList.add('modal-closing');
            setTimeout(() => {
                if (token !== modalActionToken) return;
                signupModal.classList.remove('modal-closing');
                signupModal.style.display = 'none';
                if (loginModal) triggerOpenModal(loginModal);
            }, 350);
        } else {
            if (loginModal) triggerOpenModal(loginModal);
        }
    };

    window.openSignupModal = function() {
        const token = ++modalActionToken;
        /* Close login first with animation */
        if (loginModal && loginModal.classList.contains('modal-open')) {
            loginModal.classList.remove('modal-open');
            loginModal.classList.add('modal-closing');
            setTimeout(() => {
                if (token !== modalActionToken) return;
                loginModal.classList.remove('modal-closing');
                loginModal.style.display = 'none';
                if (signupModal) triggerOpenModal(signupModal);
            }, 350);
        } else {
            if (signupModal) triggerOpenModal(signupModal);
        }
    };

    function triggerOpenModal(modal) {
        if (!modal) return;
        modal.style.display = 'flex';
        /* Force reflow so transition kicks in */
        void modal.offsetWidth;
        modal.classList.add('modal-open');
        modal.classList.remove('modal-closing');
    }

    window.closeModals = function() {
        const token = ++modalActionToken;
        const active = document.querySelector('.modal.modal-open');
        if (active) {
            active.classList.remove('modal-open');
            active.classList.add('modal-closing');
            setTimeout(() => {
                if (token !== modalActionToken) return;
                active.classList.remove('modal-closing');
                active.style.display = 'none';
            }, 400);
        } else {
            if (loginModal) { loginModal.style.display = 'none'; loginModal.classList.remove('modal-open', 'modal-closing'); }
            if (signupModal) { signupModal.style.display = 'none'; signupModal.classList.remove('modal-open', 'modal-closing'); }
            if (forgotModal) { forgotModal.style.display = 'none'; forgotModal.classList.remove('modal-open', 'modal-closing'); }
        }
    };


    if (openLoginBtn) {
        openLoginBtn.addEventListener("click", function(e) {
            e.preventDefault();
            openLoginModal();
        });
    }

    if (openLoginArea) {
        openLoginArea.addEventListener("click", function(e) {
            e.preventDefault();
            openSignupModal();
        });
    }

    if (openLoginFromMenu) {
        openLoginFromMenu.addEventListener("click", function(e) {
            e.preventDefault();
            openLoginModal();
        });
    }

    if (openLoginFromBot) {
        openLoginFromBot.addEventListener("click", function(e) {
            e.preventDefault();
            openLoginModal();
        });
    }

    if (closeLogin) closeLogin.addEventListener("click", closeModals);
    if (closeSignup) closeSignup.addEventListener("click", closeModals);

    if (goToSignup) {
        goToSignup.addEventListener("click", function(e) {
            e.preventDefault();
            const token = ++modalActionToken;
            /* Add slide transition effect when switching from login to signup */
            const loginBox = loginModal ? loginModal.querySelector('.modal-box') : null;
            if (loginBox) {
                loginBox.style.transition = 'transform 0.35s ease, opacity 0.35s ease';
                loginBox.style.transform = 'translateX(-60px)';
                loginBox.style.opacity = '0';
            }
            setTimeout(() => {
                if (token !== modalActionToken) return;
                openSignupModal();
                const signupBox = signupModal ? signupModal.querySelector('.modal-box') : null;
                if (signupBox) {
                    signupBox.style.transition = 'none';
                    signupBox.style.transform = 'translateX(60px)';
                    signupBox.style.opacity = '0';
                    void signupBox.offsetWidth;
                    signupBox.style.transition = 'transform 0.4s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.35s ease';
                    signupBox.style.transform = 'translateX(0)';
                    signupBox.style.opacity = '1';
                }
            }, 300);
        });
    }

    if (goToLogin) {
        goToLogin.addEventListener("click", function(e) {
            e.preventDefault();
            const token = ++modalActionToken;
            /* Add slide transition effect when switching from signup to login */
            const signupBox = signupModal ? signupModal.querySelector('.modal-box') : null;
            if (signupBox) {
                signupBox.style.transition = 'transform 0.35s ease, opacity 0.35s ease';
                signupBox.style.transform = 'translateX(60px)';
                signupBox.style.opacity = '0';
            }
            setTimeout(() => {
                if (token !== modalActionToken) return;
                openLoginModal();
                const loginBox = loginModal ? loginModal.querySelector('.modal-box') : null;
                if (loginBox) {
                    loginBox.style.transition = 'none';
                    loginBox.style.transform = 'translateX(-60px)';
                    loginBox.style.opacity = '0';
                    void loginBox.offsetWidth;
                    loginBox.style.transition = 'transform 0.4s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.35s ease';
                    loginBox.style.transform = 'translateX(0)';
                    loginBox.style.opacity = '1';
                }
            }, 300);
        });
    }

    /* ========== FORGOT PASSWORD WIZARD ========== */
    window.openForgotModal = function() {
        const token = ++modalActionToken;
        const activeModal = document.querySelector('.modal.modal-open');
        if (activeModal && activeModal !== forgotModal) {
            activeModal.classList.remove('modal-open');
            activeModal.classList.add('modal-closing');
            setTimeout(() => {
                if (token !== modalActionToken) return;
                activeModal.classList.remove('modal-closing');
                activeModal.style.display = 'none';
                if (forgotModal) { resetForgotWizard(); triggerOpenModal(forgotModal); }
            }, 350);
        } else if (forgotModal) {
            resetForgotWizard();
            triggerOpenModal(forgotModal);
        }
    };

    function closeForgotAndOpen(openFn) {
        const token = ++modalActionToken;
        if (forgotModal && forgotModal.classList.contains('modal-open')) {
            forgotModal.classList.remove('modal-open');
            forgotModal.classList.add('modal-closing');
            setTimeout(() => {
                if (token !== modalActionToken) return;
                forgotModal.classList.remove('modal-closing');
                forgotModal.style.display = 'none';
                openFn();
            }, 350);
        } else {
            openFn();
        }
    }

    if (forgotPasswordLink) {
        forgotPasswordLink.addEventListener('click', function(e) {
            e.preventDefault();
            openForgotModal();
        });
    }

    if (closeForgot) closeForgot.addEventListener('click', closeModals);

    const forgotStepEls = {
        email: document.getElementById('forgotStepEmail'),
        select: document.getElementById('forgotStepSelect'),
        otp: document.getElementById('forgotStepOtp')
    };
    const forgotEmailForm = document.getElementById('forgotEmailForm');
    const forgotEmailInput = document.getElementById('forgotEmailInput');
    const forgotEmailAlert = document.getElementById('forgotEmailAlert');
    const forgotEmailSubmitBtn = document.getElementById('forgotEmailSubmitBtn');
    const forgotSelectForm = document.getElementById('forgotSelectForm');
    const forgotAccountList = document.getElementById('forgotAccountList');
    const forgotSelectAlert = document.getElementById('forgotSelectAlert');
    const forgotSelectSubmitBtn = document.getElementById('forgotSelectSubmitBtn');
    const forgotResetForm = document.getElementById('forgotResetForm');
    const forgotOtpAlert = document.getElementById('forgotOtpAlert');
    const forgotOtpInput = document.getElementById('forgotOtpInput');
    const forgotNewPassword = document.getElementById('forgotNewPassword');
    const forgotConfirmPassword = document.getElementById('forgotConfirmPassword');
    const forgotResetSubmitBtn = document.getElementById('forgotResetSubmitBtn');
    const forgotResendBtn = document.getElementById('forgotResendBtn');
    const forgotBackToLoginFromEmail = document.getElementById('forgotBackToLoginFromEmail');
    const forgotStartOverFromSelect = document.getElementById('forgotStartOverFromSelect');
    const forgotStartOverFromOtp = document.getElementById('forgotStartOverFromOtp');

    const forgotEndpoints = Object.freeze({
        lookup: <?php echo json_encode(nxAppUrl('forgot_password_lookup.php'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        sendOtp: <?php echo json_encode(nxAppUrl('send_forgot_otp.php'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        reset: <?php echo json_encode(nxAppUrl('process_reset_password.php'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
    });
    const forgotPortal = 'client';

    let forgotCooldownInterval = null;

    function showForgotStep(name) {
        Object.keys(forgotStepEls).forEach(function(key) {
            if (forgotStepEls[key]) forgotStepEls[key].hidden = key !== name;
        });
        const focusMap = { email: forgotEmailInput, otp: forgotOtpInput };
        const focusEl = focusMap[name];
        if (focusEl) setTimeout(() => focusEl.focus(), 50);
    }

    function setForgotAlert(el, message, type) {
        if (!el) return;
        el.textContent = message;
        el.className = 'forgot-inline-alert show ' + type;
    }

    function clearForgotAlert(el) {
        if (!el) return;
        el.textContent = '';
        el.className = 'forgot-inline-alert';
    }

    function resetForgotWizard() {
        clearInterval(forgotCooldownInterval);
        if (forgotEmailForm) forgotEmailForm.reset();
        if (forgotSelectForm) forgotSelectForm.reset();
        if (forgotResetForm) forgotResetForm.reset();
        if (forgotAccountList) forgotAccountList.innerHTML = '';
        clearForgotAlert(forgotEmailAlert);
        clearForgotAlert(forgotSelectAlert);
        clearForgotAlert(forgotOtpAlert);
        if (forgotResendBtn) { forgotResendBtn.disabled = false; forgotResendBtn.textContent = 'Resend OTP'; }
        showForgotStep('email');
    }

    function startForgotCooldown(seconds) {
        if (!forgotResendBtn) return;
        clearInterval(forgotCooldownInterval);
        let remaining = Math.max(0, parseInt(seconds, 10) || 0);
        if (remaining <= 0) {
            forgotResendBtn.disabled = false;
            forgotResendBtn.textContent = 'Resend OTP';
            return;
        }
        forgotResendBtn.disabled = true;
        forgotResendBtn.textContent = 'Resend OTP (' + remaining + 's)';
        forgotCooldownInterval = setInterval(function() {
            remaining -= 1;
            if (remaining <= 0) {
                clearInterval(forgotCooldownInterval);
                forgotResendBtn.disabled = false;
                forgotResendBtn.textContent = 'Resend OTP';
            } else {
                forgotResendBtn.textContent = 'Resend OTP (' + remaining + 's)';
            }
        }, 1000);
    }

    function escapeForgotHtml(str) {
        const div = document.createElement('div');
        div.textContent = str == null ? '' : String(str);
        return div.innerHTML;
    }

    function renderForgotAccountList(candidates) {
        if (!forgotAccountList) return;
        forgotAccountList.innerHTML = '';
        candidates.forEach(function(c) {
            const label = document.createElement('label');
            label.className = 'forgot-account-option';
            const businessHtml = c.business_name ? '<span class="account-business">' + escapeForgotHtml(c.business_name) + '</span>' : '';
            label.innerHTML =
                '<input type="radio" name="selected_user_id" value="' + Number(c.id) + '" required>' +
                '<span>' + escapeForgotHtml(c.masked_username) + businessHtml + '</span>';
            forgotAccountList.appendChild(label);
        });
    }

    function fpPost(url, formData) {
        return fetch(url, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: formData
        }).then(function(res) {
            return res.json();
        });
    }

    function requestForgotOtp(formData, alertEl) {
        if (!formData.has('portal')) formData.append('portal', forgotPortal);
        return fpPost(forgotEndpoints.sendOtp, formData)
            .then(function(data) {
                if (!data.success) {
                    if (data.restart) {
                        resetForgotWizard();
                        setForgotAlert(forgotEmailAlert, data.message || 'Please start again.', 'error');
                        return;
                    }
                    if (typeof data.cooldown_remaining === 'number') {
                        showForgotStep('otp');
                        setForgotAlert(forgotOtpAlert, data.message || 'Please wait before requesting another OTP.', 'error');
                        startForgotCooldown(data.cooldown_remaining);
                        return;
                    }
                    setForgotAlert(alertEl, data.message || 'Something went wrong.', 'error');
                    return;
                }
                if (data.account_selected) {
                    clearForgotAlert(forgotOtpAlert);
                    showForgotStep('otp');
                    return data;
                }
                clearForgotAlert(forgotOtpAlert);
                showForgotStep('otp');
                startForgotCooldown(data.cooldown || 60);
                return data;
            })
            .catch(function() {
                setForgotAlert(alertEl, 'Something went wrong. Please try again.', 'error');
            });
    }

    if (forgotEmailForm) {
        forgotEmailForm.addEventListener('submit', function(e) {
            e.preventDefault();
            clearForgotAlert(forgotEmailAlert);
            const fd = new FormData();
            fd.append('email', forgotEmailInput.value.trim());
            fd.append('portal', forgotPortal);
            forgotEmailSubmitBtn.disabled = true;
            forgotEmailSubmitBtn.textContent = 'Please wait...';
            fpPost(forgotEndpoints.lookup, fd)
                .then(function(data) {
                    if (!data.success) {
                        setForgotAlert(forgotEmailAlert, data.message || 'Something went wrong.', 'error');
                        return;
                    }
                    setForgotAlert(forgotEmailAlert, data.message || 'If an account matches that email, an OTP will be sent to it.', 'success');
                    return requestForgotOtp(new FormData(), forgotEmailAlert);
                })
                .catch(function() {
                    setForgotAlert(forgotEmailAlert, 'Something went wrong. Please try again.', 'error');
                })
                .finally(function() {
                    forgotEmailSubmitBtn.disabled = false;
                    forgotEmailSubmitBtn.textContent = 'Continue';
                });
        });
    }

    if (forgotSelectForm) {
        forgotSelectForm.addEventListener('submit', function(e) {
            e.preventDefault();
            clearForgotAlert(forgotSelectAlert);
            const checked = forgotSelectForm.querySelector('input[name="selected_user_id"]:checked');
            if (!checked) {
                setForgotAlert(forgotSelectAlert, 'Please select an account.', 'error');
                return;
            }
            const fd = new FormData();
            fd.append('selected_user_id', checked.value);
            forgotSelectSubmitBtn.disabled = true;
            forgotSelectSubmitBtn.textContent = 'Please wait...';
            requestForgotOtp(fd, forgotSelectAlert).then(function(data) {
                if (data && data.account_selected && forgotResetForm) {
                    forgotResetForm.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
                }
            }).finally(function() {
                forgotSelectSubmitBtn.disabled = false;
                forgotSelectSubmitBtn.textContent = 'Choose Account';
            });
        });
    }

    if (forgotResetForm) {
        forgotResetForm.addEventListener('submit', function(e) {
            e.preventDefault();
            clearForgotAlert(forgotOtpAlert);
            if (forgotNewPassword.value !== forgotConfirmPassword.value) {
                setForgotAlert(forgotOtpAlert, 'Passwords do not match.', 'error');
                return;
            }
            const fd = new FormData();
            fd.append('otp_code', forgotOtpInput.value.trim());
            fd.append('new_password', forgotNewPassword.value);
            fd.append('confirm_new_password', forgotConfirmPassword.value);
            fd.append('portal', forgotPortal);
            forgotResetSubmitBtn.disabled = true;
            forgotResetSubmitBtn.textContent = 'Please wait...';
            fpPost(forgotEndpoints.reset, fd)
                .then(function(data) {
                    if (data.selection_required) {
                        renderForgotAccountList(data.candidates || []);
                        setForgotAlert(forgotSelectAlert, data.message || 'Email ownership verified. Choose which account to reset.', 'success');
                        showForgotStep('select');
                        return;
                    }
                    if (!data.success) {
                        if (data.restart) {
                            resetForgotWizard();
                            setForgotAlert(forgotEmailAlert, data.message || 'Please start again.', 'error');
                            return;
                        }
                        setForgotAlert(forgotOtpAlert, data.message || 'Something went wrong.', 'error');
                        return;
                    }
                    const successMessage = data.message || 'Password reset successful. You may now log in.';
                    closeModals();
                    resetForgotWizard();
                    setTimeout(function() {
                        openLoginModal();
                        if (typeof nxShowPopup === 'function') nxShowPopup('success', successMessage);
                    }, 420);
                })
                .catch(function() {
                    setForgotAlert(forgotOtpAlert, 'Something went wrong. Please try again.', 'error');
                })
                .finally(function() {
                    forgotResetSubmitBtn.disabled = false;
                    forgotResetSubmitBtn.textContent = 'Reset Password';
                });
        });
    }

    if (forgotResendBtn) {
        forgotResendBtn.addEventListener('click', function() {
            if (forgotResendBtn.disabled) return;
            clearForgotAlert(forgotOtpAlert);
            requestForgotOtp(new FormData(), forgotOtpAlert);
        });
    }

    if (forgotBackToLoginFromEmail) {
        forgotBackToLoginFromEmail.addEventListener('click', function(e) {
            e.preventDefault();
            closeForgotAndOpen(openLoginModal);
        });
    }

    if (forgotStartOverFromSelect) {
        forgotStartOverFromSelect.addEventListener('click', function(e) {
            e.preventDefault();
            resetForgotWizard();
        });
    }

    if (forgotStartOverFromOtp) {
        forgotStartOverFromOtp.addEventListener('click', function(e) {
            e.preventDefault();
            resetForgotWizard();
        });
    }

    window.nxShowPopup = function(type, message) {
        const overlay = document.createElement('div');
        overlay.className = 'popup-overlay';
        const box = document.createElement('div');
        box.className = 'popup-box ' + type;
        const icon = document.createElement('div');
        icon.className = 'popup-icon';
        icon.textContent = type === 'success' ? '✓' : '!';
        const heading = document.createElement('h3');
        heading.textContent = type === 'success' ? 'Success' : 'Error';
        const text = document.createElement('p');
        text.textContent = message;
        box.appendChild(icon);
        box.appendChild(heading);
        box.appendChild(text);
        overlay.appendChild(box);
        document.body.appendChild(overlay);

        function dismiss() {
            box.classList.add('popup-hide');
            overlay.classList.add('popup-overlay-hide');
            setTimeout(function() { overlay.remove(); }, 600);
        }

        const autoTimer = setTimeout(dismiss, 7000);
        overlay.addEventListener('click', function() {
            clearTimeout(autoTimer);
            dismiss();
        });
        box.addEventListener('click', function(e) { e.stopPropagation(); });
    };

    window.addEventListener("click", function(e) {
        if (e.target === loginModal || e.target === signupModal || e.target === forgotModal) {
            closeModals();
        }
    });

    /* POPUP AUTO HIDE + CLICK TO CLOSE */
    const popupOverlay = document.getElementById("popupOverlay");
    const popupBox = document.getElementById("popupBox");

    function closePopup() {
        if (popupOverlay && popupBox) {
            popupBox.classList.add("popup-hide");
            popupOverlay.classList.add("popup-overlay-hide");

            setTimeout(() => {
                popupOverlay.remove();
            }, 600);
        }
    }

    if (popupOverlay && popupBox) {
        setTimeout(() => {
            closePopup();
        }, 7000);

        popupOverlay.addEventListener("click", function() {
            closePopup();
        });

        popupBox.addEventListener("click", function(e) {
            e.stopPropagation();
        });
    }

    // Handle auto-opening modals on page load
    <?php if ($openLoginAfterLoad): ?>
        if (typeof openLoginModal === "function") {
            setTimeout(() => openLoginModal(), 100);
        }
    <?php endif; ?>

    <?php if ($openSignupAfterLoad): ?>
        if (typeof openSignupModal === "function") {
            setTimeout(() => openSignupModal(), 100);
        }
    <?php endif; ?>

    <?php if ($openForgotAfterLoad): ?>
        if (typeof openForgotModal === "function") {
            setTimeout(() => openForgotModal(), 100);
        }
    <?php endif; ?>

    const cookieBanner = document.getElementById('cookieBanner');
    const acceptCookiesBtn = document.getElementById('acceptCookiesBtn');
    const declineCookiesBtn = document.getElementById('declineCookiesBtn');
    const cookieConsent = localStorage.getItem('nextgen_cookie_consent');

    if (!cookieConsent && cookieBanner) {
        cookieBanner.classList.add('show');
    }

    if (acceptCookiesBtn) {
        acceptCookiesBtn.addEventListener('click', function() {
            localStorage.setItem('nextgen_cookie_consent', 'accepted');
            cookieBanner.classList.remove('show');
        });
    }

    if (declineCookiesBtn) {
        declineCookiesBtn.addEventListener('click', function() {
            localStorage.setItem('nextgen_cookie_consent', 'declined');
            cookieBanner.classList.remove('show');
        });
    }

    const loginRobotCheck = document.getElementById('loginRobotCheck');
    const loginCaptchaPopup = document.getElementById('loginCaptchaPopup');
    const closeLoginCaptchaPopup = document.getElementById('closeLoginCaptchaPopup');
    const cancelLoginCaptcha = document.getElementById('cancelLoginCaptcha');
    const verifyLoginCaptcha = document.getElementById('verifyLoginCaptcha');
    const loginVerifiedText = document.getElementById('loginVerifiedText');

    function loadCaptchaImages(popup) {
        if (!popup) return;

        popup.querySelectorAll('img[data-captcha-src]').forEach(function(image) {
            if (image.dataset.captchaLoaded === '1') return;

            image.src = image.dataset.captchaSrc;
            image.dataset.captchaLoaded = '1';
        });
    }

    function openLoginCaptchaModal() {
        if (loginCaptchaPopup) {
            loadCaptchaImages(loginCaptchaPopup);
            loginCaptchaPopup.classList.add('show');
            document.body.style.overflow = 'hidden';
        }
    }

    function closeLoginCaptchaModal(uncheck = true) {
        if (loginCaptchaPopup) {
            loginCaptchaPopup.classList.remove('show');
            document.body.style.overflow = '';
        }

        if (uncheck && loginRobotCheck) {
            loginRobotCheck.checked = false;
        }
    }

    function currentEnteredUsername() {
        const el = document.getElementById('loginUsername');
        return el ? el.value.trim() : '';
    }

    function isLockedUsernameAttempt() {
        const entered = currentEnteredUsername().toLowerCase();
        const lockedUser = (lockoutUsername || '').toLowerCase();

        if (!lockoutActive || lockoutUntilTs <= Math.floor(Date.now() / 1000)) {
            return false;
        }

        if (!lockedUser) {
            return false;
        }

        return entered !== '' && entered === lockedUser;
    }

    if (loginRobotCheck) {
        loginRobotCheck.addEventListener('change', function(e) {
            if (isLockedUsernameAttempt()) {
                e.preventDefault();
                this.checked = false;
                openLockoutPopup();
                return;
            }

            if (this.checked) {
                openLoginCaptchaModal();
            } else {
                if (loginVerifiedText) loginVerifiedText.classList.remove('show');
                document.querySelectorAll('#loginCaptchaPopup input[type="checkbox"]').forEach(function(box) {
                    box.checked = false;
                });
            }
        });
    }

    if (closeLoginCaptchaPopup) {
        closeLoginCaptchaPopup.addEventListener('click', function() {
            closeLoginCaptchaModal(true);
        });
    }

    if (cancelLoginCaptcha) {
        cancelLoginCaptcha.addEventListener('click', function() {
            closeLoginCaptchaModal(true);
        });
    }

    if (verifyLoginCaptcha) {
        verifyLoginCaptcha.addEventListener('click', function() {
            const checked = document.querySelectorAll('#loginCaptchaPopup input[type="checkbox"]:checked').length;
            if (checked > 0) {
                closeLoginCaptchaModal(false);
                if (loginVerifiedText) loginVerifiedText.classList.add('show');
            } else {
                openCaptchaNotice('Please select captcha images first.');
            }
        });
    }

    const signupRobotCheck = document.getElementById('signupRobotCheck');
    const signupCaptchaPopup = document.getElementById('signupCaptchaPopup');
    const closeSignupCaptchaPopup = document.getElementById('closeSignupCaptchaPopup');
    const cancelSignupCaptcha = document.getElementById('cancelSignupCaptcha');
    const verifySignupCaptcha = document.getElementById('verifySignupCaptcha');
    const signupVerifiedText = document.getElementById('signupVerifiedText');

    function openSignupCaptchaModal() {
        if (signupCaptchaPopup) {
            loadCaptchaImages(signupCaptchaPopup);
            signupCaptchaPopup.classList.add('show');
            document.body.style.overflow = 'hidden';
        }
    }

    function closeSignupCaptchaModal(uncheck = true) {
        if (signupCaptchaPopup) {
            signupCaptchaPopup.classList.remove('show');
            document.body.style.overflow = '';
        }

        if (uncheck && signupRobotCheck) {
            signupRobotCheck.checked = false;
            if (signupVerifiedText) signupVerifiedText.classList.remove('show');
            document.querySelectorAll('#signupCaptchaPopup input[type="checkbox"]').forEach(function(box) {
                box.checked = false;
            });
            document.dispatchEvent(new Event('signup-prerequisite-change'));
        }
    }

    if (signupRobotCheck) {
        signupRobotCheck.addEventListener('change', function() {
            if (this.checked) {
                openSignupCaptchaModal();
            } else {
                if (signupVerifiedText) signupVerifiedText.classList.remove('show');
                document.querySelectorAll('#signupCaptchaPopup input[type="checkbox"]').forEach(function(box) {
                    box.checked = false;
                });
                document.dispatchEvent(new Event('signup-prerequisite-change'));
            }
        });
    }

    if (closeSignupCaptchaPopup) {
        closeSignupCaptchaPopup.addEventListener('click', function() {
            closeSignupCaptchaModal(true);
        });
    }

    if (cancelSignupCaptcha) {
        cancelSignupCaptcha.addEventListener('click', function() {
            closeSignupCaptchaModal(true);
        });
    }

    if (verifySignupCaptcha) {
        verifySignupCaptcha.addEventListener('click', function() {
            const checked = document.querySelectorAll('#signupCaptchaPopup input[type="checkbox"]:checked').length;
            if (checked > 0) {
                closeSignupCaptchaModal(false);
                if (signupVerifiedText) signupVerifiedText.classList.add('show');
                document.dispatchEvent(new Event('signup-prerequisite-change'));
            } else {
                alert('Please select captcha images first.');
            }
        });
    }

    document.addEventListener('click', function(e) {
        if (e.target === loginCaptchaPopup) {
            closeLoginCaptchaModal(true);
        }

        if (e.target === signupCaptchaPopup) {
            closeSignupCaptchaModal(true);
        }
        if (e.target === captchaNoticePopup) {
    closeCaptchaNotice();
}
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeLoginCaptchaModal(true);
            closeSignupCaptchaModal(true);
        }
        closeCaptchaNotice();
    });

    const signupForm = document.getElementById('signupForm');
    const signupPassword = document.getElementById('signupPassword');
    const signupConfirmPassword = document.getElementById('signupConfirmPassword');
    const signupPasswordError = document.getElementById('signupPasswordError');
    const signupConfirmPasswordError = document.getElementById('signupConfirmPasswordError');
    const signupPhone = document.getElementById('signupPhone');
    let signupPasswordTouched = false;
    let signupConfirmPasswordTouched = false;

    if (signupPhone) {
        signupPhone.addEventListener('beforeinput', function(event) {
            if (event.data && /[^0-9]/.test(event.data)) {
                event.preventDefault();
            }
        });
        signupPhone.addEventListener('input', function() {
            this.value = this.value.replace(/[^0-9]/g, '').slice(0, 11);
        });
    }

    function validateSignupPasswords() {
        if (!signupPassword || !signupConfirmPassword) return;

        const password = signupPassword.value;
        const passwordLength = Array.from(password).length;
        let passwordMessage = '';

        if (password && (passwordLength < 12 || new TextEncoder().encode(password).length > 64)) {
            passwordMessage = 'Password must be 12–64 characters.';
        } else if (password && /[\x00-\x1F\x7F]/.test(password)) {
            passwordMessage = 'Password cannot contain control characters.';
        } else if (password && (!/[a-z]/.test(password) || !/[A-Z]/.test(password) || !/\d/.test(password) || !/[^A-Za-z0-9]/u.test(password))) {
            passwordMessage = 'Include an uppercase letter, a lowercase letter, a number, and a special character.';
        }

        signupPassword.setCustomValidity(passwordMessage);
        signupPassword.setAttribute('aria-invalid', signupPasswordTouched && (passwordMessage !== '' || password === '') ? 'true' : 'false');
        if (signupPasswordError) {
            signupPasswordError.textContent = signupPasswordTouched
                ? (passwordMessage || (password === '' ? 'Please enter a password.' : ''))
                : '';
        }

        let confirmMessage = '';
        if (signupConfirmPassword.value && password !== signupConfirmPassword.value) {
            confirmMessage = 'Passwords do not match.';
        }

        signupConfirmPassword.setCustomValidity(confirmMessage);
        signupConfirmPassword.setAttribute('aria-invalid', signupConfirmPasswordTouched && (confirmMessage !== '' || signupConfirmPassword.value === '') ? 'true' : 'false');
        if (signupConfirmPasswordError) {
            signupConfirmPasswordError.textContent = signupConfirmPasswordTouched
                ? (confirmMessage || (signupConfirmPassword.value === '' ? 'Please confirm your password.' : ''))
                : '';
        }
    }

    if (signupPassword && signupConfirmPassword) {
        validateSignupPasswords();

        signupPassword.addEventListener('input', function() {
            signupPasswordTouched = true;
            validateSignupPasswords();
        });
        signupPassword.addEventListener('blur', function() {
            signupPasswordTouched = true;
            validateSignupPasswords();
        });
        signupPassword.addEventListener('invalid', function() {
            signupPasswordTouched = true;
            validateSignupPasswords();
        });

        signupConfirmPassword.addEventListener('input', function() {
            signupConfirmPasswordTouched = true;
            validateSignupPasswords();
        });
        signupConfirmPassword.addEventListener('blur', function() {
            signupConfirmPasswordTouched = true;
            validateSignupPasswords();
        });
        signupConfirmPassword.addEventListener('invalid', function() {
            signupConfirmPasswordTouched = true;
            validateSignupPasswords();
        });

        if (signupForm) {
            signupForm.addEventListener('submit', function() {
                signupPasswordTouched = true;
                signupConfirmPasswordTouched = true;
                validateSignupPasswords();
            });
        }
    }

    document.querySelectorAll('.password-toggle-btn').forEach(function(button) {
        button.addEventListener('click', function() {
            const targetId = this.getAttribute('data-target');
            const input = document.getElementById(targetId);
            const openIcon = this.querySelector('.eye-open');
            const closedIcon = this.querySelector('.eye-closed');

            if (!input) return;

            if (input.type === 'password') {
                input.type = 'text';
                this.setAttribute('aria-label', 'Hide password');
                if (openIcon) openIcon.style.display = 'none';
                if (closedIcon) closedIcon.style.display = 'flex';
            } else {
                input.type = 'password';
                this.setAttribute('aria-label', 'Show password');
                if (openIcon) openIcon.style.display = 'flex';
                if (closedIcon) closedIcon.style.display = 'none';
            }
        });
    });
    const captchaNoticePopup = document.getElementById('captchaNoticePopup');
    const captchaNoticeMessage = document.getElementById('captchaNoticeMessage');
    const captchaNoticeOkBtn = document.getElementById('captchaNoticeOkBtn');

    function openCaptchaNotice(message) {
        if (!captchaNoticePopup || !captchaNoticeMessage) return;
        captchaNoticeMessage.textContent = message || 'Please select captcha images first.';
        captchaNoticePopup.classList.add('show');
        document.body.style.overflow = 'hidden';
    }

    function closeCaptchaNotice() {
        if (!captchaNoticePopup) return;
        captchaNoticePopup.classList.remove('show');

        const loginOpen = loginCaptchaPopup && loginCaptchaPopup.classList.contains('show');
        const signupOpen = signupCaptchaPopup && signupCaptchaPopup.classList.contains('show');

        document.body.style.overflow = (loginOpen || signupOpen) ? 'hidden' : '';
    }

    if (captchaNoticeOkBtn) {
        captchaNoticeOkBtn.addEventListener('click', closeCaptchaNotice);
    }

    const lockoutActive = <?php echo $showLockCountdown ? 'true' : 'false'; ?>;
    const lockoutUntilTs = <?php echo (int)$lockoutUntilTs; ?>;
    const lockoutUsername = <?php echo json_encode($lockoutUsername); ?>;
    const lockoutIsAdmin = <?php echo $lockoutIsAdmin ? 'true' : 'false'; ?>;

    const lockoutPopup = document.getElementById('lockoutPopup');
    const lockoutTimer = document.getElementById('lockoutTimer');
    const lockoutMessage = document.getElementById('lockoutMessage');
    const lockoutOkayBtn = document.getElementById('lockoutOkayBtn');
    const lockoutBackBtn = document.getElementById('lockoutBackBtn');
    const adminOtpUnlockBtn = document.getElementById('adminOtpUnlockBtn');
    const adminUnlockNote = document.getElementById('adminUnlockNote');

    const loginForm = document.getElementById('loginForm');
    const loginUsername = document.getElementById('loginUsername');
    const loginPassword = document.getElementById('loginPassword');
    const loginSubmitBtn = document.getElementById('loginSubmitBtn');
    const loginLockNote = document.getElementById('loginLockNote');

    function formatSeconds(totalSeconds) {
        const mins = Math.floor(totalSeconds / 60);
        const secs = totalSeconds % 60;
        return String(mins).padStart(2, '0') + ':' + String(secs).padStart(2, '0');
    }

    function setLoginLockedState(showNoteOnly) {
        if (loginUsername) loginUsername.disabled = false;
        if (loginPassword) loginPassword.disabled = false;
        if (loginSubmitBtn) loginSubmitBtn.disabled = false;
        if (loginRobotCheck) loginRobotCheck.disabled = false;

        document.querySelectorAll('#loginForm .password-toggle-btn').forEach(function(btn) {
            btn.disabled = false;
        });

        if (loginLockNote) {
            loginLockNote.classList.toggle('show', showNoteOnly);
        }
    }

    function openLockoutPopup() {
        if (lockoutPopup) {
            lockoutPopup.classList.add('show');
        }
    }

    function closeLockoutPopup() {
        if (lockoutPopup) {
            lockoutPopup.classList.remove('show');
        }
    }

    if (lockoutBackBtn) {
        lockoutBackBtn.addEventListener('click', function() {
            closeLockoutPopup();
        });
    }

    if (adminOtpUnlockBtn) {
        adminOtpUnlockBtn.addEventListener('click', function() {
            window.location.href = '/NexGen/CODE/PHP/admin_unlock_otp.php';
        });
    }

    if (lockoutActive && lockoutUntilTs > 0) {
        if (typeof openLoginModal === "function") openLoginModal();
        setLoginLockedState(true);

        if (currentEnteredUsername().toLowerCase() === (lockoutUsername || '').toLowerCase()) {
            openLockoutPopup();
        }

        if (lockoutIsAdmin) {
            if (adminOtpUnlockBtn) adminOtpUnlockBtn.style.display = 'inline-flex';
            if (adminUnlockNote) adminUnlockNote.style.display = 'block';
        } else {
            if (adminOtpUnlockBtn) adminOtpUnlockBtn.style.display = 'none';
            if (adminUnlockNote) adminUnlockNote.style.display = 'none';
        }

        if (lockoutMessage) {
            if (lockoutUsername) {
                lockoutMessage.textContent = 'The account "' + lockoutUsername + '" is temporarily locked due to multiple failed login attempts. Please wait for the countdown to finish.';
            } else {
                lockoutMessage.textContent = 'This account is temporarily locked due to multiple failed login attempts. Please wait for the countdown to finish.';
            }
        }

        const timerInterval = setInterval(function() {
            const now = Math.floor(Date.now() / 1000);
            const remaining = lockoutUntilTs - now;

            if (remaining <= 0) {
                clearInterval(timerInterval);

                if (lockoutTimer) {
                    lockoutTimer.textContent = '00:00';
                }

                if (lockoutMessage) {
                    lockoutMessage.textContent = 'The lock period has ended. You may now try logging in again.';
                }

                if (lockoutOkayBtn) {
                    lockoutOkayBtn.disabled = false;
                    lockoutOkayBtn.textContent = 'Try Login Again';
                    lockoutOkayBtn.onclick = function() {
                        closeLockoutPopup();
                    };
                }

                if (adminOtpUnlockBtn) adminOtpUnlockBtn.style.display = 'none';
                if (adminUnlockNote) adminUnlockNote.style.display = 'none';

                setLoginLockedState(false);
                return;
            }

            if (lockoutTimer) {
                lockoutTimer.textContent = formatSeconds(remaining);
            }
        }, 1000);
    } else {
        setLoginLockedState(false);

        if (lockoutOkayBtn) {
            lockoutOkayBtn.disabled = false;
            lockoutOkayBtn.textContent = 'OK';
            lockoutOkayBtn.onclick = function() {
                closeLockoutPopup();
            };
        }

        if (adminOtpUnlockBtn) adminOtpUnlockBtn.style.display = 'none';
        if (adminUnlockNote) adminUnlockNote.style.display = 'none';
    }

    if (loginForm) {
        loginForm.addEventListener('submit', function(e) {
            if (isLockedUsernameAttempt()) {
                e.preventDefault();
                openLockoutPopup();
                return false;
            }
        });
    }

    if (loginUsername) {
        loginUsername.addEventListener('input', function() {
            if (!isLockedUsernameAttempt()) {
                closeLockoutPopup();
                if (loginLockNote) loginLockNote.classList.remove('show');
            } else {
                if (loginLockNote) loginLockNote.classList.add('show');
            }
        });
    }

    const showAttemptPopup = <?php echo $showAttemptPopup ? 'true' : 'false'; ?>;
    const attemptsLeft = <?php echo $attemptsLeft === null ? 'null' : (int)$attemptsLeft; ?>;
    const attemptPopupText = <?php echo json_encode($attemptPopupText); ?>;

    const attemptPopup = document.getElementById('attemptPopup');
    const attemptPopupBtn = document.getElementById('attemptPopupBtn');
    const attemptsLeftHighlight = document.getElementById('attemptsLeftHighlight');
    const attemptPopupMessage = document.getElementById('attemptPopupMessage');

    if (showAttemptPopup && attemptPopup) {
        if (typeof openLoginModal === "function") openLoginModal();

        if (attemptsLeftHighlight && attemptsLeft !== null) {
            attemptsLeftHighlight.textContent = String(attemptsLeft);
        }

        if (attemptPopupMessage) {
            attemptPopupMessage.textContent = attemptPopupText || 'Your login attempt was not successful.';
        }

        attemptPopup.classList.add('show');
    }

    if (attemptPopupBtn) {
        attemptPopupBtn.addEventListener('click', function() {
            if (attemptPopup) {
                attemptPopup.classList.remove('show');
            }
        });
    }

    // Toggle dropdown on click
    const dropdownTrigger = document.querySelector('.has-dropdown');
    if (dropdownTrigger) {
        dropdownTrigger.addEventListener('click', function(e) {
            e.stopPropagation();
            this.classList.toggle('active');
        });
    }
    document.addEventListener('click', () => {
        const activeDropdown = document.querySelector('.has-dropdown.active');
        if (activeDropdown) activeDropdown.classList.remove('active');
    });

    // ========== MOBILE NAV DRAWER ==========
    const navToggleBtn = document.getElementById('navToggle');
    const navLinksEl = document.getElementById('navLinks');
    const navOverlayEl = document.getElementById('navOverlay');

    function openMobileNav() {
        if (!navLinksEl || !navOverlayEl || !navToggleBtn) return;
        navLinksEl.classList.add('open');
        navOverlayEl.classList.add('show');
        document.body.classList.add('nav-open');
        navToggleBtn.setAttribute('aria-expanded', 'true');
        navToggleBtn.textContent = '✕';
    }

    function closeMobileNav() {
        if (!navLinksEl || !navOverlayEl || !navToggleBtn) return;
        navLinksEl.classList.remove('open');
        navOverlayEl.classList.remove('show');
        document.body.classList.remove('nav-open');
        navToggleBtn.setAttribute('aria-expanded', 'false');
        navToggleBtn.textContent = '☰';
        const activeDropdown = document.querySelector('.has-dropdown.active');
        if (activeDropdown) activeDropdown.classList.remove('active');
    }

    if (navToggleBtn && navLinksEl) {
        navToggleBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            if (navLinksEl.classList.contains('open')) {
                closeMobileNav();
            } else {
                openMobileNav();
            }
        });
    }

    if (navOverlayEl) {
        navOverlayEl.addEventListener('click', closeMobileNav);
    }

    if (navLinksEl) {
        navLinksEl.querySelectorAll('a').forEach(function(link) {
            const isDropdownTrigger = link.parentElement && link.parentElement.classList.contains('has-dropdown');
            if (!isDropdownTrigger) {
                link.addEventListener('click', closeMobileNav);
            }
        });
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeMobileNav();
    });

    window.addEventListener('resize', function() {
        if (window.innerWidth > 980) closeMobileNav();
    }, { passive: true });
});
</script>
<script>

// ========== BACK TO TOP ==========
document.addEventListener('DOMContentLoaded', function () {
    const backToTop = document.getElementById('backToTop');
    if (!backToTop) return;

    function toggleBackToTop() {
        if (window.scrollY > 500) {
            backToTop.classList.add('show');
        } else {
            backToTop.classList.remove('show');
        }
    }
    toggleBackToTop();
    window.addEventListener('scroll', toggleBackToTop, { passive: true });

    backToTop.addEventListener('click', function () {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const roleSelect = document.getElementById('requestedRole');
    const ownerFields = document.querySelectorAll('.owner-fields');
    const employeeFields = document.querySelectorAll('.employee-fields');
    const businessName = document.getElementById('businessName');
    const businessType = document.getElementById('businessType');
    const businessAddress = document.getElementById('businessAddress');
    const businessPermit = document.getElementById('businessPermit');
    const businessCode = document.getElementById('businessCode');
    const branchCode = document.getElementById('branchCode');
    const employeeNumber = document.getElementById('employeeNumber');

    function toggleGroup(fields, show) {
        fields.forEach(function (field) { field.hidden = !show; });
    }

    function setRequired(input, required) {
        if (!input) return;
        input.required = required;
        if (!required) input.value = '';
    }

    function updateSignupRoleFields() {
        const role = roleSelect ? roleSelect.value : '';
        const isOwner = role === 'owner';
        const isEmployee = role === 'employee';

        toggleGroup(ownerFields, isOwner);
        toggleGroup(employeeFields, isEmployee);
        setRequired(businessName, isOwner);
        setRequired(businessType, isOwner);
        setRequired(businessAddress, isOwner);
        setRequired(businessPermit, isOwner);
        setRequired(businessCode, isEmployee);
        setRequired(branchCode, isEmployee);
        setRequired(employeeNumber, isEmployee);
    }

    if (roleSelect) {
        roleSelect.addEventListener('change', updateSignupRoleFields);
        updateSignupRoleFields();
    }
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const signupForm = document.getElementById('signupForm');
    const submitButton = document.getElementById('signupSubmitBtn');
    var policyLink = document.getElementById('privacyPolicyLink');
    var consentCheckbox = document.getElementById('privacy_consent');
    var consentHint = document.getElementById('privacyConsentHint');
    var consentHintText = document.getElementById('privacyConsentHintText');
    var signupCaptchaPopup = document.getElementById('signupCaptchaPopup');
    var signupVerifiedText = document.getElementById('signupVerifiedText');
    var signupRobotCheck = document.getElementById('signupRobotCheck');
    var expectedAckToken = signupForm
        ? signupForm.querySelector('[name="privacy_ack_token"]').value
        : '';
    var privacyAcknowledged = false;

    function updateConsentState() {
        if (!consentCheckbox) return;
        consentCheckbox.disabled = !privacyAcknowledged;
        if (consentHint) {
            consentHint.classList.toggle('is-cleared', privacyAcknowledged);
        }
        if (consentHintText) {
            consentHintText.textContent = privacyAcknowledged
                ? 'Thanks — you can check the box now.'
                : 'Read the Privacy Policy and click “I understand” to enable consent.';
        }
    }

    function signupCaptchaIsVerified() {
        return !!signupCaptchaPopup
            && !!signupVerifiedText
            && !!signupRobotCheck
            && signupRobotCheck.checked
            && signupVerifiedText.classList.contains('show')
            && signupCaptchaPopup.querySelectorAll('input[type="checkbox"]:checked').length > 0;
    }

    function updateSignupSubmitState() {
        if (!signupForm || !submitButton || !consentCheckbox) return;

        const fieldsAreValid = Array.from(signupForm.elements).every(function(control) {
            return control.disabled || !control.willValidate || control.validity.valid;
        });

        submitButton.disabled = !fieldsAreValid
            || !privacyAcknowledged
            || !consentCheckbox.checked
            || !signupCaptchaIsVerified();
    }

    updateConsentState();

    if (policyLink && consentCheckbox) {
        window.addEventListener('storage', function(event) {
            if (event.key !== 'nexgen_signup_privacy_ack' || event.newValue !== expectedAckToken) {
                return;
            }

            privacyAcknowledged = true;
            updateConsentState();
            updateSignupSubmitState();
        });
    }

    if (signupForm) {
        signupForm.addEventListener('input', updateSignupSubmitState);
        signupForm.addEventListener('change', updateSignupSubmitState);

        signupForm.addEventListener('submit', function(event) {
            updateSignupSubmitState();
            if (submitButton.disabled) {
                event.preventDefault();
            }
        });
    }

    if (signupCaptchaPopup) {
        signupCaptchaPopup.addEventListener('change', updateSignupSubmitState);
        signupCaptchaPopup.addEventListener('click', function() {
            window.setTimeout(updateSignupSubmitState, 0);
        });
    }

    document.addEventListener('signup-prerequisite-change', updateSignupSubmitState);
    updateSignupSubmitState();
});
</script>



<script>
/* ============================================================
   NEXGEN LANDING PAGE THEME
   Works for guests — no login/session required.
   Shares "nexgen-theme" with Settings and authenticated pages.
   ============================================================ */
(function () {
    "use strict";

    const THEME_KEY = "nexgen-theme";

    function normalizeTheme(theme) {
        return theme === "light" ? "light" : "dark";
    }

    function getCurrentTheme() {
        return normalizeTheme(
            document.documentElement.getAttribute("data-theme")
        );
    }

    function updateLandingThemeControl(theme) {
        const button = document.getElementById("landingThemeToggle");
        const icon = document.getElementById("landingThemeIcon");
        const isLight = normalizeTheme(theme) === "light";

        if (icon) {
            icon.className = isLight
                ? "bi bi-sun-fill"
                : "bi bi-moon-stars-fill";
        }

        if (button) {
            button.setAttribute(
                "aria-label",
                isLight ? "Switch to dark mode" : "Switch to light mode"
            );
            button.setAttribute(
                "aria-pressed",
                isLight ? "true" : "false"
            );
        }
    }

    function setLandingTheme(theme, savePreference = true) {
        const nextTheme = normalizeTheme(theme);

        document.documentElement.setAttribute(
            "data-theme",
            nextTheme
        );

        if (savePreference) {
            try {
                localStorage.setItem(THEME_KEY, nextTheme);
            } catch (error) {
                /* Theme still changes for this page even if storage is unavailable. */
            }
        }

        updateLandingThemeControl(nextTheme);
    }

    function initializeLandingThemeToggle() {
        const button = document.getElementById("landingThemeToggle");

        updateLandingThemeControl(getCurrentTheme());

        if (!button || button.dataset.themeBound === "true") {
            return;
        }

        button.dataset.themeBound = "true";

        button.addEventListener("click", function (event) {
            event.preventDefault();
            event.stopPropagation();

            const nextTheme =
                getCurrentTheme() === "light" ? "dark" : "light";

            setLandingTheme(nextTheme, true);
        });
    }

    if (document.readyState === "loading") {
        document.addEventListener(
            "DOMContentLoaded",
            initializeLandingThemeToggle,
            { once: true }
        );
    } else {
        initializeLandingThemeToggle();
    }

    /* Keep another open tab synchronized too. */
    window.addEventListener("storage", function (event) {
        if (
            event.key === THEME_KEY &&
            (event.newValue === "light" || event.newValue === "dark")
        ) {
            setLandingTheme(event.newValue, false);
        }
    });
})();
</script>

<script>
(() => {
  const openButton = document.getElementById("openVideoPlayer");
  const closeButton = document.getElementById("closeVideoPlayer");
  const dialog = document.getElementById("videoPlayerDialog");
  const video = document.getElementById("landingPageVideo");
  const message = document.getElementById("videoPlayerMessage");

  if (
    !(openButton instanceof HTMLButtonElement) ||
    !(closeButton instanceof HTMLButtonElement) ||
    !(dialog instanceof HTMLDialogElement) ||
    !(video instanceof HTMLVideoElement) ||
    !(message instanceof HTMLElement)
  ) {
    return;
  }

  let previousBodyOverflow = "";

  openButton.addEventListener("click", () => {
    if (dialog.open) {
      return;
    }

    previousBodyOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    message.textContent = "";
    dialog.showModal();

    video.play().catch(() => {
      message.textContent = "Press play on the video controls to start playback.";
    });
  });

  closeButton.addEventListener("click", () => dialog.close());

  dialog.addEventListener("click", (event) => {
    if (event.target === dialog) {
      dialog.close();
    }
  });

  dialog.addEventListener("close", () => {
    video.pause();
    document.body.style.overflow = previousBodyOverflow;
    message.textContent = "";
    openButton.focus();
  });

  video.addEventListener("error", () => {
    message.textContent = "The video could not be loaded. Please try again later.";
  });
})();
</script>

</body>
</html>
