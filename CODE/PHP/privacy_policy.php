<?php
session_start();

$backLink = "/NexGen/CODE/PHP/index.php";
$returnTo = $_GET['return_to'] ?? '';
$isSignupReturn = !isset($_SESSION['user_id']) && $returnTo === 'signup';

if ($returnTo === 'about_us') {
    $backLink = "about_us.php";
} elseif (isset($_SESSION['user_id'])) {
    $backLink = "/NexGen/CODE/PHP/settings.php";
} elseif ($returnTo === 'signup') {
    $backLink = "/NexGen/CODE/PHP/index.php?open=signup";
} elseif ($returnTo === 'login') {
    $backLink = "/NexGen/CODE/PHP/index.php?open=login";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php include __DIR__ . '/nexgen_favicon/favicon.php'; ?>
    <?php include __DIR__ . '/theme_init.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy Policy - NexGen</title>
    <style>
        :root {
            --navy-950: #071a3d;
            --navy-900: #0b2257;
            --navy-800: #12367f;
            --surface: rgba(255, 255, 255, 0.075);
            --surface-strong: rgba(255, 255, 255, 0.11);
            --border: rgba(255, 255, 255, 0.16);
            --text: #f7f9ff;
            --muted: rgba(247, 249, 255, 0.72);
            --accent: #f7d447;
            --accent-soft: rgba(247, 212, 71, 0.13);
            --success: #83ddb0;
        }

        * { box-sizing: border-box; }

        html { scroll-behavior: smooth; }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: var(--text);
            background: linear-gradient(155deg, var(--navy-950) 0%, var(--navy-900) 54%, var(--navy-800) 100%);
        }

        .privacy-page {
            width: min(900px, calc(100% - 32px));
            margin: 0 auto;
            padding: 30px 0 56px;
        }

        .privacy-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 34px;
        }

        .privacy-logo {
            display: flex;
            align-items: center;
            gap: 13px;
            min-width: 0;
        }

        .privacy-logo img {
            width: 48px;
            height: 48px;
            object-fit: contain;
        }

        .privacy-logo-text h1 {
            margin: 0;
            font-size: clamp(1.35rem, 3vw, 1.9rem);
            letter-spacing: -0.03em;
            line-height: 1.1;
        }

        .privacy-logo-text p {
            margin: 5px 0 0;
            color: var(--muted);
            font-size: 0.82rem;
        }

        .understand-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border-radius: 11px;
            padding: 10px 15px;
            font: inherit;
            font-size: 0.88rem;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
            transition: transform 0.2s ease, background 0.2s ease, box-shadow 0.2s ease;
        }

        .understand-btn:hover { transform: translateY(-1px); }

        .policy-shell {
            overflow: hidden;
            border: 1px solid var(--border);
            border-radius: 22px;
            background: rgba(255, 255, 255, 0.045);
            box-shadow: 0 22px 55px rgba(0, 0, 0, 0.22);
        }

        .policy-intro {
            padding: 34px 34px 28px;
            border-bottom: 1px solid var(--border);
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            margin-bottom: 15px;
            color: var(--accent);
            font-size: 0.77rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .eyebrow::before {
            content: "";
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--success);
            box-shadow: 0 0 0 4px rgba(131, 221, 176, 0.12);
        }

        .policy-intro h2 {
            max-width: 580px;
            margin: 0;
            font-size: clamp(1.55rem, 4vw, 2.35rem);
            letter-spacing: -0.045em;
            line-height: 1.12;
        }

        .policy-intro h2 span { color: var(--accent); }

        .policy-intro p {
            max-width: 640px;
            margin: 15px 0 0;
            color: var(--muted);
            font-size: 0.98rem;
            line-height: 1.7;
        }

        .policy-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px 18px;
            margin-top: 20px;
            color: var(--muted);
            font-size: 0.78rem;
        }

        .policy-meta strong { color: var(--text); }

        .quick-summary {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            padding: 20px 34px;
            background: rgba(255, 255, 255, 0.025);
            border-bottom: 1px solid var(--border);
        }

        .summary-item {
            padding: 14px 15px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 13px;
            background: rgba(255, 255, 255, 0.04);
        }

        .summary-item strong {
            display: block;
            margin-bottom: 4px;
            color: var(--accent);
            font-size: 0.84rem;
        }

        .summary-item span {
            color: var(--muted);
            font-size: 0.79rem;
            line-height: 1.45;
        }

        .policy-sections { padding: 10px 34px 0; }

        details {
            border-bottom: 1px solid var(--border);
        }

        details:last-child { border-bottom: 0; }

        summary {
            display: flex;
            align-items: center;
            gap: 12px;
            list-style: none;
            padding: 19px 0;
            color: var(--text);
            font-size: 1rem;
            font-weight: 750;
            cursor: pointer;
        }

        summary::-webkit-details-marker { display: none; }

        summary::after {
            content: "⌄";
            margin-left: auto;
            color: var(--muted);
            font-size: 1.2rem;
            transition: transform 0.2s ease;
        }

        details[open] summary::after {
            color: var(--accent);
            transform: rotate(180deg);
        }

        .section-number {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 26px;
            width: 26px;
            height: 26px;
            border-radius: 8px;
            color: var(--accent);
            background: var(--accent-soft);
            font-size: 0.78rem;
        }

        .section-content {
            max-width: 720px;
            padding: 0 38px 21px;
            color: var(--muted);
            font-size: 0.89rem;
            line-height: 1.7;
        }

        .section-content p { margin: 0; }

        .section-content ul {
            margin: 0;
            padding-left: 18px;
        }

        .section-content li { margin: 5px 0; }

        .policy-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            padding: 22px 34px 28px;
            border-top: 1px solid var(--border);
        }

        .policy-footer p {
            max-width: 520px;
            margin: 0;
            color: var(--muted);
            font-size: 0.82rem;
            line-height: 1.55;
        }

        .understand-btn {
            flex: 0 0 auto;
            color: #182650;
            border: 1px solid var(--accent);
            background: var(--accent);
            box-shadow: 0 8px 20px rgba(247, 212, 71, 0.17);
        }

        @media (max-width: 640px) {
            .privacy-page { width: min(100% - 20px, 900px); padding-top: 18px; }
            .privacy-top { align-items: flex-start; margin-bottom: 22px; }
            .privacy-logo img { width: 40px; height: 40px; }
            .policy-intro { padding: 25px 20px 22px; }
            .quick-summary { grid-template-columns: 1fr; padding: 16px 20px; }
            .policy-sections { padding: 7px 20px 0; }
            .section-content { padding: 0 38px 19px; }
            .policy-footer { align-items: stretch; flex-direction: column; padding: 20px; }
            .understand-btn { width: 100%; }
        }

        /* Respect the application's shared light-theme switch from theme_init.php. */
        html[data-theme="light"] body {
            --surface: rgba(255, 255, 255, 0.82);
            --surface-strong: rgba(255, 255, 255, 0.95);
            --border: rgba(30, 64, 175, 0.14);
            --text: #15275b;
            --muted: rgba(21, 39, 91, 0.7);
            --accent: #9a6b00;
            --accent-soft: rgba(246, 203, 8, 0.17);
            color: var(--text);
            background: linear-gradient(155deg, #f5f9ff 0%, #e7f0ff 58%, #d8e8fb 100%);
        }

        html[data-theme="light"] .policy-shell {
            background: rgba(255, 255, 255, 0.62);
            box-shadow: 0 22px 55px rgba(30, 64, 175, 0.12);
        }

        html[data-theme="light"] .quick-summary,
        html[data-theme="light"] .summary-item {
            background: rgba(255, 255, 255, 0.45);
        }

        html[data-theme="light"] .understand-btn { color: #fff; }
    </style>
</head>
<body>
    <main class="privacy-page">
        <header class="privacy-top">
            <div class="privacy-logo">
                <img src="/NexGen/IMAGES/NGlogo.png" alt="NexGen Logo">
                <div class="privacy-logo-text">
                    <h1>Privacy Policy</h1>
                    <p>NexGen Micro-Enterprise System</p>
                </div>
            </div>
        </header>

        <section class="policy-shell" aria-labelledby="privacy-heading">
            <div class="policy-intro">
                <div class="eyebrow">Your information is protected</div>
                <h2 id="privacy-heading">Privacy that is <span>simple to understand.</span></h2>

            </div>

            <div class="quick-summary" aria-label="Privacy policy summary">
                <div class="summary-item"><strong>What we collect</strong><span>Account details, login activity, uploaded files, and system logs.</span></div>
                <div class="summary-item"><strong>Why we use it</strong><span>To operate the system, manage access, and maintain accurate records.</span></div>
                <div class="summary-item"><strong>How we protect it</strong><span>Security controls, access rules, session safeguards, and activity monitoring.</span></div>
            </div>

            <div class="policy-sections">
                <details open>
                    <summary><span class="section-number">1</span>Introduction</summary>
                    <div class="section-content"><p>NexGen values the privacy and security of user information. This Privacy Policy explains how personal data and system-generated information are collected, used, stored, and protected within the system.</p></div>
                </details>

                <details>
                    <summary><span class="section-number">2</span>Information We Collect</summary>
                    <div class="section-content"><ul><li>Account registration details such as employee number, username, full name, email, phone number, and address.</li><li>Authentication-related information such as encrypted passwords, login attempts, lockout details, and session activity.</li><li>Uploaded files such as valid IDs and profile images submitted by users.</li><li>System-generated logs including account approvals, security actions, and administrative activities.</li></ul></div>
                </details>

                <details>
                    <summary><span class="section-number">3</span>Purpose of Data Collection</summary>
                    <div class="section-content"><ul><li>To review and process account registration requests.</li><li>To authenticate users and control access based on assigned roles and permissions.</li><li>To protect the system through logging, account lockout, CAPTCHA validation, and other security mechanisms.</li><li>To maintain accurate records for auditing, monitoring, and account management.</li></ul></div>
                </details>

                <details>
                    <summary><span class="section-number">4</span>Data Protection</summary>
                    <div class="section-content"><p>NexGen applies security measures such as password hashing, role-based access control, session timeout, account lockout, CAPTCHA validation, and activity logging to help protect user data from unauthorized access, misuse, or alteration.</p></div>
                </details>

                <details id="cookie-notice">
                    <summary><span class="section-number">5</span>Cookie Use</summary>
                    <div class="section-content"><p>NexGen uses essential browser storage or session-related cookies for login session continuity, security controls, inactivity timeout handling, and access management. These are used only for proper system operation and are not intended for advertising or public tracking.</p></div>
                </details>

                <details>
                    <summary><span class="section-number">6</span>Data Sharing</summary>
                    <div class="section-content"><p>Information collected in the system is intended only for legitimate system operations, administrative review, and business management processes. User information is not intended for public disclosure or unauthorized third-party sharing.</p></div>
                </details>

                <details>
                    <summary><span class="section-number">7</span>User Responsibility</summary>
                    <div class="section-content"><p>Users are expected to provide accurate information during registration and to keep their account credentials secure. Any suspicious activity or unauthorized access attempt should be reported immediately to the system administrator.</p></div>
                </details>

                <details>
                    <summary><span class="section-number">8</span>Policy Updates</summary>
                    <div class="section-content"><p>NexGen may update this Privacy Policy when necessary to improve system security, compliance, and operational transparency. Users are encouraged to review this page periodically.</p></div>
                </details>
            </div>

            <footer class="policy-footer">
                <p>By continuing to use NexGen, you acknowledge that your information may be processed for authentication, access control, security monitoring, legitimate system operations, and essential session handling.</p>
                <a
                    href="<?php echo htmlspecialchars($backLink, ENT_QUOTES, 'UTF-8'); ?>"
                    class="understand-btn"
                    id="understand-btn"
                    data-close-signup="<?php echo $isSignupReturn ? 'true' : 'false'; ?>"
                    aria-label="Acknowledge the privacy policy"
                >I understand <span aria-hidden="true">→</span></a>
            </footer>
        </section>
    </main>

    <script>
        (() => {
            const understandButton = document.getElementById('understand-btn');
            if (!understandButton || understandButton.dataset.closeSignup !== 'true') {
                return;
            }

            understandButton.addEventListener('click', (event) => {
                event.preventDefault();

                // Browsers only allow window.close() when this page was opened by script.
                window.close();

                // If the browser blocks closing, return the user to the signup screen.
                window.setTimeout(() => {
                    if (!window.closed) {
                        window.location.replace(understandButton.href);
                    }
                }, 250);
            });
        })();
    </script>
</body>
</html>
