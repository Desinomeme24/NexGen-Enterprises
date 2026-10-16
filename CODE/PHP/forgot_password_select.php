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
$loginPath = nxLoginPathForPortal($fpPortal);
$forgotStartPath = nxAppUrl('forgot_password.php?portal=' . rawurlencode($fpPortal));

$popupMessage = "";
$popupType = "";

if (isset($_SESSION['success'])) {
    $popupMessage = $_SESSION['success'];
    $popupType = "success";
    unset($_SESSION['success']);
} elseif (isset($_SESSION['error'])) {
    $popupMessage = $_SESSION['error'];
    $popupType = "error";
    unset($_SESSION['error']);
}

$candidates = $_SESSION['fp_candidates'] ?? null;

if (
    !$candidates ||
    empty($_SESSION['fp_verified_at']) ||
    time() - (int) $_SESSION['fp_verified_at'] > 600 ||
    ($_SESSION['fp_verified_portal'] ?? '') !== $fpPortal
) {
    $_SESSION['error'] = 'Please start the password reset process again.';
    header('Location: ' . $forgotStartPath);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php include __DIR__ . '/nexgen_favicon/favicon.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Account - NextGen</title>
    <link rel="stylesheet" href="<?php echo e(nxCodeUrl('STYLE/forgot_password.css')); ?>">
</head>
<body>

<?php if (!empty($popupMessage)): ?>
    <div class="popup-overlay" id="popupOverlay">
        <div class="popup-box <?php echo $popupType; ?>" id="popupBox">
            <div class="popup-icon"><?php echo $popupType === 'success' ? '✓' : '!'; ?></div>
            <h3><?php echo $popupType === 'success' ? 'Success' : 'Error'; ?></h3>
            <p><?php echo htmlspecialchars($popupMessage); ?></p>
        </div>
    </div>
<?php endif; ?>

<div class="forgot-page">
    <div class="forgot-card">
        <img src="<?php echo e(nxProjectUrl('IMAGES/NGlogo.png')); ?>" alt="Logo" class="forgot-logo">

        <h1>Select Account</h1>
        <p class="subtext">Email ownership is verified. Choose which account to reset.</p>

        <form action="<?php echo e(nxAppUrl('send_forgot_otp.php')); ?>" method="POST">
            <input type="hidden" name="portal" value="<?php echo e($fpPortal); ?>">
            <div class="account-list">
                <?php foreach ($candidates as $c): ?>
                    <label class="account-option">
                        <input type="radio" name="selected_user_id" value="<?php echo (int) $c['id']; ?>" required>
                        <span>
                            <?php echo htmlspecialchars($c['masked_username']); ?><?php if ($c['business_name'] !== ''): ?> &mdash; <?php echo htmlspecialchars($c['business_name']); ?><?php endif; ?>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>

            <button type="submit" class="main-btn">Choose Account</button>
        </form>

        <div class="links">
            <a href="<?php echo e($forgotStartPath); ?>">Start Over</a>
            <a href="<?php echo e($loginPath); ?>">Back to Login</a>
        </div>
    </div>
</div>

<script src="<?php echo e(nxCodeUrl('JS/forgot_password.js')); ?>"></script>
</body>
</html>
